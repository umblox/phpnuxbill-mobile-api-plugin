<?php
/**
 * PHPNuxBill Mobile API Plugin - GenieACS TR-069 Module (Admin & Customer)
 * Path: system/plugin/mobile_api_modules/plugin_genieacs.php
 * Compatible with phpnuxbill-genieacs-dashboard (tbl_appconfig: genieacs_hosts)
 */

if (!class_exists('GenieACSClient')) {
    class GenieACSClient
    {
        private $host;
        private $port;
        private $username;
        private $password;
        private $baseUrl;

        public function __construct($host = null, $port = 7557, $username = null, $password = null)
        {
            $this->host     = trim((string)$host);
            $this->port     = (int)($port ?: 7557);
            $this->username = $username;
            $this->password = $password;
            $cleanHost      = preg_replace('#^https?://#i', '', rtrim($this->host, '/'));
            $this->baseUrl  = "http://{$cleanHost}:{$this->port}";
        }

        private function request($endpoint, $method = 'GET', $data = null)
        {
            if (!function_exists('curl_init')) {
                return ['success' => false, 'error' => 'Ekstensi PHP cURL belum aktif di server.'];
            }
            $url = $this->baseUrl . $endpoint;
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);

            if (!empty($this->username)) {
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                }
            } elseif ($method === 'PUT') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data !== null) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                }
            } elseif ($method === 'DELETE') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['success' => false, 'error' => $error, 'http_code' => $httpCode];
            }

            return [
                'success'   => ($httpCode >= 200 && $httpCode < 300),
                'data'      => json_decode($response, true),
                'http_code' => $httpCode
            ];
        }

        public function testConnection()
        {
            $result = $this->request('/devices?limit=1');
            return !empty($result['success']);
        }

        public function getDevices($query = [], $limit = 100, $skip = 0)
        {
            $params = [];
            if (!empty($query)) {
                $params[] = 'query=' . urlencode(json_encode($query));
            }
            if ($limit > 0) {
                $params[] = 'limit=' . (int)$limit;
            }
            if ($skip > 0) {
                $params[] = 'skip=' . (int)$skip;
            }
            $queryString = !empty($params) ? '?' . implode('&', $params) : '';
            return $this->request('/devices/' . $queryString);
        }

        public function getDevice($deviceId)
        {
            $query = ['_id' => $deviceId];
            $queryString = '?query=' . urlencode(json_encode($query));
            $result = $this->request('/devices/' . $queryString);
            if (!empty($result['success']) && !empty($result['data'][0])) {
                return ['success' => true, 'data' => $result['data'][0]];
            }
            return ['success' => false, 'error' => 'Device not found'];
        }

        public function rebootDevice($deviceId)
        {
            $encodedId = rawurlencode($deviceId);
            $endpoint  = "/devices/{$encodedId}/tasks?connection_request";
            return $this->request($endpoint, 'POST', ['name' => 'reboot']);
        }

        public function summonDevice($deviceId)
        {
            $encodedId = rawurlencode($deviceId);
            $endpoint  = "/devices/{$encodedId}/tasks?connection_request";
            return $this->request($endpoint, 'POST');
        }

        public function setParameterValues($deviceId, $parameters, $timeout = 5000)
        {
            $encodedId = rawurlencode($deviceId);
            $endpoint  = "/devices/{$encodedId}/tasks?timeout={$timeout}&connection_request";
            return $this->request($endpoint, 'POST', [
                'name'            => 'setParameterValues',
                'parameterValues' => $parameters
            ]);
        }
    }
}

if (!function_exists('genieacs_get_hosts')) {
    function genieacs_get_hosts()
    {
        $d = ORM::for_table('tbl_appconfig')->where('setting', 'genieacs_hosts')->find_one();
        $raw = $d ? $d['value'] : ($GLOBALS['config']['genieacs_hosts'] ?? '[]');
        $hosts = json_decode((string)$raw, true);
        return is_array($hosts) ? array_values($hosts) : [];
    }
}

if (!function_exists('genieacs_save_hosts')) {
    function genieacs_save_hosts($hosts)
    {
        $json = json_encode(array_values($hosts), JSON_UNESCAPED_SLASHES);
        $d = ORM::for_table('tbl_appconfig')->where('setting', 'genieacs_hosts')->find_one();
        if ($d) {
            $d->value = $json;
            $d->save();
        } else {
            $d = ORM::for_table('tbl_appconfig')->create();
            $d->setting = 'genieacs_hosts';
            $d->value   = $json;
            $d->save();
        }
        $GLOBALS['config']['genieacs_hosts'] = $json;
    }
}

