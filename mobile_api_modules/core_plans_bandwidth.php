<?php

/**
 * PHPNuxBill Mobile API Plugin - Plans & Bandwidth Management Module
 * Path: system/plugin/mobile_api_modules/core_plans_bandwidth.php
 **/

global $config, $_c, $admin, $ui, $DEVICE_PATH, $_app_stage, $phpnux_root, $api_secret;

if (!function_exists('get_phpnuxbill_burst_presets')) {
    /**
     * Mengambil daftar Burst Preset langsung dari template resmi bandwidth/add.tpl
     * (mendukung ui_custom/, themes/, dan ui/ui/) atau menggunakan daftar resmi PHPNuxBill.
     */
    function get_phpnuxbill_burst_presets($root_dir)
    {
        global $config;
        $presets        = [];
        $active_theme   = trim((string) ($config['theme'] ?? ''));
        $tpl_candidates = [
            $root_dir . '/ui/ui_custom/admin/bandwidth/add.tpl',
        ];
        if ($active_theme !== '') {
            $tpl_candidates[] = $root_dir . '/ui/themes/' . $active_theme . '/admin/bandwidth/add.tpl';
        }
        $tpl_candidates[] = $root_dir . '/ui/ui/admin/bandwidth/add.tpl';

        foreach ($tpl_candidates as $tpl_file) {
            if (is_file($tpl_file) && is_readable($tpl_file)) {
                $content = (string) file_get_contents($tpl_file);
                if (preg_match_all('/autoBurst\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*(\d+)\s*,\s*[\'"]([^\'"]+)[\'"]\s*,\s*(\d+)\s*,\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)[^>]*>([^<]+)</i', $content, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $label     = trim($m[11]);
                        $group     = (stripos($label, 'upto') !== false) ? 'upto' : '2x';
                        $presets[] = [
                            'label'          => $label,
                            'group'          => $group,
                            'rate_down'      => (int) $m[2],
                            'rate_down_unit' => $m[3],
                            'rate_up'        => (int) $m[4],
                            'rate_up_unit'   => $m[5],
                            'burst'          => [$m[6], $m[7], $m[8], $m[9], $m[10]]
                        ];
                    }
                    if (!empty($presets)) {
                        return $presets;
                    }
                }
            }
        }

        // Daftar Preset Resmi Web PHPNuxBill (2x Speed & UpTo +1M)
        return [
            // Kategori 2x Speed
            ['label' => '1M 2x',  'group' => '2x', 'rate_down' => 1,  'rate_down_unit' => 'Mbps', 'rate_up' => 1,  'rate_up_unit' => 'Mbps', 'burst' => ['2M/2M', '768k/768k', '16/16', '8', '512k/512k']],
            ['label' => '2M 2x',  'group' => '2x', 'rate_down' => 2,  'rate_down_unit' => 'Mbps', 'rate_up' => 2,  'rate_up_unit' => 'Mbps', 'burst' => ['4M/4M', '1536k/1536k', '16/16', '8', '1M/1M']],
            ['label' => '3M 2x',  'group' => '2x', 'rate_down' => 3,  'rate_down_unit' => 'Mbps', 'rate_up' => 3,  'rate_up_unit' => 'Mbps', 'burst' => ['6M/6M', '2304k/2304k', '16/16', '8', '1536k/1536k']],
            ['label' => '4M 2x',  'group' => '2x', 'rate_down' => 4,  'rate_down_unit' => 'Mbps', 'rate_up' => 4,  'rate_up_unit' => 'Mbps', 'burst' => ['8M/8M', '3M/3M', '16/16', '8', '2M/2M']],
            ['label' => '5M 2x',  'group' => '2x', 'rate_down' => 5,  'rate_down_unit' => 'Mbps', 'rate_up' => 5,  'rate_up_unit' => 'Mbps', 'burst' => ['10M/10M', '3840k/3840k', '16/16', '8', '2560k/2560k']],
            ['label' => '6M 2x',  'group' => '2x', 'rate_down' => 6,  'rate_down_unit' => 'Mbps', 'rate_up' => 6,  'rate_up_unit' => 'Mbps', 'burst' => ['12M/12M', '4608k/4608k', '16/16', '8', '3M/3M']],
            ['label' => '7M 2x',  'group' => '2x', 'rate_down' => 7,  'rate_down_unit' => 'Mbps', 'rate_up' => 7,  'rate_up_unit' => 'Mbps', 'burst' => ['14M/14M', '5376k/5376k', '16/16', '8', '3584k/3584k']],
            ['label' => '8M 2x',  'group' => '2x', 'rate_down' => 8,  'rate_down_unit' => 'Mbps', 'rate_up' => 8,  'rate_up_unit' => 'Mbps', 'burst' => ['16M/16M', '6M/6M', '16/16', '8', '4M/4M']],
            ['label' => '9M 2x',  'group' => '2x', 'rate_down' => 9,  'rate_down_unit' => 'Mbps', 'rate_up' => 9,  'rate_up_unit' => 'Mbps', 'burst' => ['18M/18M', '6912k/6912k', '16/16', '8', '4608k/4608k']],
            ['label' => '10M 2x', 'group' => '2x', 'rate_down' => 10, 'rate_down_unit' => 'Mbps', 'rate_up' => 10, 'rate_up_unit' => 'Mbps', 'burst' => ['20M/20M', '7680k/7680k', '16/16', '8', '5M/5M']],

            // Kategori UpTo (+1M)
            ['label' => '512k upto 1M', 'group' => 'upto', 'rate_down' => 512, 'rate_down_unit' => 'Kbps', 'rate_up' => 512, 'rate_up_unit' => 'Kbps', 'burst' => ['1024k/1024k', '384k/384k', '16/16', '8', '256k/256k']],
            ['label' => '1M upto 2M',   'group' => 'upto', 'rate_down' => 1,   'rate_down_unit' => 'Mbps', 'rate_up' => 1,   'rate_up_unit' => 'Mbps', 'burst' => ['2M/2M', '768k/768k', '16/16', '8', '512k/512k']],
            ['label' => '2M upto 3M',   'group' => 'upto', 'rate_down' => 2,   'rate_down_unit' => 'Mbps', 'rate_up' => 2,   'rate_up_unit' => 'Mbps', 'burst' => ['3M/3M', '1536k/1536k', '12/12', '8', '1024k/1024k']],
            ['label' => '3M upto 4M',   'group' => 'upto', 'rate_down' => 3,   'rate_down_unit' => 'Mbps', 'rate_up' => 3,   'rate_up_unit' => 'Mbps', 'burst' => ['4M/4M', '2450k/2450k', '10/10', '8', '1536k/1536k']],
            ['label' => '4M upto 5M',   'group' => 'upto', 'rate_down' => 4,   'rate_down_unit' => 'Mbps', 'rate_up' => 4,   'rate_up_unit' => 'Mbps', 'burst' => ['5M/5M', '3M/3M', '10/10', '8', '2M/2M']],
            ['label' => '5M upto 6M',   'group' => 'upto', 'rate_down' => 5,   'rate_down_unit' => 'Mbps', 'rate_up' => 5,   'rate_up_unit' => 'Mbps', 'burst' => ['6M/6M', '3680k/3680k', '10/10', '8', '2450k/2450k']],
            ['label' => '6M upto 7M',   'group' => 'upto', 'rate_down' => 6,   'rate_down_unit' => 'Mbps', 'rate_up' => 6,   'rate_up_unit' => 'Mbps', 'burst' => ['7M/7M', '4560k/4560k', '11/11', '8', '3M/3M']],
            ['label' => '7M upto 8M',   'group' => 'upto', 'rate_down' => 7,   'rate_down_unit' => 'Mbps', 'rate_up' => 7,   'rate_up_unit' => 'Mbps', 'burst' => ['8M/8M', '5460k/5460k', '12/12', '8', '3640k/3640k']],
            ['label' => '8M upto 9M',   'group' => 'upto', 'rate_down' => 8,   'rate_down_unit' => 'Mbps', 'rate_up' => 8,   'rate_up_unit' => 'Mbps', 'burst' => ['9M/9M', '6M/6M', '12/12', '8', '4M/4M']],
            ['label' => '9M upto 10M',  'group' => 'upto', 'rate_down' => 9,   'rate_down_unit' => 'Mbps', 'rate_up' => 9,   'rate_up_unit' => 'Mbps', 'burst' => ['10M/10M', '6820k/6820k', '12/12', '8', '4550k/4550k']]
        ];
    }
}

