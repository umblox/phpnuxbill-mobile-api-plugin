<?php
/**
 * PHPNuxBill Mobile API Plugin - Customers Management Module
 * Path: system/plugin/mobile_api_modules/core_customers.php
 */

switch ($action) {
    case 'admin_customers':
        $admin = require_admin_session($api_secret);
        $search = trim(_req('search'));
        $service_type = trim(_req('service_type'));
        $limit = max(10, min(300, (int)(_req('limit') ?: 150)));

        $q = ORM::for_table('tbl_customers');
        if ($search !== '') {
            $like = '%' . $search . '%';
            $q->where_raw('(username LIKE ? OR fullname LIKE ? OR phonenumber LIKE ? OR email LIKE ? OR pppoe_username LIKE ? OR address LIKE ? OR city LIKE ?)', [$like, $like, $like, $like, $like, $like, $like]);
        }
        if ($service_type !== '' && strtolower($service_type) !== 'all') {
            $q->where('service_type', $service_type);
        }

        $total_q = clone $q;
        $total = $total_q->count();
        $rows = $q->order_by_desc('id')->limit($limit)->find_array();

        $cids = array_column($rows, 'id');
        $rech_map = [];
        if (!empty($cids)) {
            foreach (ORM::for_table('tbl_user_recharges')->where_in('customer_id', $cids)->order_by_desc('id')->find_array() as $r) {
                $cid = (int)$r['customer_id'];
                if (!isset($rech_map[$cid]) || $r['status'] === 'on') {
                    $rech_map[$cid] = $r;
                }
            }
        }

        $customers = [];
        foreach ($rows as $row) {
            $cid = (int)$row['id'];
            $rech = $rech_map[$cid] ?? null;
            $customers[] = [
                'id'             => $cid,
                'username'       => $row['username'] ?: '',
                'fullname'       => $row['fullname'] ?: $row['username'],
                'phonenumber'    => $row['phonenumber'] ?: '',
                'email'          => $row['email'] ?: '',
                'address'        => $row['address'] ?: '',
                'city'           => $row['city'] ?? '',
                'balance'        => (float)$row['balance'],
                'service_type'   => $row['service_type'] ?: 'Hotspot',
                'account_type'   => $row['account_type'] ?? 'Personal',
                'status'         => $row['status'] ?: 'Active',
                'auto_renewal'   => (int)$row['auto_renewal'],
                'pppoe_username' => $row['pppoe_username'] ?? '',
                'pppoe_password' => $row['pppoe_password'] ?? '',
                'pppoe_ip'       => $row['pppoe_ip'] ?? '',
                'coordinates'    => $row['coordinates'] ?? '',
                'active_package' => $rech ? [
                    'id'         => (int)$rech['id'],
                    'plan_id'    => (int)$rech['plan_id'],
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
            'total'     => (int)$total,
            'customers' => $customers
        ]);
        break;

    case 'admin_customer_save':
        global $zero, $_app_stage;
        $admin = require_admin_session($api_secret);
        $id = (int)_post('id');

        $raw_username   = trim(_post('username'));
        $username       = function_exists('alphanumeric') ? alphanumeric($raw_username, ":+_.@-") : $raw_username;
        $fullname       = trim(_post('fullname'));
        $password       = trim(_post('password'));
        $pppoe_username = trim(_post('pppoe_username'));
        $pppoe_password = trim(_post('pppoe_password'));
        $pppoe_ip       = trim(_post('pppoe_ip'));
        $email          = trim(_post('email'));
        $address        = trim(_post('address'));
        $city           = trim(_post('city'));
        $phonenumber    = trim(_post('phonenumber'));
        $service_type   = _post('service_type') ?: 'Hotspot';
        $account_type   = _post('account_type') ?: 'Personal';
        $status         = _post('status') ?: 'Active';
        $coordinates    = trim(_post('coordinates'));

        $ppln  = (int)(_post('ppln') ?: _post('plan_id'));
        $using = trim(_post('using') ?: 'Cash');
        $zero  = 0;
        $GLOBALS['zero'] = 0;

        if ($id === 0 && function_exists('run_hook')) {
            @run_hook('add_customer');
        } elseif ($id > 0 && function_exists('run_hook')) {
            @run_hook('edit_customer');
        }

        $errors = [];
        $u_len = strlen($username);
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
        } elseif (!empty($password)) {
            $p_len = strlen($password);
            if ($p_len < 3 || $p_len > 35) {
                $errors[] = 'Password baru harus terdiri dari 3 hingga 35 karakter.';
            }
        }

        if ($id > 0) {
            $cust = ORM::for_table('tbl_customers')->find_one($id);
            if (!$cust) api_out(false, 'Pelanggan tidak ditemukan.', null, 404);
            $dup = ORM::for_table('tbl_customers')->where('username', $username)->where_not_equal('id', $id)->find_one();
            if ($dup) $errors[] = 'Username sudah digunakan oleh pelanggan lain.';
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
                $u_list = implode(', ', array_column($used_u, 'username'));
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
                $ip_list = implode(', ', array_column($used_ip, 'username'));
                $errors[] = (class_exists('Lang') ? Lang::T('IP has been used by') : 'Remote IP PPPoE sudah dipakai oleh') . ' : ' . $ip_list;
            }
        }

        if (!empty($errors)) {
            api_out(false, implode("\n", $errors), null, 400);
        }

        if ($id === 0) {
            $cust = ORM::for_table('tbl_customers')->create();
            $cust->created_by = $admin['id'];
            $cust->created_at = date('Y-m-d H:i:s');
            $cust->balance    = (float)(_post('balance') ?: 0);
        }

        if (!empty($phonenumber) && class_exists('Lang') && method_exists('Lang', 'phoneFormat')) {
            $phonenumber = Lang::phoneFormat($phonenumber);
        }

        $cust->username       = $username;
        $cust->fullname       = $fullname;
        if (!empty($password)) {
            $cust->password = $password;
        } elseif ($id === 0) {
            $cust->password = '123456';
        }
        $cust->phonenumber    = $phonenumber;
        $cust->email          = $email;
        $cust->address        = $address;
        $cust->city           = $city;
        $cust->service_type   = $service_type;
        $cust->account_type   = $account_type;
        $cust->status         = $status;
        $cust->auto_renewal   = (int)(_post('auto_renewal') ?? 1);
        $cust->pppoe_username = $pppoe_username;
        $cust->pppoe_password = $pppoe_password;
        $cust->pppoe_ip       = $pppoe_ip;
        $cust->coordinates    = $coordinates;
        $cust->save();

        $customerId = (int)$cust->id();

        // Jika Edit Pelanggan dan ada paket berstatus ON, sinkronkan perubahan password/PPPoE ke MikroTik (Sesuai accounts.php)
        if ($id > 0 && $ppln <= 0 && class_exists('Package') && method_exists('Package', 'getDevice')) {
            $active_turs = ORM::for_table('tbl_user_recharges')->where('customer_id', $customerId)->where('status', 'on')->find_many();
            foreach ($active_turs as $tur) {
                $p_active = ORM::for_table('tbl_plans')->where('id', $tur['plan_id'])->find_one();
                if ($p_active) {
                    $dvc_file = Package::getDevice($p_active);
                    if (($_app_stage ?? '') !== 'demo' && file_exists($dvc_file)) {
                        require_once $dvc_file;
                        $dev_cls = $p_active['device'];
                        if (class_exists($dev_cls)) {
                            try { (new $dev_cls())->add_customer($cust, $p_active); } catch (Exception $e) {}
                        }
                    }
                }
            }
        }

        $custom_field_names  = isset($_POST['custom_field_name']) ? (array)$_POST['custom_field_name'] : [];
        $custom_field_values = isset($_POST['custom_field_value']) ? (array)$_POST['custom_field_value'] : [];
        if (!empty($custom_field_values)) {
            $totalFields = min(count($custom_field_names), count($custom_field_values));
            for ($i = 0; $i < $totalFields; $i++) {
                $cf_name  = trim((string)$custom_field_names[$i]);
                $cf_value = trim((string)$custom_field_values[$i]);
                if (!empty($cf_name) && !empty($cf_value)) {
                    try {
                        $customField = ORM::for_table('tbl_customers_fields')->create();
                        $customField->customer_id = $customerId;
                        $customField->field_name  = $cf_name;
                        $customField->field_value = $cf_value;
                        $customField->save();
                    } catch (Exception $e) {}
                }
            }
        }

        $recharged_plan_name = '';
        if ($ppln > 0) {
            $plan_q = ORM::for_table('tbl_plans');
            if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
                $plan_q->where('enabled', 1);
            }
            $plan = $plan_q->find_one($ppln);

            if ($plan) {
                $gateway = $using;
                if ($using === 'Recharge Zero' || strtolower($using) === 'zero') {
                    $zero = 1;
                    $GLOBALS['zero'] = 1;
                    $gateway = 'Recharge Zero';
                } elseif (strtolower($using) === 'balance') {
                    $gateway = 'Recharge Balance';
                }

                $server = ($plan['is_radius'] == '1') ? 'Radius' : $plan['routers'];
                $admin_name = $admin['fullname'] ?: $admin['username'];

                if (class_exists('Package') && method_exists('Package', 'rechargeUser')) {
                    if (@Package::rechargeUser($customerId, $server, $plan['id'], $gateway, $admin_name)) {
                        $recharged_plan_name = $plan['name_plan'];
                        if (class_exists('User') && method_exists('User', 'getBills')) {
                            @User::getBills($customerId);
                        }
                    }
                }
            }
        }

        $send_welcome = _post('send_welcome_message');
        if ($send_welcome === true || $send_welcome === 'true' || $send_welcome === '1' || $send_welcome === 1) {
            $welcomeMessage = '';
            if (class_exists('Lang') && method_exists('Lang', 'getNotifText')) {
                $welcomeMessage = @Lang::getNotifText('welcome_message');
            }
            if (empty(trim($welcomeMessage))) {
                $welcomeMessage = "Halo *[[name]]*,\n\nSelamat datang di layanan internet *[[company]]*.\nBerikut informasi akun Anda:\nUsername: *[[username]]*\nPassword: *[[password]]*\nLogin Portal: [[url]]\n\nTerima kasih telah bergabung!";
            }

            $clean_root_url = preg_replace('#/system/?$#i', '', rtrim(APP_URL, '/'));
            $comp_title = $config['CompanyName'] ?? 'Arneta.ID';

            $welcomeMessage = str_replace('[[company]]', $comp_title, $welcomeMessage);
            $welcomeMessage = str_replace('[[name]]', $cust['fullname'], $welcomeMessage);
            $welcomeMessage = str_replace('[[username]]', $cust['username'], $welcomeMessage);
            $welcomeMessage = str_replace('[[password]]', $cust['password'], $welcomeMessage);
            $welcomeMessage = str_replace('[[url]]', $clean_root_url . '/?_route=login', $welcomeMessage);

            $emailSubject = 'Welcome to ' . $comp_title;

            $enable_wa   = (_post('wa') === true || _post('wa') === 'true' || _post('wa') === '1' || _post('wa') === 1);
            $enable_sms  = (_post('sms') === true || _post('sms') === 'true' || _post('sms') === '1' || _post('sms') === 1);
            $enable_mail = (_post('mail') === true || _post('mail') === 'true' || _post('mail') === '1' || _post('mail') === 1);

            if ($enable_wa && !empty($cust['phonenumber'])) {
                notify_whatsapp_customer($cust['phonenumber'], $welcomeMessage);
            }
            if ($enable_sms && !empty($cust['phonenumber'])) {
                notify_sms_customer($cust['phonenumber'], $welcomeMessage);
            }
            if ($enable_mail && !empty($cust['email']) && class_exists('Message') && method_exists('Message', 'sendEmail')) {
                try {
                    @Message::sendEmail($cust['email'], $emailSubject, $welcomeMessage, $cust['email']);
                } catch (Exception $e) {
                    write_admin_log($admin, 'Gagal mengirim email welcome message: ' . $e->getMessage());
                }
            }
        }

        unset($zero, $GLOBALS['zero'], $_SESSION['notify'], $_SESSION['ntype']);

        $action_label = ($id > 0 ? 'Mengubah' : 'Menambah (QuickAdd)') . ' pelanggan: ' . $username
            . (!empty($recharged_plan_name) ? ' + Paket ' . $recharged_plan_name . ' (' . $using . ')' : '');
        write_admin_log($admin, $action_label);

        $success_msg = ($id === 0)
            ? ('Akun ' . $fullname . ' berhasil dibuat' . (!empty($recharged_plan_name) ? ' & paket ' . $recharged_plan_name . ' telah aktif!' : '!'))
            : ('Data pelanggan ' . $fullname . ' berhasil diperbarui.');

        $cust_out = $cust->as_array();
        if (!empty($recharged_plan_name)) {
            $in_last = ORM::for_table('tbl_transactions')->where('username', $cust['username'])->order_by_desc('id')->find_one();
            if ($in_last) {
                $cust_out['receipt'] = build_web_invoice_receipt($in_last->as_array(), $cust_out, $config);
            }
        }

        api_out(true, $success_msg, $cust_out);
        break;

    case 'admin_customer_delete':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $id = (int)_req('id');
        $cust = ORM::for_table('tbl_customers')->find_one($id);
        if (!$cust) api_out(false, 'Pelanggan tidak ditemukan.', null, 404);
        $uname = $cust['username'];
        ORM::for_table('tbl_user_recharges')->where('customer_id', $id)->delete_many();
        try { ORM::for_table('tbl_customers_fields')->where('customer_id', $id)->delete_many(); } catch (Exception $e) {}
        $cust->delete();
        write_admin_log($admin, 'Menghapus pelanggan: ' . $uname);
        api_out(true, 'Pelanggan ' . $uname . ' berhasil dihapus.');
        break;

    case 'admin_customer_sync':
        $admin = require_admin_session($api_secret);
        $id = (int)_req('id');
        if ($id === 0 && _req('sync_all') == '1') {
            if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
                api_out(false, 'Akses ditolak.', null, 403);
            }
            @set_time_limit(-1);
            $turs = ORM::for_table('tbl_user_recharges')->where('status', 'on')->find_many();
            $synced = 0;
            foreach ($turs as $tur) {
                $p = ORM::for_table('tbl_plans')->find_one($tur['plan_id']);
                $c = ORM::for_table('tbl_customers')->find_one($tur['customer_id']);
                if ($p && $c && class_exists('Package')) {
                    $dvc = Package::getDevice($p);
                    if (file_exists($dvc)) {
                        require_once $dvc;
                        $cls = $p['device'];
                        if (class_exists($cls)) {
                            $inst = new $cls();
                            if (method_exists($inst, 'sync_customer')) @$inst->sync_customer($c, $p);
                            elseif (method_exists($inst, 'add_customer')) @$inst->add_customer($c, $p);
                            $synced++;
                        }
                    }
                }
            }
            api_out(true, 'Berhasil sinkronisasi ' . $synced . ' pelanggan aktif ke Router.');
        }

        $cust = ORM::for_table('tbl_customers')->find_one($id);
        if (!$cust) api_out(false, 'Pelanggan tidak ditemukan.', null, 404);

        $recharges = ORM::for_table('tbl_user_recharges')->where('customer_id', $id)->where('status', 'on')->find_many();
        $synced = 0;
        foreach ($recharges as $r) {
            $plan = ORM::for_table('tbl_plans')->find_one($r['plan_id']);
            if ($plan && class_exists('Package') && method_exists('Package', 'getDevice')) {
                $dev_file = Package::getDevice($plan);
                if (file_exists($dev_file)) {
                    require_once $dev_file;
                    $d_cls = $plan['device'];
                    if (class_exists($d_cls)) {
                        $dev = new $d_cls();
                        if (method_exists($dev, 'sync_customer')) @$dev->sync_customer($cust, $plan);
                        elseif (method_exists($dev, 'add_customer')) @$dev->add_customer($cust, $plan);
                        $synced++;
                    }
                }
            }
        }
        api_out(true, $synced > 0 ? 'Berhasil sinkronisasi ' . $synced . ' paket ke Router.' : 'Pelanggan belum memiliki paket berstatus ON.');
        break;
}
