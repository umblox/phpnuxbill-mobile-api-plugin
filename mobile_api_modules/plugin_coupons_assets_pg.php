<?php
/**
 * PHPNuxBill Mobile API Plugin - Map, Coupons, Asset Manager & Payment Gateway Audit Module
 * Path: system/plugin/mobile_api_modules/plugin_coupons_assets_pg.php
 */

global $config, $_c, $admin, $ui, $UPLOAD_PATH, $DEVICE_PATH, $PAYMENTGATEWAY_PATH, $phpnux_root;

if (!function_exists('get_valid_payment_gateway_names')) {
    function get_valid_payment_gateway_names($pg_dir, $config) {
        $known = [
            'viaqris'  => true,
            'tripay'   => true,
            'duitku'   => true,
            'xendit'   => true,
            'paypal'   => true,
            'midtrans' => true,
            'ipaymu'   => true,
            'tokopay'  => true,
            'flip'     => true,
            'moota'    => true,
            'oy'       => true,
            'doku'     => true,
            'faspay'   => true,
            'nicepay'  => true,
            'stripe'   => true
        ];

        $root_dir = !empty($GLOBALS['phpnux_root']) ? $GLOBALS['phpnux_root'] : dirname(__DIR__, 3);
        $scan_dirs = [
            rtrim($pg_dir, '/\\'),
            $root_dir . DIRECTORY_SEPARATOR . ltrim(rtrim($pg_dir, '/\\'), '/\\')
        ];

        foreach ($scan_dirs as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $f) {
                    $base = strtolower(basename($f, '.php'));
                    if ($base !== '' && $base !== 'index') {
                        $known[$base] = true;
                    }
                }
            }
        }

        $cfg_pgs = array_filter(array_map('trim', explode(',', strtolower((string)($config['payment_gateway'] ?? '')))));
        foreach ($cfg_pgs as $g) {
            if ($g !== '' && $g !== 'none') {
                $known[$g] = true;
            }
        }

        return $known;
    }
}

