<?php
/**
 * PHPNuxBill Mobile API Plugin - Active Services, Recharge, Deposit & Vouchers Module
 * Path: system/plugin/mobile_api_modules/core_services_vouchers.php
 */

switch ($action) {
    // =========================================================================
    // LAYANAN: PELANGGAN AKTIF, ISI ULANG PAKET, ISI ULANG SALDO, VOUCHER
    // =========================================================================
    case 'admin_prepaid_list':
        $admin = require_admin_session($api_secret);
        $search = trim(_req('search'));
        $status = trim(_req('status'));
        $limit  = max(10, min(300, (int)(_req('limit') ?: 120)));

        $cust_map = [];
        foreach (ORM::for_table('tbl_customers')->find_array() as $c) {
            $cust_map[(int)$c['id']] = $c;
        }

        $plan_map = [];
        foreach (ORM::for_table('tbl_plans')->find_array() as $p) {
            $plan_map[(int)$p['id']] = (float)$p['price'];
        }

        $q = ORM::for_table('tbl_user_recharges');
        if ($status !== '' && strtolower($status) !== 'all') {
            $q->where('status', $status);
        }
        $rows = $q->order_by_desc('id')->limit($limit)->find_array();

        // Ambil seluruh transaksi terkait hanya dalam 1 Query (Bebas N+1 Query)
        $usernames = array_values(array_unique(array_filter(array_column($rows, 'username'))));
        $trx_exact_map  = [];
        $trx_latest_map = [];
        if (!empty($usernames)) {
            $trx_rows = ORM::for_table('tbl_transactions')
                ->where_in('username', $usernames)
                ->order_by_desc('id')
                ->find_array();
            foreach ($trx_rows as $tr) {
                $k_exact  = $tr['username'] . '|' . $tr['plan_name'] . '|' . $tr['recharged_on'];
                $k_latest = $tr['username'] . '|' . $tr['plan_name'];
                if (!isset($trx_exact_map[$k_exact])) {
                    $trx_exact_map[$k_exact] = $tr;
                }
                if (!isset($trx_latest_map[$k_latest])) {
                    $trx_latest_map[$k_latest] = $tr;
                }
            }
        }

        $items = [];
        $s_lower = strtolower($search);
        foreach ($rows as $r) {
            $c = $cust_map[(int)$r['customer_id']] ?? null;
            $r['fullname']       = $c['fullname'] ?? $r['username'];
            $r['phonenumber']    = $c['phonenumber'] ?? '';
            $r['email']          = $c['email'] ?? '';
            $r['address']        = $c['address'] ?? '';
            $r['city']           = $c['city'] ?? '';
            $r['balance']        = isset($c['balance']) ? (float)$c['balance'] : 0.0;
            $r['service_type']   = $c['service_type'] ?? ($r['type'] ?? 'Hotspot');
            $r['account_type']   = $c['account_type'] ?? 'Personal';
            $r['account_status'] = $c['status'] ?? 'Active';
            $r['auto_renewal']   = isset($c['auto_renewal']) ? (int)$c['auto_renewal'] : 1;
            $r['pppoe_username'] = $c['pppoe_username'] ?? '';
            $r['pppoe_password'] = $c['pppoe_password'] ?? '';
            $r['pppoe_ip']       = $c['pppoe_ip'] ?? '';
            $r['coordinates']    = $c['coordinates'] ?? '';
            $r['created_at']     = $c['created_at'] ?? '';
            $r['last_login']     = $c['last_login'] ?? '';

            if ($s_lower !== '') {
                $haystack = strtolower($r['username'] . ' ' . $r['fullname'] . ' ' . $r['namebp'] . ' ' . $r['routers'] . ' ' . $r['phonenumber']);
                if (strpos($haystack, $s_lower) === false) continue;
            }

            $k_exact  = $r['username'] . '|' . $r['namebp'] . '|' . $r['recharged_on'];
            $k_latest = $r['username'] . '|' . $r['namebp'];
            $trx = $trx_exact_map[$k_exact] ?? ($trx_latest_map[$k_latest] ?? null);

            $is_zero = (stripos($r['method'] ?? '', 'Recharge Zero') !== false || stripos($r['method'] ?? '', 'zero') !== false);
            $fallback_price = $is_zero ? 0.0 : ($plan_map[(int)$r['plan_id']] ?? 0.0);

            $r['price']   = $trx ? (float)$trx['price'] : $fallback_price;
            $r['invoice'] = ($trx && !empty($trx['invoice'])) ? $trx['invoice'] : ('INV-' . $r['id']);

            $items[] = $r;
        }

        api_out(true, 'Daftar pelanggan aktif dimuat', [
            'total' => count($items),
            'items' => $items
        ]);
        break;

    case 'admin_prepaid_recharge':
        // 100% Mengikuti alur asli system/controllers/plan.php (case 'recharge-post') & Package.php
        global $zero;
        $admin = require_admin_session($api_secret);
        $id_customer = (int)(_post('customer_id') ?: _post('id_customer'));
        $planId      = (int)(_post('plan_id') ?: _post('plan'));
        $using       = trim(_post('using') ?: 'Cash');
        $req_server  = trim(_post('server') ?: _post('routers'));

        if ($id_customer <= 0 || $planId <= 0) {
            api_out(false, class_exists('Lang') ? Lang::T('All field is required') : 'Pilih pelanggan dan paket terlebih dahulu.', null, 400);
        }

        $cust = (class_exists('User') && method_exists('User', '_info'))
            ? User::_info($id_customer)
            : ORM::for_table('tbl_customers')->find_one($id_customer);
        if (!$cust) {
            api_out(false, class_exists('Lang') ? Lang::T('Customer not found') : 'Pelanggan tidak ditemukan.', null, 404);
        }

        $plan = ORM::for_table('tbl_plans')->find_one($planId);
        if (!$plan) {
            api_out(false, class_exists('Lang') ? Lang::T('Plan not found') : 'Paket internet tidak ditemukan.', null, 404);
        }

        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin']) && (int)$plan['enabled'] !== 1) {
            api_out(false, 'Paket ini berstatus Hidden dan hanya dapat diakses oleh SuperAdmin/Admin.', null, 403);
        }

        $server = ($plan['is_radius'] == '1')
            ? 'Radius'
            : (!empty($plan['routers']) ? $plan['routers'] : (!empty($req_server) ? $req_server : 'radius'));

        $bills = [];
        $add_cost = 0;
        if (class_exists('User') && method_exists('User', 'getBills')) {
            list($bills, $add_cost) = User::getBills($id_customer);
        }

        $tax_enable = isset($config['enable_tax']) ? $config['enable_tax'] : 'no';
        $tax_rate_setting = isset($config['tax_rate']) ? $config['tax_rate'] : null;
        $custom_tax_rate = isset($config['custom_tax_rate']) ? (float)$config['custom_tax_rate'] : null;
        $tax_rate = ($tax_rate_setting === 'custom') ? $custom_tax_rate : $tax_rate_setting;
        $tax = ($tax_enable === 'yes' && class_exists('Package') && method_exists('Package', 'tax'))
            ? Package::tax($plan['price'], $tax_rate)
            : 0;

        $total_cost = (float)$plan['price'] + (float)$add_cost + (float)$tax;

        $gateway = ucwords($using);
        $channel = $admin['fullname'] ?: $admin['username'];
        $zero = 0;
        $GLOBALS['zero'] = 0;

        $using_lower = strtolower($using);
        if (($using_lower === 'balance' || $using_lower === 'recharge balance') && ($config['enable_balance'] ?? 'yes') === 'yes') {
            if ((float)$cust['balance'] < $total_cost) {
                api_out(false, class_exists('Lang') ? Lang::T('insufficient balance') : 'Saldo pelanggan tidak mencukupi (Butuh ' . number_format($total_cost, 0, ',', '.') . ').', null, 400);
            }
            $gateway = 'Recharge Balance';
            $using_lower = 'balance';
        } elseif ($using_lower === 'zero' || $using_lower === 'recharge zero') {
            $add_cost = 0;
            $zero = 1;
            $GLOBALS['zero'] = 1;
            $gateway = 'Recharge Zero';
        }

        $ok = false;
        if (class_exists('Package') && method_exists('Package', 'rechargeUser')) {
            $ok = @Package::rechargeUser($id_customer, $server, $planId, $gateway, $channel);
        }
        unset($zero, $GLOBALS['zero']);

        if ($ok) {
            if ($using_lower === 'balance') {
                if (class_exists('Balance') && method_exists('Balance', 'min')) {
                    Balance::min($cust['id'], $total_cost);
                } else {
                    $c_upd = ORM::for_table('tbl_customers')->find_one($cust['id']);
                    if ($c_upd) {
                        $c_upd->balance = max(0, (float)$c_upd['balance'] - $total_cost);
                        $c_upd->save();
                    }
                }
            }

            $in = ORM::for_table('tbl_transactions')->where('username', $cust['username'])->order_by_desc('id')->find_one();
            $price_fmt = (class_exists('Lang') && method_exists('Lang', 'moneyFormat'))
                ? Lang::moneyFormat($in ? $in['price'] : $plan['price'])
                : (($config['currency_code'] ?? 'Rp.') . ' ' . number_format((float)($in ? $in['price'] : $plan['price']), 0, ',', '.'));

            write_admin_log($admin, '[' . $admin['username'] . ']: Recharge ' . $cust['username'] . ' [' . ($in ? $in['plan_name'] : $plan['name_plan']) . '][' . $price_fmt . ']');
            unset($_SESSION['notify'], $_SESSION['ntype']);

            $cust_fresh = ORM::for_table('tbl_customers')->find_one($cust['id']) ?: $cust;
            $in_arr = $in ? $in->as_array() : [
                'id' => 0, 'invoice' => 'INV-' . time(), 'username' => $cust['username'],
                'plan_name' => $plan['name_plan'], 'price' => $plan['price'],
                'recharged_on' => date('Y-m-d'), 'recharged_time' => date('H:i:s'),
                'expiration' => date('Y-m-d'), 'time' => date('H:i:s'),
                'method' => $gateway . ' - ' . $channel, 'routers' => $server, 'type' => $plan['type']
            ];
            api_out(true, 'Paket ' . $plan['name_plan'] . ' berhasil diaktifkan untuk ' . $cust['fullname'] . '!', [
                'invoice' => $in_arr,
                'receipt' => build_web_invoice_receipt($in_arr, $cust_fresh, $config)
            ]);
        } else {
            $err = isset($_SESSION['notify']) ? trim(strip_tags($_SESSION['notify'])) : 'Gagal mengaktifkan paket ke Router ' . $server . '.';
            unset($_SESSION['notify'], $_SESSION['ntype']);
            api_out(false, $err, null, 500);
        }
        break;

    case 'admin_prepaid_delete':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $id = (int)_req('id');
        $d = ORM::for_table('tbl_user_recharges')->find_one($id);
        if (!$d) api_out(false, 'Data langganan tidak ditemukan.', null, 404);

        if (function_exists('run_hook')) {
            @run_hook('delete_customer_active_plan');
        }

        $p = ORM::for_table('tbl_plans')->find_one($d['plan_id']);
        $c = (class_exists('User') && method_exists('User', '_info'))
            ? User::_info($d['customer_id'])
            : ORM::for_table('tbl_customers')->find_one($d['customer_id']);

        if ($p && $c && class_exists('Package') && method_exists('Package', 'getDevice')) {
            $dvc = Package::getDevice($p);
            if (file_exists($dvc)) {
                require_once $dvc;
                $d_cls = $p['device'];
                if (class_exists($d_cls)) {
                    $dev = new $d_cls();
                    if (method_exists($dev, 'remove_customer')) @$dev->remove_customer($c, $p);
                }
            }
        }
        $uname = $d['username'];
        $pname = $d['namebp'];
        $d->delete();
        write_admin_log($admin, '[' . $admin['username'] . ']: Delete Plan for Customer ' . $uname . ' [' . $pname . ']');
        api_out(true, 'Paket pelanggan ' . $uname . ' berhasil dihapus.');
        break;

    case 'admin_prepaid_edit':
        global $_app_stage;
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $id         = (int)_post('id');
        $id_plan    = (int)_post('id_plan');
        $expiration = trim(_post('expiration'));
        $time       = trim(_post('time') ?: '23:59:59');

        $d = ORM::for_table('tbl_user_recharges')->find_one($id);
        if (!$d) {
            api_out(false, class_exists('Lang') ? Lang::T('Data Not Found') : 'Data pelanggan aktif tidak ditemukan.', null, 404);
        }
        $oldPlanID = (int)$d['plan_id'];
        $newPlan   = ORM::for_table('tbl_plans')->where('id', $id_plan)->find_one();
        if (!$newPlan) {
            api_out(false, 'Plan Not Found', null, 404);
        }

        if (function_exists('run_hook')) {
            @run_hook('edit_customer_plan');
        }

        if ($expiration !== '') $d->expiration = $expiration;
        if ($time !== '') $d->time = $time;

        if ($d['status'] === 'off' && strtotime($d->expiration . ' ' . $d->time) > time()) {
            $d->status = 'on';
        }

        if ($oldPlanID !== $id_plan) {
            $d->plan_id = $newPlan['id'];
            $d->namebp  = $newPlan['name_plan'];
            $customer   = ORM::for_table('tbl_customers')->find_one($d['customer_id']);

            if ($d['status'] === 'on' && $customer && class_exists('Package') && method_exists('Package', 'getDevice')) {
                $p_old = ORM::for_table('tbl_plans')->find_one($oldPlanID);
                if ($p_old) {
                    $dvc_old = Package::getDevice($p_old);
                    if (($_app_stage ?? '') !== 'demo' && file_exists($dvc_old)) {
                        require_once $dvc_old;
                        $p_old['plan_expired'] = 0;
                        $cls_old = $p_old['device'];
                        if (class_exists($cls_old)) {
                            try { (new $cls_old())->remove_customer($customer, $p_old); } catch (Exception $e) {}
                        }
                    }
                }
                $dvc_new = Package::getDevice($newPlan);
                if (($_app_stage ?? '') !== 'demo' && file_exists($dvc_new)) {
                    require_once $dvc_new;
                    $cls_new = $newPlan['device'];
                    if (class_exists($cls_new)) {
                        try { (new $cls_new())->add_customer($customer, $newPlan); } catch (Exception $e) {}
                    }
                }
            }
        }

        $d->save();
        write_admin_log($admin, "Edit Plan for Customer {$d['username']} to [{$d['namebp']}]");
        api_out(true, class_exists('Lang') ? Lang::T('Data Updated Successfully') : 'Paket pelanggan aktif berhasil diperbarui.', $d->as_array());
        break;

    case 'admin_prepaid_extend':
        global $_app_stage, $isChangePlan;
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin', 'Agent']);
        $id   = (int)_req('id');
        $days = max(1, (int)(_req('days') ?: 3));

        $tur = ORM::for_table('tbl_user_recharges')->find_one($id);
        if (!$tur) {
            api_out(false, 'Data langganan tidak ditemukan.', null, 404);
        }
        if ($tur['status'] !== 'off') {
            api_out(false, 'Customer is not expired yet (Status paket masih aktif).', null, 400);
        }

        if (strtotime($tur['expiration'] . ' ' . $tur['time']) > time()) {
            $expiration = date('Y-m-d', strtotime($tur['expiration'] . " +$days day"));
        } else {
            $expiration = date('Y-m-d', strtotime(" +$days day"));
        }

        $c = ORM::for_table('tbl_customers')->find_one($tur['customer_id']);
        $p = ORM::for_table('tbl_plans')->find_one($tur['plan_id']);
        if (!$c || !$p) {
            api_out(false, 'Customer atau Plan tidak ditemukan.', null, 404);
        }

        if (class_exists('Package') && method_exists('Package', 'getDevice')) {
            $dvc = Package::getDevice($p);
            if (($_app_stage ?? '') !== 'demo' && file_exists($dvc)) {
                require_once $dvc;
                $isChangePlan = true;
                $cls = $p['device'];
                if (class_exists($cls)) {
                    try { (new $cls())->add_customer($c, $p); } catch (Exception $e) {}
                }
            }
        }

        $tur->expiration = $expiration;
        $tur->status     = 'on';
        $tur->save();

        write_admin_log($admin, "Extend Customer {$tur['username']} for $days days until $expiration");
        api_out(true, "Masa aktif {$tur['username']} berhasil diperpanjang $days hari hingga $expiration.", $tur->as_array());
        break;

    case 'admin_deposit':
        $admin = require_admin_session($api_secret);
        $user_id    = (int)(_post('customer_id') ?: _post('id_customer'));
        $plan_id    = (int)(_post('plan_id') ?: _post('id_plan'));
        $custom_amt = (float)_post('amount');
        $note       = trim(_post('note') ?: '');

        $c = ORM::for_table('tbl_customers')->find_one($user_id);
        if (!$c) {
            api_out(false, 'Pelanggan tidak ditemukan.', null, 404);
        }

        if (function_exists('run_hook')) {
            @run_hook('deposit_customer');
        }

        $admin_name = $admin['fullname'] ?: $admin['username'];
        $currency   = $config['currency_code'] ?? 'Rp.';
        $trxId      = 0;
        $plan_label = 'Balance';
        $price_amt  = 0.0;

        if ($custom_amt != 0) {
            $plan = [
                'name_plan' => class_exists('Lang') ? Lang::T('Balance') : 'Balance',
                'price'     => $custom_amt
            ];
            $plan_label = $plan['name_plan'];
            $price_amt  = $custom_amt;

            if (class_exists('Package') && method_exists('Package', 'rechargeBalance')) {
                $trxId = (int)@Package::rechargeBalance($c, $plan, 'Deposit', $admin_name, $note);
            }
        } elseif ($plan_id > 0) {
            $p = ORM::for_table('tbl_plans')->find_one($plan_id);
            if (!$p) {
                api_out(false, 'Paket Saldo tidak ditemukan.', null, 404);
            }
            $plan_label = $p['name_plan'];
            $price_amt  = (float)$p['price'];

            if (class_exists('Package') && method_exists('Package', 'rechargeBalance')) {
                $trxId = (int)@Package::rechargeBalance($c, $p, 'Deposit', $admin_name, $note);
            }
        } else {
            api_out(false, 'Pilih paket saldo atau masukkan nominal top-up terlebih dahulu.', null, 400);
        }

        if ($trxId > 0) {
            $c_fresh = ORM::for_table('tbl_customers')->find_one($user_id);
            $new_bal = $c_fresh ? (float)$c_fresh['balance'] : ((float)$c['balance'] + $price_amt);
            $in = ORM::for_table('tbl_transactions')->find_one($trxId);

            $tg_msg = "#u" . $c['username'] . " " . $c['fullname'] . " #deposit #Balance \n"
                . $plan_label
                . "\nGateway: Deposit"
                . "\nChannel: " . $admin_name
                . "\nAmount: " . $currency . " " . number_format($price_amt, 0, ',', '.')
                . "\nNew Balance: " . $currency . " " . number_format($new_bal, 0, ',', '.')
                . (!empty($note) ? "\nNote:\n" . $note : "");
            notify_telegram_admin($tg_msg);

            write_admin_log($admin, '[' . $admin['username'] . ']: Deposit ' . $c['username'] . ' [' . $plan_label . '][' . $currency . ' ' . number_format($price_amt, 0, ',', '.') . ']');
            unset($_SESSION['notify'], $_SESSION['ntype']);

            $in_arr = $in ? $in->as_array() : [
                'id' => $trxId, 'invoice' => 'INV-' . $trxId, 'username' => $c['username'],
                'plan_name' => $plan_label, 'price' => $price_amt,
                'recharged_on' => date('Y-m-d'), 'recharged_time' => date('H:i:s'),
                'expiration' => date('Y-m-d'), 'time' => date('H:i:s'),
                'method' => 'Deposit - ' . $admin_name, 'routers' => 'balance', 'type' => 'Balance'
            ];
            api_out(true, 'Saldo ' . $c['fullname'] . ' berhasil ditambah menjadi ' . $currency . ' ' . number_format($new_bal, 0, ',', '.'), [
                'balance' => $new_bal,
                'invoice' => $in_arr,
                'receipt' => build_web_invoice_receipt($in_arr, $c_fresh ?: $c, $config, $note)
            ]);
        } else {
            api_out(false, 'Gagal memproses isi ulang saldo pelanggan.', null, 500);
        }
        break;

    case 'admin_vouchers':
        $admin = require_admin_session($api_secret);
        $search = trim(_req('search'));
        $router = trim(_req('router'));
        $plan   = (int)_req('plan');
        $status = trim((string)_req('status'));
        $limit  = max(20, min(500, (int)(_req('limit') ?: 200)));

        $plans_map = [];
        foreach (ORM::for_table('tbl_plans')->find_array() as $p) {
            $plans_map[(int)$p['id']] = $p;
        }

        $admins_map = [];
        foreach (ORM::for_table('tbl_users')->find_array() as $u) {
            $t_badge = ($u['user_type'] === 'Sales') ? ' [S]' : (($u['user_type'] === 'Agent') ? ' [A]' : '');
            $admins_map[(int)$u['id']] = $u['fullname'] . $t_badge;
        }

        $q = ORM::for_table('tbl_voucher')->order_by_desc('id');
        if ($router !== '' && strtolower($router) !== 'all') {
            $q->where('routers', $router);
        }
        if ($status === '0' || $status === '1') {
            $q->where('status', $status);
        }
        if ($plan > 0) {
            $q->where('id_plan', $plan);
        }
        if ($search !== '') {
            $q->where_like('code', '%' . $search . '%');
        }
        if ($admin['user_type'] === 'Agent') {
            $sales = [$admin['id']];
            foreach (ORM::for_table('tbl_users')->select('id')->where('root', $admin['id'])->find_array() as $s) {
                $sales[] = (int)$s['id'];
            }
            $q->where_in('generated_by', $sales);
        }

        $rows = $q->limit($limit)->find_array();
        $vouchers = [];
        foreach ($rows as $r) {
            $p = $plans_map[(int)$r['id_plan']] ?? null;
            $r['name_plan']         = $p['name_plan'] ?? '-';
            $r['price']             = isset($p['price']) ? (float)$p['price'] : 0.0;
            $r['validity']          = $p ? ($p['validity'] . ' ' . $p['validity_unit']) : '-';
            $r['generated_by_name'] = $admins_map[(int)($r['generated_by'] ?? 0)] ?? 'System';
            $vouchers[] = $r;
        }

        api_out(true, 'Daftar voucher dimuat', [
            'voucher_prefix' => $config['voucher_prefix'] ?? 'ARN-',
            'vouchers'       => $vouchers
        ]);
        break;

    case 'admin_voucher_generate':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin', 'Agent', 'Sales']);
        $type           = trim(_post('type') ?: 'Hotspot');
        $plan_id        = (int)(_post('plan_id') ?: _post('plan'));
        $server         = trim(_post('server') ?: _post('routers'));
        $count          = max(1, min(500, (int)(_post('count') ?: _post('numbervoucher') ?: 10)));
        $voucher_format = trim(_post('voucher_format') ?: 'up'); // up | low | rand | numbers
        $prefix         = trim((string)_post('prefix'));
        $len            = max(4, min(16, (int)(_post('length_code') ?: _post('lengthcode') ?: 6)));

        $p = ORM::for_table('tbl_plans')->find_one($plan_id);
        if (!$p) {
            api_out(false, 'Pilih paket voucher terlebih dahulu.', null, 400);
        }
        if ($server === '') {
            $server = ($p['is_radius'] == 1) ? 'radius' : $p['routers'];
        }
        if ($voucher_format === 'numbers' && $len < 6) {
            api_out(false, 'The Length Code must be more than 6 for numbers (Minimal 6 digit untuk format angka).', null, 400);
        }

        if ($prefix !== '') {
            $cfg_pref = ORM::for_table('tbl_appconfig')->where('setting', 'voucher_prefix')->find_one();
            if ($cfg_pref) {
                $cfg_pref->value = $prefix;
                $cfg_pref->save();
            } else {
                $cfg_pref = ORM::for_table('tbl_appconfig')->create();
                $cfg_pref->setting = 'voucher_prefix';
                $cfg_pref->value   = $prefix;
                $cfg_pref->save();
            }
        }

        if (function_exists('run_hook')) {
            @run_hook('create_voucher');
        }

        $generated_codes = [];
        if ($voucher_format === 'numbers') {
            if (function_exists('generateUniqueNumericVouchers')) {
                $generated_codes = generateUniqueNumericVouchers($count, $len);
            } else {
                $min_n = (int)str_pad('1', $len, '0');
                $max_n = (int)str_pad('9', $len, '9');
                for ($i = 0; $i < $count; $i++) {
                    $generated_codes[] = (string)mt_rand($min_n, $max_n);
                }
            }
        } else {
            for ($i = 0; $i < $count; $i++) {
                $code = strtoupper(substr(md5(time() . rand(10000, 99999) . $i), 0, $len));
                if ($voucher_format === 'low') {
                    $code = strtolower($code);
                } elseif ($voucher_format === 'rand') {
                    if (class_exists('Lang') && method_exists('Lang', 'randomUpLowCase')) {
                        $code = Lang::randomUpLowCase($code);
                    } else {
                        $chars = str_split($code);
                        foreach ($chars as &$ch) {
                            $ch = (mt_rand(0, 1) === 1) ? strtolower($ch) : strtoupper($ch);
                        }
                        $code = implode('', $chars);
                    }
                }
                $generated_codes[] = $code;
            }
        }

        $created = [];
        foreach ($generated_codes as $raw_code) {
            $full_code = $prefix . $raw_code;
            if (ORM::for_table('tbl_voucher')->where('code', $full_code)->find_one()) continue;
            $v = ORM::for_table('tbl_voucher')->create();
            $v->type         = $p['type'] ?: $type;
            $v->routers      = $server;
            $v->id_plan      = $p['id'];
            $v->code         = $full_code;
            $v->user         = '0';
            $v->status       = '0';
            $v->generated_by = $admin['id'];
            if (array_key_exists('created_at', $v->as_array())) {
                $v->created_at = date('Y-m-d H:i:s');
            }
            $v->save();
            $created[] = $full_code;
        }

        write_admin_log($admin, 'Generate ' . count($created) . ' voucher paket ' . $p['name_plan']);
        api_out(true, count($created) . ' Voucher berhasil dibuat!', ['codes' => $created]);
        break;

    case 'admin_voucher_delete':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (function_exists('run_hook')) {
            @run_hook('delete_voucher');
        }
        $id          = (int)_req('id');
        $delete_used = (int)_req('delete_used');
        $older_3m    = (int)_req('older_than_3months');
        $ids_arr     = $_POST['voucher_ids'] ?? null;

        if (is_array($ids_arr) && !empty($ids_arr)) {
            $clean_ids = array_map('intval', $ids_arr);
            ORM::for_table('tbl_voucher')->where_in('id', $clean_ids)->delete_many();
            api_out(true, count($clean_ids) . ' Voucher terpilih berhasil dihapus.');
        }

        if ($delete_used === 1) {
            if ($older_3m === 1) {
                $time3months = strtotime('-3 months');
                $d_list = ORM::for_table('tbl_voucher')
                    ->where('status', '1')
                    ->where_raw("UNIX_TIMESTAMP(used_date) < $time3months")
                    ->find_many();
                $jml = 0;
                foreach ($d_list as $v_item) {
                    if (!ORM::for_table('tbl_user_recharges')->where('method', 'Voucher - ' . $v_item['code'])->find_one()) {
                        $v_item->delete();
                        $jml++;
                    }
                }
                api_out(true, "$jml Voucher terpakai (>3 bulan) berhasil dibersihkan.");
            } else {
                ORM::for_table('tbl_voucher')->where('status', '1')->delete_many();
                api_out(true, 'Seluruh voucher yang sudah terpakai berhasil dibersihkan.');
            }
        }

        $v = ORM::for_table('tbl_voucher')->find_one($id);
        if (!$v) api_out(false, 'Voucher tidak ditemukan.', null, 404);
        $v->delete();
        api_out(true, class_exists('Lang') ? Lang::T('Data Deleted Successfully') : 'Voucher berhasil dihapus.');
        break;

    case 'admin_voucher_refill':
        $admin = require_admin_session($api_secret);
        $customer_id = (int)(_post('customer_id') ?: _post('id_customer'));
        $raw_code    = trim(_post('code'));
        $code        = (class_exists('Text') && method_exists('Text', 'alphanumeric'))
            ? Text::alphanumeric($raw_code, "-_.,")
            : $raw_code;

        $user = ORM::for_table('tbl_customers')->where('id', $customer_id)->find_one();
        if (!$user) api_out(false, 'Pilih pelanggan terlebih dahulu.', null, 404);

        $v1 = ORM::for_table('tbl_voucher')->where_raw("BINARY code = ?", [$code])->where('status', 0)->find_one();
        if (!$v1) {
            api_out(false, class_exists('Lang') ? Lang::T('Voucher Not Valid') : 'Kode voucher tidak ditemukan atau sudah terpakai.', null, 404);
        }

        if (function_exists('run_hook')) {
            @run_hook('refill_customer');
        }

        if (class_exists('Package') && Package::rechargeUser($user['id'], $v1['routers'], $v1['id_plan'], 'Voucher', $code)) {
            $v1->status = '1';
            $v1->user   = $user['username'];
            $v1->save();
            $in = ORM::for_table('tbl_transactions')->where('username', $user['username'])->order_by_desc('id')->find_one();
            unset($_SESSION['notify'], $_SESSION['ntype']);
            $in_arr = $in ? $in->as_array() : [
                'id' => 0, 'invoice' => 'VCR-' . $code, 'username' => $user['username'],
                'plan_name' => 'Voucher ' . $code, 'price' => 0,
                'recharged_on' => date('Y-m-d'), 'recharged_time' => date('H:i:s'),
                'expiration' => date('Y-m-d'), 'time' => date('H:i:s'),
                'method' => 'Voucher - ' . $code, 'routers' => $v1['routers'], 'type' => $v1['type']
            ];
            api_out(true, 'Voucher ' . $code . ' berhasil diaktifkan ke ' . $user['fullname'] . '!', [
                'invoice' => $in_arr,
                'receipt' => build_web_invoice_receipt($in_arr, $user, $config)
            ]);
        }
        api_out(false, 'Gagal mengaktifkan voucher ke Router.', null, 500);
        break;

    case 'admin_resend_invoice_wa':
        $admin = require_admin_session($api_secret);
        $trx_id   = (int)_post('trx_id');
        $invoice  = trim(_post('invoice'));
        $username = trim(_post('username'));
        $note     = trim(_post('note'));

        $in = null;
        if ($trx_id > 0) {
            $in = ORM::for_table('tbl_transactions')->find_one($trx_id);
        }
        if (!$in && $invoice !== '') {
            $in = ORM::for_table('tbl_transactions')->where('invoice', $invoice)->order_by_desc('id')->find_one();
        }
        if (!$in && $username !== '') {
            $in = ORM::for_table('tbl_transactions')->where('username', $username)->order_by_desc('id')->find_one();
        }
        if (!$in) {
            api_out(false, 'Data transaksi invoice tidak ditemukan.', null, 404);
        }

        $cust = ORM::for_table('tbl_customers')->where('username', $in['username'])->find_one();
        $cust_arr = $cust ? $cust->as_array() : ['id' => 0, 'username' => $in['username'], 'fullname' => $in['username'], 'phonenumber' => _post('phonenumber')];
        $receipt = build_web_invoice_receipt($in->as_array(), $cust_arr, $config, $note);
        $phone = trim($cust_arr['phonenumber'] ?? '');

        $sent_via_gateway = false;
        if (!empty($phone) && (!empty($config['wa_url']) || (class_exists('Message') && method_exists('Message', 'sendWhatsapp')))) {
            notify_whatsapp_customer($phone, $receipt['wa_web_text']);
            $sent_via_gateway = true;
        }

        api_out(true, $sent_via_gateway ? ('Notifikasi Invoice berhasil dikirim ke WhatsApp ' . $phone . ' via Server Web!') : 'Membuka WhatsApp dengan template Invoice Web...', [
            'sent_via_gateway' => $sent_via_gateway,
            'phonenumber'      => $phone,
            'wa_web_text'      => $receipt['wa_web_text'],
            'receipt'          => $receipt
        ]);
        break;
}
