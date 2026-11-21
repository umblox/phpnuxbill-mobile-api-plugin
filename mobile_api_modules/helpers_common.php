<?php
/**
 * PHPNuxBill Mobile API Plugin - Common Helpers
 * Path: system/plugin/mobile_api_modules/helpers_common.php
 */

global $config, $_c, $admin, $user, $ui, $routes, $root_path, $lan_file, $_L, $UPLOAD_PATH, $CACHE_PATH, $DEVICE_PATH, $PLUGIN_PATH, $PAYMENTGATEWAY_PATH, $phpnux_root, $api_secret;

// 1. Pastikan working directory berada di root instalasi PHPNuxBill
$phpnux_root = dirname(__DIR__, 3);
if (is_dir($phpnux_root . DIRECTORY_SEPARATOR . 'system') && getcwd() !== $phpnux_root) {
    @chdir($phpnux_root);
}
$GLOBALS['phpnux_root'] = $phpnux_root;

// 2. Sinkronisasi variabel $config dan $_c dari tbl_appconfig
if (empty($GLOBALS['config']) && !empty($GLOBALS['_c']) && is_array($GLOBALS['_c'])) {
    $GLOBALS['config'] = $GLOBALS['_c'];
}
if (empty($GLOBALS['config']) || !is_array($GLOBALS['config'])) {
    $GLOBALS['config'] = [];
    try {
        foreach (ORM::for_table('tbl_appconfig')->find_array() as $_cfg_row) {
            $GLOBALS['config'][$_cfg_row['setting']] = $_cfg_row['value'];
        }
    } catch (Throwable $e) {
    }
}
$config        = $GLOBALS['config'];
$_c            = $GLOBALS['config'];
$GLOBALS['_c'] = $GLOBALS['config'];

// 3a. Normalisasi seluruh variabel path global agar sesuai dengan working directory root PHPNuxBill
$GLOBALS['root_path']           = '';
$GLOBALS['UPLOAD_PATH']         = 'system/uploads';
$GLOBALS['CACHE_PATH']          = 'system/cache';
$GLOBALS['DEVICE_PATH']         = 'system/devices';
$GLOBALS['PLUGIN_PATH']         = 'system/plugin';
$GLOBALS['PAYMENTGATEWAY_PATH'] = 'system/paymentgateway';
$GLOBALS['lan_file']            = 'system/lan/' . ($config['language'] ?? 'indonesia') . '.json';

$root_path           = $GLOBALS['root_path'];
$UPLOAD_PATH         = $GLOBALS['UPLOAD_PATH'];
$CACHE_PATH          = $GLOBALS['CACHE_PATH'];
$DEVICE_PATH         = $GLOBALS['DEVICE_PATH'];
$PLUGIN_PATH         = $GLOBALS['PLUGIN_PATH'];
$PAYMENTGATEWAY_PATH = $GLOBALS['PAYMENTGATEWAY_PATH'];
$lan_file            = $GLOBALS['lan_file'];

// 3b. Sinkronisasi bahasa dinamis dari aplikasi (X-App-Lang: id | en) & URL Logo Web
$_hdr_lang = strtolower(trim((string) ($_SERVER['HTTP_X_APP_LANG'] ?? ($_REQUEST['lang'] ?? ''))));
if ($_hdr_lang === 'en' || $_hdr_lang === 'english') {
    $GLOBALS['lan_file'] = 'system/lan/english.json';
    $lan_file            = $GLOBALS['lan_file'];
} elseif ($_hdr_lang === 'id' || $_hdr_lang === 'indonesia') {
    $GLOBALS['lan_file'] = 'system/lan/indonesia.json';
    $lan_file            = $GLOBALS['lan_file'];
}
if ($_hdr_lang !== '' && is_file($phpnux_root . DIRECTORY_SEPARATOR . $lan_file)) {
    $_loaded_lan = json_decode((string) @file_get_contents($phpnux_root . DIRECTORY_SEPARATOR . $lan_file), true);
    if (is_array($_loaded_lan)) {
        $GLOBALS['_L'] = $_loaded_lan;
        $_L            = $_loaded_lan;
    }
}

