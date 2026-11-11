<?php
/**
 * PHPNuxBill Mobile API Plugin - GenieACS TR-069 Pro Module (Admin & Customer)
 * Path: system/plugin/mobile_api_modules/plugin_genieacs.php
 * Supports HTTP/HTTPS, Multi-Host, Optical Diagnostics, WAN/WLAN TR-098 & TR-181,
 * Connected Hosts (Active WiFi/LAN Clients), Tags, Raw JSON, and Customer Self-Service Portal.
 */

if (!class_exists('GenieACSClient')) {
    class GenieACSClient
    {
        private $host;
        private $port;
        private $protocol;
        private $username;
        private $password;
        private $baseUrl;

        public function __construct($host = null, $port = 7557, $username = null, $password = null, $protocol = 'auto')
        {
            $rawHost        = trim((string)$host);
            $this->port     = (int)$port;
            $this->username = trim((string)$username);
            $this->password = (string)$password;
            $this->protocol = strtolower(trim((string)$protocol));

            $this->baseUrl = $this->buildBaseUrl($rawHost, $this->port, $this->protocol);
        }

        private function buildBaseUrl($rawHost, $port, $protocol)
        {
            if ($rawHost === '') {
                return '';
            }

            $detectedScheme = '';
            if (preg_match('#^(https?)://#i', $rawHost, $m)) {
                $detectedScheme = strtolower($m[1]);
                $rawHost = preg_replace('#^https?://#i', '', $rawHost);
            }

            $rawHost = rtrim($rawHost, '/');

            // Pisahkan path jika host menggunakan subfolder reverse proxy
            $hostParts = explode('/', $rawHost, 2);
            $hostOnly  = $hostParts[0];
            $subPath   = isset($hostParts[1]) ? '/' . $hostParts[1] : '';

            // Cek apakah port sudah tertulis langsung di dalam string host (misal 192.168.1.10:7557)
            $explicitPortInHost = false;
            if (strpos($hostOnly, ':') !== false) {
                $explicitPortInHost = true;
            }

            if ($protocol === 'https' || $protocol === 'http') {
                $scheme = $protocol;
            } elseif ($detectedScheme !== '') {
                $scheme = $detectedScheme;
            } elseif ($port === 443) {
                $scheme = 'https';
            } else {
                $scheme = 'http';
            }

            if ($explicitPortInHost) {
                return "{$scheme}://{$hostOnly}{$subPath}";
            }

            // Jangan tambahkan :port jika menggunakan port standar HTTP (80) / HTTPS (443) atau port <= 0
            if ($port <= 0 || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
                return "{$scheme}://{$hostOnly}{$subPath}";
            }

            return "{$scheme}://{$hostOnly}:{$port}{$subPath}";
        }

        public function getBaseUrl()
        {
            return $this->baseUrl;
        }

        private function request($endpoint, $method = 'GET', $data = null, $timeout = 30)
        {
            if (!function_exists('curl_init')) {
                return ['success' => false, 'error' => 'Ekstensi PHP cURL belum aktif di server.', 'http_code' => 500];
            }
            if ($this->baseUrl === '') {
                return ['success' => false, 'error' => 'Host GenieACS belum dikonfigurasi.', 'http_code' => 400];
            }

            $url = $this->baseUrl . $endpoint;
            $ch  = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, max(5, (int)$timeout));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            $headers = ['Accept: application/json'];

            if ($this->username !== '') {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC | CURLAUTH_DIGEST);
                curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
            }

            $method = strtoupper($method);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data !== null) {
                    $jsonBody = is_string($data) ? $data : json_encode($data);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
                    $headers[] = 'Content-Type: application/json';
                    $headers[] = 'Content-Length: ' . strlen($jsonBody);
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, '');
                    $headers[] = 'Content-Length: 0';
                }
            } elseif ($method === 'PUT') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data !== null) {
                    $jsonBody = is_string($data) ? $data : json_encode($data);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
                    $headers[] = 'Content-Type: application/json';
                }
            } elseif ($method === 'DELETE') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $t0       = microtime(true);
            $response = curl_exec($ch);
            $pingMs   = (int)round((microtime(true) - $t0) * 1000);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return [
                    'success'   => false,
                    'error'     => $error,
                    'http_code' => $httpCode,
                    'ping_ms'   => $pingMs
                ];
            }

            $decoded = json_decode((string)$response, true);
            $isOk    = ($httpCode >= 200 && $httpCode < 300);

            return [
                'success'   => $isOk,
                'data'      => $decoded,
                'raw'       => $isOk ? null : substr(trim(strip_tags((string)$response)), 0, 200),
                'error'     => $isOk ? null : ('HTTP ' . $httpCode . (!empty($response) ? ': ' . substr(trim(strip_tags((string)$response)), 0, 120) : '')),
                'http_code' => $httpCode,
                'ping_ms'   => $pingMs
            ];
        }

        public function testConnection()
        {
            $res = $this->request('/devices/?limit=1&projection=_id', 'GET', null, 10);
            return $res;
        }

        public function getDevices($query = [], $limit = 100, $skip = 0, $projection = [])
        {
            $params = [];
            if (!empty($query)) {
                $params[] = 'query=' . rawurlencode(json_encode($query));
            }
            if ($limit > 0) {
                $params[] = 'limit=' . (int)$limit;
            }
            if ($skip > 0) {
                $params[] = 'skip=' . (int)$skip;
            }
            if (!empty($projection)) {
                $params[] = 'projection=' . rawurlencode(implode(',', $projection));
            }
            $queryString = !empty($params) ? '?' . implode('&', $params) : '';
            return $this->request('/devices/' . $queryString);
        }

        public function getDevice($deviceId)
        {
            $query = ['_id' => $deviceId];
            $queryString = '?query=' . rawurlencode(json_encode($query));
            $result = $this->request('/devices/' . $queryString);
            if (!empty($result['success']) && !empty($result['data'][0])) {
                return ['success' => true, 'data' => $result['data'][0]];
            }
            return ['success' => false, 'error' => $result['error'] ?? 'Perangkat tidak ditemukan di GenieACS.'];
        }

        public function rebootDevice($deviceId)
        {
            $encodedId = rawurlencode($deviceId);
            $endpoint  = "/devices/{$encodedId}/tasks?timeout=3000&connection_request";
            return $this->request($endpoint, 'POST', ['name' => 'reboot']);
        }

        public function factoryResetDevice($deviceId)
        {
            $encodedId = rawurlencode($deviceId);
            $endpoint  = "/devices/{$encodedId}/tasks?timeout=3000&connection_request";
            return $this->request($endpoint, 'POST', ['name' => 'factoryReset']);
        }

        public function summonDevice($deviceId, $refreshObject = false)
        {
            $encodedId = rawurlencode($deviceId);
            $endpoint  = "/devices/{$encodedId}/tasks?timeout=3000&connection_request";
            if ($refreshObject) {
                return $this->request($endpoint, 'POST', [
                    'name'       => 'refreshObject',
                    'objectName' => ''
                ]);
            }
            $res = $this->request($endpoint, 'POST', null);
            if (empty($res['success'])) {
                // Fallback jika proxy menolak POST kosong
                return $this->request($endpoint, 'POST', [
                    'name'       => 'refreshObject',
                    'objectName' => 'InternetGatewayDevice.DeviceInfo'
                ]);
            }
            return $res;
        }

        public function setParameterValues($deviceId, $parameters, $timeout = 5000)
        {
            $encodedId = rawurlencode($deviceId);
            $endpoint  = "/devices/{$encodedId}/tasks?timeout=" . (int)$timeout . "&connection_request";
            return $this->request($endpoint, 'POST', [
                'name'            => 'setParameterValues',
                'parameterValues' => $parameters
            ]);
        }

        public function deleteDevice($deviceId)
        {
            $encodedId = rawurlencode($deviceId);
            return $this->request("/devices/{$encodedId}", 'DELETE');
        }

        public function addTag($deviceId, $tag)
        {
            $encodedId  = rawurlencode($deviceId);
            $encodedTag = rawurlencode(trim($tag));
            return $this->request("/devices/{$encodedId}/tags/{$encodedTag}", 'POST', null);
        }

        public function removeTag($deviceId, $tag)
        {
            $encodedId  = rawurlencode($deviceId);
            $encodedTag = rawurlencode(trim($tag));
            return $this->request("/devices/{$encodedId}/tags/{$encodedTag}", 'DELETE');
        }

        public function getDeviceTasks($deviceId)
        {
            $query = ['device' => $deviceId];
            return $this->request('/tasks/?query=' . rawurlencode(json_encode($query)));
        }

        public function deleteTask($taskId)
        {
            return $this->request('/tasks/' . rawurlencode($taskId), 'DELETE');
        }
    }
}