if (!function_exists('genieacs_resolve_host')) {
    function genieacs_resolve_host($hostId = null)
    {
        $hosts = genieacs_get_hosts();
        if (empty($hosts)) return null;
        if (!empty($hostId)) {
            foreach ($hosts as $h) {
                if (($h['id'] ?? '') == $hostId) return $h;
            }
        }
        foreach ($hosts as $h) {
            if (!empty($h['active'])) return $h;
        }
        return $hosts[0] ?? null;
    }
}

if (!function_exists('genieacs_load_config')) {
    function genieacs_load_config($hostId = null)
    {
        $h = genieacs_resolve_host($hostId);
        if (!$h) return null;
        return new GenieACSClient(
            $h['host'] ?? '',
            (int)($h['port'] ?? 7557),
            $h['username'] ?? '',
            $h['password'] ?? ''
        );
    }
}

if (!function_exists('genieacs_format_uptime')) {
    function genieacs_format_uptime($seconds)
    {
        if (!$seconds || !is_numeric($seconds)) return '-';
        $s = (int)$seconds;
        $days = floor($s / 86400);
        $s %= 86400;
        $hours = floor($s / 3600);
        $s %= 3600;
        $mins = floor($s / 60);
        $secs = $s % 60;
        $parts = [];
        if ($days > 0) $parts[] = "{$days}d";
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($mins > 0) $parts[] = "{$mins}m";
        $parts[] = "{$secs}s";
        return implode(' ', $parts);
    }
}

if (!function_exists('genieacs_extract_wlan_configs')) {
    function genieacs_extract_wlan_configs($device)
    {
        $configs = [];
        for ($i = 1; $i <= 8; $i++) {
            $wlan = $device['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration'][$i] ?? null;
            if ($wlan) {
                $password = $wlan['KeyPassphrase']['_value']
                    ?? $wlan['PreSharedKey']['1']['KeyPassphrase']['_value']
                    ?? '';
                $beacon = $wlan['BeaconType']['_value'] ?? '11i';
                $secLabel = ($beacon === '11i') ? 'WPA2-PSK' : (($beacon === 'WPA') ? 'WPA-PSK' : (($beacon === 'WPAand11i') ? 'WPA/WPA2-PSK' : (($beacon === 'Basic' || $beacon === 'None') ? 'Open' : $beacon)));
                $configs[] = [
                    'index'             => $i,
                    'ssid'              => $wlan['SSID']['_value'] ?? '',
                    'password'          => $password,
                    'enable'            => (bool)($wlan['Enable']['_value'] ?? true),
                    'channel'           => $wlan['Channel']['_value'] ?? '-',
                    'beacon_type'       => $beacon,
                    'security'          => $secLabel,
                    'mac'               => $wlan['BSSID']['_value'] ?? '-',
                    'standard'          => $wlan['Standard']['_value'] ?? '-',
                    'max_bitrate'       => $wlan['MaxBitRate']['_value'] ?? '-',
                    'connected_devices' => (int)($wlan['AssociatedDeviceNumberOfEntries']['_value']
                        ?? $wlan['TotalAssociations']['_value']
                        ?? $wlan['X_CT-COM_AssociatedDeviceNumberOfEntries']['_value']
                        ?? 0),
                    'source'            => 'tr098'
                ];
            }
        }

        for ($i = 1; $i <= 8; $i++) {
            $ssidEntry = $device['Device']['WiFi']['SSID'][$i] ?? null;
            if ($ssidEntry) {
                $ap       = $device['Device']['WiFi']['AccessPoint'][$i] ?? null;
                $sec      = $ap['Security'] ?? null;
                $radio    = $device['Device']['WiFi']['Radio'][$i] ?? null;
                $password = $sec['KeyPassphrase']['_value'] ?? '';
                $beacon   = $sec['ModeEnabled']['_value'] ?? 'WPA2-Personal';
                $configs[] = [
                    'index'             => $i,
                    'ssid'              => $ssidEntry['SSID']['_value'] ?? '',
                    'password'          => $password,
                    'enable'            => (bool)($ssidEntry['Enable']['_value'] ?? ($ap['Enable']['_value'] ?? true)),
                    'channel'           => $radio['Channel']['_value'] ?? '-',
                    'beacon_type'       => $beacon,
                    'security'          => $beacon,
                    'mac'               => $ap['BSSID']['_value'] ?? '-',
                    'standard'          => $radio['Standard']['_value'] ?? ($radio['OperatingFrequencyBand']['_value'] ?? '-'),
                    'max_bitrate'       => $radio['SupportedFrequencies']['_value'] ?? '-',
                    'connected_devices' => (int)($ap['AssociatedDeviceNumberOfEntries']['_value'] ?? ($ap['TotalAssociations']['_value'] ?? 0)),
                    'source'            => 'tr181'
                ];
            }
        }
        return $configs;
    }
}

