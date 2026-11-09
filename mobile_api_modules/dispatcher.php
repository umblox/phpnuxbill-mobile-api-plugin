<?php
/**
 * Pintu Pengatur Rute (Dispatcher) Mobile API Plugin
 * Kompatibel dengan system/api.php resmi PHPNuxBill
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$phpnux_root = dirname(__DIR__, 3);
if (is_dir($phpnux_root . '/system') && getcwd() !== $phpnux_root) {
    @chdir($phpnux_root);
}

if (empty($api_secret)) {
    $api_secret = !empty($config['tawkto']) ? $config['tawkto'] : 'Arneta_WiFi_Secret_Key_2026_#99';
}

$raw_input = json_decode(file_get_contents('php://input'), true);
if (is_array($raw_input)) {
    $_POST = array_merge($_POST, $raw_input);
    $_REQUEST = array_merge($_REQUEST, $raw_input);
}

// Tangkap action dari parameter URL, r, _route, atau post
$action = isset($routes[1]) && !empty($routes[1]) ? $routes[1] : (_req('action') ?: _req('r') ?: 'index');
if ($action === 'mobile_api' || $action === 'plugin/mobile_api') {
    $action = isset($routes[2]) && !empty($routes[2]) ? $routes[2] : (_req('action') ?: 'index');
}

// Muat fungsi helper umum
require_once __DIR__ . '/helpers_common.php';

// Route pemanggilan modul modular berdasarkan kelompok action
switch (true) {
    case in_array($action, ['auth', 'admin_login', 'customer_login', 'admin_dashboard', 'dashboard_extras']):
        require_once __DIR__ . '/core_auth_dashboard.php';
        break;
    case strpos($action, 'admin_customer') === 0 || $action === 'admin_customers':
        require_once __DIR__ . '/core_customers.php';
        break;
    case strpos($action, 'admin_prepaid') === 0 || strpos($action, 'admin_voucher') === 0 || strpos($action, 'admin_deposit') === 0 || $action === 'admin_vouchers':
        require_once __DIR__ . '/core_services_vouchers.php';
        break;
    case strpos($action, 'admin_plan') === 0 || strpos($action, 'admin_bandwidth') === 0 || $action === 'admin_plans' || $action === 'admin_bandwidths':
        require_once __DIR__ . '/core_plans_bandwidth.php';
        break;
    case strpos($action, 'mikrotik') === 0 || in_array($action, ['online_users', 'admin_online_users', 'data_usage', 'admin_data_usage', 'user_live_traffic', 'admin_user_live_traffic', 'kick_user', 'admin_kick_user', 'router_reboot', 'admin_router_reboot', 'admin_routers', 'admin_router_save', 'admin_router_delete', 'admin_pools', 'admin_pool_save', 'admin_pool_delete']):
        require_once __DIR__ . '/core_network_mikrotik.php';
        break;
    case in_array($action, ['admin_reports', 'admin_send_message', 'pages', 'admin_pages', 'page_save', 'admin_page_save']):
        require_once __DIR__ . '/core_reports_messages.php';
        break;
    case strpos($action, 'admin_settings') === 0 || strpos($action, 'admin_staff') === 0 || in_array($action, ['admin_test_notif', 'admin_notifications_get', 'admin_notifications_save', 'admin_db_status', 'admin_change_password', 'admin_logs', 'system_tools', 'admin_system_tools']):
        require_once __DIR__ . '/core_settings_staff.php';
        break;
    case in_array($action, ['map_all', 'admin_map_data', 'coupons', 'admin_coupons', 'admin_coupon_save', 'admin_coupon_status', 'admin_coupon_delete', 'assets', 'admin_assets', 'asset_save', 'admin_asset_save', 'asset_delete', 'admin_asset_delete', 'pg_action', 'admin_payment_gateways', 'admin_pg_action']):
        require_once __DIR__ . '/plugin_payment_gateways.php'; // atau plugin_coupons_assets_pg.php
        break;
    case strpos($action, 'genieacs') === 0:
        require_once __DIR__ . '/plugin_genieacs.php';
        break;
    case in_array($action, ['login', 'dashboard', 'plans', 'transactions', 'toggle_renewal', 'redeem_voucher', 'buy', 'payment_detail', 'check_payment', 'cancel_payment', 'qris_buy', 'qris_status', 'qris_cancel']):
        require_once __DIR__ . '/customer_portal_api.php';
        break;
    case in_array($action, ['webview_sso', 'admin_webview_sso']):
        require_once __DIR__ . '/core_auth_dashboard.php';
        break;
    default:
        // Coba cari langsung di file modul core yang ada
        $mod_files = glob(__DIR__ . '/*.php') ?: [];
        $handled = false;
        foreach ($mod_files as $mf) {
            if (basename($mf) === 'dispatcher.php' || basename($mf) === 'helpers_common.php') continue;
            // Setiap modul akan menangani action-nya masing-masing
        }
        if (!$handled) {
            api_out(true, 'PHPNuxBill Mobile API Ready (Modular Plugin)');
        }
        break;
}