switch ($action) {
    // =========================================================================
    // PETA, KUPON PROMO, ASSET MANAGER & AUDIT PAYMENT GATEWAY
    // =========================================================================
    case 'map_all':
    case 'admin_map_data':
        require_admin_session($api_secret);
        ensure_custom_tables();

        $active_map = [];
        foreach (ORM::for_table('tbl_user_recharges')->find_array() as $r) {
            $cid = (int)$r['customer_id'];
            if (!isset($active_map[$cid]) || $r['status'] === 'on') $active_map[$cid] = $r;
        }

        $customers = [];
        foreach (ORM::for_table('tbl_customers')->where_not_equal('coordinates', '')->where_not_null('coordinates')->find_array() as $c) {
            $p = array_map('trim', explode(',', $c['coordinates']));
            if (count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1])) continue;
            $rech = $active_map[(int)$c['id']] ?? null;
            $customers[] = [
                'id'           => (int)$c['id'],
                'username'     => $c['username'] ?: '-',
                'fullname'     => $c['fullname'] ?: $c['username'],
                'phonenumber'  => $c['phonenumber'] ?: '-',
                'address'      => str_replace(["\r", "\n"], ' ', $c['address'] ?: '-'),
                'service_type' => $c['service_type'] ?: 'Hotspot',
                'lat'          => (float)$p[0],
                'lng'          => (float)$p[1],
                'plan_status'  => $rech ? $rech['status'] : 'off',
                'plan_name'    => $rech ? $rech['namebp'] : 'Non-Paket',
                'expiration'   => $rech ? $rech['expiration'] : '-'
            ];
        }

        $routers = [];
        foreach (ORM::for_table('tbl_routers')->find_array() as $r) {
            if (empty($r['coordinates'])) continue;
            $p = array_map('trim', explode(',', $r['coordinates']));
            if (count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1])) continue;
            $routers[] = [
                'id'          => (int)$r['id'],
                'name'        => $r['name'],
                'ip_address'  => $r['ip_address'],
                'description' => str_replace(["\r", "\n"], ' ', $r['description'] ?: ''),
                'coverage'    => (int)($r['coverage'] ?: 350),
                'lat'         => (float)$p[0],
                'lng'         => (float)$p[1]
            ];
        }

        $assets = [];
        try {
            foreach (ORM::for_table('tbl_assets')->find_array() as $a) {
                if (empty($a['coordinates'])) continue;
                $p = array_map('trim', explode(',', $a['coordinates']));
                if (count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1])) continue;
                $assets[] = [
                    'id'        => (int)$a['id'],
                    'name'      => $a['name'],
                    'category'  => $a['category'],
                    'condition' => $a['condition_status'],
                    'lat'       => (float)$p[0],
                    'lng'       => (float)$p[1]
                ];
            }
        } catch (Throwable $e) {}

        api_out(true, 'Data peta berhasil dimuat', [
            'customers' => $customers,
            'routers'   => $routers,
            'assets'    => $assets
        ]);
        break;

    case 'coupons':
    case 'admin_coupons':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin', 'Sales']);
        ensure_custom_tables();
        $search = trim(_req('search'));
        $status_filter = trim(_req('status'));

        $q = ORM::for_table('tbl_coupons')->order_by_desc('id');
        if ($status_filter !== '' && strtolower($status_filter) !== 'all') {
            $q->where('status', strtolower($status_filter));
        }
        if ($search !== '') {
            $s_like = "%$search%";
            $q->where_raw(
                "(code LIKE ? OR type LIKE ? OR description LIKE ? OR status LIKE ?)",
                [$s_like, $s_like, $s_like, $s_like]
            );
        }

        $rows = $q->find_array();
        foreach ($rows as &$r) {
            if (empty($r['end_date']) && !empty($r['expired_date'])) {
                $r['end_date'] = $r['expired_date'];
            }
            if (empty($r['expired_date']) && !empty($r['end_date'])) {
                $r['expired_date'] = $r['end_date'];
            }
            if (empty($r['start_date'])) {
                $r['start_date'] = !empty($r['created_at']) ? substr($r['created_at'], 0, 10) : date('Y-m-d');
            }
        }
        unset($r);
        api_out(true, 'Daftar kupon promo dimuat', $rows);
        break;

    case 'admin_coupon_save':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin', 'Sales']);
        ensure_custom_tables();

        $id   = (int)_post('id');
        $raw_code = _post('code', '');
        $code = class_exists('Text') && method_exists('Text', 'alphanumeric')
            ? strtoupper(Text::alphanumeric($raw_code))
            : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw_code));
        $type = (_post('type') === 'percent') ? 'percent' : 'fixed';
        $value = (float)_post('value', 0);
        $description = trim(_post('description', 'Promo Diskon Layanan'));
        $max_usage = max(0, (int)_post('max_usage', 0));
        $min_order_amount = max(0.0, (float)_post('min_order_amount', 0));
        $max_discount_amount = max(0, (int)_post('max_discount_amount', 0));
        $status = (_post('status') === 'inactive') ? 'inactive' : 'active';

        $raw_start = trim(_post('start_date') ?: date('Y-m-d'));
        $raw_end   = trim(_post('end_date') ?: _post('expired_date') ?: date('Y-m-d', strtotime('+30 days')));
        $start_ts  = strtotime($raw_start);
        $end_ts    = strtotime($raw_end);

        if (empty($code)) {
            api_out(false, class_exists('Lang') ? Lang::T('Coupon Code is required') : 'Kode kupon wajib diisi.', null, 400);
        }
        if ($value <= 0) {
            api_out(false, class_exists('Lang') ? Lang::T('Coupon Value is required') : 'Nilai potongan kupon harus lebih dari 0.', null, 400);
        }
        if (!$start_ts || !$end_ts) {
            api_out(false, 'Format tanggal mulai / berakhir kupon tidak valid.', null, 400);
        }

        if ($id > 0) {
            $coupon = ORM::for_table('tbl_coupons')->find_one($id);
            if (!$coupon) {
                api_out(false, class_exists('Lang') ? Lang::T('Coupon Not Found') : 'Kupon tidak ditemukan.', null, 404);
            }
            if (strtoupper($coupon['code']) !== $code) {
                $dup = ORM::for_table('tbl_coupons')->where('code', $code)->find_one();
                if ($dup) {
                    api_out(false, class_exists('Lang') ? Lang::T('Coupon Code already exists') : 'Kode kupon sudah digunakan.', null, 400);
                }
            }
        } else {
            $dup = ORM::for_table('tbl_coupons')->where('code', $code)->find_one();
            if ($dup) {
                api_out(false, class_exists('Lang') ? Lang::T('Coupon Code already exists') : 'Kode kupon sudah terdaftar.', null, 400);
            }
            $coupon = ORM::for_table('tbl_coupons')->create();
            $coupon->usage_count = 0;
            $coupon->created_at  = date('Y-m-d H:i:s');
        }

        $coupon->code                = $code;
        $coupon->type                = $type;
        $coupon->value               = $value;
        $coupon->description         = $description !== '' ? $description : "Diskon Kupon $code";
        $coupon->max_usage           = $max_usage;
        $coupon->min_order_amount    = $min_order_amount;
        $coupon->max_discount_amount = $max_discount_amount > 0 ? $max_discount_amount : (int)$value;
        $coupon->status              = $status;
        $coupon->start_date          = date('Y-m-d', $start_ts);
        $coupon->end_date            = date('Y-m-d', $end_ts);
        $coupon->expired_date        = date('Y-m-d', $end_ts);
        if ($id > 0) {
            $coupon->updated_at = date('Y-m-d H:i:s');
        }
        $coupon->save();

        api_out(
            true,
            class_exists('Lang')
                ? Lang::T($id > 0 ? 'Coupon has been updated successfully' : 'Coupon has been added successfully')
                : ('Kupon ' . $code . ' berhasil disimpan.'),
            $coupon->as_array()
        );
        break;

    case 'admin_coupon_status':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin', 'Sales']);
        ensure_custom_tables();
        $id     = (int)(_req('id') ?: _req('coupon_id'));
        $status = (_req('status') === 'inactive') ? 'inactive' : 'active';

        $coupon = ORM::for_table('tbl_coupons')->find_one($id);
        if (!$coupon) {
            api_out(false, class_exists('Lang') ? Lang::T('Coupon not found.') : 'Kupon tidak ditemukan.', null, 404);
        }
        $coupon->status     = $status;
        $coupon->updated_at = date('Y-m-d H:i:s');
        $coupon->save();
        api_out(true, class_exists('Lang') ? Lang::T('Coupon status updated successfully.') : 'Status kupon berhasil diperbarui.', $coupon->as_array());
        break;

    case 'admin_coupon_delete':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin', 'Sales']);
        ensure_custom_tables();
        $id = (int)_req('id');
        $ids_arr = $_POST['coupon_ids'] ?? ($_POST['couponIds'] ?? null);
        if (is_string($ids_arr)) {
            $ids_arr = json_decode($ids_arr, true);
        }
        if (is_array($ids_arr) && !empty($ids_arr)) {
            $clean_ids = array_map('intval', $ids_arr);
            ORM::for_table('tbl_coupons')->where_in('id', $clean_ids)->delete_many();
            api_out(true, class_exists('Lang') ? Lang::T('Coupons Deleted Successfully.') : (count($clean_ids) . ' Kupon berhasil dihapus.'));
        }
        $c = ORM::for_table('tbl_coupons')->find_one($id);
        if ($c) $c->delete();
        api_out(true, class_exists('Lang') ? Lang::T('Coupons Deleted Successfully.') : 'Kupon berhasil dihapus.');
        break;

    case 'assets':
    case 'admin_assets':
        require_admin_session($api_secret);
        ensure_custom_tables();
        $assets = ORM::for_table('tbl_assets')->order_by_desc('id')->find_array();
        $val = ORM::for_table('tbl_assets')->sum('cost') ?: 0;
        api_out(true, 'Daftar aset dimuat', [
            'summary' => ['total_items' => count($assets), 'total_value' => (float)$val],
            'assets'  => $assets
        ]);
        break;

    case 'asset_save':
    case 'admin_asset_save':
        require_admin_session($api_secret);
        ensure_custom_tables();
        $id   = (int)_post('id');
        $name = trim(_post('name'));
        if (empty($name)) api_out(false, 'Nama aset wajib diisi.', null, 400);
        $a = ($id > 0) ? ORM::for_table('tbl_assets')->find_one($id) : ORM::for_table('tbl_assets')->create();
        if (!$a) api_out(false, 'Data aset tidak ditemukan.', null, 404);
        $a->name             = $name;
        $a->category         = trim(_post('category') ?: 'ODP/ODC');
        $a->brand            = trim(_post('brand') ?: ($a['brand'] ?? ''));
        $a->serial_number    = trim(_post('serial_number'));
        $a->cost             = (float)(_post('cost') ?: 0);
        $a->location         = trim(_post('location') ?: ($a['location'] ?? ''));
        $a->coordinates      = trim(_post('coordinates'));
        $a->condition_status = _post('condition_status') ?: 'Good';
        $a->notes            = trim(_post('notes') ?: ($a['notes'] ?? ''));
        if ($id === 0) $a->purchase_date = date('Y-m-d');
        $a->save();
        api_out(true, 'Aset ' . $name . ' berhasil disimpan.', $a->as_array());
        break;

    case 'asset_delete':
    case 'admin_asset_delete':
        require_admin_session($api_secret);
        ensure_custom_tables();
        $a = ORM::for_table('tbl_assets')->find_one((int)_req('id'));
        if ($a) $a->delete();
        api_out(true, 'Aset berhasil dihapus.');
        break;

    case 'pg_action':
    case 'admin_payment_gateways':
    case 'admin_pg_action':
        $pg_dir = !empty($PAYMENTGATEWAY_PATH) ? $PAYMENTGATEWAY_PATH : 'system/paymentgateway';
        $valid_pg_map = get_valid_payment_gateway_names($pg_dir, $config);

        $admin = require_admin_session($api_secret);
        $sub = trim(_req('sub_action') ?: ($action === 'admin_payment_gateways' ? 'list' : 'check'));

        if ($sub === 'list') {
            $status    = _req('status');
            $gw_filter = strtolower(trim((string)_req('gateway')));
            $search    = trim(_req('search'));
            $limit     = max(20, min(150, (int)(_req('limit') ?: 80)));

            $q = ORM::for_table('tbl_payment_gateway');
            if ($status !== '' && $status !== null && strtolower((string)$status) !== 'all') {
                $q->where('status', (int)$status);
            }
            if ($gw_filter !== '' && $gw_filter !== 'all') {
                $q->where_raw('LOWER(gateway) = ?', [$gw_filter]);
            }
            if ($search !== '') {
                $like = '%' . $search . '%';
                $q->where_raw(
                    '(username LIKE ? OR plan_name LIKE ? OR gateway LIKE ? OR payment_method LIKE ? OR payment_channel LIKE ? OR trx_invoice LIKE ? OR gateway_trx_id LIKE ?)',
                    [$like, $like, $like, $like, $like, $like, $like]
                );
            }

            $rows = $q->order_by_desc('id')->limit($limit)->find_many();
            $items = [];
            $gw_stats = [];
            $total_paid = 0.0;
            $total_unpaid = 0;

            foreach ($rows as $r) {
                $raw_gw_lower = strtolower(trim((string)($r['gateway'] ?? '')));
                // Abaikan baris yang kolom gateway-nya berisi nama orang/pelanggan atau bukan Payment Gateway sah
                if ($raw_gw_lower === '' || !isset($valid_pg_map[$raw_gw_lower])) {
                    continue;
                }

                $parsed = parse_qris_trx($r, $config);
                if (!$parsed) continue;
                $items[] = $parsed;

                $g_key = strtoupper($raw_gw_lower);
                if (!isset($gw_stats[$g_key])) {
                    $gw_stats[$g_key] = [
                        'gateway'      => $g_key,
                        'paid_count'   => 0,
                        'paid_total'   => 0.0,
                        'unpaid_count' => 0
                    ];
                }
                if ((int)$parsed['status'] === 2) {
                    $gw_stats[$g_key]['paid_count']++;
                    $gw_stats[$g_key]['paid_total'] += (float)$parsed['price'];
                    $total_paid += (float)$parsed['price'];
                } elseif ((int)$parsed['status'] === 1) {
                    $gw_stats[$g_key]['unpaid_count']++;
                    $total_unpaid++;
                }
            }

            $active_gws = get_active_gateways($config);
            $raw_gw_candidates = array_unique(array_merge(
                ['viaqris', 'tripay', 'duitku', 'xendit', 'paypal'],
                array_map('strtolower', array_column($active_gws, 'id')),
                array_map('strtolower', array_keys($gw_stats))
            ));

            $gw_list = [];
            foreach ($raw_gw_candidates as $cand) {
                $c_clean = strtolower(trim((string)$cand));
                if ($c_clean !== '' && isset($valid_pg_map[$c_clean])) {
                    $gw_list[] = $c_clean;
                }
            }

            api_out(true, 'Daftar transaksi Payment Gateway dimuat', [
                'summary' => [
                    'total_paid_amount' => $total_paid,
                    'total_unpaid'      => $total_unpaid,
                    'by_gateway'        => array_values($gw_stats)
                ],
                'gateways'        => array_values($gw_list),
                'active_gateways' => $active_gws,
                'items'           => $items
            ]);
        }

        $trx_id = (int)_req('id');

        $lock_file = sys_get_temp_dir() . '/phpnuxbill_paylock_' . $trx_id . '.lock';
        $lock_fp = @fopen($lock_file, 'c+');
        if ($lock_fp) {
            @flock($lock_fp, LOCK_EX);
            $GLOBALS['API_LOCK_HANDLE'] = $lock_fp;
        }

        $trx = ORM::for_table('tbl_payment_gateway')->find_one($trx_id);
        if (!$trx) api_out(false, 'Data tagihan tidak ditemukan.', null, 404);
        $cust = ORM::for_table('tbl_customers')->where('username', $trx['username'])->find_one();

        if ($sub === 'cancel') {
            if (verify_already_recharged($trx)) api_out(false, 'Tagihan sudah LUNAS.', null, 400);
            $trx->pg_paid_response = '{}';
            $trx->status           = 4;
            $trx->paid_date        = date('Y-m-d H:i:s');
            $trx->save();
            api_out(true, 'Tagihan berhasil dibatalkan.', parse_qris_trx($trx, $config));
        }

        if ($sub === 'approve_manual') {
            if (verify_already_recharged($trx)) {
                api_out(true, 'Tagihan sudah LUNAS sebelumnya.', parse_qris_trx($trx, $config));
            }
            $cur_notif = strtolower(trim((string)($config['user_notification_payment'] ?? '')));
            if ($cur_notif === '' || $cur_notif === 'none') {
                if (!empty($config['wa_url'])) {
                    $config['user_notification_payment'] = 'wa';
                    $_c['user_notification_payment']     = 'wa';
                } elseif (!empty($config['sms_url'])) {
                    $config['user_notification_payment'] = 'sms';
                    $_c['user_notification_payment']     = 'sms';
                }
            }
            if ($cust && class_exists('Package') && Package::rechargeUser($cust['id'], $trx['routers'], $trx['plan_id'], $trx['gateway'], 'Manual Approve (' . $admin['username'] . ')')) {
                $trx->status    = 2;
                $trx->paid_date = date('Y-m-d H:i:s');
                $trx->save();
                write_admin_log($admin, '[' . $admin['username'] . ']: Manual Approve PG #' . $trx['id'] . ' (' . $trx['gateway'] . ') untuk ' . $cust['username']);
                unset($_SESSION['notify'], $_SESSION['ntype']);
                api_out(true, 'Tagihan berhasil dilunasi & paket/saldo aktif!', parse_qris_trx($trx, $config));
            }
            api_out(false, 'Gagal mengaktifkan paket ke Router.', null, 500);
        }

        check_and_expire_trx($trx);
        $gw_lower = strtolower(trim((string)($trx['gateway'] ?? '')));
        $can_query_gateway = ($gw_lower === 'viaqris') || !empty($trx['gateway_trx_id']);

        if (!verify_already_recharged($trx) && (int)$trx['status'] === 1 && !empty($trx['gateway']) && $cust && $can_query_gateway) {
            $gw_file = rtrim($pg_dir, '/\\') . DIRECTORY_SEPARATOR . $trx['gateway'] . '.php';
            if (file_exists($gw_file)) {
                include_once $gw_file;
                $status_fn = $trx['gateway'] . '_get_status';
                if (function_exists($status_fn)) {
                    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
                    $GLOBALS['API_INTERCEPT_MODE']   = 'check_payment';
                    $GLOBALS['API_INTERCEPT_TRX_ID'] = $trx['id'];
                    if (function_exists($trx['gateway'] . '_validate_config')) {
                        @call_user_func($trx['gateway'] . '_validate_config');
                    }
                    @$status_fn($trx, $cust);
                    $GLOBALS['API_INTERCEPT_TRX_ID'] = null;
                    $trx = ORM::for_table('tbl_payment_gateway')->find_one($trx_id);
                }
            }
        }
        $is_paid = verify_already_recharged($trx);
        api_out(true, $is_paid ? 'Pembayaran LUNAS!' : 'Pembayaran belum diterima.', [
            'paid' => $is_paid,
            'bill' => parse_qris_trx($trx, $config)
        ]);
        break;
}
