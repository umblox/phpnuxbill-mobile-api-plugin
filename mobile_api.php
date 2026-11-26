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
    global $ui, $config, $_c, $admin, $PAGES_PATH, $PAYMENTGATEWAY_PATH;
    _admin();

    $cfg     = (!empty($config) && is_array($config)) ? $config : (is_array($_c ?? null) ? $_c : []);
    $rootDir = dirname(__DIR__, 2);

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

    // Daftar 13 modul resmi lengkap dengan kategori & badge class untuk ui/mobile_api_config.tpl
    $moduleSpecs = [
        'dispatcher.php' => [
            'category'    => 'Core',
            'badge_class' => 'mod-cat-core',
            'description' => 'Router Utama & Gerbang Otorisasi Request Mobile API'
        ],
        'helpers_common.php' => [
            'category'    => 'Helper',
            'badge_class' => 'mod-cat-helper',
            'description' => 'Helper Global, Parser Token, Gerbang Maintenance & Jembatan Notifikasi Resmi Web (Message Class)'
        ],
        'helpers_payment_gateways.php' => [
            'category'    => 'Helper',
            'badge_class' => 'mod-cat-helper',
            'description' => 'Adapter Multi-Payment Gateway (ViaQRIS, Tripay, Duitku, Xendit, PayPal & Generic)'
        ],
        'core_auth_dashboard.php' => [
            'category'    => 'Admin',
            'badge_class' => 'mod-cat-admin',
            'description' => 'Autentikasi Admin, Statistik Dasbor, Grafik Bulanan & Widget Expired'
        ],
        'core_customers.php' => [
            'category'    => 'Admin',
            'badge_class' => 'mod-cat-admin',
            'description' => 'Manajemen Pelanggan (CRUD, Custom Fields, Sync Router & Welcome Message)'
        ],
        'core_services_vouchers.php' => [
            'category'    => 'Admin',
            'badge_class' => 'mod-cat-admin',
            'description' => 'Layanan Aktif, Isi Ulang Paket/Saldo, Generate Voucher, Refill & Struk Resmi'
        ],
        'core_plans_bandwidth.php' => [
            'category'    => 'Admin',
            'badge_class' => 'mod-cat-admin',
            'description' => 'Manajemen Paket Hotspot/PPPoE/Saldo/VPN, Bandwidth, Burst & Sync Router'
        ],
        'core_network_mikrotik.php' => [
            'category'    => 'Admin',
            'badge_class' => 'mod-cat-admin',
            'description' => 'MikroTik Live Traffic Monitor, Resource Health, User Online, Kick & Log'
        ],
        'core_reports_messages.php' => [
            'category'    => 'Admin',
            'badge_class' => 'mod-cat-admin',
            'description' => 'Laporan Keuangan, Kirim Pesan Personal, Broadcast Massal & Halaman Statis'
        ],
        'core_settings_staff.php' => [
            'category'    => 'Admin',
            'badge_class' => 'mod-cat-admin',
            'description' => 'Pengaturan Sistem, Mode Pemeliharaan, Template notifications.json, Tes Gateway, Staf & Backup DB'
        ],
        'plugin_coupons_assets_pg.php' => [
            'category'    => 'Plugin',
            'badge_class' => 'mod-cat-plugin',
            'description' => 'Kupon Promo, Inventaris Asset Manager, Peta Koordinat & Audit Payment Gateway'
        ],
        'plugin_genieacs.php' => [
            'category'    => 'Plugin',
            'badge_class' => 'mod-cat-plugin',
            'description' => 'GenieACS TR-069 Pro (HTTP/HTTPS, Diagnostik Optik PON, WAN, WLAN & Portal ONT)'
        ],
        'customer_portal_api.php' => [
            'category'    => 'Portal',
            'badge_class' => 'mod-cat-portal',
            'description' => 'Endpoint Khusus Aplikasi Pelanggan (Home, Beli Paket, Tagihan, Voucher & Inbox)'
        ]
    ];

    $modulesDir    = __DIR__ . '/mobile_api_modules';
    $modulesList   = [];
    $expectedCount = count($moduleSpecs);
    $loadedCount   = 0;

    foreach ($moduleSpecs as $fileName => $spec) {
        $fullPath = $modulesDir . '/' . $fileName;
        $exists   = is_file($fullPath) && filesize($fullPath) > 0;
        $sizeStr  = $exists ? (round((filesize($fullPath) ?: 0) / 1024, 1) . ' KB') : '-';
        $mtime    = $exists ? (int) @filemtime($fullPath) : 0;
        $modStr   = $mtime > 0 ? date('d M Y H:i', $mtime) : '-';

        if ($exists) {
            $loadedCount++;
        }

        $modulesList[] = [
            'name'        => $fileName,
            'category'    => $spec['category'],
            'badge_class' => $spec['badge_class'],
            'description' => $spec['description'],
            'size'        => $sizeStr,
            'modified'    => $modStr,
            'exists'      => $exists,
            'is_ready'    => $exists
        ];
    }

    // Deteksi modul ekstensi tambahan di luar 13 modul inti
    $extraFiles = glob($modulesDir . '/*.php') ?: [];
    foreach ($extraFiles as $fPath) {
        $bName = basename($fPath);
        if (isset($moduleSpecs[$bName])) {
            continue;
        }
        $exists  = is_file($fPath) && filesize($fPath) > 0;
        $sizeStr = $exists ? (round((filesize($fPath) ?: 0) / 1024, 1) . ' KB') : '-';
        $mtime   = $exists ? (int) @filemtime($fPath) : 0;
        $modStr  = $mtime > 0 ? date('d M Y H:i', $mtime) : '-';

        $modulesList[] = [
            'name'        => $bName,
            'category'    => 'Plugin',
            'badge_class' => 'mod-cat-plugin',
            'description' => 'Modul Ekstensi Tambahan Mobile API',
            'size'        => $sizeStr,
            'modified'    => $modStr,
            'exists'      => $exists,
            'is_ready'    => $exists
        ];
    }

    $missingCount = max(0, $expectedCount - $loadedCount);

    // Status Notifikasi & Ekstensi PHP Server
    $gatewaysStatus = [
        'wa'       => !empty($cfg['wa_url']),
        'telegram' => !empty($cfg['telegram_bot']) && !empty($cfg['telegram_target_id']),
        'sms'      => !empty($cfg['sms_url']),
        'email'    => !empty($cfg['smtp_host']) || !empty($cfg['mail_from']),
        'curl'     => function_exists('curl_init'),
        'gd'       => extension_loaded('gd') || function_exists('gd_info')
    ];

    // Daftar Payment Gateway Aktif untuk APK (via helpers_payment_gateways.php)
    $activePgs    = [];
    $pgHelperPath = $modulesDir . '/helpers_payment_gateways.php';
    if (is_file($pgHelperPath)) {
        require_once $pgHelperPath;
        if (function_exists('get_active_gateways')) {
            try {
                $activePgs = get_active_gateways($cfg);
            } catch (Throwable $e) {
                $activePgs = [];
            }
        }
    }

    // Status Konfigurasi GenieACS TR-069 (baca langsung dari tbl_appconfig tanpa memicu router)
    $genieRaw = isset($cfg['genieacs_hosts']) ? (string) $cfg['genieacs_hosts'] : '';
    if (trim($genieRaw) === '' && class_exists('ORM')) {
        try {
            $gRow = ORM::for_table('tbl_appconfig')->where('setting', 'genieacs_hosts')->find_one();
            if ($gRow && !empty($gRow['value'])) {
                $genieRaw = (string) $gRow['value'];
            }
        } catch (Throwable $e) {
        }
    }
    $genieHosts = json_decode($genieRaw, true);
    if (!is_array($genieHosts)) {
        $genieHosts = [];
    }
    $genieActiveName = '';
    foreach ($genieHosts as $gh) {
        if (is_array($gh) && !empty($gh['active'])) {
            $genieActiveName = trim((string) ($gh['name'] ?? ($gh['host'] ?? 'Active Host')));
            break;
        }
    }
    if ($genieActiveName === '' && !empty($genieHosts[0]) && is_array($genieHosts[0])) {
        $genieActiveName = trim((string) ($genieHosts[0]['name'] ?? ($genieHosts[0]['host'] ?? 'Default Host')));
    }
    $genieacsStatus = [
        'configured'  => count($genieHosts) > 0,
        'host_count'  => count($genieHosts),
        'active_name' => $genieActiveName
    ];

    // Kesiapan Fitur Portal Pelanggan & Pengumuman
    $relPages        = !empty($PAGES_PATH) ? trim((string) $PAGES_PATH, '/\\') : 'pages';
    $pagesDir        = is_dir($rootDir . DIRECTORY_SEPARATOR . $relPages)
        ? ($rootDir . DIRECTORY_SEPARATOR . $relPages)
        : $relPages;
    $hasAnnouncement = false;
    foreach (['Announcement.html', 'Announcement_Customer.html'] as $annFile) {
        $annPath = rtrim($pagesDir, '/\\') . DIRECTORY_SEPARATOR . $annFile;
        if (is_file($annPath)) {
            $annContent = trim(strip_tags((string) @file_get_contents($annPath)));
            if ($annContent !== '') {
                $hasAnnouncement = true;
                break;
            }
        }
    }

    $portalFeatures = [
        'balance'      => ($cfg['enable_balance'] ?? 'yes') === 'yes',
        'transfer'     => ($cfg['allow_balance_transfer'] ?? 'no') === 'yes',
        'extend'       => !empty($cfg['extend_expired']) && $cfg['extend_expired'] !== 'no' && $cfg['extend_expired'] !== '0',
        'extend_days'  => max(1, (int) ($cfg['extend_days'] ?? 3)),
        'voucher'      => ($cfg['disable_voucher'] ?? 'no') !== 'yes',
        'announcement' => $hasAnnouncement
    ];

    $ui->assign('_title', 'Mobile App API Configuration');
    $ui->assign('_system_menu', 'settings');
    $ui->assign('_admin', $admin);
    $ui->assign('api_version', '3.1.0');
    $ui->assign('app_root_url', $appRootUrl);
    $ui->assign('modules', $modulesList);
    $ui->assign('loaded_modules_count', $loadedCount);
    $ui->assign('expected_modules_count', $expectedCount);
    $ui->assign('missing_modules_count', $missingCount);
    $ui->assign('ready_modules_count', $loadedCount);
    $ui->assign('gw_status', $gatewaysStatus);
    $ui->assign('active_pgs', $activePgs);
    $ui->assign('genieacs_status', $genieacsStatus);
    $ui->assign('portal_features', $portalFeatures);

    // Opsional: custom APK URL dari tbl_appconfig bila ISP ingin hosting APK sendiri
    if (!empty($cfg['mobile_apk_download_url'])) {
        $ui->assign('apk_download_url', trim((string) $cfg['mobile_apk_download_url']));
    }
    if (!empty($cfg['mobile_apk_releases_url'])) {
        $ui->assign('apk_releases_url', trim((string) $cfg['mobile_apk_releases_url']));
    }

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