if (!function_exists('genieacs_default_projections')) {
    function genieacs_default_projections()
    {
        return [
            '_id',
            '_deviceId',
            '_lastInform',
            '_tags',
            'VirtualParameters',
            'InternetGatewayDevice.DeviceInfo',
            'InternetGatewayDevice.ManagementServer.ConnectionRequestURL',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig',
            'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig',
            'InternetGatewayDevice.WANDevice.1.X_CU_GponInterfaceConfig',
            'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration',
            'Device.DeviceInfo',
            'Device.ManagementServer.ConnectionRequestURL',
            'Device.Optical.Interface.1',
            'Device.IP.Interface.1.IPv4Address.1.IPAddress',
            'Device.WiFi'
        ];
    }
}

if (!function_exists('genieacs_get_hosts')) {
    function genieacs_get_hosts()
    {
        $d = ORM::for_table('tbl_appconfig')->where('setting', 'genieacs_hosts')->find_one();
        $raw = $d ? $d['value'] : ($GLOBALS['config']['genieacs_hosts'] ?? '[]');
        $hosts = json_decode((string)$raw, true);
        if (!is_array($hosts)) {
            return [];
        }
        foreach ($hosts as &$h) {
            if (empty($h['protocol'])) {
                if (preg_match('#^https://#i', $h['host'] ?? '') || (int)($h['port'] ?? 7557) === 443) {
                    $h['protocol'] = 'https';
                } else {
                    $h['protocol'] = 'http';
                }
            }
            $client = new GenieACSClient($h['host'] ?? '', (int)($h['port'] ?? 7557), $h['username'] ?? '', $h['password'] ?? '', $h['protocol']);
            $h['full_url'] = $client->getBaseUrl();
        }
        unset($h);
        return array_values($hosts);
    }
}

