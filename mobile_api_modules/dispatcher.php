<?php
/**
 * PHPNuxBill Mobile API Plugin - Main Dispatcher
 * Path: system/plugin/mobile_api_modules/dispatcher.php
 * Dipanggil melalui gerbang resmi system/api.php?r=plugin/mobile_api
 **/

global $config, $_c, $admin, $user, $ui, $routes, $_app_stage, $root_path, $lan_file, $_L, $DEVICE_PATH, $PAYMENTGATEWAY_PATH, $UPLOAD_PATH, $CACHE_PATH, $PLUGIN_PATH, $isApi, $zero, $isChangePlan, $phpnux_root, $api_secret;

$isApi = true;

if (ob_get_level() === 0) {
    ob_start();
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-App-Lang');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Muat bootstrap & seluruh fungsi helper bersama
require_once __DIR__ . '/helpers_common.php';

// Parse JSON body jika request dikirim dengan Content-Type: application/json
$raw_input = file_get_contents('php://input');
if ($raw_input !== false && trim($raw_input) !== '') {
    $content_type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    $trimmed_raw  = ltrim($raw_input);
    if (strpos($content_type, 'application/json') !== false || ($trimmed_raw !== '' && ($trimmed_raw[0] === '{' || $trimmed_raw[0] === '['))) {
        $json_input = json_decode($raw_input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            api_out(false, 'Format JSON pada body request tidak valid: ' . json_last_error_msg(), null, 400);
        }
        if (is_array($json_input)) {
            foreach ($json_input as $k => $v) {
                $_POST[$k]    = $v;
                $_REQUEST[$k] = $v;
            }
        }
    }
}

// Gunakan $api_secret global PHPNuxBill jika tersedia, atau fallback ke mobile_api_secret di tbl_appconfig
if (empty($api_secret)) {
    if (!empty($config['mobile_api_secret'])) {
        $api_secret = $config['mobile_api_secret'];
    } else {
        $api_secret = 'PHPNuxBill_Mobile_API_' . md5(__DIR__);
        try {
            $sec_row = ORM::for_table('tbl_appconfig')->where('setting', 'mobile_api_secret')->find_one();
            if ($sec_row && !empty($sec_row['value'])) {
                $api_secret = $sec_row['value'];
            } else {
                if (!$sec_row) {
                    $sec_row          = ORM::for_table('tbl_appconfig')->create();
                    $sec_row->setting = 'mobile_api_secret';
                }
                $sec_row->value = $api_secret;
                $sec_row->save();
            }
            $config['mobile_api_secret']            = $api_secret;
            $GLOBALS['config']['mobile_api_secret'] = $api_secret;
        } catch (Throwable $e) {
        }
    }
}
$GLOBALS['api_secret'] = $api_secret;

// Resolusi parameter $action dari system/api.php (?r=plugin/mobile_api/<action> atau &action=<action>)
$_raw_r   = trim((string) ($_GET['r'] ?? $_POST['r'] ?? $_GET['_route'] ?? ''));
$_r_parts = array_values(array_filter(explode('/', $_raw_r)));

if (!empty($_REQUEST['action'])) {
    $action = trim((string) $_REQUEST['action']);
} elseif (isset($_r_parts[0]) && $_r_parts[0] === 'plugin' && !empty($_r_parts[2])) {
    $action = trim((string) $_r_parts[2]);
} elseif (isset($_r_parts[0]) && $_r_parts[0] !== 'plugin' && !empty($_r_parts[1])) {
    $action = trim((string) $_r_parts[1]);
} elseif (isset($routes[1]) && !empty($routes[1]) && $routes[1] !== 'mobile_api') {
    $action = trim((string) $routes[1]);
} elseif (isset($routes[2]) && !empty($routes[2])) {
    $action = trim((string) $routes[2]);
} else {
    $action = 'index';
}

try {
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
        case 'genieacs_customer_dashboard':
        case 'my_device':
        case 'customer_device':
        case 'customer_genieacs_wlan_save':
        case 'genieacs_customer_wlan_edit':
        case 'customer_wifi_save':
        case 'customer_genieacs_reboot':
        case 'genieacs_customer_reboot':
        case 'customer_device_reboot':
        case 'customer_genieacs_summon':
        case 'customer_device_refresh':
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
        case 'customer_inbox':
        case 'customer_inbox_read':
        case 'customer_inbox_delete':
        case 'customer_transfer_balance':
        case 'customer_lookup_friend':
        case 'customer_send_plan':
        case 'customer_sync_plan':
        case 'customer_extend_plan':
        case 'customer_profile_save':
        case 'customer_change_password':
            require __DIR__ . '/customer_portal_api.php';
            break;

        default:
            $comp_name    = !empty($config['CompanyName']) ? $config['CompanyName'] : 'PHPNuxBill';
            $curr_code    = !empty($config['currency_code']) ? $config['currency_code'] : 'Rp.';
            $srv_lang     = strtolower(trim((string) ($config['language'] ?? 'indonesia')));
            $logo_url     = function_exists('get_server_logo_url') ? get_server_logo_url() : '';
            $is_en        = (strtolower(trim((string) ($_SERVER['HTTP_X_APP_LANG'] ?? _req('lang', '')))) === 'en');
            $maint_active = !empty($config['maintenance_mode']) && $config['maintenance_mode'] !== 'no' && $config['maintenance_mode'] !== '0';
            $maint_logout = !empty($config['maintenance_mode_logout']) && $config['maintenance_mode_logout'] !== 'no' && $config['maintenance_mode_logout'] !== '0';
            $maint_date   = trim((string) ($config['maintenance_date'] ?? ''));

            $welcome_msg = $is_en
                ? ('Connected to ' . $comp_name . ' (PHPNuxBill Mobile API v3.1.0)')
                : ('Terhubung ke ' . $comp_name . ' (PHPNuxBill Mobile API v3.1.0)');

            api_out(true, $welcome_msg, [
                'version'                 => '3.1.0',
                'action'                  => $action,
                'company_name'            => $comp_name,
                'currency_code'           => $curr_code,
                'language'                => $srv_lang,
                'logo_url'                => $logo_url,
                'maintenance_mode'        => $maint_active,
                'maintenance_mode_logout' => $maint_logout,
                'maintenance_date'        => $maint_date,
                'company'                 => [
                    'name'                    => $comp_name,
                    'currency'                => $curr_code,
                    'language'                => $srv_lang,
                    'logo_url'                => $logo_url,
                    'phone'                   => $config['phone'] ?? '',
                    'maintenance_mode'        => $maint_active,
                    'maintenance_mode_logout' => $maint_logout,
                    'maintenance_date'        => $maint_date
                ]
            ]);
            break;
    }
} catch (Throwable $e) {
    api_out(
        false,
        'Server Error pada ' . $action . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
        null,
        500
    );
}
