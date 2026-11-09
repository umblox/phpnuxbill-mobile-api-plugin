{include file="sections/header.tpl"}
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">
                <div class="pull-right">
                    <span class="label label-success">Version {$api_version}</span>
                </div>
                Mobile App API Bridge Status
            </div>
            <div class="panel-body">
                <div class="alert alert-success">
                    <h4><i class="glyphicon glyphicon-ok-sign"></i> Plugin Berjalan Normal!</h4>
                    <p>Mobile App API Bridge berhasil aktif dan siap melayani permintaan dari Aplikasi Android Admin & Pelanggan melalui jalur resmi <code>system/api.php?r=plugin/mobile_api</code>.</p>
                </div>
                <h4>Modul Terdaftar ({$modules|@count} File):</h4>
                <ul>
                    {foreach $modules as $mod}
                    <li><code>{$mod}</code></li>
                    {/foreach}
                </ul>
            </div>
        </div>
    </div>
</div>
{include file="sections/footer.tpl"}