if (!function_exists('genieacs_save_hosts')) {
    function genieacs_save_hosts($hosts)
    {
        $clean = [];
        foreach ($hosts as $h) {
            unset($h['full_url']);
            $clean[] = $h;
        }
        $json = json_encode(array_values($clean), JSON_UNESCAPED_SLASHES);
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
            $h['password'] ?? '',
            $h['protocol'] ?? 'auto'
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
            if ($wlan && is_array($wlan)) {
                $ssid = $wlan['SSID']['_value'] ?? null;
                if ($ssid === null && !isset($wlan['Enable'])) continue;

                $password = $wlan['KeyPassphrase']['_value']
                    ?? $wlan['PreSharedKey']['1']['KeyPassphrase']['_value']
                    ?? $wlan['PreSharedKey']['1']['PreSharedKey']['_value']
                    ?? '';
                $beacon = (string)($wlan['BeaconType']['_value'] ?? '11i');
                $secMode = 'WPA2PSK';
                $secLabel = 'WPA2-PSK';
                if ($beacon === 'WPA') {
                    $secMode = 'WPAPSK';
                    $secLabel = 'WPA-PSK';
                } elseif ($beacon === 'WPAand11i' || $beacon === 'WPA/WPA2') {
                    $secMode = 'WPA2PSKWPAPSK';
                    $secLabel = 'WPA/WPA2-PSK';
                } elseif ($beacon === 'Basic' || $beacon === 'None') {
                    $secMode = 'None';
                    $secLabel = 'Open';
                }

                $channel = $wlan['Channel']['_value'] ?? '-';
                $standard = (string)($wlan['Standard']['_value'] ?? '');
                $band = ((int)$channel > 14 || stripos($standard, 'ac') !== false || stripos($standard, 'a') !== false || $i >= 5)
                    ? '5GHz'
                    : '2.4GHz';

                $enableVal = $wlan['Enable']['_value'] ?? true;
                $isEnabled = ($enableVal === true || $enableVal === 1 || $enableVal === '1' || $enableVal === 'true');

                $configs[] = [
                    'index'             => $i,
                    'ssid'              => (string)($ssid ?? ''),
                    'password'          => (string)$password,
                    'enable'            => $isEnabled,
                    'channel'           => (string)$channel,
                    'band'              => $band,
                    'beacon_type'       => $beacon,
                    'security_mode'     => $secMode,
                    'security'          => $secLabel,
                    'mac'               => (string)($wlan['BSSID']['_value'] ?? '-'),
                    'standard'          => $standard !== '' ? $standard : $band,
                    'max_bitrate'       => (string)($wlan['MaxBitRate']['_value'] ?? '-'),
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
            if ($ssidEntry && is_array($ssidEntry)) {
                $ap       = $device['Device']['WiFi']['AccessPoint'][$i] ?? [];
                $sec      = $ap['Security'] ?? [];
                $radio    = $device['Device']['WiFi']['Radio'][$i] ?? [];
                $password = $sec['KeyPassphrase']['_value'] ?? $sec['PreSharedKey']['_value'] ?? '';
                $beacon   = (string)($sec['ModeEnabled']['_value'] ?? 'WPA2-Personal');

                $secMode = 'WPA2PSK';
                if (stripos($beacon, 'WPA-WPA2') !== false) $secMode = 'WPA2PSKWPAPSK';
                elseif (stripos($beacon, 'WPA-') !== false && stripos($beacon, 'WPA2') === false) $secMode = 'WPAPSK';
                elseif (stripos($beacon, 'None') !== false) $secMode = 'None';

                $channel = $radio['Channel']['_value'] ?? '-';
                $freqBand = (string)($radio['OperatingFrequencyBand']['_value'] ?? '');
                $band = ((int)$channel > 14 || stripos($freqBand, '5') !== false) ? '5GHz' : '2.4GHz';

                $enableVal = $ssidEntry['Enable']['_value'] ?? ($ap['Enable']['_value'] ?? true);
                $isEnabled = ($enableVal === true || $enableVal === 1 || $enableVal === '1' || $enableVal === 'true');

                $configs[] = [
                    'index'             => $i,
                    'ssid'              => (string)($ssidEntry['SSID']['_value'] ?? ''),
                    'password'          => (string)$password,
                    'enable'            => $isEnabled,
                    'channel'           => (string)$channel,
                    'band'              => $band,
                    'beacon_type'       => $beacon,
                    'security_mode'     => $secMode,
                    'security'          => $beacon,
                    'mac'               => (string)($ap['BSSID']['_value'] ?? ($ssidEntry['BSSID']['_value'] ?? '-')),
                    'standard'          => (string)($radio['Standard']['_value'] ?? ($freqBand ?: $band)),
                    'max_bitrate'       => (string)($radio['SupportedFrequencies']['_value'] ?? '-'),
                    'connected_devices' => (int)($ap['AssociatedDeviceNumberOfEntries']['_value'] ?? ($ap['TotalAssociations']['_value'] ?? 0)),
                    'source'            => 'tr181'
                ];
            }
        }
        return $configs;
    }
}

if (!function_exists('genieacs_extract_wan_connections')) {
    function genieacs_extract_wan_connections($device, &$availIndex = null)
    {
        $wanConnections = [];
        $usedIndexes    = [];

        for ($i = 1; $i <= 8; $i++) {
            $wanDev = $device['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'][$i] ?? null;
            if (!$wanDev || !is_array($wanDev)) continue;

            $ppp = $wanDev['WANPPPConnection']['1'] ?? null;
            $ip  = $wanDev['WANIPConnection']['1'] ?? null;
            if ($ppp || $ip) {
                $conn = $ppp ?: $ip;
                $type = $ppp ? 'ppp' : 'ip';
                $vlan = $wanDev['X_CT-COM_VLANID']['_value']
                    ?? $wanDev['X_HW_VLAN']['_value']
                    ?? $conn['X_HW_VLAN']['_value']
                    ?? $conn['X_ZTE-COM_VLANID']['_value']
                    ?? $wanDev['X_FH_VLANID']['_value']
                    ?? null;

                $natVal    = $conn['NATEnabled']['_value'] ?? false;
                $enableVal = $conn['Enable']['_value'] ?? true;

                $wanConnections[] = [
                    'index'             => $i,
                    'type'              => $type,
                    'name'              => (string)($conn['Name']['_value'] ?? "WAN #{$i}"),
                    'username'          => (string)($ppp['Username']['_value'] ?? ''),
                    'password'          => (string)($ppp['Password']['_value'] ?? ''),
                    'external_ip'       => (string)($conn['ExternalIPAddress']['_value'] ?? '-'),
                    'connection_type'   => (string)($conn['ConnectionType']['_value'] ?? '-'),
                    'connection_status' => (string)($conn['ConnectionStatus']['_value'] ?? '-'),
                    'nat'               => ($natVal === true || $natVal === 1 || $natVal === '1' || $natVal === 'true'),
                    'enable'            => ($enableVal === true || $enableVal === 1 || $enableVal === '1' || $enableVal === 'true'),
                    'gateway'           => (string)($conn['DefaultGateway']['_value'] ?? ($ppp['RemoteIPAddress']['_value'] ?? '-')),
                    'subnet'            => (string)($conn['SubnetMask']['_value'] ?? '-'),
                    'dns'               => (string)($conn['DNSServers']['_value'] ?? '-'),
                    'mac'               => (string)($conn['MACAddress']['_value'] ?? '-'),
                    'vlan'              => $vlan !== null ? (string)$vlan : ''
                ];
                $usedIndexes[] = $i;
            }
        }

        $availIndex = null;
        for ($i = 1; $i <= 8; $i++) {
            if (!in_array($i, $usedIndexes)) {
                $availIndex = $i;
                break;
            }
        }

        return $wanConnections;
    }
}

if (!function_exists('genieacs_extract_connected_hosts')) {
    function genieacs_extract_connected_hosts($device)
    {
        $hostsList = [];
        $seenMacs  = [];

        // 1. TR-098 LANDevice Hosts
        $tr098Hosts = $device['InternetGatewayDevice']['LANDevice']['1']['Hosts']['Host'] ?? [];
        if (is_array($tr098Hosts)) {
            foreach ($tr098Hosts as $idx => $h) {
                if (!is_array($h) || strpos((string)$idx, '_') === 0) continue;
                $ip   = (string)($h['IPAddress']['_value'] ?? '');
                $mac  = strtoupper((string)($h['MACAddress']['_value'] ?? ''));
                $name = trim((string)($h['HostName']['_value'] ?? ''));
                if ($ip === '' && $mac === '') continue;

                $actVal    = $h['Active']['_value'] ?? true;
                $isActive  = ($actVal === true || $actVal === 1 || $actVal === '1' || $actVal === 'true');
                $ifaceType = (string)($h['InterfaceType']['_value'] ?? 'WiFi');
                $connLabel = (stripos($ifaceType, '802.11') !== false || stripos($ifaceType, 'wireless') !== false || stripos($ifaceType, 'wifi') !== false)
                    ? 'WiFi'
                    : ((stripos($ifaceType, 'ethernet') !== false) ? 'LAN' : $ifaceType);

                if ($mac !== '') $seenMacs[$mac] = true;
                $hostsList[] = [
                    'index'          => (int)$idx,
                    'hostname'       => $name !== '' ? $name : 'Perangkat-' . substr(str_replace(':', '', $mac ?: $ip), -4),
                    'ip_address'     => $ip ?: '-',
                    'mac_address'    => $mac ?: '-',
                    'interface_type' => $connLabel,
                    'active'         => $isActive,
                    'signal'         => (string)($h['X_HW_RSSI']['_value'] ?? ($h['SignalStrength']['_value'] ?? ''))
                ];
            }
        }

        // 2. TR-181 Device.Hosts.Host
        $tr181Hosts = $device['Device']['Hosts']['Host'] ?? [];
        if (is_array($tr181Hosts)) {
            foreach ($tr181Hosts as $idx => $h) {
                if (!is_array($h) || strpos((string)$idx, '_') === 0) continue;
                $ip   = (string)($h['IPAddress']['_value'] ?? '');
                $mac  = strtoupper((string)($h['PhysAddress']['_value'] ?? ''));
                if ($mac !== '' && isset($seenMacs[$mac])) continue;
                if ($ip === '' && $mac === '') continue;

                $name     = trim((string)($h['HostName']['_value'] ?? ''));
                $actVal   = $h['Active']['_value'] ?? true;
                $isActive = ($actVal === true || $actVal === 1 || $actVal === '1' || $actVal === 'true');

                $hostsList[] = [
                    'index'          => (int)$idx,
                    'hostname'       => $name !== '' ? $name : 'Perangkat-' . substr(str_replace(':', '', $mac ?: $ip), -4),
                    'ip_address'     => $ip ?: '-',
                    'mac_address'    => $mac ?: '-',
                    'interface_type' => 'WiFi/LAN',
                    'active'         => $isActive,
                    'signal'         => ''
                ];
            }
        }

        return $hostsList;
    }
}

if (!function_exists('genieacs_extract_device_info')) {
    function genieacs_extract_device_info($d)
    {
        $pppoe  = 'N/A';
        $wan_ip = 'N/A';
        for ($i = 1; $i <= 8; $i++) {
            $wanConn = $d['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'][$i] ?? null;
            if (!$wanConn) continue;

            $u = $wanConn['WANPPPConnection']['1']['Username']['_value'] ?? null;
            if ($u && $u !== '' && $u !== 'N/A' && $pppoe === 'N/A') {
                $pppoe = (string)$u;
            }
            $ppp_ip = $wanConn['WANPPPConnection']['1']['ExternalIPAddress']['_value'] ?? null;
            $ip_c   = $wanConn['WANIPConnection']['1']['ExternalIPAddress']['_value'] ?? null;
            if ($ppp_ip && $ppp_ip !== '0.0.0.0' && $wan_ip === 'N/A') $wan_ip = (string)$ppp_ip;
            if ($ip_c && $ip_c !== '0.0.0.0' && $wan_ip === 'N/A') $wan_ip = (string)$ip_c;
        }
        if ($wan_ip === 'N/A') {
            $tr181_ip = $d['Device']['IP']['Interface']['1']['IPv4Address']['1']['IPAddress']['_value'] ?? null;
            if ($tr181_ip && $tr181_ip !== '0.0.0.0') $wan_ip = (string)$tr181_ip;
        }

        $connUrl = $d['InternetGatewayDevice']['ManagementServer']['ConnectionRequestURL']['_value']
            ?? $d['Device']['ManagementServer']['ConnectionRequestURL']['_value']
            ?? null;
        $tr069ip = 'N/A';
        if ($connUrl && preg_match('/https?:\/\/([^:\/]+)/', (string)$connUrl, $m)) {
            $tr069ip = $m[1];
        }

        // Ekstraksi Redaman Optik (RX Power) & Klasifikasi Status Warna sesuai genieacs_device.tpl
        $rxPowerRaw = null;
        $rxPowerVal = $d['VirtualParameters']['RXPower']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_CT-COM_EponInterfaceConfig']['RXPower']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_GponInterafceConfig']['RXPower']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_CU_GponInterfaceConfig']['RXPower']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_FH_GponInterfaceConfig']['RXPower']['_value']
            ?? $d['Device']['Optical']['Interface']['1']['RxPower']['_value']
            ?? null;

        $rxStatus = 'unknown';
        if ($rxPowerVal !== null && is_numeric($rxPowerVal)) {
            $rx = (float)$rxPowerVal;
            if ($rx > 100) {
                $rx = ($rx / 100) - 40;
            }
            $rxPowerRaw = round($rx, 2);
            $rxPower    = number_format($rx, 2) . ' dBm';

            if ($rxPowerRaw <= -33.1) {
                $rxStatus = 'los';
            } elseif ($rxPowerRaw < -28.0) {
                $rxStatus = 'critical';
            } elseif ($rxPowerRaw <= -23.0) {
                $rxStatus = 'warning';
            } elseif ($rxPowerRaw <= -17.0) {
                $rxStatus = 'good';
            } else {
                $rxStatus = 'over';
            }
        } else {
            $rxPower = 'N/A';
        }

        // Ekstraksi TX Power Optik
        $txPowerVal = $d['VirtualParameters']['TXPower']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_CT-COM_EponInterfaceConfig']['TXPower']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_GponInterafceConfig']['TXPower']['_value']
            ?? $d['Device']['Optical']['Interface']['1']['TxPower']['_value']
            ?? null;
        $txPower = 'N/A';
        if ($txPowerVal !== null && is_numeric($txPowerVal)) {
            $tx = (float)$txPowerVal;
            if ($tx > 100) $tx = ($tx / 100) - 40;
            $txPower = number_format($tx, 2) . ' dBm';
        }

        // Ekstraksi Suhu Perangkat & Klasifikasi Status Warna sesuai genieacs_device.tpl
        $tempRaw = null;
        $tempVal = $d['VirtualParameters']['gettemp']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_CT-COM_EponInterfaceConfig']['TransceiverTemperature']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_GponInterafceConfig']['TransceiverTemperature']['_value']
            ?? $d['VirtualParameters']['Temperature']['_value']
            ?? $d['InternetGatewayDevice']['DeviceInfo']['Temperature']['_value']
            ?? null;

        $tempStatus = 'unknown';
        if ($tempVal !== null && is_numeric($tempVal)) {
            $t = (float)$tempVal;
            if ($t > 1000) {
                $t = $t / 256;
            }
            $tempRaw = round($t, 1);
            $temp    = number_format($t, 1) . ' °C';

            if ($tempRaw < 40.0) {
                $tempStatus = 'good';
            } elseif ($tempRaw <= 50.0) {
                $tempStatus = 'warning';
            } else {
                $tempStatus = 'critical';
            }
        } else {
            $temp = 'N/A';
        }

        // Pencocokan ke tabel Pelanggan PHPNuxBill (PPPoE Username / Username / IP HTB)
        $fullname    = '';
        $customer_id = 0;
        $cust_phone  = '';
        if ($pppoe !== 'N/A') {
            $cust = ORM::for_table('tbl_customers')
                ->where_raw('(pppoe_username = ? OR username = ?)', [$pppoe, $pppoe])
                ->find_one();
            if ($cust) {
                $fullname    = (string)$cust['fullname'];
                $customer_id = (int)$cust['id'];
                $cust_phone  = (string)($cust['phonenumber'] ?? '');
            }
        }
        if ($fullname === '') {
            $ip_candidate = ($tr069ip !== 'N/A') ? $tr069ip : (($wan_ip !== 'N/A') ? $wan_ip : '');
            if ($ip_candidate !== '') {
                $cust = ORM::for_table('tbl_customers')->where('pppoe_ip', $ip_candidate)->find_one();
                if ($cust) {
                    $fullname    = (string)$cust['fullname'];
                    $customer_id = (int)$cust['id'];
                    $cust_phone  = (string)($cust['phonenumber'] ?? '');
                    if ($pppoe === 'N/A') $pppoe = (string)$cust['username'];
                }
            }
        }

        $connectedDevices = 0;
        $wlanCfgs = genieacs_extract_wlan_configs($d);
        foreach ($wlanCfgs as $wc) {
            $connectedDevices += (int)($wc['connected_devices'] ?? 0);
        }

        $lastInform   = (string)($d['_lastInform'] ?? '');
        $lastInformTs = $lastInform !== '' ? (int)strtotime($lastInform) : 0;
        $isOnline     = ($lastInformTs > 0 && (time() - $lastInformTs) < 300);

        $uptimeRaw = $d['InternetGatewayDevice']['DeviceInfo']['UpTime']['_value']
            ?? $d['Device']['DeviceInfo']['UpTime']['_value']
            ?? null;

        $productClass = (string)($d['_deviceId']['_ProductClass'] ?? '-');

        return [
            'id'                 => (string)($d['_id'] ?? ''),
            '_id'                => (string)($d['_id'] ?? ''),
            'serial_number'      => (string)($d['_deviceId']['_SerialNumber'] ?? ($d['_id'] ?? '-')),
            'manufacturer'       => (string)($d['_deviceId']['_Manufacturer'] ?? '-'),
            'product_class'      => $productClass,
            'model'              => $productClass,
            'oui'                => (string)($d['_deviceId']['_OUI'] ?? '-'),
            'software_version'   => (string)($d['InternetGatewayDevice']['DeviceInfo']['SoftwareVersion']['_value']
                ?? ($d['Device']['DeviceInfo']['SoftwareVersion']['_value'] ?? '-')),
            'hardware_version'   => (string)($d['InternetGatewayDevice']['DeviceInfo']['HardwareVersion']['_value']
                ?? ($d['Device']['DeviceInfo']['HardwareVersion']['_value'] ?? '-')),
            'uptime_seconds'     => (int)($uptimeRaw ?: 0),
            'uptime'             => genieacs_format_uptime($uptimeRaw),
            'pppoe_username'     => $pppoe,
            'wan_ip'             => $wan_ip,
            'tr069_ip'           => $tr069ip,
            'connection_url'     => (string)($connUrl ?? '-'),
            'temperature'        => $temp,
            'temperature_raw'    => $tempRaw,
            'temperature_status' => $tempStatus,
            'rx_power'           => $rxPower,
            'rx_power_raw'       => $rxPowerRaw,
            'rx_power_status'    => $rxStatus,
            'tx_power'           => $txPower,
            'fullname'           => $fullname,
            'customer_id'        => $customer_id,
            'phonenumber'        => $cust_phone,
            'connected_devices'  => $connectedDevices,
            'online'             => $isOnline,
            'last_inform'        => $lastInform !== '' ? date('Y-m-d H:i:s', $lastInformTs) : '-',
            'last_inform_ts'     => $lastInformTs,
            'tags'               => isset($d['_tags']) && is_array($d['_tags']) ? array_values($d['_tags']) : []
        ];
    }
}