if (!function_exists('get_server_logo_url')) {
    function get_server_logo_url()
    {
        global $UPLOAD_PATH, $phpnux_root;
        $root_dir = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $up_dir   = !empty($UPLOAD_PATH) ? trim($UPLOAD_PATH, '/\\') : 'system/uploads';
        $logo_abs = $root_dir . DIRECTORY_SEPARATOR . $up_dir . DIRECTORY_SEPARATOR . 'logo.png';
        if (!is_file($logo_abs) || filesize($logo_abs) <= 0) {
            return '';
        }
        $base_url = defined('APP_URL') ? trim((string) APP_URL) : '';
        if ($base_url === '') {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
            $scheme   = $isHttps ? 'https' : 'http';
            $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $dir      = rtrim(preg_replace('#/system$#i', '', dirname(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''))), '/');
            $base_url = "{$scheme}://{$host}{$dir}";
        }
        $clean_root = rtrim(preg_replace('#(/system|/index\.php)$#i', '', $base_url), '/');
        $ver        = (int) @filemtime($logo_abs);
        return $clean_root . '/' . str_replace('\\', '/', $up_dir) . '/logo.png' . ($ver > 0 ? ('?v=' . $ver) : '');
    }
}

// 4. Pastikan objek $ui selalu tersedia ketika fungsi internal PHPNuxBill memanggil $ui->assign()
if (empty($GLOBALS['ui']) || !is_object($GLOBALS['ui'])) {
    $GLOBALS['ui'] = new class {
        public $vars = [];

        public function assign($k, $v = null)
        {
            if (is_array($k)) {
                foreach ($k as $key => $val) {
                    $this->vars[$key] = $val;
                }
            } else {
                $this->vars[$k] = $v;
            }
        }

        public function get($k)
        {
            return $this->vars[$k] ?? null;
        }

        public function getTemplateVars($k)
        {
            return $this->vars[$k] ?? '';
        }

        public function fetch($tpl = '')
        {
            return '';
        }

        public function display($tpl = '')
        {
        }
    };
}
$ui = $GLOBALS['ui'];

if (!defined('U') && defined('APP_URL')) {
    $clean_u_root = preg_replace('#/system/?$#i', '', rtrim(APP_URL, '/'));
    define('U', $clean_u_root . '/?_route=');
}

// 5. Fallback fungsi input _post, _get, _req jika belum terdefinisi
if (!function_exists('_post')) {
    function _post($param, $defvalue = '')
    {
        return $_POST[$param] ?? $defvalue;
    }
}
if (!function_exists('_get')) {
    function _get($param, $defvalue = '')
    {
        return $_GET[$param] ?? $defvalue;
    }
}
if (!function_exists('_req')) {
    function _req($param, $defvalue = '')
    {
        return $_REQUEST[$param] ?? ($_POST[$param] ?? ($_GET[$param] ?? $defvalue));
    }
}

// 6. Antrean tugas latar belakang pasca-respon JSON (notifikasi WA/Telegram non-blocking)
if (!isset($GLOBALS['MOBILE_API_POST_TASKS'])) {
    $GLOBALS['MOBILE_API_POST_TASKS'] = [];
}
if (!function_exists('queue_post_response_task')) {
    function queue_post_response_task(callable $task)
    {
        $GLOBALS['MOBILE_API_POST_TASKS'][] = $task;
    }
}
if (!function_exists('run_post_response_tasks')) {
    function run_post_response_tasks()
    {
        if (empty($GLOBALS['MOBILE_API_POST_TASKS'])) {
            return;
        }
        $tasks                            = $GLOBALS['MOBILE_API_POST_TASKS'];
        $GLOBALS['MOBILE_API_POST_TASKS'] = [];
        @ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } else {
            if (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
        }
        foreach ($tasks as $fn) {
            try {
                if (is_callable($fn)) {
                    $fn();
                }
            } catch (Throwable $e) {
            }
        }
    }
}

if (!function_exists('api_out')) {
    function api_out($success, $message, $result = null, $code = 200)
    {
        $GLOBALS['MOBILE_API_OUTPUT_SENT'] = true;
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
            $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
        }
        $payload = [
            'success' => (bool) $success,
            'status'  => (bool) $success,
            'message' => (string) $message,
            'result'  => $result,
            'data'    => $result
        ];
        $json = json_encode($payload, $flags);
        if ($json === false || $json === '') {
            $json = json_encode([
                'success' => (bool) $success,
                'status'  => (bool) $success,
                'message' => (string) $message,
                'result'  => null,
                'data'    => null
            ]);
        }
        echo $json;
        run_post_response_tasks();
        exit();
    }
}

