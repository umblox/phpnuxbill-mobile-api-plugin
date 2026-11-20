<?php

/**
 * PHPNuxBill Mobile API Plugin - System Settings, Staff, Notifications, DB & Tools Module
 * Path: system/plugin/mobile_api_modules/core_settings_staff.php
 **/

global $config, $_c, $admin, $ui, $UPLOAD_PATH, $CACHE_PATH, $DEVICE_PATH, $phpnux_root, $_app_stage, $api_secret;

switch ($action) {
    // =========================================================================
    // 1. PENGATURAN SISTEM, LOKALISASI & MAINTENANCE MODE
    // =========================================================================
    case 'admin_settings':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $rows         = ORM::for_table('tbl_appconfig')->find_array();
        $settings_out = [];
        foreach ($rows as $r) {
            if (in_array($r['setting'], ['api_secret_jwt', 'mobile_api_secret'])) {
                continue;
            }
            $settings_out[$r['setting']] = $r['value'];
        }
        if (empty($settings_out['mikrotik_sms_command'])) {
            $settings_out['mikrotik_sms_command'] = '/tool sms send';
        }
        if (!isset($settings_out['maintenance_mode'])) {
            $settings_out['maintenance_mode'] = '0';
        }
        if (!isset($settings_out['maintenance_mode_logout'])) {
            $settings_out['maintenance_mode_logout'] = '0';
        }
        api_out(true, 'Pengaturan sistem dimuat', $settings_out);
        break;

    case 'admin_settings_save':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $settings_payload = $_POST['settings'] ?? $_POST;
        if (isset($settings_payload['custom_tax_rate'])) {
            $custom_tax = filter_var($settings_payload['custom_tax_rate'], FILTER_SANITIZE_SPECIAL_CHARS);
            if ($custom_tax !== '' && preg_match('/[^0-9.]/', $custom_tax)) {
                api_out(false, 'Special characters are not allowed in tax rate', null, 400);
            }
        }

        // Normalisasi kunci Maintenance Mode agar sesuai dengan system/controllers/settings.php
        if (is_array($settings_payload)) {
            if (isset($settings_payload['force_logout']) && !isset($settings_payload['maintenance_mode_logout'])) {
                $settings_payload['maintenance_mode_logout'] = $settings_payload['force_logout'];
            }
            foreach (['maintenance_mode', 'maintenance_mode_logout'] as $m_key) {
                if (isset($settings_payload[$m_key])) {
                    $raw_m = strtolower(trim((string) $settings_payload[$m_key]));
                    $settings_payload[$m_key] = in_array($raw_m, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
                }
            }
        }

        $is_localisation = is_array($settings_payload) && (
            isset($settings_payload['timezone']) ||
            isset($settings_payload['date_format']) ||
            isset($settings_payload['language']) ||
            isset($settings_payload['currency_code'])
        );

        if (function_exists('run_hook')) {
            try {
                @run_hook($is_localisation ? 'save_localisation' : 'save_settings');
            } catch (Throwable $e) {
            }
        }

        if (is_array($settings_payload)) {
            foreach ($settings_payload as $k => $v) {
                if (in_array($k, ['action', 'token', 'r', '_route', 'settings', 'csrf_token', 'force_logout', 'api_secret_jwt', 'mobile_api_secret'])) {
                    continue;
                }
                if (is_array($v)) {
                    continue;
                }
                $val_str = (string) $v;
                $row     = ORM::for_table('tbl_appconfig')->where('setting', $k)->find_one();
                if ($row) {
                    $row->value = $val_str;
                    $row->save();
                } else {
                    $row          = ORM::for_table('tbl_appconfig')->create();
                    $row->setting = $k;
                    $row->value   = $val_str;
                    $row->save();
                }
                $config[$k]            = $val_str;
                $_c[$k]                = $val_str;
                $GLOBALS['config'][$k] = $val_str;
                $GLOBALS['_c'][$k]     = $val_str;
            }
        }

        write_admin_log($admin, class_exists('Lang') ? Lang::T('Settings Saved Successfully') : 'Memperbarui pengaturan aplikasi');
        api_out(true, class_exists('Lang') ? Lang::T('Settings Saved Successfully') : 'Pengaturan berhasil disimpan.');
        break;

    // =========================================================================
    // 2. TES GATEWAY & TEMPLATE NOTIFIKASI (notifications.json)
    // =========================================================================
    case 'admin_test_notif':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $channel = strtolower(trim((string) (_req('channel') ?: 'wa'))); // wa | sms | email | tg
        $target  = trim((string) _req('target'));
        $comp    = $config['CompanyName'] ?? 'PHPNuxBill';

        if ($channel === 'wa') {
            if ($target === '') {
                api_out(false, 'Masukkan nomor WhatsApp tujuan tes terlebih dahulu.', null, 400);
            }
            if (empty($config['wa_url'])) {
                api_out(false, 'WhatsApp Gateway URL (wa_url) belum disimpan di Pengaturan Umum.', null, 400);
            }
            $fmt_target = (class_exists('Lang') && method_exists('Lang', 'phoneFormat'))
                ? Lang::phoneFormat($target)
                : $target;
            $test_msg = "*{$comp}*\nPHPNuxBill Test WhatsApp berhasil terhubung!";
            $res      = (class_exists('Message') && method_exists('Message', 'sendWhatsapp'))
                ? Message::sendWhatsapp($fmt_target, $test_msg)
                : notify_whatsapp_customer($fmt_target, $test_msg);
            api_out(true, 'Tes WhatsApp dikirim ke ' . $fmt_target . '. Respons Server: ' . (is_string($res) && $res !== '' ? $res : 'OK'));
        } elseif ($channel === 'sms') {
            if ($target === '') {
                api_out(false, 'Masukkan nomor HP tujuan tes SMS terlebih dahulu.', null, 400);
            }
            if (empty($config['sms_url'])) {
                api_out(false, 'SMS Gateway URL (sms_url) belum disimpan di Pengaturan Umum.', null, 400);
            }
            $fmt_target = (class_exists('Lang') && method_exists('Lang', 'phoneFormat'))
                ? Lang::phoneFormat($target)
                : $target;
            $test_msg = "{$comp} - PHPNuxBill Test SMS";
            $res      = (class_exists('Message') && method_exists('Message', 'sendSMS'))
                ? Message::sendSMS($fmt_target, $test_msg)
                : notify_sms_customer($fmt_target, $test_msg);
            api_out(true, 'Tes SMS dikirim ke ' . $fmt_target . '. Respons Server: ' . (is_string($res) && $res !== '' ? $res : 'OK'));
        } elseif ($channel === 'email') {
            if ($target === '') {
                api_out(false, 'Masukkan alamat Email tujuan tes terlebih dahulu.', null, 400);
            }
            $subj = $comp . ' - PHPNuxBill Test Email';
            $body = "Halo,\n\nIni adalah pesan uji coba SMTP / Email dari sistem " . $comp . ".";
            if (class_exists('Message') && method_exists('Message', 'sendEmail')) {
                Message::sendEmail($target, $subj, $body);
            } else {
                @mail($target, $subj, $body);
            }
            api_out(true, 'Tes Email berhasil dikirim ke ' . $target);
        } elseif ($channel === 'tg') {
            if (empty($config['telegram_bot']) || empty($config['telegram_target_id'])) {
                api_out(false, 'Telegram Bot Token atau Chat ID belum disimpan di Pengaturan Umum.', null, 400);
            }
            $test_msg = "[{$comp}] PHPNuxBill Test Telegram berhasil terhubung!";
            $res      = (class_exists('Message') && method_exists('Message', 'sendTelegram'))
                ? Message::sendTelegram($test_msg)
                : notify_telegram_admin($test_msg);
            api_out(true, 'Tes Telegram berhasil dikirim ke Bot Admin. Respons: ' . (is_string($res) && $res !== '' ? $res : 'OK'));
        }
        api_out(false, 'Channel tes tidak dikenal.', null, 400);
        break;

    case 'admin_notifications_get':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (function_exists('run_hook')) {
            try {
                @run_hook('view_notifications');
            } catch (Throwable $e) {
            }
        }
        $root_dir  = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $up_dir    = !empty($UPLOAD_PATH) ? $UPLOAD_PATH : 'system/uploads';
        $abs_up    = $root_dir . DIRECTORY_SEPARATOR . ltrim($up_dir, '/\\');
        $json_file = $abs_up . DIRECTORY_SEPARATOR . 'notifications.json';
        $def_file  = $abs_up . DIRECTORY_SEPARATOR . 'notifications.default.json';

        $curr_data = [];
        $def_data  = [];
        if (file_exists($def_file)) {
            $def_data = json_decode((string) file_get_contents($def_file), true) ?: [];
        }
        if (file_exists($json_file)) {
            $curr_data = json_decode((string) file_get_contents($json_file), true) ?: $def_data;
        } else {
            $curr_data = $def_data;
        }
        api_out(true, 'Template notifikasi dimuat', [
            'notifications' => $curr_data,
            'defaults'      => $def_data
        ]);
        break;

    case 'admin_notifications_save':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $root_dir  = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $up_dir    = !empty($UPLOAD_PATH) ? $UPLOAD_PATH : 'system/uploads';
        $abs_up    = $root_dir . DIRECTORY_SEPARATOR . ltrim($up_dir, '/\\');
        $json_file = $abs_up . DIRECTORY_SEPARATOR . 'notifications.json';
        $def_file  = $abs_up . DIRECTORY_SEPARATOR . 'notifications.default.json';

        $existing = [];
        if (file_exists($json_file)) {
            $existing = json_decode((string) file_get_contents($json_file), true) ?: [];
        } elseif (file_exists($def_file)) {
            $existing = json_decode((string) file_get_contents($def_file), true) ?: [];
        }

        $payload = $_POST['notifications'] ?? $_POST;
        if (is_array($payload)) {
            foreach ($payload as $k => $v) {
                if (in_array($k, ['action', 'token', 'r', '_route', 'notifications', 'csrf_token'])) {
                    continue;
                }
                if (is_array($v)) {
                    continue;
                }
                $existing[$k] = (string) $v;
            }
        }

        if (@file_put_contents($json_file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            api_out(false, 'Gagal menyimpan notifications.json. Pastikan folder system/uploads memiliki izin tulis (writable).', null, 500);
        }
        write_admin_log($admin, 'Memperbarui template notifications.json');
        api_out(true, class_exists('Lang') ? Lang::T('Settings Saved Successfully') : 'Template notifikasi berhasil disimpan.');
        break;

    // =========================================================================
    // 3. STATUS & BACKUP DATABASE
    // =========================================================================
    case 'admin_db_status':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (function_exists('run_hook')) {
            try {
                @run_hook('view_database');
            } catch (Throwable $e) {
            }
        }
        $tables           = [];
        $total_rows       = 0;
        $total_size_bytes = 0;
        try {
            $db = ORM::get_db();
            foreach ($db->query("SHOW TABLE STATUS")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $t_name = $row['Name'] ?? '';
                if ($t_name === '') {
                    continue;
                }
                $cnt = 0;
                try {
                    $cnt = ORM::for_table($t_name)->count();
                } catch (Exception $e) {
                    $cnt = (int) ($row['Rows'] ?? 0);
                }
                $size_b            = (int) ($row['Data_length'] ?? 0) + (int) ($row['Index_length'] ?? 0);
                $total_rows       += $cnt;
                $total_size_bytes += $size_b;
                $tables[]          = [
                    'name'       => $t_name,
                    'rows'       => $cnt,
                    'engine'     => $row['Engine'] ?? 'InnoDB',
                    'size_bytes' => $size_b,
                    'size_label' => fmt_bytes($size_b)
                ];
            }
        } catch (Exception $e) {
        }
        api_out(true, 'Status database dimuat', [
            'total_tables' => count($tables),
            'total_rows'   => $total_rows,
            'total_size'   => fmt_bytes($total_size_bytes),
            'tables'       => $tables
        ]);
        break;

    case 'admin_db_backup':
        $admin = require_admin_session($api_secret, ['SuperAdmin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $root_dir = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $up_dir   = !empty($UPLOAD_PATH) ? $UPLOAD_PATH : 'system/uploads';
        $abs_up   = $root_dir . DIRECTORY_SEPARATOR . ltrim($up_dir, '/\\');
        if (!is_dir($abs_up)) {
            @mkdir($abs_up, 0755, true);
        }

        $dump = [];
        try {
            $db = ORM::get_db();
            foreach ($db->query("SHOW TABLE STATUS")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $t_name = $row['Name'] ?? '';
                if ($t_name === '' || $t_name === 'tbl_logs') {
                    continue;
                }
                try {
                    $dump[$t_name] = ORM::for_table($t_name)->find_array();
                } catch (Exception $e) {
                }
            }
        } catch (Exception $e) {
            api_out(false, 'Gagal membaca tabel database: ' . $e->getMessage(), null, 500);
        }

        $filename = 'phpnuxbill_backup_' . date('Y-m-d_His') . '.json';
        $filepath = rtrim($abs_up, '/\\') . DIRECTORY_SEPARATOR . $filename;
        $written  = @file_put_contents($filepath, json_encode($dump, JSON_UNESCAPED_UNICODE));
        if ($written === false) {
            api_out(false, 'Gagal menulis file backup ke folder system/uploads.', null, 500);
        }
        write_admin_log($admin, 'Membuat backup database JSON: ' . $filename);
        api_out(true, 'Backup database berhasil disimpan (' . $filename . ').', [
            'filename' => $filename,
            'size'     => fmt_bytes($written ?: 0),
            'tables'   => count($dump)
        ]);
        break;

    // =========================================================================
    // 4. MANAJEMEN STAF & KATA SANDI ADMIN
    // =========================================================================
    case 'admin_staff_list':
        $admin  = require_admin_session($api_secret, ['SuperAdmin', 'Admin', 'Agent']);
        $search = trim((string) _req('search'));
        $q      = ORM::for_table('tbl_users')->select_many(
            'id', 'username', 'fullname', 'user_type', 'phone', 'email',
            'city', 'subdistrict', 'ward', 'status', 'root', 'creationdate', 'last_login'
        );
        if ($admin['user_type'] === 'SuperAdmin') {
            if ($search !== '') {
                $q->where_like('username', "%$search%");
            }
        } elseif ($admin['user_type'] === 'Admin') {
            $q->where_raw("(user_type IN ('Report','Agent','Sales') OR id = ?)", [$admin['id']]);
            if ($search !== '') {
                $q->where_like('username', "%$search%");
            }
        } else {
            $q->where_raw("(id = ? OR root = ?)", [$admin['id'], $admin['id']]);
            if ($search !== '') {
                $q->where_like('username', "%$search%");
            }
        }
        $rows       = $q->order_by_asc('id')->find_array();
        $agents_map = [];
        foreach (ORM::for_table('tbl_users')->where('user_type', 'Agent')->find_array() as $ag) {
            $agents_map[(int) $ag['id']] = $ag['fullname'] ?: $ag['username'];
        }
        foreach ($rows as &$r) {
            $root_id        = (int) ($r['root'] ?? 0);
            $r['root_name'] = $agents_map[$root_id] ?? '';
        }
        unset($r);
        api_out(true, 'Daftar staf admin dimuat', $rows);
        break;

    case 'admin_staff_save':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin', 'Agent']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $id          = (int) _post('id');
        $is_edit     = ($id > 0);
        $uname       = trim((string) _post('username'));
        $fname       = trim((string) _post('fullname'));
        $pass        = trim((string) _post('password'));
        $cpass       = trim((string) (_post('cpassword') ?: $pass));
        $utype       = trim((string) (_post('user_type') ?: 'Agent'));
        $phone       = trim((string) _post('phone'));
        $email       = trim((string) _post('email'));
        $city        = trim((string) _post('city'));
        $subdistrict = trim((string) _post('subdistrict'));
        $ward        = trim((string) _post('ward'));
        $status      = trim((string) (_post('status') ?: 'Active'));
        $root        = (int) _post('root');
        $send_notif  = trim((string) _post('send_notif'));

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

        // Validasi RBAC peran sesuai system/controllers/settings.php
        if ($admin['user_type'] === 'Admin' && (int) $admin['id'] !== $id) {
            if (!in_array($utype, ['Report', 'Agent', 'Sales'], true)) {
                api_out(false, 'Level Admin hanya dapat menetapkan peran Report, Agent, atau Sales.', null, 403);
            }
        }

        if ($is_edit) {
            if ((int) $admin['id'] === $id) {
                $u = ORM::for_table('tbl_users')->find_one($id);
            } elseif ($admin['user_type'] === 'SuperAdmin') {
                $u = ORM::for_table('tbl_users')->find_one($id);
            } elseif ($admin['user_type'] === 'Admin') {
                $u = ORM::for_table('tbl_users')->where_in('user_type', ['Report', 'Agent', 'Sales'])->find_one($id);
            } else {
                $u = ORM::for_table('tbl_users')->where('root', $admin['id'])->find_one($id);
            }

            if (!$u) {
                api_out(false, class_exists('Lang') ? Lang::T('Data Not Found') : 'Akun staf tidak ditemukan atau akses ditolak.', null, 404);
            }
            if ($u['username'] !== $uname) {
                if (ORM::for_table('tbl_users')->where('username', $uname)->find_one()) {
                    api_out(false, class_exists('Lang') ? Lang::T('Account already axist') : 'Username sudah digunakan.', null, 400);
                }
            }
            if (function_exists('run_hook')) {
                @run_hook('edit_admin');
            }
        } else {
            if (ORM::for_table('tbl_users')->where('username', $uname)->find_one()) {
                api_out(false, class_exists('Lang') ? Lang::T('Account already axist') : 'Username sudah terdaftar.', null, 400);
            }
            if (function_exists('run_hook')) {
                @run_hook('add_admin');
            }
            $u               = ORM::for_table('tbl_users')->create();
            $u->creationdate = date('Y-m-d H:i:s');
        }

        $u->username = $uname;
        $u->fullname = $fname;
        if ($pass !== '') {
            $u->password = hash_user_password($pass);
        }
        if (!$is_edit || (int) $admin['id'] !== $id) {
            $u->user_type = ($admin['user_type'] === 'Agent') ? 'Sales' : $utype;
        }
        $u->phone       = $phone;
        $u->email       = $email;
        $u->city        = $city;
        $u->subdistrict = $subdistrict;
        $u->ward        = $ward;
        $u->status      = $status;

        if ($admin['user_type'] === 'Agent') {
            $u->root = (int) $admin['id'];
        } elseif ($u->user_type === 'Sales') {
            $u->root = $root;
        } else {
            $u->root = 0;
        }
        $u->save();

        if (!$is_edit && $phone !== '' && ($send_notif === 'wa' || $send_notif === 'sms')) {
            $comp_title = $config['CompanyName'] ?? 'PHPNuxBill';
            $notif_msg  = (class_exists('Lang') ? Lang::T('Hello, Your account has been created successfully.') : 'Halo, Akun staf Anda telah dibuat.')
                . "\nUsername: $uname\nPassword: $pass\n\n" . $comp_title;
            $fmt_phone = (class_exists('Lang') && method_exists('Lang', 'phoneFormat')) ? Lang::phoneFormat($phone) : $phone;
            if ($send_notif === 'wa') {
                notify_whatsapp_customer($fmt_phone, $notif_msg, true);
            } else {
                notify_sms_customer($fmt_phone, $notif_msg, true);
            }
        }

        write_admin_log($admin, ($is_edit ? "Updated" : "Created") . " {$u->user_type} $uname");
        api_out(true, class_exists('Lang') ? Lang::T($is_edit ? 'User Updated Successfully' : 'Account Created Successfully') : 'Data staf berhasil disimpan.');
        break;

    case 'admin_staff_delete':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $id = (int) _req('id');
        if ($id === (int) $admin['id']) {
            api_out(false, "Sorry You can't delete yourself (Tidak dapat menghapus akun sendiri).", null, 400);
        }
        if ($admin['user_type'] === 'SuperAdmin') {
            $u = ORM::for_table('tbl_users')->find_one($id);
        } else {
            $u = ORM::for_table('tbl_users')->where_in('user_type', ['Report', 'Agent', 'Sales'])->find_one($id);
        }
        if (!$u) {
            api_out(false, class_exists('Lang') ? Lang::T('Account Not Found') : 'Akun tidak ditemukan atau akses ditolak.', null, 404);
        }
        if (function_exists('run_hook')) {
            @run_hook('delete_admin');
        }
        $deleted_uname = $u['username'];
        $u->delete();
        write_admin_log($admin, "Deleted staff account $deleted_uname");
        api_out(true, class_exists('Lang') ? Lang::T('User deleted Successfully') : 'Akun staf berhasil dihapus.');
        break;

    case 'admin_change_password':
        $admin = require_admin_session($api_secret);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $curr_pass = (string) _post('password');
        $npass     = (string) _post('npass');
        $cnpass    = (string) _post('cnpass');

        if ($curr_pass === '') {
            api_out(false, class_exists('Lang') ? Lang::T('Incorrect Current Password') : 'Masukkan password saat ini.', null, 400);
        }
        $d = ORM::for_table('tbl_users')->where('username', $admin['username'])->find_one();
        if (!$d) {
            api_out(false, 'Akun tidak ditemukan.', null, 404);
        }
        if (function_exists('run_hook')) {
            @run_hook('change_password');
        }
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

    // =========================================================================
    // 5. SYSTEM LOGS & SYSTEM TOOLS (CLEAR CACHE / MANUAL CRON)
    // =========================================================================
    case 'admin_logs':
        require_admin_session($api_secret);
        $limit = max(20, min(300, (int) (_req('limit') ?: 100)));
        $logs  = ORM::for_table('tbl_logs')->order_by_desc('id')->limit($limit)->find_array();
        api_out(true, 'System logs berhasil dimuat', $logs);
        break;

    case 'system_tools':
    case 'admin_system_tools':
        $admin    = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $tool     = _req('tool') ?: 'clear_cache';
        $root_dir = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);

        if ($tool === 'clear_cache') {
            $del = 0;
            foreach ([$root_dir . '/ui/compiled/*', $root_dir . '/system/cache/*'] as $pat) {
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
            $cnt    = 0;
            $active = ORM::for_table('tbl_user_recharges')
                ->where('status', 'on')
                ->where_lte('expiration', date('Y-m-d'))
                ->find_many();
            foreach ($active as $ur) {
                if (time() >= strtotime($ur['expiration'] . ' ' . ($ur['time'] ?: '23:59:59'))) {
                    $c = ORM::for_table('tbl_customers')->find_one($ur['customer_id']);
                    $p = ORM::for_table('tbl_plans')->find_one($ur['plan_id']);
                    if ($c && $p && class_exists('Package') && method_exists('Package', 'getDevice')) {
                        $dvc = Package::getDevice($p);
                        if (strtolower((string) ($_app_stage ?? '')) !== 'demo' && file_exists($dvc)) {
                            require_once $dvc;
                            $cls = $p['device'];
                            if (class_exists($cls)) {
                                try {
                                    (new $cls())->remove_customer($c, $p);
                                } catch (Exception $e) {
                                }
                            }
                        }
                    }
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