if (!function_exists('genieacs_extract_wan_connections')) {
    function genieacs_extract_wan_connections($device)
    {
        $wanConnections = [];
        for ($i = 1; $i <= 8; $i++) {
            $ppp = $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'][$i]['WANPPPConnection']['1'] ?? null;
            $ip  = $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'][$i]['WANIPConnection']['1'] ?? null;
            $vlan = $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'][$i]['X_CT-COM_VLANID']['_value'] ?? null;
            if ($ppp || $ip) {
                $conn = $ppp ?? $ip;
                $type = $ppp ? 'ppp' : 'ip';
                $wanConnections[] = [
                    'index'           => $i,
                    'type'            => $type,
                    'username'        => $ppp['Username']['_value'] ?? '',
                    'password'        => $ppp['Password']['_value'] ?? '',
                    'external_ip'     => $conn['ExternalIPAddress']['_value'] ?? '-',
                    'connection_type' => $conn['ConnectionType']['_value'] ?? '-',
                    'nat'             => (bool)($conn['NATEnabled']['_value'] ?? false),
                    'enable'          => (bool)($conn['Enable']['_value'] ?? true),
                    'gateway'         => $conn['DefaultGateway']['_value'] ?? '-',
                    'subnet'          => $conn['SubnetMask']['_value'] ?? '-',
                    'dns'             => $conn['DNSServers']['_value'] ?? '-',
                    'vlan'            => $vlan !== null ? (string)$vlan : ''
                ];
            }
        }
        return $wanConnections;
    }
}