if (!function_exists('create_native_token')) {
    function create_native_token($prefix, $uid, $secret)
    {
        $time = time();
        return $prefix . '.' . $uid . '.' . $time . '.' . sha1($uid . '.' . $time . '.' . $secret);
    }
}

if (!function_exists('generate_customer_token')) {
    function generate_customer_token($user, $secret)
    {
        $payload = $user['id'] . '|' . $user['username'];
        $sig     = hash_hmac('sha256', $payload . '|' . md5($user['password']), $secret);
        return base64_encode($payload . '|' . $sig);
    }
}

if (!function_exists('get_req_token')) {
    function get_req_token()
    {
        if (!empty($_REQUEST['token'])) {
            return trim((string) $_REQUEST['token']);
        }
        $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
        $auth    = $headers['Authorization'] ?? ($headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')));
        if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}

if (!function_exists('require_admin_session')) {
    function require_admin_session($secret, $allowed_roles = [])
    {
        global $config, $admin;
        $finalize_adm = function ($adm) use ($allowed_roles, &$admin) {
            if (!empty($allowed_roles) && !in_array($adm['user_type'], $allowed_roles)) {
                api_out(false, 'Akses ditolak untuk level ' . $adm['user_type'], null, 403);
            }
            $_SESSION['aid']  = $adm['id'];
            $admin            = $adm;
            $GLOBALS['admin'] = $adm;
            return $adm;
        };
        if (!empty($_SESSION['aid'])) {
            $adm = ORM::for_table('tbl_users')->find_one($_SESSION['aid']);
            if ($adm) {
                return $finalize_adm($adm);
            }
        }
        $token = get_req_token();
        if (empty($token)) {
            api_out(false, 'Token otorisasi Admin tidak ditemukan.', null, 401);
        }
        if (!empty($config['api_key']) && hash_equals((string) $config['api_key'], $token)) {
            $adm = ORM::for_table('tbl_users')->where_in('user_type', ['SuperAdmin', 'Admin'])->find_one();
            if ($adm) {
                return $finalize_adm($adm);
            }
        }
        $dot_parts = explode('.', $token);
        if (count($dot_parts) === 4 && $dot_parts[0] === 'a') {
            list($tipe, $uid, $time, $hash) = $dot_parts;
            $ts = (int) $time;
            if ($ts > 0 && (time() - $ts) > 7776000) {
                api_out(false, 'Sesi token Admin telah kedaluwarsa, silakan login ulang.', null, 401);
            }
            if (hash_equals(sha1($uid . '.' . $time . '.' . $secret), trim($hash))) {
                $adm = ORM::for_table('tbl_users')->find_one($uid);
                if ($adm) {
                    return $finalize_adm($adm);
                }
            }
        }
        $decoded    = base64_decode($token, true);
        $pipe_parts = $decoded !== false ? explode('|', $decoded) : [];
        if (count($pipe_parts) === 4 && $pipe_parts[0] === 'ADMIN') {
            list($prefix, $id, $username, $sig) = $pipe_parts;
            $adm = ORM::for_table('tbl_users')->find_one($id);
            if ($adm && $adm['username'] === $username) {
                $expected = hash_hmac('sha256', 'ADMIN|' . $id . '|' . $username . '|' . md5($adm['password']), $secret);
                if (hash_equals($expected, $sig)) {
                    return $finalize_adm($adm);
                }
            }
        }
        api_out(false, 'Sesi Admin tidak valid, silakan login ulang.', null, 401);
    }
}

if (!function_exists('require_customer_session')) {
    function require_customer_session($secret)
    {
        $finalize_cust = function ($cust) {
            $_SESSION['uid'] = $cust['id'];
            $GLOBALS['user'] = $cust;
            return $cust;
        };
        if (!empty($_SESSION['uid'])) {
            $cust = ORM::for_table('tbl_customers')->find_one($_SESSION['uid']);
            if ($cust) {
                return $finalize_cust($cust);
            }
        }
        $token = get_req_token();
        if (empty($token)) {
            api_out(false, 'Token otorisasi Pelanggan tidak ditemukan.', null, 401);
        }
        $dot_parts = explode('.', $token);
        if (count($dot_parts) === 4 && $dot_parts[0] === 'c') {
            list($tipe, $uid, $time, $hash) = $dot_parts;
            $ts = (int) $time;
            if ($ts > 0 && (time() - $ts) > 7776000) {
                api_out(false, 'Sesi token Pelanggan telah kedaluwarsa, silakan login ulang.', null, 401);
            }
            if (hash_equals(sha1($uid . '.' . $time . '.' . $secret), trim($hash))) {
                $cust = ORM::for_table('tbl_customers')->find_one($uid);
                if ($cust) {
                    return $finalize_cust($cust);
                }
            }
        }
        $decoded    = base64_decode($token, true);
        $pipe_parts = $decoded !== false ? explode('|', $decoded) : [];
        if (count($pipe_parts) === 3) {
            list($id, $username, $sig) = $pipe_parts;
            $cust = ORM::for_table('tbl_customers')->find_one($id);
            if ($cust && $cust['username'] === $username) {
                $expected = hash_hmac('sha256', $id . '|' . $username . '|' . md5($cust['password']), $secret);
                if (hash_equals($expected, $sig)) {
                    return $finalize_cust($cust);
                }
            }
        }
        api_out(false, 'Sesi Pelanggan berakhir, silakan login ulang.', null, 401);
    }
}

if (!function_exists('check_user_password')) {
    function check_user_password($plain, $hashed)
    {
        if (class_exists('Password') && method_exists('Password', '_verify')) {
            if (Password::_verify($plain, $hashed)) {
                return true;
            }
        }
        if (password_verify($plain, $hashed)) {
            return true;
        }
        return ($plain === $hashed || sha1($plain) === $hashed || md5($plain) === $hashed);
    }
}

if (!function_exists('hash_user_password')) {
    function hash_user_password($plain)
    {
        if (class_exists('Password') && method_exists('Password', '_crypt')) {
            return Password::_crypt($plain);
        }
        return password_hash($plain, PASSWORD_DEFAULT);
    }
}

if (!function_exists('write_admin_log')) {
    function write_admin_log($admin, $desc)
    {
        try {
            if (function_exists('_log')) {
                _log($desc, $admin['user_type'] ?? 'Admin', $admin['id'] ?? 0);
            } else {
                $log              = ORM::for_table('tbl_logs')->create();
                $log->date        = date('Y-m-d H:i:s');
                $log->type        = $admin['user_type'] ?? 'Admin';
                $log->description = $desc;
                $log->userid      = $admin['id'] ?? 0;
                $log->ip          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $log->save();
            }
        } catch (Throwable $e) {
        }
    }
}

/**
 * Mengambil teks template resmi dari Lang::getNotifText() atau file notifications.json / notifications.default.json
 */
if (!function_exists('get_official_notif_template')) {
    function get_official_notif_template($key)
    {
        global $UPLOAD_PATH, $phpnux_root;
        if (class_exists('Lang') && method_exists('Lang', 'getNotifText')) {
            $txt = Lang::getNotifText($key);
            if (!empty($txt)) {
                return (string) $txt;
            }
        }
        $up_dir   = !empty($UPLOAD_PATH) ? $UPLOAD_PATH : 'system/uploads';
        $root_dir = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        foreach ([
            $root_dir . DIRECTORY_SEPARATOR . $up_dir . DIRECTORY_SEPARATOR . 'notifications.json',
            $root_dir . DIRECTORY_SEPARATOR . $up_dir . DIRECTORY_SEPARATOR . 'notifications.default.json'
        ] as $fPath) {
            if (file_exists($fPath)) {
                $json = json_decode((string) @file_get_contents($fPath), true);
                if (is_array($json) && !empty($json[$key])) {
                    return (string) $json[$key];
                }
            }
        }
        return '';
    }
}

if (!function_exists('notify_telegram_admin')) {
    function notify_telegram_admin($text, $async = false)
    {
        if (empty($text)) {
            return null;
        }
        if ($async) {
            queue_post_response_task(function () use ($text) {
                notify_telegram_admin($text, false);
            });
            return true;
        }
        @ob_start();
        $res = null;
        try {
            if (class_exists('Message') && method_exists('Message', 'sendTelegram')) {
                $res = @Message::sendTelegram($text);
            } elseif (function_exists('sendTelegram')) {
                $res = @sendTelegram($text);
            }
        } catch (Throwable $e) {
        }
        @ob_end_clean();
        return $res;
    }
}

if (!function_exists('notify_whatsapp_customer')) {
    function notify_whatsapp_customer($phone, $text, $async = false)
    {
        if (empty($phone) || empty($text)) {
            return null;
        }
        if ($async) {
            queue_post_response_task(function () use ($phone, $text) {
                notify_whatsapp_customer($phone, $text, false);
            });
            return true;
        }
        @ob_start();
        $res = null;
        try {
            if (class_exists('Message') && method_exists('Message', 'sendWhatsapp')) {
                $res = @Message::sendWhatsapp($phone, $text);
            } elseif (function_exists('sendWhatsapp')) {
                $res = @sendWhatsapp($phone, $text);
            }
        } catch (Throwable $e) {
        }
        @ob_end_clean();
        return $res;
    }
}

if (!function_exists('notify_sms_customer')) {
    function notify_sms_customer($phone, $text, $async = false)
    {
        if (empty($phone) || empty($text)) {
            return null;
        }
        if ($async) {
            queue_post_response_task(function () use ($phone, $text) {
                notify_sms_customer($phone, $text, false);
            });
            return true;
        }
        @ob_start();
        $res = null;
        try {
            if (class_exists('Message') && method_exists('Message', 'sendSMS')) {
                $res = @Message::sendSMS($phone, $text);
            } elseif (function_exists('sendSMS')) {
                $res = @sendSMS($phone, $text);
            }
        } catch (Throwable $e) {
        }
        @ob_end_clean();
        return $res;
    }
}

if (!function_exists('sendTelegram')) {
    function sendTelegram($text)
    {
        if (class_exists('Message') && method_exists('Message', 'sendTelegram')) {
            return @Message::sendTelegram($text);
        }
        return null;
    }
}

if (!function_exists('build_web_invoice_receipt')) {
    function build_web_invoice_receipt($in, $cust, $config = null, $note = '')
    {
        if (empty($config) || !is_array($config)) {
            $config = $GLOBALS['config'] ?? [];
        }
        $currency    = $config['currency_code'] ?? 'Rp.';
        $comp_name   = $config['CompanyName'] ?? 'PHPNuxBill';
        $comp_addr   = $config['address'] ?? '';
        $comp_phone  = $config['phone'] ?? '';
        $footer_note = $config['note'] ?? '';

        $inv_no    = !empty($in['invoice']) ? $in['invoice'] : ('INV-' . ($in['id'] ?? rand(100, 999)));
        $plan_name = $in['plan_name'] ?? '-';
        $type      = $in['type'] ?? ($cust['service_type'] ?? 'Hotspot');
        $price_num = (float) ($in['price'] ?? 0);
        $price_fmt = (class_exists('Lang') && method_exists('Lang', 'moneyFormat'))
            ? Lang::moneyFormat($price_num)
            : ($currency . ' ' . number_format($price_num, 0, ',', '.'));

        $raw_method = trim((string) ($in['method'] ?? 'Cash'));
        $m_parts    = array_map('trim', explode('-', $raw_method, 2));
        $pg_name    = $m_parts[0] ?: $raw_method;
        $ch_name    = $m_parts[1] ?? $pg_name;

        $rech_date   = trim(($in['recharged_on'] ?? date('Y-m-d')) . ' ' . ($in['recharged_time'] ?? date('H:i:s')));
        $exp_date    = trim(($in['expiration'] ?? '') . ' ' . ($in['time'] ?? ''));
        $date_pretty = (class_exists('Lang') && method_exists('Lang', 'dateAndTimeFormat') && !empty($in['recharged_on']))
            ? Lang::dateAndTimeFormat($in['recharged_on'], $in['recharged_time'] ?? '00:00:00')
            : $rech_date;
        $exp_pretty  = (class_exists('Lang') && method_exists('Lang', 'dateAndTimeFormat') && !empty($in['expiration']))
            ? Lang::dateAndTimeFormat($in['expiration'], $in['time'] ?? '23:59:59')
            : ($exp_date ?: '-');

        $cur_bal = (float) ($cust['balance'] ?? 0);
        $bal_fmt = (class_exists('Lang') && method_exists('Lang', 'moneyFormat'))
            ? Lang::moneyFormat($cur_bal)
            : ($currency . ' ' . number_format($cur_bal, 0, ',', '.'));

        $is_balance = (strtoupper(trim($type)) === 'BALANCE' || strtolower(trim($in['routers'] ?? '')) === 'balance');
        $tpl        = get_official_notif_template($is_balance ? 'invoice_balance' : 'invoice_paid');
        if (empty($tpl) && $is_balance) {
            $tpl = get_official_notif_template('balance_send');
        }
        if (empty($tpl)) {
            $tpl = get_official_notif_template('invoice_paid');
        }

        if (!empty($tpl)) {
            $wa_text = str_replace(
                [
                    '[[company_name]]', '[[address]]', '[[phone]]', '[[invoice]]', '[[date]]',
                    '[[payment_gateway]]', '[[payment_channel]]', '[[type]]', '[[plan_name]]',
                    '[[plan_price]]', '[[name]]', '[[note]]', '[[user_name]]', '[[user_password]]',
                    '[[expired_date]]', '[[current_balance]]', '[[footer]]', '[[bills]]', '[[additional_cost]]'
                ],
                [
                    $comp_name, $comp_addr, $comp_phone, $inv_no, $date_pretty,
                    $pg_name, $ch_name, $type, $plan_name,
                    $price_fmt, $cust['fullname'] ?? ($cust['username'] ?? '-'), $note ?: ($cust['note'] ?? ''),
                    $cust['username'] ?? '-', $cust['password'] ?? '',
                    $exp_pretty, $bal_fmt, $footer_note, '', ''
                ],
                $tpl
            );
        } else {
            if ($is_balance) {
                $wa_text = "*{$comp_name}*\n"
                    . "========================\n"
                    . "No. Invoice : {$inv_no}\n"
                    . "Tanggal     : {$date_pretty}\n"
                    . "Pelanggan   : " . ($cust['fullname'] ?? $cust['username']) . " (@" . ($cust['username'] ?? '-') . ")\n"
                    . "Layanan     : Top-Up Saldo ({$plan_name})\n"
                    . "Nominal     : {$price_fmt}\n"
                    . "Saldo Akhir : *{$bal_fmt}*\n"
                    . ($note !== '' ? "Catatan     : {$note}\n" : "")
                    . "========================\n"
                    . $footer_note;
            } else {
                $wa_text = "*{$comp_name}*\n"
                    . "========================\n"
                    . "No. Invoice : {$inv_no}\n"
                    . "Tanggal     : {$date_pretty}\n"
                    . "Pelanggan   : " . ($cust['fullname'] ?? $cust['username']) . " (@" . ($cust['username'] ?? '-') . ")\n"
                    . "Paket       : {$plan_name}\n"
                    . "Harga       : {$price_fmt}\n"
                    . "Metode      : {$raw_method}\n"
                    . "Aktif s/d   : *{$exp_pretty}*\n"
                    . "========================\n"
                    . $footer_note;
            }
        }

        return [
            'trx_id'            => (int) ($in['id'] ?? 0),
            'invoice'           => $inv_no,
            'customer_id'       => (int) ($cust['id'] ?? 0),
            'username'          => $cust['username'] ?? ($in['username'] ?? '-'),
            'fullname'          => $cust['fullname'] ?? ($in['username'] ?? '-'),
            'phonenumber'       => $cust['phonenumber'] ?? '',
            'plan_name'         => $plan_name,
            'type'              => $is_balance ? 'Balance' : $type,
            'price'             => $price_num,
            'price_formatted'   => $price_fmt,
            'method'            => $raw_method,
            'routers'           => $in['routers'] ?? '-',
            'recharged_at'      => $rech_date,
            'expiration'        => $exp_date ?: '-',
            'expiration_pretty' => $exp_pretty,
            'balance_after'     => $cur_bal,
            'balance_formatted' => $bal_fmt,
            'note'              => $note,
            'wa_web_text'       => trim($wa_text)
        ];
    }
}

if (!function_exists('ensure_custom_tables')) {
    function ensure_custom_tables()
    {
        try {
            $db = ORM::get_db();
            $db->exec("CREATE TABLE IF NOT EXISTS tbl_coupons (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) NOT NULL UNIQUE,
                type ENUM('fixed','percent') DEFAULT 'fixed',
                value DECIMAL(12,2) NOT NULL DEFAULT 0,
                description TEXT NULL,
                max_usage INT NOT NULL DEFAULT 0,
                usage_count INT NOT NULL DEFAULT 0,
                status VARCHAR(20) DEFAULT 'active',
                min_order_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                max_discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                start_date DATE NULL,
                end_date DATE NULL,
                expired_date DATE NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            )");
            $cols = [];
            foreach ($db->query("SHOW COLUMNS FROM tbl_coupons")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cols[strtolower($c['Field'])] = true;
            }
            $alter_stmts = [
                'description'         => "ALTER TABLE tbl_coupons ADD COLUMN description TEXT NULL",
                'max_usage'           => "ALTER TABLE tbl_coupons ADD COLUMN max_usage INT NOT NULL DEFAULT 0",
                'usage_count'         => "ALTER TABLE tbl_coupons ADD COLUMN usage_count INT NOT NULL DEFAULT 0",
                'min_order_amount'    => "ALTER TABLE tbl_coupons ADD COLUMN min_order_amount DECIMAL(12,2) NOT NULL DEFAULT 0",
                'max_discount_amount' => "ALTER TABLE tbl_coupons ADD COLUMN max_discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0",
                'start_date'          => "ALTER TABLE tbl_coupons ADD COLUMN start_date DATE NULL",
                'end_date'            => "ALTER TABLE tbl_coupons ADD COLUMN end_date DATE NULL",
                'updated_at'          => "ALTER TABLE tbl_coupons ADD COLUMN updated_at DATETIME NULL"
            ];
            foreach ($alter_stmts as $col_name => $sql) {
                if (!isset($cols[$col_name])) {
                    try {
                        $db->exec($sql);
                    } catch (Throwable $e) {
                    }
                }
            }
            $db->exec("CREATE TABLE IF NOT EXISTS tbl_assets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                category VARCHAR(60) DEFAULT 'ODP/ODC',
                brand VARCHAR(80) DEFAULT '',
                serial_number VARCHAR(100) DEFAULT '',
                cost DECIMAL(15,2) DEFAULT 0,
                location VARCHAR(255) DEFAULT '',
                coordinates VARCHAR(100) DEFAULT '',
                condition_status VARCHAR(40) DEFAULT 'Good',
                purchase_date DATE NULL,
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('fmt_bytes')) {
    function fmt_bytes($bytes, $precision = 2)
    {
        $b = max((float) $bytes, 0);
        if ($b <= 0) {
            return '0 B';
        }
        $u   = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $pow = floor(($b ? log($b) : 0) / log(1024));
        $pow = min($pow, count($u) - 1);
        $b  /= pow(1024, $pow);
        return round($b, $precision) . ' ' . $u[$pow];
    }
}

if (!function_exists('fmt_bps')) {
    function fmt_bps($bps)
    {
        $b = max((float) $bps, 0);
        if ($b <= 0) {
            return '0 bps';
        }
        if ($b >= 1000000) {
            return round($b / 1000000, 2) . ' Mbps';
        }
        if ($b >= 1000) {
            return round($b / 1000, 1) . ' Kbps';
        }
        return round($b, 0) . ' bps';
    }
}

if (!function_exists('parse_ros_rate')) {
    function parse_ros_rate($val)
    {
        $s = strtoupper(trim((string) $val));
        if ($s === '' || $s === '0' || $s === '0BPS') {
            return 0.0;
        }
        $num = (float) preg_replace('/[^0-9\.]/', '', $s);
        if (strpos($s, 'G') !== false) {
            return $num * 1000000000.0;
        }
        if (strpos($s, 'M') !== false) {
            return $num * 1000000.0;
        }
        if (strpos($s, 'K') !== false) {
            return $num * 1000.0;
        }
        return $num;
    }
}

if (!function_exists('connect_ros')) {
    function connect_ros($ip_port, $user, $pass)
    {
        if (class_exists('Mikrotik') && method_exists('Mikrotik', 'getClient')) {
            try {
                $c = @Mikrotik::getClient($ip_port, $user, $pass);
                if ($c) {
                    return $c;
                }
            } catch (Throwable $e) {
            }
        }
        if (class_exists('PEAR2\Net\RouterOS\Client')) {
            try {
                $parts = explode(':', $ip_port);
                return new PEAR2\Net\RouterOS\Client($parts[0], $user, $pass, isset($parts[1]) ? (int) $parts[1] : 8728, false, 4);
            } catch (Throwable $e) {
                return null;
            }
        }
        return null;
    }
}

// 7. Muat modul khusus Payment Gateways (ViaQRIS, Tripay, Duitku, Xendit, PayPal, dll.)
require_once __DIR__ . '/helpers_payment_gateways.php';

// 8. Shutdown interceptor untuk menangkap redirect r2()/exit() dari plugin Payment Gateway & Fatal Error PHP
if (empty($GLOBALS['MOBILE_API_SHUTDOWN_REGISTERED'])) {
    $GLOBALS['MOBILE_API_SHUTDOWN_REGISTERED'] = true;
    register_shutdown_function(function () {
        if (!empty($GLOBALS['API_LOCK_HANDLE']) && is_resource($GLOBALS['API_LOCK_HANDLE'])) {
            @flock($GLOBALS['API_LOCK_HANDLE'], LOCK_UN);
            @fclose($GLOBALS['API_LOCK_HANDLE']);
            $GLOBALS['API_LOCK_HANDLE'] = null;
        }
        if (!empty($GLOBALS['API_INTERCEPT_TRX_ID'])) {
            $trx_id                          = $GLOBALS['API_INTERCEPT_TRX_ID'];
            $mode                            = $GLOBALS['API_INTERCEPT_MODE'] ?? 'buy';
            $cfg                             = $GLOBALS['config'] ?? [];
            $GLOBALS['API_INTERCEPT_TRX_ID'] = null;
            if (ob_get_length()) {
                @ob_clean();
            }
            if (!headers_sent()) {
                header_remove('Location');
                header_remove('location');
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(200);
            }
            $trx    = ORM::for_table('tbl_payment_gateway')->find_one($trx_id);
            $ntype  = $_SESSION['ntype'] ?? 's';
            $notify = isset($_SESSION['notify']) ? trim(strip_tags((string) $_SESSION['notify'])) : '';
            unset($_SESSION['notify'], $_SESSION['ntype']);

            if ($mode === 'check_payment') {
                $is_paid = verify_already_recharged($trx);
                $payload = ['paid' => $is_paid, 'bill' => parse_qris_trx($trx, $cfg)];
                echo json_encode([
                    'success' => true,
                    'status'  => true,
                    'message' => $is_paid ? 'Pembayaran diterima! Paket sudah aktif.' : ($notify ?: 'Pembayaran belum masuk.'),
                    'result'  => $payload,
                    'data'    => $payload
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            }

            $gw_lower            = strtolower(trim((string) ($trx['gateway'] ?? '')));
            $has_valid_pg_output = ($gw_lower === 'viaqris')
                ? (!empty($trx['pg_url_payment']) || !empty($trx['pg_request']) || $ntype === 's')
                : (!empty($trx['gateway_trx_id']) || !empty($trx['pg_url_payment']));

            if ($trx && $ntype === 's' && $has_valid_pg_output) {
                $payload = parse_qris_trx($trx, $cfg);
                echo json_encode([
                    'success' => true,
                    'status'  => true,
                    'message' => $notify ?: 'Tagihan pembayaran berhasil dibuat.',
                    'result'  => $payload,
                    'data'    => $payload
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                if ($trx && (int) $trx['status'] === 1 && empty($trx['gateway_trx_id']) && empty($trx['pg_url_payment'])) {
                    $trx->delete();
                }
                echo json_encode([
                    'success' => false,
                    'status'  => false,
                    'message' => $notify ?: 'Gagal membuat transaksi ke Payment Gateway. Pastikan metode/channel pembayaran sudah aktif di Merchant.',
                    'result'  => null,
                    'data'    => null
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            return;
        }

        // Tangkap Fatal Error PHP jika belum ada JSON yang dikirim oleh api_out()
        if (empty($GLOBALS['MOBILE_API_OUTPUT_SENT'])) {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode([
                    'success' => false,
                    'status'  => false,
                    'message' => 'Fatal PHP Error: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')',
                    'result'  => null,
                    'data'    => null
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
    });
}
