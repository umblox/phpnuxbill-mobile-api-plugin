<?php
/**
 * PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 * Plugin Name: PHPNuxBill Mobile API Bridge
 * Lokasi: system/plugin/mobile_api.php
 */

// 1. Daftarkan menu manajemen Mobile API di Sidebar Web Admin
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

// 2. Endpoint resmi via system/controllers/plugin.php (?_route=plugin/mobile_api)
function mobile_api()
{
    require_once __DIR__ . '/mobile_api_modules/dispatcher.php';
    exit;
}

// 3. Halaman Status & Pengaturan Mobile API di Web Admin (?_route=plugin/mobile_api_config)
function mobile_api_config()
{
    global $ui, $config, $admin;
    _admin();

    // Hitung Root URL murni tanpa akhiran ?_route= atau index.php
    $appRootUrl = defined('APP_URL') ? APP_URL : '';
    if (empty($appRootUrl)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $appRootUrl = "{$scheme}://{$host}{$dir}";
    }
    $appRootUrl = rtrim($appRootUrl, '/');

    $moduleDescriptions = [
        'dispatcher.php'            => 'Router Utama & Gerbang Otorisasi Request Mobile API',
        'helpers_common.php'        => 'Helper Global, Parser Token, & Jembatan Notifikasi Resmi Web (Message Class)',
        'core_auth_dashboard.php'   => 'Autentikasi Admin, Statistik Dasbor, Grafik Bulanan & Widget Expired',
        'core_customers.php'        => 'Manajemen Pelanggan (CRUD, Custom Fields, Sync Router & Welcome Message)',
        'core_services_vouchers.php'=> 'Layanan Aktif, Isi Ulang Paket/Saldo, Generate Voucher, Refill & Struk Resmi',
        'core_plans_bandwidth.php'  => 'Manajemen Paket Hotspot/PPPoE/Saldo/VPN, Bandwidth, Burst & Sync Router',
        'core_network_mikrotik.php' => 'MikroTik Live Traffic Monitor, Resource Health, User Online, Kick & Log',
        'core_reports_messages.php' => 'Laporan Keuangan, Kirim Pesan Personal, Broadcast Massal & Halaman Statis',
        'core_settings_staff.php'   => 'Pengaturan Sistem, Template notifications.json, Tes Gateway, Staf & Backup DB',
        'plugin_coupons_assets_pg.php' => 'Kupon Promo, Inventaris Asset Manager, Peta Koordinat & Audit Payment Gateway',
        'plugin_genieacs.php'       => 'GenieACS TR-069 Pro (HTTP/HTTPS, Diagnostik Optik PON, WAN, WLAN & Portal ONT)',
        'customer_portal_api.php'   => 'Endpoint Khusus Aplikasi Pelanggan (Home, Beli Paket, Tagihan, Voucher & Inbox)'
    ];

    $files = glob(__DIR__ . '/mobile_api_modules/*.php') ?: [];
    $modulesList = [];
    foreach ($files as $fPath) {
        $bName = basename($fPath);
        $sizeKb = round((filesize($fPath) ?: 0) / 1024, 1);
        $modulesList[] = [
            'name'        => $bName,
            'description' => $moduleDescriptions[$bName] ?? 'Modul Ekstensi Mobile API',
            'size'        => $sizeKb . ' KB'
        ];
    }

    // Cek status konfigurasi jalur notifikasi resmi web PHPNuxBill
    $gatewaysStatus = [
        'wa'       => !empty($config['wa_url']),
        'telegram' => !empty($config['telegram_bot']) && !empty($config['telegram_target_id']),
        'sms'      => !empty($config['sms_url']),
        'email'    => !empty($config['smtp_host']) || !empty($config['mail_from']),
        'curl'     => function_exists('curl_init')
    ];

    $ui->assign('_title', 'Mobile App API Configuration');
    $ui->assign('_system_menu', 'settings');
    $ui->assign('_admin', $admin);
    $ui->assign('api_version', '3.0.0');
    $ui->assign('app_root_url', $appRootUrl);
    $ui->assign('modules', $modulesList);
    $ui->assign('gw_status', $gatewaysStatus);
    $ui->display('mobile_api_config.tpl');
}

// 4. Tangkap panggilan API dari Aplikasi Mobile (?r=plugin/mobile_api atau ?r=mobile_api)
$_req_route = trim($_GET['r'] ?? $_POST['r'] ?? $_GET['_route'] ?? '');
if (
    strpos($_req_route, 'mobile_api') === 0 ||
    strpos($_req_route, 'plugin/mobile_api') === 0
) {
    if ($_req_route !== 'plugin/mobile_api_config' && $_req_route !== 'mobile_api_config') {
        require_once __DIR__ . '/mobile_api_modules/dispatcher.php';
        exit;
    }
}
