<?php

/**
 * PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 * Plugin Name: PHPNuxBill Mobile API Bridge
 * Lokasi: system/plugin/mobile_api.php
 **/

// 1. Daftarkan menu manajemen Mobile API di Sidebar Web Admin (ringan, 0 query DB)
if (function_exists('register_menu')) {
    register_menu(
        "Mobile App API",
        true,
        "mobile_api_config",
        'AFTER_SETTINGS',
        'glyphicon glyphicon-phone',
        'API',
        'green',
        ['SuperAdmin', 'Admin']
    );
}

// 2. Endpoint resmi dipanggil otomatis oleh system/controllers/plugin.php
//    Baik melalui system/api.php?r=plugin/mobile_api maupun ?_route=plugin/mobile_api
function mobile_api()
{
    $dispatcherPath = __DIR__ . '/mobile_api_modules/dispatcher.php';
    if (!is_file($dispatcherPath)) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'status'  => false,
            'message' => 'Modul mobile_api_modules/dispatcher.php tidak ditemukan di server.',
            'result'  => null,
            'data'    => null
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once $dispatcherPath;
    exit;
}

// 3. Halaman Status & Pengaturan Mobile API di Web Admin (?_route=plugin/mobile_api_config)
function mobile_api_config()
{
    global $ui, $config, $_c, $admin;
    _admin();

    $cfg = (!empty($config) && is_array($config)) ? $config : (is_array($_c ?? null) ? $_c : []);

    // Hitung Root URL murni (mendukung Reverse Proxy / Cloudflare HTTPS)
    $appRootUrl = defined('APP_URL') ? trim((string) APP_URL) : '';
    if (empty($appRootUrl)) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);

        $scheme     = $isHttps ? 'https' : 'http';
        $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script     = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $dir        = rtrim(preg_replace('#/system$#i', '', dirname($script)), '/');
        $appRootUrl = "{$scheme}://{$host}{$dir}";
    }
    $appRootUrl = rtrim(preg_replace('#/index\.php$#i', '', $appRootUrl), '/');

    $moduleDescriptions = [
        'dispatcher.php'               => 'Router Utama & Gerbang Otorisasi Request Mobile API',
        'helpers_common.php'           => 'Helper Global, Parser Token, & Jembatan Notifikasi Resmi Web (Message Class)',
        'core_auth_dashboard.php'      => 'Autentikasi Admin, Statistik Dasbor, Grafik Bulanan & Widget Expired',
        'core_customers.php'           => 'Manajemen Pelanggan (CRUD, Custom Fields, Sync Router & Welcome Message)',
        'core_services_vouchers.php'   => 'Layanan Aktif, Isi Ulang Paket/Saldo, Generate Voucher, Refill & Struk Resmi',
        'core_plans_bandwidth.php'     => 'Manajemen Paket Hotspot/PPPoE/Saldo/VPN, Bandwidth, Burst & Sync Router',
        'core_network_mikrotik.php'    => 'MikroTik Live Traffic Monitor, Resource Health, User Online, Kick & Log',
        'core_reports_messages.php'    => 'Laporan Keuangan, Kirim Pesan Personal, Broadcast Massal & Halaman Statis',
        'core_settings_staff.php'      => 'Pengaturan Sistem, Template notifications.json, Tes Gateway, Staf & Backup DB',
        'plugin_coupons_assets_pg.php' => 'Kupon Promo, Inventaris Asset Manager, Peta Koordinat & Audit Payment Gateway',
        'plugin_genieacs.php'          => 'GenieACS TR-069 Pro (HTTP/HTTPS, Diagnostik Optik PON, WAN, WLAN & Portal ONT)',
        'customer_portal_api.php'      => 'Endpoint Khusus Aplikasi Pelanggan (Home, Beli Paket, Tagihan, Voucher & Inbox)'
    ];

    $modulesDir  = __DIR__ . '/mobile_api_modules';
    $modulesList = [];
    $readyCount  = 0;

    foreach ($moduleDescriptions as $fileName => $desc) {
        $fullPath = $modulesDir . '/' . $fileName;
        $exists   = is_file($fullPath) && filesize($fullPath) > 0;
        $sizeKb   = $exists ? round((filesize($fullPath) ?: 0) / 1024, 1) : 0;
        if ($exists) {
            $readyCount++;
        }
        $modulesList[] = [
            'name'        => $fileName,
            'description' => $desc,
            'size'        => $sizeKb . ' KB',
            'is_ready'    => $exists
        ];
    }

    $extraFiles = glob($modulesDir . '/*.php') ?: [];
    foreach ($extraFiles as $fPath) {
        $bName = basename($fPath);
        if (isset($moduleDescriptions[$bName])) {
            continue;
        }
        $exists = is_file($fPath) && filesize($fPath) > 0;
        $sizeKb = $exists ? round((filesize($fPath) ?: 0) / 1024, 1) : 0;
        if ($exists) {
            $readyCount++;
        }
        $modulesList[] = [
            'name'        => $bName,
            'description' => 'Modul Ekstensi Tambahan Mobile API',
            'size'        => $sizeKb . ' KB',
            'is_ready'    => $exists
        ];
    }

    $gatewaysStatus = [
        'wa'       => !empty($cfg['wa_url']),
        'telegram' => !empty($cfg['telegram_bot']) && !empty($cfg['telegram_target_id']),
        'sms'      => !empty($cfg['sms_url']),
        'email'    => !empty($cfg['smtp_host']) || !empty($cfg['mail_from']),
        'curl'     => function_exists('curl_init')
    ];

    $ui->assign('_title', 'Mobile App API Configuration');
    $ui->assign('_system_menu', 'settings');
    $ui->assign('_admin', $admin);
    $ui->assign('api_version', '3.0.0');
    $ui->assign('app_root_url', $appRootUrl);
    $ui->assign('modules', $modulesList);
    $ui->assign('ready_modules_count', $readyCount);
    $ui->assign('gw_status', $gatewaysStatus);

    $tplFile = 'mobile_api_config.tpl';
    if (method_exists($ui, 'templateExists') && !$ui->templateExists($tplFile)) {
        $fallbackTpl = __DIR__ . '/ui/mobile_api_config.tpl';
        if (is_file($fallbackTpl)) {
            $tplFile = $fallbackTpl;
        }
    }
    $ui->display($tplFile);
}

