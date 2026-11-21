<?php
/**
 * PHPNuxBill Mobile API Plugin - Auth, WebView SSO & Role-Aware Admin Dashboard Module
 * Path: system/plugin/mobile_api_modules/core_auth_dashboard.php
 */

global $config, $_c, $admin, $user, $ui, $UPLOAD_PATH, $phpnux_root, $api_secret;

if (!function_exists('admin_get_root_url')) {
    function admin_get_root_url()
    {
        $base_url = defined('APP_URL') ? trim((string) APP_URL) : '';
        if ($base_url === '') {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
            $scheme   = $isHttps ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $dir      = rtrim(preg_replace('#/system$#i', '', dirname(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''))), '/');
            $base_url = "{$scheme}://{$host}{$dir}";
        }
        return rtrim(preg_replace('#(/system|/index\.php)$#i', '', $base_url), '/');
    }
}

if (!function_exists('admin_resolve_photo_url')) {
    function admin_resolve_photo_url($adm)
    {
        global $UPLOAD_PATH, $phpnux_root;
        $root_dir = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $up_dir   = !empty($UPLOAD_PATH) ? trim($UPLOAD_PATH, '/\\') : 'system/uploads';
        $base_url = admin_get_root_url();
        $relPhoto = trim((string) ($adm['photo'] ?? ''));

        if ($relPhoto !== '' && strpos($relPhoto, 'default') === false) {
            $relPhoto = '/' . ltrim(str_replace('\\', '/', $relPhoto), '/');
            $thumbAbs = $root_dir . DIRECTORY_SEPARATOR . $up_dir . str_replace('/', DIRECTORY_SEPARATOR, $relPhoto) . '.thumb.jpg';
            if (is_file($thumbAbs) && filesize($thumbAbs) > 0) {
                $ver = (int) @filemtime($thumbAbs);
                return $base_url . '/' . str_replace('\\', '/', $up_dir) . $relPhoto . '.thumb.jpg' . ($ver > 0 ? ('?v=' . $ver) : '');
            }
            $origAbs = $root_dir . DIRECTORY_SEPARATOR . $up_dir . str_replace('/', DIRECTORY_SEPARATOR, $relPhoto);
            if (is_file($origAbs) && filesize($origAbs) > 0) {
                $ver = (int) @filemtime($origAbs);
                return $base_url . '/' . str_replace('\\', '/', $up_dir) . $relPhoto . ($ver > 0 ? ('?v=' . $ver) : '');
            }
        }

        $defAbs = $root_dir . DIRECTORY_SEPARATOR . $up_dir . DIRECTORY_SEPARATOR . 'admin.default.png';
        if (is_file($defAbs) && filesize($defAbs) > 0) {
            return $base_url . '/' . str_replace('\\', '/', $up_dir) . '/admin.default.png';
        }
        return '';
    }
}