if (!function_exists('genieacs_resolve_customer_device')) {
    function genieacs_resolve_customer_device($client, $customer, $explicitDeviceId = '')
    {
        if (!$client || !$customer) return null;

        if ($explicitDeviceId !== '') {
            $res = $client->getDevice($explicitDeviceId);
            if (!empty($res['success']) && !empty($res['data'])) {
                return $res['data'];
            }
        }

        $candidates = array_values(array_unique(array_filter([
            trim((string)($customer['pppoe_username'] ?? '')),
            trim((string)($customer['username'] ?? ''))
        ])));

        if (!empty($candidates)) {
            $orClauses = [];
            foreach ($candidates as $u) {
                $regex = ['$regex' => '^' . preg_quote($u, '/') . '$', '$options' => 'i'];
                for ($idx = 1; $idx <= 4; $idx++) {
                    $orClauses[] = ["InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$idx}.WANPPPConnection.1.Username._value" => $regex];
                }
                $orClauses[] = ['_deviceId._SerialNumber' => $regex];
                $orClauses[] = ['_id' => $regex];
                $orClauses[] = ['_tags' => $u];
            }
            $res = $client->getDevices(['$or' => $orClauses], 1, 0);
            if (!empty($res['success']) && !empty($res['data'][0])) {
                return $res['data'][0];
            }
        }

        // Fallback pencocokan IP untuk pelanggan HTB / Static IP
        $custIp = trim((string)($customer['pppoe_ip'] ?? ''));
        if ($custIp !== '') {
            $ipRegex = ['$regex' => preg_quote($custIp, '/'), '$options' => 'i'];
            $ipQuery = ['$or' => [
                ['InternetGatewayDevice.ManagementServer.ConnectionRequestURL._value' => $ipRegex],
                ['Device.ManagementServer.ConnectionRequestURL._value' => $ipRegex],
                ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress._value' => $custIp],
                ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress._value' => $custIp]
            ]];
            $res = $client->getDevices($ipQuery, 1, 0);
            if (!empty($res['success']) && !empty($res['data'][0])) {
                return $res['data'][0];
            }
        }

        return null;
    }
}

