<?php
/**
 * PHPNuxBill Mobile API Plugin - Plans & Bandwidth Management Module
 * Path: system/plugin/mobile_api_modules/core_plans_bandwidth.php
 */

global $config, $_c, $admin, $ui, $DEVICE_PATH, $_app_stage, $phpnux_root;

switch ($action) {
    // =========================================================================
    // PAKET INTERNET, BANDWIDTH, ROUTERS & DAFTAR PAYMENT USINGS
    // =========================================================================
    case 'admin_plans':
        $admin = require_admin_session($api_secret);
        $is_superadmin = ($admin['user_type'] === 'SuperAdmin');
        $type_filter   = trim(_req('type'));
        $router_filter = trim(_req('routers') ?: _req('server'));

        $bw_rows = ORM::for_table('tbl_bandwidth')->order_by_desc('id')->find_array();
        $bw_map = [];
        foreach ($bw_rows as &$b_item) {
            $b_item['burst_parts'] = !empty($b_item['burst']) ? explode(' ', trim($b_item['burst'])) : [];
            $bw_map[(int)$b_item['id']] = $b_item;
        }
        unset($b_item);

        $root_dir = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $dev_dir  = !empty($DEVICE_PATH) ? $DEVICE_PATH : 'system/devices';
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
                        if (!in_array($dev_name, $devices)) {
                            $devices[] = $dev_name;
                        }
                    }
                }
                if (!empty($devices)) break;
            }
        }
        if (empty($devices)) {
            $devices = ['MikrotikHotspot', 'MikrotikPppoe', 'MikrotikVpn', 'Radius', 'Dummy'];
        }

        $active_cnt_map = [];
        try {
            $db = ORM::get_db();
            foreach ($db->query("SELECT plan_id, COUNT(*) AS cnt FROM tbl_user_recharges WHERE status = 'on' GROUP BY plan_id")->fetchAll(PDO::FETCH_ASSOC) as $ur) {
                $active_cnt_map[(int)$ur['plan_id']] = (int)$ur['cnt'];
            }
        } catch (Throwable $e) {}

        $q = ORM::for_table('tbl_plans');
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            $q->where('enabled', 1);
        }
        if ($type_filter !== '' && strtolower($type_filter) !== 'all') {
            if (strtoupper($type_filter) === 'PPPOE') {
                $q->where_raw('UPPER(type) = ?', ['PPPOE']);
            } else {
                $q->where('type', $type_filter);
            }
        }
        if ($router_filter !== '' && strtolower($router_filter) !== 'all') {
            if (strtolower($router_filter) === 'radius') {
                $q->where_raw('(is_radius = 1 OR LOWER(routers) = ?)', ['radius']);
            } else {
                $q->where('routers', $router_filter);
            }
        }

        $rows = $q->order_by_asc('price')->find_array();
        $plans = [];
        foreach ($rows as $r) {
            $p_type    = strtoupper(trim($r['type']));
            $prepaid   = strtolower(trim($r['prepaid'] ?? 'yes'));
            $is_hidden = ((int)($r['enabled'] ?? 1) === 0);
            $is_rad    = ((int)($r['is_radius'] ?? 0) === 1);

            if (!$is_superadmin && $p_type === 'HOTSPOT' && $prepaid === 'no') {
                continue;
            }

            $bw = $bw_map[(int)$r['id_bw']] ?? null;
            $r['name_bw'] = $bw ? $bw['name_bw'] : ($p_type === 'BALANCE' ? 'Balance' : '-');
            $r['rate_label'] = $bw
                ? ($bw['rate_down'] . ' ' . $bw['rate_down_unit'] . ' / ' . $bw['rate_up'] . ' ' . $bw['rate_up_unit'] . (!empty($bw['burst']) ? ' (' . $bw['burst'] . ')' : ''))
                : ($p_type === 'BALANCE' ? 'Paket Deposit Saldo' : '-');

            $r['raw_routers'] = $r['routers'] ?? '';
            if ($is_rad && empty($r['routers'])) {
                $r['routers'] = 'Radius';
            }

            if (empty($r['device'])) {
                if ($is_rad) $r['device'] = 'Radius';
                elseif ($p_type === 'PPPOE') $r['device'] = 'MikrotikPppoe';
                elseif ($p_type === 'VPN') $r['device'] = 'MikrotikVpn';
                elseif ($p_type === 'HOTSPOT') $r['device'] = 'MikrotikHotspot';
                else $r['device'] = '';
            }

            $r['is_hidden']     = $is_hidden;
            $r['billing_label'] = (($prepaid === 'no') ? 'Postpaid (Pascabayar)' : 'Prepaid (Prabayar)')
                . ($is_hidden ? ' • [HIDDEN]' : '');
            $r['active_users']  = $active_cnt_map[(int)$r['id']] ?? 0;
            $plans[] = $r;
        }

        $raw_usings = !empty($config['payment_usings']) ? explode(',', $config['payment_usings']) : [];
        $usings = array_values(array_filter(array_unique(array_map('trim', $raw_usings))));
        if (empty($usings)) {
            $usings[] = class_exists('Lang') ? Lang::T('Cash') : 'Cash';
        }
        if (($config['enable_balance'] ?? 'yes') === 'yes' && !in_array('Balance', $usings) && !in_array('balance', $usings)) {
            $usings[] = 'Balance';
        }
        if (!in_array('Recharge Zero', $usings)) {
            $usings[] = 'Recharge Zero';
        }

        api_out(true, 'Daftar paket internet & bandwidth dimuat', [
            'is_superadmin'  => $is_superadmin,
            'voucher_prefix' => $config['voucher_prefix'] ?? '',
            'plans'          => $plans,
            'usings'         => $usings,
            'bandwidths'     => $bw_rows,
            'devices'        => $devices,
            'plan_types'     => ['Personal', 'Business'],
            'typebps'        => ['Unlimited', 'Limited'],
            'limit_types'    => ['Time_Limit', 'Data_Limit', 'Both_Limit'],
            'validity_units' => ['Mins', 'Hrs', 'Days', 'Months', 'Period'],
            'routers'        => ORM::for_table('tbl_routers')->where('enabled', 1)->find_array(),
            'pools'          => ORM::for_table('tbl_pool')->find_array()
        ]);
        break;

    case 'admin_plan_save':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $id    = (int)_post('id');
        $is_edit = ($id > 0);

        $raw_type = trim(_post('type') ?: 'Hotspot');
        $type_upper = strtoupper($raw_type);
        $db_type = ($type_upper === 'PPPOE') ? 'PPPOE' : (($type_upper === 'BALANCE') ? 'Balance' : (($type_upper === 'VPN') ? 'VPN' : 'Hotspot'));

        $name = trim(_post('name_plan') ?: _post('name'));
        $price = (float)_post('price');
        $price_old = trim((string)_post('price_old'));
        $enabled = (int)(_post('enabled') ?? 1);
        $prepaid = _post('prepaid') ?: ($db_type === 'PPPOE' ? 'no' : 'yes');

        if ($name === '') {
            api_out(false, class_exists('Lang') ? Lang::T('All field is required') : 'Nama paket wajib diisi.', null, 400);
        }
        if ($price < 0) {
            api_out(false, 'Harga paket tidak valid.', null, 400);
        }
        if ($price_old !== '' && (float)$price_old <= $price) {
            $price_old = '';
        }

        if ($is_edit) {
            $d = ORM::for_table('tbl_plans')->where('id', $id)->find_one();
            $old = ORM::for_table('tbl_plans')->where('id', $id)->find_one();
            if (!$d) {
                api_out(false, class_exists('Lang') ? Lang::T('Data Not Found') : 'Paket tidak ditemukan.', null, 404);
            }
            if (!empty($d['type'])) {
                $exist_upper = strtoupper(trim($d['type']));
                $db_type = ($exist_upper === 'PPPOE') ? 'PPPOE' : (($exist_upper === 'BALANCE') ? 'Balance' : (($exist_upper === 'VPN') ? 'VPN' : 'Hotspot'));
            }
        } else {
            $dup_q = ORM::for_table('tbl_plans')->where('name_plan', $name);
            if ($db_type === 'Hotspot') {
                $dup_q->where('type', 'Hotspot');
            }
            if ($dup_q->find_one()) {
                api_out(false, class_exists('Lang') ? Lang::T('Name Plan Already Exist') : 'Nama paket sudah terdaftar.', null, 400);
            }
            $d = ORM::for_table('tbl_plans')->create();
            $d->type = $db_type;
            $old = null;
        }

        if ($db_type === 'Balance') {
            if (function_exists('run_hook')) {
                try { @run_hook($is_edit ? 'edit_ppoe' : 'add_ppoe'); } catch (Throwable $e) {}
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

        $plan_type     = _post('plan_type') ?: 'Personal';
        $id_bw         = (int)_post('id_bw');
        $validity      = (int)_post('validity');
        $validity_unit = _post('validity_unit') ?: 'Days';
        $raw_routers   = trim(_post('routers'));
        $radius_flag   = (_post('radius') == '1' || _post('is_radius') == '1' || strtolower($raw_routers) === 'radius');
        $pool          = trim(_post('pool_name') ?: _post('pool'));
        $plan_expired  = (int)(_post('plan_expired') ?: 0);
        $expired_date  = (int)(_post('expired_date') ?: 20);
        $on_login      = _post('on_login') ?? ($d['on_login'] ?? '');
        $on_logout     = _post('on_logout') ?? ($d['on_logout'] ?? '');

        $default_dev = $radius_flag ? 'Radius' : (($db_type === 'PPPOE') ? 'MikrotikPppoe' : (($db_type === 'VPN') ? 'MikrotikVpn' : 'MikrotikHotspot'));
        $device = trim(_post('device') ?: ($d['device'] ?? $default_dev));
        if (empty($device)) $device = $default_dev;

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
                if ($db_type === 'Hotspot') @run_hook($is_edit ? 'edit_plan' : 'add_plan');
                elseif ($db_type === 'PPPOE') @run_hook($is_edit ? 'edit_ppoe' : 'add_ppoe');
                else @run_hook($is_edit ? 'edit_vpn' : 'add_vpn');
            } catch (Throwable $e) {}
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
            $typebp       = _post('typebp') ?: 'Unlimited';
            $limit_type   = _post('limit_type') ?: 'Time_Limit';
            $time_limit   = (int)(_post('time_limit') ?: 0);
            $time_unit    = _post('time_unit') ?: 'Hrs';
            $data_limit   = (int)(_post('data_limit') ?: 0);
            $data_unit    = _post('data_unit') ?: 'MB';
            $shared_users = max(1, (int)(_post('sharedusers') ?: _post('shared_users') ?: 1));

            $d->typebp       = $typebp;
            $d->limit_type   = $limit_type;
            $d->time_limit   = ($typebp === 'Limited') ? $time_limit : 0;
            $d->time_unit    = $time_unit;
            $d->data_limit   = ($typebp === 'Limited') ? $data_limit : 0;
            $d->data_unit    = $data_unit;
            $d->shared_users = $shared_users;

            if ($prepaid === 'no') {
                if ($expired_date > 28 || $expired_date < 1) $expired_date = 20;
                $d->expired_date = $expired_date;
            } else {
                $d->expired_date = 20;
            }
        } else {
            $d->pool = $pool;
            if ($prepaid === 'no') {
                if ($expired_date > 28 || $expired_date < 1) $expired_date = 20;
                $d->expired_date = $expired_date;
            } else {
                $d->expired_date = 0;
            }
        }

        $d->save();

        $router_sync_err = '';
        if (class_exists('Package') && method_exists('Package', 'getDevice')) {
            $dvc = Package::getDevice($d);
            if (($_app_stage ?? '') !== 'demo' && file_exists($dvc)) {
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
        $id = (int)_req('id');
        $d = ORM::for_table('tbl_plans')->find_one($id);
        if (!$d) {
            api_out(false, class_exists('Lang') ? Lang::T('Account Not Found') : 'Paket tidak ditemukan.', null, 404);
        }

        $p_type = strtoupper(trim($d['type']));
        if (function_exists('run_hook')) {
            try {
                if ($p_type === 'HOTSPOT') @run_hook('delete_plan');
                elseif ($p_type === 'PPPOE') @run_hook('delete_ppoe');
                elseif ($p_type === 'BALANCE') @run_hook('delete_balance');
                elseif ($p_type === 'VPN') @run_hook('delete_vpn');
            } catch (Throwable $e) {}
        }

        if ($p_type !== 'BALANCE' && class_exists('Package') && method_exists('Package', 'getDevice')) {
            $dvc = Package::getDevice($d);
            if (($_app_stage ?? '') !== 'demo' && file_exists($dvc)) {
                require_once $dvc;
                $dev_cls = $d['device'];
                if (class_exists($dev_cls)) {
                    try {
                        $dev_inst = new $dev_cls();
                        if (method_exists($dev_inst, 'remove_plan')) {
                            @$dev_inst->remove_plan($d);
                        }
                    } catch (Throwable $e) {}
                }
            }
        }

        $plan_name = $d['name_plan'];
        $d->delete();
        write_admin_log($admin, 'Menghapus paket ' . $p_type . ': ' . $plan_name);
        api_out(true, class_exists('Lang') ? Lang::T('Data Deleted Successfully') : ('Paket ' . $plan_name . ' berhasil dihapus.'));
        break;

    case 'admin_plan_sync':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        @set_time_limit(-1);
        $sync_type = strtolower(trim(_req('type') ?: 'hotspot'));

        $db_type = ($sync_type === 'pppoe') ? 'PPPOE' : (($sync_type === 'vpn') ? 'VPN' : 'Hotspot');
        $plans = ORM::for_table('tbl_plans')->where('type', $db_type)->find_many();

        $done_cnt = 0;
        $fail_cnt = 0;
        $logs = [];

        foreach ($plans as $plan) {
            if (empty($plan['device'])) {
                $plan->device = ($plan['is_radius'] == 1) ? 'Radius' : (($db_type === 'PPPOE') ? 'MikrotikPppoe' : 'MikrotikHotspot');
                $plan->save();
            }
            if (class_exists('Package') && method_exists('Package', 'getDevice')) {
                $dvc = Package::getDevice($plan);
                if (($_app_stage ?? '') !== 'demo') {
                    if (file_exists($dvc)) {
                        require_once $dvc;
                        $cls = $plan['device'];
                        if (class_exists($cls)) {
                            try {
                                (new $cls())->add_plan($plan);
                                $done_cnt++;
                                $logs[] = "DONE : {$plan['name_plan']}, {$plan['device']}";
                            } catch (Throwable $e) {
                                $fail_cnt++;
                                $logs[] = "FAILED : {$plan['name_plan']} ({$e->getMessage()})";
                            }
                        }
                    } else {
                        $fail_cnt++;
                        $logs[] = "FAILED : {$plan['name_plan']}, {$plan['device']} | Device Not Found";
                    }
                }
            }
        }

        api_out(true, "Sinkronisasi paket $db_type selesai ($done_cnt berhasil" . ($fail_cnt > 0 ? ", $fail_cnt gagal" : "") . ").", [
            'done'   => $done_cnt,
            'failed' => $fail_cnt,
            'logs'   => $logs
        ]);
        break;

    case 'admin_bandwidths':
        require_admin_session($api_secret);
        if (function_exists('run_hook')) {
            try { @run_hook('view_list_bandwidth'); } catch (Throwable $e) {}
        }
        $rows = ORM::for_table('tbl_bandwidth')->order_by_desc('id')->find_array();
        foreach ($rows as &$r) {
            $r['burst_parts'] = !empty($r['burst']) ? explode(' ', trim($r['burst'])) : [];
        }
        unset($r);
        api_out(true, 'Daftar bandwidth dimuat', $rows);
        break;

    case 'admin_bandwidth_save':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $id             = (int)_post('id');
        $is_edit        = ($id > 0);
        $name           = trim(_post('name_bw') ?: _post('name'));
        $rate_down      = (int)_post('rate_down');
        $rate_down_unit = (_post('rate_down_unit') === 'Kbps') ? 'Kbps' : 'Mbps';
        $rate_up        = (int)_post('rate_up');
        $rate_up_unit   = (_post('rate_up_unit') === 'Kbps') ? 'Kbps' : 'Mbps';

        if (function_exists('run_hook')) {
            try { @run_hook($is_edit ? 'edit_bandwidth' : 'add_bandwidth'); } catch (Throwable $e) {}
        }

        if ($name === '' || strlen($name) > 255) {
            api_out(false, 'Name should be between 1 to 255 characters', null, 400);
        }
        if ($rate_down <= 0 || $rate_up <= 0) {
            api_out(false, 'Rate Download dan Upload harus lebih dari 0.', null, 400);
        }

        $burst = '';
        if (isset($_POST['burst']) && is_array($_POST['burst'])) {
            $isBurst = true;
            foreach ($_POST['burst'] as $b) {
                if (empty(trim((string)$b))) {
                    $isBurst = false;
                }
            }
            if ($isBurst) {
                $burst = implode(' ', array_map('trim', $_POST['burst']));
            }
        } else {
            $b_limit     = trim((string)_post('burst_limit'));
            $b_threshold = trim((string)_post('burst_threshold'));
            $b_time      = trim((string)_post('burst_time'));
            $b_priority  = trim((string)_post('burst_priority'));
            $b_limit_at  = trim((string)_post('burst_limit_at'));

            if ($b_limit !== '' && $b_threshold !== '' && $b_time !== '' && $b_priority !== '') {
                $parts = [$b_limit, $b_threshold, $b_time, $b_priority];
                if ($b_limit_at !== '') $parts[] = $b_limit_at;
                $burst = implode(' ', $parts);
            } else {
                $burst = trim((string)_post('burst'));
            }
        }

        if ($is_edit) {
            $d = ORM::for_table('tbl_bandwidth')->find_one($id);
            if (!$d) {
                api_out(false, class_exists('Lang') ? Lang::T('Data Not Found') : 'Data Bandwidth tidak ditemukan.', null, 404);
            }
            if ($d['name_bw'] !== $name) {
                $c = ORM::for_table('tbl_bandwidth')->where('name_bw', $name)->find_one();
                if ($c) {
                    api_out(false, class_exists('Lang') ? Lang::T('Name Bandwidth Already Exist') : 'Nama Bandwidth sudah digunakan.', null, 400);
                }
            }
        } else {
            $c = ORM::for_table('tbl_bandwidth')->where('name_bw', $name)->find_one();
            if ($c) {
                api_out(false, class_exists('Lang') ? Lang::T('Name Bandwidth Already Exist') : 'Nama Bandwidth sudah terdaftar.', null, 400);
            }
            $d = ORM::for_table('tbl_bandwidth')->create();
        }

        $d->name_bw        = $name;
        $d->rate_down      = $rate_down;
        $d->rate_down_unit = $rate_down_unit;
        $d->rate_up        = $rate_up;
        $d->rate_up_unit   = $rate_up_unit;
        $d->burst          = $burst;
        $d->save();

        write_admin_log($admin, ($is_edit ? 'Mengubah' : 'Menambah') . ' Bandwidth: ' . $name);
        api_out(
            true,
            class_exists('Lang') ? Lang::T($is_edit ? 'Data Updated Successfully' : 'Data Created Successfully') : ('Bandwidth ' . $name . ' berhasil disimpan.'),
            $d->as_array()
        );
        break;

    case 'admin_bandwidth_delete':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $id = (int)_req('id');
        if (function_exists('run_hook')) {
            try { @run_hook('delete_bandwidth'); } catch (Throwable $e) {}
        }
        $bw = ORM::for_table('tbl_bandwidth')->find_one($id);
        if (!$bw) {
            api_out(false, class_exists('Lang') ? Lang::T('Account Not Found') : 'Bandwidth tidak ditemukan.', null, 404);
        }
        $bw->delete();
        api_out(true, class_exists('Lang') ? Lang::T('Data Deleted Successfully') : 'Bandwidth berhasil dihapus.');
        break;
}