switch ($action) {

    // =========================================================================
    // 1. AUTH ADMIN & CUSTOMER (Sesuai system/controllers/admin.php)
    // =========================================================================
    case 'auth':
    case 'admin_login':
    case 'customer_login':
        $role     = strtolower(trim((string) (_post('role') ?: ($action === 'customer_login' ? 'customer' : 'admin'))));
        $username = trim((string) _post('username'));
        $password = (string) _post('password');

        if ($username === '' || $password === '') {
            api_out(false, class_exists('Lang') ? Lang::T('Invalid Username or Password') : 'Username dan Password wajib diisi.', null, 400);
        }

        $comp_name = !empty($config['CompanyName']) ? $config['CompanyName'] : 'PHPNuxBill';
        $curr_code = !empty($config['currency_code']) ? $config['currency_code'] : 'Rp.';
        $logo_url  = function_exists('get_server_logo_url') ? get_server_logo_url() : '';

        if ($role === 'customer') {
            if (function_exists('run_hook')) {
                try {
                    @run_hook('customer_login');
                } catch (Throwable $e) {
                }
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
                try {
                    @User::setCookie($cust['id']);
                } catch (Throwable $e) {
                }
            }
            $cust->last_login = date('Y-m-d H:i:s');
            $cust->save();
            $token    = create_native_token('c', $cust['id'], $api_secret);
            $cust_arr = $cust->as_array();
            unset($cust_arr['password']);
            api_out(true, 'Login Pelanggan berhasil', [
                'token'         => $token,
                'company_name'  => $comp_name,
                'currency_code' => $curr_code,
                'logo_url'      => $logo_url,
                'customer'      => $cust_arr,
                'company'       => [
                    'name'            => $comp_name,
                    'currency'        => $curr_code,
                    'logo_url'        => $logo_url,
                    'phone'           => $config['phone'] ?? '',
                    'address'         => $config['address'] ?? '',
                    'payment_gateway' => $config['payment_gateway'] ?? 'viaqris'
                ]
            ]);
        }

        if (function_exists('run_hook')) {
            try {
                @run_hook('admin_login');
            } catch (Throwable $e) {
            }
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
            try {
                @Admin::setCookie($admin['id']);
            } catch (Throwable $e) {
            }
        }
        $admin->last_login = date('Y-m-d H:i:s');
        $admin->save();
        write_admin_log($admin->as_array(), $username . ' ' . (class_exists('Lang') ? Lang::T('Login Successful') : 'Login Successful'));
        $token               = create_native_token('a', $admin['id'], $api_secret);
        $adm_arr             = $admin->as_array();
        $adm_arr['photo_url'] = admin_resolve_photo_url($adm_arr);
        unset($adm_arr['password']);

        api_out(true, 'Selamat datang, ' . ($admin['fullname'] ?: $admin['username']), [
            'token'         => $token,
            'company_name'  => $comp_name,
            'currency_code' => $curr_code,
            'logo_url'      => $logo_url,
            'admin'         => $adm_arr,
            'company'       => [
                'name'     => $comp_name,
                'currency' => $curr_code,
                'logo_url' => $logo_url,
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
        $token  = get_req_token();
        $target = trim((string) (_req('redirect') ?: (_req('target') ?: 'dashboard')));
        if (session_status() === PHP_SESSION_ACTIVE) {
            @setcookie(session_name(), session_id(), 0, '/');
        }
        if (strpos($token, 'c.') === 0) {
            $cust            = require_customer_session($api_secret);
            $_SESSION['uid'] = $cust['id'];
            if (class_exists('User') && method_exists('User', 'setCookie')) {
                try {
                    @User::setCookie($cust['id']);
                } catch (Throwable $e) {
                }
            }
            if ($target === 'dashboard') {
                $target = 'home';
            }
        } else {
            $adm             = require_admin_session($api_secret);
            $_SESSION['aid'] = $adm['id'];
            if (class_exists('Admin') && method_exists('Admin', 'setCookie')) {
                try {
                    @Admin::setCookie($adm['id']);
                } catch (Throwable $e) {
                }
            }
        }
        $clean_root_url = admin_get_root_url();
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Location: ' . $clean_root_url . '/?_route=' . ltrim($target, '/'));
        exit();

    // =========================================================================
    // 3. DASBOR ADMIN (ROLE-AWARE: SUPERADMIN, ADMIN, REPORT, AGENT, SALES)
    // =========================================================================
    case 'admin_dashboard':
    case 'dashboard_extras':
        $admin      = require_admin_session($api_secret);
        $user_type  = (string) ($admin['user_type'] ?: 'Admin');
        $is_full    = in_array($user_type, ['SuperAdmin', 'Admin'], true);
        $can_finance = in_array($user_type, ['SuperAdmin', 'Admin', 'Report'], true);
        $is_agent   = ($user_type === 'Agent');
        $is_sales   = ($user_type === 'Sales');

        $today       = date('Y-m-d');
        $reset_day   = max(1, min(28, (int) ($config['reset_day'] ?? 1)));
        $start_month = ((int) date('d') >= $reset_day)
            ? date('Y-m-') . str_pad((string) $reset_day, 2, '0', STR_PAD_LEFT)
            : date('Y-m-', strtotime('-1 month')) . str_pad((string) $reset_day, 2, '0', STR_PAD_LEFT);
        $yr     = date('Y');
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        // Lingkup ID staf untuk Agent (diri sendiri + Sales di bawahnya) atau Sales (diri sendiri)
        $scoped_staff_ids = [];
        if ($is_agent) {
            $scoped_staff_ids[] = (int) $admin['id'];
            foreach (ORM::for_table('tbl_users')->select('id')->where('root', $admin['id'])->find_array() as $s) {
                $scoped_staff_ids[] = (int) $s['id'];
            }
        } elseif ($is_sales) {
            $scoped_staff_ids[] = (int) $admin['id'];
        }

        // 1. Pendapatan (Penuh untuk SuperAdmin/Admin/Report; lingkup penjualan sendiri untuk Agent/Sales)
        $q_today = ORM::for_table('tbl_transactions')
            ->where('recharged_on', $today)
            ->where_not_equal('method', 'Customer - Balance')
            ->where_not_like('method', 'Recharge Balance%');
        $q_month = ORM::for_table('tbl_transactions')
            ->where_gte('recharged_on', $start_month)
            ->where_lte('recharged_on', $today)
            ->where_not_equal('method', 'Customer - Balance')
            ->where_not_like('method', 'Recharge Balance%');

        if (!empty($scoped_staff_ids)) {
            $q_today->where_in('admin_id', $scoped_staff_ids);
            $q_month->where_in('admin_id', $scoped_staff_ids);
        }

        $income_today = (float) ($q_today->sum('price') ?: 0);
        $income_month = (float) ($q_month->sum('price') ?: 0);

        $u_act = (int) ORM::for_table('tbl_user_recharges')->where('status', 'on')->count();
        $u_exp = (int) ORM::for_table('tbl_user_recharges')->where('status', 'off')->count();
        $c_all = (int) ORM::for_table('tbl_customers')->count();
        $cb    = $can_finance ? (float) (ORM::for_table('tbl_customers')->sum('balance') ?: 0) : 0.0;

        // 2. Rincian Pelanggan Aktif berdasarkan tipe layanan (Hotspot / PPPoE / VPN)
        $active_hotspot = 0;
        $active_pppoe   = 0;
        $active_vpn     = 0;
        try {
            $db = ORM::get_db();
            foreach ($db->query("SELECT UPPER(type) AS t, COUNT(*) AS cnt FROM tbl_user_recharges WHERE status = 'on' GROUP BY UPPER(type)")->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                $tp = (string) ($ar['t'] ?? '');
                if ($tp === 'HOTSPOT') {
                    $active_hotspot = (int) $ar['cnt'];
                } elseif ($tp === 'PPPOE') {
                    $active_pppoe = (int) $ar['cnt'];
                } elseif ($tp === 'VPN') {
                    $active_vpn = (int) $ar['cnt'];
                }
            }
        } catch (Throwable $e) {
        }

        // 3. Grafik 12 Bulan
        $reg_map   = array_fill(1, 12, 0);
        $sales_map = array_fill(1, 12, 0.0);
        try {
            $db     = ORM::get_db();
            $st_reg = $db->prepare("SELECT MONTH(created_at) AS m, COUNT(*) AS cnt FROM tbl_customers WHERE YEAR(created_at) = ? GROUP BY MONTH(created_at)");
            $st_reg->execute([$yr]);
            foreach ($st_reg->fetchAll(PDO::FETCH_ASSOC) as $rm) {
                $m_idx = (int) $rm['m'];
                if ($m_idx >= 1 && $m_idx <= 12) {
                    $reg_map[$m_idx] = (int) $rm['cnt'];
                }
            }

            if ($can_finance) {
                $st_sls = $db->prepare("SELECT MONTH(recharged_on) AS m, SUM(price) AS total FROM tbl_transactions WHERE YEAR(recharged_on) = ? AND method != 'Customer - Balance' AND method NOT LIKE 'Recharge Balance%' GROUP BY MONTH(recharged_on)");
                $st_sls->execute([$yr]);
            } else {
                $placeholders = implode(',', array_fill(0, count($scoped_staff_ids), '?'));
                $st_sls       = $db->prepare("SELECT MONTH(recharged_on) AS m, SUM(price) AS total FROM tbl_transactions WHERE YEAR(recharged_on) = ? AND admin_id IN ({$placeholders}) AND method != 'Customer - Balance' AND method NOT LIKE 'Recharge Balance%' GROUP BY MONTH(recharged_on)");
                $st_sls->execute(array_merge([$yr], $scoped_staff_ids));
            }
            foreach ($st_sls->fetchAll(PDO::FETCH_ASSOC) as $sm) {
                $m_idx = (int) $sm['m'];
                if ($m_idx >= 1 && $m_idx <= 12) {
                    $sales_map[$m_idx] = (float) $sm['total'];
                }
            }
        } catch (Throwable $e) {
        }

        $reg   = [];
        $sales = [];
        for ($m = 1; $m <= 12; $m++) {
            $reg[]   = ['month' => $months[$m - 1], 'count' => $reg_map[$m]];
            $sales[] = ['month' => $months[$m - 1], 'total' => $sales_map[$m]];
        }

        // 4. Stok Voucher & Pelanggan Aktif per Paket (tersaring per lingkup Agent/Sales)
        $v_unused_map = [];
        $v_used_map   = [];
        $ur_act_map   = [];
        try {
            $db = ORM::get_db();
            if (!empty($scoped_staff_ids)) {
                $ph  = implode(',', array_fill(0, count($scoped_staff_ids), '?'));
                $stv = $db->prepare("SELECT id_plan, status, COUNT(*) AS cnt FROM tbl_voucher WHERE generated_by IN ({$ph}) GROUP BY id_plan, status");
                $stv->execute($scoped_staff_ids);
                $v_rows = $stv->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $v_rows = $db->query("SELECT id_plan, status, COUNT(*) AS cnt FROM tbl_voucher GROUP BY id_plan, status")->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($v_rows as $vr) {
                if ((int) $vr['status'] === 0) {
                    $v_unused_map[(int) $vr['id_plan']] = (int) $vr['cnt'];
                } else {
                    $v_used_map[(int) $vr['id_plan']] = (int) $vr['cnt'];
                }
            }
            foreach ($db->query("SELECT plan_id, COUNT(*) AS cnt FROM tbl_user_recharges WHERE status = 'on' GROUP BY plan_id")->fetchAll(PDO::FETCH_ASSOC) as $ur) {
                $ur_act_map[(int) $ur['plan_id']] = (int) $ur['cnt'];
            }
        } catch (Throwable $e) {
        }

        $stocks = [];
        foreach (ORM::for_table('tbl_plans')->where('enabled', 1)->find_array() as $p) {
            if (strtoupper(trim((string) $p['type'])) === 'BALANCE') {
                continue;
            }
            $pid      = (int) $p['id'];
            $stocks[] = [
                'plan_id'      => $pid,
                'name_plan'    => $p['name_plan'],
                'type'         => $p['type'],
                'unused'       => $v_unused_map[$pid] ?? 0,
                'used'         => $v_used_map[$pid] ?? 0,
                'active_users' => $ur_act_map[$pid] ?? 0
            ];
        }

        // 5. Antrean Payment Gateway Tertunda (khusus SuperAdmin/Admin)
        $unpaid_cnt = 0;
        if ($is_full) {
            $pending_pgs = ORM::for_table('tbl_payment_gateway')->where('status', 1)->order_by_desc('id')->limit(30)->find_many();
            foreach ($pending_pgs as $p_trx) {
                check_and_expire_trx($p_trx);
            }
            $unpaid_cnt = (int) ORM::for_table('tbl_payment_gateway')->where('status', 1)->count();
        }

        // 6. Log Aktivitas Terbaru (khusus SuperAdmin/Admin, sesuai widget activity_log.php)
        $recent_logs = [];
        if ($is_full) {
            try {
                foreach (ORM::for_table('tbl_logs')->order_by_desc('id')->limit(8)->find_array() as $lg) {
                    $recent_logs[] = [
                        'id'          => (int) $lg['id'],
                        'date'        => (string) ($lg['date'] ?? ''),
                        'type'        => (string) ($lg['type'] ?? ''),
                        'description' => trim(strip_tags((string) ($lg['description'] ?? ''))),
                        'ip'          => (string) ($lg['ip'] ?? '')
                    ];
                }
            } catch (Throwable $e) {
            }
        }

        // 7. Daftar Widget Aktif Web per Role (tbl_widgets)
        $enabled_widgets = [];
        try {
            $w_role = $is_full ? 'Admin' : $user_type;
            $w_rows = ORM::for_table('tbl_widgets')
                ->where('enabled', 1)
                ->where('user', $w_role)
                ->order_by_asc('orders')
                ->find_array();
            foreach ($w_rows as $wr) {
                if (!empty($wr['widget'])) {
                    $enabled_widgets[] = (string) $wr['widget'];
                }
            }
        } catch (Throwable $e) {
        }

        $comp_name = !empty($config['CompanyName']) ? $config['CompanyName'] : 'PHPNuxBill';
        $curr_code = !empty($config['currency_code']) ? $config['currency_code'] : 'Rp.';

        api_out(true, 'Dashboard berhasil dimuat', [
            'company_name'  => $comp_name,
            'currency_code' => $curr_code,
            'admin'         => [
                'id'        => (int) $admin['id'],
                'username'  => (string) $admin['username'],
                'fullname'  => (string) ($admin['fullname'] ?: $admin['username']),
                'user_type' => $user_type,
                'photo_url' => admin_resolve_photo_url($admin)
            ],
            'feature_flags' => [
                'enable_balance'   => ($config['enable_balance'] ?? 'yes') === 'yes',
                'disable_voucher'  => ($config['disable_voucher'] ?? 'no') === 'yes',
                'enable_coupons'   => ($config['enable_coupons'] ?? 'no') === 'yes',
                'radius_enable'    => !empty($config['radius_enable']) && $config['radius_enable'] !== 'no' && $config['radius_enable'] !== '0',
                'maintenance_mode' => !empty($config['maintenance_mode']) && $config['maintenance_mode'] !== 'no' && $config['maintenance_mode'] !== '0'
            ],
            'enabled_widgets' => $enabled_widgets,
            'company'         => [
                'name'        => $comp_name,
                'currency'    => $curr_code,
                'date_Period' => date('d M Y', strtotime($start_month)) . ' — ' . date('d M Y')
            ],
            'cards' => [
                'income_today'           => $income_today,
                'income_month'           => $income_month,
                'active_users'           => $u_act,
                'expired_users'          => $u_exp,
                'active_expired_label'   => $u_act . '/' . $u_exp,
                'total_customers'        => $c_all,
                'total_customer_balance' => $cb,
                'unpaid_qris_count'      => $unpaid_cnt
            ],
            'active_breakdown' => [
                'hotspot' => $active_hotspot,
                'pppoe'   => $active_pppoe,
                'vpn'     => $active_vpn
            ],
            'charts' => [
                'monthly_registered' => $reg,
                'monthly_sales'      => $sales
            ],
            'unpaid_qris_count'  => $unpaid_cnt,
            'monthly_registered' => $reg,
            'monthly_sales'      => $sales,
            'stocks'             => $stocks,
            'expiring_users'     => ORM::for_table('tbl_user_recharges')
                ->where_lte('expiration', date('Y-m-d', strtotime('+2 days')))
                ->order_by_desc('expiration')
                ->limit(15)
                ->find_array(),
            'recent_logs' => $recent_logs
        ]);
        break;
}
