<?php
/**
 * PHPNuxBill Mobile API Plugin - System Settings, Staff, Notifications, DB & Tools Module
 * Path: system/plugin/mobile_api_modules/core_settings_staff.php
 */

switch ($action) {
    // =========================================================================
    // PENGATURAN SISTEM, LOKALISASI, MAINTENANCE, STAF, NOTIFIKASI & DB STATUS
    // =========================================================================
    case 'admin_settings':
        global $UPLOAD_PATH;
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $rows = ORM::for_table('tbl_appconfig')->find_array();
        $settings_out = [];
        foreach ($rows as $r) {
            if ($r['setting'] === 'api_secret_jwt') continue;
            $settings_out[$r['setting']] = $r['value'];
        }
        if (empty($settings_out['mikrotik_sms_command'])) {
            $settings_out['mikrotik_sms_command'] = '/tool sms send';
        }
        api_out(true, 'Pengaturan sistem dimuat', $settings_out);
        break;

    case 'admin_settings_save':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $settings_payload = $_POST['settings'] ?? $_POST;

        if (isset($settings_payload['custom_tax_rate'])) {
            $custom_tax = filter_var($settings_payload['custom_tax_rate'], FILTER_SANITIZE_SPECIAL_CHARS);
            if ($custom_tax !== '' && preg_match('/[^0-9.]/', $custom_tax)) {
                api_out(false, 'Special characters are not allowed in tax rate', null, 400);
            }
        }

        if (function_exists('run_hook')) {
            @run_hook('save_settings');
        }

        if (is_array($settings_payload)) {
            foreach ($settings_payload as $k => $v) {
                if (in_array($k, ['action', 'token', 'r', '_route', 'settings', 'csrf_token'])) continue;
                if (is_array($v)) continue;
                $row = ORM::for_table('tbl_appconfig')->where('setting', $k)->find_one();
                if ($row) {
                    $row->value = (string)$v;
                    $row->save();
                } else {
                    $row = ORM::for_table('tbl_appconfig')->create();
                    $row->setting = $k;
                    $row->value   = (string)$v;
                    $row->save();
                }
            }
        }
        write_admin_log($admin, class_exists('Lang') ? Lang::T('Settings Saved Successfully') : 'Memperbarui pengaturan aplikasi');
        api_out(true, class_exists('Lang') ? Lang::T('Settings Saved Successfully') : 'Pengaturan berhasil disimpan.');
        break;

    case 'admin_test_notif':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $channel = strtolower(trim(_req('channel') ?: 'wa')); // wa | sms | email | tg
        $target  = trim(_req('target'));

        if ($channel === 'wa') {
            if (empty($target)) api_out(false, 'Masukkan nomor WhatsApp tujuan tes.', null, 400);
            $res = (class_exists('Message') && method_exists('Message', 'sendWhatsapp'))
                ? Message::sendWhatsapp($target, 'PHPNuxBill Test Whatsapp')
                : notify_whatsapp_customer($target, 'PHPNuxBill Test Whatsapp');
            api_out(true, 'Test Whatsapp has been sent. Result: ' . (is_string($res) ? $res : 'OK'));
        } elseif ($channel === 'sms') {
            if (empty($target)) api_out(false, 'Masukkan nomor HP tujuan tes SMS.', null, 400);
            $res = (class_exists('Message') && method_exists('Message', 'sendSMS'))
                ? Message::sendSMS($target, 'PHPNuxBill Test SMS')
                : notify_sms_customer($target, 'PHPNuxBill Test SMS');
            api_out(true, 'Test SMS has been sent. Result: ' . (is_string($res) ? $res : 'OK'));
        } elseif ($channel === 'email') {
            if (empty($target)) api_out(false, 'Masukkan alamat Email tujuan tes.', null, 400);
            if (class_exists('Message') && method_exists('Message', 'sendEmail')) {
                Message::sendEmail($target, 'PHPNuxBill Test Email', 'PHPNuxBill Test Email Body');
            } else {
                @mail($target, 'PHPNuxBill Test Email', 'PHPNuxBill Test Email Body');
            }
            api_out(true, 'Test Email has been sent to ' . $target);
        } elseif ($channel === 'tg') {
            $res = (class_exists('Message') && method_exists('Message', 'sendTelegram'))
                ? Message::sendTelegram('PHPNuxBill Test Telegram')
                : 'Message::sendTelegram tidak tersedia';
            api_out(true, 'Test Telegram has been sent. Result: ' . (is_string($res) ? $res : 'OK'));
        }
        api_out(false, 'Channel tes tidak dikenal.', null, 400);
        break;

    case 'admin_notifications_get':
        global $UPLOAD_PATH;
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $up_dir = !empty($UPLOAD_PATH) ? $UPLOAD_PATH : 'system/uploads';
        $json_file = $up_dir . DIRECTORY_SEPARATOR . 'notifications.json';
        $def_file  = $up_dir . DIRECTORY_SEPARATOR . 'notifications.default.json';

        $curr_data = [];
        $def_data  = [];
        if (file_exists($def_file)) {
            $def_data = json_decode(file_get_contents($def_file), true) ?: [];
        }
        if (file_exists($json_file)) {
            $curr_data = json_decode(file_get_contents($json_file), true) ?: $def_data;
        } else {
            $curr_data = $def_data;
        }
        api_out(true, 'Template notifikasi dimuat', [
            'notifications' => $curr_data,
            'defaults'      => $def_data
        ]);
        break;

    case 'admin_notifications_save':
        global $UPLOAD_PATH;
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $up_dir = !empty($UPLOAD_PATH) ? $UPLOAD_PATH : 'system/uploads';
        $json_file = $up_dir . DIRECTORY_SEPARATOR . 'notifications.json';
        $def_file  = $up_dir . DIRECTORY_SEPARATOR . 'notifications.default.json';

        $existing = [];
        if (file_exists($json_file)) {
            $existing = json_decode(file_get_contents($json_file), true) ?: [];
        } elseif (file_exists($def_file)) {
            $existing = json_decode(file_get_contents($def_file), true) ?: [];
        }

        $payload = $_POST['notifications'] ?? $_POST;
        if (is_array($payload)) {
            foreach ($payload as $k => $v) {
                if (in_array($k, ['action', 'token', 'r', '_route', 'notifications', 'csrf_token'])) continue;
                if (is_array($v)) continue;
                $existing[$k] = (string)$v;
            }
        }
        @file_put_contents($json_file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        write_admin_log($admin, 'Memperbarui template notifications.json');
        api_out(true, class_exists('Lang') ? Lang::T('Settings Saved Successfully') : 'Template notifikasi berhasil disimpan.');
        break;

    case 'admin_db_status':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $tables = [];
        $total_rows = 0;
        $total_size_bytes = 0;
        try {
            $db = ORM::get_db();
            foreach ($db->query("SHOW TABLE STATUS")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $t_name = $row['Name'] ?? '';
                if ($t_name === '') continue;
                $cnt = 0;
                try {
                    $cnt = ORM::for_table($t_name)->count();
                } catch (Exception $e) {
                    $cnt = (int)($row['Rows'] ?? 0);
                }
                $size_b = (int)($row['Data_length'] ?? 0) + (int)($row['Index_length'] ?? 0);
                $total_rows += $cnt;
                $total_size_bytes += $size_b;
                $tables[] = [
                    'name'       => $t_name,
                    'rows'       => $cnt,
                    'engine'     => $row['Engine'] ?? 'InnoDB',
                    'size_bytes' => $size_b,
                    'size_label' => fmt_bytes($size_b)
                ];
            }
        } catch (Exception $e) {}
        api_out(true, 'Status database dimuat', [
            'total_tables' => count($tables),
            'total_rows'   => $total_rows,
            'total_size'   => fmt_bytes($total_size_bytes),
            'tables'       => $tables
        ]);
        break;

    case 'admin_db_backup':
        global $UPLOAD_PATH;
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $up_dir = !empty($UPLOAD_PATH) ? $UPLOAD_PATH : 'system/uploads';
        if (!is_dir($up_dir)) @mkdir($up_dir, 0755, true);

        $dump = [];
        try {
            $db = ORM::get_db();
            foreach ($db->query("SHOW TABLE STATUS")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $t_name = $row['Name'] ?? '';
                if ($t_name === '' || $t_name === 'tbl_logs') continue;
                try {
                    $dump[$t_name] = ORM::for_table($t_name)->find_array();
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {
            api_out(false, 'Gagal membaca tabel database: ' . $e->getMessage(), null, 500);
        }

        $filename = 'phpnuxbill_backup_' . date('Y-m-d_His') . '.json';
        $filepath = rtrim($up_dir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        $written  = @file_put_contents($filepath, json_encode($dump, JSON_UNESCAPED_UNICODE));

        write_admin_log($admin, 'Membuat backup database JSON: ' . $filename);
        api_out(true, 'Backup database berhasil disimpan (' . $filename . ').', [
            'filename' => $filename,
            'size'     => fmt_bytes($written ?: 0),
            'tables'   => count($dump)
        ]);
        break;

    case 'admin_staff_list':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin', 'Agent']);
        $search = trim(_req('search'));

        $q = ORM::for_table('tbl_users')->select_many(
            'id', 'username', 'fullname', 'user_type', 'phone', 'email',
            'city', 'subdistrict', 'ward', 'status', 'root', 'creationdate', 'last_login'
        );

        if ($admin['user_type'] === 'SuperAdmin') {
            if ($search !== '') $q->where_like('username', "%$search%");
        } elseif ($admin['user_type'] === 'Admin') {
            $q->where_raw("(user_type IN ('Report','Agent','Sales') OR id = ?)", [$admin['id']]);
            if ($search !== '') $q->where_like('username', "%$search%");
        } else {
            $q->where_raw("(id = ? OR root = ?)", [$admin['id'], $admin['id']]);
            if ($search !== '') $q->where_like('username', "%$search%");
        }

        $rows = $q->order_by_asc('id')->find_array();
        $agents_map = [];
        foreach (ORM::for_table('tbl_users')->where('user_type', 'Agent')->find_array() as $ag) {
            $agents_map[(int)$ag['id']] = $ag['fullname'] ?: $ag['username'];
        }
        foreach ($rows as &$r) {
            $root_id = (int)($r['root'] ?? 0);
            $r['root_name'] = $agents_map[$root_id] ?? '';
        }
        unset($r);

        api_out(true, 'Daftar staf admin dimuat', $rows);
        break;

    case 'admin_staff_save':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin', 'Agent']);
        $id          = (int)_post('id');
        $is_edit     = ($id > 0);
        $uname       = trim(_post('username'));
        $fname       = trim(_post('fullname'));
        $pass        = trim(_post('password'));
        $cpass       = trim(_post('cpassword') ?: $pass);
        $utype       = _post('user_type') ?: 'Agent';
        $phone       = trim(_post('phone'));
        $email       = trim(_post('email'));
        $city        = trim(_post('city'));
        $subdistrict = trim(_post('subdistrict'));
        $ward        = trim(_post('ward'));
        $status      = _post('status') ?: 'Active';
        $root        = (int)_post('root');
        $send_notif  = trim(_post('send_notif'));

        if (strlen($uname) < 3 || strlen($uname) > 45) {
            api_out(false, class_exists('Lang') ? Lang::T('Username should be between 3 to 45 characters') : 'Username harus 3 - 45 karakter.', null, 400);
        }
        if (strlen($fname) < 3 || strlen($fname) > 45) {
            api_out(false, class_exists('Lang') ? Lang::T('Full Name should be between 3 to 45 characters') : 'Nama lengkap harus 3 - 45 karakter.', null, 400);
        }
        if (!$is_edit && strlen($pass) < 6) {
            api_out(false, class_exists('Lang') ? Lang::T('Password should be minimum 6 characters') : 'Password minimal 6 karakter.', null, 400);
        }
        if ($is_edit && $pass !== '') {
            if (strlen($pass) < 6) {
                api_out(false, class_exists('Lang') ? Lang::T('Password should be minimum 6 characters') : 'Password baru minimal 6 karakter.', null, 400);
            }
            if ($pass !== $cpass) {
                api_out(false, class_exists('Lang') ? Lang::T('Passwords does not match') : 'Konfirmasi password tidak cocok.', null, 400);
            }
        }

        if ($is_edit) {
            $u = ORM::for_table('tbl_users')->find_one($id);
            if (!$u) {
                api_out(false, class_exists('Lang') ? Lang::T('Data Not Found') : 'Akun staf tidak ditemukan.', null, 404);
            }
            if ($u['username'] !== $uname) {
                if (ORM::for_table('tbl_users')->where('username', $uname)->find_one()) {
                    api_out(false, class_exists('Lang') ? Lang::T('Account already axist') : 'Username sudah digunakan.', null, 400);
                }
            }
            if (function_exists('run_hook')) @run_hook('edit_admin');
        } else {
            if (ORM::for_table('tbl_users')->where('username', $uname)->find_one()) {
                api_out(false, class_exists('Lang') ? Lang::T('Account already axist') : 'Username sudah terdaftar.', null, 400);
            }
            if (function_exists('run_hook')) @run_hook('add_admin');
            $u = ORM::for_table('tbl_users')->create();
            $u->creationdate = date('Y-m-d H:i:s');
        }

        $u->username = $uname;
        $u->fullname = $fname;
        if ($pass !== '') {
            $u->password = hash_user_password($pass);
        }
        if (!$is_edit || (int)$admin['id'] !== $id) {
            $u->user_type = ($admin['user_type'] === 'Agent') ? 'Sales' : $utype;
        }
        $u->phone       = $phone;
        $u->email       = $email;
        $u->city        = $city;
        $u->subdistrict = $subdistrict;
        $u->ward        = $ward;
        $u->status      = $status;

        if ($admin['user_type'] === 'Agent') {
            $u->root = (int)$admin['id'];
        } elseif ($u->user_type === 'Sales') {
            $u->root = $root;
        } else {
            $u->root = 0;
        }
        $u->save();

        if (!$is_edit && $phone !== '' && ($send_notif === 'wa' || $send_notif === 'sms')) {
            $notif_msg = (class_exists('Lang') ? Lang::T('Hello, Your account has been created successfully.') : 'Halo, Akun staf Anda telah dibuat.')
                . "\nUsername: $uname\nPassword: $pass\n\n" . ($config['CompanyName'] ?? 'Arneta.ID');
            if ($send_notif === 'wa') {
                $fmt_phone = (class_exists('Lang') && method_exists('Lang', 'phoneFormat')) ? Lang::phoneFormat($phone) : $phone;
                notify_whatsapp_customer($fmt_phone, $notif_msg);
            } else {
                notify_sms_customer($phone, $notif_msg);
            }
        }

        write_admin_log($admin, ($is_edit ? "Updated" : "Created") . " {$u->user_type} $uname");
        api_out(true, class_exists('Lang') ? Lang::T($is_edit ? 'User Updated Successfully' : 'Account Created Successfully') : 'Data staf berhasil disimpan.');
        break;

    case 'admin_staff_delete':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $id = (int)_req('id');
        if ($id === (int)$admin['id']) {
            api_out(false, "Sorry You can't delete yourself (Tidak dapat menghapus akun sendiri).", null, 400);
        }
        $u = ORM::for_table('tbl_users')->find_one($id);
        if (!$u) {
            api_out(false, class_exists('Lang') ? Lang::T('Account Not Found') : 'Akun tidak ditemukan.', null, 404);
        }
        if (function_exists('run_hook')) @run_hook('delete_admin');
        $u->delete();
        api_out(true, class_exists('Lang') ? Lang::T('User deleted Successfully') : 'Akun staf berhasil dihapus.');
        break;

    case 'admin_change_password':
        $admin = require_admin_session($api_secret);
        $curr_pass = _post('password');
        $npass     = _post('npass');
        $cnpass    = _post('cnpass');

        if ($curr_pass === '') {
            api_out(false, class_exists('Lang') ? Lang::T('Incorrect Current Password') : 'Masukkan password saat ini.', null, 400);
        }
        $d = ORM::for_table('tbl_users')->where('username', $admin['username'])->find_one();
        if (!$d) {
            api_out(false, 'Akun tidak ditemukan.', null, 404);
        }
        if (function_exists('run_hook')) @run_hook('change_password');

        if (!check_user_password($curr_pass, $d['password'])) {
            api_out(false, class_exists('Lang') ? Lang::T('Incorrect Current Password') : 'Password saat ini salah.', null, 400);
        }
        if (strlen($npass) < 6 || strlen($npass) > 15) {
            api_out(false, 'New Password must be 6 to 14 character', null, 400);
        }
        if ($npass !== $cnpass) {
            api_out(false, 'Both Password should be same (Konfirmasi password tidak cocok).', null, 400);
        }

        $d->password = hash_user_password($npass);
        $d->save();

        write_admin_log($admin, 'Password changed successfully');
        api_out(true, class_exists('Lang') ? Lang::T('Password changed successfully, Please login again') : 'Password berhasil diubah.');
        break;

    case 'admin_logs':
        require_admin_session($api_secret);
        $limit = max(20, min(300, (int)(_req('limit') ?: 100)));
        $logs = ORM::for_table('tbl_logs')->order_by_desc('id')->limit($limit)->find_array();
        api_out(true, 'System logs berhasil dimuat', $logs);
        break;

    case 'system_tools':
    case 'admin_system_tools':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $tool = _req('tool') ?: 'clear_cache';
        if ($tool === 'clear_cache') {
            $del = 0;
            foreach ([$phpnux_root . '/ui/compiled/*', $phpnux_root . '/system/cache/*'] as $pat) {
                foreach (glob($pat) ?: [] as $f) {
                    if (is_file($f) && !in_array(basename($f), ['index.html', '.htaccess'])) {
                        @unlink($f);
                        $del++;
                    }
                }
            }
            api_out(true, $del . ' file cache sistem berhasil dibersihkan.');
        }
        if ($tool === 'run_cron') {
            $cnt = 0;
            $active = ORM::for_table('tbl_user_recharges')->where('status', 'on')->where_lte('expiration', date('Y-m-d'))->find_many();
            foreach ($active as $ur) {
                if (time() >= strtotime($ur['expiration'] . ' ' . ($ur['time'] ?: '23:59:59'))) {
                    $ur->status = 'off';
                    $ur->save();
                    $cnt++;
                }
            }
            api_out(true, 'Cronjob selesai. ' . $cnt . ' paket kedaluwarsa telah dinonaktifkan.');
        }
        api_out(false, 'Perintah tidak dikenali.', null, 400);
        break;
}