if (!function_exists('genieacs_build_wlan_set_params')) {
    function genieacs_build_wlan_set_params($wlanIndex, $ssid, $password, $security, $source = 'tr098', $channel = '', $enable = '')
    {
        $params = [];
        $idx = max(1, min(8, (int)$wlanIndex));

        if (strtolower((string)$source) === 'tr181') {
            if ($ssid !== '') {
                $params[] = ["Device.WiFi.SSID.{$idx}.SSID", $ssid, 'xsd:string'];
            }
            if ($enable === '1' || $enable === '0' || is_bool($enable)) {
                $enBool = ($enable === true || $enable === '1' || $enable === 1);
                $params[] = ["Device.WiFi.SSID.{$idx}.Enable", $enBool, 'xsd:boolean'];
            }
            if ($channel !== '' && is_numeric($channel) && (int)$channel > 0) {
                $params[] = ["Device.WiFi.Radio.{$idx}.Channel", (int)$channel, 'xsd:unsignedInt'];
            }
            $modeMap = [
                'WPA2PSK'       => 'WPA2-Personal',
                'WPAPSK'        => 'WPA-Personal',
                'WPA2PSKWPAPSK' => 'WPA-WPA2-Personal',
                'None'          => 'None'
            ];
            if (isset($modeMap[$security])) {
                $params[] = ["Device.WiFi.AccessPoint.{$idx}.Security.ModeEnabled", $modeMap[$security], 'xsd:string'];
            }
            if ($security !== 'None' && $password !== '') {
                $params[] = ["Device.WiFi.AccessPoint.{$idx}.Security.KeyPassphrase", $password, 'xsd:string'];
            }
        } else {
            $baseWlan = "InternetGatewayDevice.LANDevice.1.WLANConfiguration.{$idx}";
            if ($ssid !== '') {
                $params[] = ["{$baseWlan}.SSID", $ssid, 'xsd:string'];
            }
            if ($enable === '1' || $enable === '0' || is_bool($enable)) {
                $enBool = ($enable === true || $enable === '1' || $enable === 1);
                $params[] = ["{$baseWlan}.Enable", $enBool, 'xsd:boolean'];
            }
            if ($channel !== '' && is_numeric($channel) && (int)$channel > 0) {
                $params[] = ["{$baseWlan}.Channel", (int)$channel, 'xsd:unsignedInt'];
            }
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
                $params[] = ["{$baseWlan}.PreSharedKey.1.KeyPassphrase", $password, 'xsd:string'];
                $params[] = ["{$baseWlan}.WPAAuthenticationMode", 'PSKAuthentication', 'xsd:string'];
                $encMode  = ($security === 'WPA2PSK' || $security === 'WPA2PSKWPAPSK') ? 'AESEncryption' : 'TKIPEncryption';
                $params[] = ["{$baseWlan}.WPAEncryptionModes", $encMode, 'xsd:string'];
                $params[] = ["{$baseWlan}.IEEE11iEncryptionModes", $encMode, 'xsd:string'];
            }
        }
        return $params;
    }
}

