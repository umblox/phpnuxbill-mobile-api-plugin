<?php

/**
 * PHPNuxBill Mobile API Plugin - GenieACS TR-069 Pro Module (Admin & Customer)
 * Path: system/plugin/mobile_api_modules/plugin_genieacs.php
 * Supports HTTP/HTTPS, Multi-Host, Optical ONT & Standard Wireless Routers (HTB/Ethernet),
 * Auto Bot/Scanner Junk Cleanup, WAN/WLAN TR-098 & TR-181, Filtered Connected Hosts,
 * Tags, and Hardened Customer Portal (Anti-IDOR & Sanitized Payload).
 **/

global $config, $_c, $admin, $user, $ui, $_app_stage, $api_secret;

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
            $rawHost        = trim((string) $host);
            $this->port     = (int) $port;
            $this->username = trim((string) $username);
            $this->password = (string) $password;
            $this->protocol = strtolower(trim((string) $protocol));

            if (preg_match('#^(https?)://#i', $rawHost, $m)) {
                if ($this->protocol === '' || $this->protocol === 'auto') {
                    $this->protocol = strtolower($m[1]);
                }
                $rawHost = preg_replace('#^https?://#i', '', $rawHost);
            }

            $rawHost = rtrim($rawHost, '/');
            if (strpos($rawHost, ':') !== false && strpos($rawHost, '[') === false) {
                $parts   = explode(':', $rawHost, 2);
                $rawHost = $parts[0];
                if ($this->port <= 0 && is_numeric($parts[1])) {
                    $this->port = (int) $parts[1];
                }
            }

            if ($this->protocol !== 'http' && $this->protocol !== 'https') {
                $this->protocol = ($this->port === 443) ? 'https' : 'http';
            }
            if ($this->port <= 0) {
                $this->port = ($this->protocol === 'https') ? 443 : 7557;
            }

            $this->host = $rawHost;
            if ($this->host === '') {
                $this->baseUrl = '';
            } else {
                $omitPort      = ($this->protocol === 'https' && $this->port === 443) || ($this->protocol === 'http' && $this->port === 80);
                $this->baseUrl = $this->protocol . '://' . $this->host . ($omitPort ? '' : ':' . $this->port);
            }
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
            $t0  = microtime(true);
            $ch  = curl_init($url);

            $headers = ['Accept: application/json'];
            if ($data !== null) {
                $headers[] = 'Content-Type: application/json';
            }

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => strtoupper($method),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => $headers
            ]);

            if ($this->username !== '' || $this->password !== '') {
                curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            }

            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($data) ? $data : json_encode($data));
            }

            $response = curl_exec($ch);
            $curlErr  = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $pingMs = (int) round((microtime(true) - $t0) * 1000);
            if ($response === false) {
                return [
                    'success'   => false,
                    'error'     => $curlErr !== '' ? $curlErr : 'Tidak dapat terhubung ke server GenieACS.',
                    'http_code' => 0,
                    'ping_ms'   => $pingMs
                ];
            }

            $decoded = json_decode($response, true);
            $isOk    = ($httpCode >= 200 && $httpCode < 300);

            return [
                'success'   => $isOk,
                'data'      => $decoded,
                'raw'       => $isOk ? null : substr(trim(strip_tags((string) $response)), 0, 200),
                'error'     => $isOk ? null : ('HTTP ' . $httpCode . (!empty($response) ? ': ' . substr(trim(strip_tags((string) $response)), 0, 120) : '')),
                'http_code' => $httpCode,
                'ping_ms'   => $pingMs
            ];
        }

        public function testConnection()
        {
            return $this->request('/devices/?limit=1&projection=_id', 'GET', null, 10);
        }

        public function getDevices($query = [], $limit = 100, $skip = 0, $projection = [])
        {
            $params = [];
            if (!empty($query)) {
                $params[] = 'query=' . rawurlencode(json_encode($query));
            }
            if ($limit > 0) {
                $params[] = 'limit=' . (int) $limit;
            }
            if ($skip > 0) {
                $params[] = 'skip=' . (int) $skip;
            }
            if (!empty($projection)) {
                $params[] = 'projection=' . rawurlencode(implode(',', $projection));
            }
            $queryString = !empty($params) ? '?' . implode('&', $params) : '';
            return $this->request('/devices/' . $queryString);
        }

        public function getDevice($deviceId)
        {
            $query       = ['_id' => $deviceId];
            $queryString = '?query=' . rawurlencode(json_encode($query));
            $result      = $this->request('/devices/' . $queryString);
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

        /**
         * Summon & Refresh Parameter Perangkat:
         * Mengirim getParameterValues eksplisit untuk UpTime, WAN, dan WLAN (efektif untuk router non-ONT),
         * diikuti refreshObject pada cabang DeviceInfo, WANDevice, dan LANDevice.
         */
        public function summonDevice($deviceId, $fullRefresh = false)
        {
            $encodedId = rawurlencode($deviceId);
            if ($fullRefresh) {
                return $this->request(
                    "/devices/{$encodedId}/tasks?timeout=5000&connection_request",
                    'POST',
                    ['name' => 'refreshObject', 'objectName' => '']
                );
            }

            // 1. Minta nilai parameter spesifik menggunakan getParameterValues
            $this->request("/devices/{$encodedId}/tasks", 'POST', [
                'name'           => 'getParameterValues',
                'parameterNames' => [
                    'InternetGatewayDevice.DeviceInfo.UpTime',
                    'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
                    'InternetGatewayDevice.DeviceInfo.HardwareVersion',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.Uptime',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
                    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Uptime',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.AssociatedDeviceNumberOfEntries',
                    'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.TotalAssociations',
                    'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries',
                    'Device.DeviceInfo.UpTime'
                ]
            ], 8);

            // 2. Segarkan cabang DeviceInfo, WANDevice, dan LANDevice lalu picu connection_request
            $branches = [
                'InternetGatewayDevice.DeviceInfo',
                'InternetGatewayDevice.WANDevice.1',
                'InternetGatewayDevice.LANDevice.1'
            ];
            $lastRes = null;
            foreach ($branches as $idx => $objName) {
                $isLast  = ($idx === count($branches) - 1);
                $ep      = "/devices/{$encodedId}/tasks" . ($isLast ? '?timeout=4000&connection_request' : '');
                $lastRes = $this->request($ep, 'POST', [
                    'name'       => 'refreshObject',
                    'objectName' => $objName
                ], 10);
            }

            if ($lastRes !== null) {
                return $lastRes;
            }
            return $this->request("/devices/{$encodedId}/tasks?timeout=3000&connection_request", 'POST', null, 10);
        }

        public function setParameterValues($deviceId, $parameters, $timeout = 5000)
        {
            $encodedId = rawurlencode($deviceId);
            $endpoint  = "/devices/{$encodedId}/tasks?timeout=" . (int) $timeout . "&connection_request";
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

/**
 * Deteksi entri perangkat palsu / hasil injeksi bot scanner internet (Mirai / DISCOVERYSERVICE / wget)
 */
if (!function_exists('genieacs_is_junk_bot_device')) {
    function genieacs_is_junk_bot_device($d)
    {
        $id          = (string) ($d['_id'] ?? '');
        $sn          = (string) ($d['_deviceId']['_SerialNumber'] ?? '');
        $mfg         = (string) ($d['_deviceId']['_Manufacturer'] ?? '');
        $comb        = strtolower($id . ' ' . $sn . ' ' . $mfg);
        $badPatterns = ['discoveryservice', 'wget ', 'curl ', 'cd /tmp', 'tftp ', '/bin/sh', 'busybox', 'mirai'];
        foreach ($badPatterns as $pat) {
            if (strpos($comb, $pat) !== false) {
                return true;
            }
        }
        return false;
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
            'InternetGatewayDevice.LANDevice.1.Hosts',
            'Device.DeviceInfo',
            'Device.ManagementServer.ConnectionRequestURL',
            'Device.Optical.Interface.1',
            'Device.IP.Interface.1.IPv4Address.1.IPAddress',
            'Device.WiFi',
            'Device.Hosts'
        ];
    }
}

if (!function_exists('genieacs_get_hosts')) {
    function genieacs_get_hosts()
    {
        $d     = ORM::for_table('tbl_appconfig')->where('setting', 'genieacs_hosts')->find_one();
        $raw   = $d ? $d['value'] : ($GLOBALS['config']['genieacs_hosts'] ?? '[]');
        $hosts = json_decode((string) $raw, true);
        if (!is_array($hosts)) {
            return [];
        }
        foreach ($hosts as &$h) {
            if (empty($h['protocol'])) {
                if (preg_match('#^https://#i', $h['host'] ?? '') || (int) ($h['port'] ?? 7557) === 443) {
                    $h['protocol'] = 'https';
                } else {
                    $h['protocol'] = 'http';
                }
            }
            $client        = new GenieACSClient($h['host'] ?? '', (int) ($h['port'] ?? 7557), $h['username'] ?? '', $h['password'] ?? '', $h['protocol']);
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
        $d    = ORM::for_table('tbl_appconfig')->where('setting', 'genieacs_hosts')->find_one();
        if ($d) {
            $d->value = $json;
            $d->save();
        } else {
            $d          = ORM::for_table('tbl_appconfig')->create();
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
        if (empty($hosts)) {
            return null;
        }
        if (!empty($hostId)) {
            foreach ($hosts as $h) {
                if (($h['id'] ?? '') == $hostId) {
                    return $h;
                }
            }
        }
        foreach ($hosts as $h) {
            if (!empty($h['active'])) {
                return $h;
            }
        }
        return $hosts[0] ?? null;
    }
}

if (!function_exists('genieacs_load_config')) {
    function genieacs_load_config($hostId = null)
    {
        $h = genieacs_resolve_host($hostId);
        if (!$h) {
            return null;
        }
        return new GenieACSClient(
            $h['host'] ?? '',
            (int) ($h['port'] ?? 7557),
            $h['username'] ?? '',
            $h['password'] ?? '',
            $h['protocol'] ?? 'auto'
        );
    }
}

if (!function_exists('genieacs_format_uptime')) {
    function genieacs_format_uptime($val)
    {
        if ($val === null || $val === '' || $val === '-' || $val === 'N/A') {
            return '-';
        }
        if (!is_numeric($val)) {
            $clean = trim((string) $val);
            return $clean !== '' ? $clean : '-';
        }
        $s = (int) $val;
        if ($s <= 0) {
            return '-';
        }
        $days  = floor($s / 86400);
        $s    %= 86400;
        $hours = floor($s / 3600);
        $s    %= 3600;
        $mins  = floor($s / 60);
        $secs  = $s % 60;
        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days}d";
        }
        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($mins > 0) {
            $parts[] = "{$mins}m";
        }
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
                if ($ssid === null && !isset($wlan['Enable'])) {
                    continue;
                }
                $password = $wlan['KeyPassphrase']['_value']
                    ?? $wlan['PreSharedKey']['1']['KeyPassphrase']['_value']
                    ?? $wlan['X_HW_WPAKey']['_value']
                    ?? '';
                $beacon   = (string) ($wlan['BeaconType']['_value'] ?? 'WPA2');
                $secMode  = ($beacon === 'None' || $beacon === 'Basic') ? 'None' : (($beacon === 'WPA') ? 'WPAPSK' : (($beacon === 'WPAand11i' || $beacon === 'WPA/WPA2') ? 'WPA/WPA2PSK' : 'WPA2PSK'));
                $channel  = (string) ($wlan['Channel']['_value'] ?? 'Auto');
                $band     = ($i >= 5 || stripos((string) ($wlan['OperatingFrequencyBand']['_value'] ?? ''), '5G') !== false) ? '5GHz' : '2.4GHz';

                $enableVal       = $wlan['Enable']['_value'] ?? true;
                $isEnabled       = ($enableVal === true || $enableVal === 1 || $enableVal === '1' || $enableVal === 'true');
                $assocCountParam = (int) ($wlan['AssociatedDeviceNumberOfEntries']['_value']
                    ?? $wlan['TotalAssociations']['_value']
                    ?? $wlan['X_CT-COM_AssociatedDeviceNumberOfEntries']['_value']
                    ?? 0);
                $assocObjCount = 0;
                if (!empty($wlan['AssociatedDevice']) && is_array($wlan['AssociatedDevice'])) {
                    foreach ($wlan['AssociatedDevice'] as $k => $v) {
                        if (strpos((string) $k, '_') !== 0 && is_array($v)) {
                            $assocObjCount++;
                        }
                    }
                }
                $configs[] = [
                    'index'             => $i,
                    'ssid'              => (string) ($ssid ?? ''),
                    'password'          => (string) $password,
                    'enable'            => $isEnabled,
                    'channel'           => $channel,
                    'band'              => $band,
                    'beacon_type'       => $beacon,
                    'security_mode'     => $secMode,
                    'security'          => $beacon,
                    'mac'               => (string) ($wlan['BSSID']['_value'] ?? '-'),
                    'standard'          => (string) ($wlan['Standard']['_value'] ?? $band),
                    'max_bitrate'       => (string) ($wlan['MaxBitRate']['_value'] ?? '-'),
                    'connected_devices' => max($assocCountParam, $assocObjCount),
                    'source'            => 'tr098'
                ];
            }
        }

        for ($i = 1; $i <= 8; $i++) {
            $ssidEntry = $device['Device']['WiFi']['SSID'][$i] ?? null;
            if ($ssidEntry && is_array($ssidEntry)) {
                $ap        = $device['Device']['WiFi']['AccessPoint'][$i] ?? [];
                $radio     = $device['Device']['WiFi']['Radio'][$i] ?? [];
                $password  = (string) ($ap['Security']['KeyPassphrase']['_value'] ?? '');
                $beacon    = (string) ($ap['Security']['ModeEnabled']['_value'] ?? 'WPA2-Personal');
                $secMode   = ($beacon === 'None') ? 'None' : 'WPA2PSK';
                $freqBand  = (string) ($radio['OperatingFrequencyBand']['_value'] ?? '');
                $band      = (stripos($freqBand, '5G') !== false) ? '5GHz' : '2.4GHz';
                $enableVal = $ssidEntry['Enable']['_value'] ?? true;
                $isEnabled = ($enableVal === true || $enableVal === 1 || $enableVal === '1' || $enableVal === 'true');
                $channel   = (string) ($radio['Channel']['_value'] ?? 'Auto');

                $configs[] = [
                    'index'             => $i,
                    'ssid'              => (string) ($ssidEntry['SSID']['_value'] ?? ''),
                    'password'          => $password,
                    'enable'            => $isEnabled,
                    'channel'           => $channel,
                    'band'              => $band,
                    'beacon_type'       => $beacon,
                    'security_mode'     => $secMode,
                    'security'          => $beacon,
                    'mac'               => (string) ($ap['BSSID']['_value'] ?? ($ssidEntry['BSSID']['_value'] ?? '-')),
                    'standard'          => (string) ($radio['Standard']['_value'] ?? ($freqBand !== '' ? $freqBand : $band)),
                    'max_bitrate'       => (string) ($radio['SupportedFrequencies']['_value'] ?? '-'),
                    'connected_devices' => (int) ($ap['AssociatedDeviceNumberOfEntries']['_value'] ?? ($ap['TotalAssociations']['_value'] ?? 0)),
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
            if (!$wanDev || !is_array($wanDev)) {
                continue;
            }
            $vlanId = $wanDev['X_CT-COM_VLANID']['_value']
                ?? $wanDev['X_HW_VLAN']['_value']
                ?? $wanDev['WANPPPConnection']['1']['X_HW_VLAN']['_value']
                ?? $wanDev['WANIPConnection']['1']['X_HW_VLAN']['_value']
                ?? '';

            if (!empty($wanDev['WANPPPConnection']['1']) && is_array($wanDev['WANPPPConnection']['1'])) {
                $conn             = $wanDev['WANPPPConnection']['1'];
                $natVal           = $conn['NATEnabled']['_value'] ?? true;
                $enableVal        = $conn['Enable']['_value'] ?? true;
                $wanConnections[] = [
                    'index'             => $i,
                    'type'              => 'ppp',
                    'name'              => (string) ($conn['Name']['_value'] ?? "WAN_PPP_{$i}"),
                    'username'          => (string) ($conn['Username']['_value'] ?? ''),
                    'password'          => (string) ($conn['Password']['_value'] ?? ''),
                    'vlan'              => (string) $vlanId,
                    'external_ip'       => (string) ($conn['ExternalIPAddress']['_value'] ?? '-'),
                    'connection_type'   => (string) ($conn['ConnectionType']['_value'] ?? 'IP_Routed'),
                    'connection_status' => (string) ($conn['ConnectionStatus']['_value'] ?? '-'),
                    'nat'               => ($natVal === true || $natVal === 1 || $natVal === '1' || $natVal === 'true'),
                    'enable'            => ($enableVal === true || $enableVal === 1 || $enableVal === '1' || $enableVal === 'true'),
                    'uptime'            => genieacs_format_uptime($conn['Uptime']['_value'] ?? null)
                ];
                $usedIndexes[] = $i;
            } elseif (!empty($wanDev['WANIPConnection']['1']) && is_array($wanDev['WANIPConnection']['1'])) {
                $conn             = $wanDev['WANIPConnection']['1'];
                $natVal           = $conn['NATEnabled']['_value'] ?? true;
                $enableVal        = $conn['Enable']['_value'] ?? true;
                $wanConnections[] = [
                    'index'             => $i,
                    'type'              => 'ip',
                    'name'              => (string) ($conn['Name']['_value'] ?? "WAN_IP_{$i}"),
                    'username'          => '',
                    'password'          => '',
                    'vlan'              => (string) $vlanId,
                    'external_ip'       => (string) ($conn['ExternalIPAddress']['_value'] ?? '-'),
                    'connection_type'   => (string) ($conn['ConnectionType']['_value'] ?? 'IP_Routed'),
                    'connection_status' => (string) ($conn['ConnectionStatus']['_value'] ?? '-'),
                    'nat'               => ($natVal === true || $natVal === 1 || $natVal === '1' || $natVal === 'true'),
                    'enable'            => ($enableVal === true || $enableVal === 1 || $enableVal === '1' || $enableVal === 'true'),
                    'uptime'            => genieacs_format_uptime($conn['Uptime']['_value'] ?? null)
                ];
                $usedIndexes[] = $i;
            }
        }

        $availIndex = null;
        for ($i = 1; $i <= 8; $i++) {
            if (!in_array($i, $usedIndexes, true)) {
                $availIndex = $i;
                break;
            }
        }
        return $wanConnections;
    }
}

if (!function_exists('genieacs_is_valid_client_mac')) {
    /**
     * Memastikan MAC address adalah perangkat klien asli:
     * menolak 00:00:00:xx:xx:xx, FF:FF:FF:FF:FF:FF, format rusak, dan alamat multicast.
     */
    function genieacs_is_valid_client_mac($mac)
    {
        $m = strtoupper(trim((string) $mac));
        if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $m)) {
            return false;
        }
        if (strpos($m, '00:00:00:') === 0 || $m === 'FF:FF:FF:FF:FF:FF') {
            return false;
        }
        // Bit terendah oktet pertama = 1 menandakan alamat multicast/broadcast (bukan perangkat klien)
        $firstOctet = hexdec(substr($m, 0, 2));
        if (($firstOctet & 1) === 1) {
            return false;
        }
        return true;
    }
}