if (!function_exists('genieacs_extract_device_info')) {
    function genieacs_extract_device_info($d)
    {
        $pppoe  = 'N/A';
        $wan_ip = 'N/A';
        for ($i = 1; $i <= 8; $i++) {
            $u = $d['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'][$i]['WANPPPConnection']['1']['Username']['_value'] ?? null;
            if ($u && $u !== '' && $u !== 'N/A' && $pppoe === 'N/A') {
                $pppoe = $u;
            }
            $ppp_ip = $d['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'][$i]['WANPPPConnection']['1']['ExternalIPAddress']['_value'] ?? null;
            $ip_c   = $d['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'][$i]['WANIPConnection']['1']['ExternalIPAddress']['_value'] ?? null;
            if ($ppp_ip && $ppp_ip !== '0.0.0.0' && $wan_ip === 'N/A') $wan_ip = $ppp_ip;
            if ($ip_c && $ip_c !== '0.0.0.0' && $wan_ip === 'N/A') $wan_ip = $ip_c;
        }
        if ($wan_ip === 'N/A') {
            $tr181_ip = $d['Device']['IP']['Interface']['1']['IPv4Address']['1']['IPAddress']['_value'] ?? null;
            if ($tr181_ip && $tr181_ip !== '0.0.0.0') $wan_ip = $tr181_ip;
        }

        $connUrl = $d['InternetGatewayDevice']['ManagementServer']['ConnectionRequestURL']['_value']
            ?? $d['Device']['ManagementServer']['ConnectionRequestURL']['_value']
            ?? null;
        $tr069ip = 'N/A';
        if ($connUrl && preg_match('/https?:\/\/([^:\/]+)/', $connUrl, $m)) {
            $tr069ip = $m[1];
        }

        $rxPowerRaw = null;
        $rxPower = $d['VirtualParameters']['RXPower']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_CT-COM_EponInterfaceConfig']['RXPower']['_value']
            ?? $d['Device']['Optical']['Interface']['1']['RxPower']['_value']
            ?? null;
        if ($rxPower !== null && is_numeric($rxPower)) {
            $rx = (float)$rxPower;
            if ($rx > 100) {
                $rx = ($rx / 100) - 40;
            }
            $rxPowerRaw = round($rx, 2);
            $rxPower    = number_format($rx, 2) . ' dBm';
        } else {
            $rxPower = 'N/A';
        }

        $tempRaw = null;
        $temp = $d['VirtualParameters']['gettemp']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_CT-COM_EponInterfaceConfig']['TransceiverTemperature']['_value']
            ?? $d['VirtualParameters']['Temperature']['_value']
            ?? $d['InternetGatewayDevice']['DeviceInfo']['Temperature']['_value']
            ?? null;
        if ($temp !== null && is_numeric($temp)) {
            $t = (float)$temp;
            if ($t > 1000) {
                $t = $t / 256;
            }
            $tempRaw = round($t, 1);
            $temp    = number_format($t, 1) . ' °C';
        } else {
            $temp = 'N/A';
        }

        $fullname    = '';
        $customer_id = 0;
        if ($pppoe !== 'N/A') {
            $cust = ORM::for_table('tbl_customers')
                ->where_raw('(pppoe_username = ? OR username = ?)', [$pppoe, $pppoe])
                ->find_one();
            if ($cust) {
                $fullname    = $cust['fullname'];
                $customer_id = (int)$cust['id'];
            }
        }
        // Fallback pencocokan IP untuk jaringan HTB Konverter (DHCP / Static IP)
        if ($fullname === '') {
            $ip_candidate = ($tr069ip !== 'N/A') ? $tr069ip : (($wan_ip !== 'N/A') ? $wan_ip : '');
            if ($ip_candidate !== '') {
                $cust = ORM::for_table('tbl_customers')->where('pppoe_ip', $ip_candidate)->find_one();
                if ($cust) {
                    $fullname    = $cust['fullname'];
                    $customer_id = (int)$cust['id'];
                    if ($pppoe === 'N/A') $pppoe = $cust['username'];
                }
            }
        }

        $connectedDevices = 0;
        $wlanCfgs = genieacs_extract_wlan_configs($d);
        foreach ($wlanCfgs as $wc) {
            $connectedDevices += (int)($wc['connected_devices'] ?? 0);
        }

        $lastInform = $d['_lastInform'] ?? '';
        $isOnline   = false;
        if ($lastInform !== '') {
            $ts = strtotime($lastInform);
            if ($ts !== false && (time() - $ts) < 300) {
                $isOnline = true;
            }
        }

        return [
            'id'                => $d['_id'] ?? '',
            'serial_number'     => $d['_deviceId']['_SerialNumber'] ?? ($d['_id'] ?? '-'),
            'manufacturer'      => $d['_deviceId']['_Manufacturer'] ?? '-',
            'product_class'     => $d['_deviceId']['_ProductClass'] ?? '-',
            'oui'               => $d['_deviceId']['_OUI'] ?? '-',
            'software_version'  => $d['InternetGatewayDevice']['DeviceInfo']['SoftwareVersion']['_value'] ?? '-',
            'hardware_version'  => $d['InternetGatewayDevice']['DeviceInfo']['HardwareVersion']['_value'] ?? '-',
            'uptime'            => genieacs_format_uptime($d['InternetGatewayDevice']['DeviceInfo']['UpTime']['_value'] ?? null),
            'pppoe_username'    => $pppoe,
            'wan_ip'            => $wan_ip,
            'tr069_ip'          => $tr069ip,
            'temperature'       => $temp,
            'temperature_raw'   => $tempRaw,
            'rx_power'          => $rxPower,
            'rx_power_raw'      => $rxPowerRaw,
            'fullname'          => $fullname,
            'customer_id'       => $customer_id,
            'connected_devices' => $connectedDevices,
            'online'            => $isOnline,
            'last_inform'       => $lastInform ?: '-',
            'tags'              => isset($d['_tags']) && is_array($d['_tags']) ? array_values($d['_tags']) : []
        ];
    }
}

