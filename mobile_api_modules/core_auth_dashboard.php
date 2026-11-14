<?php
/**
 * PHPNuxBill Mobile API Plugin - Auth, WebView SSO & Admin Dashboard Module
 * Path: system/plugin/mobile_api_modules/core_auth_dashboard.php
 */

global $config, $_c, $admin, $user, $ui;

switch ($action) {
    // =========================================================================
    // 1. AUTH ADMIN & CUSTOMER (Sesuai system/controllers/admin.php)
    // =========================================================================
    case 'auth':
    case 'admin_login':
    case 'customer_login':
        $role = strtolower(trim(_post('role') ?: ($action === 'customer_login' ? 'customer' : 'admin')));
        $username = trim(_post('username'));
        $password = _post('password');

        if (empty($username) || empty($password)) {
            api_out(false, class_exists('Lang') ? Lang::T('Invalid Username or Password') : 'Username dan Password wajib diisi.', null, 400);
        }

        $comp_name = !empty($config['CompanyName']) ? $config['CompanyName'] : 'PHPNuxBill';
        $curr_code = !empty($config['currency_code']) ? $config['currency_code'] : 'Rp.';

        if ($role === 'customer') {
            if (function_exists('run_hook')) {
                try { @run_hook('customer_login'); } catch (Throwable $e) {}
            }
            $cust = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
            if (!$cust || !check_user_password($password, $cust['password'])) {
                api_out(false, 'Username atau Password Pelanggan salah.', null, 401);
            }
            if (!empty($cust['status']) && $cust['status'] !== 'Active') {
                api_out(false, 'Status akun Anda: ' . $cust['status'], null, 403);
            }
            $_SESSION['uid'] = $cust['id'];
            if (class_exists('User') && method_exists('User', 'setCookie')) {
                try { @User::setCookie($cust['id']); } catch (Throwable $e) {}
            }
            $cust->last_login = date('Y-m-d H:i:s');
            $cust->save();

            $token = create_native_token('c', $cust['id'], $api_secret);
            $cust_arr = $cust->as_array();
            unset($cust_arr['password']);
            api_out(true, 'Login Pelanggan berhasil', [
                'token'         => $token,
                'company_name'  => $comp_name,
                'currency_code' => $curr_code,
                'customer'      => $cust_arr,
                'company'       => [
                    'name'            => $comp_name,
                    'currency'        => $curr_code,
                    'phone'           => $config['phone'] ?? '',
                    'address'         => $config['address'] ?? '',
                    'payment_gateway' => $config['payment_gateway'] ?? 'viaqris'
                ]
            ]);
        }

        if (function_exists('run_hook')) {
            try { @run_hook('admin_login'); } catch (Throwable $e) {}
        }

        $admin = ORM::for_table('tbl_users')->where('username', $username)->find_one();
        if (!$admin || !check_user_password($password, $admin['password'])) {
            if ($admin) {
                write_admin_log($admin->as_array(), $username . ' ' . (class_exists('Lang') ? Lang::T('Failed Login') : 'Failed Login'));
            }
            api_out(false, class_exists('Lang') ? Lang::T('Invalid Username or Password') : 'Username atau Password Admin salah.', null, 401);
        }
        if (!empty($admin['status']) && $admin['status'] !== 'Active') {
            api_out(false, 'Akun Admin sedang dinonaktifkan (' . $admin['status'] . ').', null, 403);
        }

        $_SESSION['aid'] = $admin['id'];
        if (class_exists('Admin') && method_exists('Admin', 'setCookie')) {
            try { @Admin::setCookie($admin['id']); } catch (Throwable $e) {}
        }
        $admin->last_login = date('Y-m-d H:i:s');
        $admin->save();

        write_admin_log($admin->as_array(), $username . ' ' . (class_exists('Lang') ? Lang::T('Login Successful') : 'Login Successful'));

        $token = create_native_token('a', $admin['id'], $api_secret);
        $adm_arr = $admin->as_array();
        unset($adm_arr['password']);
        api_out(true, 'Selamat datang, ' . ($admin['fullname'] ?: $admin['username']), [
            'token'         => $token,
            'company_name'  => $comp_name,
            'currency_code' => $curr_code,
            'admin'         => $adm_arr,
            'company'       => [
                'name'     => $comp_name,
                'currency' => $curr_code,
                'phone'    => $config['phone'] ?? '',
                'address'  => $config['address'] ?? ''
            ]
        ]);
        break;

    // =========================================================================
    // 2. SSO WEBVIEW IN-APP (ADMIN & CUSTOMER)
    // =========================================================================
    case 'webview_sso':
    case 'admin_webview_sso':
        $GLOBALS['MOBILE_API_OUTPUT_SENT'] = true;
        $token = get_req_token();
        $target = trim(_req('redirect') ?: (_req('target') ?: 'dashboard'));

        if (session_status() === PHP_SESSION_ACTIVE) {
            @setcookie(session_name(), session_id(), 0, '/');
        }

        if (strpos($token, 'c.') === 0) {
            $cust = require_customer_session($api_secret);
            $_SESSION['uid'] = $cust['id'];
            if (class_exists('User') && method_exists('User', 'setCookie')) {
                try { @User::setCookie($cust['id']); } catch (Throwable $e) {}
            }
            if ($target === 'dashboard') $target = 'home';
        } else {
            $adm = require_admin_session($api_secret);
            $_SESSION['aid'] = $adm['id'];
            if (class_exists('Admin') && method_exists('Admin', 'setCookie')) {
                try { @Admin::setCookie($adm['id']); } catch (Throwable $e) {}
            }
        }

        $clean_root_url = preg_replace('#/system/?$#i', '', rtrim(APP_URL, '/'));
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Location: ' . $clean_root_url . '/?_route=' . ltrim($target, '/'));
        exit();

    // =========================================================================
    // 3. DASBOR ADMIN
    // =========================================================================
    case 'admin_dashboard':
    case 'dashboard_extras':
        $admin = require_admin_session($api_secret);
        $today = date('Y-m-d');
        $start_month = date('Y-m-01');
        $yr = date('Y');
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        $income_today = ORM::for_table('tbl_transactions')
            ->where('recharged_on', $today)
            ->where_not_equal('method', 'Customer - Balance')
            ->where_not_like('method', 'Recharge Balance%')
            ->sum('price') ?: 0;

        $income_month = ORM::for_table('tbl_transactions')
            ->where_gte('recharged_on', $start_month)
            ->where_lte('recharged_on', $today)
            ->where_not_equal('method', 'Customer - Balance')
            ->where_not_like('method', 'Recharge Balance%')
            ->sum('price') ?: 0;

        $u_act = ORM::for_table('tbl_user_recharges')->where('status', 'on')->count();
        $u_exp = ORM::for_table('tbl_user_recharges')->where('status', 'off')->count();
        $c_all = ORM::for_table('tbl_customers')->count();
        $cb    = ORM::for_table('tbl_customers')->sum('balance') ?: 0;

        // Akselerasi Grafik 12 Bulan: Hanya 2 Query GROUP BY
        $reg_map = array_fill(1, 12, 0);
        $sales_map = array_fill(1, 12, 0.0);
        try {
            $db = ORM::get_db();
            $st_reg = $db->prepare("SELECT MONTH(created_at) AS m, COUNT(*) AS cnt FROM tbl_customers WHERE YEAR(created_at) = ? GROUP BY MONTH(created_at)");
            $st_reg->execute([$yr]);
            foreach ($st_reg->fetchAll(PDO::FETCH_ASSOC) as $rm) {
                $m_idx = (int)$rm['m'];
                if ($m_idx >= 1 && $m_idx <= 12) $reg_map[$m_idx] = (int)$rm['cnt'];
            }

            $st_sls = $db->prepare("SELECT MONTH(recharged_on) AS m, SUM(price) AS total FROM tbl_transactions WHERE YEAR(recharged_on) = ? AND method != 'Customer - Balance' AND method NOT LIKE 'Recharge Balance%' GROUP BY MONTH(recharged_on)");
            $st_sls->execute([$yr]);
            foreach ($st_sls->fetchAll(PDO::FETCH_ASSOC) as $sm) {
                $m_idx = (int)$sm['m'];
                if ($m_idx >= 1 && $m_idx <= 12) $sales_map[$m_idx] = (float)$sm['total'];
            }
        } catch (Throwable $e) {}

        $reg = [];
        $sales = [];
        for ($m = 1; $m <= 12; $m++) {
            $reg[]   = ['month' => $months[$m - 1], 'count' => $reg_map[$m]];
            $sales[] = ['month' => $months[$m - 1], 'total' => $sales_map[$m]];
        }

        // Akselerasi Stok Voucher & Paket Aktif: Gunakan GROUP BY
        $v_unused_map = [];
        $v_used_map   = [];
        $ur_act_map   = [];
        try {
            $db = ORM::get_db();
            foreach ($db->query("SELECT id_plan, status, COUNT(*) AS cnt FROM tbl_voucher GROUP BY id_plan, status")->fetchAll(PDO::FETCH_ASSOC) as $vr) {
                if ((int)$vr['status'] === 0) $v_unused_map[(int)$vr['id_plan']] = (int)$vr['cnt'];
                else $v_used_map[(int)$vr['id_plan']] = (int)$vr['cnt'];
            }
            foreach ($db->query("SELECT plan_id, COUNT(*) AS cnt FROM tbl_user_recharges WHERE status = 'on' GROUP BY plan_id")->fetchAll(PDO::FETCH_ASSOC) as $ur) {
                $ur_act_map[(int)$ur['plan_id']] = (int)$ur['cnt'];
            }
        } catch (Throwable $e) {}

        $stocks = [];
        foreach (ORM::for_table('tbl_plans')->where('enabled', 1)->find_array() as $p) {
            if (strtoupper(trim($p['type'])) === 'BALANCE') continue;
            $pid = (int)$p['id'];
            $stocks[] = [
                'plan_id'      => $pid,
                'name_plan'    => $p['name_plan'],
                'type'         => $p['type'],
                'unused'       => $v_unused_map[$pid] ?? 0,
                'used'         => $v_used_map[$pid] ?? 0,
                'active_users' => $ur_act_map[$pid] ?? 0
            ];
        }

        // Bersihkan transaksi zombie/expired pada antrean aktif agar jumlah antrean semua Payment Gateway akurat
        $pending_pgs = ORM::for_table('tbl_payment_gateway')->where('status', 1)->order_by_desc('id')->limit(30)->find_many();
        foreach ($pending_pgs as $p_trx) {
            check_and_expire_trx($p_trx);
        }
        $unpaid_cnt = (int)ORM::for_table('tbl_payment_gateway')->where('status', 1)->count();

        $comp_name = !empty($config['CompanyName']) ? $config['CompanyName'] : 'PHPNuxBill';
        $curr_code = !empty($config['currency_code']) ? $config['currency_code'] : 'Rp.';

        api_out(true, 'Dashboard berhasil dimuat', [
            'company_name'  => $comp_name,
            'currency_code' => $curr_code,
            'company' => [
                'name'        => $comp_name,
                'currency'    => $curr_code,
                'date_Period' => date('01 M Y') . ' / ' . date('d M Y')
            ],
            'cards' => [
                'income_today'           => (float)$income_today,
                'income_month'           => (float)$income_month,
                'active_users'           => (int)$u_act,
                'expired_users'          => (int)$u_exp,
                'active_expired_label'   => $u_act . '/' . $u_exp,
                'total_customers'        => (int)$c_all,
                'total_customer_balance' => (float)$cb,
                'unpaid_qris_count'      => $unpaid_cnt
            ],
            'charts' => [
                'monthly_registered' => $reg,
                'monthly_sales'      => $sales
            ],
            'unpaid_qris_count'  => $unpaid_cnt,
            'monthly_registered' => $reg,
            'monthly_sales'      => $sales,
            'stocks'             => $stocks,
            'expiring_users'     => ORM::for_table('tbl_user_recharges')->where_lte('expiration', date('Y-m-d', strtotime('+2 days')))->order_by_desc('expiration')->limit(15)->find_array()
        ]);
        break;
}