switch ($action) {
    // =========================================================================
    // 1. MANAJEMEN MULTI-HOST GENIEACS (SUPPORT HTTP & HTTPS) - ADMIN
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
        $hostId   = trim((string)_post('id'));
        $name     = trim((string)_post('name'));
        $hostAddr = trim((string)_post('host'));
        $protocol = strtolower(trim((string)(_post('protocol') ?: 'auto')));
        $rawPort  = trim((string)_post('port'));
        $username = trim((string)_post('username'));
        $password = (string)(_post('password') ?? '');

        if ($name === '' || $hostAddr === '') {
            api_out(false, 'Nama Server dan Host/Domain/IP GenieACS wajib diisi.', null, 400);
        }

        if (preg_match('#^(https?)://#i', $hostAddr, $m)) {
            $protocol = strtolower($m[1]);
            $hostAddr = preg_replace('#^https?://#i', '', $hostAddr);
        } elseif ($protocol !== 'http' && $protocol !== 'https') {
            $protocol = ((int)$rawPort === 443) ? 'https' : 'http';
        }

        $port = ($rawPort === '') ? ($protocol === 'https' ? 443 : 7557) : (int)$rawPort;

        $hosts = genieacs_get_hosts();
        if ($hostId !== '') {
            $found = false;
            foreach ($hosts as &$h) {
                if (($h['id'] ?? '') === $hostId) {
                    $h['name']     = $name;
                    $h['host']     = $hostAddr;
                    $h['port']     = $port;
                    $h['protocol'] = $protocol;
                    $h['username'] = $username;
                    if ($password !== '' || !isset($h['password'])) {
                        $h['password'] = $password;
                    }
                    $found = true;
                    break;
                }
            }
            unset($h);
            if (!$found) api_out(false, 'Host GenieACS tidak ditemukan.', null, 404);
        } else {
            $hosts[] = [
                'id'       => uniqid(),
                'name'     => $name,
                'host'     => $hostAddr,
                'port'     => $port,
                'protocol' => $protocol,
                'username' => $username,
                'password' => $password,
                'active'   => (count($hosts) === 0)
            ];
        }

        genieacs_save_hosts($hosts);
        $freshHosts = genieacs_get_hosts();
        api_out(true, 'Konfigurasi server GenieACS ' . $name . ' berhasil disimpan.', [
            'hosts'       => $freshHosts,
            'active_host' => genieacs_resolve_host()
        ]);
        break;

    case 'admin_genieacs_host_action':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $sub    = strtolower(trim((string)(_req('sub_action') ?: 'test'))); // test | setactive | delete
        $hostId = trim((string)_req('id'));
        $hosts  = genieacs_get_hosts();

        if ($sub === 'test') {
            $target = genieacs_resolve_host($hostId);
            if (!$target) api_out(false, 'Host GenieACS tidak ditemukan.', null, 404);
            $client = new GenieACSClient(
                $target['host'] ?? '',
                (int)($target['port'] ?? 7557),
                $target['username'] ?? '',
                $target['password'] ?? '',
                $target['protocol'] ?? 'auto'
            );
            $testRes = $client->testConnection();
            if (!empty($testRes['success'])) {
                api_out(
                    true,
                    'Koneksi ke ' . $target['name'] . ' (' . $client->getBaseUrl() . ') BERHASIL! Ping: ' . ($testRes['ping_ms'] ?? 0) . ' ms.',
                    ['url' => $client->getBaseUrl(), 'ping_ms' => $testRes['ping_ms'] ?? 0]
                );
            }
            api_out(
                false,
                'Gagal terhubung ke ' . $target['name'] . ' (' . $client->getBaseUrl() . '). Detail: ' . ($testRes['error'] ?? 'Unknown error'),
                ['url' => $client->getBaseUrl()],
                500
            );
        }

        if ($sub === 'setactive') {
            foreach ($hosts as &$h) {
                $h['active'] = (($h['id'] ?? '') === $hostId);
            }
            unset($h);
            genieacs_save_hosts($hosts);
            api_out(true, 'Host GenieACS aktif berhasil diubah.', [
                'hosts'       => genieacs_get_hosts(),
                'active_host' => genieacs_resolve_host()
            ]);
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
            api_out(true, 'Host GenieACS berhasil dihapus.', [
                'hosts'       => genieacs_get_hosts(),
                'active_host' => genieacs_resolve_host()
            ]);
        }
        api_out(false, 'Aksi host tidak dikenali.', null, 400);
        break;

    // =========================================================================
    // 2. DASHBOARD & DAFTAR PERANGKAT TR-069 - ADMIN
    // =========================================================================
    case 'admin_genieacs_dashboard':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId     = trim((string)_req('host_id'));
        $hosts      = genieacs_get_hosts();
        $activeHost = genieacs_resolve_host($hostId);
        $client     = genieacs_load_config($hostId);

        $stats = [
            'total'          => 0,
            'online'         => 0,
            'offline'        => 0,
            'bad_rx_count'   => 0,
            'high_temp_count'=> 0
        ];
        $recentDevices  = [];
        $offlineDevices = [];
        $connectionError = '';

        if ($client) {
            $result = $client->getDevices([], 500, 0, genieacs_default_projections());
            if (!empty($result['success']) && is_array($result['data'])) {
                $rawList = $result['data'];
                $stats['total'] = count($rawList);
                foreach ($rawList as $idx => $d) {
                    $info = genieacs_extract_device_info($d);
                    if ($info['online']) {
                        $stats['online']++;
                    } else {
                        $stats['offline']++;
                        if (count($offlineDevices) < 20) {
                            $offlineDevices[] = $info;
                        }
                    }
                    if (in_array($info['rx_power_status'], ['critical', 'los'])) {
                        $stats['bad_rx_count']++;
                    }
                    if ($info['temperature_status'] === 'critical') {
                        $stats['high_temp_count']++;
                    }
                    if ($idx < 10) {
                        $recentDevices[] = $info;
                    }
                }
            } else {
                $connectionError = $result['error'] ?? 'Gagal mengambil daftar perangkat dari GenieACS.';
            }
        }

        api_out(true, $connectionError !== '' ? ('Peringatan GenieACS: ' . $connectionError) : 'Dashboard GenieACS dimuat', [
            'hosts'            => $hosts,
            'active_host'      => $activeHost,
            'connection_error' => $connectionError,
            'stats'            => $stats,
            'recent_devices'   => $recentDevices,
            'offline_devices'  => $offlineDevices
        ]);
        break;

    case 'admin_genieacs_devices':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId       = trim((string)_req('host_id'));
        $search       = trim((string)_req('search'));
        $statusFilter = strtolower(trim((string)(_req('status_filter') ?: 'all'))); // all | online | offline | bad_rx
        $sortBy       = strtolower(trim((string)(_req('sort_by') ?: 'last_inform'))); // last_inform | rx_power | temp | connected | serial
        $sortDir      = strtolower(trim((string)(_req('sort_dir') ?: 'desc'))); // asc | desc
        $page         = max(1, (int)(_req('page') ?: 1));
        $limit        = max(10, min(200, (int)(_req('limit') ?: 50)));
        $skip         = ($page - 1) * $limit;

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
                ['_tags' => ['$regex' => $search, '$options' => 'i']],
                ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username._value' => ['$regex' => $search, '$options' => 'i']],
                ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username._value' => ['$regex' => $search, '$options' => 'i']],
                ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.1.Username._value' => ['$regex' => $search, '$options' => 'i']]
            ]];
            $customers = ORM::for_table('tbl_customers')
                ->where_raw('(fullname LIKE ? OR username LIKE ? OR pppoe_username LIKE ?)', ["%{$search}%", "%{$search}%", "%{$search}%"])
                ->find_many();
            foreach ($customers as $c) {
                $u = $c['pppoe_username'] ?: $c['username'];
                if (!empty($u)) {
                    $exact = ['$regex' => '^' . preg_quote($u, '/') . '$', '$options' => 'i'];
                    $query['$or'][] = ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username._value' => $exact];
                    $query['$or'][] = ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection.1.Username._value' => $exact];
                }
            }
        }

        $result = $client->getDevices($query, 500, 0, genieacs_default_projections());
        if (empty($result['success'])) {
            api_out(false, 'Gagal menghubungi server GenieACS: ' . ($result['error'] ?? 'Unknown error'), [
                'hosts'       => $hosts,
                'active_host' => $activeHost
            ], 500);
        }

        $allExtracted = [];
        if (is_array($result['data'])) {
            foreach ($result['data'] as $d) {
                $info = genieacs_extract_device_info($d);
                if ($statusFilter === 'online' && !$info['online']) continue;
                if ($statusFilter === 'offline' && $info['online']) continue;
                if ($statusFilter === 'bad_rx' && !in_array($info['rx_power_status'], ['critical', 'los', 'warning'])) continue;
                $allExtracted[] = $info;
            }
        }

        usort($allExtracted, function ($a, $b) use ($sortBy, $sortDir) {
            $mult = ($sortDir === 'asc') ? 1 : -1;
            if ($sortBy === 'rx_power') {
                $va = $a['rx_power_raw'] ?? -999;
                $vb = $b['rx_power_raw'] ?? -999;
                return ($va <=> $vb) * $mult;
            }
            if ($sortBy === 'temp') {
                $va = $a['temperature_raw'] ?? -999;
                $vb = $b['temperature_raw'] ?? -999;
                return ($va <=> $vb) * $mult;
            }
            if ($sortBy === 'connected') {
                return (($a['connected_devices'] ?? 0) <=> ($b['connected_devices'] ?? 0)) * $mult;
            }
            if ($sortBy === 'serial') {
                return strcmp($a['serial_number'] ?? '', $b['serial_number'] ?? '') * $mult;
            }
            return (($a['last_inform_ts'] ?? 0) <=> ($b['last_inform_ts'] ?? 0)) * $mult;
        });

        $total   = count($allExtracted);
        $paged   = array_slice($allExtracted, $skip, $limit);

        api_out(true, 'Daftar perangkat GenieACS dimuat', [
            'hosts'       => $hosts,
            'active_host' => $activeHost,
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'devices'     => $paged
        ]);
        break;

    // =========================================================================
    // 3. DETAIL PERANGKAT LENGKAP (INFO, OPTIK, WAN, WLAN, HOSTS, RAW JSON) - ADMIN
    // =========================================================================
    case 'admin_genieacs_device_detail':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId     = trim((string)_req('host_id'));
        $deviceId   = trim((string)(_req('id') ?: _req('device_id')));
        $includeRaw = (_req('include_raw') == '1' || _req('include_raw') === 'true');

        if ($deviceId === '') api_out(false, 'Device ID wajib diisi.', null, 400);

        $client = genieacs_load_config($hostId);
        if (!$client) api_out(false, 'Server GenieACS belum dikonfigurasi.', null, 400);

        $res = $client->getDevice($deviceId);
        if (empty($res['success']) || empty($res['data'])) {
            api_out(false, $res['error'] ?? 'Perangkat tidak ditemukan di GenieACS.', null, 404);
        }

        $dev        = $res['data'];
        $info       = genieacs_extract_device_info($dev);
        $availIndex = null;
        $wans       = genieacs_extract_wan_connections($dev, $availIndex);
        $wlan       = genieacs_extract_wlan_configs($dev);
        $lanHosts   = genieacs_extract_connected_hosts($dev);

        $tasksRes   = $client->getDeviceTasks($deviceId);
        $pendingTasks = (!empty($tasksRes['success']) && is_array($tasksRes['data'])) ? $tasksRes['data'] : [];

        // Ringkasan JSON perangkat (agar Raw JSON tetap ringan namun informatif sesuai genieacs_device.tpl)
        $rawSummary = [
            '_id'          => $dev['_id'] ?? '',
            '_deviceId'    => $dev['_deviceId'] ?? [],
            '_lastInform'  => $dev['_lastInform'] ?? '',
            '_tags'        => $dev['_tags'] ?? [],
            'VirtualParameters' => $dev['VirtualParameters'] ?? [],
            'DeviceInfo'   => $dev['InternetGatewayDevice']['DeviceInfo'] ?? ($dev['Device']['DeviceInfo'] ?? [])
        ];

        api_out(true, 'Detail perangkat GenieACS dimuat', [
            'device'          => $info,
            'wan_connections' => $wans,
            'avail_wan_index' => $availIndex,
            'wlan_configs'    => $wlan,
            'connected_hosts' => $lanHosts,
            'pending_tasks'   => $pendingTasks,
            'raw_json'        => json_encode($includeRaw ? $dev : $rawSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'active_host'     => genieacs_resolve_host($hostId)
        ]);
        break;

    // =========================================================================
    // 4. AKSI PERANGKAT (SUMMON, REFRESH, REBOOT, FACTORY RESET, TAGS, DELETE)
    // =========================================================================
    case 'admin_genieacs_device_action':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId   = trim((string)_req('host_id'));
        $deviceId = trim((string)(_req('id') ?: _req('device_id')));
        $sub      = strtolower(trim((string)(_req('sub_action') ?: 'summon')));
        // summon | refresh | reboot | factory_reset | add_tag | remove_tag | clear_tasks | delete_device

        if ($deviceId === '') api_out(false, 'Device ID wajib diisi.', null, 400);
        $client = genieacs_load_config($hostId);
        if (!$client) api_out(false, 'Server GenieACS belum dikonfigurasi.', null, 400);

        if ($sub === 'reboot') {
            $res = $client->rebootDevice($deviceId);
            if (!empty($res['success'])) {
                api_out(true, 'Perintah Reboot berhasil dikirim ke perangkat ' . $deviceId . '.');
            }
            api_out(false, 'Gagal mengirim perintah Reboot: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        }

        if ($sub === 'summon' || $sub === 'refresh') {
            $res = $client->summonDevice($deviceId, ($sub === 'refresh'));
            if (!empty($res['success'])) {
                api_out(true, 'Perintah Summon / Refresh berhasil dikirim ke perangkat ' . $deviceId . '.');
            }
            api_out(false, 'Gagal melakukan Summon: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        }

        if ($sub === 'factory_reset') {
            $res = $client->factoryResetDevice($deviceId);
            if (!empty($res['success'])) {
                api_out(true, 'Perintah Factory Reset berhasil dikirim ke perangkat.');
            }
            api_out(false, 'Gagal mengirim Factory Reset: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        }

        if ($sub === 'add_tag') {
            $tag = trim((string)_req('tag'));
            if ($tag === '') api_out(false, 'Nama Tag tidak boleh kosong.', null, 400);
            $res = $client->addTag($deviceId, $tag);
            if (!empty($res['success'])) {
                api_out(true, "Tag '{$tag}' berhasil ditambahkan.");
            }
            api_out(false, 'Gagal menambah Tag: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        }

        if ($sub === 'remove_tag') {
            $tag = trim((string)_req('tag'));
            if ($tag === '') api_out(false, 'Nama Tag tidak boleh kosong.', null, 400);
            $res = $client->removeTag($deviceId, $tag);
            if (!empty($res['success'])) {
                api_out(true, "Tag '{$tag}' berhasil dihapus.");
            }
            api_out(false, 'Gagal menghapus Tag: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        }

        if ($sub === 'clear_tasks') {
            $tRes = $client->getDeviceTasks($deviceId);
            $deleted = 0;
            if (!empty($tRes['success']) && is_array($tRes['data'])) {
                foreach ($tRes['data'] as $tk) {
                    if (!empty($tk['_id'])) {
                        $client->deleteTask($tk['_id']);
                        $deleted++;
                    }
                }
            }
            api_out(true, "{$deleted} antrean task pada perangkat berhasil dibersihkan.");
        }

        if ($sub === 'delete_device') {
            $res = $client->deleteDevice($deviceId);
            if (!empty($res['success'])) {
                api_out(true, 'Perangkat berhasil dihapus dari database GenieACS.');
            }
            api_out(false, 'Gagal menghapus perangkat: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        }

        api_out(false, 'Aksi perangkat tidak dikenali.', null, 400);
        break;

    // =========================================================================
    // 5. KONFIGURASI WAN (EDIT WAN & ADD WAN BARU INDEX 1-8) - ADMIN
    // =========================================================================
    case 'admin_genieacs_wan_save':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId     = trim((string)_req('host_id'));
        $deviceId   = trim((string)(_post('id') ?: _post('device_id')));
        $connIndex  = max(1, min(8, (int)(_post('connection_index') ?: 1)));
        $connType   = (_post('connection_type') === 'ip') ? 'ip' : 'ppp';
        $username   = trim((string)_post('username'));
        $password   = trim((string)_post('password'));
        $vlan       = trim((string)_post('vlan'));
        $nat        = trim((string)_post('nat'));
        $enable     = trim((string)_post('enable'));
        $customerId = (int)_post('customer_id');

        if ($deviceId === '') api_out(false, 'Device ID wajib diisi.', null, 400);
        $client = genieacs_load_config($hostId);
        if (!$client) api_out(false, 'Server GenieACS belum dikonfigurasi.', null, 400);

        // Jika Admin memilih pelanggan PHPNuxBill untuk di-push akun PPPoE-nya
        if ($customerId > 0 && $username === '') {
            $cust = ORM::for_table('tbl_customers')->find_one($customerId);
            if ($cust) {
                $username = trim((string)($cust['pppoe_username'] ?: $cust['username']));
                if ($password === '') {
                    $password = trim((string)($cust['pppoe_password'] ?: $cust['password']));
                }
            }
        }

        $basePath = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$connIndex}."
            . ($connType === 'ppp' ? 'WANPPPConnection.1' : 'WANIPConnection.1');

        $params = [];
        if ($enable === '1' || $enable === '0') {
            $params[] = [$basePath . '.Enable', ($enable === '1'), 'xsd:boolean'];
        }
        if ($connType === 'ppp') {
            if ($username !== '') {
                $params[] = [$basePath . '.Username', $username, 'xsd:string'];
            }
            if ($password !== '') {
                $params[] = [$basePath . '.Password', $password, 'xsd:string'];
            }
        }
        if ($vlan !== '' && is_numeric($vlan)) {
            $vlanInt = (int)$vlan;
            $params[] = ["InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$connIndex}.X_CT-COM_VLANID", $vlanInt, 'xsd:unsignedInt'];
            $params[] = ["InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$connIndex}.X_HW_VLAN", $vlanInt, 'xsd:unsignedInt'];
            $params[] = [$basePath . '.X_HW_VLAN', $vlanInt, 'xsd:unsignedInt'];
        }
        if ($nat === '1' || $nat === '0') {
            $params[] = [$basePath . '.NATEnabled', ($nat === '1'), 'xsd:boolean'];
        }

        if (empty($params)) {
            api_out(false, 'Tidak ada parameter WAN yang diubah.', null, 400);
        }

        $res = $client->setParameterValues($deviceId, $params, 5000);
        if (!empty($res['success'])) {
            api_out(true, "Konfigurasi WAN #{$connIndex} (" . strtoupper($connType) . ") berhasil dikirim ke perangkat.");
        }
        api_out(false, 'Gagal memperbarui WAN: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        break;

    // =========================================================================
    // 6. KONFIGURASI WLAN (EDIT SSID, PASSWORD, SECURITY, CHANNEL, ENABLE) - ADMIN
    // =========================================================================
    case 'admin_genieacs_wlan_save':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId    = trim((string)_req('host_id'));
        $deviceId  = trim((string)(_post('id') ?: _post('device_id')));
        $wlanIndex = max(1, min(8, (int)(_post('wlan_index') ?: 1)));
        $ssid      = trim((string)_post('ssid'));
        $password  = trim((string)_post('password'));
        $security  = trim((string)(_post('security_mode') ?: 'WPA2PSK'));
        $channel   = trim((string)_post('channel'));
        $enable    = trim((string)_post('enable'));
        $source    = strtolower(trim((string)(_post('source') ?: 'tr098')));

        if ($deviceId === '' || $ssid === '') {
            api_out(false, 'Device ID dan Nama WiFi (SSID) wajib diisi.', null, 400);
        }
        if ($security !== 'None' && $password !== '' && strlen($password) < 8) {
            api_out(false, 'Password WiFi minimal 8 karakter.', null, 400);
        }

        $client = genieacs_load_config($hostId);
        if (!$client) api_out(false, 'Server GenieACS belum dikonfigurasi.', null, 400);

        $params = genieacs_build_wlan_set_params($wlanIndex, $ssid, $password, $security, $source, $channel, $enable);
        $res    = $client->setParameterValues($deviceId, $params, 10000);

        if (!empty($res['success'])) {
            api_out(true, "Konfigurasi WiFi #{$wlanIndex} (SSID: {$ssid}) berhasil diperbarui!");
        }
        api_out(false, 'Gagal mengubah WiFi: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        break;

    // =========================================================================
    // 7. ENDPOINT KHUSUS APK PELANGGAN (MY DEVICE / PORTAL MANDIRI PELANGGAN)
    //    Kompatibel penuh dengan genieacs_customer.tpl & genieacs_customer_wlan.tpl
    // =========================================================================
    case 'customer_genieacs_dashboard':
    case 'genieacs_customer_dashboard':
    case 'my_device':
    case 'customer_device':
        $customer = require_customer_session($api_secret);
        $client   = genieacs_load_config();
        if (!$client) {
            api_out(false, 'Server manajemen modem belum dikonfigurasi oleh Admin.', null, 400);
        }

        $device = genieacs_resolve_customer_device($client, $customer, trim((string)(_req('id') ?: _req('device_id'))));
        if (!$device) {
            api_out(false, 'Perangkat modem/router belum terdeteksi untuk akun Anda.', null, 404);
        }

        $deviceInfo     = genieacs_extract_device_info($device);
        $wlanConfigs    = genieacs_extract_wlan_configs($device);
        $connectedHosts = genieacs_extract_connected_hosts($device);
        $wanConnections = genieacs_extract_wan_connections($device);

        api_out(true, 'Status perangkat berhasil dimuat', [
            'online'          => (bool)$deviceInfo['online'],
            'device'          => $deviceInfo,
            'device_info'     => $deviceInfo,
            'wlan_configs'    => $wlanConfigs,
            'connected_hosts' => $connectedHosts,
            'wan_connections' => $wanConnections,
            'customer'        => [
                'id'             => (int)$customer['id'],
                'username'       => $customer['username'],
                'fullname'       => $customer['fullname'],
                'pppoe_username' => $customer['pppoe_username'] ?? '',
                'pppoe_ip'       => $customer['pppoe_ip'] ?? ''
            ]
        ]);
        break;

    case 'customer_genieacs_wlan_save':
    case 'genieacs_customer_wlan_edit':
    case 'customer_wifi_save':
        $customer  = require_customer_session($api_secret);
        $wlanIndex = max(1, min(8, (int)(_post('wlan_index') ?: 1)));
        $ssid      = trim((string)_post('ssid'));
        $password  = trim((string)_post('password'));
        $security  = trim((string)(_post('security_mode') ?: 'WPA2PSK'));
        $channel   = trim((string)_post('channel'));
        $enable    = trim((string)_post('enable'));
        $source    = strtolower(trim((string)(_post('source') ?: 'tr098')));

        if ($ssid === '') {
            api_out(false, 'Nama WiFi (SSID) tidak boleh kosong.', null, 400);
        }
        if ($security !== 'None' && $password !== '' && strlen($password) < 8) {
            api_out(false, 'Password WiFi minimal 8 karakter.', null, 400);
        }

        $client = genieacs_load_config();
        if (!$client) api_out(false, 'Server GenieACS tidak tersedia.', null, 400);

        $reqDeviceId = trim((string)(_post('id') ?: _post('device_id')));
        $device      = genieacs_resolve_customer_device($client, $customer, $reqDeviceId);
        if (!$device || empty($device['_id'])) {
            api_out(false, 'Perangkat modem Anda tidak ditemukan.', null, 404);
        }
        $deviceId = $device['_id'];

        // Deteksi otomatis apakah perangkat pelanggan menggunakan TR-181 atau TR-098
        $existingWlans = genieacs_extract_wlan_configs($device);
        foreach ($existingWlans as $ew) {
            if ((int)$ew['index'] === $wlanIndex && !empty($ew['source'])) {
                $source = $ew['source'];
                break;
            }
        }

        $params = genieacs_build_wlan_set_params($wlanIndex, $ssid, $password, $security, $source, $channel, $enable);
        $res    = $client->setParameterValues($deviceId, $params, 10000);

        if (!empty($res['success'])) {
            api_out(true, 'Nama WiFi & Password berhasil diperbarui!', [
                'device_id'  => $deviceId,
                'wlan_index' => $wlanIndex,
                'ssid'       => $ssid
            ]);
        }
        api_out(false, 'Gagal memperbarui WiFi: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        break;

    case 'customer_genieacs_reboot':
    case 'genieacs_customer_reboot':
    case 'customer_device_reboot':
        $customer = require_customer_session($api_secret);
        $client   = genieacs_load_config();
        if (!$client) api_out(false, 'Server GenieACS tidak tersedia.', null, 400);

        $reqDeviceId = trim((string)(_req('id') ?: _req('device_id')));
        $device      = genieacs_resolve_customer_device($client, $customer, $reqDeviceId);
        if (!$device || empty($device['_id'])) {
            api_out(false, 'Perangkat modem Anda tidak ditemukan.', null, 404);
        }

        $res = $client->rebootDevice($device['_id']);
        if (!empty($res['success'])) {
            api_out(true, 'Perintah restart modem berhasil dikirim. Modem akan menyala kembali dalam 1-2 menit.');
        }
        api_out(false, 'Gagal me-restart modem: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        break;

    case 'customer_genieacs_summon':
    case 'customer_device_refresh':
        $customer = require_customer_session($api_secret);
        $client   = genieacs_load_config();
        if (!$client) api_out(false, 'Server GenieACS tidak tersedia.', null, 400);

        $reqDeviceId = trim((string)(_req('id') ?: _req('device_id')));
        $device      = genieacs_resolve_customer_device($client, $customer, $reqDeviceId);
        if (!$device || empty($device['_id'])) {
            api_out(false, 'Perangkat modem Anda tidak ditemukan.', null, 404);
        }

        $res = $client->summonDevice($device['_id'], true);
        if (!empty($res['success'])) {
            api_out(true, 'Sinkronisasi data modem berhasil dikirim.');
        }
        api_out(false, 'Gagal menyegarkan data modem: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        break;
}