switch ($action) {
    // =========================================================================
    // 1. MANAJEMEN HOST GENIEACS (PORT 7557) - ADMIN
    // =========================================================================
    case 'admin_genieacs_hosts':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hosts = genieacs_get_hosts();
        api_out(true, 'Daftar host GenieACS dimuat', [
            'hosts'       => $hosts,
            'active_host' => genieacs_resolve_host()
        ]);
        break;

    case 'admin_genieacs_host_save':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId   = trim(_post('id'));
        $name     = trim(_post('name'));
        $hostAddr = trim(_post('host'));
        $port     = (int)(_post('port') ?: 7557);
        $username = trim(_post('username'));
        $password = _post('password') ?? '';

        if ($name === '' || $hostAddr === '') {
            api_out(false, 'Nama Server dan Host/IP GenieACS wajib diisi.', null, 400);
        }

        $hosts = genieacs_get_hosts();
        if ($hostId !== '') {
            $found = false;
            foreach ($hosts as &$h) {
                if (($h['id'] ?? '') === $hostId) {
                    $h['name']     = $name;
                    $h['host']     = $hostAddr;
                    $h['port']     = $port;
                    $h['username'] = $username;
                    $h['password'] = $password;
                    $found = true;
                    break;
                }
            }
            unset($h);
            if (!$found) api_out(false, 'Host GenieACS tidak ditemukan.', null, 404);
        } else {
            $hosts[] = [
                'id'       => uniqid('gacs_'),
                'name'     => $name,
                'host'     => $hostAddr,
                'port'     => $port,
                'username' => $username,
                'password' => $password,
                'active'   => (count($hosts) === 0)
            ];
        }

        genieacs_save_hosts($hosts);
        api_out(true, 'Konfigurasi server GenieACS ' . $name . ' berhasil disimpan.', [
            'hosts'       => $hosts,
            'active_host' => genieacs_resolve_host()
        ]);
        break;

    case 'admin_genieacs_host_action':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $sub    = strtolower(trim(_req('sub_action') ?: 'test')); // test | setactive | delete
        $hostId = trim(_req('id'));
        $hosts  = genieacs_get_hosts();

        if ($sub === 'test') {
            $target = genieacs_resolve_host($hostId);
            if (!$target) api_out(false, 'Host GenieACS tidak ditemukan.', null, 404);
            $client = new GenieACSClient($target['host'], (int)($target['port'] ?? 7557), $target['username'] ?? '', $target['password'] ?? '');
            if ($client->testConnection()) {
                api_out(true, 'Koneksi ke GenieACS ' . $target['name'] . ' (' . $target['host'] . ':' . $target['port'] . ') BERHASIL!');
            }
            api_out(false, 'Gagal terhubung ke GenieACS ' . $target['name'] . '. Pastikan port ' . ($target['port'] ?? 7557) . ' dapat diakses.', null, 500);
        }

        if ($sub === 'setactive') {
            foreach ($hosts as &$h) {
                $h['active'] = (($h['id'] ?? '') === $hostId);
            }
            unset($h);
            genieacs_save_hosts($hosts);
            api_out(true, 'Server GenieACS aktif berhasil diubah.', ['hosts' => $hosts]);
        }

        if ($sub === 'delete') {
            $newHosts = [];
            foreach ($hosts as $h) {
                if (($h['id'] ?? '') !== $hostId) $newHosts[] = $h;
            }
            if (!empty($newHosts)) {
                $hasActive = false;
                foreach ($newHosts as $nh) {
                    if (!empty($nh['active'])) { $hasActive = true; break; }
                }
                if (!$hasActive) $newHosts[0]['active'] = true;
            }
            genieacs_save_hosts($newHosts);
            api_out(true, 'Host GenieACS berhasil dihapus.', ['hosts' => $newHosts]);
        }
        api_out(false, 'Aksi host tidak dikenali.', null, 400);
        break;

    // =========================================================================
    // 2. DASHBOARD & DAFTAR PERANGKAT TR-069 - ADMIN
    // =========================================================================
    case 'admin_genieacs_dashboard':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId     = trim(_req('host_id'));
        $hosts      = genieacs_get_hosts();
        $activeHost = genieacs_resolve_host($hostId);
        $client     = genieacs_load_config($hostId);

        $stats = ['total' => 0, 'online' => 0, 'offline' => 0];
        $recentDevices  = [];
        $offlineDevices = [];

        if ($client) {
            $result = $client->getDevices([], 500, 0);
            if (!empty($result['success']) && is_array($result['data'])) {
                $rawList = $result['data'];
                $stats['total'] = count($rawList);
                foreach ($rawList as $idx => $d) {
                    $info = genieacs_extract_device_info($d);
                    if ($info['online']) {
                        $stats['online']++;
                    } else {
                        $stats['offline']++;
                        if (count($offlineDevices) < 25) {
                            $offlineDevices[] = $info;
                        }
                    }
                    if ($idx < 15) {
                        $recentDevices[] = $info;
                    }
                }
            }
        }

        api_out(true, 'Dashboard GenieACS dimuat', [
            'hosts'           => $hosts,
            'active_host'     => $activeHost,
            'stats'           => $stats,
            'recent_devices'  => $recentDevices,
            'offline_devices' => $offlineDevices
        ]);
        break;

    case 'admin_genieacs_devices':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId     = trim(_req('host_id'));
        $search     = trim(_req('search'));
        $page       = max(1, (int)(_req('page') ?: 1));
        $limit      = max(10, min(200, (int)(_req('limit') ?: 50)));
        $skip       = ($page - 1) * $limit;

        $hosts      = genieacs_get_hosts();
        $activeHost = genieacs_resolve_host($hostId);
        $client     = genieacs_load_config($hostId);

        if (!$client) {
            api_out(false, 'Belum ada server GenieACS yang dikonfigurasi.', ['hosts' => $hosts], 400);
        }

        $query = [];
        if ($search !== '') {
            $query = ['$or' => [
                ['_id' => ['$regex' => $search, '$options' => 'i']],
                ['_deviceId._SerialNumber' => ['$regex' => $search, '$options' => 'i']],
                ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username._value' => ['$regex' => $search, '$options' => 'i']],
                ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username._value' => ['$regex' => $search, '$options' => 'i']]
            ]];
            $customers = ORM::for_table('tbl_customers')->where_like('fullname', "%{$search}%")->find_many();
            foreach ($customers as $c) {
                $u = $c['pppoe_username'] ?: $c['username'];
                if (!empty($u)) {
                    $query['$or'][] = ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username._value' => ['$regex' => '^' . preg_quote($u, '/') . '$', '$options' => 'i']];
                    $query['$or'][] = ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username._value' => ['$regex' => '^' . preg_quote($u, '/') . '$', '$options' => 'i']];
                }
            }
        }

        $devices = [];
        $total   = 0;
        $result  = $client->getDevices($query, $limit, $skip);
        if (!empty($result['success']) && is_array($result['data'])) {
            foreach ($result['data'] as $d) {
                $devices[] = genieacs_extract_device_info($d);
            }
        }
        $countRes = $client->getDevices($query, 0, 0);
        if (!empty($countRes['success']) && is_array($countRes['data'])) {
            $total = count($countRes['data']);
        }

        api_out(true, 'Daftar perangkat GenieACS dimuat', [
            'hosts'       => $hosts,
            'active_host' => $activeHost,
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'devices'     => $devices
        ]);
        break;

    case 'admin_genieacs_device_detail':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId   = trim(_req('host_id'));
        $deviceId = trim(_req('id') ?: _req('device_id'));
        if ($deviceId === '') api_out(false, 'Device ID wajib diisi.', null, 400);

        $client = genieacs_load_config($hostId);
        if (!$client) api_out(false, 'Server GenieACS belum dikonfigurasi.', null, 400);

        $res = $client->getDevice($deviceId);
        if (empty($res['success']) || empty($res['data'])) {
            api_out(false, 'Perangkat tidak ditemukan di GenieACS.', null, 404);
        }

        $dev  = $res['data'];
        $info = genieacs_extract_device_info($dev);
        $wans = genieacs_extract_wan_connections($dev);
        $wlan = genieacs_extract_wlan_configs($dev);

        api_out(true, 'Detail perangkat GenieACS dimuat', [
            'device'          => $info,
            'wan_connections' => $wans,
            'wlan_configs'    => $wlan,
            'active_host'     => genieacs_resolve_host($hostId)
        ]);
        break;

    case 'admin_genieacs_device_action':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId   = trim(_req('host_id'));
        $deviceId = trim(_req('id') ?: _req('device_id'));
        $sub      = strtolower(trim(_req('sub_action') ?: 'summon')); // summon | reboot

        if ($deviceId === '') api_out(false, 'Device ID wajib diisi.', null, 400);
        $client = genieacs_load_config($hostId);
        if (!$client) api_out(false, 'Server GenieACS belum dikonfigurasi.', null, 400);

        $res = ($sub === 'reboot') ? $client->rebootDevice($deviceId) : $client->summonDevice($deviceId);
        if (!empty($res['success'])) {
            api_out(true, ($sub === 'reboot') ? 'Perintah Reboot berhasil dikirim ke perangkat.' : 'Perintah Summon (Connection Request) berhasil dikirim.');
        }
        api_out(false, 'Gagal mengirim perintah: ' . ($res['error'] ?? 'HTTP ' . ($res['http_code'] ?? 500)), null, 500);
        break;

    case 'admin_genieacs_wan_save':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId    = trim(_req('host_id'));
        $deviceId  = trim(_post('id') ?: _post('device_id'));
        $connIndex = max(1, min(8, (int)(_post('connection_index') ?: 1)));
        $connType  = (_post('connection_type') === 'ip') ? 'ip' : 'ppp';
        $username  = trim((string)_post('username'));
        $password  = trim((string)_post('password'));
        $vlan      = trim((string)_post('vlan'));
        $nat       = trim((string)_post('nat'));

        if ($deviceId === '') api_out(false, 'Device ID wajib diisi.', null, 400);
        $client = genieacs_load_config($hostId);
        if (!$client) api_out(false, 'Server GenieACS belum dikonfigurasi.', null, 400);

        $basePath = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$connIndex}."
            . ($connType === 'ppp' ? 'WANPPPConnection.1' : 'WANIPConnection.1');

        $params = [];
        if ($username !== '') {
            $params[] = [$basePath . '.Username', $username, 'xsd:string'];
        }
        if ($password !== '') {
            $params[] = [$basePath . '.Password', $password, 'xsd:string'];
        }
        if ($vlan !== '' && is_numeric($vlan)) {
            $params[] = ["InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$connIndex}.X_CT-COM_VLANID", (int)$vlan, 'xsd:unsignedInt'];
        }
        if ($nat === '1' || $nat === '0') {
            $params[] = [$basePath . '.NATEnabled', ($nat === '1'), 'xsd:boolean'];
        }

        if (empty($params)) {
            api_out(false, 'Tidak ada parameter WAN yang diubah.', null, 400);
        }

        $res = $client->setParameterValues($deviceId, $params, 5000);
        if (!empty($res['success'])) {
            api_out(true, 'Konfigurasi WAN #' . $connIndex . ' berhasil dikirim ke perangkat.');
        }
        api_out(false, 'Gagal memperbarui WAN: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        break;

    case 'admin_genieacs_wlan_save':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId    = trim(_req('host_id'));
        $deviceId  = trim(_post('id') ?: _post('device_id'));
        $wlanIndex = max(1, min(8, (int)(_post('wlan_index') ?: 1)));
        $ssid      = trim((string)_post('ssid'));
        $password  = trim((string)_post('password'));
        $security  = trim(_post('security_mode') ?: 'WPA2PSK');
        $source    = strtolower(trim(_post('source') ?: 'tr098'));

        if ($deviceId === '' || $ssid === '') {
            api_out(false, 'Device ID dan Nama WiFi (SSID) wajib diisi.', null, 400);
        }
        $client = genieacs_load_config($hostId);
        if (!$client) api_out(false, 'Server GenieACS belum dikonfigurasi.', null, 400);

        $params = [];
        if ($source === 'tr181') {
            $params[] = ["Device.WiFi.SSID.{$wlanIndex}.SSID", $ssid, 'xsd:string'];
            if ($security !== 'None' && $password !== '') {
                $params[] = ["Device.WiFi.AccessPoint.{$wlanIndex}.Security.KeyPassphrase", $password, 'xsd:string'];
            }
        } else {
            $baseWlan = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}";
            $params[] = ["{$baseWlan}.SSID", $ssid, 'xsd:string'];
            $beaconTypeMap = [
                'WPA2PSK'       => '11i',
                'WPAPSK'        => 'WPA',
                'WPA2PSKWPAPSK' => 'WPAand11i',
                'None'          => 'Basic'
            ];
            $beaconType = $beaconTypeMap[$security] ?? '11i';
            $params[] = ["{$baseWlan}.BeaconType", $beaconType, 'xsd:string'];

            if ($security !== 'None' && $password !== '') {
                $params[] = ["{$baseWlan}.KeyPassphrase", $password, 'xsd:string'];
                $params[] = ["{$baseWlan}.WPAAuthenticationMode", 'PSKAuthentication', 'xsd:string'];
                $encMode  = ($security === 'WPA2PSK' || $security === 'WPA2PSKWPAPSK') ? 'AESEncryption' : 'TKIPEncryption';
                $params[] = ["{$baseWlan}.WPAEncryptionModes", $encMode, 'xsd:string'];
            }
        }

        $res = $client->setParameterValues($deviceId, $params, 10000);
        if (!empty($res['success'])) {
            api_out(true, 'Konfigurasi WiFi (SSID: ' . $ssid . ') berhasil diperbarui!');
        }
        api_out(false, 'Gagal mengubah WiFi: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        break;

    // =========================================================================
    // 3. GENIEACS PORTAL MANDIRI PELANGGAN (MY DEVICE) - APK PELANGGAN
    // =========================================================================
    case 'customer_genieacs_dashboard':
        $customer = require_customer_session($api_secret);
        $client   = genieacs_load_config();
        if (!$client) {
            api_out(false, 'Server manajemen modem belum dikonfigurasi oleh Admin.', null, 400);
        }

        $search = $customer['pppoe_username'] ?: $customer['username'];
        $device = null;
        if (!empty($search)) {
            $regex = ['$regex' => '^' . preg_quote($search, '/') . '$', '$options' => 'i'];
            $query = ['$or' => [
                ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username._value' => $regex],
                ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username._value' => $regex],
                ['_deviceId._SerialNumber' => $regex],
                ['_id' => $regex]
            ]];
            $res = $client->getDevices($query, 1, 0);
            if (!empty($res['success']) && !empty($res['data'][0])) {
                $device = $res['data'][0];
            }
        }

        if (!$device) {
            api_out(false, 'Perangkat modem/router belum terdeteksi untuk akun Anda.', null, 404);
        }

        api_out(true, 'Status perangkat berhasil dimuat', [
            'device'       => genieacs_extract_device_info($device),
            'wlan_configs' => genieacs_extract_wlan_configs($device)
        ]);
        break;

    case 'customer_genieacs_wlan_save':
        $customer  = require_customer_session($api_secret);
        $deviceId  = trim(_post('id') ?: _post('device_id'));
        $wlanIndex = max(1, min(8, (int)(_post('wlan_index') ?: 1)));
        $ssid      = trim((string)_post('ssid'));
        $password  = trim((string)_post('password'));
        $security  = trim(_post('security_mode') ?: 'WPA2PSK');

        if ($deviceId === '' || $ssid === '') {
            api_out(false, 'Nama WiFi (SSID) tidak boleh kosong.', null, 400);
        }
        $client = genieacs_load_config();
        if (!$client) api_out(false, 'Server GenieACS tidak tersedia.', null, 400);

        $baseWlan = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$wlanIndex}";
        $beaconTypeMap = ['WPA2PSK' => '11i', 'WPAPSK' => 'WPA', 'WPA2PSKWPAPSK' => 'WPAand11i', 'None' => 'Basic'];
        $params = [
            ["{$baseWlan}.SSID", $ssid, 'xsd:string'],
            ["{$baseWlan}.BeaconType", $beaconTypeMap[$security] ?? '11i', 'xsd:string']
        ];
        if ($security !== 'None' && $password !== '') {
            $params[] = ["{$baseWlan}.KeyPassphrase", $password, 'xsd:string'];
            $params[] = ["{$baseWlan}.WPAAuthenticationMode", 'PSKAuthentication', 'xsd:string'];
            $encMode  = ($security === 'WPA2PSK' || $security === 'WPA2PSKWPAPSK') ? 'AESEncryption' : 'TKIPEncryption';
            $params[] = ["{$baseWlan}.WPAEncryptionModes", $encMode, 'xsd:string'];
        }

        $res = $client->setParameterValues($deviceId, $params, 10000);
        if (!empty($res['success'])) {
            api_out(true, 'Nama WiFi & Password berhasil diperbarui!');
        }
        api_out(false, 'Gagal memperbarui WiFi: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        break;

    case 'customer_genieacs_reboot':
        $customer = require_customer_session($api_secret);
        $deviceId = trim(_req('id') ?: _req('device_id'));
        if ($deviceId === '') api_out(false, 'Device ID diperlukan.', null, 400);
        $client = genieacs_load_config();
        if (!$client) api_out(false, 'Server GenieACS tidak tersedia.', null, 400);

        $res = $client->rebootDevice($deviceId);
        if (!empty($res['success'])) {
            api_out(true, 'Perintah restart modem berhasil dikirim.');
        }
        api_out(false, 'Gagal me-restart modem: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        break;
}
