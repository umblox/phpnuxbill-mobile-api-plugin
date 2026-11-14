<?php
/**
 * PHPNuxBill Mobile API Plugin - Common Helpers
 * Path: system/plugin/mobile_api_modules/helpers_common.php
 */

global $config, $_c, $admin, $user, $ui, $root_path, $lan_file, $_L, $UPLOAD_PATH, $CACHE_PATH, $DEVICE_PATH, $PLUGIN_PATH, $PAYMENTGATEWAY_PATH, $phpnux_root;

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
    } catch (Throwable $e) {}
}
$config             = $GLOBALS['config'];
$_c                 = $GLOBALS['config'];
$GLOBALS['_c']      = $GLOBALS['config'];

// 3. Normalisasi seluruh variabel path global agar sesuai dengan working directory root PHPNuxBill
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

// 4. Pastikan objek $ui selalu tersedia ketika fungsi internal PHPNuxBill memanggil $ui->assign()
if (empty($GLOBALS['ui']) || !is_object($GLOBALS['ui'])) {
    $GLOBALS['ui'] = new class {
        public $vars = [];
        public function assign($k, $v = null) {
            if (is_array($k)) {
                foreach ($k as $key => $val) $this->vars[$key] = $val;
            } else {
                $this->vars[$k] = $v;
            }
        }
        public function get($k) { return $this->vars[$k] ?? null; }
        public function fetch($tpl = '') { return ''; }
        public function display($tpl = '') {}
    };
    $ui = $GLOBALS['ui'];
}

if (!defined('U') && defined('APP_URL')) {
    $clean_u_root = preg_replace('#/system/?$#i', '', rtrim(APP_URL, '/'));
    define('U', $clean_u_root . '/?_route=');
}

// 5. Fallback fungsi input _post, _get, _req jika belum terdefinisi
if (!function_exists('_post')) {
    function _post($param, $defvalue = '') {
        return $_POST[$param] ?? $defvalue;
    }
}
if (!function_exists('_get')) {
    function _get($param, $defvalue = '') {
        return $_GET[$param] ?? $defvalue;
    }
}
if (!function_exists('_req')) {
    function _req($param, $defvalue = '') {
        return $_REQUEST[$param] ?? ($_POST[$param] ?? ($_GET[$param] ?? $defvalue));
    }
}

if (!function_exists('api_out')) {
    function api_out($success, $message, $result = null, $code = 200) {
        $GLOBALS['MOBILE_API_OUTPUT_SENT'] = true;
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
            $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
        }

        $payload = [
            'success' => (bool)$success,
            'status'  => (bool)$success,
            'message' => (string)$message,
            'result'  => $result,
            'data'    => $result
        ];

        $json = json_encode($payload, $flags);
        if ($json === false || $json === '') {
            $json = json_encode([
                'success' => (bool)$success,
                'status'  => (bool)$success,
                'message' => (string)$message,
                'result'  => null,
                'data'    => null
            ]);
        }

        echo $json;
        exit();
    }
}

if (!function_exists('create_native_token')) {
    function create_native_token($prefix, $uid, $secret) {
        $time = time();
        return $prefix . '.' . $uid . '.' . $time . '.' . sha1($uid . '.' . $time . '.' . $secret);
    }
}

if (!function_exists('generate_customer_token')) {
    function generate_customer_token($user, $secret) {
        $payload = $user['id'] . '|' . $user['username'];
        $sig = hash_hmac('sha256', $payload . '|' . md5($user['password']), $secret);
        return base64_encode($payload . '|' . $sig);
    }
}

