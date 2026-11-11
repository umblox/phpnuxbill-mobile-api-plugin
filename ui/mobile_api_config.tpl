{include file="sections/header.tpl"}

{literal}
<style>
    .api-stat-box {
        border-radius: 8px;
        padding: 16px;
        color: #fff;
        margin-bottom: 18px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.12);
    }
    .api-stat-box h3 {
        margin: 0 0 4px 0;
        font-weight: 800;
        font-size: 22px;
    }
    .api-stat-box p {
        margin: 0;
        font-size: 12.5px;
        opacity: 0.9;
    }
    .bg-navy-card { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); }
    .bg-teal-card { background: linear-gradient(135deg, #0ba360 0%, #3cba92 100%); }
    .bg-amber-card { background: linear-gradient(135deg, #f12711 0%, #f5af19 100%); }
    .bg-purple-card { background: linear-gradient(135deg, #5f2c82 0%, #49a09d 100%); }
    .endpoint-box {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 8px 12px;
        font-family: monospace;
        font-size: 13px;
        color: #0f172a;
        word-break: break-all;
    }
    .gw-badge {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        margin-right: 6px;
        margin-bottom: 6px;
    }
    .gw-on { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .gw-off { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
</style>
{/literal}

<div class="row">
    <div class="col-md-3 col-sm-6">
        <div class="api-stat-box bg-navy-card">
            <h3>v{$api_version|default:'3.0.0'}</h3>
            <p><i class="glyphicon glyphicon-phone"></i> PHPNuxBill Mobile API</p>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="api-stat-box bg-teal-card">
            <h3>{$modules|@count} Modul</h3>
            <p><i class="glyphicon glyphicon-ok-circle"></i> Modular Engine Ready</p>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="api-stat-box bg-amber-card">
            <h3>Native API</h3>
            <p><i class="glyphicon glyphicon-lock"></i> Gerbang Resmi system/api.php</p>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="api-stat-box bg-purple-card">
            <h3>Admin &amp; Client</h3>
            <p><i class="glyphicon glyphicon-transfer"></i> Support APK Admin &amp; Pelanggan</p>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">
                <div class="pull-right">
                    <span class="label label-success">Status: Online &amp; Active</span>
                </div>
                <i class="glyphicon glyphicon-link"></i> Konfigurasi Endpoint &amp; Integrasi Notifikasi Resmi Web
            </div>
            <div class="panel-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <tbody>
                            <tr>
                                <th width="250" style="vertical-align: middle;">URL Server di Aplikasi Android</th>
                                <td>
                                    <div class="endpoint-box">
                                        <span id="rootUrlText">{$app_root_url}</span>
                                        <button type="button" class="btn btn-xs btn-primary" onclick="copyText('rootUrlText')">
                                            <i class="glyphicon glyphicon-copy"></i> Salin URL Server
                                        </button>
                                    </div>
                                    <small class="text-muted">Masukkan URL di atas pada pengaturan Server di aplikasi Android PHPNuxBill.</small>
                                </td>
                            </tr>
                            <tr>
                                <th style="vertical-align: middle;">Endpoint Utama (<code>system/api.php</code>)</th>
                                <td>
                                    <div class="endpoint-box">
                                        <span id="primaryApiText">{$app_root_url}/system/api.php?r=plugin/mobile_api</span>
                                        <button type="button" class="btn btn-xs btn-info" onclick="copyText('primaryApiText')">
                                            <i class="glyphicon glyphicon-copy"></i> Salin Endpoint
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th style="vertical-align: middle;">Endpoint Rute Plugin</th>
                                <td>
                                    <div class="endpoint-box">
                                        <span id="pluginRouteText">{$app_root_url}/?_route=plugin/mobile_api</span>
                                        <button type="button" class="btn btn-xs btn-default" onclick="copyText('pluginRouteText')">
                                            <i class="glyphicon glyphicon-copy"></i> Salin Rute
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th style="vertical-align: middle;">Jalur Notifikasi Resmi Web (<code>Message</code>)</th>
                                <td>
                                    <span class="gw-badge {if $gw_status.wa}gw-on{else}gw-off{/if}">
                                        <i class="glyphicon {if $gw_status.wa}glyphicon-ok{else}glyphicon-remove{/if}"></i>
                                        WhatsApp Gateway: {if $gw_status.wa} Terkonfigurasi{else} Belum Diatur{/if}
                                    </span>
                                    <span class="gw-badge {if $gw_status.telegram}gw-on{else}gw-off{/if}">
                                        <i class="glyphicon {if $gw_status.telegram}glyphicon-ok{else}glyphicon-remove{/if}"></i>
                                        Telegram Admin: {if $gw_status.telegram} Aktif{else} Belum Diatur{/if}
                                    </span>
                                    <span class="gw-badge {if $gw_status.sms}gw-on{else}gw-off{/if}">
                                        <i class="glyphicon {if $gw_status.sms}glyphicon-ok{else}glyphicon-remove{/if}"></i>
                                        SMS Gateway: {if $gw_status.sms} Terkonfigurasi{else} Belum Diatur{/if}
                                    </span>
                                    <span class="gw-badge {if $gw_status.email}gw-on{else}gw-off{/if}">
                                        <i class="glyphicon {if $gw_status.email}glyphicon-ok{else}glyphicon-remove{/if}"></i>
                                        SMTP / Email: {if $gw_status.email} Aktif{else} Default PHP Mail{/if}
                                    </span>
                                    <span class="gw-badge {if $gw_status.curl}gw-on{else}gw-off{/if}">
                                        <i class="glyphicon {if $gw_status.curl}glyphicon-ok{else}glyphicon-remove{/if}"></i>
                                        PHP cURL (GenieACS/API): {if $gw_status.curl} Ready{else} Nonaktif{/if}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h4 style="margin-top: 20px; margin-bottom: 12px; font-weight: bold;">
                    <i class="glyphicon glyphicon-th-list"></i> Daftar Modul Aktif (<code>system/plugin/mobile_api_modules/</code>)
                </h4>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover">
                        <thead>
                            <tr>
                                <th width="45" class="text-center">#</th>
                                <th width="260">Nama File Modul</th>
                                <th>Fungsi &amp; Cakupan Fitur</th>
                                <th width="95" class="text-right">Ukuran</th>
                                <th width="130" class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $modules as $idx => $mod}
                            <tr>
                                <td class="text-center">{$idx + 1}</td>
                                <td><code>{$mod.name|escape:'html'}</code></td>
                                <td>{$mod.description|escape:'html'}</td>
                                <td class="text-right"><small class="text-muted">{$mod.size}</small></td>
                                <td class="text-center"><span class="label label-success">Loaded &amp; Ready</span></td>
                            </tr>
                            {foreachelse}
                            <tr>
                                <td colspan="5" class="text-center text-danger">Folder <code>mobile_api_modules/</code> belum ditemukan.</td>
                            </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{literal}
<script>
function copyText(elementId) {
    var txt = document.getElementById(elementId).innerText;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(txt).then(function() {
            alert('Disalin ke clipboard:\n' + txt);
        });
    } else {
        var temp = document.createElement('textarea');
        temp.value = txt;
        document.body.appendChild(temp);
        temp.select();
        document.execCommand('copy');
        document.body.removeChild(temp);
        alert('Disalin ke clipboard:\n' + txt);
    }
}
</script>
{/literal}

{include file="sections/footer.tpl"}
