<?php

/**
 * PHPNuxBill Mobile API Plugin - Network, Routers, Pools & MikroTik Live Monitor Module
 * Path: system/plugin/mobile_api_modules/core_network_mikrotik.php
 **/

global $config, $_c, $admin, $DEVICE_PATH, $_app_stage, $phpnux_root, $api_secret;

switch ($action) {
    // =========================================================================
    // 1. MANAJEMEN ROUTER & IP POOL (SELARAS DENGAN routers.php & pool.php)
    // =========================================================================
    case 'admin_routers':
        require_admin_session($api_secret);
        api_out(true, 'Daftar router dimuat', ORM::for_table('tbl_routers')->order_by_asc('id')->find_array());
        break;

    case 'admin_router_save':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $id      = (int) _post('id');
        $is_edit = ($id > 0);
        $name    = trim((string) _post('name'));
        $ip      = trim((string) _post('ip_address'));
        $user    = trim((string) _post('username'));
        $pass    = (string) _post('password');
        $enabled = (int) (_post('enabled') ?? 1);
        $test_it = (_post('testIt') === '1' || _post('test_it') === '1');

        if ($name === '' || $ip === '' || $user === '') {
            api_out(false, 'Nama, IP Address, dan Username Router wajib diisi.', null, 400);
        }
        if (strtolower($name) === 'radius') {
            api_out(false, 'Nama "Radius" dicadangkan oleh sistem.', null, 400);
        }

        $oldname = '';
        if ($is_edit) {
            $r = ORM::for_table('tbl_routers')->find_one($id);
            if (!$r) {
                api_out(false, 'Router tidak ditemukan.', null, 404);
            }
            $oldname = (string) $r['name'];
            if ($oldname !== $name && ORM::for_table('tbl_routers')->where('name', $name)->where_not_equal('id', $id)->find_one()) {
                api_out(false, 'Nama Router sudah digunakan.', null, 400);
            }
            if ($r['ip_address'] !== $ip && ORM::for_table('tbl_routers')->where('ip_address', $ip)->where_not_equal('id', $id)->find_one()) {
                api_out(false, 'IP Address Router sudah terdaftar.', null, 400);
            }
            if ($pass === '') {
                $pass = (string) $r['password'];
            }
            if (function_exists('run_hook')) {
                try {
                    @run_hook('router_edit');
                } catch (Throwable $e) {
                }
            }
        } else {
            if (ORM::for_table('tbl_routers')->where('name', $name)->find_one()) {
                api_out(false, 'Nama Router sudah digunakan.', null, 400);
            }
            if (ORM::for_table('tbl_routers')->where('ip_address', $ip)->find_one()) {
                api_out(false, class_exists('Lang') ? Lang::T('IP Router Already Exist') : 'IP Router sudah terdaftar.', null, 400);
            }
            if (function_exists('run_hook')) {
                try {
                    @run_hook('add_router');
                } catch (Throwable $e) {
                }
            }
            $r = ORM::for_table('tbl_routers')->create();
        }

        if ($test_it) {
            $test_client = connect_ros($ip, $user, $pass);
            if (!$test_client) {
                api_out(false, 'Gagal terhubung ke MikroTik (' . $ip . '). Periksa IP, Port API, Username, dan Password.', null, 400);
            }
        }

        $r->name        = $name;
        $r->ip_address  = $ip;
        $r->username    = $user;
        $r->password    = $pass;
        $r->description = trim((string) (_post('description') ?: ($r['description'] ?? '')));
        $r->coordinates = trim((string) _post('coordinates'));
        $r->coverage    = trim((string) (_post('coverage') ?: ($r['coverage'] ?: '350')));
        $r->enabled     = $enabled;
        $r->save();

        // Sinkronisasi nama router di seluruh tabel terkait jika nama router diubah (sesuai routers.php)
        if ($is_edit && $oldname !== '' && $name !== $oldname) {
            foreach (['tbl_plans', 'tbl_payment_gateway', 'tbl_user_recharges', 'tbl_voucher', 'tbl_pool'] as $tbl_sync) {
                try {
                    $rs = ORM::for_table($tbl_sync)->where('routers', $oldname)->find_result_set();
                    $rs->set('routers', $name);
                    $rs->save();
                } catch (Throwable $e) {
                }
            }
        }

        write_admin_log($admin, ($is_edit ? 'Mengubah' : 'Menambah') . ' Router: ' . $name);
        api_out(true, 'Router ' . $name . ' berhasil disimpan.', $r->as_array());
        break;

    case 'admin_router_delete':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $r = ORM::for_table('tbl_routers')->find_one((int) _req('id'));
        if (!$r) {
            api_out(false, 'Router tidak ditemukan.', null, 404);
        }
        if (function_exists('run_hook')) {
            try {
                @run_hook('router_delete');
            } catch (Throwable $e) {
            }
        }
        $r_name = $r['name'];
        $r->delete();
        write_admin_log($admin, 'Menghapus Router: ' . $r_name);
        api_out(true, 'Router ' . $r_name . ' berhasil dihapus.');
        break;

    case 'admin_pools':
        require_admin_session($api_secret);
        api_out(true, 'Daftar IP Pool dimuat', ORM::for_table('tbl_pool')->order_by_desc('id')->find_array());
        break;

    case 'admin_pool_save':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $id        = (int) _post('id');
        $is_edit   = ($id > 0);
        $pool_name = trim((string) (_post('pool_name') ?: _post('name')));
        $local_ip  = trim((string) _post('local_ip'));
        $range_ip  = trim((string) (_post('range_ip') ?: _post('ip_address')));
        $routers   = trim((string) _post('routers'));

        if ($pool_name === '' || $range_ip === '' || $routers === '') {
            api_out(false, 'Nama Pool, Rentang IP, dan Router wajib diisi.', null, 400);
        }

        if ($is_edit) {
            $p   = ORM::for_table('tbl_pool')->find_one($id);
            $old = ORM::for_table('tbl_pool')->find_one($id);
            if (!$p) {
                api_out(false, 'IP Pool tidak ditemukan.', null, 404);
            }
            if (function_exists('run_hook')) {
                try {
                    @run_hook('edit_pool');
                } catch (Throwable $e) {
                }
            }
        } else {
            if (ORM::for_table('tbl_pool')->where('pool_name', $pool_name)->where('routers', $routers)->find_one()) {
                api_out(false, 'Nama IP Pool sudah ada pada router tersebut.', null, 400);
            }
            if (function_exists('run_hook')) {
                try {
                    @run_hook('add_pool');
                } catch (Throwable $e) {
                }
            }
            $p   = ORM::for_table('tbl_pool')->create();
            $old = null;
        }

        $p->pool_name = $pool_name;
        $p->local_ip  = $local_ip;
        $p->range_ip  = $range_ip;
        $p->routers   = $routers;
        $p->save();

        // Sinkronkan IP Pool ke MikroTik (sesuai pool.php)
        if (strtolower($routers) !== 'radius') {
            $dev_dir   = !empty($DEVICE_PATH) ? $DEVICE_PATH : 'system/devices';
            $pppoe_dev = rtrim($dev_dir, '/\\') . DIRECTORY_SEPARATOR . 'MikrotikPppoe.php';
            if (file_exists($pppoe_dev)) {
                require_once $pppoe_dev;
                if (class_exists('MikrotikPppoe')) {
                    try {
                        $mp = new MikrotikPppoe();
                        if ($is_edit && $old && method_exists($mp, 'update_pool')) {
                            $mp->update_pool($old, $p);
                        } elseif (method_exists($mp, 'add_pool')) {
                            $mp->add_pool($p);
                        }
                    } catch (Throwable $e) {
                    }
                }
            }
        }

        write_admin_log($admin, ($is_edit ? 'Mengubah' : 'Menambah') . ' IP Pool: ' . $pool_name);
        api_out(true, 'IP Pool berhasil disimpan.', $p->as_array());
        break;

    case 'admin_pool_delete':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $p = ORM::for_table('tbl_pool')->find_one((int) _req('id'));
        if (!$p) {
            api_out(false, 'IP Pool tidak ditemukan.', null, 404);
        }
        if (function_exists('run_hook')) {
            try {
                @run_hook('delete_pool');
            } catch (Throwable $e) {
            }
        }
        if (strtolower((string) $p['routers']) !== 'radius') {
            $dev_dir   = !empty($DEVICE_PATH) ? $DEVICE_PATH : 'system/devices';
            $pppoe_dev = rtrim($dev_dir, '/\\') . DIRECTORY_SEPARATOR . 'MikrotikPppoe.php';
            if (file_exists($pppoe_dev)) {
                require_once $pppoe_dev;
                if (class_exists('MikrotikPppoe')) {
                    try {
                        $mp = new MikrotikPppoe();
                        if (method_exists($mp, 'remove_pool')) {
                            $mp->remove_pool($p);
                        }
                    } catch (Throwable $e) {
                    }
                }
            }
        }
        $p_name = $p['pool_name'];
        $p->delete();
        write_admin_log($admin, 'Menghapus IP Pool: ' . $p_name);
        api_out(true, 'IP Pool ' . $p_name . ' berhasil dihapus.');
        break;

    // =========================================================================
    // 2. MIKROTIK LIVE STATUS, RESOURCE HEALTH & TRAFFIC INTERFACE
    // =========================================================================
    case 'mikrotik_live':
    case 'admin_router_status':
        require_admin_session($api_secret);
        $req_router_id = (int) _req('router_id');
        $req_iface     = trim((string) _req('interface'));

        $q = ORM::for_table('tbl_routers')->where('enabled', 1);
        if ($req_router_id > 0) {
            $q->where('id', $req_router_id);
        }
        $routers = $q->find_array();
        $results = [];

        foreach ($routers as $r) {
            $t0     = microtime(true);
            $client = connect_ros($r['ip_address'], $r['username'], $r['password']);
            $ping   = round((microtime(true) - $t0) * 1000);

            if (!$client) {
                $results[] = [
                    'id'                   => (int) $r['id'],
                    'name'                 => $r['name'],
                    'ip_address'           => $r['ip_address'],
                    'online'               => false,
                    'ping_ms'              => 0,
                    'cpu_load'             => 0,
                    'ram_percent'          => 0,
                    'free_memory'          => '0 B',
                    'total_memory'         => '0 B',
                    'uptime'               => 'Offline',
                    'board_name'           => '-',
                    'version'              => '-',
                    'hotspot_online'       => 0,
                    'pppoe_online'         => 0,
                    'monitored_interface'  => '-',
                    'interfaces_list'      => [],
                    'interfaces_traffic'   => [],
                    'rx_bps'               => 0,
                    'tx_bps'               => 0,
                    'download_mbps'        => 0.0,
                    'upload_mbps'          => 0.0,
                    'download_speed_label' => '0 bps',
                    'upload_speed_label'   => '0 bps',
                    'rx_speed_label'       => '0 bps',
                    'tx_speed_label'       => '0 bps'
                ];
                continue;
            }

            try {
                $res_resp  = $client->sendSync(new PEAR2\Net\RouterOS\Request('/system/resource/print'));
                $cpu       = 0;
                $free_mem  = 0.0;
                $total_mem = 0.0;
                $up        = '-';
                $board     = '-';
                $ver       = '-';
                $platform  = 'MikroTik';
                $arch      = '-';
                $cpu_model = '-';
                $cpu_count = '1';
                $cpu_freq  = '-';
                $free_hdd  = 0.0;
                $total_hdd = 0.0;

                foreach ($res_resp as $item) {
                    if ($item->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                        $cpu       = (int) ($item->getProperty('cpu-load') ?: 0);
                        $free_mem  = (float) ($item->getProperty('free-memory') ?: 0);
                        $total_mem = (float) ($item->getProperty('total-memory') ?: 0);
                        $up        = $item->getProperty('uptime') ?: '-';
                        $board     = $item->getProperty('board-name') ?: '-';
                        $ver       = $item->getProperty('version') ?: '-';
                        $platform  = $item->getProperty('platform') ?: 'MikroTik';
                        $arch      = $item->getProperty('architecture-name') ?: '-';
                        $cpu_model = $item->getProperty('cpu') ?: '-';
                        $cpu_count = $item->getProperty('cpu-count') ?: '1';
                        $cpu_freq  = $item->getProperty('cpu-frequency') ?: '-';
                        $free_hdd  = (float) ($item->getProperty('free-hdd-space') ?: 0);
                        $total_hdd = (float) ($item->getProperty('total-hdd-space') ?: 0);
                        break;
                    }
                }

                $if_resp            = $client->sendSync(new PEAR2\Net\RouterOS\Request('/interface/print'));
                $interfaces_list    = [];
                $interfaces_traffic = [];
                $default_iface      = '';

                foreach ($if_resp as $iface) {
                    if ($iface->getType() !== PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                        continue;
                    }
                    $if_name = $iface->getProperty('name');
                    if (empty($if_name)) {
                        continue;
                    }
                    $is_dyn_pppoe = (strpos($if_name, '<pppoe-') === 0);
                    if (!$is_dyn_pppoe) {
                        $interfaces_list[] = $if_name;
                    }
                    $is_running = ($iface->getProperty('running') === 'true');
                    if ($default_iface === '' && $is_running && !$is_dyn_pppoe) {
                        $default_iface = $if_name;
                    }
                    $tx_b = (float) ($iface->getProperty('tx-byte') ?: 0);
                    $rx_b = (float) ($iface->getProperty('rx-byte') ?: 0);
                    if (!$is_dyn_pppoe) {
                        $interfaces_traffic[] = [
                            'name'    => $if_name,
                            'type'    => $iface->getProperty('type') ?: '-',
                            'running' => $is_running,
                            'status'  => $is_running ? 'UP' : 'DOWN',
                            'tx'      => fmt_bytes($tx_b),
                            'rx'      => fmt_bytes($rx_b),
                            'total'   => fmt_bytes($tx_b + $rx_b)
                        ];
                    }
                }

                if ($default_iface === '' && !empty($interfaces_list)) {
                    $default_iface = $interfaces_list[0];
                }
                $target_iface = ($req_iface !== '' && in_array($req_iface, $interfaces_list, true))
                    ? $req_iface
                    : $default_iface;

                $tx_bps = 0.0;
                $rx_bps = 0.0;
                if ($target_iface !== '') {
                    $mon_req = new PEAR2\Net\RouterOS\Request('/interface/monitor-traffic');
                    $mon_req->setArgument('interface', $target_iface);
                    $mon_req->setArgument('once', '');
                    $mon_res = $client->sendSync($mon_req);
                    foreach ($mon_res as $m_item) {
                        if ($m_item->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                            $tx_bps = parse_ros_rate($m_item->getProperty('tx-bits-per-second'));
                            $rx_bps = parse_ros_rate($m_item->getProperty('rx-bits-per-second'));
                            break;
                        }
                    }
                }

                $hs = 0;
                foreach ($client->sendSync(new PEAR2\Net\RouterOS\Request('/ip/hotspot/active/print')) as $h) {
                    if ($h->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                        $hs++;
                    }
                }
                $ppp = 0;
                foreach ($client->sendSync(new PEAR2\Net\RouterOS\Request('/ppp/active/print')) as $p) {
                    if ($p->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                        $ppp++;
                    }
                }

                $used_mem = max(0.0, $total_mem - $free_mem);
                $ram_pct  = $total_mem > 0 ? round(($used_mem / $total_mem) * 100, 1) : 0;
                $used_hdd = max(0.0, $total_hdd - $free_hdd);
                $hdd_pct  = $total_hdd > 0 ? round(($used_hdd / $total_hdd) * 100, 1) : 0;

                $rx_mbps = round($rx_bps / 1000000.0, 2);
                $tx_mbps = round($tx_bps / 1000000.0, 2);

                $results[] = [
                    'id'                   => (int) $r['id'],
                    'name'                 => $r['name'],
                    'ip_address'           => $r['ip_address'],
                    'online'               => true,
                    'ping_ms'              => $ping,
                    'platform'             => $platform,
                    'board_name'           => $board,
                    'arch'                 => $arch,
                    'version'              => $ver,
                    'cpu'                  => $cpu_model,
                    'cpu_load'             => $cpu,
                    'cpu_info'             => $cpu_count . ' Core / ' . $cpu_freq . ' MHz / ' . $cpu . '%',
                    'ram_percent'          => $ram_pct,
                    'free_memory'          => fmt_bytes($free_mem),
                    'total_memory'         => fmt_bytes($total_mem),
                    'hdd_percent'          => $hdd_pct,
                    'free_hdd'             => fmt_bytes($free_hdd),
                    'total_hdd'            => fmt_bytes($total_hdd),
                    'uptime'               => $up,
                    'hotspot_online'       => $hs,
                    'pppoe_online'         => $ppp,
                    'monitored_interface'  => $target_iface,
                    'interfaces_list'      => $interfaces_list,
                    'interfaces_traffic'   => $interfaces_traffic,
                    'rx_bps'               => round($rx_bps),
                    'tx_bps'               => round($tx_bps),
                    'rx_mbps'              => $rx_mbps,
                    'tx_mbps'              => $tx_mbps,
                    'rx_speed_label'       => fmt_bps($rx_bps),
                    'tx_speed_label'       => fmt_bps($tx_bps),
                    'download_mbps'        => $rx_mbps,
                    'upload_mbps'          => $tx_mbps,
                    'download_speed_label' => fmt_bps($rx_bps),
                    'upload_speed_label'   => fmt_bps($tx_bps)
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'id'         => (int) $r['id'],
                    'name'       => $r['name'],
                    'ip_address' => $r['ip_address'],
                    'online'     => false,
                    'ping_ms'    => 0,
                    'uptime'     => 'Error: ' . $e->getMessage()
                ];
            }
        }

        api_out(true, 'Status live router berhasil dimuat', $results);
        break;

    // =========================================================================
    // 3. DAFTAR PENGGUNA ONLINE & KONSUMSI KUOTA (HOTSPOT + PPPOE)
    // =========================================================================
    case 'online_users':
    case 'admin_online_users':
    case 'data_usage':
    case 'admin_data_usage':
        require_admin_session($api_secret);
        $req_router_id = (int) _req('router_id');
        $q             = ORM::for_table('tbl_routers')->where('enabled', 1);
        if ($req_router_id > 0) {
            $q->where('id', $req_router_id);
        }
        $routers = $q->find_array();
        $users   = [];
        $rx_sum  = 0.0;
        $tx_sum  = 0.0;

        foreach ($routers as $r) {
            $client = connect_ros($r['ip_address'], $r['username'], $r['password']);
            if (!$client) {
                continue;
            }
            try {
                $interface_data = [];
                foreach ($client->sendSync(new PEAR2\Net\RouterOS\Request('/interface/print')) as $if_item) {
                    if ($if_item->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                        $if_name = $if_item->getProperty('name');
                        if (!empty($if_name)) {
                            $interface_data[$if_name] = [
                                'txBytes' => (float) ($if_item->getProperty('tx-byte') ?: 0),
                                'rxBytes' => (float) ($if_item->getProperty('rx-byte') ?: 0)
                            ];
                        }
                    }
                }

                foreach ($client->sendSync(new PEAR2\Net\RouterOS\Request('/ip/hotspot/active/print')) as $hs) {
                    if ($hs->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                        $uname   = $hs->getProperty('user') ?: '-';
                        $rx      = (float) ($hs->getProperty('bytes-in') ?: 0);
                        $tx      = (float) ($hs->getProperty('bytes-out') ?: 0);
                        $rx_sum += $rx;
                        $tx_sum += $tx;
                        $users[] = [
                            'ros_id'       => $hs->getProperty('.id'),
                            'username'     => $uname,
                            'service'      => 'Hotspot',
                            'server'       => $hs->getProperty('server') ?: 'all',
                            'router'       => $r['name'],
                            'router_id'    => (int) $r['id'],
                            'ip_address'   => $hs->getProperty('address') ?: '-',
                            'mac_address'  => $hs->getProperty('mac-address') ?: '-',
                            'uptime'       => $hs->getProperty('uptime') ?: '0s',
                            'session_time' => $hs->getProperty('session-time-left') ?: '-',
                            'upload'       => fmt_bytes($rx),
                            'download'     => fmt_bytes($tx),
                            'rx_bytes'     => fmt_bytes($rx),
                            'tx_bytes'     => fmt_bytes($tx),
                            'total'        => fmt_bytes($rx + $tx),
                            'total_bytes'  => 'TX: ' . fmt_bytes($tx) . ' • RX: ' . fmt_bytes($rx) . ' (Total: ' . fmt_bytes($rx + $tx) . ')',
                            'total_raw'    => $rx + $tx
                        ];
                    }
                }

                foreach ($client->sendSync(new PEAR2\Net\RouterOS\Request('/ppp/active/print')) as $ppp) {
                    if ($ppp->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                        $uname   = $ppp->getProperty('name') ?: '-';
                        $if_key  = "<pppoe-$uname>";
                        $tx      = isset($interface_data[$if_key]) ? $interface_data[$if_key]['txBytes'] : 0.0;
                        $rx      = isset($interface_data[$if_key]) ? $interface_data[$if_key]['rxBytes'] : 0.0;
                        $rx_sum += $rx;
                        $tx_sum += $tx;
                        $users[] = [
                            'ros_id'       => $ppp->getProperty('.id'),
                            'username'     => $uname,
                            'service'      => 'PPPoE',
                            'server'       => $ppp->getProperty('service') ?: 'pppoe',
                            'router'       => $r['name'],
                            'router_id'    => (int) $r['id'],
                            'ip_address'   => $ppp->getProperty('address') ?: '-',
                            'mac_address'  => $ppp->getProperty('caller-id') ?: '-',
                            'uptime'       => $ppp->getProperty('uptime') ?: '0s',
                            'session_time' => '-',
                            'upload'       => fmt_bytes($rx),
                            'download'     => fmt_bytes($tx),
                            'rx_bytes'     => fmt_bytes($rx),
                            'tx_bytes'     => fmt_bytes($tx),
                            'total'        => fmt_bytes($rx + $tx),
                            'total_bytes'  => 'TX: ' . fmt_bytes($tx) . ' • RX: ' . fmt_bytes($rx) . ' (Total: ' . fmt_bytes($rx + $tx) . ')',
                            'total_raw'    => $rx + $tx
                        ];
                    }
                }
            } catch (Throwable $e) {
            }
        }

        api_out(true, 'Daftar pengguna online berhasil dimuat', [
            'total_online' => count($users),
            'users'        => $users,
            'summary'      => [
                'total_upload'   => fmt_bytes($rx_sum),
                'total_download' => fmt_bytes($tx_sum),
                'total_traffic'  => fmt_bytes($rx_sum + $tx_sum)
            ]
        ]);
        break;

    // =========================================================================
    // 4. MIKROTIK EXTRA (LOG ROUTEROS & SINYAL WIRELESS)
    // =========================================================================
    case 'mikrotik_extra':
        require_admin_session($api_secret);
        $router_id = (int) _req('router_id');
        $sub_type  = trim((string) (_req('sub_type') ?: 'logs'));

        $q = ORM::for_table('tbl_routers')->where('enabled', 1);
        if ($router_id > 0) {
            $q->where('id', $router_id);
        }
        $r = $q->find_one();
        if (!$r) {
            api_out(false, 'Router tidak ditemukan.', null, 404);
        }

        $client = connect_ros($r['ip_address'], $r['username'], $r['password']);
        if (!$client) {
            api_out(false, 'Gagal terhubung ke Router ' . $r['name'], null, 500);
        }

        if ($sub_type === 'wlan') {
            $signals = [];
            try {
                $wlan_res = $client->sendSync(new PEAR2\Net\RouterOS\Request('/interface/wireless/registration-table/print'));
                foreach ($wlan_res as $sig) {
                    if ($sig->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                        $signals[] = [
                            'interface'       => $sig->getProperty('interface') ?: '-',
                            'mac_address'     => $sig->getProperty('mac-address') ?: '-',
                            'uptime'          => $sig->getProperty('uptime') ?: '-',
                            'last_ip'         => $sig->getProperty('last-ip') ?: '-',
                            'signal_strength' => $sig->getProperty('signal-strength') ?: '-',
                            'tx_ccq'          => $sig->getProperty('tx-ccq') ?: '-',
                            'rx_rate'         => $sig->getProperty('rx-rate') ?: '-',
                            'tx_rate'         => $sig->getProperty('tx-rate') ?: '-'
                        ];
                    }
                }
            } catch (Throwable $e) {
            }
            api_out(true, 'Data sinyal wireless dimuat', $signals);
        }

        $ros_logs = [];
        try {
            $log_res = $client->sendSync(new PEAR2\Net\RouterOS\Request('/log/print'));
            foreach ($log_res as $entry) {
                if ($entry->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                    $ros_logs[] = [
                        'time'    => $entry->getProperty('time') ?: '-',
                        'topics'  => $entry->getProperty('topics') ?: 'system',
                        'message' => $entry->getProperty('message') ?: '-'
                    ];
                }
            }
        } catch (Throwable $e) {
        }
        $ros_logs = array_slice(array_reverse($ros_logs), 0, 100);
        api_out(true, 'Log RouterOS dimuat', $ros_logs);
        break;

    // =========================================================================
    // 5. LIVE SPEED PER PELANGGAN (REAL-TIME BPS TANPA SKALA MINIMUM HARDCODE)
    // =========================================================================
    case 'user_live_traffic':
    case 'admin_user_live_traffic':
        require_admin_session($api_secret);
        $uname        = trim((string) _req('username'));
        $cid_param    = (int) _req('customer_id');
        $router_param = trim((string) (_req('router') ?: _req('router_id')));
        $service_hint = strtolower(trim((string) (_req('service') ?: '')));

        $cust = null;
        if ($cid_param > 0) {
            $cust = ORM::for_table('tbl_customers')->find_one($cid_param);
        }
        if (!$cust && $uname !== '') {
            $cust = ORM::for_table('tbl_customers')
                ->where_raw('(username = ? OR pppoe_username = ?)', [$uname, $uname])
                ->find_one();
        }
        if ($cust && $uname === '') {
            $uname = (string) $cust['username'];
        }
        if ($uname === '') {
            api_out(false, 'Username pelanggan tidak ditemukan.', null, 400);
        }

        $rech          = null;
        $plan          = null;
        $bw            = null;
        $custom_fields = [];
        $recent_trx    = [];

        if ($cust) {
            $rech = ORM::for_table('tbl_user_recharges')
                ->where('customer_id', $cust['id'])
                ->order_by_desc('id')
                ->find_one();
            try {
                foreach (ORM::for_table('tbl_customers_fields')->where('customer_id', $cust['id'])->find_array() as $cf) {
                    $custom_fields[] = [
                        'name'  => $cf['field_name'] ?? '',
                        'value' => $cf['field_value'] ?? ''
                    ];
                }
            } catch (Throwable $e) {
            }
            $recent_trx = ORM::for_table('tbl_transactions')
                ->where('username', $cust['username'])
                ->order_by_desc('id')
                ->limit(5)
                ->find_array();
        } else {
            $rech = ORM::for_table('tbl_user_recharges')
                ->where('username', $uname)
                ->order_by_desc('id')
                ->find_one();
            $recent_trx = ORM::for_table('tbl_transactions')
                ->where('username', $uname)
                ->order_by_desc('id')
                ->limit(5)
                ->find_array();
        }

        if ($rech && !empty($rech['plan_id'])) {
            $plan = ORM::for_table('tbl_plans')->find_one($rech['plan_id']);
            if ($plan && !empty($plan['id_bw'])) {
                $bw = ORM::for_table('tbl_bandwidth')->find_one($plan['id_bw']);
            }
        }

        $r = null;
        if ($router_param !== '' && strtolower($router_param) !== 'all') {
            $r = is_numeric($router_param)
                ? ORM::for_table('tbl_routers')->find_one((int) $router_param)
                : ORM::for_table('tbl_routers')->where('name', $router_param)->find_one();
        }
        if (!$r && $rech && !empty($rech['routers']) && strtolower((string) $rech['routers']) !== 'radius') {
            $r = ORM::for_table('tbl_routers')->where('name', $rech['routers'])->find_one();
        }
        if (!$r) {
            $r = ORM::for_table('tbl_routers')->where('enabled', 1)->find_one();
        }

        $is_online      = false;
        $ros_id         = '';
        $active_devices = 0;
        $service_type   = ($service_hint === 'pppoe') ? 'PPPoE' : (($service_hint === 'hotspot') ? 'Hotspot' : ($cust['service_type'] ?? ($rech['type'] ?? 'Hotspot')));
        $ip_address     = '-';
        $mac_address    = '-';
        $uptime         = 'Offline';
        $session_left   = '-';
        $idle_time      = '-';
        $login_by       = '-';
        $server_name    = '-';
        $rx_bytes       = 0.0;
        $tx_bytes       = 0.0;
        $rx_bps         = 0.0;
        $tx_bps         = 0.0;

        if ($r) {
            $client = connect_ros($r['ip_address'], $r['username'], $r['password']);
            if ($client) {
                try {
                    if ($service_hint !== 'pppoe') {
                        $hs_req = new PEAR2\Net\RouterOS\Request('/ip/hotspot/active/print');
                        $hs_req->setQuery(PEAR2\Net\RouterOS\Query::where('user', $uname));
                        foreach ($client->sendSync($hs_req) as $hs) {
                            if ($hs->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                                $is_online    = true;
                                $active_devices++;
                                $service_type = 'Hotspot';
                                if ($ros_id === '') {
                                    $ros_id = $hs->getProperty('.id') ?: '';
                                }
                                if ($ip_address === '-') {
                                    $ip_address = $hs->getProperty('address') ?: '-';
                                }
                                if ($mac_address === '-') {
                                    $mac_address = $hs->getProperty('mac-address') ?: '-';
                                }
                                if ($uptime === 'Offline') {
                                    $uptime = $hs->getProperty('uptime') ?: '0s';
                                }
                                if ($session_left === '-') {
                                    $session_left = $hs->getProperty('session-time-left') ?: '-';
                                }
                                if ($idle_time === '-') {
                                    $idle_time = $hs->getProperty('idle-time') ?: '-';
                                }
                                if ($login_by === '-') {
                                    $login_by = $hs->getProperty('login-by') ?: '-';
                                }
                                if ($server_name === '-') {
                                    $server_name = $hs->getProperty('server') ?: 'all';
                                }
                                $rx_bytes += (float) ($hs->getProperty('bytes-in') ?: 0);
                                $tx_bytes += (float) ($hs->getProperty('bytes-out') ?: 0);
                            }
                        }
                    }

                    if (!$is_online) {
                        $ppp_candidates = array_values(array_unique(array_filter([
                            $uname,
                            $cust ? ($cust['pppoe_username'] ?? '') : '',
                            $cust ? ($cust['username'] ?? '') : ''
                        ])));
                        foreach ($ppp_candidates as $p_user) {
                            $ppp_req = new PEAR2\Net\RouterOS\Request('/ppp/active/print');
                            $ppp_req->setQuery(PEAR2\Net\RouterOS\Query::where('name', $p_user));
                            foreach ($client->sendSync($ppp_req) as $ppp) {
                                if ($ppp->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                                    $is_online      = true;
                                    $active_devices = 1;
                                    $service_type   = 'PPPoE';
                                    $uname          = $p_user;
                                    $ros_id         = $ppp->getProperty('.id') ?: '';
                                    $ip_address     = $ppp->getProperty('address') ?: '-';
                                    $mac_address    = $ppp->getProperty('caller-id') ?: '-';
                                    $uptime         = $ppp->getProperty('uptime') ?: '0s';
                                    $server_name    = $ppp->getProperty('service') ?: 'pppoe';
                                    $login_by       = $ppp->getProperty('encoding') ?: 'PPPoE';
                                    break 2;
                                }
                            }
                        }

                        if ($is_online && $service_type === 'PPPoE') {
                            $if_name = "<pppoe-$uname>";
                            $if_req  = new PEAR2\Net\RouterOS\Request('/interface/print');
                            $if_req->setQuery(PEAR2\Net\RouterOS\Query::where('name', $if_name));
                            foreach ($client->sendSync($if_req) as $iface) {
                                if ($iface->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                                    $rx_bytes = (float) ($iface->getProperty('rx-byte') ?: 0);
                                    $tx_bytes = (float) ($iface->getProperty('tx-byte') ?: 0);
                                    break;
                                }
                            }

                            $mon_req = new PEAR2\Net\RouterOS\Request('/interface/monitor-traffic');
                            $mon_req->setArgument('interface', $if_name);
                            $mon_req->setArgument('once', '');
                            foreach ($client->sendSync($mon_req) as $m_item) {
                                if ($m_item->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                                    $rx_bps = parse_ros_rate($m_item->getProperty('rx-bits-per-second'));
                                    $tx_bps = parse_ros_rate($m_item->getProperty('tx-bits-per-second'));
                                    break;
                                }
                            }
                        }
                    }

                    // Fallback via Simple Queue jika rate interface bernilai 0
                    if ($is_online && $rx_bps <= 0 && $tx_bps <= 0) {
                        foreach ($client->sendSync(new PEAR2\Net\RouterOS\Request('/queue/simple/print')) as $q_item) {
                            if ($q_item->getType() !== PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                                continue;
                            }
                            $q_name   = (string) ($q_item->getProperty('name') ?: '');
                            $q_target = (string) ($q_item->getProperty('target') ?: '');
                            $match_u  = (stripos($q_name, $uname) !== false) || ($ip_address !== '-' && strpos($q_target, $ip_address) !== false);
                            if (!$match_u) {
                                continue;
                            }
                            $rate_str = (string) ($q_item->getProperty('rate') ?: '0/0');
                            $r_parts  = explode('/', $rate_str);
                            if (count($r_parts) === 2) {
                                $rx_bps = parse_ros_rate($r_parts[0]);
                                $tx_bps = parse_ros_rate($r_parts[1]);
                            }
                            if ($rx_bytes <= 0 && $tx_bytes <= 0) {
                                $b_str   = (string) ($q_item->getProperty('bytes') ?: '0/0');
                                $b_parts = explode('/', $b_str);
                                if (count($b_parts) === 2) {
                                    $rx_bytes = (float) $b_parts[0];
                                    $tx_bytes = (float) $b_parts[1];
                                }
                            }
                            break;
                        }
                    }

                    // Delta byte sampler jika RouterOS tidak menyediakan rate langsung
                    $cache_file = sys_get_temp_dir() . '/phpnuxbill_usr_spd_' . md5(($r['id'] ?? 0) . '_' . $uname) . '.json';
                    $now_ms     = microtime(true);
                    if ($is_online && $rx_bps <= 0 && $tx_bps <= 0 && ($rx_bytes > 0 || $tx_bytes > 0)) {
                        if (file_exists($cache_file)) {
                            $prev = json_decode((string) @file_get_contents($cache_file), true);
                            if (is_array($prev) && isset($prev['ts'])) {
                                $dt = $now_ms - (float) $prev['ts'];
                                if ($dt >= 0.35 && $dt <= 15.0) {
                                    $d_rx   = max(0.0, $rx_bytes - (float) ($prev['rx'] ?? 0));
                                    $d_tx   = max(0.0, $tx_bytes - (float) ($prev['tx'] ?? 0));
                                    $rx_bps = round(($d_rx * 8.0) / $dt);
                                    $tx_bps = round(($d_tx * 8.0) / $dt);
                                }
                            }
                        }
                    }
                    if ($is_online) {
                        @file_put_contents($cache_file, json_encode([
                            'ts' => $now_ms,
                            'rx' => $rx_bytes,
                            'tx' => $tx_bytes
                        ]));
                    }
                } catch (Throwable $e) {
                }
            }
        }

        $rate_label = $bw
            ? ($bw['rate_down'] . ' ' . $bw['rate_down_unit'] . ' / ' . $bw['rate_up'] . ' ' . $bw['rate_up_unit'])
            : '-';
        $validity_label = $plan
            ? ($plan['validity'] . ' ' . $plan['validity_unit'])
            : '-';

        $dl_kbps = round($tx_bps / 1000.0, 1);
        $ul_kbps = round($rx_bps / 1000.0, 1);
        $dl_mbps = round($tx_bps / 1000000.0, 2);
        $ul_mbps = round($rx_bps / 1000000.0, 2);

        api_out(true, 'Detail & live traffic pelanggan dimuat', [
            'online'               => $is_online,
            'ros_id'               => $ros_id,
            'active_devices'       => $active_devices,
            'username'             => $uname,
            'service'              => $service_type,
            'router'               => $r ? $r['name'] : ($rech['routers'] ?? '-'),
            'router_id'            => $r ? (int) $r['id'] : 0,
            'server'               => $server_name,
            'ip_address'           => ($ip_address !== '-' ? $ip_address : ($cust['pppoe_ip'] ?? '-')),
            'mac_address'          => $mac_address,
            'uptime'               => $uptime,
            'session_time'         => $session_left,
            'idle_time'            => $idle_time,
            'login_by'             => $login_by,
            'upload_bytes'         => fmt_bytes($rx_bytes),
            'download_bytes'       => fmt_bytes($tx_bytes),
            'total_bytes'          => fmt_bytes($rx_bytes + $tx_bytes),
            'rx_bps'               => round($rx_bps),
            'tx_bps'               => round($tx_bps),
            'upload_kbps'          => $ul_kbps,
            'download_kbps'        => $dl_kbps,
            'upload_mbps'          => $ul_mbps,
            'download_mbps'        => $dl_mbps,
            'upload_speed_label'   => fmt_bps($rx_bps),
            'download_speed_label' => fmt_bps($tx_bps),
            'customer'             => $cust ? [
                'id'          => (int) $cust['id'],
                'username'    => $cust['username'],
                'fullname'    => $cust['fullname'] ?: $cust['username'],
                'phonenumber' => $cust['phonenumber'] ?: '-',
                'email'       => $cust['email'] ?: '-',
                'address'     => $cust['address'] ?: '-',
                'pppoe_ip'    => $cust['pppoe_ip'] ?? '',
                'coordinates' => $cust['coordinates'] ?? '',
                'created_at'  => $cust['created_at'] ?? '-',
                'last_login'  => $cust['last_login'] ?? '-'
            ] : null,
            'active_package' => $rech ? [
                'id'             => (int) $rech['id'],
                'plan_id'        => (int) $rech['plan_id'],
                'namebp'         => $rech['namebp'],
                'status'         => $rech['status'],
                'expiration'     => $rech['expiration'],
                'time'           => $rech['time'],
                'routers'        => $rech['routers'],
                'type'           => $rech['type'],
                'bandwidth_name' => $bw['name_bw'] ?? '-',
                'rate_label'     => $rate_label,
                'validity_label' => $validity_label
            ] : null,
            'custom_fields'       => $custom_fields,
            'recent_transactions' => $recent_trx
        ]);
        break;

    // =========================================================================
    // 6. KICK SESI PENGGUNA AKTIF & REBOOT ROUTEROS
    // =========================================================================
    case 'kick_user':
    case 'admin_kick_user':
        $admin        = require_admin_session($api_secret);
        $router_param = trim((string) (_post('router') ?: _post('router_id')));
        $r            = is_numeric($router_param)
            ? ORM::for_table('tbl_routers')->find_one((int) $router_param)
            : ORM::for_table('tbl_routers')->where('name', $router_param)->find_one();

        if (!$r) {
            api_out(false, 'Router tidak ditemukan.', null, 404);
        }
        $client = connect_ros($r['ip_address'], $r['username'], $r['password']);
        if (!$client) {
            api_out(false, 'Gagal terhubung ke Router.', null, 500);
        }

        $service = strtolower(trim((string) (_post('service') ?: _post('userType') ?: 'hotspot')));
        $ros_id  = trim((string) _post('ros_id'));
        $uname   = trim((string) _post('username'));

        try {
            if ($service === 'hotspot' && class_exists('Mikrotik') && method_exists('Mikrotik', 'removeHotspotActiveUser') && $uname !== '') {
                @Mikrotik::removeHotspotActiveUser($client, $uname);
            } elseif ($service === 'pppoe' && class_exists('Mikrotik') && method_exists('Mikrotik', 'removePpoeActive') && $uname !== '') {
                @Mikrotik::removePpoeActive($client, $uname);
            } else {
                $path = ($service === 'pppoe') ? '/ppp/active' : '/ip/hotspot/active';
                $prop = ($service === 'pppoe') ? 'name' : 'user';
                if ($ros_id !== '') {
                    $rem = new PEAR2\Net\RouterOS\Request($path . '/remove');
                    $rem->setArgument('numbers', $ros_id);
                    $client->sendSync($rem);
                } elseif ($uname !== '') {
                    $find_req = new PEAR2\Net\RouterOS\Request($path . '/print');
                    $find_req->setQuery(PEAR2\Net\RouterOS\Query::where($prop, $uname));
                    foreach ($client->sendSync($find_req) as $item) {
                        if ($item->getType() === PEAR2\Net\RouterOS\Response::TYPE_DATA) {
                            $rem = new PEAR2\Net\RouterOS\Request($path . '/remove');
                            $rem->setArgument('numbers', $item->getProperty('.id'));
                            $client->sendSync($rem);
                        }
                    }
                }
            }
            write_admin_log($admin, 'Memutus sesi ' . strtoupper($service) . ' user ' . ($uname ?: $ros_id) . ' di ' . $r['name']);
            api_out(true, 'Sesi ' . ($uname ?: $ros_id) . ' berhasil diputus dari ' . $r['name'] . '.');
        } catch (Throwable $e) {
            api_out(false, 'Gagal memutus sesi: ' . $e->getMessage(), null, 500);
        }
        break;

    case 'router_reboot':
    case 'admin_router_reboot':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $r = ORM::for_table('tbl_routers')->find_one((int) _req('id'));
        if (!$r) {
            api_out(false, 'Router tidak ditemukan.', null, 404);
        }
        $client = connect_ros($r['ip_address'], $r['username'], $r['password']);
        if (!$client) {
            api_out(false, 'Gagal terhubung ke Router.', null, 500);
        }
        try {
            @$client->sendSync(new PEAR2\Net\RouterOS\Request('/system/reboot'));
        } catch (Throwable $e) {
        }
        write_admin_log($admin, 'Mengirim perintah Reboot ke Router ' . $r['name']);
        api_out(true, 'Perintah Reboot dikirim ke Router ' . $r['name'] . '.');
        break;
}
