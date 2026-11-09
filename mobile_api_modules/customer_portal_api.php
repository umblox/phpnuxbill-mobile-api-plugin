<?php
/**
 * PHPNuxBill Mobile API Plugin - Customer Mobile App Portal Module
 * Path: system/plugin/mobile_api_modules/customer_portal_api.php
 */

switch ($action) {
    // =========================================================================
    // ENDPOINT APK PELANGGAN (MENGGUNAKAN FUNGSI ASLI VERSI WEB & SYSTEM/API.PHP)
    // =========================================================================
    case 'login':
        $username = trim(_post('username'));
        $password = _post('password');

        if (empty($username) || empty($password)) {
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
        api_out(true, class_exists('Lang') ? Lang::T('Login Successful') : 'Login berhasil', [
            'token'        => $native_token,
            'native_token' => $native_token
        ]);
        break;

    case 'dashboard':
        $user = require_customer_session($api_secret);
        if (class_exists('User') && method_exists('User', '_info')) {
            $u_info = User::_info($user['id']);
            if ($u_info) $user = $u_info;
        }

        $recharges = [];
        if (class_exists('User') && method_exists('User', '_billing')) {
            $bills = User::_billing($user['id']);
            if ($bills) {
                foreach ($bills as $b) {
                    $recharges[] = ($b instanceof ORM) ? $b->as_array() : (array)$b;
                }
            }
        } else {
            $recharges = ORM::for_table('tbl_user_recharges')->where('customer_id', $user['id'])->order_by_desc('id')->find_array();
        }

        foreach ($recharges as &$b_item) {
            if ($b_item['status'] === 'on' && !empty($b_item['expiration']) && time() > strtotime($b_item['expiration'] . ' ' . ($b_item['time'] ?: '23:59:59'))) {
                $ur_obj = ORM::for_table('tbl_user_recharges')->find_one($b_item['id']);
                if ($ur_obj) {
                    if (($config['enable_balance'] ?? 'no') === 'yes' && (int)$user['auto_renewal'] === 1 && class_exists('Package')) {
                        $plan_info = ORM::for_table('tbl_plans')->find_one($ur_obj['plan_id']);
                        if ($plan_info && (int)$plan_info['enabled'] === 1 && (float)$user['balance'] >= (float)$plan_info['price']) {
                            if (@Package::rechargeUser($user['id'], $plan_info['routers'], $plan_info['id'], 'Customer', 'Balance')) {
                                if (class_exists('Balance')) Balance::min($user['id'], $plan_info['price']);
                                $user = ORM::for_table('tbl_customers')->find_one($user['id']);
                                $ur_fresh = ORM::for_table('tbl_user_recharges')->find_one($b_item['id']);
                                if ($ur_fresh) $b_item = $ur_fresh->as_array();
                                continue;
                            }
                        }
                    }
                }
            }
        }
        unset($b_item);

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
                if ((int)$parsed['status'] === 1) {
                    $unpaid_bills[] = $parsed;
                }
            }
        }

        api_out(true, 'Data beranda dimuat', [
            'company' => [
                'name'           => $config['CompanyName'] ?? 'Arneta.ID',
                'phone'          => $config['phone'] ?? '',
                'address'        => $config['address'] ?? '',
                'currency'       => $config['currency_code'] ?? 'Rp',
                'enable_balance' => ($config['enable_balance'] ?? 'yes') === 'yes'
            ],
            'customer' => [
                'id'           => (int)$user['id'],
                'username'     => $user['username'],
                'fullname'     => $user['fullname'],
                'phonenumber'  => $user['phonenumber'],
                'email'        => $user['email'],
                'address'      => $user['address'],
                'balance'      => (float)$user['balance'],
                'service_type' => $user['service_type'] ?: 'Others',
                'auto_renewal' => (int)$user['auto_renewal']
            ],
            'payment_gateways' => get_active_gateways($config),
            'active_packages'  => $recharges,
            'unpaid_bills'     => $unpaid_bills,
            'unpaid_bill'      => $unpaid_bills[0] ?? null,
            'order_history'    => $order_history
        ]);
        break;

    case 'plans':
        $user = require_customer_session($api_secret);
        $cust_type = strtoupper(trim($user['service_type'] ?: 'OTHERS'));

        $plans = ORM::for_table('tbl_plans')
            ->where('enabled', 1)
            ->order_by_asc('price')
            ->find_array();

        $internet_plans = [];
        $balance_plans  = [];

        foreach ($plans as $p) {
            $p_type  = strtoupper(trim($p['type']));
            $prepaid = strtolower(trim($p['prepaid'] ?? 'yes'));

            $item = [
                'id'        => (int)$p['id'],
                'name_plan' => $p['name_plan'],
                'price'     => (float)$p['price'],
                'type'      => ($p_type === 'PPPOE') ? 'PPPoE' : (($p_type === 'HOTSPOT') ? 'Hotspot' : $p['type']),
                'prepaid'   => $p['prepaid'],
                'enabled'   => (int)$p['enabled'],
                'validity'  => $p['validity'] . ' ' . $p['validity_unit'],
                'routers'   => $p['routers']
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
            'internet_plans'   => $internet_plans,
            'balance_plans'    => $balance_plans,
            'payment_gateways' => get_active_gateways($config)
        ]);
        break;

    case 'transactions':
        $user = require_customer_session($api_secret);
        $trx = ORM::for_table('tbl_transactions')
            ->where('username', $user['username'])
            ->order_by_desc('id')
            ->limit(30)
            ->find_array();
        api_out(true, 'Riwayat transaksi', $trx);
        break;

    case 'toggle_renewal':
        $user = require_customer_session($api_secret);
        $user->auto_renewal = ($user->auto_renewal == 1) ? 0 : 1;
        $user->save();
        api_out(true, 'Status perpanjangan otomatis diubah.', ['auto_renewal' => (int)$user->auto_renewal]);
        break;

    case 'redeem_voucher':
        $user = require_customer_session($api_secret);
        $raw_code = trim(_post('code'));
        $code = function_exists('alphanumeric') ? alphanumeric($raw_code, "-_.,") : $raw_code;

        if (empty($code)) {
            api_out(false, class_exists('Lang') ? Lang::T('Voucher Not Valid') : 'Kode voucher tidak boleh kosong.', null, 400);
        }

        $v1 = ORM::for_table('tbl_voucher')
            ->where_raw("BINARY code = ?", [$code])
            ->where('status', 0)
            ->find_one();

        if (function_exists('run_hook')) {
            @run_hook('customer_activate_voucher');
        }

        if (!$v1) {
            $is_registered_cust = ORM::for_table('tbl_customers')->where('username', $code)->find_one();
            if (!$is_registered_cust) {
                $routers = ORM::for_table('tbl_routers')->where('enabled', 1)->find_many();
                foreach ($routers as $r) {
                    $client = connect_ros($r['ip_address'], $r['username'], $r['password']);
                    if (!$client) continue;
                    try {
                        $print_req = new PEAR2\Net\RouterOS\Request('/ip/hotspot/user/print');
                        $print_req->setQuery(PEAR2\Net\RouterOS\Query::where('name', $code));
                        $resp = $client->sendSync($print_req);
                        foreach ($resp as $item) {
                            if ($item->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                                $ros_id  = $item->getProperty('.id');
                                $profile = trim($item->getProperty('profile') ?: '');
                                $uptime  = $item->getProperty('uptime') ?: '0s';

                                if ($uptime !== '0s' && $uptime !== '00:00:00' && (int)$item->getProperty('bytes-in') > 0) {
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

                                    $v1 = ORM::for_table('tbl_voucher')->create();
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
                    } catch (Exception $e) {}
                }
            }
        }

        if ($v1) {
            if (class_exists('Package') && Package::rechargeUser($user['id'], $v1['routers'], $v1['id_plan'], "Voucher", $code)) {
                $v1->status    = "1";
                $v1->used_date = date('Y-m-d H:i:s');
                $v1->user      = $user['username'];
                $v1->save();
                unset($_SESSION['notify'], $_SESSION['ntype']);
                api_out(true, class_exists('Lang') ? Lang::T('Activation Vouchers Successfully') : 'Voucher berhasil diaktifkan!');
            } else {
                api_out(false, "Failed to refill account", null, 500);
            }
        } else {
            api_out(false, class_exists('Lang') ? Lang::T('Voucher Not Valid') : 'Kode voucher tidak ditemukan atau sudah terpakai.', null, 404);
        }
        break;

    case 'buy':
    case 'qris_buy':
        global $PAYMENTGATEWAY_PATH, $routes;
        $pg_dir = !empty($PAYMENTGATEWAY_PATH) ? $PAYMENTGATEWAY_PATH : 'system/paymentgateway';

        $user = require_customer_session($api_secret);
        if (!empty($user['status']) && $user['status'] !== 'Active') {
            api_out(false, (class_exists('Lang') ? Lang::T('This account status') : 'Status akun') . ' : ' . $user['status'], null, 403);
        }

        $plan_id = (int)_post('plan_id');
        $gateway = trim(_post('gateway') ?: _post('method'));
        $discount = (float)(_post('discount') ?: 0);
        $channel_choice = trim(_post('channel') ?: _post('payment_channel') ?: '');

        if (strpos($gateway, ':') !== false) {
            list($gateway, $channel_choice) = explode(':', $gateway, 2);
        }

        $plan = ORM::for_table('tbl_plans')->where('enabled', '1')->find_one($plan_id);
        if (!$plan) {
            api_out(false, class_exists('Lang') ? Lang::T('Plan Not found') : 'Paket tidak ditemukan.', null, 404);
        }

        $cost_info  = calculate_order_cost($user['id'], $plan, $config);
        $total_cost = max(0, $cost_info['total_cost'] - $discount);

        if ($gateway === 'balance') {
            if (($config['enable_balance'] ?? 'no') !== 'yes') {
                api_out(false, class_exists('Lang') ? Lang::T('Balance not enabled') : 'Fitur Saldo tidak diaktifkan.', null, 400);
            }
            if (strtoupper(trim($plan['type'])) === 'BALANCE') {
                api_out(false, 'Topup Saldo tidak dapat dibayar menggunakan Saldo.', null, 400);
            }

            $router_name = ($plan['is_radius'] == '1') ? 'radius' : $plan['routers'];
            if ((float)$user['balance'] < $total_cost) {
                api_out(false, class_exists('Lang') ? Lang::T('Insufficient balance') : 'Saldo Anda tidak mencukupi.', null, 400);
            }

            if (class_exists('Package') && Package::rechargeUser($user['id'], $router_name, $plan['id'], 'Customer', 'Balance')) {
                if (class_exists('Balance') && method_exists('Balance', 'min')) {
                    Balance::min($user['id'], $total_cost);
                } else {
                    $user->balance = (float)$user['balance'] - $total_cost;
                    $user->save();
                }
                api_out(true, class_exists('Lang') ? Lang::T('Success to buy package') : 'Paket berhasil diaktifkan menggunakan Saldo!');
            } else {
                sendTelegram("Buy Package with Balance Failed\n\n#u{$user['username']} #buy \n{$plan['name_plan']}\nRouter: {$router_name}\nPrice: {$total_cost}");
                api_out(false, class_exists('Lang') ? Lang::T('Failed to buy package') : 'Gagal mengaktifkan paket.', null, 500);
            }
        }

        $active_pgs = array_column(get_active_gateways($config), 'id');
        if (empty($active_pgs)) {
            sendTelegram("Payment Gateway not set, please set it in Settings");
            api_out(false, class_exists('Lang') ? Lang::T('Failed to create Transaction..') : 'Payment Gateway belum diatur oleh Admin.', null, 400);
        }

        if (empty($gateway) || !in_array($gateway, $active_pgs)) {
            api_out(false, class_exists('Lang') ? Lang::T('Please select Payment Gateway') : 'Silakan pilih Payment Gateway yang tersedia.', null, 400);
        }

        $gw_file = rtrim($pg_dir, '/\\') . DIRECTORY_SEPARATOR . $gateway . '.php';
        if (!file_exists($gw_file)) {
            api_out(false, class_exists('Lang') ? Lang::T('No Payment Gateway Available') : 'File plugin Payment Gateway tidak ditemukan.', null, 400);
        }

        if (function_exists('run_hook')) {
            @run_hook('customer_buy_plan');
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
            if (empty($channel_choice) || !in_array($channel_choice, $valid_ch_ids)) {
                api_out(false, 'Silakan pilih Channel Pembayaran ' . strtoupper($gateway) . ' terlebih dahulu.', null, 400);
            }
        }

        $min_req = get_gateway_min_amount(strtolower($gateway), $channel_choice);
        if ($total_cost < $min_req) {
            api_out(false, 'Minimal pembayaran untuk channel ' . ($channel_choice ?: strtoupper($gateway)) . ' adalah Rp ' . number_format($min_req, 0, ',', '.') . '. Silakan pilih channel QRIS / E-Wallet atau lakukan Topup Saldo terlebih dahulu.', null, 400);
        }

        $router = ['id' => 0, 'name' => 'balance'];
        if ($plan['is_radius'] == '1') {
            $router['id']   = 0;
            $router['name'] = 'radius';
        } elseif (strtoupper(trim($plan['type'])) !== 'BALANCE' && !empty($plan['routers']) && $plan['routers'] !== 'balance') {
            $r_row = ORM::for_table('tbl_routers')->where('enabled', '1')->where('name', $plan['routers'])->find_one();
            if ($r_row) {
                $router['id']   = (int)$r_row['id'];
                $router['name'] = $r_row['name'];
            } else {
                $router['id']   = 0;
                $router['name'] = $plan['routers'];
            }
        }

        $id = 0;
        $d = ORM::for_table('tbl_payment_gateway')
            ->where('username', $user['username'])
            ->where('status', 1)
            ->find_one();

        if ($d && !verify_already_recharged($d) && !check_and_expire_trx($d)) {
            if (!empty($d['pg_url_payment'])) {
                api_out(false, class_exists('Lang') ? Lang::T('You already have unpaid transaction, cancel it or pay it.') : 'Anda masih memiliki transaksi yang belum dibayar.', parse_qris_trx($d, $config), 400);
            } else {
                if ($gateway === $d['gateway']) {
                    $id = (int)$d['id'];
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
        if (!empty($channel_choice)) {
            $d->payment_method = $channel_choice;
            foreach ($gw_channels as $ch_item) {
                if ($ch_item['id'] === $channel_choice) {
                    $d->payment_channel = $ch_item['name'];
                    break;
                }
            }
        }
        $d->save();
        $id = (int)$d->id();

        if (!$id) {
            api_out(false, class_exists('Lang') ? Lang::T('Failed to create Transaction..') : 'Gagal membuat transaksi.', null, 500);
        }

        $routes[2] = (string)$router['id'];
        $routes[3] = (string)$plan['id'];
        $routes[4] = $channel_choice;

        $GLOBALS['API_INTERCEPT_MODE']   = 'buy';
        $GLOBALS['API_INTERCEPT_TRX_ID'] = $id;
        call_user_func($gateway . '_create_transaction', $d, $user);
        $GLOBALS['API_INTERCEPT_TRX_ID'] = null;

        $updated = ORM::for_table('tbl_payment_gateway')->find_one($id);
        api_out(true, class_exists('Lang') ? Lang::T('Create Transaction Success') : 'Tagihan berhasil dibuat.', parse_qris_trx($updated, $config));
        break;

    case 'payment_detail':
        $user = require_customer_session($api_secret);
        $trx_id = (int)_req('id');
        $trx = ORM::for_table('tbl_payment_gateway')
            ->where('username', $user['username'])
            ->find_one($trx_id);

        if (!$trx) api_out(false, 'Tagihan tidak ditemukan.', null, 404);
        check_and_expire_trx($trx);
        $is_paid = verify_already_recharged($trx);
        api_out(true, $is_paid ? 'Pembayaran diterima!' : 'Menunggu pembayaran.', [
            'paid' => $is_paid,
            'bill' => parse_qris_trx($trx, $config)
        ]);
        break;

    case 'check_payment':
    case 'qris_status':
        global $PAYMENTGATEWAY_PATH;
        $pg_dir = !empty($PAYMENTGATEWAY_PATH) ? $PAYMENTGATEWAY_PATH : 'system/paymentgateway';

        $user = require_customer_session($api_secret);
        $trx_id = (int)_req('id');

        $lock_file = sys_get_temp_dir() . '/arneta_paylock_' . $trx_id . '.lock';
        $lock_fp = @fopen($lock_file, 'c+');
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

        $gw_lower = strtolower(trim((string)($trx['gateway'] ?? '')));
        $can_query_gateway = ($gw_lower === 'viaqris') || !empty($trx['gateway_trx_id']);

        if (!verify_already_recharged($trx) && (int)$trx['status'] === 1 && !empty($trx['gateway']) && $can_query_gateway) {
            $gw_file = rtrim($pg_dir, '/\\') . DIRECTORY_SEPARATOR . $trx['gateway'] . '.php';
            if (!file_exists($gw_file)) {
                api_out(false, class_exists('Lang') ? Lang::T('No Payment Gateway Available') : 'Plugin Payment Gateway tidak tersedia.', null, 400);
            }
            if (function_exists('run_hook')) {
                @run_hook('customer_check_payment_status');
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
            $trx = ORM::for_table('tbl_payment_gateway')->find_one($trx_id);
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
        $trx = ORM::for_table('tbl_payment_gateway')
            ->where('username', $user['username'])
            ->where('status', 1)
            ->find_one((int)_req('id'));

        if ($trx && !verify_already_recharged($trx)) {
            if (function_exists('run_hook')) {
                @run_hook('customer_cancel_payment');
            }
            $trx->pg_paid_response = '{}';
            $trx->status           = 4;
            $trx->paid_date        = date('Y-m-d H:i:s');
            $trx->save();
        }
        api_out(true, 'Tagihan berhasil dibatalkan.');
        break;
}
