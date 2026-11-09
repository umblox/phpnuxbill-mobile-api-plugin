{include file="sections/header.tpl"}

<div class="row">
    <div class="col-md-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">
                <div class="pull-right">
                    <span class="label label-success">v{$api_version|default:'2.5.0'} Active</span>
                </div>
                <i class="glyphicon glyphicon-phone"></i> Mobile App REST API Bridge
            </div>
            <div class="panel-body">
                <div class="alert alert-success">
                    <strong><i class="glyphicon glyphicon-ok-circle"></i> Status Plugin Aktif:</strong>
                    Plugin Mobile API terhubung langsung dengan gerbang resmi <code>system/api.php</code> tanpa memerlukan file tambahan di <code>system/controllers/</code>.
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <tbody>
                            <tr>
                                <th width="260">Endpoint Utama (via <code>system/api.php</code>)</th>
                                <td><code>{$_url}system/api.php?r=plugin/mobile_api</code></td>
                            </tr>
                            <tr>
                                <th>Endpoint Kompatibilitas (Legacy)</th>
                                <td><code>{$_url}system/api.php?r=apiv1</code></td>
                            </tr>
                            <tr>
                                <th>Endpoint Controller Plugin</th>
                                <td><code>{$_url}?_route=plugin/mobile_api</code></td>
                            </tr>
                            <tr>
                                <th>Jumlah Modul Terpasang</th>
                                <td><span class="badge bg-green">{$modules|@count} Modul</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h4><i class="glyphicon glyphicon-th-list"></i> Daftar Modul Aktif (<code>mobile_api_modules/</code>)</h4>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th width="50">#</th>
                                <th>Nama File Modul</th>
                                <th width="140">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $modules as $idx => $mod}
                            <tr>
                                <td>{$idx + 1}</td>
                                <td><code>{$mod|escape:'html'}</code></td>
                                <td><span class="label label-success">Loaded &amp; Ready</span></td>
                            </tr>
                            {foreachelse}
                            <tr>
                                <td colspan="3" class="text-center text-danger">Folder <code>mobile_api_modules/</code> belum ditemukan.</td>
                            </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
