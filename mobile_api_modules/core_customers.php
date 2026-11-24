<?php

/**
 * PHPNuxBill Mobile API Plugin - Customers Management Module
 * Path: system/plugin/mobile_api_modules/core_customers.php
 **/

global $config, $_c, $admin, $ui, $UPLOAD_PATH, $DEVICE_PATH, $zero, $_app_stage, $isChangePlan, $api_secret;

switch ($action) {
    // =========================================================================
    // 1. DAFTAR PELANGGAN (DENGAN FILTER, CUSTOM FIELDS & PAKET AKTIF)
    // =========================================================================
    case 'admin_customers':
        $admin        = require_admin_session($api_secret);
        $search       = trim((string) _req('search'));
        $service_type = trim((string) _req('service_type'));
        $status_flt   = trim((string) _req('status'));
        $limit        = max(10, min(300, (int) (_req('limit') ?: 150)));

        $q = ORM::for_table('tbl_customers');
        if ($search !== '') {
            $like = '%' . $search . '%';
            $q->where_raw(
                '(username LIKE ? OR fullname LIKE ? OR phonenumber LIKE ? OR email LIKE ? OR pppoe_username LIKE ? OR address LIKE ? OR city LIKE ?)',
                [$like, $like, $like, $like, $like, $like, $like]
            );
        }
        if ($service_type !== '' && strtolower($service_type) !== 'all') {
            $q->where('service_type', $service_type);
        }
        if ($status_flt !== '' && strtolower($status_flt) !== 'all') {
            $q->where('status', $status_flt);
        }

        $total_q = clone $q;
        $total   = $total_q->count();
        $rows    = $q->order_by_desc('id')->limit($limit)->find_array();

        $cids     = array_column($rows, 'id');
        $rech_map = [];
        $cf_map   = [];
        if (!empty($cids)) {
            foreach (ORM::for_table('tbl_user_recharges')->where_in('customer_id', $cids)->order_by_desc('id')->find_array() as $r) {
                $cid = (int) $r['customer_id'];
                if (!isset($rech_map[$cid]) || $r['status'] === 'on') {
                    $rech_map[$cid] = $r;
                }
            }
            try {
                foreach (ORM::for_table('tbl_customers_fields')->where_in('customer_id', $cids)->find_array() as $cf) {
                    $cid            = (int) $cf['customer_id'];
                    $cf_map[$cid][] = [
                        'name'  => $cf['field_name'] ?? '',
                        'value' => $cf['field_value'] ?? ''
                    ];
                }
            } catch (Throwable $e) {
            }
        }

        $customers = [];
        foreach ($rows as $row) {
            $cid         = (int) $row['id'];
            $rech        = $rech_map[$cid] ?? null;
            $customers[] = [
                'id'             => $cid,
                'username'       => $row['username'] ?: '',
                'fullname'       => $row['fullname'] ?: $row['username'],
                'phonenumber'    => $row['phonenumber'] ?: '',
                'email'          => $row['email'] ?: '',
                'address'        => $row['address'] ?: '',
                'city'           => $row['city'] ?: '',
                'district'       => $row['district'] ?? '',
                'state'          => $row['state'] ?? '',
                'zip'            => $row['zip'] ?? '',
                'balance'        => (float) $row['balance'],
                'service_type'   => $row['service_type'] ?: 'Hotspot',
                'account_type'   => $row['account_type'] ?? 'Personal',
                'status'         => $row['status'] ?: 'Active',
                'auto_renewal'   => (int) ($row['auto_renewal'] ?? 1),
                'pppoe_username' => $row['pppoe_username'] ?? '',
                'pppoe_password' => $row['pppoe_password'] ?? '',
                'pppoe_ip'       => $row['pppoe_ip'] ?? '',
                'coordinates'    => $row['coordinates'] ?? '',
                'custom_fields'  => $cf_map[$cid] ?? [],
                'active_package' => $rech ? [
                    'id'         => (int) $rech['id'],
                    'plan_id'    => (int) $rech['plan_id'],
                    'namebp'     => $rech['namebp'],
                    'status'     => $rech['status'],
                    'expiration' => $rech['expiration'],
                    'time'       => $rech['time'],
                    'routers'    => $rech['routers'],
                    'type'       => $rech['type']
                ] : null
            ];
        }

        api_out(true, 'Daftar pelanggan berhasil dimuat', [
            'total'     => (int) $total,
            'customers' => $customers
        ]);
        break;

    // =========================================================================
    // 2. TAMBAH / UBAH PELANGGAN (SELARAS DENGAN customers.php & sync router)
    // =========================================================================
    case 'admin_customer_save':
        $admin = require_admin_session($api_secret);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }
        @set_time_limit(60);

        $id           = (int) _post('id');
        $raw_username = trim((string) _post('username'));
        $username     = (class_exists('Text') && method_exists('Text', 'alphanumeric'))
            ? Text::alphanumeric($raw_username, ":+_.@-")
            : (function_exists('alphanumeric') ? alphanumeric($raw_username, ":+_.@-") : $raw_username);

        $fullname       = trim((string) _post('fullname'));
        $password       = trim((string) _post('password'));
        $pppoe_username = trim((string) _post('pppoe_username'));
        $pppoe_password = trim((string) _post('pppoe_password'));
        $pppoe_ip       = trim((string) _post('pppoe_ip'));
        $email          = trim((string) _post('email'));
        $address        = trim((string) _post('address'));
        $city           = trim((string) _post('city'));
        $district       = trim((string) _post('district'));
        $state          = trim((string) _post('state'));
        $zip            = trim((string) _post('zip'));
        $phonenumber    = trim((string) _post('phonenumber'));
        $service_type   = trim((string) (_post('service_type') ?: 'Hotspot'));
        $account_type   = trim((string) (_post('account_type') ?: 'Personal'));
        $status         = trim((string) (_post('status') ?: 'Active'));
        $coordinates    = trim((string) _post('coordinates'));

        $ppln            = (int) (_post('ppln') ?: _post('plan_id'));
        $using           = trim((string) (_post('using') ?: 'Cash'));
        $zero            = 0;
        $GLOBALS['zero'] = 0;

        if ($id === 0 && function_exists('run_hook')) {
            try {
                @run_hook('add_customer');
            } catch (Throwable $e) {
            }
        } elseif ($id > 0 && function_exists('run_hook')) {
            try {
                @run_hook('edit_customer');
            } catch (Throwable $e) {
            }
        }

        $errors = [];
        $u_len  = strlen($username);
        if ($u_len < 3 || $u_len > 54) {
            $errors[] = 'Username harus terdiri dari 3 hingga 54 karakter.';
        }
        $f_len = strlen($fullname);
        if ($f_len < 2 || $f_len > 60) {
            $errors[] = 'Nama Lengkap harus terdiri dari 2 hingga 60 karakter.';
        }
        if ($id === 0) {
            if ($ppln <= 0) {
                $errors[] = 'Pilih Paket Internet terlebih dahulu!';
            }
            $p_len = strlen($password);
            if ($p_len < 3 || $p_len > 35) {
                $errors[] = 'Password harus terdiri dari 3 hingga 35 karakter.';
            }
        } elseif ($password !== '') {
            $p_len = strlen($password);
            if ($p_len < 3 || $p_len > 35) {
                $errors[] = 'Password baru harus terdiri dari 3 hingga 35 karakter.';
            }
        }

        $old_username       = '';
        $old_pppoe_username = '';
        $old_pppoe_password = '';
        $old_pppoe_ip       = '';
        $old_password       = '';

        if ($id > 0) {
            if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'], true)) {
                api_out(false, 'Hanya SuperAdmin atau Admin yang dapat mengubah data pelanggan.', null, 403);
            }
            $cust = ORM::for_table('tbl_customers')->find_one($id);
            if (!$cust) {
                api_out(false, 'Pelanggan tidak ditemukan.', null, 404);
            }
            $old_username       = (string) ($cust['username'] ?? '');
            $old_pppoe_username = (string) ($cust['pppoe_username'] ?? '');
            $old_pppoe_password = (string) ($cust['pppoe_password'] ?? '');
            $old_pppoe_ip       = (string) ($cust['pppoe_ip'] ?? '');
            $old_password       = (string) ($cust['password'] ?? '');

            $dup = ORM::for_table('tbl_customers')->where('username', $username)->where_not_equal('id', $id)->find_one();
            if ($dup) {
                $errors[] = 'Username sudah digunakan oleh pelanggan lain.';
            }
        } else {
            $dup = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
            if ($dup) {
                $errors[] = class_exists('Lang') ? Lang::T('Account already axist') : 'Username sudah terdaftar.';
            }
        }

        // Validasi duplikasi PPPoE Username & PPPoE IP sesuai system/controllers/autoload.php
        if ($pppoe_username !== '') {
            $used_u = ORM::for_table('tbl_customers')
                ->select('username')
                ->where_not_equal('id', $id)
                ->where('pppoe_username', $pppoe_username)
                ->find_array();
            if (count($used_u) > 0) {
                $u_list   = implode(', ', array_column($used_u, 'username'));
                $errors[] = (class_exists('Lang') ? Lang::T('Username has been used by') : 'PPPoE Username sudah dipakai oleh') . ' : ' . $u_list;
            }
        }
        if ($pppoe_ip !== '') {
            $used_ip = ORM::for_table('tbl_customers')
                ->select('username')
                ->where_not_equal('id', $id)
                ->where('pppoe_ip', $pppoe_ip)
                ->find_array();
            if (count($used_ip) > 0) {
                $ip_list  = implode(', ', array_column($used_ip, 'username'));
                $errors[] = (class_exists('Lang') ? Lang::T('IP has been used by') : 'Remote IP PPPoE sudah dipakai oleh') . ' : ' . $ip_list;
            }
        }

        if (!empty($errors)) {
            api_out(false, implode("\n", $errors), null, 400);
        }

        if ($id === 0) {
            $cust             = ORM::for_table('tbl_customers')->create();
            $cust->created_by = (int) $admin['id'];
            $cust->created_at = date('Y-m-d H:i:s');
            $cust->balance    = (float) (_post('balance') ?: 0);
        }

        if ($phonenumber !== '' && class_exists('Lang') && method_exists('Lang', 'phoneFormat')) {
            $phonenumber = Lang::phoneFormat($phonenumber);
        }

        $cust->username = $username;
        $cust->fullname = $fullname;
        if ($password !== '') {
            $cust->password = $password;
        } elseif ($id === 0) {
            $cust->password = '123456';
        }
        $cust->phonenumber    = $phonenumber;
        $cust->email          = $email;
        $cust->address        = $address;
        $cust->city           = $city;
        $cust->district       = $district;
        $cust->state          = $state;
        $cust->zip            = $zip;
        $cust->service_type   = $service_type;
        $cust->account_type   = $account_type;
        $cust->status         = $status;
        $cust->auto_renewal   = (int) (_post('auto_renewal') ?? ($cust['auto_renewal'] ?? 1));
        $cust->pppoe_username = $pppoe_username;
        $cust->pppoe_password = $pppoe_password;
        $cust->pppoe_ip       = $pppoe_ip;
        $cust->coordinates    = $coordinates;
        $cust->save();

        $customerId = (int) $cust->id();

        // Jika Edit Pelanggan dan ada paket aktif / perubahan kredensial, sinkronkan ke Router (sesuai customers.php)
        if ($id > 0) {
            $user_diff     = ($old_username !== '' && $old_username !== $username);
            $pppoe_diff    = ($old_pppoe_username !== $pppoe_username);
            $pppoe_ip_diff = ($old_pppoe_ip !== $pppoe_ip);
            $pass_diff     = ($password !== '' && $old_password !== $password) || ($old_pppoe_password !== $pppoe_password);

            if ($user_diff || $pppoe_diff || $pppoe_ip_diff || $pass_diff || $ppln <= 0) {
                $turs = ORM::for_table('tbl_user_recharges')->where('customer_id', $customerId)->find_many();
                foreach ($turs as $tur) {
                    if ($tur['status'] === 'on' && class_exists('Package') && method_exists('Package', 'getDevice')) {
                        $p_active = ORM::for_table('tbl_plans')->where('id', $tur['plan_id'])->find_one();
                        if ($p_active) {
                            $dvc_file = Package::getDevice($p_active);
                            if (file_exists($dvc_file)) {
                                require_once $dvc_file;
                                $dev_cls = $p_active['device'];
                                if (class_exists($dev_cls)) {
                                    try {
                                        $dev_inst = new $dev_cls();
                                        if (method_exists($dev_inst, 'change_username')) {
                                            if ($user_diff) {
                                                @$dev_inst->change_username($p_active, $old_username, $username);
                                            }
                                            if ($pppoe_diff && strtoupper((string) $tur['type']) === 'PPPOE') {
                                                if ($old_pppoe_username === '' && $pppoe_username !== '') {
                                                    @$dev_inst->change_username($p_active, $username, $pppoe_username);
                                                } elseif ($pppoe_username === '' && $old_pppoe_username !== '') {
                                                    @$dev_inst->change_username($p_active, $old_pppoe_username, $username);
                                                } elseif ($old_pppoe_username !== '') {
                                                    @$dev_inst->change_username($p_active, $old_pppoe_username, $pppoe_username);
                                                }
                                            }
                                        }
                                        @$dev_inst->add_customer($cust, $p_active);
                                    } catch (Throwable $e) {
                                    }
                                }
                            }
                        }
                    }
                    if ($user_diff) {
                        $tur->username = $username;
                        $tur->save();
                    }
                }
            }
        }

        // Simpan Custom Fields di tbl_customers_fields
        $custom_field_names  = isset($_POST['custom_field_name']) ? (array) $_POST['custom_field_name'] : [];
        $custom_field_values = isset($_POST['custom_field_value']) ? (array) $_POST['custom_field_value'] : [];
        if ($id > 0 && isset($_POST['custom_field_name'])) {
            try {
                ORM::for_table('tbl_customers_fields')->where('customer_id', $customerId)->delete_many();
            } catch (Throwable $e) {
            }
        }
        if (!empty($custom_field_values)) {
            $totalFields = min(count($custom_field_names), count($custom_field_values));
            for ($i = 0; $i < $totalFields; $i++) {
                $cf_name  = trim((string) $custom_field_names[$i]);
                $cf_value = trim((string) $custom_field_values[$i]);
                if ($cf_name !== '' && $cf_value !== '') {
                    try {
                        $customField              = ORM::for_table('tbl_customers_fields')->create();
                        $customField->customer_id = $customerId;
                        $customField->field_name  = $cf_name;
                        $customField->field_value = $cf_value;
                        $customField->save();
                    } catch (Throwable $e) {
                    }
                }
            }
        }

        // Kirim Welcome Message menggunakan template resmi notifications.json (Sesuai customers.php)
        $send_welcome = _post('send_welcome_message');
        if ($send_welcome === true || $send_welcome === 'true' || $send_welcome === '1' || $send_welcome === 1) {
            $welcomeMessage = get_official_notif_template('welcome_message');
            if (trim($welcomeMessage) !== '') {
                $clean_root_url = defined('APP_URL') ? preg_replace('#/system/?$#i', '', rtrim((string) APP_URL, '/')) : '';
                $comp_title     = $config['CompanyName'] ?? 'PHPNuxBill';
                $welcomeMessage = str_replace(
                    ['[[company]]', '[[company_name]]', '[[name]]', '[[username]]', '[[user_name]]', '[[password]]', '[[user_password]]', '[[url]]'],
                    [$comp_title, $comp_title, $cust['fullname'], $cust['username'], $cust['username'], $cust['password'], $cust['password'], $clean_root_url . '/?_route=login'],
                    $welcomeMessage
                );
                $emailSubject = 'Welcome to ' . $comp_title;

                $enable_wa    = (_post('wa') === true || _post('wa') === 'true' || _post('wa') === '1' || _post('wa') === 1);
                $enable_sms   = (_post('sms') === true || _post('sms') === 'true' || _post('sms') === '1' || _post('sms') === 1);
                $enable_mail  = (_post('mail') === true || _post('mail') === 'true' || _post('mail') === '1' || _post('mail') === 1);
                $enable_inbox = (_post('inbox') === true || _post('inbox') === 'true' || _post('inbox') === '1' || _post('inbox') === 1);

                if ($enable_wa && !empty($cust['phonenumber'])) {
                    notify_whatsapp_customer($cust['phonenumber'], $welcomeMessage, true);
                }
                if ($enable_sms && !empty($cust['phonenumber'])) {
                    notify_sms_customer($cust['phonenumber'], $welcomeMessage, true);
                }
                if ($enable_mail && !empty($cust['email']) && class_exists('Message') && method_exists('Message', 'sendEmail')) {
                    $c_email = $cust['email'];
                    queue_post_response_task(function () use ($c_email, $emailSubject, $welcomeMessage) {
                        @Message::sendEmail($c_email, $emailSubject, $welcomeMessage, $c_email);
                    });
                }
                if ($enable_inbox && class_exists('Message') && method_exists('Message', 'addToInbox')) {
                    try {
                        @Message::addToInbox($customerId, $emailSubject, $welcomeMessage, 'Admin');
                    } catch (Throwable $e) {
                    }
                }
            }
        }

        // Aktivasi paket langsung (Quick-Add / Edit + Paket) menggunakan alur resmi Package::rechargeUser
        $recharged_plan_name = '';
        if ($ppln > 0) {
            $cur_notif = strtolower(trim((string) ($config['user_notification_payment'] ?? '')));
            if ($cur_notif === '' || $cur_notif === 'none') {
                if (!empty($config['wa_url'])) {
                    $config['user_notification_payment']            = 'wa';
                    $_c['user_notification_payment']                = 'wa';
                    $GLOBALS['config']['user_notification_payment'] = 'wa';
                    $GLOBALS['_c']['user_notification_payment']     = 'wa';
                } elseif (!empty($config['sms_url'])) {
                    $config['user_notification_payment']            = 'sms';
                    $_c['user_notification_payment']                = 'sms';
                    $GLOBALS['config']['user_notification_payment'] = 'sms';
                    $GLOBALS['_c']['user_notification_payment']     = 'sms';
                } elseif (!empty($config['smtp_host'])) {
                    $config['user_notification_payment']            = 'email';
                    $_c['user_notification_payment']                = 'email';
                    $GLOBALS['config']['user_notification_payment'] = 'email';
                    $GLOBALS['_c']['user_notification_payment']     = 'email';
                }
            }

            $plan_q = ORM::for_table('tbl_plans');
            if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'], true)) {
                $plan_q->where('enabled', 1);
            }
            $plan = $plan_q->find_one($ppln);
            if ($plan) {
                $using_lower = strtolower($using);
                $gateway     = ucwords($using);
                if ($using_lower === 'recharge zero' || $using_lower === 'zero') {
                    $zero            = 1;
                    $GLOBALS['zero'] = 1;
                    $gateway         = 'Recharge Zero';
                } elseif ($using_lower === 'balance' || $using_lower === 'recharge balance') {
                    $gateway     = 'Recharge Balance';
                    $using_lower = 'balance';
                }

                $server     = ($plan['is_radius'] == '1') ? 'Radius' : $plan['routers'];
                $admin_name = $admin['fullname'] ?: $admin['username'];
                if (class_exists('Package') && method_exists('Package', 'rechargeUser')) {
                    $rech_ok = false;
                    @ob_start();
                    try {
                        $rech_ok = Package::rechargeUser($customerId, $server, $plan['id'], $gateway, $admin_name);
                    } catch (Throwable $e) {
                    }
                    @ob_end_clean();

                    if ($rech_ok) {
                        $recharged_plan_name = $plan['name_plan'];
                        if ($using_lower === 'balance') {
                            $cost_info = calculate_order_cost($customerId, $plan, $config);
                            if (class_exists('Balance') && method_exists('Balance', 'min')) {
                                Balance::min($customerId, $cost_info['total_cost']);
                            }
                        }
                    }
                }
            }
        }

        unset($zero, $GLOBALS['zero'], $_SESSION['notify'], $_SESSION['ntype']);

        $action_label = ($id > 0 ? 'Mengubah' : 'Menambah') . ' pelanggan: ' . $username
            . ($recharged_plan_name !== '' ? ' + Paket ' . $recharged_plan_name . ' (' . $using . ')' : '');
        write_admin_log($admin, $action_label);

        $success_msg = ($id === 0)
            ? ('Akun ' . $fullname . ' berhasil dibuat' . ($recharged_plan_name !== '' ? ' & paket ' . $recharged_plan_name . ' telah aktif!' : '!'))
            : ('Data pelanggan ' . $fullname . ' berhasil diperbarui.');

        $cust_fresh = ORM::for_table('tbl_customers')->find_one($customerId) ?: $cust;
        $cust_out   = $cust_fresh->as_array();
        unset($cust_out['password']);

        if ($recharged_plan_name !== '') {
            $in_last = ORM::for_table('tbl_transactions')->where('username', $cust['username'])->order_by_desc('id')->find_one();
            if ($in_last) {
                $cust_out['invoice'] = $in_last->as_array();
                $cust_out['receipt'] = build_web_invoice_receipt($in_last->as_array(), $cust_fresh->as_array(), $config);
            }
        }

        api_out(true, $success_msg, $cust_out);
        break;

    // =========================================================================
    // 3. HAPUS PELANGGAN (HAPUS DARI ROUTER, LANGGANAN, CUSTOM FIELDS & DB)
    // =========================================================================
    case 'admin_customer_delete':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $id   = (int) _req('id');
        $cust = ORM::for_table('tbl_customers')->find_one($id);
        if (!$cust) {
            api_out(false, 'Pelanggan tidak ditemukan.', null, 404);
        }

        $uname = $cust['username'];
        if (function_exists('run_hook')) {
            try {
                @run_hook('delete_customer');
            } catch (Throwable $e) {
            }
        }

        // Hapus akun dari perangkat MikroTik/Radius terlebih dahulu (sesuai customers.php)
        $turs = ORM::for_table('tbl_user_recharges')->where('customer_id', $id)->find_many();
        foreach ($turs as $tur) {
            $p = ORM::for_table('tbl_plans')->find_one($tur['plan_id']);
            if ($p && class_exists('Package') && method_exists('Package', 'getDevice')) {
                $dvc = Package::getDevice($p);
                if (file_exists($dvc)) {
                    require_once $dvc;
                    $cls = $p['device'];
                    if (class_exists($cls)) {
                        try {
                            $p['plan_expired'] = 0;
                            (new $cls())->remove_customer($cust, $p);
                        } catch (Throwable $e) {
                        }
                    }
                }
            }
            try {
                $tur->delete();
            } catch (Throwable $e) {
            }
        }

        try {
            ORM::for_table('tbl_customers_fields')->where('customer_id', $id)->delete_many();
        } catch (Throwable $e) {
        }
        $cust->delete();

        write_admin_log($admin, 'Menghapus pelanggan: ' . $uname);
        api_out(true, 'Pelanggan ' . $uname . ' berhasil dihapus.');
        break;

    // =========================================================================
    // 4. SINKRONISASI PELANGGAN KE MIKROTIK / RADIUS
    // =========================================================================
    case 'admin_customer_sync':
        $admin = require_admin_session($api_secret);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $id = (int) _req('id');
        if ($id === 0 && _req('sync_all') == '1') {
            if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'], true)) {
                api_out(false, 'Akses ditolak.', null, 403);
            }
            @set_time_limit(-1);
            $turs   = ORM::for_table('tbl_user_recharges')->where('status', 'on')->find_many();
            $synced = 0;
            foreach ($turs as $tur) {
                $p = ORM::for_table('tbl_plans')->find_one($tur['plan_id']);
                $c = ORM::for_table('tbl_customers')->find_one($tur['customer_id']);
                if ($p && $c && class_exists('Package') && method_exists('Package', 'getDevice')) {
                    $dvc = Package::getDevice($p);
                    if (file_exists($dvc)) {
                        require_once $dvc;
                        $cls = $p['device'];
                        if (class_exists($cls)) {
                            try {
                                $inst = new $cls();
                                if (method_exists($inst, 'sync_customer')) {
                                    @$inst->sync_customer($c, $p);
                                } elseif (method_exists($inst, 'add_customer')) {
                                    @$inst->add_customer($c, $p);
                                }
                                $synced++;
                            } catch (Throwable $e) {
                            }
                        }
                    }
                }
            }
            api_out(true, 'Berhasil sinkronisasi ' . $synced . ' pelanggan aktif ke Router.');
        }

        $cust = ORM::for_table('tbl_customers')->find_one($id);
        if (!$cust) {
            api_out(false, 'Pelanggan tidak ditemukan.', null, 404);
        }

        $recharges = ORM::for_table('tbl_user_recharges')->where('customer_id', $id)->where('status', 'on')->find_many();
        $synced    = 0;
        foreach ($recharges as $r) {
            $plan = ORM::for_table('tbl_plans')->find_one($r['plan_id']);
            if ($plan && class_exists('Package') && method_exists('Package', 'getDevice')) {
                $dev_file = Package::getDevice($plan);
                if (file_exists($dev_file)) {
                    require_once $dev_file;
                    $d_cls = $plan['device'];
                    if (class_exists($d_cls)) {
                        try {
                            $dev = new $d_cls();
                            if (method_exists($dev, 'sync_customer')) {
                                @$dev->sync_customer($cust, $plan);
                            } elseif (method_exists($dev, 'add_customer')) {
                                @$dev->add_customer($cust, $plan);
                            }
                            $synced++;
                        } catch (Throwable $e) {
                        }
                    }
                }
            }
        }

        api_out(true, $synced > 0 ? 'Berhasil sinkronisasi ' . $synced . ' paket ke Router.' : 'Pelanggan belum memiliki paket berstatus ON.');
        break;
}
