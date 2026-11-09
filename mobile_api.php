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

    $modules = glob(__DIR__ . '/mobile_api_modules/*.php') ?: [];
    $moduleNames = array_map('basename', $modules);

    $ui->assign('_title', 'Mobile App API Configuration');
    $ui->assign('_system_menu', 'settings');
    $ui->assign('_admin', $admin);
    $ui->assign('api_version', '2.5.0');
    $ui->assign('modules', $moduleNames);
    $ui->display('mobile_api_config.tpl');
}

// 4. Tangkap otomatis panggilan API dari Aplikasi Mobile (?r=mobile_api atau ?r=apiv1)
//    Eksekusi berhenti di sini (exit) sehingga system/controllers/apiv1.php tidak diperlukan lagi
$_req_route = trim($_GET['r'] ?? $_POST['r'] ?? $_GET['_route'] ?? '');
if (
    strpos($_req_route, 'mobile_api') === 0 ||
    strpos($_req_route, 'plugin/mobile_api') === 0 ||
    strpos($_req_route, 'apiv1') === 0
) {
    if ($_req_route !== 'plugin/mobile_api_config') {
        require_once __DIR__ . '/mobile_api_modules/dispatcher.php';
        exit;
    }
}
