{include file="sections/header.tpl"}

{assign var="apk_repo_base" value="https://github.com/umblox/phpnuxbill-apk"}
{assign var="apk_dl_url" value=$apk_download_url|default:($apk_repo_base|cat:'/releases/latest/download/PHPNuxBill-Mobile.apk')}
{assign var="apk_rel_url" value=$apk_releases_url|default:($apk_repo_base|cat:'/releases')}

{literal}
<style>
.api-stat-box {
    border-radius: 10px;
    padding: 18px;
    color: #fff;
    margin-bottom: 18px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
    position: relative;
    overflow: hidden;
}
.api-stat-box h3 {
    margin: 0 0 4px 0;
    font-weight: 800;
    font-size: 22px;
    letter-spacing: -0.3px;
}
.api-stat-box p {
    margin: 0;
    font-size: 12.5px;
    opacity: 0.92;
}
.bg-navy-card   { background: linear-gradient(135deg, #0f172a 0%, #0284c7 100%); }
.bg-teal-card   { background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%); }
.bg-cyan-card   { background: linear-gradient(135deg, #0369a1 0%, #0d9488 100%); }
.bg-slate-card  { background: linear-gradient(135deg, #1e293b 0%, #334155 100%); }
.bg-danger-card { background: linear-gradient(135deg, #991b1b 0%, #dc2626 100%); }

.api-panel {
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
}
.api-panel > .panel-heading {
    background: linear-gradient(135deg, #0f172a 0%, #0369a1 100%);
    color: #fff;
    border-bottom: none;
    padding: 13px 16px;
    font-weight: 700;
}
.endpoint-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 8px 12px;
    font-family: Menlo, Monaco, Consolas, monospace;
    font-size: 12.5px;
    color: #0f172a;
    word-break: break-all;
}
.endpoint-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
}
.gw-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    margin-right: 6px;
    margin-bottom: 6px;
}
.gw-on   { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.gw-off  { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.gw-info { background: #e0f2fe; color: #075985; border: 1px solid #7dd3fc; }

.mod-cat {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.mod-cat-core   { background: #0f172a; color: #f8fafc; }
.mod-cat-helper { background: #ccfbf1; color: #115e59; }
.mod-cat-admin  { background: #e0f2fe; color: #075985; }
.mod-cat-plugin { background: #ede9fe; color: #5b21b6; }
.mod-cat-portal { background: #fef3c7; color: #92400e; }

.pg-chip-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #f8fafc;
    margin-bottom: 8px;
}
.pg-chip-item:last-child { margin-bottom: 0; }
.feature-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #f1f5f9;
    font-size: 13px;
}
.feature-row:last-child { border-bottom: none; }

.apk-qr-card {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    padding: 14px;
    text-align: center;
}
.apk-qr-card img {
    width: 156px;
    height: 156px;
    border-radius: 8px;
    background: #fff;
    padding: 6px;
    border: 1px solid #e2e8f0;
    margin-bottom: 8px;
}
.apk-btn-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}
.apk-steps {
    margin: 12px 0 0 0;
    padding-left: 18px;
    font-size: 12.5px;
    color: #475569;
}
.apk-steps li {
    margin-bottom: 4px;
}

#apiTestResult {
    margin-top: 10px;
    display: none;
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 12.5px;
    font-family: Menlo, Monaco, Consolas, monospace;
}
#apiCopyToast {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 9999;
    background: #0f172a;
    color: #fff;
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.25);
    display: none;
}
</style>
{/literal}

{* 1. RINGKASAN STATISTIK ATAS *}
<div class="row">
    <div class="col-md-3 col-sm-6">
        <div class="api-stat-box bg-navy-card">
            <h3>v{$api_version|default:'3.1.0'}</h3>
            <p><i class="glyphicon glyphicon-phone"></i> PHPNuxBill Mobile API</p>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="api-stat-box {if $missing_modules_count > 0}bg-danger-card{else}bg-teal-card{/if}">
            <h3>{$loaded_modules_count}/{$expected_modules_count} Modul</h3>
            <p>
                <i class="glyphicon {if $missing_modules_count > 0}glyphicon-warning-sign{else}glyphicon-ok-circle{/if}"></i>
                {if $missing_modules_count > 0}
                    {$missing_modules_count} Modul Belum Terunggah
                {else}
                    Modular Engine Lengkap & Siap
                {/if}
            </p>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="api-stat-box bg-cyan-card">
            <h3>{$active_pgs|@count} Gateway</h3>
            <p><i class="glyphicon glyphicon-credit-card"></i> Payment Gateway Aktif di APK</p>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="api-stat-box bg-slate-card">
            <h3>{if $genieacs_status.configured}{$genieacs_status.host_count} Host{else}Nonaktif{/if}</h3>
            <p><i class="glyphicon glyphicon-hdd"></i> GenieACS TR-069 (Anti-IDOR)</p>
        </div>
    </div>
</div>

{if $missing_modules_count > 0}
<div class="alert alert-danger" style="border-radius: 8px;">
    <strong><i class="glyphicon glyphicon-exclamation-sign"></i> Peringatan Modul Tidak Lengkap:</strong>
    Terdapat <strong>{$missing_modules_count} file modul</strong> yang belum ditemukan di folder
    <code>system/plugin/mobile_api_modules/</code>. Periksa daftar modul berwarna merah pada tabel di bawah lalu unggah file yang kurang.
</div>
{/if}

{* 2. PANEL ENDPOINT & INTEGRASI SERVER *}
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-primary api-panel mb20">
            <div class="panel-heading">
                <div class="pull-right">
                    <span class="label {if $missing_modules_count > 0}label-warning{else}label-success{/if}">
                        {if $missing_modules_count > 0}Status: Perlu Kelengkapan Modul{else}Status: Online & Ready{/if}
                    </span>
                </div>
                <i class="glyphicon glyphicon-link"></i> Konfigurasi Endpoint & Kesiapan Server
            </div>
            <div class="panel-body">
                <div class="table-responsive">
                    <table class="table table-bordered" style="margin-bottom: 0;">
                        <tbody>
                            <tr>
                                <th width="260" style="vertical-align: middle;">URL Server di Aplikasi Android</th>
                                <td>
                                    <div class="endpoint-box">
                                        <span id="rootUrlText">{$app_root_url|escape:'html'}</span>
                                        <div class="endpoint-actions">
                                            <button type="button" class="btn btn-xs btn-primary" onclick="copyText('rootUrlText')">
                                                <i class="glyphicon glyphicon-copy"></i> Salin URL
                                            </button>
                                        </div>
                                    </div>
                                    <small class="text-muted">Masukkan URL di atas pada kolom Server URL di aplikasi Android Admin maupun Pelanggan.</small>
                                </td>
                            </tr>
                            <tr>
                                <th style="vertical-align: middle;">Endpoint Utama (<code>system/api.php</code>)</th>
                                <td>
                                    <div class="endpoint-box">
                                        <span id="primaryApiText">{$app_root_url|escape:'html'}/system/api.php?r=plugin/mobile_api</span>
                                        <div class="endpoint-actions">
                                            <button type="button" class="btn btn-xs btn-success" id="btnTestApi"
                                                data-api-url="{$app_root_url|escape:'html'}/system/api.php?r=plugin/mobile_api"
                                                onclick="testMobileApi(this)">
                                                <i class="glyphicon glyphicon-flash"></i> Tes API
                                            </button>
                                            <button type="button" class="btn btn-xs btn-info" onclick="copyText('primaryApiText')">
                                                <i class="glyphicon glyphicon-copy"></i> Salin
                                            </button>
                                        </div>
                                    </div>
                                    <div id="apiTestResult"></div>
                                </td>
                            </tr>
                            <tr>
                                <th style="vertical-align: middle;">Endpoint Rute Alternatif</th>
                                <td>
                                    <div class="endpoint-box">
                                        <span id="pluginRouteText">{$app_root_url|escape:'html'}/?_route=plugin/mobile_api</span>
                                        <div class="endpoint-actions">
                                            <button type="button" class="btn btn-xs btn-default" onclick="copyText('pluginRouteText')">
                                                <i class="glyphicon glyphicon-copy"></i> Salin
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th style="vertical-align: middle;">Notifikasi & Ekstensi PHP Server</th>
                                <td>
                                    <span class="gw-badge {if $gw_status.wa}gw-on{else}gw-off{/if}">
                                        <i class="glyphicon {if $gw_status.wa}glyphicon-ok{else}glyphicon-remove{/if}"></i>
                                        WhatsApp: {if $gw_status.wa}Aktif{else}Belum Diatur{/if}
                                    </span>
                                    <span class="gw-badge {if $gw_status.telegram}gw-on{else}gw-off{/if}">
                                        <i class="glyphicon {if $gw_status.telegram}glyphicon-ok{else}glyphicon-remove{/if}"></i>
                                        Telegram Admin: {if $gw_status.telegram}Aktif{else}Belum Diatur{/if}
                                    </span>
                                    <span class="gw-badge {if $gw_status.sms}gw-on{else}gw-off{/if}">
                                        <i class="glyphicon {if $gw_status.sms}glyphicon-ok{else}glyphicon-remove{/if}"></i>
                                        SMS Gateway: {if $gw_status.sms}Aktif{else}Belum Diatur{/if}
                                    </span>
                                    <span class="gw-badge {if $gw_status.email}gw-on{else}gw-off{/if}">
                                        <i class="glyphicon {if $gw_status.email}glyphicon-ok{else}glyphicon-remove{/if}"></i>
                                        Email/SMTP: {if $gw_status.email}Aktif{else}Default PHP{/if}
                                    </span>
                                    <span class="gw-badge {if $gw_status.curl}gw-on{else}gw-off{/if}">
                                        <i class="glyphicon {if $gw_status.curl}glyphicon-ok{else}glyphicon-remove{/if}"></i>
                                        PHP cURL: {if $gw_status.curl}Ready{else}Nonaktif{/if}
                                    </span>
                                    <span class="gw-badge {if $gw_status.gd}gw-on{else}gw-off{/if}">
                                        <i class="glyphicon {if $gw_status.gd}glyphicon-ok{else}glyphicon-remove{/if}"></i>
                                        PHP GD (Foto Profil): {if $gw_status.gd}Ready{else}Nonaktif{/if}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{* 2B. PANEL UNDUH APLIKASI ANDROID (APK RELEASE TERBARU + QR CODE) *}
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-primary api-panel mb20">
            <div class="panel-heading">
                <div class="pull-right">
                    <span class="label label-info">Android 7.0+ (Signed Release)</span>
                </div>
                <i class="glyphicon glyphicon-download-alt"></i> Unduh Aplikasi Android (Admin & Portal Pelanggan)
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-8">
                        <p style="margin-top: 0; color: #334155;">
                            Satu aplikasi Android resmi untuk <strong>Admin / Staf (5 Level RBAC)</strong> sekaligus <strong>Portal Pelanggan</strong>.
                            File APK rilis terbaru diunduh langsung dari <strong>GitHub Releases</strong> sehingga selalu ringan dan tidak membebani penyimpanan server web Anda.
                        </p>

                        <div class="endpoint-box">
                            <span id="apkDownloadUrlText">{$apk_dl_url|escape:'html'}</span>
                            <div class="endpoint-actions">
                                <button type="button" class="btn btn-xs btn-primary" onclick="copyText('apkDownloadUrlText')">
                                    <i class="glyphicon glyphicon-copy"></i> Salin Link APK
                                </button>
                            </div>
                        </div>

                        <div class="apk-btn-group">
                            <a href="{$apk_dl_url|escape:'html'}" class="btn btn-success" target="_blank" rel="noopener noreferrer">
                                <i class="glyphicon glyphicon-download-alt"></i> Download APK Release Terbaru
                            </a>
                            <button type="button" class="btn btn-info" id="btnShareApkWa"
                                data-apk-url="{$apk_dl_url|escape:'html'}"
                                data-server-url="{$app_root_url|escape:'html'}"
                                onclick="shareApkViaWhatsApp(this)">
                                <i class="glyphicon glyphicon-share"></i> Bagikan ke WhatsApp
                            </button>
                            <a href="{$apk_rel_url|escape:'html'}" class="btn btn-default" target="_blank" rel="noopener noreferrer">
                                <i class="glyphicon glyphicon-tags"></i> Semua Versi Rilis
                            </a>
                        </div>

                        <ol class="apk-steps">
                            <li>Unduh dan pasang file <code>PHPNuxBill-Mobile.apk</code> di perangkat Android (izinkan pemasangan dari sumber ini jika diminta).</li>
                            <li>Buka aplikasi lalu masukkan <strong>URL Server</strong> di atas dan ketuk <strong>Cek Server</strong>.</li>
                            <li>Pilih tab <strong>Admin / Staf</strong> atau <strong>Pelanggan</strong>, lalu masuk menggunakan akun billing Anda.</li>
                        </ol>
                    </div>
                    <div class="col-md-4" style="margin-top: 10px;">
                        <div class="apk-qr-card">
                            <img id="apkDownloadQrImg" alt="QR Code Download APK" data-apk-url="{$apk_dl_url|escape:'html'}" />
                            <div style="font-weight: 700; font-size: 13px; color: #0f172a;">Scan QR dari HP</div>
                            <small class="text-muted">Arahkan kamera HP untuk langsung mengunduh APK Release terbaru.</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{* 3. DUA KOLOM: PAYMENT GATEWAY AKTIF & FITUR PORTAL PELANGGAN *}
<div class="row">
    <div class="col-md-6">
        <div class="panel panel-primary api-panel mb20">
            <div class="panel-heading">
                <i class="glyphicon glyphicon-credit-card"></i> Payment Gateway Aktif untuk APK
                <small style="opacity: 0.85; font-weight: 400;">(<code>helpers_payment_gateways.php</code>)</small>
            </div>
            <div class="panel-body">
                {if $active_pgs|@count > 0}
                    {foreach $active_pgs as $pg}
                    <div class="pg-chip-item">
                        <div>
                            <strong style="color: #0f172a;">{$pg.name|escape:'html'}</strong>
                            <div class="text-muted" style="font-size: 12px;">{$pg.description|escape:'html'}</div>
                        </div>
                        <div class="text-right" style="flex-shrink: 0; margin-left: 10px;">
                            {if $pg.requires_channel}
                                <span class="label label-info">{$pg.channels|@count} Channel</span>
                            {else}
                                <span class="label label-success">Langsung</span>
                            {/if}
                        </div>
                    </div>
                    {/foreach}
                {else}
                    <div class="alert alert-warning" style="margin-bottom: 0;">
                        Belum ada Payment Gateway yang aktif atau kredensial/channel belum dikonfigurasi pada menu <strong>Payment Gateway</strong>.
                    </div>
                {/if}
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="panel panel-primary api-panel mb20">
            <div class="panel-heading">
                <i class="glyphicon glyphicon-user"></i> Kesiapan Fitur Portal Pelanggan & GenieACS
            </div>
            <div class="panel-body">
                <div class="feature-row">
                    <span><i class="glyphicon glyphicon-piggy-bank text-primary"></i> Fitur Saldo & Top-Up</span>
                    <span class="label {if $portal_features.balance}label-success{else}label-default{/if}">
                        {if $portal_features.balance}Aktif{else}Nonaktif{/if}
                    </span>
                </div>
                <div class="feature-row">
                    <span><i class="glyphicon glyphicon-send text-primary"></i> Transfer Saldo & Kirim Paket Teman</span>
                    <span class="label {if $portal_features.transfer}label-success{else}label-default{/if}">
                        {if $portal_features.transfer}Aktif{else}Nonaktif{/if}
                    </span>
                </div>
                <div class="feature-row">
                    <span><i class="glyphicon glyphicon-time text-primary"></i> Perpanjangan Darurat (Extend Expired)</span>
                    <span class="label {if $portal_features.extend}label-success{else}label-default{/if}">
                        {if $portal_features.extend}Aktif (+{$portal_features.extend_days} Hari){else}Nonaktif{/if}
                    </span>
                </div>
                <div class="feature-row">
                    <span><i class="glyphicon glyphicon-barcode text-primary"></i> Aktivasi Voucher Mandiri</span>
                    <span class="label {if $portal_features.voucher}label-success{else}label-default{/if}">
                        {if $portal_features.voucher}Aktif{else}Disembunyikan{/if}
                    </span>
                </div>
                <div class="feature-row">
                    <span><i class="glyphicon glyphicon-bullhorn text-primary"></i> Pengumuman (<code>Announcement.html</code>)</span>
                    <span class="label {if $portal_features.announcement}label-info{else}label-default{/if}">
                        {if $portal_features.announcement}Terisi & Tampil{else}Kosong{/if}
                    </span>
                </div>
                <div class="feature-row">
                    <span><i class="glyphicon glyphicon-hdd text-primary"></i> GenieACS TR-069 (Modem PPPoE/Others)</span>
                    <span class="label {if $genieacs_status.configured}label-success{else}label-warning{/if}">
                        {if $genieacs_status.configured}{$genieacs_status.active_name|escape:'html'} ({$genieacs_status.host_count} Host){else}Belum Diatur{/if}
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

{* 4. TABEL DAFTAR MODUL API *}
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-primary api-panel mb20">
            <div class="panel-heading">
                <i class="glyphicon glyphicon-th-list"></i> Daftar Modul Aktif (<code>system/plugin/mobile_api_modules/</code>)
            </div>
            <div class="panel-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover" style="margin-bottom: 0;">
                        <thead>
                            <tr>
                                <th width="42" class="text-center">#</th>
                                <th width="250">Nama File Modul</th>
                                <th width="90" class="text-center">Kategori</th>
                                <th>Fungsi & Cakupan Fitur</th>
                                <th width="85" class="text-right">Ukuran</th>
                                <th width="135" class="text-center">Update Terakhir</th>
                                <th width="125" class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $modules as $idx => $mod}
                            <tr class="{if !$mod.exists}danger{/if}">
                                <td class="text-center">{$idx + 1}</td>
                                <td><code>{$mod.name|escape:'html'}</code></td>
                                <td class="text-center">
                                    <span class="mod-cat {$mod.badge_class|escape:'html'}">{$mod.category|escape:'html'}</span>
                                </td>
                                <td>{$mod.description|escape:'html'}</td>
                                <td class="text-right"><small class="text-muted">{$mod.size|escape:'html'}</small></td>
                                <td class="text-center"><small class="text-muted">{$mod.modified|escape:'html'}</small></td>
                                <td class="text-center">
                                    {if $mod.exists}
                                        <span class="label label-success">Loaded & Ready</span>
                                    {else}
                                        <span class="label label-danger">Belum Diunggah</span>
                                    {/if}
                                </td>
                            </tr>
                            {foreachelse}
                            <tr>
                                <td colspan="7" class="text-center text-danger">
                                    Folder <code>system/plugin/mobile_api_modules/</code> belum ditemukan.
                                </td>
                            </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="apiCopyToast"></div>

{literal}
<script>
(function initApkQrCode() {
    var qrImg = document.getElementById('apkDownloadQrImg');
    if (!qrImg) return;
    var apkUrl = qrImg.getAttribute('data-apk-url') || '';
    if (!apkUrl) return;
    qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=10&data=' + encodeURIComponent(apkUrl);
    qrImg.onerror = function() {
        qrImg.style.display = 'none';
    };
})();

function shareApkViaWhatsApp(btn) {
    var apkUrl = btn.getAttribute('data-apk-url') || '';
    var srvUrl = btn.getAttribute('data-server-url') || '';
    var text = 'Unduh Aplikasi Android PHPNuxBill Mobile (Admin & Pelanggan):\n'
        + apkUrl + '\n\n'
        + 'URL Server Billing:\n'
        + srvUrl;
    var waHref = 'https://wa.me/?text=' + encodeURIComponent(text);
    window.open(waHref, '_blank', 'noopener,noreferrer');
}

function showApiToast(msg) {
    var toast = document.getElementById('apiCopyToast');
    if (!toast) return;
    toast.innerText = msg;
    toast.style.display = 'block';
    clearTimeout(window._apiToastTimer);
    window._apiToastTimer = setTimeout(function() {
        toast.style.display = 'none';
    }, 2600);
}

function copyText(elementId) {
    var el = document.getElementById(elementId);
    if (!el) return;
    var txt = el.innerText.trim();
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(function() {
            showApiToast('Disalin: ' + txt);
        }).catch(function() {
            fallbackCopy(txt);
        });
    } else {
        fallbackCopy(txt);
    }
}

function fallbackCopy(txt) {
    var temp = document.createElement('textarea');
    temp.value = txt;
    document.body.appendChild(temp);
    temp.select();
    document.execCommand('copy');
    document.body.removeChild(temp);
    showApiToast('Disalin: ' + txt);
}

function testMobileApi(btn) {
    var url = btn.getAttribute('data-api-url');
    var box = document.getElementById('apiTestResult');
    if (!url || !box) return;

    btn.disabled = true;
    box.style.display = 'block';
    box.style.background = '#f1f5f9';
    box.style.border = '1px solid #cbd5e1';
    box.style.color = '#334155';
    box.innerText = 'Menguji koneksi ke ' + url + ' ...';

    var t0 = Date.now();
    fetch(url, { method: 'GET', headers: { 'Accept': 'application/json' } })
        .then(function(res) {
            return res.text().then(function(body) {
                return { ok: res.ok, status: res.status, body: body };
            });
        })
        .then(function(res) {
            var ms = Date.now() - t0;
            var parsed = null;
            try { parsed = JSON.parse(res.body); } catch (e) {}
            if (res.ok && parsed && (parsed.success === true || parsed.status === true)) {
                box.style.background = '#dcfce7';
                box.style.border = '1px solid #86efac';
                box.style.color = '#166534';
                box.innerText = 'OK (' + res.status + ', ' + ms + ' ms) — ' + (parsed.message || 'API aktif & merespons JSON valid.');
            } else {
                box.style.background = '#fee2e2';
                box.style.border = '1px solid #fca5a5';
                box.style.color = '#991b1b';
                box.innerText = 'Gagal (HTTP ' + res.status + ', ' + ms + ' ms): ' + (parsed && parsed.message ? parsed.message : res.body.substring(0, 180));
            }
        })
        .catch(function(err) {
            box.style.background = '#fee2e2';
            box.style.border = '1px solid #fca5a5';
            box.style.color = '#991b1b';
            box.innerText = 'Error koneksi: ' + err.message;
        })
        .finally(function() {
            btn.disabled = false;
        });
}
</script>
{/literal}

{include file="sections/footer.tpl"}