// 4. Penyelarasan gerbang resmi system/api.php & fallback index.php (0 overhead bagi web browser)
$_script_base = basename($_SERVER['SCRIPT_NAME'] ?? '');
$_raw_r       = trim((string) ($_GET['r'] ?? $_POST['r'] ?? ''));

if ($_script_base === 'api.php' && strpos($_raw_r, 'plugin/mobile_api') === 0) {
    // Pastikan parameter r terbaca oleh _get('r') di system/api.php meskipun dikirim via POST
    if (empty($_GET['r']) && !empty($_POST['r'])) {
        $_GET['r'] = $_POST['r'];
    }
    // Pindahkan parameter ?token=... ke HTTP_AUTHORIZATION agar system/api.php tidak menolak
    // token Base64 HMAC sebelum meneruskan eksekusi ke system/controllers/plugin.php -> mobile_api()
    if (!empty($_REQUEST['token'])) {
        $incomingTok = trim((string) $_REQUEST['token']);
        if (empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $incomingTok;
        }
        unset($_GET['token'], $_POST['token'], $_REQUEST['token']);
    }
} elseif ($_script_base === 'index.php' && empty($_GET['_route']) && strpos($_raw_r, 'plugin/mobile_api') === 0) {
    // Dukungan fallback jika server mengaktifkan .htaccess_firewall yang memblokir system/api.php
    $_GET['_route']     = $_raw_r;
    $_REQUEST['_route'] = $_raw_r;
}