if (!function_exists('get_req_token')) {
    function get_req_token() {
        if (!empty($_REQUEST['token'])) {
            return trim((string)$_REQUEST['token']);
        }
        $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
        $auth = $headers['Authorization'] ?? ($headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/Bearer\s(\S+)/', $auth, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}

if (!function_exists('require_admin_session')) {
    function require_admin_session($secret, $allowed_roles = []) {
        global $config, $admin;

        $finalize_adm = function($adm) use ($allowed_roles, &$admin) {
            if (!empty($allowed_roles) && !in_array($adm['user_type'], $allowed_roles)) {
                api_out(false, 'Akses ditolak untuk level ' . $adm['user_type'], null, 403);
            }
            $_SESSION['aid'] = $adm['id'];
            $admin = $adm;
            $GLOBALS['admin'] = $adm;
            return $adm;
        };

        if (!empty($_SESSION['aid'])) {
            $adm = ORM::for_table('tbl_users')->find_one($_SESSION['aid']);
            if ($adm) return $finalize_adm($adm);
        }

        $token = get_req_token();
        if (empty($token)) {
            api_out(false, 'Token otorisasi Admin tidak ditemukan.', null, 401);
        }

        if (!empty($config['api_key']) && $token === $config['api_key']) {
            $adm = ORM::for_table('tbl_users')->where_in('user_type', ['SuperAdmin', 'Admin'])->find_one();
            if ($adm) return $finalize_adm($adm);
        }

        $dot_parts = explode('.', $token);
        if (count($dot_parts) === 4 && $dot_parts[0] === 'a') {
            list($tipe, $uid, $time, $hash) = $dot_parts;
            if (trim($hash) === sha1($uid . '.' . $time . '.' . $secret)) {
                $adm = ORM::for_table('tbl_users')->find_one($uid);
                if ($adm) return $finalize_adm($adm);
            }
        }

        $decoded = base64_decode($token);
        $pipe_parts = explode('|', $decoded);
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
    function require_customer_session($secret) {
        global $config;

        $finalize_cust = function($cust) {
            $_SESSION['uid'] = $cust['id'];
            $GLOBALS['user'] = $cust;
            return $cust;
        };

        if (!empty($_SESSION['uid'])) {
            $cust = ORM::for_table('tbl_customers')->find_one($_SESSION['uid']);
            if ($cust) return $finalize_cust($cust);
        }

        $token = get_req_token();
        if (empty($token)) {
            api_out(false, 'Token otorisasi Pelanggan tidak ditemukan.', null, 401);
        }

        $dot_parts = explode('.', $token);
        if (count($dot_parts) === 4 && $dot_parts[0] === 'c') {
            list($tipe, $uid, $time, $hash) = $dot_parts;
            if (trim($hash) === sha1($uid . '.' . $time . '.' . $secret)) {
                $cust = ORM::for_table('tbl_customers')->find_one($uid);
                if ($cust) return $finalize_cust($cust);
            }
        }

        $decoded = base64_decode($token);
        $pipe_parts = explode('|', $decoded);
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
    function check_user_password($plain, $hashed) {
        if (class_exists('Password') && method_exists('Password', '_verify')) {
            if (Password::_verify($plain, $hashed)) return true;
        }
        if (password_verify($plain, $hashed)) return true;
        return ($plain === $hashed || sha1($plain) === $hashed || md5($plain) === $hashed);
    }
}

if (!function_exists('hash_user_password')) {
    function hash_user_password($plain) {
        if (class_exists('Password') && method_exists('Password', '_crypt')) {
            return Password::_crypt($plain);
        }
        return password_hash($plain, PASSWORD_DEFAULT);
    }
}

if (!function_exists('write_admin_log')) {
    function write_admin_log($admin, $desc) {
        try {
            if (function_exists('_log')) {
                _log($desc, $admin['user_type'] ?? 'Admin', $admin['id'] ?? 0);
            } else {
                $log = ORM::for_table('tbl_logs')->create();
                $log->date = date('Y-m-d H:i:s');
                $log->type = $admin['user_type'] ?? 'Admin';
                $log->description = $desc;
                $log->userid = $admin['id'] ?? 0;
                $log->ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $log->save();
            }
        } catch (Throwable $e) {}
    }
}

/**
 * Mengambil teks template resmi dari Lang::getNotifText() atau file notifications.json / notifications.default.json
 */
if (!function_exists('get_official_notif_template')) {
    function get_official_notif_template($key) {
        global $UPLOAD_PATH, $phpnux_root;
        if (class_exists('Lang') && method_exists('Lang', 'getNotifText')) {
            $txt = Lang::getNotifText($key);
            if (!empty($txt)) {
                return (string)$txt;
            }
        }
        $up_dir   = !empty($UPLOAD_PATH) ? $UPLOAD_PATH : 'system/uploads';
        $root_dir = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        foreach ([
            $root_dir . DIRECTORY_SEPARATOR . $up_dir . DIRECTORY_SEPARATOR . 'notifications.json',
            $root_dir . DIRECTORY_SEPARATOR . $up_dir . DIRECTORY_SEPARATOR . 'notifications.default.json'
        ] as $fPath) {
            if (file_exists($fPath)) {
                $json = json_decode((string)@file_get_contents($fPath), true);
                if (is_array($json) && !empty($json[$key])) {
                    return (string)$json[$key];
                }
            }
        }
        return '';
    }
}

if (!function_exists('notify_telegram_admin')) {
    function notify_telegram_admin($text) {
        global $config, $_c;
        if (empty($text)) return null;
        try {
            if (class_exists('Message') && method_exists('Message', 'sendTelegram')) {
                return Message::sendTelegram($text);
            } elseif (function_exists('sendTelegram')) {
                return sendTelegram($text);
            }
        } catch (Throwable $e) {}
        return null;
    }
}

if (!function_exists('notify_whatsapp_customer')) {
    function notify_whatsapp_customer($phone, $text) {
        global $config, $_c;
        if (empty($phone) || empty($text)) return null;
        try {
            if (class_exists('Message') && method_exists('Message', 'sendWhatsapp')) {
                return Message::sendWhatsapp($phone, $text);
            } elseif (function_exists('sendWhatsapp')) {
                return sendWhatsapp($phone, $text);
            }
        } catch (Throwable $e) {}
        return null;
    }
}

if (!function_exists('notify_sms_customer')) {
    function notify_sms_customer($phone, $text) {
        global $config, $_c;
        if (empty($phone) || empty($text)) return null;
        try {
            if (class_exists('Message') && method_exists('Message', 'sendSMS')) {
                return Message::sendSMS($phone, $text);
            } elseif (function_exists('sendSMS')) {
                return sendSMS($phone, $text);
            }
        } catch (Throwable $e) {}
        return null;
    }
}

if (!function_exists('sendTelegram')) {
    function sendTelegram($text) {
        if (class_exists('Message') && method_exists('Message', 'sendTelegram')) {
            return Message::sendTelegram($text);
        }
        return null;
    }
}

if (!function_exists('build_web_invoice_receipt')) {
    function build_web_invoice_receipt($in, $cust, $config = null, $note = '') {
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
        $price_num = (float)($in['price'] ?? 0);
        $price_fmt = (class_exists('Lang') && method_exists('Lang', 'moneyFormat'))
            ? Lang::moneyFormat($price_num)
            : ($currency . ' ' . number_format($price_num, 0, ',', '.'));

        $raw_method = trim((string)($in['method'] ?? 'Cash'));
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

        $cur_bal = (float)($cust['balance'] ?? 0);
        $bal_fmt = (class_exists('Lang') && method_exists('Lang', 'moneyFormat'))
            ? Lang::moneyFormat($cur_bal)
            : ($currency . ' ' . number_format($cur_bal, 0, ',', '.'));

        $is_balance = (strtoupper(trim($type)) === 'BALANCE');
        $tpl = get_official_notif_template($is_balance ? 'invoice_balance' : 'invoice_paid');

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

        return [
            'trx_id'          => (int)($in['id'] ?? 0),
            'invoice'         => $inv_no,
            'customer_id'     => (int)($cust['id'] ?? 0),
            'username'        => $cust['username'] ?? ($in['username'] ?? '-'),
            'fullname'        => $cust['fullname'] ?? ($in['username'] ?? '-'),
            'phonenumber'     => $cust['phonenumber'] ?? '',
            'plan_name'       => $plan_name,
            'type'            => $type,
            'price'           => $price_num,
            'price_formatted' => $price_fmt,
            'method'          => $raw_method,
            'routers'         => $in['routers'] ?? '-',
            'recharged_at'    => $rech_date,
            'expiration'      => $exp_date ?: '-',
            'balance_after'   => $cur_bal,
            'note'            => $note,
            'wa_web_text'     => trim($wa_text)
        ];
    }
}

if (!function_exists('ensure_custom_tables')) {
    function ensure_custom_tables() {
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
                    try { $db->exec($sql); } catch (Throwable $e) {}
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
        } catch (Throwable $e) {}
    }
}

if (!function_exists('fmt_bytes')) {
    function fmt_bytes($bytes, $precision = 2) {
        $b = max((float)$bytes, 0);
        if ($b <= 0) return '0 B';
        $u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $pow = floor(($b ? log($b) : 0) / log(1024));
        $pow = min($pow, count($u) - 1);
        $b /= pow(1024, $pow);
        return round($b, $precision) . ' ' . $u[$pow];
    }
}

if (!function_exists('fmt_bps')) {
    function fmt_bps($bps) {
        $b = max((float)$bps, 0);
        if ($b <= 0) return '0 bps';
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
    function parse_ros_rate($val) {
        $s = strtoupper(trim((string)$val));
        if ($s === '' || $s === '0' || $s === '0BPS') return 0.0;
        $num = (float)preg_replace('/[^0-9\.]/', '', $s);
        if (strpos($s, 'G') !== false) return $num * 1000000000.0;
        if (strpos($s, 'M') !== false) return $num * 1000000.0;
        if (strpos($s, 'K') !== false) return $num * 1000.0;
        return $num;
    }
}

if (!function_exists('connect_ros')) {
    function connect_ros($ip_port, $user, $pass) {
        if (class_exists('Mikrotik') && method_exists('Mikrotik', 'getClient')) {
            try {
                $c = @Mikrotik::getClient($ip_port, $user, $pass);
                if ($c) return $c;
            } catch (Throwable $e) {}
        }
        if (class_exists('PEAR2\Net\RouterOS\Client')) {
            try {
                $parts = explode(':', $ip_port);
                return new PEAR2\Net\RouterOS\Client($parts[0], $user, $pass, isset($parts[1]) ? (int)$parts[1] : 8728, false, 4);
            } catch (Throwable $e) {
                return null;
            }
        }
        return null;
    }
}

if (!function_exists('get_gateway_min_amount')) {
    function get_gateway_min_amount($gw_lower, $channel_id) {
        $cid = strtoupper(trim((string)$channel_id));
        if ($gw_lower === 'tripay') {
            if (strpos($cid, 'QRIS') !== false || in_array($cid, ['OVO', 'DANA', 'SHOPEEPAY'])) {
                return 1000;
            }
            return 10000;
        }
        if ($gw_lower === 'duitku') {
            if (in_array($cid, ['SP', 'LQ', 'NQ', 'OV', 'SA', 'DA', 'LA', 'LF'])) {
                return 1000;
            }
            return 10000;
        }
        return 1000;
    }
}

if (!function_exists('get_gateway_channels')) {
    function get_gateway_channels($gw_key, $config, $pg_dir = 'system/paymentgateway') {
        $gw = strtolower(trim((string)$gw_key));
        $cfg_key = $gw . '_channel';
        $json_file = rtrim($pg_dir, '/\\') . DIRECTORY_SEPARATOR . 'channel_' . $gw . '.json';

        if (empty($config[$cfg_key]) || !file_exists($json_file)) {
            return [];
        }

        $admin_checked_ids = array_values(array_filter(array_map('trim', explode(',', $config[$cfg_key]))));
        if (empty($admin_checked_ids)) return [];

        $all_channels = json_decode(@file_get_contents($json_file), true);
        if (!is_array($all_channels)) return [];

        $list = [];
        foreach ($all_channels as $ch) {
            $cid = trim((string)($ch['id'] ?? ''));
            if ($cid !== '' && in_array($cid, $admin_checked_ids)) {
                $list[] = [
                    'id'         => $cid,
                    'name'       => trim((string)($ch['name'] ?? $cid)),
                    'min_amount' => get_gateway_min_amount($gw, $cid)
                ];
            }
        }
        return $list;
    }
}

if (!function_exists('get_active_gateways')) {
    function get_active_gateways($config) {
        global $PAYMENTGATEWAY_PATH;
        $pg_dir = !empty($PAYMENTGATEWAY_PATH) ? $PAYMENTGATEWAY_PATH : 'system/paymentgateway';

        $raw = trim((string)($config['payment_gateway'] ?? ''));
        if ($raw === '' || strtolower($raw) === 'none') {
            return [];
        }

        $pgs = array_values(array_filter(array_map('trim', explode(',', $raw)), function($g) {
            return $g !== '' && strtolower($g) !== 'none';
        }));

        $result = [];
        foreach ($pgs as $gw) {
            $gw_file = rtrim($pg_dir, '/\\') . DIRECTORY_SEPARATOR . $gw . '.php';
            if (!file_exists($gw_file)) continue;

            $gw_lower = strtolower($gw);
            $channel_tpl = rtrim($pg_dir, '/\\') . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . $gw . '_channel.tpl';
            $requires_channel = file_exists($channel_tpl);
            $channels = get_gateway_channels($gw, $config, $pg_dir);

            if ($gw_lower === 'paypal') {
                $cur = !empty($config['paypal_currency']) ? $config['paypal_currency'] : 'USD';
                $desc = 'Pembayaran Internasional PayPal (' . $cur . ')';
            } elseif (!empty($channels)) {
                $names = array_column($channels, 'name');
                $preview = implode(', ', array_slice($names, 0, 4));
                if (count($names) > 4) $preview .= ', dll.';
                $desc = ($requires_channel ? 'Pilih Channel: ' : 'Mendukung: ') . $preview;
            } else {
                $desc = 'Pembayaran Otomatis (' . strtoupper($gw) . ')';
            }

            $result[] = [
                'id'               => $gw,
                'name'             => strtoupper($gw),
                'description'      => $desc,
                'requires_channel' => $requires_channel,
                'channels'         => $channels
            ];
        }
        return $result;
    }
}

if (!function_exists('calculate_order_cost')) {
    function calculate_order_cost($user_id, $plan, $config) {
        $base_price = (float)$plan['price'];
        $is_balance = (strtoupper(trim($plan['type'])) === 'BALANCE' || $plan['routers'] === 'balance');

        if (!$is_balance && ($plan['validity_unit'] ?? '') === 'Period' && class_exists('User') && method_exists('User', 'getAttribute')) {
            $add_inv = User::getAttribute("Invoice", $user_id);
            if (!empty($add_inv) && (float)$add_inv > 0) {
                $base_price = (float)$add_inv;
            }
        }

        $bills = [];
        $add_cost = 0.0;
        if (!$is_balance && class_exists('User') && method_exists('User', 'getBills')) {
            $bill_res = User::getBills($user_id);
            if (is_array($bill_res)) {
                $bills    = $bill_res[0] ?? [];
                $add_cost = (float)($bill_res[1] ?? 0);
            }
        }

        $tax = 0.0;
        $tax_enable = $config['enable_tax'] ?? 'no';
        if ($tax_enable === 'yes' && class_exists('Package') && method_exists('Package', 'tax')) {
            $tax_rate_setting = $config['tax_rate'] ?? null;
            $custom_tax_rate  = isset($config['custom_tax_rate']) ? (float)$config['custom_tax_rate'] : null;
            $tax_rate = ($tax_rate_setting === 'custom') ? $custom_tax_rate : $tax_rate_setting;
            $tax = (float)Package::tax($base_price + $add_cost, $tax_rate);
        }

        return [
            'base_price' => $base_price,
            'add_cost'   => $add_cost,
            'tax'        => $tax,
            'bills'      => $bills,
            'total_cost' => $base_price + $add_cost + $tax
        ];
    }
}

if (!function_exists('qris_crc16')) {
    function qris_crc16($str) {
        $crc = 0xFFFF;
        for ($c = 0, $len = strlen($str); $c < $len; $c++) {
            $crc ^= ord($str[$c]) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) ? ((($crc << 1) ^ 0x1021) & 0xFFFF) : (($crc << 1) & 0xFFFF);
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}

if (!function_exists('to_dynamic_qris')) {
    function to_dynamic_qris($qris_raw, $amount) {
        $qris = trim($qris_raw);
        if (strpos($qris, '000201') !== 0 || strpos($qris, '5802ID') === false) return $qris;
        $amt_str = (string)((int)$amount);
        if ($amt_str <= 0) return $qris;
        $crc_pos = strrpos($qris, '6304');
        if ($crc_pos === false || $crc_pos < strlen($qris) - 8) return $qris;
        $body = preg_replace('/^000201010211/', '000201010212', substr($qris, 0, $crc_pos), 1);
        $parts = explode('5802ID', $body, 2);
        if (count($parts) === 2) {
            $tag54 = '54' . str_pad(strlen($amt_str), 2, '0', STR_PAD_LEFT) . $amt_str;
            if (strpos($parts[0], $tag54) === false) $body = $parts[0] . $tag54 . '5802ID' . $parts[1];
        }
        $body .= '6304';
        return $body . qris_crc16($body);
    }
}

if (!function_exists('verify_already_recharged')) {
    function verify_already_recharged(&$trx) {
        if (!$trx) return false;
        if ((int)$trx['status'] === 2) return true;
        if (!empty($trx['created_date'])) {
            $created_ts = strtotime($trx['created_date']);
            $recent = ORM::for_table('tbl_transactions')
                ->where('username', $trx['username'])
                ->where('plan_name', $trx['plan_name'])
                ->order_by_desc('id')
                ->find_one();
            if ($recent && !empty($recent['recharged_on'])) {
                $rech_ts = strtotime($recent['recharged_on'] . ' ' . ($recent['recharged_time'] ?: '00:00:00'));
                if ($rech_ts >= ($created_ts - 5)) {
                    $trx->status = 2;
                    if (empty($trx->paid_date)) $trx->paid_date = date('Y-m-d H:i:s');
                    if (empty($trx->trx_invoice) && !empty($recent['invoice'])) $trx->trx_invoice = $recent['invoice'];
                    $trx->save();
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('check_and_expire_trx')) {
    function check_and_expire_trx(&$trx) {
        if (!$trx || (int)$trx['status'] !== 1) return false;
        if (verify_already_recharged($trx)) return false;

        $gw_lower = strtolower(trim((string)($trx['gateway'] ?? '')));
        if ($gw_lower !== 'viaqris' && empty($trx['gateway_trx_id']) && empty($trx['pg_url_payment']) && empty($trx['pg_request'])) {
            $trx->status = 4;
            $trx->save();
            return true;
        }

        $now = time();
        if (!empty($trx['expired_date']) && $trx['expired_date'] !== '0000-00-00 00:00:00') {
            $exp_ts = strtotime($trx['expired_date']);
            if ($exp_ts > 0 && $now > $exp_ts) {
                $trx->status = 3;
                $trx->save();
                return true;
            }
        } elseif (!empty($trx['created_date'])) {
            $cr_ts = strtotime($trx['created_date']);
            if ($cr_ts > 0 && ($now - $cr_ts) > 86400) {
                $trx->status = 3;
                $trx->save();
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('parse_qris_trx')) {
    function parse_qris_trx($unpaid, $config) {
        if (!$unpaid) return null;
        check_and_expire_trx($unpaid);

        $gw_raw       = trim((string)($unpaid['gateway'] ?? ''));
        $gw_lower     = strtolower($gw_raw);
        $total_amount = (float)$unpaid['price'];
        $qris_string  = '';
        $qris_image   = '';
        $pay_code     = '';
        $checkout_url = trim((string)($unpaid['pg_url_payment'] ?? ''));
        $instructions = [];
        $method_code  = trim((string)($unpaid['payment_method'] ?? ''));
        $channel_name = trim((string)($unpaid['payment_channel'] ?? ''));
        $currency     = $config['currency_code'] ?? 'Rp';
        $ui_mode      = 'checkout_url';

        $req_json = !empty($unpaid['pg_request']) ? json_decode($unpaid['pg_request'], true) : null;

        if ($gw_lower === 'viaqris') {
            $ui_mode = 'qris';
            $checkout_url = '';
            foreach (['pg_request', 'pg_paid_response'] as $col) {
                if (empty($unpaid[$col])) continue;
                $raw = trim($unpaid[$col]);
                if (preg_match('/(000201[0-9A-Za-z\.\-\s\/\+]{40,600})/', $raw, $m)) $qris_string = trim($m[1]);
                if (preg_match('/(data:image\/[a-zA-Z0-9\+\/\=;:,]+)/', $raw, $m)) $qris_image = trim($m[1]);
                $j = json_decode($raw, true);
                if (is_array($j)) {
                    array_walk_recursive($j, function($val, $key) use (&$total_amount, &$qris_string, &$qris_image) {
                        $k = strtolower((string)$key);
                        $v = trim((string)$val);
                        if (in_array($k, ['amount', 'total', 'nominal', 'total_amount', 'amount_unique', 'bayar']) && is_numeric($v) && (float)$v > 0) {
                            $total_amount = (float)$v;
                        }
                        if (strpos($v, '000201') === 0 && strlen($v) > 40) $qris_string = $v;
                        elseif (strpos($v, 'data:image/') === 0) $qris_image = $v;
                        elseif (in_array($k, ['qr_url', 'qris_url', 'qr_image', 'qris_image']) && strpos($v, 'http') === 0) $qris_image = $v;
                    });
                }
            }
            foreach ($config as $k => $v) {
                if (!is_string($v)) continue;
                $vt = trim($v);
                if (empty($qris_string) && strpos($vt, '000201') === 0) $qris_string = to_dynamic_qris($vt, $total_amount);
                if (empty($qris_image) && stripos($k, 'qris') !== false && (stripos($vt, 'http') === 0 || preg_match('/\.(png|jpg|jpeg|webp)$/i', $vt))) {
                    $qris_image = (stripos($vt, 'http') === 0) ? $vt : rtrim(APP_URL, '/') . '/' . ltrim($vt, '/');
                }
            }
            if (!empty($qris_string)) {
                $qris_string = to_dynamic_qris($qris_string, $total_amount);
                if (empty($qris_image)) {
                    $qris_image = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&margin=20&data=' . urlencode($qris_string);
                }
            }
            if (empty($method_code)) $method_code = 'QRIS';
            if (empty($channel_name)) $channel_name = 'ViaQRIS';
        } elseif ($gw_lower === 'tripay') {
            $tp = (is_array($req_json) && isset($req_json['data']) && is_array($req_json['data'])) ? $req_json['data'] : (is_array($req_json) ? $req_json : []);
            if (!empty($tp['amount']) && is_numeric($tp['amount'])) $total_amount = (float)$tp['amount'];
            if (!empty($tp['payment_method'])) $method_code = trim((string)$tp['payment_method']);
            if (!empty($tp['payment_name'])) $channel_name = trim((string)$tp['payment_name']);
            if (!empty($tp['pay_code'])) $pay_code = trim((string)$tp['pay_code']);

            $pay_url = !empty($tp['pay_url']) ? trim((string)$tp['pay_url']) : '';
            $tp_checkout = !empty($tp['checkout_url']) ? trim((string)$tp['checkout_url']) : '';

            if (!empty($tp['instructions']) && is_array($tp['instructions'])) {
                foreach ($tp['instructions'] as $ins) {
                    if (!empty($ins['title']) && !empty($ins['steps']) && is_array($ins['steps'])) {
                        $clean_steps = array_map(function($s) { return trim(strip_tags((string)$s)); }, $ins['steps']);
                        $instructions[] = [
                            'title' => trim(strip_tags((string)$ins['title'])),
                            'steps' => array_values(array_filter($clean_steps))
                        ];
                    }
                }
            }

            $m_up = strtoupper($method_code);
            if (strpos($m_up, 'QRIS') !== false || !empty($tp['qr_string']) || !empty($tp['qr_url'])) {
                $ui_mode = 'qris';
                $checkout_url = '';
                $qris_string = trim((string)($tp['qr_string'] ?? ''));
                $qris_image  = trim((string)($tp['qr_url'] ?? ''));
                if (!empty($qris_string) && empty($qris_image)) {
                    $qris_image = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&margin=20&data=' . urlencode($qris_string);
                }
            } elseif (!empty($pay_code)) {
                $ui_mode = 'va_code';
                $checkout_url = '';
            } else {
                $ui_mode = 'ewallet_link';
                $checkout_url = !empty($pay_url) ? $pay_url : $tp_checkout;
            }
        } elseif ($gw_lower === 'duitku') {
            $dk = is_array($req_json) ? $req_json : [];
            if (!empty($dk['amount']) && is_numeric($dk['amount'])) $total_amount = (float)$dk['amount'];
            if (!empty($dk['vaNumber'])) $pay_code = trim((string)$dk['vaNumber']);
            $dk_url = !empty($dk['paymentUrl']) ? trim((string)$dk['paymentUrl']) : $checkout_url;

            $m_up = strtoupper($method_code);
            if (in_array($m_up, ['SP', 'LQ', 'NQ']) || !empty($dk['qrString'])) {
                $ui_mode = 'qris';
                $checkout_url = '';
                $qris_string = trim((string)($dk['qrString'] ?? ''));
                if (!empty($qris_string)) {
                    $qris_image = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&margin=20&data=' . urlencode($qris_string);
                }
            } elseif (!empty($pay_code)) {
                $ui_mode = 'va_code';
                $checkout_url = '';
            } else {
                $ui_mode = 'ewallet_link';
                $checkout_url = $dk_url;
            }
        } elseif ($gw_lower === 'xendit') {
            $ui_mode = 'checkout_url';
            $xd = is_array($req_json) ? $req_json : [];
            if (!empty($xd['amount']) && is_numeric($xd['amount'])) $total_amount = (float)$xd['amount'];
            if (!empty($xd['invoice_url'])) $checkout_url = trim((string)$xd['invoice_url']);
            if (empty($method_code)) $method_code = 'XENDIT';
            if (empty($channel_name)) $channel_name = 'Xendit Invoice';
        } elseif ($gw_lower === 'paypal') {
            $ui_mode  = 'checkout_url';
            $currency = !empty($config['paypal_currency']) ? strtoupper(trim($config['paypal_currency'])) : 'USD';
            if (empty($method_code)) $method_code = 'PAYPAL';
            if (empty($channel_name)) $channel_name = 'PayPal (' . $currency . ')';
        } else {
            $ui_mode = 'checkout_url';
            if (empty($method_code)) $method_code = strtoupper($gw_raw);
            if (empty($channel_name)) $channel_name = strtoupper($gw_raw);
        }

        $st = (int)$unpaid['status'];
        $st_label = ($st === 2) ? 'LUNAS' : (($st === 3) ? 'EXPIRED' : (($st === 4) ? 'DIBATALKAN' : 'MENUNGGU PEMBAYARAN'));

        return [
            'id'              => (int)$unpaid['id'],
            'username'        => $unpaid['username'],
            'plan_id'         => (int)$unpaid['plan_id'],
            'plan_name'       => $unpaid['plan_name'],
            'price'           => $total_amount,
            'base_price'      => (float)$unpaid['price'],
            'currency'        => $currency,
            'gateway'         => $gw_raw,
            'gateway_trx_id'  => $unpaid['gateway_trx_id'] ?? '',
            'ui_mode'         => $ui_mode,
            'payment_method'  => $method_code ?: strtoupper($gw_raw),
            'payment_channel' => $channel_name ?: strtoupper($gw_raw),
            'pg_url_payment'  => $checkout_url,
            'checkout_url'    => $checkout_url,
            'pay_code'        => $pay_code,
            'qris_string'     => $qris_string,
            'qris_image'      => $qris_image,
            'instructions'    => $instructions,
            'status'          => $st,
            'status_label'    => $st_label,
            'expired_date'    => $unpaid['expired_date'] ?: '-',
            'created_date'    => $unpaid['created_date'] ?: '-',
            'paid_date'       => $unpaid['paid_date'] ?: '-',
            'trx_invoice'     => $unpaid['trx_invoice'] ?: ('INV-' . $unpaid['id'])
        ];
    }
}

if (empty($GLOBALS['MOBILE_API_SHUTDOWN_REGISTERED'])) {
    $GLOBALS['MOBILE_API_SHUTDOWN_REGISTERED'] = true;
    register_shutdown_function(function() {
        if (!empty($GLOBALS['API_LOCK_HANDLE']) && is_resource($GLOBALS['API_LOCK_HANDLE'])) {
            @flock($GLOBALS['API_LOCK_HANDLE'], LOCK_UN);
            @fclose($GLOBALS['API_LOCK_HANDLE']);
            $GLOBALS['API_LOCK_HANDLE'] = null;
        }
        if (!empty($GLOBALS['API_INTERCEPT_TRX_ID'])) {
            $trx_id = $GLOBALS['API_INTERCEPT_TRX_ID'];
            $mode   = $GLOBALS['API_INTERCEPT_MODE'] ?? 'buy';
            $cfg    = $GLOBALS['config'] ?? [];
            $GLOBALS['API_INTERCEPT_TRX_ID'] = null;

            if (ob_get_length()) ob_clean();
            if (!headers_sent()) {
                header_remove('Location');
                header_remove('location');
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(200);
            }

            $trx = ORM::for_table('tbl_payment_gateway')->find_one($trx_id);
            $ntype = $_SESSION['ntype'] ?? 's';
            $notify = isset($_SESSION['notify']) ? trim(strip_tags($_SESSION['notify'])) : '';
            unset($_SESSION['notify'], $_SESSION['ntype']);

            if ($mode === 'check_payment') {
                $is_paid = verify_already_recharged($trx);
                $payload = ['paid' => $is_paid, 'bill' => parse_qris_trx($trx, $cfg)];
                echo json_encode([
                    'success' => true, 'status' => true,
                    'message' => $is_paid ? 'Pembayaran diterima! Paket sudah aktif.' : ($notify ?: 'Pembayaran belum masuk.'),
                    'result'  => $payload, 'data' => $payload
                ], JSON_UNESCAPED_SLASHES);
                return;
            }

            $gw_lower = strtolower(trim((string)($trx['gateway'] ?? '')));
            $has_valid_pg_output = ($gw_lower === 'viaqris')
                ? (!empty($trx['pg_url_payment']) || !empty($trx['pg_request']) || $ntype === 's')
                : (!empty($trx['gateway_trx_id']) || !empty($trx['pg_url_payment']));

            if ($trx && $ntype === 's' && $has_valid_pg_output) {
                $payload = parse_qris_trx($trx, $cfg);
                echo json_encode([
                    'success' => true, 'status' => true,
                    'message' => $notify ?: 'Tagihan pembayaran berhasil dibuat.',
                    'result'  => $payload, 'data' => $payload
                ], JSON_UNESCAPED_SLASHES);
            } else {
                if ($trx && (int)$trx['status'] === 1 && empty($trx['gateway_trx_id']) && empty($trx['pg_url_payment'])) {
                    $trx->delete();
                }
                echo json_encode([
                    'success' => false, 'status' => false,
                    'message' => $notify ?: 'Gagal membuat transaksi ke Payment Gateway. Pastikan metode/channel pembayaran sudah aktif di Merchant.',
                    'result'  => null, 'data' => null
                ], JSON_UNESCAPED_SLASHES);
            }
            return;
        }

        // Tangkap Fatal Error PHP jika belum ada JSON yang dikirim oleh api_out()
        if (empty($GLOBALS['MOBILE_API_OUTPUT_SENT'])) {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
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
                ], JSON_UNESCAPED_SLASHES);
            }
        }
    });
}