if (!function_exists('genieacs_is_valid_lan_ip')) {
    function genieacs_is_valid_lan_ip($ip)
    {
        $ip = trim((string) $ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        if ($ip === '0.0.0.0' || $ip === '127.0.0.1' || $ip === '255.255.255.255' || strpos($ip, '169.254.') === 0) {
            return false;
        }
        return true;
    }
}

if (!function_exists('genieacs_extract_connected_hosts')) {
    function genieacs_extract_connected_hosts($device, $onlyActive = false)
    {
        $byMac      = [];
        $fallback   = [];
        $addOrMerge = function ($idx, $name, $ip, $mac, $iface, $isActive, $signal) use (&$byMac, &$fallback, $onlyActive) {
            $mac      = strtoupper(trim((string) $mac));
            $ip       = trim((string) $ip);
            $name     = trim((string) $name);
            $signal   = trim((string) $signal);
            $validMac = genieacs_is_valid_client_mac($mac);
            $validIp  = genieacs_is_valid_lan_ip($ip);

            // Buang entri dummy seperti 00:00:00:xx:xx:xx atau slot kosong tanpa MAC/IP valid
            if ($mac !== '' && !$validMac) {
                return;
            }
            if (!$validMac && !$validIp) {
                return;
            }
            if ($onlyActive && !$isActive) {
                return;
            }
            if ($onlyActive && !$validMac) {
                return;
            }

            $cleanName = ($name !== '' && $name !== '*' && strtolower($name) !== 'unknown' && strtolower($name) !== 'null')
                ? $name
                : '';

            if ($validMac && isset($byMac[$mac])) {
                // Gabungkan informasi terbaik (mis. hostname dari DHCP Host + sinyal RSSI dari AssociatedDevice)
                if ($byMac[$mac]['hostname_raw'] === '' && $cleanName !== '') {
                    $byMac[$mac]['hostname_raw'] = $cleanName;
                    $byMac[$mac]['hostname']     = $cleanName;
                }
                if ($byMac[$mac]['ip_address'] === '-' && $validIp) {
                    $byMac[$mac]['ip_address'] = $ip;
                }
                if ($byMac[$mac]['signal'] === '' && $signal !== '') {
                    $byMac[$mac]['signal'] = $signal;
                }
                if ($isActive) {
                    $byMac[$mac]['active'] = true;
                }
                return;
            }

            $suffix   = substr(str_replace([':', '.'], '', $validMac ? $mac : $ip), -4);
            $dispName = $cleanName !== '' ? $cleanName : ('Perangkat-' . strtoupper($suffix));
            $entry    = [
                'index'          => (int) $idx,
                'hostname_raw'   => $cleanName,
                'hostname'       => $dispName,
                'ip_address'     => $validIp ? $ip : '-',
                'mac_address'    => $validMac ? $mac : '-',
                'interface_type' => $iface,
                'active'         => (bool) $isActive,
                'signal'         => $signal
            ];
            if ($validMac) {
                $byMac[$mac] = $entry;
            } else {
                $fallback[] = $entry;
            }
        };

        // 1. TR-098 LANDevice.1.Hosts.Host
        $tr098Hosts = $device['InternetGatewayDevice']['LANDevice']['1']['Hosts']['Host'] ?? [];
        if (is_array($tr098Hosts)) {
            foreach ($tr098Hosts as $idx => $h) {
                if (!is_array($h) || strpos((string) $idx, '_') === 0) {
                    continue;
                }
                $ip        = (string) ($h['IPAddress']['_value'] ?? '');
                $mac       = (string) ($h['MACAddress']['_value'] ?? '');
                $name      = (string) ($h['HostName']['_value'] ?? '');
                $actVal    = $h['Active']['_value'] ?? false;
                $isActive  = ($actVal === true || $actVal === 1 || $actVal === '1' || strtolower((string) $actVal) === 'true');
                $ifaceType = (string) ($h['InterfaceType']['_value'] ?? 'WiFi');
                $connLabel = (stripos($ifaceType, '802.11') !== false || stripos($ifaceType, 'wireless') !== false || stripos($ifaceType, 'wifi') !== false)
                    ? 'WiFi'
                    : ((stripos($ifaceType, 'ethernet') !== false) ? 'LAN' : 'WiFi/LAN');
                $sig       = (string) ($h['X_HW_RSSI']['_value'] ?? ($h['SignalStrength']['_value'] ?? ''));
                $addOrMerge($idx, $name, $ip, $mac, $connLabel, $isActive, $sig);
            }
        }

        // 2. TR-098 WLANConfiguration.i.AssociatedDevice (Klien WiFi yang benar-benar terhubung saat ini)
        for ($i = 1; $i <= 8; $i++) {
            $assocMap = $device['InternetGatewayDevice']['LANDevice']['1']['WLANConfiguration'][$i]['AssociatedDevice'] ?? [];
            if (!is_array($assocMap)) {
                continue;
            }
            foreach ($assocMap as $idx => $ad) {
                if (!is_array($ad) || strpos((string) $idx, '_') === 0) {
                    continue;
                }
                $mac = (string) ($ad['AssociatedDeviceMACAddress']['_value'] ?? '');
                $ip  = (string) ($ad['AssociatedDeviceIPAddress']['_value'] ?? '');
                $sig = (string) ($ad['X_HW_RSSI']['_value'] ?? ($ad['SignalStrength']['_value'] ?? ''));
                $addOrMerge($idx, '', $ip, $mac, 'WiFi', true, $sig);
            }
        }

        // 3. TR-181 Device.Hosts.Host
        $tr181Hosts = $device['Device']['Hosts']['Host'] ?? [];
        if (is_array($tr181Hosts)) {
            foreach ($tr181Hosts as $idx => $h) {
                if (!is_array($h) || strpos((string) $idx, '_') === 0) {
                    continue;
                }
                $ip       = (string) ($h['IPAddress']['_value'] ?? '');
                $mac      = (string) ($h['PhysAddress']['_value'] ?? ($h['MACAddress']['_value'] ?? ''));
                $name     = (string) ($h['HostName']['_value'] ?? '');
                $actVal   = $h['Active']['_value'] ?? false;
                $isActive = ($actVal === true || $actVal === 1 || $actVal === '1' || strtolower((string) $actVal) === 'true');
                $addOrMerge($idx, $name, $ip, $mac, 'WiFi/LAN', $isActive, '');
            }
        }

        $result = array_merge(array_values($byMac), $fallback);
        foreach ($result as &$row) {
            unset($row['hostname_raw']);
        }
        unset($row);
        return $result;
    }
}

if (!function_exists('genieacs_extract_device_info')) {
    function genieacs_extract_device_info($d)
    {
        $pppoe      = 'N/A';
        $wan_ip     = 'N/A';
        $wan_uptime = null;

        for ($i = 1; $i <= 8; $i++) {
            $wanConn = $d['InternetGatewayDevice']['WANDevice']['1']['WANConnectionDevice'][$i] ?? null;
            if (!$wanConn) {
                continue;
            }
            $u = $wanConn['WANPPPConnection']['1']['Username']['_value'] ?? null;
            if ($u && $u !== '' && $u !== 'N/A' && $pppoe === 'N/A') {
                $pppoe = (string) $u;
            }
            $ppp_ip = $wanConn['WANPPPConnection']['1']['ExternalIPAddress']['_value'] ?? null;
            $ip_c   = $wanConn['WANIPConnection']['1']['ExternalIPAddress']['_value'] ?? null;
            if ($ppp_ip && $ppp_ip !== '0.0.0.0' && $wan_ip === 'N/A') {
                $wan_ip = (string) $ppp_ip;
            }
            if ($ip_c && $ip_c !== '0.0.0.0' && $wan_ip === 'N/A') {
                $wan_ip = (string) $ip_c;
            }
            if ($wan_uptime === null) {
                $wan_uptime = $wanConn['WANPPPConnection']['1']['Uptime']['_value']
                    ?? $wanConn['WANIPConnection']['1']['Uptime']['_value']
                    ?? null;
            }
        }

        if ($wan_ip === 'N/A') {
            $tr181Ip = $d['Device']['IP']['Interface']['1']['IPv4Address']['1']['IPAddress']['_value'] ?? null;
            if ($tr181Ip && $tr181Ip !== '0.0.0.0') {
                $wan_ip = (string) $tr181Ip;
            }
        }

        $connUrl = $d['InternetGatewayDevice']['ManagementServer']['ConnectionRequestURL']['_value']
            ?? $d['Device']['ManagementServer']['ConnectionRequestURL']['_value']
            ?? '';
        $tr069ip = 'N/A';
        if ($connUrl !== '' && preg_match('#https?://([^:/]+)#i', (string) $connUrl, $mIp)) {
            $tr069ip = $mIp[1];
        }

        // Ekstraksi RX Power Optik (hanya valid pada ONT PON)
        $rxPowerVal = $d['VirtualParameters']['RXPower']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_CT-COM_EponInterfaceConfig']['RXPower']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_GponInterafceConfig']['RXPower']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_CU_GponInterfaceConfig']['RXPower']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_FH_GponInterfaceConfig']['RXPower']['_value']
            ?? $d['Device']['Optical']['Interface']['1']['OpticalSignalLevel']['_value']
            ?? null;

        $isOptical = false;
        $rxStatus  = 'none';
        if ($rxPowerVal !== null && $rxPowerVal !== '' && is_numeric($rxPowerVal)) {
            $rxRaw = (float) $rxPowerVal;
            if ($rxRaw > 100 || $rxRaw < -100) {
                $rxRaw = $rxRaw / 100.0;
            }
            $isOptical = true;
            $rxPower   = number_format($rxRaw, 2) . ' dBm';
            if ($rxRaw < -28.0) {
                $rxStatus = 'critical';
            } elseif ($rxRaw < -25.0) {
                $rxStatus = 'warning';
            } elseif ($rxRaw <= -8.0) {
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
            ?? $d['Device']['Optical']['Interface']['1']['TransmitOpticalLevel']['_value']
            ?? null;

        if ($txPowerVal !== null && $txPowerVal !== '' && is_numeric($txPowerVal)) {
            $txRaw = (float) $txPowerVal;
            if ($txRaw > 100 || $txRaw < -100) {
                $txRaw = $txRaw / 100.0;
            }
            $isOptical = true;
            $txPower   = number_format($txRaw, 2) . ' dBm';
        } else {
            $txPower = 'N/A';
        }

        // Ekstraksi Suhu Optik / Perangkat
        $tempVal = $d['VirtualParameters']['Temperature']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_CT-COM_EponInterfaceConfig']['TransceiverTemperature']['_value']
            ?? $d['InternetGatewayDevice']['WANDevice']['1']['X_GponInterafceConfig']['TransceiverTemperature']['_value']
            ?? $d['InternetGatewayDevice']['DeviceInfo']['TemperatureStatus']['TemperatureSensor']['1']['Value']['_value']
            ?? null;

        $tempStatus = 'none';
        if ($tempVal !== null && $tempVal !== '' && is_numeric($tempVal)) {
            $t = (float) $tempVal;
            if ($t > 200) {
                $t = $t / 256.0;
            }
            $temp = number_format($t, 1) . ' °C';
            if ($t < 45.0) {
                $tempStatus = 'good';
            } elseif ($t <= 60.0) {
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
        try {
            $cust = null;
            if ($pppoe !== 'N/A' && $pppoe !== '') {
                $cust = ORM::for_table('tbl_customers')
                    ->where_raw('(pppoe_username = ? OR username = ?)', [$pppoe, $pppoe])
                    ->find_one();
            }
            if (!$cust && $tr069ip !== 'N/A') {
                $cust = ORM::for_table('tbl_customers')->where('pppoe_ip', $tr069ip)->find_one();
            }
            if (!$cust && $wan_ip !== 'N/A') {
                $cust = ORM::for_table('tbl_customers')->where('pppoe_ip', $wan_ip)->find_one();
            }
            if ($cust) {
                $customer_id = (int) $cust['id'];
                $fullname    = (string) ($cust['fullname'] ?: $cust['username']);
                $cust_phone  = (string) ($cust['phonenumber'] ?? '');
            }
        } catch (Throwable $e) {
        }

        // Hitung jumlah perangkat terhubung secara akurat dari host aktif ber-MAC valid (tanpa HostNumberOfEntries historis)
        $wlanConnected = 0;
        $wlanCfgs      = genieacs_extract_wlan_configs($d);
        foreach ($wlanCfgs as $wc) {
            $wlanConnected += (int) ($wc['connected_devices'] ?? 0);
        }
        $activeHosts      = genieacs_extract_connected_hosts($d, true);
        $activeHostsCount = count($activeHosts);
        $connectedDevices = ($activeHostsCount > 0) ? $activeHostsCount : $wlanConnected;

        $lastInform   = (string) ($d['_lastInform'] ?? '');
        $lastInformTs = $lastInform !== '' ? (int) strtotime($lastInform) : 0;
        $isOnline     = ($lastInformTs > 0 && (time() - $lastInformTs) < 300);

        $uptimeRaw = $d['InternetGatewayDevice']['DeviceInfo']['UpTime']['_value']
            ?? $d['InternetGatewayDevice']['DeviceInfo']['Uptime']['_value']
            ?? $d['Device']['DeviceInfo']['UpTime']['_value']
            ?? $wan_uptime
            ?? null;

        return [
            '_id'                => (string) ($d['_id'] ?? ''),
            'id'                 => (string) ($d['_id'] ?? ''),
            'serial_number'      => (string) ($d['_deviceId']['_SerialNumber'] ?? ($d['InternetGatewayDevice']['DeviceInfo']['SerialNumber']['_value'] ?? '-')),
            'manufacturer'       => (string) ($d['_deviceId']['_Manufacturer'] ?? ($d['InternetGatewayDevice']['DeviceInfo']['Manufacturer']['_value'] ?? '-')),
            'model'              => (string) ($d['_deviceId']['_ProductClass'] ?? ($d['InternetGatewayDevice']['DeviceInfo']['ModelName']['_value'] ?? '-')),
            'oui'                => (string) ($d['_deviceId']['_OUI'] ?? '-'),
            'product_class'      => (string) ($d['_deviceId']['_ProductClass'] ?? '-'),
            'software_version'   => (string) ($d['InternetGatewayDevice']['DeviceInfo']['SoftwareVersion']['_value'] ?? ($d['Device']['DeviceInfo']['SoftwareVersion']['_value'] ?? '-')),
            'hardware_version'   => (string) ($d['InternetGatewayDevice']['DeviceInfo']['HardwareVersion']['_value'] ?? ($d['Device']['DeviceInfo']['HardwareVersion']['_value'] ?? '-')),
            'uptime_seconds'     => is_numeric($uptimeRaw) ? (int) $uptimeRaw : 0,
            'uptime'             => genieacs_format_uptime($uptimeRaw),
            'pppoe_username'     => $pppoe,
            'wan_ip'             => $wan_ip,
            'tr069_ip'           => $tr069ip,
            'connection_url'     => (string) ($connUrl !== '' ? $connUrl : '-'),
            'is_optical'         => $isOptical,
            'rx_power'           => $rxPower,
            'rx_power_status'    => $rxStatus,
            'tx_power'           => $txPower,
            'temperature'        => $temp,
            'temp_status'        => $tempStatus,
            'online'             => $isOnline,
            'status'             => $isOnline ? 'Online' : 'Offline',
            'connected_devices'  => $connectedDevices,
            'customer_id'        => $customer_id,
            'fullname'           => $fullname,
            'phonenumber'        => $cust_phone,
            'last_inform'        => $lastInform !== '' ? date('Y-m-d H:i:s', $lastInformTs) : '-',
            'last_inform_ts'     => $lastInformTs,
            'tags'               => isset($d['_tags']) && is_array($d['_tags']) ? array_values($d['_tags']) : []
        ];
    }
}

if (!function_exists('genieacs_resolve_customer_device')) {
    function genieacs_resolve_customer_device($client, $customer, $explicitDeviceId = '')
    {
        if (!$client || !$customer) {
            return null;
        }
        if ($explicitDeviceId !== '') {
            $res = $client->getDevice($explicitDeviceId);
            if (!empty($res['success']) && !empty($res['data'])) {
                return $res['data'];
            }
        }

        $candidates = array_values(array_unique(array_filter([
            trim((string) ($customer['pppoe_username'] ?? '')),
            trim((string) ($customer['username'] ?? ''))
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

        $custIp = trim((string) ($customer['pppoe_ip'] ?? ''));
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
        $idx    = max(1, min(8, (int) $wlanIndex));
        $params = [];
        if ($source === 'tr181') {
            if ($ssid !== '') {
                $params[] = ["Device.WiFi.SSID.{$idx}.SSID", $ssid, 'xsd:string'];
            }
            if ($enable === '1' || $enable === '0' || is_bool($enable)) {
                $enBool   = ($enable === true || $enable === '1' || $enable === 1);
                $params[] = ["Device.WiFi.SSID.{$idx}.Enable", $enBool, 'xsd:boolean'];
            }
            if ($channel !== '' && is_numeric($channel)) {
                $params[] = ["Device.WiFi.Radio.{$idx}.Channel", (int) $channel, 'xsd:unsignedInt'];
            }
            if ($security === 'None') {
                $params[] = ["Device.WiFi.AccessPoint.{$idx}.Security.ModeEnabled", 'None', 'xsd:string'];
            } elseif ($security !== '') {
                $params[] = ["Device.WiFi.AccessPoint.{$idx}.Security.ModeEnabled", 'WPA2-Personal', 'xsd:string'];
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
                $enBool   = ($enable === true || $enable === '1' || $enable === 1);
                $params[] = ["{$baseWlan}.Enable", $enBool, 'xsd:boolean'];
            }
            if ($channel !== '' && is_numeric($channel)) {
                $params[] = ["{$baseWlan}.Channel", (int) $channel, 'xsd:unsignedInt'];
            }
            if ($security === 'None') {
                $params[] = ["{$baseWlan}.BeaconType", 'None', 'xsd:string'];
            } elseif ($security !== '') {
                $beacon   = ($security === 'WPAPSK') ? 'WPA' : (($security === 'WPA/WPA2PSK') ? 'WPAand11i' : '11i');
                $encMode  = ($security === 'WPAPSK') ? 'TKIPEncryption' : 'AESEncryption';
                $params[] = ["{$baseWlan}.BeaconType", $beacon, 'xsd:string'];
                $params[] = ["{$baseWlan}.WPAEncryptionModes", $encMode, 'xsd:string'];
                $params[] = ["{$baseWlan}.IEEE11iEncryptionModes", $encMode, 'xsd:string'];
            }
            if ($security !== 'None' && $password !== '') {
                $params[] = ["{$baseWlan}.KeyPassphrase", $password, 'xsd:string'];
                $params[] = ["{$baseWlan}.PreSharedKey.1.KeyPassphrase", $password, 'xsd:string'];
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
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $hostId   = trim((string) _post('id'));
        $name     = trim((string) _post('name'));
        $hostAddr = trim((string) _post('host'));
        $protocol = strtolower(trim((string) (_post('protocol') ?: 'auto')));
        $rawPort  = trim((string) _post('port'));
        $username = trim((string) _post('username'));
        $password = (string) (_post('password') ?? '');

        if ($name === '' || $hostAddr === '') {
            api_out(false, 'Nama Server dan Host/Domain/IP GenieACS wajib diisi.', null, 400);
        }

        if (preg_match('#^(https?)://#i', $hostAddr, $m)) {
            $protocol = strtolower($m[1]);
            $hostAddr = preg_replace('#^https?://#i', '', $hostAddr);
        } elseif ($protocol !== 'http' && $protocol !== 'https') {
            $protocol = ((int) $rawPort === 443) ? 'https' : 'http';
        }
        $port  = ($rawPort === '') ? ($protocol === 'https' ? 443 : 7557) : (int) $rawPort;
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
            if (!$found) {
                api_out(false, 'Host GenieACS tidak ditemukan.', null, 404);
            }
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
        write_admin_log($admin, 'Menyimpan konfigurasi server GenieACS: ' . $name);
        $freshHosts = genieacs_get_hosts();
        api_out(true, 'Konfigurasi server GenieACS ' . $name . ' berhasil disimpan.', [
            'hosts'       => $freshHosts,
            'active_host' => genieacs_resolve_host()
        ]);
        break;

    case 'admin_genieacs_host_action':
        $admin  = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $sub    = strtolower(trim((string) (_req('sub_action') ?: 'test')));
        $hostId = trim((string) _req('id'));
        $hosts  = genieacs_get_hosts();

        if ($sub === 'test') {
            $target = genieacs_resolve_host($hostId);
            if (!$target) {
                api_out(false, 'Host GenieACS tidak ditemukan.', null, 404);
            }
            $client = new GenieACSClient(
                $target['host'] ?? '',
                (int) ($target['port'] ?? 7557),
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

        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
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
                if (($h['id'] ?? '') !== $hostId) {
                    $newHosts[] = $h;
                }
            }
            if (!empty($newHosts)) {
                $hasActive = false;
                foreach ($newHosts as $nh) {
                    if (!empty($nh['active'])) {
                        $hasActive = true;
                        break;
                    }
                }
                if (!$hasActive) {
                    $newHosts[0]['active'] = true;
                }
            }
            genieacs_save_hosts($newHosts);
            write_admin_log($admin, 'Menghapus konfigurasi server GenieACS ID: ' . $hostId);
            api_out(true, 'Host GenieACS berhasil dihapus.', [
                'hosts'       => genieacs_get_hosts(),
                'active_host' => genieacs_resolve_host()
            ]);
        }

        api_out(false, 'Aksi host tidak dikenali.', null, 400);
        break;

    // =========================================================================
    // 2. DASHBOARD & DAFTAR PERANGKAT TR-069 (DENGAN AUTO-CLEANUP BOT/JUNK)
    // =========================================================================
    case 'admin_genieacs_dashboard':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId     = trim((string) _req('host_id'));
        $hosts      = genieacs_get_hosts();
        $activeHost = genieacs_resolve_host($hostId);
        $client     = genieacs_load_config($hostId);

        $stats = [
            'total'           => 0,
            'online'          => 0,
            'offline'         => 0,
            'bad_rx_count'    => 0,
            'high_temp_count' => 0
        ];
        $recentDevices   = [];
        $offlineDevices  = [];
        $connectionError = '';

        if ($client) {
            $result = $client->getDevices([], 500, 0, genieacs_default_projections());
            if (!empty($result['success']) && is_array($result['data'])) {
                foreach ($result['data'] as $d) {
                    if (genieacs_is_junk_bot_device($d)) {
                        if (!empty($d['_id'])) {
                            @$client->deleteDevice($d['_id']);
                        }
                        continue;
                    }
                    $stats['total']++;
                    $info = genieacs_extract_device_info($d);
                    if ($info['online']) {
                        $stats['online']++;
                    } else {
                        $stats['offline']++;
                        if (count($offlineDevices) < 10) {
                            $offlineDevices[] = $info;
                        }
                    }
                    if (in_array($info['rx_power_status'], ['critical', 'los', 'warning'], true)) {
                        $stats['bad_rx_count']++;
                    }
                    if (in_array($info['temp_status'], ['warning', 'critical'], true)) {
                        $stats['high_temp_count']++;
                    }
                    if (count($recentDevices) < 10) {
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
        $hostId       = trim((string) _req('host_id'));
        $search       = trim((string) _req('search'));
        $statusFilter = strtolower(trim((string) (_req('status_filter') ?: 'all')));
        $sortBy       = strtolower(trim((string) (_req('sort_by') ?: 'last_inform')));
        $sortDir      = strtolower(trim((string) (_req('sort_dir') ?: 'desc')));
        $page         = max(1, (int) (_req('page') ?: 1));
        $limit        = max(10, min(200, (int) (_req('limit') ?: 50)));
        $skip         = ($page - 1) * $limit;

        $hosts      = genieacs_get_hosts();
        $activeHost = genieacs_resolve_host($hostId);
        $client     = genieacs_load_config($hostId);
        if (!$client) {
            api_out(false, 'Server GenieACS belum dikonfigurasi. Silakan tambahkan host terlebih dahulu.', [
                'hosts'       => $hosts,
                'active_host' => null
            ], 400);
        }

        $result = $client->getDevices([], 500, 0, genieacs_default_projections());
        if (empty($result['success'])) {
            api_out(false, 'Gagal menghubungi server GenieACS: ' . ($result['error'] ?? 'Unknown error'), [
                'hosts'       => $hosts,
                'active_host' => $activeHost
            ], 500);
        }

        $allExtracted = [];
        if (is_array($result['data'])) {
            foreach ($result['data'] as $d) {
                if (genieacs_is_junk_bot_device($d)) {
                    if (!empty($d['_id'])) {
                        @$client->deleteDevice($d['_id']);
                    }
                    continue;
                }
                $info = genieacs_extract_device_info($d);
                if ($statusFilter === 'online' && !$info['online']) {
                    continue;
                }
                if ($statusFilter === 'offline' && $info['online']) {
                    continue;
                }
                if ($statusFilter === 'bad_rx' && !in_array($info['rx_power_status'], ['critical', 'los', 'warning'], true)) {
                    continue;
                }
                if ($search !== '') {
                    $haystack = strtolower(
                        $info['id'] . ' ' . $info['serial_number'] . ' ' . $info['manufacturer'] . ' ' .
                        $info['model'] . ' ' . $info['pppoe_username'] . ' ' . $info['fullname'] . ' ' .
                        $info['wan_ip'] . ' ' . $info['tr069_ip'] . ' ' . implode(' ', $info['tags'])
                    );
                    if (strpos($haystack, strtolower($search)) === false) {
                        continue;
                    }
                }
                $allExtracted[] = $info;
            }
        }

        usort($allExtracted, function ($a, $b) use ($sortBy, $sortDir) {
            $cmp = 0;
            if ($sortBy === 'serial_number') {
                $cmp = strcasecmp($a['serial_number'], $b['serial_number']);
            } elseif ($sortBy === 'pppoe_username') {
                $cmp = strcasecmp($a['pppoe_username'], $b['pppoe_username']);
            } else {
                $cmp = ($a['last_inform_ts'] <=> $b['last_inform_ts']);
            }
            return ($sortDir === 'asc') ? $cmp : -$cmp;
        });

        $total = count($allExtracted);
        $paged = array_slice($allExtracted, $skip, $limit);

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
    // 3. DETAIL PERANGKAT LENGKAP (INFO, OPTIK, WAN, WLAN, HOSTS, RAW JSON)
    // =========================================================================
    case 'admin_genieacs_device_detail':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $hostId     = trim((string) _req('host_id'));
        $deviceId   = trim((string) (_req('id') ?: _req('device_id')));
        $includeRaw = (_req('include_raw') == '1' || _req('include_raw') === 'true');

        if ($deviceId === '') {
            api_out(false, 'Device ID wajib diisi.', null, 400);
        }
        $client = genieacs_load_config($hostId);
        if (!$client) {
            api_out(false, 'Server GenieACS belum dikonfigurasi.', null, 400);
        }

        $res = $client->getDevice($deviceId);
        if (empty($res['success']) || empty($res['data'])) {
            api_out(false, $res['error'] ?? 'Perangkat tidak ditemukan di GenieACS.', null, 404);
        }

        $dev          = $res['data'];
        $info         = genieacs_extract_device_info($dev);
        $availIndex   = null;
        $wans         = genieacs_extract_wan_connections($dev, $availIndex);
        $wlan         = genieacs_extract_wlan_configs($dev);
        $lanHosts     = genieacs_extract_connected_hosts($dev, false);
        $tasksRes     = $client->getDeviceTasks($deviceId);
        $pendingTasks = (!empty($tasksRes['success']) && is_array($tasksRes['data'])) ? $tasksRes['data'] : [];

        $rawSummary = [
            '_id'               => $dev['_id'] ?? '',
            '_deviceId'         => $dev['_deviceId'] ?? [],
            '_lastInform'       => $dev['_lastInform'] ?? '',
            '_tags'             => $dev['_tags'] ?? [],
            'VirtualParameters' => $dev['VirtualParameters'] ?? [],
            'DeviceInfo'        => $dev['InternetGatewayDevice']['DeviceInfo'] ?? ($dev['Device']['DeviceInfo'] ?? [])
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
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $hostId   = trim((string) _req('host_id'));
        $deviceId = trim((string) (_req('id') ?: _req('device_id')));
        $sub      = strtolower(trim((string) (_req('sub_action') ?: 'summon')));

        $client = genieacs_load_config($hostId);
        if (!$client) {
            api_out(false, 'Server GenieACS belum dikonfigurasi.', null, 400);
        }

        if ($sub === 'clean_junk') {
            $resAll  = $client->getDevices([], 500, 0, ['_id', '_deviceId']);
            $cleaned = 0;
            if (!empty($resAll['success']) && is_array($resAll['data'])) {
                foreach ($resAll['data'] as $d) {
                    if (genieacs_is_junk_bot_device($d) && !empty($d['_id'])) {
                        $client->deleteDevice($d['_id']);
                        $cleaned++;
                    }
                }
            }
            api_out(true, "{$cleaned} perangkat bot/sampah berhasil dibersihkan dari GenieACS.");
        }

        if ($deviceId === '') {
            api_out(false, 'Device ID wajib diisi.', null, 400);
        }

        if ($sub === 'reboot') {
            $res = $client->rebootDevice($deviceId);
            if (!empty($res['success'])) {
                write_admin_log($admin, 'GenieACS Reboot perangkat: ' . $deviceId);
                api_out(true, 'Perintah Reboot berhasil dikirim ke perangkat ' . $deviceId . '.');
            }
            api_out(false, 'Gagal mengirim perintah Reboot: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        }

        if ($sub === 'summon' || $sub === 'refresh') {
            $res = $client->summonDevice($deviceId, ($sub === 'refresh'));
            if (!empty($res['success'])) {
                api_out(true, 'Perintah Summon & penarikan parameter (Uptime, WAN, WiFi & Host) berhasil dikirim ke ' . $deviceId . '.');
            }
            api_out(false, 'Gagal melakukan Summon: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        }

        if ($sub === 'factory_reset') {
            $res = $client->factoryResetDevice($deviceId);
            if (!empty($res['success'])) {
                write_admin_log($admin, 'GenieACS Factory Reset perangkat: ' . $deviceId);
                api_out(true, 'Perintah Factory Reset berhasil dikirim ke perangkat.');
            }
            api_out(false, 'Gagal mengirim Factory Reset: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        }

        if ($sub === 'add_tag') {
            $tag = trim((string) _req('tag'));
            if ($tag === '') {
                api_out(false, 'Nama Tag tidak boleh kosong.', null, 400);
            }
            $res = $client->addTag($deviceId, $tag);
            if (!empty($res['success'])) {
                api_out(true, "Tag '{$tag}' berhasil ditambahkan.");
            }
            api_out(false, 'Gagal menambah Tag: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        }

        if ($sub === 'remove_tag') {
            $tag = trim((string) _req('tag'));
            if ($tag === '') {
                api_out(false, 'Nama Tag tidak boleh kosong.', null, 400);
            }
            $res = $client->removeTag($deviceId, $tag);
            if (!empty($res['success'])) {
                api_out(true, "Tag '{$tag}' berhasil dihapus.");
            }
            api_out(false, 'Gagal menghapus Tag: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        }

        if ($sub === 'clear_tasks') {
            $tRes    = $client->getDeviceTasks($deviceId);
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

        if ($sub === 'delete_device' || $sub === 'delete') {
            $res = $client->deleteDevice($deviceId);
            if (!empty($res['success'])) {
                write_admin_log($admin, 'GenieACS Menghapus perangkat: ' . $deviceId);
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
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $hostId     = trim((string) _req('host_id'));
        $deviceId   = trim((string) (_post('id') ?: _post('device_id')));
        $connIndex  = max(1, min(8, (int) (_post('connection_index') ?: 1)));
        $connType   = (_post('connection_type') === 'ip') ? 'ip' : 'ppp';
        $username   = trim((string) _post('username'));
        $password   = trim((string) _post('password'));
        $vlan       = trim((string) _post('vlan'));
        $nat        = trim((string) _post('nat'));
        $enable     = trim((string) _post('enable'));
        $customerId = (int) _post('customer_id');

        if ($deviceId === '') {
            api_out(false, 'Device ID wajib diisi.', null, 400);
        }
        $client = genieacs_load_config($hostId);
        if (!$client) {
            api_out(false, 'Server GenieACS belum dikonfigurasi.', null, 400);
        }

        if ($customerId > 0 && $username === '') {
            $cust = ORM::for_table('tbl_customers')->find_one($customerId);
            if ($cust) {
                $username = trim((string) ($cust['pppoe_username'] ?: $cust['username']));
                if ($password === '') {
                    $password = trim((string) ($cust['pppoe_password'] ?: $cust['password']));
                }
            }
        }

        $basePath = "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$connIndex}."
            . ($connType === 'ppp' ? 'WANPPPConnection.1' : 'WANIPConnection.1');

        $params = [];
        if ($connType === 'ppp') {
            if ($username !== '') {
                $params[] = [$basePath . '.Username', $username, 'xsd:string'];
            }
            if ($password !== '') {
                $params[] = [$basePath . '.Password', $password, 'xsd:string'];
            }
        }
        if ($vlan !== '' && is_numeric($vlan)) {
            $vlanInt  = (int) $vlan;
            $params[] = ["InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$connIndex}.X_CT-COM_VLANID", $vlanInt, 'xsd:unsignedInt'];
            $params[] = ["InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$connIndex}.X_HW_VLAN", $vlanInt, 'xsd:unsignedInt'];
            $params[] = [$basePath . '.X_HW_VLAN', $vlanInt, 'xsd:unsignedInt'];
        }
        if ($nat === '1' || $nat === '0') {
            $params[] = [$basePath . '.NATEnabled', ($nat === '1'), 'xsd:boolean'];
        }
        if ($enable === '1' || $enable === '0') {
            $params[] = [$basePath . '.Enable', ($enable === '1'), 'xsd:boolean'];
        }

        if (empty($params)) {
            api_out(false, 'Tidak ada parameter WAN yang diubah.', null, 400);
        }

        $res = $client->setParameterValues($deviceId, $params, 5000);
        if (!empty($res['success'])) {
            write_admin_log($admin, "GenieACS Update WAN #{$connIndex} pada perangkat {$deviceId}");
            api_out(true, "Konfigurasi WAN #{$connIndex} (" . strtoupper($connType) . ") berhasil dikirim ke perangkat.");
        }
        api_out(false, 'Gagal memperbarui WAN: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        break;

    // =========================================================================
    // 6. KONFIGURASI WLAN (EDIT SSID, PASSWORD, SECURITY, CHANNEL, ENABLE) - ADMIN
    // =========================================================================
    case 'admin_genieacs_wlan_save':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $hostId    = trim((string) _req('host_id'));
        $deviceId  = trim((string) (_post('id') ?: _post('device_id')));
        $wlanIndex = max(1, min(8, (int) (_post('wlan_index') ?: 1)));
        $ssid      = trim((string) _post('ssid'));
        $password  = trim((string) _post('password'));
        $security  = trim((string) (_post('security_mode') ?: 'WPA2PSK'));
        $channel   = trim((string) _post('channel'));
        $enable    = trim((string) _post('enable'));
        $source    = strtolower(trim((string) (_post('source') ?: 'tr098')));

        if ($deviceId === '' || $ssid === '') {
            api_out(false, 'Device ID dan Nama WiFi (SSID) wajib diisi.', null, 400);
        }
        if ($security !== 'None' && $password !== '' && strlen($password) < 8) {
            api_out(false, 'Password WiFi minimal 8 karakter.', null, 400);
        }

        $client = genieacs_load_config($hostId);
        if (!$client) {
            api_out(false, 'Server GenieACS belum dikonfigurasi.', null, 400);
        }

        $params = genieacs_build_wlan_set_params($wlanIndex, $ssid, $password, $security, $source, $channel, $enable);
        $res    = $client->setParameterValues($deviceId, $params, 10000);
        if (!empty($res['success'])) {
            write_admin_log($admin, "GenieACS Update WiFi #{$wlanIndex} ({$ssid}) pada perangkat {$deviceId}");
            api_out(true, "Konfigurasi WiFi #{$wlanIndex} (SSID: {$ssid}) berhasil diperbarui!");
        }
        api_out(false, 'Gagal mengubah WiFi: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        break;

    // =========================================================================
    // 7. ENDPOINT KHUSUS APK PELANGGAN (ANTI-IDOR & PAYLOAD AMAN TERSANITASI)
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

        // KEAMANAN (Anti-IDOR): Abaikan device_id dari client; resolusi ketat dari sesi pelanggan yang login.
        $device = genieacs_resolve_customer_device($client, $customer, '');
        if (!$device) {
            api_out(false, 'Perangkat modem/router belum terdeteksi untuk akun Anda.', null, 404);
        }

        $deviceInfo     = genieacs_extract_device_info($device);
        $wlanConfigs    = genieacs_extract_wlan_configs($device);
        $connectedHosts = genieacs_extract_connected_hosts($device, true); // Hanya perangkat aktif & MAC valid
        $wanConnections = genieacs_extract_wan_connections($device);

        // Deteksi status koneksi internet secara aman tanpa membocorkan IP WAN/VLAN/PPPoE
        $internetStatus = $deviceInfo['online'] ? 'Connected' : 'Disconnected';
        foreach ($wanConnections as $wc) {
            if (!empty($wc['enable']) && strcasecmp((string) ($wc['connection_status'] ?? ''), 'Connected') === 0) {
                $internetStatus = 'Connected';
                break;
            }
        }

        // Saring hanya field WLAN yang aman (tanpa BSSID/MAC internal router)
        $safeWlans = [];
        foreach ($wlanConfigs as $w) {
            if (trim((string) ($w['ssid'] ?? '')) === '' && empty($w['enable'])) {
                continue;
            }
            $safeWlans[] = [
                'index'             => (int) ($w['index'] ?? 1),
                'ssid'              => (string) ($w['ssid'] ?? ''),
                'password'          => (string) ($w['password'] ?? ''),
                'enable'            => (bool) ($w['enable'] ?? true),
                'band'              => (string) ($w['band'] ?? '2.4GHz'),
                'channel'           => (string) ($w['channel'] ?? 'Auto'),
                'security'          => (string) ($w['security'] ?? 'WPA2-PSK'),
                'security_mode'     => (string) ($w['security_mode'] ?? 'WPA2PSK'),
                'connected_devices' => (int) ($w['connected_devices'] ?? 0),
                'source'            => (string) ($w['source'] ?? 'tr098')
            ];
        }

        $activeCount = count($connectedHosts);
        if ($activeCount === 0) {
            foreach ($safeWlans as $sw) {
                $activeCount += (int) $sw['connected_devices'];
            }
        }

        $mfgPart   = trim((string) ($deviceInfo['manufacturer'] ?? ''));
        $modelPart = trim((string) ($deviceInfo['model'] ?? ''));
        if ($mfgPart === '-') {
            $mfgPart = '';
        }
        if ($modelPart === '-') {
            $modelPart = '';
        }
        $displayModel = trim($mfgPart . ' ' . $modelPart);
        if ($mfgPart !== '' && $modelPart !== '' && stripos($modelPart, $mfgPart) !== false) {
            $displayModel = $modelPart;
        }
        if ($displayModel === '') {
            $displayModel = 'Router Pelanggan';
        }

        // Payload perangkat aman (TANPA device_id, serial_number, wan_ip, tr069_ip, connection_url, pppoe_username, tags)
        $safeDevice = [
            'online'             => (bool) $deviceInfo['online'],
            'manufacturer'       => $mfgPart,
            'model'              => $displayModel,
            'device_type'        => !empty($deviceInfo['is_optical']) ? 'Optical ONT' : 'Wireless Router',
            'internet_status'    => $internetStatus,
            'uptime'             => (string) ($deviceInfo['uptime'] ?? '-'),
            'last_inform'        => (string) ($deviceInfo['last_inform'] ?? '-'),
            'is_optical'         => (bool) ($deviceInfo['is_optical'] ?? false),
            'rx_power'           => (string) ($deviceInfo['rx_power'] ?? 'N/A'),
            'rx_power_status'    => (string) ($deviceInfo['rx_power_status'] ?? 'normal'),
            'temperature'        => (string) ($deviceInfo['temperature'] ?? ''),
            'temperature_status' => (string) ($deviceInfo['temp_status'] ?? 'normal'),
            'connected_devices'  => $activeCount
        ];

        api_out(true, 'Status perangkat berhasil dimuat', [
            'online'          => $safeDevice['online'],
            'device'          => $safeDevice,
            'wlan_configs'    => $safeWlans,
            'connected_hosts' => $connectedHosts
        ]);
        break;

    case 'customer_genieacs_wlan_save':
    case 'genieacs_customer_wlan_edit':
    case 'customer_wifi_save':
        $customer  = require_customer_session($api_secret);
        $wlanIndex = max(1, min(8, (int) (_post('wlan_index') ?: 1)));
        $ssid      = trim((string) _post('ssid'));
        $password  = trim((string) _post('password'));
        $security  = trim((string) (_post('security_mode') ?: 'WPA2PSK'));
        $source    = strtolower(trim((string) (_post('source') ?: 'tr098')));

        if ($ssid === '' || strlen($ssid) > 32) {
            api_out(false, 'Nama WiFi (SSID) wajib diisi (maksimal 32 karakter).', null, 400);
        }
        if ($security !== 'None' && $password !== '' && (strlen($password) < 8 || strlen($password) > 63)) {
            api_out(false, 'Password WiFi harus 8 sampai 63 karakter.', null, 400);
        }

        $client = genieacs_load_config();
        if (!$client) {
            api_out(false, 'Server GenieACS tidak tersedia.', null, 400);
        }

        // KEAMANAN (Anti-IDOR): Abaikan id dari POST; resolusi ketat dari sesi customer
        $device = genieacs_resolve_customer_device($client, $customer, '');
        if (!$device || empty($device['_id'])) {
            api_out(false, 'Perangkat modem Anda tidak ditemukan.', null, 404);
        }

        $deviceId      = $device['_id'];
        $existingWlans = genieacs_extract_wlan_configs($device);
        foreach ($existingWlans as $ew) {
            if ((int) $ew['index'] === $wlanIndex && !empty($ew['source'])) {
                $source = $ew['source'];
                break;
            }
        }

        $params = genieacs_build_wlan_set_params($wlanIndex, $ssid, $password, $security, $source);
        $res    = $client->setParameterValues($deviceId, $params, 10000);
        if (!empty($res['success'])) {
            api_out(true, 'Nama WiFi & Password berhasil diperbarui! Perangkat Anda akan menyambung ulang.', [
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
        if (!$client) {
            api_out(false, 'Server GenieACS tidak tersedia.', null, 400);
        }

        // KEAMANAN (Anti-IDOR): Resolusi ketat dari sesi customer
        $device = genieacs_resolve_customer_device($client, $customer, '');
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
        if (!$client) {
            api_out(false, 'Server GenieACS tidak tersedia.', null, 400);
        }

        // KEAMANAN (Anti-IDOR): Resolusi ketat dari sesi customer
        $device = genieacs_resolve_customer_device($client, $customer, '');
        if (!$device || empty($device['_id'])) {
            api_out(false, 'Perangkat modem Anda tidak ditemukan.', null, 404);
        }

        $res = $client->summonDevice($device['_id'], false);
        if (!empty($res['success'])) {
            api_out(true, 'Sinkronisasi data modem berhasil dikirim.');
        }
        api_out(false, 'Gagal menyegarkan data modem: ' . ($res['error'] ?? 'Unknown error'), null, 500);
        break;
}
