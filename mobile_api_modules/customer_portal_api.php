<?php
/**
 * PHPNuxBill Mobile API Plugin - Customer Mobile App Portal Module
 * Path: system/plugin/mobile_api_modules/customer_portal_api.php
 *
 * Fitur: Login, Dashboard (+ Pengumuman, Foto Profil Web, Unread Inbox),
 * Paket Internet & Top-Up (Nama Bandwidth + Harga Coret), Riwayat, Auto-Renewal,
 * Redeem Voucher, Checkout & Pembayaran Multi-Gateway, Inbox Pelanggan,
 * Transfer Saldo, Belikan Paket Teman, Sync Router, Extend Masa Tenggang,
 * Edit Profil & Foto, Ganti Sandi.
 */

global $config, $_c, $user, $PAYMENTGATEWAY_PATH, $UPLOAD_PATH, $CACHE_PATH, $routes, $_app_stage, $phpnux_root, $api_secret;

if (!function_exists('portal_get_base_url')) {
    function portal_get_base_url()
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

if (!function_exists('portal_resolve_customer_photo_url')) {
    /**
     * Menghasilkan URL foto profil pelanggan asli dari web PHPNuxBill
     * ($UPLOAD_PATH . $user['photo'] . '.thumb.jpg' -> fallback foto asli -> user.default.jpg).
     */
    function portal_resolve_customer_photo_url($cust)
    {
        global $UPLOAD_PATH, $phpnux_root;
        $root_dir = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $up_dir   = !empty($UPLOAD_PATH) ? trim($UPLOAD_PATH, '/\\') : 'system/uploads';
        $base_url = portal_get_base_url();
        $relPhoto = trim((string) ($cust['photo'] ?? ''));

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

        $defAbs = $root_dir . DIRECTORY_SEPARATOR . $up_dir . DIRECTORY_SEPARATOR . 'user.default.jpg';
        if (is_file($defAbs) && filesize($defAbs) > 0) {
            return $base_url . '/' . str_replace('\\', '/', $up_dir) . '/user.default.jpg';
        }
        return '';
    }
}

if (!function_exists('portal_read_announcement')) {
    function portal_read_announcement()
    {
        global $phpnux_root;
        $root_dir = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        foreach ([
            $root_dir . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'Announcement.html',
            $root_dir . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'Announcement_Customer.html'
        ] as $fPath) {
            if (is_file($fPath)) {
                $raw = trim((string) @file_get_contents($fPath));
                if ($raw !== '') {
                    $text = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\b[^>]*>/i', "\n", $raw)), ENT_QUOTES, 'UTF-8');
                    $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }
        return '';
    }
}

switch ($action) {

    // =========================================================================
    // 1. AUTENTIKASI PELANGGAN
    // =========================================================================
    case 'login':
        $username = trim((string) _post('username'));
        $password = (string) _post('password');
        if ($username === '' || $password === '') {
            api_out(false, class_exists('Lang') ? Lang::T('Enter username and password') : 'Username dan Password wajib diisi.', null, 400);
        }
        $user = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
        if (!$user || !check_user_password($password, $user['password'])) {
            api_out(false, class_exists('Lang') ? Lang::T('Invalid Username or Password') : 'Username atau Password salah.', null, 401);
        }
        if (!empty($user['status']) && $user['status'] !== 'Active') {
            api_out(false, (class_exists('Lang') ? Lang::T('This account status') : 'Status akun Anda') . ': ' . $user['status'], null, 403);
        }
        $user->last_login = date('Y-m-d H:i:s');
        $user->save();
        $_SESSION['uid'] = $user['id'];
        if (class_exists('User') && method_exists('User', 'setCookie')) {
            @User::setCookie($user['id']);
        }
        if (function_exists('_log')) {
            @_log($username . ' ' . (class_exists('Lang') ? Lang::T('Login Successful') : 'Login Successful'), 'User', $user['id']);
        }
        $native_token = create_native_token('c', $user['id'], $api_secret);
        $comp_name    = !empty($config['CompanyName']) ? $config['CompanyName'] : 'PHPNuxBill';
        $curr_code    = !empty($config['currency_code']) ? $config['currency_code'] : 'Rp.';
        api_out(true, class_exists('Lang') ? Lang::T('Login Successful') : 'Login berhasil', [
            'token'         => $native_token,
            'native_token'  => $native_token,
            'company_name'  => $comp_name,
            'currency_code' => $curr_code,
            'logo_url'      => get_server_logo_url()
        ]);
        break;

    // =========================================================================
    // 2. BERANDA PELANGGAN (PAKET AKTIF, PENGUMUMAN, INBOX, AUTO-RENEWAL, TAGIHAN)
    // =========================================================================
    case 'dashboard':
        $user = require_customer_session($api_secret);
        if (class_exists('User') && method_exists('User', '_info')) {
            $u_info = User::_info($user['id']);
            if ($u_info) {
                $user = $u_info;
            }
        }
        $recharges = [];
        if (class_exists('User') && method_exists('User', '_billing')) {
            $bills = User::_billing($user['id']);
            if ($bills) {
                foreach ($bills as $b) {
                    $recharges[] = ($b instanceof ORM) ? $b->as_array() : (array) $b;
                }
            }
        } else {
            $recharges = ORM::for_table('tbl_user_recharges')->where('customer_id', $user['id'])->order_by_desc('id')->find_array();
        }

        // Periksa perpanjangan otomatis menggunakan Saldo jika paket baru saja kedaluwarsa
        foreach ($recharges as &$b_item) {
            if (($b_item['status'] ?? '') === 'on' && !empty($b_item['expiration']) && time() > strtotime($b_item['expiration'] . ' ' . ($b_item['time'] ?: '23:59:59'))) {
                $ur_obj = ORM::for_table('tbl_user_recharges')->find_one($b_item['id']);
                if ($ur_obj && ($config['enable_balance'] ?? 'no') === 'yes' && (int) ($user['auto_renewal'] ?? 0) === 1 && class_exists('Package')) {
                    $plan_info = ORM::for_table('tbl_plans')->find_one($ur_obj['plan_id']);
                    if ($plan_info && (int) $plan_info['enabled'] === 1 && (float) $user['balance'] >= (float) $plan_info['price']) {
                        if (@Package::rechargeUser($user['id'], $plan_info['routers'], $plan_info['id'], 'Customer', 'Balance')) {
                            if (class_exists('Balance') && method_exists('Balance', 'min')) {
                                Balance::min($user['id'], $plan_info['price']);
                            }
                            $user     = ORM::for_table('tbl_customers')->find_one($user['id']);
                            $ur_fresh = ORM::for_table('tbl_user_recharges')->find_one($b_item['id']);
                            if ($ur_fresh) {
                                $b_item = $ur_fresh->as_array();
                            }
                            continue;
                        }
                    }
                }
            }
        }
        unset($b_item);

        foreach ($recharges as &$r_item) {
            $p_row = !empty($r_item['plan_id']) ? ORM::for_table('tbl_plans')->find_one((int) $r_item['plan_id']) : null;
            if (!$p_row && !empty($r_item['namebp'])) {
                $p_row = ORM::for_table('tbl_plans')->where('name_plan', $r_item['namebp'])->find_one();
            }
            if ($p_row) {
                $cost_r                 = calculate_order_cost($user['id'], $p_row, $config);
                $r_item['plan_id']      = (int) $p_row['id'];
                $r_item['price']        = (float) $cost_r['total_cost'];
                $r_item['validity']     = trim($p_row['validity'] . ' ' . $p_row['validity_unit']);
                $r_item['plan_enabled'] = (int) $p_row['enabled'];
            } else {
                $r_item['price']        = 0.0;
                $r_item['validity']     = '';
                $r_item['plan_enabled'] = 0;
            }
        }
        unset($r_item);
        
        $pg_rows = ORM::for_table('tbl_payment_gateway')
            ->where('username', $user['username'])
            ->order_by_desc('id')
            ->limit(15)
            ->find_many();
        $unpaid_bills  = [];
        $order_history = [];
        foreach ($pg_rows as $pr) {
            $parsed = parse_qris_trx($pr, $config);
            if ($parsed) {
                $order_history[] = $parsed;
                if ((int) $parsed['status'] === 1) {
                    $unpaid_bills[] = $parsed;
                }
            }
        }

        $inbox_unread = 0;
        try {
            $inbox_unread = (int) ORM::for_table('tbl_customers_inbox')
                ->where('customer_id', $user['id'])
                ->where_null('date_read')
                ->count();
        } catch (Throwable $e) {
            $inbox_unread = 0;
        }

        $cache_dir      = !empty($CACHE_PATH) ? $CACHE_PATH : 'system/cache';
        $root_dir       = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $extend_file    = $root_dir . DIRECTORY_SEPARATOR . trim($cache_dir, '/\\') . DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR . $user['id'] . '.txt';
        $extended_month = (is_file($extend_file) && trim((string) @file_get_contents($extend_file)) === date('m'));

        $comp_name = !empty($config['CompanyName']) ? $config['CompanyName'] : 'PHPNuxBill';
        $curr_code = !empty($config['currency_code']) ? $config['currency_code'] : 'Rp.';

        api_out(true, 'Data beranda dimuat', [
            'company' => [
                'name'                   => $comp_name,
                'phone'                  => $config['phone'] ?? '',
                'address'                => $config['address'] ?? '',
                'currency'               => $curr_code,
                'enable_balance'         => ($config['enable_balance'] ?? 'yes') === 'yes',
                'allow_balance_transfer' => ($config['allow_balance_transfer'] ?? 'no') === 'yes',
                'minimum_transfer'       => (float) ($config['minimum_transfer'] ?? 0),
                'extend_expired'         => !empty($config['extend_expired']) && $config['extend_expired'] !== 'no' && $config['extend_expired'] !== '0',
                'extend_days'            => (int) ($config['extend_days'] ?? 3),
                'disable_voucher'        => ($config['disable_voucher'] ?? 'no') === 'yes',
                'allow_phone_otp'        => ($config['allow_phone_otp'] ?? 'no') === 'yes',
                'allow_email_otp'        => ($config['allow_email_otp'] ?? 'no') === 'yes'
            ],
            'customer' => [
                'id'                  => (int) $user['id'],
                'username'            => $user['username'],
                'fullname'            => $user['fullname'],
                'phonenumber'         => $user['phonenumber'],
                'email'               => $user['email'],
                'address'             => $user['address'],
                'balance'             => (float) $user['balance'],
                'service_type'        => $user['service_type'] ?: 'Others',
                'auto_renewal'        => (int) ($user['auto_renewal'] ?? 0),
                'photo_url'           => portal_resolve_customer_photo_url($user),
                'extended_this_month' => $extended_month
            ],
            'announcement'       => portal_read_announcement(),
            'inbox_unread_count' => $inbox_unread,
            'payment_gateways'   => get_active_gateways($config),
            'active_packages'    => $recharges,
            'unpaid_bills'       => $unpaid_bills,
            'unpaid_bill'        => $unpaid_bills[0] ?? null,
            'order_history'      => $order_history
        ]);
        break;

    // =========================================================================
    // 3. DAFTAR PAKET INTERNET & TOP-UP SALDO (MENAMPILKAN NAMA BANDWIDTH)
    // =========================================================================
    case 'plans':
        $user      = require_customer_session($api_secret);
        $cust_type = strtoupper(trim((string) ($user['service_type'] ?: 'OTHERS')));
        $plans     = ORM::for_table('tbl_plans')
            ->where('enabled', 1)
            ->order_by_asc('price')
            ->find_array();

        $bw_map = [];
        try {
            foreach (ORM::for_table('tbl_bandwidth')->find_array() as $bw) {
                $bw_map[(int) $bw['id']] = trim((string) ($bw['name_bw'] ?? ''));
            }
        } catch (Throwable $e) {
        }

        $internet_plans = [];
        $balance_plans  = [];
        foreach ($plans as $p) {
            $p_type    = strtoupper(trim((string) $p['type']));
            $prepaid   = strtolower(trim((string) ($p['prepaid'] ?? 'yes')));
            $bwName    = $bw_map[(int) ($p['id_bw'] ?? 0)] ?? '';
            $price     = (float) $p['price'];
            $price_old = (float) ($p['price_old'] ?? 0);
            if ($price_old <= $price) {
                $price_old = 0.0;
            }

            $quota_label = 'Unlimited';
            if (strcasecmp((string) ($p['typebp'] ?? ''), 'Limited') === 0) {
                $lt = (string) ($p['limit_type'] ?? '');
                if ($lt === 'Data_Limit' && !empty($p['data_limit'])) {
                    $quota_label = trim($p['data_limit'] . ' ' . ($p['data_unit'] ?? 'MB'));
                } elseif ($lt === 'Time_Limit' && !empty($p['time_limit'])) {
                    $quota_label = trim($p['time_limit'] . ' ' . ($p['time_unit'] ?? 'Hrs'));
                } elseif ($lt === 'Both_Limit') {
                    $parts_q = [];
                    if (!empty($p['data_limit'])) {
                        $parts_q[] = trim($p['data_limit'] . ' ' . ($p['data_unit'] ?? 'MB'));
                    }
                    if (!empty($p['time_limit'])) {
                        $parts_q[] = trim($p['time_limit'] . ' ' . ($p['time_unit'] ?? 'Hrs'));
                    }
                    if (!empty($parts_q)) {
                        $quota_label = implode(' • ', $parts_q);
                    }
                }
            }

            $item = [
                'id'           => (int) $p['id'],
                'name_plan'    => $p['name_plan'],
                'price'        => $price,
                'price_old'    => $price_old,
                'type'         => ($p_type === 'PPPOE') ? 'PPPoE' : (($p_type === 'HOTSPOT') ? 'Hotspot' : $p['type']),
                'prepaid'      => $p['prepaid'],
                'enabled'      => (int) $p['enabled'],
                'validity'     => $p['validity'] . ' ' . $p['validity_unit'],
                'bandwidth'    => $bwName,
                'shared_users' => max(1, (int) ($p['shared_users'] ?? 1)),
                'quota_label'  => $quota_label,
                'routers'      => ($p['is_radius'] == '1') ? 'Radius' : (string) ($p['routers'] ?? '')
            ];

            if ($p_type === 'BALANCE') {
                if ($prepaid === 'yes') {
                    $balance_plans[] = $item;
                }
                continue;
            }
            if ($p_type === 'HOTSPOT' && $prepaid === 'no') {
                continue;
            }
            if ($cust_type === 'HOTSPOT' && $p_type === 'HOTSPOT') {
                $internet_plans[] = $item;
            } elseif ($cust_type === 'PPPOE' && $p_type === 'PPPOE') {
                $internet_plans[] = $item;
            } elseif ($cust_type !== 'HOTSPOT' && $cust_type !== 'PPPOE') {
                if ($p_type === 'HOTSPOT' || $p_type === 'PPPOE') {
                    $internet_plans[] = $item;
                }
            }
        }

        api_out(true, 'Daftar paket dimuat', [
            'customer_service_type' => $user['service_type'] ?: 'Others',
            'internet_plans'        => $internet_plans,
            'balance_plans'         => $balance_plans,
            'payment_gateways'      => get_active_gateways($config)
        ]);
        break;

    // =========================================================================
    // 4. RIWAYAT TRANSAKSI, AUTO-RENEWAL & AKTIVASI VOUCHER MANDIRI
    // =========================================================================
    case 'transactions':
        $user = require_customer_session($api_secret);
        $trx  = ORM::for_table('tbl_transactions')
            ->where('username', $user['username'])
            ->order_by_desc('id')
            ->limit(30)
            ->find_array();
        api_out(true, 'Riwayat transaksi', $trx);
        break;

    case 'toggle_renewal':
        $user               = require_customer_session($api_secret);
        $user->auto_renewal = ($user->auto_renewal == 1) ? 0 : 1;
        $user->save();
        api_out(true, 'Status perpanjangan otomatis diubah.', ['auto_renewal' => (int) $user->auto_renewal]);
        break;

    case 'redeem_voucher':
        $user     = require_customer_session($api_secret);
        $raw_code = trim((string) _post('code'));
        $code     = (class_exists('Text') && method_exists('Text', 'alphanumeric'))
            ? Text::alphanumeric($raw_code, "-_.,")
            : (function_exists('alphanumeric') ? alphanumeric($raw_code, "-_.,") : $raw_code);
        if ($code === '') {
            api_out(false, class_exists('Lang') ? Lang::T('Voucher Not Valid') : 'Kode voucher tidak boleh kosong.', null, 400);
        }
        $v1 = ORM::for_table('tbl_voucher')
            ->where_raw('BINARY code = ?', [$code])
            ->where('status', 0)
            ->find_one();
        if (function_exists('run_hook')) {
            try {
                @run_hook('customer_activate_voucher');
            } catch (Throwable $e) {
            }
        }
        if (!$v1) {
            $is_registered_cust = ORM::for_table('tbl_customers')->where('username', $code)->find_one();
            if (!$is_registered_cust) {
                $routers = ORM::for_table('tbl_routers')->where('enabled', 1)->find_many();
                foreach ($routers as $r) {
                    $client = connect_ros($r['ip_address'], $r['username'], $r['password']);
                    if (!$client) {
                        continue;
                    }
                    try {
                        $print_req = new PEAR2\Net\RouterOS\Request('/ip/hotspot/user/print');
                        $print_req->setQuery(PEAR2\Net\RouterOS\Query::where('name', $code));
                        $resp = $client->sendSync($print_req);
                        foreach ($resp as $item) {
                            if ($item->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                                $ros_id  = $item->getProperty('.id');
                                $profile = trim((string) ($item->getProperty('profile') ?: ''));
                                $uptime  = $item->getProperty('uptime') ?: '0s';
                                if ($uptime !== '0s' && $uptime !== '00:00:00' && (int) $item->getProperty('bytes-in') > 0) {
                                    api_out(false, 'Voucher MikroTik tersebut sudah pernah digunakan.', null, 400);
                                }
                                $plan = ORM::for_table('tbl_plans')
                                    ->where('name_plan', $profile)
                                    ->where('routers', $r['name'])
                                    ->find_one();
                                if (!$plan) {
                                    $plan = ORM::for_table('tbl_plans')->where('name_plan', $profile)->find_one();
                                }
                                if ($plan) {
                                    if (!empty($ros_id) && $code !== $user['username']) {
                                        $rem_req = new PEAR2\Net\RouterOS\Request('/ip/hotspot/user/remove');
                                        $rem_req->setArgument('numbers', $ros_id);
                                        @$client->sendSync($rem_req);
                                    }
                                    $v1               = ORM::for_table('tbl_voucher')->create();
                                    $v1->type         = 'Hotspot';
                                    $v1->routers      = $r['name'];
                                    $v1->id_plan      = $plan['id'];
                                    $v1->code         = $code;
                                    $v1->user         = '0';
                                    $v1->status       = '0';
                                    $v1->generated_by = 0;
                                    $v1->save();
                                    break 2;
                                }
                            }
                        }
                    } catch (Throwable $e) {
                    }
                }
            }
        }
        if ($v1) {
            if (class_exists('Package') && Package::rechargeUser($user['id'], $v1['routers'], $v1['id_plan'], 'Voucher', $code)) {
                $v1->status    = '1';
                $v1->used_date = date('Y-m-d H:i:s');
                $v1->user      = $user['username'];
                $v1->save();
                unset($_SESSION['notify'], $_SESSION['ntype']);
                api_out(true, class_exists('Lang') ? Lang::T('Activation Vouchers Successfully') : 'Voucher berhasil diaktifkan!');
            } else {
                api_out(false, 'Failed to refill account', null, 500);
            }
        }
        api_out(false, class_exists('Lang') ? Lang::T('Voucher Not Valid') : 'Kode voucher tidak ditemukan atau sudah terpakai.', null, 404);
        break;

    // =========================================================================
    // 5. PEMBELIAN PAKET / TOP-UP SALDO (VIA SALDO ATAU PAYMENT GATEWAY)
    // =========================================================================
    case 'buy':
    case 'qris_buy':
        $pg_dir = !empty($PAYMENTGATEWAY_PATH) ? $PAYMENTGATEWAY_PATH : 'system/paymentgateway';
        $user   = require_customer_session($api_secret);
        if (!empty($user['status']) && $user['status'] !== 'Active') {
            api_out(false, (class_exists('Lang') ? Lang::T('This account status') : 'Status akun') . ' : ' . $user['status'], null, 403);
        }
        $plan_id        = (int) _post('plan_id');
        $gateway        = trim((string) (_post('gateway') ?: _post('method')));
        $coupon_code    = strtoupper(trim((string) (_post('coupon_code') ?: _post('coupon'))));
        $discount       = 0.0;
        $applied_coupon = null;
        $channel_choice = trim((string) (_post('channel') ?: _post('payment_channel') ?: ''));
        if (strpos($gateway, ':') !== false) {
            list($gateway, $channel_choice) = explode(':', $gateway, 2);
        }
        $plan = ORM::for_table('tbl_plans')->where('enabled', '1')->find_one($plan_id);
        if (!$plan) {
            api_out(false, class_exists('Lang') ? Lang::T('Plan Not found') : 'Paket tidak ditemukan.', null, 404);
        }
        $cost_info = calculate_order_cost($user['id'], $plan, $config);

        if ($coupon_code !== '') {
            ensure_custom_tables();
            $cp = ORM::for_table('tbl_coupons')->where('code', $coupon_code)->where('status', 'active')->find_one();
            if (!$cp) {
                api_out(false, 'Kode kupon tidak ditemukan atau tidak aktif.', null, 400);
            }
            $today_str = date('Y-m-d');
            if (!empty($cp['start_date']) && $today_str < $cp['start_date']) {
                api_out(false, 'Kupon promo belum memasuki masa berlaku.', null, 400);
            }
            $exp_d = !empty($cp['end_date']) ? $cp['end_date'] : ($cp['expired_date'] ?? '');
            if ($exp_d !== '' && $today_str > $exp_d) {
                api_out(false, 'Kupon promo sudah kedaluwarsa.', null, 400);
            }
            if ((int) $cp['max_usage'] > 0 && (int) $cp['usage_count'] >= (int) $cp['max_usage']) {
                api_out(false, 'Kuota penggunaan kupon ini sudah habis.', null, 400);
            }
            if ((float) $cp['min_order_amount'] > 0 && (float) $cost_info['total_cost'] < (float) $cp['min_order_amount']) {
                api_out(false, 'Minimal transaksi untuk kupon ini adalah Rp ' . number_format((float) $cp['min_order_amount'], 0, ',', '.'), null, 400);
            }
            if ($cp['type'] === 'percent') {
                $discount = round(((float) $cost_info['total_cost'] * (float) $cp['value']) / 100.0);
                if ((float) $cp['max_discount_amount'] > 0 && $discount > (float) $cp['max_discount_amount']) {
                    $discount = (float) $cp['max_discount_amount'];
                }
            } else {
                $discount = (float) $cp['value'];
            }
            $applied_coupon = $cp;
        }

        $total_cost = max(0.0, (float) $cost_info['total_cost'] - $discount);

        if (strtolower($gateway) === 'balance') {
            if (($config['enable_balance'] ?? 'no') !== 'yes') {
                api_out(false, class_exists('Lang') ? Lang::T('Balance not enabled') : 'Fitur Saldo tidak diaktifkan.', null, 400);
            }
            if (strtoupper(trim((string) $plan['type'])) === 'BALANCE') {
                api_out(false, 'Topup Saldo tidak dapat dibayar menggunakan Saldo.', null, 400);
            }
            $router_name = ($plan['is_radius'] == '1') ? 'radius' : $plan['routers'];
            if ((float) $user['balance'] < $total_cost) {
                api_out(false, class_exists('Lang') ? Lang::T('Insufficient balance') : 'Saldo Anda tidak mencukupi.', null, 400);
            }
            if (class_exists('Package') && Package::rechargeUser($user['id'], $router_name, $plan['id'], 'Customer', 'Balance')) {
                if (class_exists('Balance') && method_exists('Balance', 'min')) {
                    Balance::min($user['id'], $total_cost);
                } else {
                    $user->balance = max(0.0, (float) $user['balance'] - $total_cost);
                    $user->save();
                }
                if ($applied_coupon) {
                    $applied_coupon->usage_count = (int) $applied_coupon['usage_count'] + 1;
                    $applied_coupon->save();
                }
                api_out(true, class_exists('Lang') ? Lang::T('Success to buy package') : 'Paket berhasil diaktifkan menggunakan Saldo!');
            } else {
                notify_telegram_admin("Buy Package with Balance Failed\n\n#u{$user['username']} #buy \n{$plan['name_plan']}\nRouter: {$router_name}\nPrice: {$total_cost}", true);
                api_out(false, class_exists('Lang') ? Lang::T('Failed to buy package') : 'Gagal mengaktifkan paket.', null, 500);
            }
        }

        $active_pgs = array_column(get_active_gateways($config), 'id');
        if (empty($active_pgs)) {
            notify_telegram_admin('Payment Gateway not set, please set it in Settings', true);
            api_out(false, class_exists('Lang') ? Lang::T('Failed to create Transaction..') : 'Payment Gateway belum diatur oleh Admin.', null, 400);
        }
        if ($gateway === '' || !in_array($gateway, $active_pgs, true)) {
            api_out(false, class_exists('Lang') ? Lang::T('Please select Payment Gateway') : 'Silakan pilih Payment Gateway yang tersedia.', null, 400);
        }
        $gw_file = rtrim($pg_dir, '/\\') . DIRECTORY_SEPARATOR . $gateway . '.php';
        if (!file_exists($gw_file)) {
            api_out(false, class_exists('Lang') ? Lang::T('No Payment Gateway Available') : 'File plugin Payment Gateway tidak ditemukan.', null, 400);
        }
        if (function_exists('run_hook')) {
            try {
                @run_hook('customer_buy_plan');
            } catch (Throwable $e) {
            }
        }
        include_once $gw_file;
        if (function_exists($gateway . '_validate_config')) {
            call_user_func($gateway . '_validate_config');
        }
        $channel_tpl = rtrim($pg_dir, '/\\') . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . $gateway . '_channel.tpl';
        $gw_channels = get_gateway_channels($gateway, $config, $pg_dir);
        if (file_exists($channel_tpl)) {
            $valid_ch_ids = array_column($gw_channels, 'id');
            if (empty($valid_ch_ids)) {
                api_out(false, 'Admin belum mengaktifkan channel pembayaran pada plugin ' . strtoupper($gateway) . '.', null, 400);
            }
            if ($channel_choice === '' || !in_array($channel_choice, $valid_ch_ids, true)) {
                api_out(false, 'Silakan pilih Channel Pembayaran ' . strtoupper($gateway) . ' terlebih dahulu.', null, 400);
            }
        }
        $min_req = get_gateway_min_amount(strtolower($gateway), $channel_choice);
        if ($total_cost < $min_req) {
            api_out(false, 'Minimal pembayaran untuk channel ' . ($channel_choice !== '' ? $channel_choice : strtoupper($gateway)) . ' adalah Rp ' . number_format($min_req, 0, ',', '.') . '.', null, 400);
        }

        $router = ['id' => 0, 'name' => 'balance'];
        if ($plan['is_radius'] == '1') {
            $router['id']   = 0;
            $router['name'] = 'radius';
        } elseif (strtoupper(trim((string) $plan['type'])) !== 'BALANCE' && !empty($plan['routers']) && $plan['routers'] !== 'balance') {
            $r_row = ORM::for_table('tbl_routers')->where('enabled', '1')->where('name', $plan['routers'])->find_one();
            if ($r_row) {
                $router['id']   = (int) $r_row['id'];
                $router['name'] = $r_row['name'];
            } else {
                $router['id']   = 0;
                $router['name'] = $plan['routers'];
            }
        }

        $id = 0;
        $d  = ORM::for_table('tbl_payment_gateway')
            ->where('username', $user['username'])
            ->where('status', 1)
            ->find_one();
        if ($d && !verify_already_recharged($d) && !check_and_expire_trx($d)) {
            if (!empty($d['pg_url_payment'])) {
                api_out(false, class_exists('Lang') ? Lang::T('You already have unpaid transaction, cancel it or pay it.') : 'Anda masih memiliki transaksi yang belum dibayar.', parse_qris_trx($d, $config), 400);
            } else {
                if ($gateway === $d['gateway']) {
                    $id = (int) $d['id'];
                } else {
                    $d->status = 4;
                    $d->save();
                }
            }
        }
        if (empty($id)) {
            $d = ORM::for_table('tbl_payment_gateway')->create();
        }
        $d->username     = $user['username'];
        $d->user_id      = $user['id'];
        $d->gateway      = $gateway;
        $d->plan_id      = $plan['id'];
        $d->plan_name    = $plan['name_plan'];
        $d->routers_id   = $router['id'];
        $d->routers      = $router['name'];
        $d->price        = $total_cost;
        $d->created_date = date('Y-m-d H:i:s');
        $d->status       = 1;
        if ($channel_choice !== '') {
            $d->payment_method = $channel_choice;
            foreach ($gw_channels as $ch_item) {
                if ($ch_item['id'] === $channel_choice) {
                    $d->payment_channel = $ch_item['name'];
                    break;
                }
            }
        }
        $d->save();
        $id = (int) $d->id();
        if (!$id) {
            api_out(false, class_exists('Lang') ? Lang::T('Failed to create Transaction..') : 'Gagal membuat transaksi.', null, 500);
        }
        if ($applied_coupon) {
            $applied_coupon->usage_count = (int) $applied_coupon['usage_count'] + 1;
            $applied_coupon->save();
        }
        $routes[2] = (string) $router['id'];
        $routes[3] = (string) $plan['id'];
        $routes[4] = $channel_choice;

        $GLOBALS['API_INTERCEPT_MODE']   = 'buy';
        $GLOBALS['API_INTERCEPT_TRX_ID'] = $id;
        call_user_func($gateway . '_create_transaction', $d, $user);
        $GLOBALS['API_INTERCEPT_TRX_ID'] = null;

        $updated = ORM::for_table('tbl_payment_gateway')->find_one($id);
        api_out(true, class_exists('Lang') ? Lang::T('Create Transaction Success') : 'Tagihan berhasil dibuat.', parse_qris_trx($updated, $config));
        break;

    // =========================================================================
    // 6. DETAIL, CEK STATUS & BATALKAN TAGIHAN PEMBAYARAN
    // =========================================================================
    case 'payment_detail':
        $user   = require_customer_session($api_secret);
        $trx_id = (int) _req('id');
        $trx    = ORM::for_table('tbl_payment_gateway')
            ->where('username', $user['username'])
            ->find_one($trx_id);
        if (!$trx) {
            api_out(false, 'Tagihan tidak ditemukan.', null, 404);
        }
        check_and_expire_trx($trx);
        $is_paid = verify_already_recharged($trx);
        api_out(true, $is_paid ? 'Pembayaran diterima!' : 'Menunggu pembayaran.', [
            'paid' => $is_paid,
            'bill' => parse_qris_trx($trx, $config)
        ]);
        break;

    case 'check_payment':
    case 'qris_status':
        $pg_dir = !empty($PAYMENTGATEWAY_PATH) ? $PAYMENTGATEWAY_PATH : 'system/paymentgateway';
        $user   = require_customer_session($api_secret);
        $trx_id = (int) _req('id');

        $lock_file = sys_get_temp_dir() . '/phpnuxbill_paylock_' . $trx_id . '.lock';
        $lock_fp   = @fopen($lock_file, 'c+');
        if ($lock_fp) {
            if (!@flock($lock_fp, LOCK_EX | LOCK_NB)) {
                @flock($lock_fp, LOCK_EX);
                $trx_after = ORM::for_table('tbl_payment_gateway')->where('username', $user['username'])->find_one($trx_id);
                @flock($lock_fp, LOCK_UN);
                @fclose($lock_fp);
                $is_paid_after = verify_already_recharged($trx_after);
                api_out(true, $is_paid_after ? 'Pembayaran diterima!' : 'Sedang memeriksa pembayaran...', [
                    'paid' => $is_paid_after,
                    'bill' => parse_qris_trx($trx_after, $config)
                ]);
            }
            $GLOBALS['API_LOCK_HANDLE'] = $lock_fp;
        }

        $trx = ORM::for_table('tbl_payment_gateway')
            ->where('username', $user['username'])
            ->find_one($trx_id);
        if (!$trx) {
            api_out(false, class_exists('Lang') ? Lang::T('Payment not found') : 'Tagihan tidak ditemukan.', null, 404);
        }
        check_and_expire_trx($trx);
        $gw_lower          = strtolower(trim((string) ($trx['gateway'] ?? '')));
        $can_query_gateway = ($gw_lower === 'viaqris') || !empty($trx['gateway_trx_id']);
        if (!verify_already_recharged($trx) && (int) $trx['status'] === 1 && !empty($trx['gateway']) && $can_query_gateway) {
            $gw_file = rtrim($pg_dir, '/\\') . DIRECTORY_SEPARATOR . $trx['gateway'] . '.php';
            if (!file_exists($gw_file)) {
                api_out(false, class_exists('Lang') ? Lang::T('No Payment Gateway Available') : 'Plugin Payment Gateway tidak tersedia.', null, 400);
            }
            if (function_exists('run_hook')) {
                try {
                    @run_hook('customer_check_payment_status');
                } catch (Throwable $e) {
                }
            }
            include_once $gw_file;
            $GLOBALS['API_INTERCEPT_MODE']   = 'check_payment';
            $GLOBALS['API_INTERCEPT_TRX_ID'] = $trx['id'];
            if (function_exists($trx['gateway'] . '_validate_config')) {
                @call_user_func($trx['gateway'] . '_validate_config');
            }
            if (function_exists($trx['gateway'] . '_get_status')) {
                @call_user_func($trx['gateway'] . '_get_status', $trx, $user);
            }
            $GLOBALS['API_INTERCEPT_TRX_ID'] = null;
            $trx                             = ORM::for_table('tbl_payment_gateway')->find_one($trx_id);
        }
        $is_paid = verify_already_recharged($trx);
        api_out(true, $is_paid ? (class_exists('Lang') ? Lang::T('Transaction has been paid.') : 'Pembayaran diterima!') : (class_exists('Lang') ? Lang::T('Transaction still unpaid.') : 'Menunggu pembayaran.'), [
            'paid' => $is_paid,
            'bill' => parse_qris_trx($trx, $config)
        ]);
        break;

    case 'cancel_payment':
    case 'qris_cancel':
        $user = require_customer_session($api_secret);
        $trx  = ORM::for_table('tbl_payment_gateway')
            ->where('username', $user['username'])
            ->where('status', 1)
            ->find_one((int) _req('id'));
        if ($trx && !verify_already_recharged($trx)) {
            if (function_exists('run_hook')) {
                try {
                    @run_hook('customer_cancel_payment');
                } catch (Throwable $e) {
                }
            }
            $trx->pg_paid_response = '{}';
            $trx->status           = 4;
            $trx->paid_date        = date('Y-m-d H:i:s');
            $trx->save();
        }
        api_out(true, 'Tagihan berhasil dibatalkan.');
        break;

    // =========================================================================
    // 7. INBOX PELANGGAN (tbl_customers_inbox - SESUAI mail.php)
    // =========================================================================
    case 'customer_inbox':
        $user  = require_customer_session($api_secret);
        $mails = [];
        try {
            $rows = ORM::for_table('tbl_customers_inbox')
                ->where('customer_id', $user['id'])
                ->order_by_desc('date_created')
                ->limit(40)
                ->find_array();
            foreach ($rows as $m) {
                $bodyClean = html_entity_decode(
                    strip_tags(preg_replace('/<(br|\/p|\/div)\b[^>]*>/i', "\n", (string) ($m['body'] ?? ''))),
                    ENT_QUOTES,
                    'UTF-8'
                );
                $mails[] = [
                    'id'           => (int) $m['id'],
                    'subject'      => (string) ($m['subject'] ?? '(Tanpa Subjek)'),
                    'body'         => trim($bodyClean),
                    'from'         => (string) ($m['from'] ?? 'Admin'),
                    'date_created' => (string) ($m['date_created'] ?? ''),
                    'date_read'    => (string) ($m['date_read'] ?? ''),
                    'is_read'      => !empty($m['date_read'])
                ];
            }
        } catch (Throwable $e) {
            $mails = [];
        }
        api_out(true, 'Inbox dimuat', $mails);
        break;

    case 'customer_inbox_read':
        $user = require_customer_session($api_secret);
        $id   = (int) _req('id');
        $mail = ORM::for_table('tbl_customers_inbox')
            ->where('customer_id', $user['id'])
            ->find_one($id);
        if (!$mail) {
            api_out(false, class_exists('Lang') ? Lang::T('Message Not Found') : 'Pesan tidak ditemukan.', null, 404);
        }
        if (empty($mail['date_read'])) {
            $mail->date_read = date('Y-m-d H:i:s');
            $mail->save();
        }
        api_out(true, 'Pesan ditandai telah dibaca.');
        break;

    case 'customer_inbox_delete':
        $user = require_customer_session($api_secret);
        $id   = (int) _req('id');
        $mail = ORM::for_table('tbl_customers_inbox')
            ->where('customer_id', $user['id'])
            ->find_one($id);
        if (!$mail) {
            api_out(false, class_exists('Lang') ? Lang::T('Message Not Found') : 'Pesan tidak ditemukan.', null, 404);
        }
        $mail->delete();
        api_out(true, class_exists('Lang') ? Lang::T('Mail Deleted Successfully') : 'Pesan berhasil dihapus.');
        break;

    // =========================================================================
    // 8. TRANSFER SALDO KE TEMAN (SESUAI home.php)
    // =========================================================================
    case 'customer_transfer_balance':
        $user = require_customer_session($api_secret);
        if (($config['enable_balance'] ?? 'no') !== 'yes' || ($config['allow_balance_transfer'] ?? 'no') !== 'yes') {
            api_out(false, 'Fitur transfer saldo tidak diaktifkan oleh Admin.', null, 400);
        }
        if (!empty($user['status']) && $user['status'] !== 'Active') {
            api_out(false, (class_exists('Lang') ? Lang::T('This account status') : 'Status akun') . ': ' . $user['status'], null, 403);
        }
        $target_username = trim((string) _post('username'));
        $amount          = (float) _post('amount');
        if ($target_username === '' || $amount <= 0) {
            api_out(false, 'Username tujuan dan nominal wajib diisi.', null, 400);
        }
        $target = ORM::for_table('tbl_customers')->where('username', $target_username)->find_one();
        if (!$target) {
            api_out(false, class_exists('Lang') ? Lang::T('Username not found') : 'Username tujuan tidak ditemukan.', null, 404);
        }
        if (strcasecmp($user['username'], $target['username']) === 0) {
            api_out(false, class_exists('Lang') ? Lang::T('Cannot send to yourself') : 'Tidak dapat mengirim saldo ke akun sendiri.', null, 400);
        }
        $min_trf = (float) ($config['minimum_transfer'] ?? 0);
        if ($min_trf > 0 && $amount < $min_trf) {
            api_out(false, (class_exists('Lang') ? Lang::T('Minimum Transfer') : 'Minimal transfer') . ' ' . ($config['currency_code'] ?? 'Rp.') . ' ' . number_format($min_trf, 0, ',', '.'), null, 400);
        }
        if ((float) $user['balance'] < $amount) {
            api_out(false, class_exists('Lang') ? Lang::T('insufficient balance') : 'Saldo Anda tidak mencukupi.', null, 400);
        }

        $ok = false;
        if (class_exists('Balance') && method_exists('Balance', 'transfer')) {
            $ok = (bool) Balance::transfer($user['id'], $target['username'], $amount);
        } else {
            $user->balance   = (float) $user['balance'] - $amount;
            $target->balance = (float) $target['balance'] + $amount;
            $user->save();
            $target->save();
            $ok = true;
        }
        if (!$ok) {
            api_out(false, 'Gagal memproses transfer saldo.', null, 500);
        }

        $now = date('Y-m-d H:i:s');
        foreach ([
            ['u' => $user['username'], 'gw' => $target['username'], 'name' => 'Send Balance'],
            ['u' => $target['username'], 'gw' => $user['username'], 'name' => 'Receive Balance']
        ] as $rec) {
            $d                  = ORM::for_table('tbl_payment_gateway')->create();
            $d->username        = $rec['u'];
            $d->gateway         = $rec['gw'];
            $d->plan_id         = 0;
            $d->plan_name       = $rec['name'];
            $d->routers_id      = 0;
            $d->routers         = 'balance';
            $d->price           = $amount;
            $d->payment_method  = 'Customer';
            $d->payment_channel = 'Balance';
            $d->created_date    = $now;
            $d->paid_date       = $now;
            $d->expired_date    = $now;
            $d->pg_url_payment  = 'balance';
            $d->status          = 2;
            $d->save();
        }

        queue_post_response_task(function () use ($user, $target, $amount, $config) {
            if (class_exists('Message') && method_exists('Message', 'sendBalanceNotification')) {
                @Message::sendBalanceNotification($user, $target, $amount, ((float) $user['balance'] - $amount), get_official_notif_template('balance_send'), $config['user_notification_payment'] ?? 'wa');
                @Message::sendBalanceNotification($target, $user, $amount, ((float) $target['balance'] + $amount), get_official_notif_template('balance_received'), $config['user_notification_payment'] ?? 'wa');
            }
            notify_telegram_admin("#u{$user['username']} send balance to #u{$target['username']} \n{$amount}", false);
        });

        api_out(true, class_exists('Lang') ? Lang::T('Sending balance success') : 'Transfer saldo berhasil dikirim!');
        break;

    // =========================================================================
    // 8b. CEK AKUN TEMAN & DAFTAR PAKET YANG COCOK (UNTUK KIRIM PAKET TEMAN)
    // =========================================================================
    case 'customer_lookup_friend':
        $user = require_customer_session($api_secret);
        if (($config['enable_balance'] ?? 'no') !== 'yes') {
            api_out(false, class_exists('Lang') ? Lang::T('Balance not enabled') : 'Fitur Saldo tidak diaktifkan.', null, 400);
        }
        $target_username = trim((string) (_req('username') ?: _post('username')));
        if ($target_username === '') {
            api_out(false, 'Masukkan username teman terlebih dahulu.', null, 400);
        }
        $target = ORM::for_table('tbl_customers')->where('username', $target_username)->find_one();
        if (!$target) {
            api_out(false, class_exists('Lang') ? Lang::T('Username not found') : 'Username teman tidak ditemukan.', null, 404);
        }
        if (strcasecmp($user['username'], $target['username']) === 0) {
            api_out(false, 'Gunakan menu Paket untuk membeli paket bagi akun Anda sendiri.', null, 400);
        }
        if (!empty($target['status']) && $target['status'] !== 'Active') {
            api_out(false, 'Status akun teman sedang tidak aktif (' . $target['status'] . ').', null, 400);
        }

        $target_type = strtoupper(trim((string) ($target['service_type'] ?: 'OTHERS')));

        $bw_map = [];
        try {
            foreach (ORM::for_table('tbl_bandwidth')->find_array() as $bw) {
                $bw_map[(int) $bw['id']] = trim((string) ($bw['name_bw'] ?? ''));
            }
        } catch (Throwable $e) {
        }

        $format_friend_plan = function ($p) use ($target, $config, $bw_map) {
            $p_type    = strtoupper(trim((string) $p['type']));
            $cost_info = calculate_order_cost($target['id'], $p, $config);
            $price     = (float) $cost_info['total_cost'];
            $price_old = (float) ($p['price_old'] ?? 0);
            if ($price_old <= $price) {
                $price_old = 0.0;
            }
            return [
                'id'           => (int) $p['id'],
                'name_plan'    => (string) $p['name_plan'],
                'price'        => $price,
                'price_old'    => $price_old,
                'type'         => ($p_type === 'PPPOE') ? 'PPPoE' : (($p_type === 'HOTSPOT') ? 'Hotspot' : (string) $p['type']),
                'validity'     => trim($p['validity'] . ' ' . $p['validity_unit']),
                'bandwidth'    => $bw_map[(int) ($p['id_bw'] ?? 0)] ?? '',
                'shared_users' => max(1, (int) ($p['shared_users'] ?? 1)),
                'quota_label'  => 'Unlimited',
                'routers'      => ($p['is_radius'] == '1') ? 'Radius' : (string) ($p['routers'] ?? '')
            ];
        };

        $active_ur = ORM::for_table('tbl_user_recharges')
            ->where('username', $target['username'])
            ->where('status', 'on')
            ->order_by_desc('id')
            ->find_one();

        $last_ur = $active_ur ?: ORM::for_table('tbl_user_recharges')
            ->where('username', $target['username'])
            ->order_by_desc('id')
            ->find_one();

        $last_plan = null;
        if ($last_ur && !empty($last_ur['plan_id'])) {
            $lp = ORM::for_table('tbl_plans')->where('enabled', 1)->find_one((int) $last_ur['plan_id']);
            if ($lp && strtoupper(trim((string) $lp['type'])) !== 'BALANCE') {
                $last_plan = $format_friend_plan($lp);
            }
        }

        $plans          = ORM::for_table('tbl_plans')->where('enabled', 1)->order_by_asc('price')->find_array();
        $eligible_plans = [];
        foreach ($plans as $p) {
            $p_type  = strtoupper(trim((string) $p['type']));
            $prepaid = strtolower(trim((string) ($p['prepaid'] ?? 'yes')));
            if ($p_type === 'BALANCE') {
                continue;
            }
            if ($p_type === 'HOTSPOT' && $prepaid === 'no') {
                continue;
            }
            $matches = false;
            if ($target_type === 'HOTSPOT' && $p_type === 'HOTSPOT') {
                $matches = true;
            } elseif ($target_type === 'PPPOE' && $p_type === 'PPPOE') {
                $matches = true;
            } elseif ($target_type !== 'HOTSPOT' && $target_type !== 'PPPOE') {
                if ($p_type === 'HOTSPOT' || $p_type === 'PPPOE') {
                    $matches = true;
                }
            }
            if ($matches) {
                $eligible_plans[] = $format_friend_plan($p);
            }
        }

        api_out(true, 'Akun teman ditemukan.', [
            'username'          => (string) $target['username'],
            'fullname'          => (string) ($target['fullname'] ?: $target['username']),
            'service_type'      => (string) ($target['service_type'] ?: 'Others'),
            'has_active_plan'   => (bool) ($active_ur != null),
            'active_plan_id'    => $active_ur ? (int) $active_ur['plan_id'] : 0,
            'active_plan_name'  => $active_ur ? (string) ($active_ur['namebp'] ?? '') : '',
            'active_expiration' => $active_ur ? trim(($active_ur['expiration'] ?? '') . ' ' . ($active_ur['time'] ?? '')) : '',
            'last_plan'         => $last_plan,
            'eligible_plans'    => $eligible_plans
        ]);
        break;
        
    // =========================================================================
    // 9. BELIKAN PAKET UNTUK TEMAN (SESUAI home.php & order.php case 'send')
    // =========================================================================
    case 'customer_send_plan':
        $user = require_customer_session($api_secret);
        if (($config['enable_balance'] ?? 'no') !== 'yes') {
            api_out(false, class_exists('Lang') ? Lang::T('Balance not enabled') : 'Fitur Saldo tidak diaktifkan.', null, 400);
        }
        if (!empty($user['status']) && $user['status'] !== 'Active') {
            api_out(false, (class_exists('Lang') ? Lang::T('This account status') : 'Status akun') . ': ' . $user['status'], null, 403);
        }
        $target_username = trim((string) _post('username'));
        $plan_id         = (int) _post('plan_id');
        if ($target_username === '') {
            api_out(false, 'Username teman wajib diisi.', null, 400);
        }
        $target = ORM::for_table('tbl_customers')->where('username', $target_username)->find_one();
        if (!$target) {
            api_out(false, class_exists('Lang') ? Lang::T('Username not found') : 'Username teman tidak ditemukan.', null, 404);
        }
        if (strcasecmp($user['username'], $target['username']) === 0) {
            api_out(false, 'Gunakan menu Paket untuk membeli paket bagi akun Anda sendiri.', null, 400);
        }

        // Jika plan_id tidak dipilih eksplisit, otomatis ambil paket aktif/terakhir milik teman (seperti home.php)
        if ($plan_id <= 0) {
            $friend_ur = ORM::for_table('tbl_user_recharges')
                ->where('username', $target['username'])
                ->order_by_desc('id')
                ->find_one();
            if (!$friend_ur) {
                api_out(false, class_exists('Lang') ? Lang::T('Your friend do not have active package') : 'Teman Anda belum memiliki paket yang pernah aktif. Pilih paket secara manual.', null, 404);
            }
            $plan_id = (int) $friend_ur['plan_id'];
        }

        $plan = ORM::for_table('tbl_plans')->where('enabled', 1)->find_one($plan_id);
        if (!$plan || strtoupper(trim((string) $plan['type'])) === 'BALANCE') {
            api_out(false, class_exists('Lang') ? Lang::T('Plan Not found') : 'Paket tidak ditemukan atau tidak valid.', null, 404);
        }

        $active_friend = ORM::for_table('tbl_user_recharges')
            ->where('username', $target['username'])
            ->where('status', 'on')
            ->find_one();
        if ($active_friend && (int) $active_friend['plan_id'] !== (int) $plan['id']) {
            api_out(false, 'Teman Anda masih memiliki paket aktif berbeda (' . $active_friend['namebp'] . ').', null, 400);
        }

        $cost_info  = calculate_order_cost($target['id'], $plan, $config);
        $total_cost = (float) $cost_info['total_cost'];
        if ((float) $user['balance'] < $total_cost) {
            api_out(false, class_exists('Lang') ? Lang::T('insufficient balance') : 'Saldo Anda tidak mencukupi untuk membeli paket ini.', null, 400);
        }

        $router_name = ($plan['is_radius'] == '1') ? 'radius' : $plan['routers'];
        $router_id   = 0;
        if ($router_name !== 'radius') {
            $r_row     = ORM::for_table('tbl_routers')->where('name', $router_name)->find_one();
            $router_id = $r_row ? (int) $r_row['id'] : 0;
        }

        $result_inv = (class_exists('Package')) ? Package::rechargeUser($target['id'], $router_name, $plan['id'], $user['username'], 'Balance') : false;
        if (empty($result_inv)) {
            api_out(false, class_exists('Lang') ? Lang::T('Failed to Send package') : 'Gagal mengaktifkan paket untuk teman.', null, 500);
        }

        if (class_exists('Balance') && method_exists('Balance', 'min')) {
            Balance::min($user['id'], $total_cost);
        } else {
            $user->balance = max(0.0, (float) $user['balance'] - $total_cost);
            $user->save();
        }

        $now = date('Y-m-d H:i:s');
        foreach ([
            ['u' => $user['username'], 'uid' => $user['id'], 'gw' => $target['username'], 'ch' => 'Send Plan'],
            ['u' => $target['username'], 'uid' => $target['id'], 'gw' => $user['username'], 'ch' => 'Received Plan']
        ] as $rec) {
            $d                  = ORM::for_table('tbl_payment_gateway')->create();
            $d->username        = $rec['u'];
            $d->user_id         = $rec['uid'];
            $d->gateway         = $rec['gw'];
            $d->plan_id         = $plan['id'];
            $d->plan_name       = $plan['name_plan'];
            $d->routers_id      = $router_id;
            $d->routers         = $router_name;
            $d->price           = $total_cost;
            $d->payment_method  = 'Balance';
            $d->payment_channel = $rec['ch'];
            $d->created_date    = $now;
            $d->paid_date       = $now;
            $d->expired_date    = $now;
            $d->pg_url_payment  = 'balance';
            $d->trx_invoice     = is_string($result_inv) ? $result_inv : ('INV-' . time());
            $d->status          = 2;
            $d->save();
        }

        api_out(true, 'Paket ' . $plan['name_plan'] . ' berhasil dikirim ke @' . $target['username'] . '!');
        break;

    // =========================================================================
    // 10. SINKRONISASI KONEKSI KE ROUTER (SESUAI home.php?sync=1)
    // =========================================================================
    case 'customer_sync_plan':
        $user = require_customer_session($api_secret);
        $turs = ORM::for_table('tbl_user_recharges')
            ->where('customer_id', $user['id'])
            ->where('status', 'on')
            ->find_many();
        if (count($turs) === 0) {
            api_out(false, 'Tidak ada paket aktif yang dapat disinkronkan.', null, 404);
        }
        $synced = 0;
        $notes  = [];
        foreach ($turs as $tur) {
            $p = ORM::for_table('tbl_plans')->find_one($tur['plan_id']);
            if (!$p || !class_exists('Package') || !method_exists('Package', 'getDevice')) {
                continue;
            }
            $dvc = Package::getDevice($p);
            if ($dvc && file_exists($dvc)) {
                require_once $dvc;
                $devClass = $p['device'];
                if (class_exists($devClass)) {
                    $inst = new $devClass();
                    if (method_exists($inst, 'sync_customer')) {
                        $inst->sync_customer($user, $p);
                    } elseif (method_exists($inst, 'add_customer')) {
                        $inst->add_customer($user, $p);
                    }
                    $synced++;
                    $notes[] = $tur['namebp'] . ' (' . $tur['routers'] . ')';
                }
            }
        }
        if ($synced === 0) {
            api_out(false, 'Perangkat router tidak ditemukan atau tidak mendukung sinkronisasi otomatis.', null, 400);
        }
        api_out(true, 'Sinkronisasi berhasil: ' . implode(', ', $notes));
        break;

    // =========================================================================
    // 11. EXTEND MASA TENGGANG / DARURAT (SESUAI home.php?extend=id)
    // =========================================================================
    case 'customer_extend_plan':
        $user = require_customer_session($api_secret);
        if (!empty($user['status']) && $user['status'] !== 'Active') {
            api_out(false, (class_exists('Lang') ? Lang::T('This account status') : 'Status akun') . ': ' . $user['status'], null, 403);
        }
        if (empty($config['extend_expired']) || $config['extend_expired'] === 'no' || $config['extend_expired'] === '0') {
            api_out(false, 'Fitur perpanjangan darurat tidak diaktifkan oleh Admin.', null, 400);
        }
        $recharge_id = (int) _req('id');
        $tur         = ORM::for_table('tbl_user_recharges')
            ->where('customer_id', $user['id'])
            ->find_one($recharge_id);
        if (!$tur) {
            api_out(false, 'Data layanan tidak ditemukan.', null, 404);
        }
        if ($tur['status'] === 'on') {
            api_out(false, 'Paket masih aktif, tidak memerlukan perpanjangan darurat.', null, 400);
        }

        $cache_dir = !empty($CACHE_PATH) ? $CACHE_PATH : 'system/cache';
        $root_dir  = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $ext_dir   = $root_dir . DIRECTORY_SEPARATOR . trim($cache_dir, '/\\') . DIRECTORY_SEPARATOR . 'extends';
        if (!is_dir($ext_dir)) {
            @mkdir($ext_dir, 0755, true);
        }
        $ext_file = $ext_dir . DIRECTORY_SEPARATOR . $user['id'] . '.txt';
        $month    = date('m');
        if (is_file($ext_file) && trim((string) @file_get_contents($ext_file)) === $month) {
            api_out(false, 'Anda sudah menggunakan perpanjangan darurat pada bulan ini.', null, 400);
        }

        $p = ORM::for_table('tbl_plans')->find_one($tur['plan_id']);
        if ($p && class_exists('Package') && method_exists('Package', 'getDevice')) {
            $dvc = Package::getDevice($p);
            if ($dvc && file_exists($dvc)) {
                require_once $dvc;
                global $isChangePlan;
                $isChangePlan = true;
                $devClass     = $p['device'];
                if (class_exists($devClass)) {
                    (new $devClass())->add_customer($user, $p);
                }
            }
        }
        $days            = max(1, (int) ($config['extend_days'] ?? 3));
        $expiration      = date('Y-m-d', strtotime("+{$days} days"));
        $tur->expiration = $expiration;
        $tur->status     = 'on';
        $tur->save();
        @file_put_contents($ext_file, $month);

        if (function_exists('_log')) {
            @_log("Customer {$tur['customer_id']} {$user['fullname']} ({$tur['username']}) extend for {$days} days", 'Customer', $user['id']);
        }
        notify_telegram_admin("#u{$user['username']} ({$user['fullname']}) #extend\nPaket: {$tur['namebp']}\nAktif s/d: {$expiration}", true);
        api_out(true, "Masa tenggang berhasil diaktifkan hingga {$expiration} (+{$days} hari).");
        break;

    // =========================================================================
    // 12. EDIT PROFIL & UPLOAD FOTO PROFIL PELANGGAN (SESUAI accounts.php)
    // =========================================================================
    case 'customer_profile_save':
        $user        = require_customer_session($api_secret);
        $fullname    = trim((string) _post('fullname'));
        $address     = trim((string) _post('address'));
        $phonenumber = trim((string) _post('phonenumber'));
        $email       = trim((string) _post('email'));
        $photo_b64   = trim((string) _post('photo_base64'));

        if ($fullname === '' || strlen($fullname) > 60) {
            api_out(false, 'Nama lengkap wajib diisi (1-60 karakter).', null, 400);
        }

        // Proses upload foto profil (Base64 dari Galeri HP -> simpan ke system/uploads/photos/... + .thumb.jpg)
        if ($photo_b64 !== '') {
            if (strpos($photo_b64, 'base64,') !== false) {
                $photo_b64 = substr($photo_b64, strpos($photo_b64, 'base64,') + 7);
            }
            $binary = base64_decode($photo_b64, true);
            if ($binary !== false && strlen($binary) > 100 && strlen($binary) <= 5 * 1024 * 1024) {
                $root_dir  = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
                $up_dir    = !empty($UPLOAD_PATH) ? trim($UPLOAD_PATH, '/\\') : 'system/uploads';
                $hash      = md5($binary);
                $subfolder = substr($hash, 0, 2);
                $photoDir  = $root_dir . DIRECTORY_SEPARATOR . $up_dir . DIRECTORY_SEPARATOR . 'photos' . DIRECTORY_SEPARATOR . $subfolder;
                if (!is_dir($photoDir)) {
                    @mkdir($photoDir, 0755, true);
                }
                $imgPath   = $photoDir . DIRECTORY_SEPARATOR . $hash . '.jpg';
                $thumbPath = $imgPath . '.thumb.jpg';
                $saved     = false;

                if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
                    $src = @imagecreatefromstring($binary);
                    if ($src !== false) {
                        $sw   = imagesx($src);
                        $sh   = imagesy($src);
                        $side = min($sw, $sh);
                        $sx   = (int) (($sw - $side) / 2);
                        $sy   = (int) (($sh - $side) / 2);

                        // Simpan foto utama 600x600
                        $mainImg = imagecreatetruecolor(600, 600);
                        imagecopyresampled($mainImg, $src, 0, 0, $sx, $sy, 600, 600, $side, $side);
                        @imagejpeg($mainImg, $imgPath, 88);
                        imagedestroy($mainImg);

                        // Simpan thumbnail 200x200 (.thumb.jpg sesuai accounts.php)
                        $thumbImg = imagecreatetruecolor(200, 200);
                        imagecopyresampled($thumbImg, $src, 0, 0, $sx, $sy, 200, 200, $side, $side);
                        @imagejpeg($thumbImg, $thumbPath, 85);
                        imagedestroy($thumbImg);
                        imagedestroy($src);
                        $saved = is_file($imgPath);
                    }
                } else {
                    @file_put_contents($imgPath, $binary);
                    @file_put_contents($thumbPath, $binary);
                    $saved = is_file($imgPath);
                }

                if ($saved) {
                    $oldRel = trim((string) ($user['photo'] ?? ''));
                    if ($oldRel !== '' && strpos($oldRel, 'default') === false) {
                        $oldAbs = $root_dir . DIRECTORY_SEPARATOR . $up_dir . str_replace('/', DIRECTORY_SEPARATOR, '/' . ltrim($oldRel, '/'));
                        if (is_file($oldAbs)) {
                            @unlink($oldAbs);
                        }
                        if (is_file($oldAbs . '.thumb.jpg')) {
                            @unlink($oldAbs . '.thumb.jpg');
                        }
                    }
                    $user->photo = '/photos/' . $subfolder . '/' . $hash . '.jpg';
                }
            }
        }

        $user->fullname = $fullname;
        $user->address  = $address;
        if (($config['allow_phone_otp'] ?? 'no') !== 'yes' && $phonenumber !== '') {
            $user->phonenumber = $phonenumber;
        }
        if (($config['allow_email_otp'] ?? 'no') !== 'yes' && $email !== '') {
            $user->email = $email;
        }
        $user->save();

        if (function_exists('_log')) {
            @_log('[' . $user['username'] . ']: ' . (class_exists('Lang') ? Lang::T('User Updated Successfully') : 'User Updated Successfully'), 'User', $user['id']);
        }
        api_out(true, class_exists('Lang') ? Lang::T('User Updated Successfully') : 'Profil berhasil diperbarui.', [
            'photo_url' => portal_resolve_customer_photo_url($user)
        ]);
        break;

    // =========================================================================
    // 13. GANTI KATA SANDI PELANGGAN + SINKRON ROUTER (SESUAI accounts.php)
    // =========================================================================
    case 'customer_change_password':
        $user         = require_customer_session($api_secret);
        $old_password = (string) _post('old_password');
        $new_password = (string) _post('new_password');
        $confirm_pass = (string) _post('confirm_password');

        if ($old_password === '' || !check_user_password($old_password, $user['password'])) {
            api_out(false, class_exists('Lang') ? Lang::T('Incorrect Current Password') : 'Kata sandi saat ini salah.', null, 400);
        }
        if (strlen($new_password) < 4 || strlen($new_password) > 35) {
            api_out(false, 'Kata sandi baru harus 4 sampai 35 karakter.', null, 400);
        }
        if ($new_password !== $confirm_pass) {
            api_out(false, 'Konfirmasi kata sandi baru tidak cocok.', null, 400);
        }

        $user->password = $new_password;
        $turs           = ORM::for_table('tbl_user_recharges')->where('customer_id', $user['id'])->find_many();
        foreach ($turs as $tur) {
            if ($tur['status'] === 'on' && class_exists('Package') && method_exists('Package', 'getDevice')) {
                $p = ORM::for_table('tbl_plans')->where('id', $tur['plan_id'])->find_one();
                if ($p) {
                    $dvc = Package::getDevice($p);
                    if ($dvc && file_exists($dvc)) {
                        try {
                            require_once $dvc;
                            $devClass = $p['device'];
                            if (class_exists($devClass)) {
                                (new $devClass())->add_customer($user, $p);
                            }
                        } catch (Throwable $e) {
                        }
                    }
                }
            }
        }
        $user->save();

        if (function_exists('_log')) {
            @_log('[' . $user['username'] . ']: Password changed successfully', 'User', $user['id']);
        }
        api_out(true, 'Kata sandi berhasil diubah.');
        break;
}
