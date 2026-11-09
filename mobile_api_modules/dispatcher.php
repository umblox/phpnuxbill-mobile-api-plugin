<?php
/**
 * PHPNuxBill Mobile API Plugin - Main Dispatcher
 * Path: system/plugin/mobile_api_modules/dispatcher.php
 * Dipanggil melalui gerbang resmi system/api.php
 */

global $config, $admin, $user, $ui, $routes, $_app_stage, $DEVICE_PATH, $PAYMENTGATEWAY_PATH, $UPLOAD_PATH, $CACHE_PATH, $isApi, $zero, $isChangePlan;

$isApi = true;
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Pastikan working directory berada di root instalasi PHPNuxBill (penting saat dipanggil dari system/api.php)
$phpnux_root = dirname(__DIR__, 3);
if (is_dir($phpnux_root . '/system') && getcwd() !== $phpnux_root) {
    @chdir($phpnux_root);
}

if (empty($api_secret)) {
    $api_secret = !empty($config['tawkto']) ? $config['tawkto'] : 'Arneta_WiFi_Secret_Key_2026_#99';
}

// Parse JSON body jika request dikirim dengan Content-Type: application/json
$raw_input = json_decode(file_get_contents('php://input'), true);
if (is_array($raw_input)) {
    $_POST    = array_merge($_POST, $raw_input);
    $_REQUEST = array_merge($_REQUEST, $raw_input);
}

// Muat seluruh fungsi helper bersama
require_once __DIR__ . '/helpers_common.php';

// Resolusi parameter $action dari system/api.php (?r=plugin/mobile_api/<action> atau ?r=apiv1/<action> atau &action=<action>)
$_raw_r = trim($_GET['r'] ?? $_POST['r'] ?? $_GET['_route'] ?? '');
$_r_parts = array_values(array_filter(explode('/', $_raw_r)));

if (!empty($_REQUEST['action'])) {
    $action = trim((string)$_REQUEST['action']);
} elseif (isset($_r_parts[0]) && $_r_parts[0] === 'plugin' && !empty($_r_parts[2])) {
    $action = trim((string)$_r_parts[2]);
} elseif (isset($_r_parts[0]) && $_r_parts[0] !== 'plugin' && !empty($_r_parts[1])) {
    $action = trim((string)$_r_parts[1]);
} elseif (isset($routes[1]) && !empty($routes[1]) && $routes[1] !== 'mobile_api') {
    $action = trim((string)$routes[1]);
} elseif (isset($routes[2]) && !empty($routes[2])) {
    $action = trim((string)$routes[2]);
} else {
    $action = 'index';
}