switch ($action) {
    // =========================================================================
    // 1. DAFTAR PAKET INTERNET, BANDWIDTH, ROUTERS & PAYMENT USINGS
    // =========================================================================
    case 'admin_plans':
        $admin         = require_admin_session($api_secret);
        $is_superadmin = ($admin['user_type'] === 'SuperAdmin');
        $type_filter   = trim((string) _req('type'));
        $router_filter = trim((string) (_req('routers') ?: _req('server')));

        $bw_rows = ORM::for_table('tbl_bandwidth')->order_by_desc('id')->find_array();
        $bw_map  = [];
        foreach ($bw_rows as &$b_item) {
            $b_item['burst_parts']     = !empty($b_item['burst']) ? explode(' ', trim((string) $b_item['burst'])) : [];
            $bw_map[(int) $b_item['id']] = $b_item;
        }
        unset($b_item);

        // Pindai driver perangkat resmi dari system/devices/
        $root_dir  = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $dev_dir   = !empty($DEVICE_PATH) ? $DEVICE_PATH : 'system/devices';
        $scan_dirs = [
            $dev_dir,
            $root_dir . DIRECTORY_SEPARATOR . ltrim($dev_dir, '/\\')
        ];
        $devices = [];
        foreach ($scan_dirs as $s_dir) {
            if (is_dir($s_dir)) {
                foreach (scandir($s_dir) ?: [] as $file) {
                    if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                        $dev_name = pathinfo($file, PATHINFO_FILENAME);
                        if (!in_array($dev_name, $devices, true)) {
                            $devices[] = $dev_name;
                        }
                    }
                }
                if (!empty($devices)) {
                    break;
                }
            }
        }
        if (empty($devices)) {
            $devices = ['MikrotikHotspot', 'MikrotikPppoe', 'MikrotikVpn', 'Radius', 'Dummy'];
        }

        // Hitung jumlah pelanggan aktif per paket dalam 1 kueri GROUP BY
        $active_cnt_map = [];
        try {
            $db = ORM::get_db();
            foreach ($db->query("SELECT plan_id, COUNT(*) AS cnt FROM tbl_user_recharges WHERE status = 'on' GROUP BY plan_id")->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                $active_cnt_map[(int) $ar['plan_id']] = (int) $ar['cnt'];
            }
        } catch (Throwable $e) {
        }

        $q = ORM::for_table('tbl_plans');
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'], true)) {
            $q->where('enabled', 1);
        }
        if ($type_filter !== '' && strtolower($type_filter) !== 'all') {
            $q->where('type', $type_filter);
        }
        if ($router_filter !== '' && strtolower($router_filter) !== 'all') {
            if (strtolower($router_filter) === 'radius') {
                $q->where('is_radius', 1);
            } else {
                $q->where('routers', $router_filter);
            }
        }

        $rows  = $q->order_by_asc('price')->find_array();
        $plans = [];
        foreach ($rows as $r) {
            $p_type    = strtoupper(trim((string) $r['type']));
            $prepaid   = strtolower(trim((string) ($r['prepaid'] ?? 'yes')));
            $is_hidden = ((int) ($r['enabled'] ?? 1) === 0);
            $is_rad    = ((int) ($r['is_radius'] ?? 0) === 1);

            if (!$is_superadmin && $p_type === 'HOTSPOT' && $prepaid === 'no') {
                continue;
            }

            if ($is_rad && empty($r['routers'])) {
                $r['routers'] = 'Radius';
            }
            if (empty($r['device'])) {
                if ($is_rad) {
                    $r['device'] = 'Radius';
                } elseif ($p_type === 'PPPOE') {
                    $r['device'] = 'MikrotikPppoe';
                } elseif ($p_type === 'VPN') {
                    $r['device'] = 'MikrotikVpn';
                } elseif ($p_type === 'HOTSPOT') {
                    $r['device'] = 'MikrotikHotspot';
                } else {
                    $r['device'] = '';
                }
            }

            $bw                  = $bw_map[(int) ($r['id_bw'] ?? 0)] ?? null;
            $r['bandwidth_name'] = $bw ? $bw['name_bw'] : ($p_type === 'BALANCE' ? 'Saldo' : '-');
            $r['rate_label']     = $bw ? ($bw['rate_down'] . ' ' . $bw['rate_down_unit'] . ' / ' . $bw['rate_up'] . ' ' . $bw['rate_up_unit']) : '-';
            $r['active_users']   = $active_cnt_map[(int) $r['id']] ?? 0;
            $r['is_hidden']      = $is_hidden;
            $r['billing_label']  = (($prepaid === 'no') ? 'Postpaid (Pascabayar)' : 'Prepaid (Prabayar)')
                . ($is_hidden ? ' • Hidden' : '');
            $plans[]             = $r;
        }

        $usings = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($config['payment_usings'] ?? 'Cash'))))));
        if (empty($usings)) {
            $usings = ['Cash'];
        }
        if (($config['enable_balance'] ?? 'yes') === 'yes' && !in_array('Balance', $usings, true)) {
            $usings[] = 'Balance';
        }
        if (!in_array('Recharge Zero', $usings, true)) {
            $usings[] = 'Recharge Zero';
        }

        api_out(true, 'Daftar paket internet & bandwidth dimuat', [
            'is_superadmin'  => $is_superadmin,
            'voucher_prefix' => $config['voucher_prefix'] ?? '',
            'plans'          => $plans,
            'usings'         => $usings,
            'bandwidths'     => $bw_rows,
            'rate_units'     => ['Kbps', 'Mbps'],
            'burst_presets'  => get_phpnuxbill_burst_presets($root_dir),
            'devices'        => $devices,
            'plan_types'     => ['Personal', 'Business'],
            'typebps'        => ['Unlimited', 'Limited'],
            'limit_types'    => ['Time_Limit', 'Data_Limit', 'Both_Limit'],
            'validity_units' => ['Mins', 'Hrs', 'Days', 'Months', 'Period'],
            'routers'        => ORM::for_table('tbl_routers')->where('enabled', 1)->find_array(),
            'pools'          => ORM::for_table('tbl_pool')->find_array()
        ]);
        break;

    // =========================================================================
    // 2. SIMPAN PAKET INTERNET / SALDO (SELARAS DENGAN services.php)
    // =========================================================================
    case 'admin_plan_save':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $id         = (int) _post('id');
        $is_edit    = ($id > 0);
        $raw_type   = trim((string) (_post('type') ?: 'Hotspot'));
        $type_upper = strtoupper($raw_type);
        $db_type    = ($type_upper === 'PPPOE') ? 'PPPOE' : (($type_upper === 'BALANCE') ? 'Balance' : (($type_upper === 'VPN') ? 'VPN' : 'Hotspot'));

        $name      = trim((string) (_post('name_plan') ?: _post('name')));
        $price     = (float) _post('price');
        $price_old = trim((string) _post('price_old'));
        $enabled   = (int) (_post('enabled') ?? 1);
        $prepaid   = trim((string) (_post('prepaid') ?: ($db_type === 'PPPOE' ? 'no' : 'yes')));

        if ($name === '') {
            api_out(false, class_exists('Lang') ? Lang::T('All field is required') : 'Nama paket wajib diisi.', null, 400);
        }
        if ($price < 0) {
            api_out(false, 'Harga paket tidak valid.', null, 400);
        }
        if ($price_old !== '' && (float) $price_old <= $price) {
            $price_old = '';
        }

        if ($is_edit) {
            $d   = ORM::for_table('tbl_plans')->where('id', $id)->find_one();
            $old = ORM::for_table('tbl_plans')->where('id', $id)->find_one();
            if (!$d) {
                api_out(false, class_exists('Lang') ? Lang::T('Data Not Found') : 'Paket tidak ditemukan.', null, 404);
            }
            if (!empty($d['type'])) {
                $exist_upper = strtoupper(trim((string) $d['type']));
                $db_type     = ($exist_upper === 'PPPOE') ? 'PPPOE' : (($exist_upper === 'BALANCE') ? 'Balance' : (($exist_upper === 'VPN') ? 'VPN' : 'Hotspot'));
            }
        } else {
            $dup_q = ORM::for_table('tbl_plans')->where('name_plan', $name);
            if ($db_type === 'Hotspot') {
                $dup_q->where('type', 'Hotspot');
            }
            if ($dup_q->find_one()) {
                api_out(false, class_exists('Lang') ? Lang::T('Name Plan Already Exist') : 'Nama paket sudah terdaftar.', null, 400);
            }
            $d       = ORM::for_table('tbl_plans')->create();
            $d->type = $db_type;
            $old     = null;
        }

        // Paket Saldo (Balance)
        if ($db_type === 'Balance') {
            if (function_exists('run_hook')) {
                try {
                    @run_hook($is_edit ? 'edit_ppoe' : 'add_ppoe');
                } catch (Throwable $e) {
                }
            }
            $d->name_plan = $name;
            $d->price     = $price;
            $d->enabled   = $enabled;
            $d->prepaid   = 'yes';
            if ($is_edit) {
                $d->price_old = $price_old;
            } else {
                $d->id_bw         = 0;
                $d->validity      = 0;
                $d->validity_unit = 'Months';
                $d->routers       = '';
                $d->pool          = '';
            }
            $d->save();
            write_admin_log($admin, ($is_edit ? 'Mengubah' : 'Menambah') . ' Paket Saldo: ' . $name);
            api_out(true, class_exists('Lang') ? Lang::T($is_edit ? 'Data Updated Successfully' : 'Data Created Successfully') : 'Paket Saldo berhasil disimpan.', $d->as_array());
        }

        // Paket Hotspot / PPPoE / VPN
        $plan_type     = trim((string) (_post('plan_type') ?: 'Personal'));
        $id_bw         = (int) _post('id_bw');
        $validity      = (int) _post('validity');
        $validity_unit = trim((string) (_post('validity_unit') ?: 'Days'));
        $raw_routers   = trim((string) _post('routers'));
        $radius_flag   = (_post('radius') == '1' || _post('is_radius') == '1' || strtolower($raw_routers) === 'radius');
        $pool          = trim((string) (_post('pool_name') ?: _post('pool')));
        $plan_expired  = (int) (_post('plan_expired') ?: 0);
        $expired_date  = (int) (_post('expired_date') ?: 20);
        $on_login      = _post('on_login') ?? ($d['on_login'] ?? '');
        $on_logout     = _post('on_logout') ?? ($d['on_logout'] ?? '');

        $default_dev = $radius_flag ? 'Radius' : (($db_type === 'PPPOE') ? 'MikrotikPppoe' : (($db_type === 'VPN') ? 'MikrotikVpn' : 'MikrotikHotspot'));
        $device      = trim((string) (_post('device') ?: ($d['device'] ?? $default_dev)));
        if ($device === '') {
            $device = $default_dev;
        }

        if ($id_bw <= 0 || $validity <= 0) {
            api_out(false, class_exists('Lang') ? Lang::T('All field is required') : 'Profil Bandwidth dan Masa Aktif wajib diisi.', null, 400);
        }
        if (($db_type === 'PPPOE' || $db_type === 'VPN') && $pool === '') {
            api_out(false, class_exists('Lang') ? Lang::T('All field is required') : 'IP Pool wajib dipilih untuk paket ' . $db_type . '.', null, 400);
        }
        if (!$is_edit && !$radius_flag && $raw_routers === '') {
            api_out(false, class_exists('Lang') ? Lang::T('All field is required') : 'Pilih Router atau aktifkan Radius terlebih dahulu.', null, 400);
        }

        if (function_exists('run_hook')) {
            try {
                if ($db_type === 'Hotspot') {
                    @run_hook($is_edit ? 'edit_plan' : 'add_plan');
                } elseif ($db_type === 'PPPOE') {
                    @run_hook($is_edit ? 'edit_ppoe' : 'add_ppoe');
                } else {
                    @run_hook($is_edit ? 'edit_vpn' : 'add_vpn');
                }
            } catch (Throwable $e) {
            }
        }

        $d->name_plan     = $name;
        $d->id_bw         = $id_bw;
        $d->price         = $price;
        $d->plan_type     = $plan_type;
        $d->validity      = $validity;
        $d->validity_unit = $validity_unit;
        $d->enabled       = $enabled;
        $d->prepaid       = $prepaid;
        $d->device        = $device;

        if ($is_edit) {
            $d->price_old    = $price_old;
            $d->plan_expired = $plan_expired;
            $d->on_login     = $on_login;
            $d->on_logout    = $on_logout;
            if ($db_type !== 'Hotspot' && !$d['is_radius'] && $raw_routers !== '') {
                $d->routers = $raw_routers;
            }
        } else {
            if ($radius_flag) {
                $d->is_radius = 1;
                $d->routers   = '';
            } else {
                $d->is_radius = 0;
                $d->routers   = $raw_routers;
            }
        }

        if ($db_type === 'Hotspot') {
            $typebp       = trim((string) (_post('typebp') ?: 'Unlimited'));
            $limit_type   = trim((string) (_post('limit_type') ?: 'Time_Limit'));
            $time_limit   = (int) (_post('time_limit') ?: 0);
            $time_unit    = trim((string) (_post('time_unit') ?: 'Hrs'));
            $data_limit   = (float) (_post('data_limit') ?: 0);
            $data_unit    = trim((string) (_post('data_unit') ?: 'MB'));
            $shared_users = max(1, (int) (_post('shared_users') ?: _post('sharedusers') ?: 1));

            $d->typebp       = $typebp;
            $d->limit_type   = $limit_type;
            $d->time_limit   = $time_limit;
            $d->time_unit    = $time_unit;
            $d->data_limit   = $data_limit;
            $d->data_unit    = $data_unit;
            $d->shared_users = $shared_users;
            if ($prepaid === 'no') {
                if ($expired_date > 28 || $expired_date < 1) {
                    $expired_date = 20;
                }
                $d->expired_date = $expired_date;
            } else {
                $d->expired_date = 20;
            }
        } else {
            $d->pool = $pool;
            if ($prepaid === 'no') {
                if ($expired_date > 28 || $expired_date < 1) {
                    $expired_date = 20;
                }
                $d->expired_date = $expired_date;
            } else {
                $d->expired_date = 0;
            }
        }

        $d->save();

        // Sinkronisasi profil paket ke RouterOS / FreeRADIUS (LSP Device Driver)
        $router_sync_err = '';
        if (class_exists('Package') && method_exists('Package', 'getDevice')) {
            $dvc = Package::getDevice($d);
            if (file_exists($dvc)) {
                require_once $dvc;
                $dev_cls = $d['device'];
                if (class_exists($dev_cls)) {
                    try {
                        $dev_inst = new $dev_cls();
                        if ($is_edit && $old && method_exists($dev_inst, 'update_plan')) {
                            $dev_inst->update_plan($old, $d);
                        } elseif (method_exists($dev_inst, 'add_plan')) {
                            $dev_inst->add_plan($d);
                        }
                    } catch (Throwable $e) {
                        $router_sync_err = ' (Catatan Router: ' . $e->getMessage() . ')';
                    }
                }
            }
        }

        write_admin_log($admin, ($is_edit ? 'Mengubah' : 'Menambah') . ' paket ' . $db_type . ': ' . $name);
        $base_msg = class_exists('Lang')
            ? Lang::T($is_edit ? 'Data Updated Successfully' : 'Data Created Successfully')
            : ('Paket ' . $name . ' berhasil disimpan.');
        api_out(true, $base_msg . $router_sync_err, $d->as_array());
        break;

    case 'admin_plan_delete':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $id = (int) _req('id');
        $d  = ORM::for_table('tbl_plans')->find_one($id);
        if (!$d) {
            api_out(false, class_exists('Lang') ? Lang::T('Data Not Found') : 'Paket tidak ditemukan.', null, 404);
        }

        $p_type = strtoupper(trim((string) $d['type']));
        $p_name = $d['name_plan'];

        if (function_exists('run_hook')) {
            try {
                if ($p_type === 'HOTSPOT') {
                    @run_hook('delete_plan');
                } elseif ($p_type === 'PPPOE') {
                    @run_hook('delete_ppoe');
                } elseif ($p_type === 'VPN') {
                    @run_hook('delete_vpn');
                }
            } catch (Throwable $e) {
            }
        }

        if ($p_type !== 'BALANCE' && class_exists('Package') && method_exists('Package', 'getDevice')) {
            $dvc = Package::getDevice($d);
            if (file_exists($dvc)) {
                require_once $dvc;
                $dev_cls = $d['device'];
                if (class_exists($dev_cls)) {
                    try {
                        $dev_inst = new $dev_cls();
                        if (method_exists($dev_inst, 'remove_plan')) {
                            $dev_inst->remove_plan($d);
                        }
                    } catch (Throwable $e) {
                    }
                }
            }
        }

        $d->delete();
        write_admin_log($admin, 'Menghapus paket ' . $p_type . ': ' . $p_name);
        api_out(true, class_exists('Lang') ? Lang::T('Data Deleted Successfully') : 'Paket ' . $p_name . ' berhasil dihapus.');
        break;

    case 'admin_plan_sync':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        @set_time_limit(-1);
        $sync_type = strtolower(trim((string) (_req('type') ?: 'hotspot')));
        if ($sync_type === 'all') {
            $plans = ORM::for_table('tbl_plans')->where_not_equal('type', 'Balance')->find_many();
        } else {
            $db_type = ($sync_type === 'pppoe') ? 'PPPOE' : (($sync_type === 'vpn') ? 'VPN' : 'Hotspot');
            $plans   = ORM::for_table('tbl_plans')->where('type', $db_type)->find_many();
        }

        $done_cnt = 0;
        $fail_cnt = 0;
        $logs     = [];
        foreach ($plans as $plan) {
            $pt = strtoupper(trim((string) $plan['type']));
            if (empty($plan['device'])) {
                $plan->device = ($plan['is_radius'] == 1)
                    ? 'Radius'
                    : (($pt === 'PPPOE') ? 'MikrotikPppoe' : (($pt === 'VPN') ? 'MikrotikVpn' : 'MikrotikHotspot'));
                $plan->save();
            }
            if (class_exists('Package') && method_exists('Package', 'getDevice')) {
                $dvc = Package::getDevice($plan);
                if (file_exists($dvc)) {
                    require_once $dvc;
                    $cls = $plan['device'];
                    if (class_exists($cls)) {
                        try {
                            (new $cls())->add_plan($plan);
                            $done_cnt++;
                            $logs[] = 'DONE: ' . $plan['name_plan'] . ' (' . $cls . ')';
                        } catch (Throwable $e) {
                            $fail_cnt++;
                            $logs[] = 'FAILED: ' . $plan['name_plan'] . ' - ' . $e->getMessage();
                        }
                    } else {
                        $fail_cnt++;
                        $logs[] = 'FAILED: ' . $plan['name_plan'] . ' (Class ' . $cls . ' tidak ditemukan)';
                    }
                } else {
                    $fail_cnt++;
                    $logs[] = 'FAILED: ' . $plan['name_plan'] . ' (Device Not Found)';
                }
            }
        }

        api_out(true, "Sinkronisasi paket selesai ($done_cnt berhasil, $fail_cnt gagal).", [
            'done' => $done_cnt,
            'fail' => $fail_cnt,
            'logs' => $logs
        ]);
        break;

    // =========================================================================
    // 3. MANAJEMEN PROFIL BANDWIDTH (SELARAS DENGAN bandwidth.php)
    // =========================================================================
    case 'admin_bandwidths':
        require_admin_session($api_secret);
        if (function_exists('run_hook')) {
            try {
                @run_hook('view_list_bandwidth');
            } catch (Throwable $e) {
            }
        }
        $name_search = trim((string) (_req('name') ?: _req('search')));
        $query       = ORM::for_table('tbl_bandwidth');
        if ($name_search !== '') {
            $query->where_like('name_bw', '%' . $name_search . '%');
        }
        $rows = $query->order_by_desc('id')->find_array();
        foreach ($rows as &$r) {
            $r['burst_parts'] = !empty($r['burst']) ? explode(' ', trim((string) $r['burst'])) : [];
        }
        unset($r);
        api_out(true, 'Daftar bandwidth dimuat', $rows);
        break;

    case 'admin_bandwidth_save':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $id             = (int) _post('id');
        $is_edit        = ($id > 0);
        $name           = trim((string) (_post('name') ?: _post('name_bw')));
        $rate_down      = _post('rate_down');
        $rate_down_unit = (_post('rate_down_unit') === 'Kbps') ? 'Kbps' : 'Mbps';
        $rate_up        = _post('rate_up');
        $rate_up_unit   = (_post('rate_up_unit') === 'Kbps') ? 'Kbps' : 'Mbps';

        if (function_exists('run_hook')) {
            try {
                @run_hook($is_edit ? 'edit_bandwidth' : 'add_bandwidth');
            } catch (Throwable $e) {
            }
        }

        // Validasi 5 elemen burst (sesuai system/controllers/bandwidth.php)
        $burst       = '';
        $burst_input = $_POST['burst'] ?? null;
        if (is_string($burst_input) && trim($burst_input) !== '') {
            $burst_input = preg_split('/\s+/', trim($burst_input));
        }
        if (is_array($burst_input) && count($burst_input) >= 5) {
            $is_burst   = true;
            $five_parts = array_slice($burst_input, 0, 5);
            foreach ($five_parts as $b_part) {
                if (trim((string) $b_part) === '') {
                    $is_burst = false;
                    break;
                }
            }
            if ($is_burst) {
                $burst = implode(' ', array_map('trim', $five_parts));
            }
        }

        $msg = '';
        if (strlen($name) < 1 || strlen($name) > 255) {
            $msg .= "Name should be between 1 to 255 characters\n";
        }
        if (!is_numeric($rate_down) || (float) $rate_down <= 0 || !is_numeric($rate_up) || (float) $rate_up <= 0) {
            $msg .= "Rate Download dan Upload wajib berupa angka lebih dari 0\n";
        }

        // Konversi unit rate biner sesuai bandwidth.php (1024 / 1048576)
        $unit_rate_down = ($rate_down_unit === 'Kbps') ? ((float) $rate_down * 1024) : ((float) $rate_down * 1048576);
        $unit_rate_up   = ($rate_up_unit === 'Kbps') ? ((float) $rate_up * 1024) : ((float) $rate_up * 1048576);

        if ($is_edit) {
            $d = ORM::for_table('tbl_bandwidth')->find_one($id);
            if (!$d) {
                $msg .= (class_exists('Lang') ? Lang::T('Data Not Found') : 'Data Not Found') . "\n";
            } elseif ($d['name_bw'] !== $name) {
                $c = ORM::for_table('tbl_bandwidth')->where('name_bw', $name)->find_one();
                if ($c) {
                    $msg .= (class_exists('Lang') ? Lang::T('Name Bandwidth Already Exist') : 'Name Bandwidth Already Exist') . "\n";
                }
            }
        } else {
            $c = ORM::for_table('tbl_bandwidth')->where('name_bw', $name)->find_one();
            if ($c) {
                $msg .= (class_exists('Lang') ? Lang::T('Name Bandwidth Already Exist') : 'Name Bandwidth Already Exist') . "\n";
            }
        }

        if ($msg !== '') {
            api_out(false, trim($msg), null, 400);
        }

        if (!$is_edit) {
            $d = ORM::for_table('tbl_bandwidth')->create();
        }
        $d->name_bw        = $name;
        $d->rate_down      = (float) $rate_down;
        $d->rate_down_unit = $rate_down_unit;
        $d->rate_up        = (float) $rate_up;
        $d->rate_up_unit   = $rate_up_unit;
        $d->burst          = $burst;
        $d->save();

        $saved_arr                   = $d->as_array();
        $saved_arr['unit_rate_down'] = $unit_rate_down;
        $saved_arr['unit_rate_up']   = $unit_rate_up;
        $saved_arr['burst_parts']    = $burst !== '' ? explode(' ', $burst) : [];

        write_admin_log($admin, ($is_edit ? 'Mengubah' : 'Menambah') . ' Bandwidth: ' . $name);
        api_out(
            true,
            class_exists('Lang') ? Lang::T($is_edit ? 'Data Updated Successfully' : 'Data Created Successfully') : 'Data Saved Successfully',
            $saved_arr
        );
        break;

    case 'admin_bandwidth_delete':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $id = (int) _req('id');
        if (function_exists('run_hook')) {
            try {
                @run_hook('delete_bandwidth');
            } catch (Throwable $e) {
            }
        }
        $d = ORM::for_table('tbl_bandwidth')->find_one($id);
        if (!$d) {
            api_out(false, class_exists('Lang') ? Lang::T('Account Not Found') : 'Account Not Found', null, 404);
        }
        $bw_name = $d['name_bw'];
        $d->delete();
        write_admin_log($admin, 'Menghapus Bandwidth: ' . $bw_name);
        api_out(true, class_exists('Lang') ? Lang::T('Data Deleted Successfully') : 'Data Deleted Successfully');
        break;
}