// Pemetaan $action ke file modul terkait
switch ($action) {
    // 1. Modul Auth, SSO WebView & Dashboard Admin
    case 'auth':
    case 'admin_login':
    case 'customer_login':
    case 'webview_sso':
    case 'admin_webview_sso':
    case 'admin_dashboard':
    case 'dashboard_extras':
        require __DIR__ . '/core_auth_dashboard.php';
        break;

    // 2. Modul Manajemen Pelanggan
    case 'admin_customers':
    case 'admin_customer_save':
    case 'admin_customer_delete':
    case 'admin_customer_sync':
        require __DIR__ . '/core_customers.php';
        break;

    // 3. Modul Layanan Aktif, Recharge, Deposit, Voucher & Resend WA Invoice
    case 'admin_prepaid_list':
    case 'admin_prepaid_recharge':
    case 'admin_prepaid_delete':
    case 'admin_prepaid_edit':
    case 'admin_prepaid_extend':
    case 'admin_deposit':
    case 'admin_vouchers':
    case 'admin_voucher_generate':
    case 'admin_voucher_delete':
    case 'admin_voucher_refill':
    case 'admin_resend_invoice_wa':
        require __DIR__ . '/core_services_vouchers.php';
        break;

    // 4. Modul Paket Internet & Bandwidth
    case 'admin_plans':
    case 'admin_plan_save':
    case 'admin_plan_delete':
    case 'admin_plan_sync':
    case 'admin_bandwidths':
    case 'admin_bandwidth_save':
    case 'admin_bandwidth_delete':
        require __DIR__ . '/core_plans_bandwidth.php';
        break;

    // 5. Modul Jaringan, Router, IP Pool & MikroTik Live Monitor
    case 'admin_routers':
    case 'admin_router_save':
    case 'admin_router_delete':
    case 'admin_pools':
    case 'admin_pool_save':
    case 'admin_pool_delete':
    case 'mikrotik_live':
    case 'admin_router_status':
    case 'online_users':
    case 'admin_online_users':
    case 'data_usage':
    case 'admin_data_usage':
    case 'mikrotik_extra':
    case 'user_live_traffic':
    case 'admin_user_live_traffic':
    case 'kick_user':
    case 'admin_kick_user':
    case 'router_reboot':
    case 'admin_router_reboot':
        require __DIR__ . '/core_network_mikrotik.php';
        break;

    // 6. Modul Laporan Keuangan, Kirim Pesan Broadcast & Halaman Statis
    case 'admin_reports':
    case 'admin_send_message':
    case 'pages':
    case 'admin_pages':
    case 'page_save':
    case 'admin_page_save':
        require __DIR__ . '/core_reports_messages.php';
        break;

    // 7. Modul Pengaturan Sistem, Lokalisasi, Maintenance, Notifikasi, Staf, DB & Log
    case 'admin_settings':
    case 'admin_settings_save':
    case 'admin_test_notif':
    case 'admin_notifications_get':
    case 'admin_notifications_save':
    case 'admin_db_status':
    case 'admin_db_backup':
    case 'admin_staff_list':
    case 'admin_staff_save':
    case 'admin_staff_delete':
    case 'admin_change_password':
    case 'admin_logs':
    case 'system_tools':
    case 'admin_system_tools':
        require __DIR__ . '/core_settings_staff.php';
        break;

    // 8. Modul Plugin: Peta, Kupon Promo, Asset Manager & Audit Payment Gateway
    case 'map_all':
    case 'admin_map_data':
    case 'coupons':
    case 'admin_coupons':
    case 'admin_coupon_save':
    case 'admin_coupon_status':
    case 'admin_coupon_delete':
    case 'assets':
    case 'admin_assets':
    case 'asset_save':
    case 'admin_asset_save':
    case 'asset_delete':
    case 'admin_asset_delete':
    case 'pg_action':
    case 'admin_payment_gateways':
    case 'admin_pg_action':
        require __DIR__ . '/plugin_coupons_assets_pg.php';
        break;

    // 9. Modul Plugin GenieACS TR-069 (Admin & Customer)
    case 'admin_genieacs_hosts':
    case 'admin_genieacs_host_save':
    case 'admin_genieacs_host_action':
    case 'admin_genieacs_dashboard':
    case 'admin_genieacs_devices':
    case 'admin_genieacs_device_detail':
    case 'admin_genieacs_device_action':
    case 'admin_genieacs_wan_save':
    case 'admin_genieacs_wlan_save':
    case 'customer_genieacs_dashboard':
    case 'customer_genieacs_wlan_save':
    case 'customer_genieacs_reboot':
        require __DIR__ . '/plugin_genieacs.php';
        break;

    // 10. Modul Portal APK Pelanggan
    case 'login':
    case 'dashboard':
    case 'plans':
    case 'transactions':
    case 'toggle_renewal':
    case 'redeem_voucher':
    case 'buy':
    case 'qris_buy':
    case 'payment_detail':
    case 'check_payment':
    case 'qris_status':
    case 'cancel_payment':
    case 'qris_cancel':
        require __DIR__ . '/customer_portal_api.php';
        break;

    default:
        api_out(true, 'PHPNuxBill Mobile REST API Plugin Ready (Connected via system/api.php)', [
            'version' => '2.5.0',
            'action'  => $action
        ]);
        break;
}
