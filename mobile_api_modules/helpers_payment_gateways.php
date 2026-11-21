<?php
/**
 * PHPNuxBill Mobile API Plugin - Payment Gateways Adapter & Helper Module
 * Path: system/plugin/mobile_api_modules/helpers_payment_gateways.php
 *
 * Mendukung: ViaQRIS, Tripay Proxy, Duitku, Xendit, PayPal, dan Gateway Generic.
 * Menjaga format kontrak JSON seragam (qris | va_code | ewallet_link | checkout_url)
 * untuk seluruh versi APK Android.
 */

if (!function_exists('get_gateway_min_amount')) {
    function get_gateway_min_amount($gw_lower, $channel_id)
    {
        $cid = strtoupper(trim((string) $channel_id));
        if ($gw_lower === 'tripay') {
            if (strpos($cid, 'QRIS') !== false || in_array($cid, ['OVO', 'DANA', 'SHOPEEPAY', 'LINKAJA'], true)) {
                return 1000;
            }
            return 10000;
        }
        if ($gw_lower === 'duitku') {
            if (in_array($cid, ['SP', 'LQ', 'NQ', 'GQ', 'OV', 'SA', 'SL', 'DA', 'LA', 'LF', 'OL'], true)) {
                return 1000;
            }
            return 10000;
        }
        return 1000;
    }
}

if (!function_exists('get_gateway_channels')) {
    function get_gateway_channels($gw_key, $config, $pg_dir = 'system/paymentgateway')
    {
        $gw        = strtolower(trim((string) $gw_key));
        $cfg_key   = $gw . '_channel';
        $json_file = rtrim($pg_dir, '/\\') . DIRECTORY_SEPARATOR . 'channel_' . $gw . '.json';
        if (empty($config[$cfg_key]) || !file_exists($json_file)) {
            return [];
        }
        $admin_checked_ids = array_values(array_filter(array_map('trim', explode(',', (string) $config[$cfg_key]))));
        if (empty($admin_checked_ids)) {
            return [];
        }
        $all_channels = json_decode((string) @file_get_contents($json_file), true);
        if (!is_array($all_channels)) {
            return [];
        }
        $list = [];
        foreach ($all_channels as $ch) {
            $cid = trim((string) ($ch['id'] ?? ''));
            if ($cid !== '' && in_array($cid, $admin_checked_ids, true)) {
                $list[] = [
                    'id'         => $cid,
                    'name'       => trim((string) ($ch['name'] ?? $cid)),
                    'min_amount' => get_gateway_min_amount($gw, $cid)
                ];
            }
        }
        return $list;
    }
}

if (!function_exists('pg_is_gateway_configured')) {
    /**
     * Memastikan gateway yang dicentang admin benar-benar sudah diisi kredensial minimalnya
     * sesuai fungsi {gateway}_validate_config() pada masing-masing plugin resmi.
     */
    function pg_is_gateway_configured($gw_lower, $config)
    {
        switch ($gw_lower) {
            case 'viaqris':
                return !empty($config['viaqris_api_url']);
            case 'tripay':
                return !empty($config['tripay_secret_key']);
            case 'duitku':
                return !empty($config['duitku_merchant_id']) && !empty($config['duitku_merchant_key']);
            case 'xendit':
                return !empty($config['xendit_secret_key']) && !empty($config['xendit_verification_token']);
            case 'paypal':
                return !empty($config['paypal_client_id']) && !empty($config['paypal_secret_key']);
            default:
                return true;
        }
    }
}

if (!function_exists('get_active_gateways')) {
    function get_active_gateways($config)
    {
        global $PAYMENTGATEWAY_PATH;
        $pg_dir = !empty($PAYMENTGATEWAY_PATH) ? $PAYMENTGATEWAY_PATH : 'system/paymentgateway';
        $raw    = trim((string) ($config['payment_gateway'] ?? ''));
        if ($raw === '' || strtolower($raw) === 'none') {
            return [];
        }
        $pgs = array_values(array_filter(array_map('trim', explode(',', $raw)), function ($g) {
            return $g !== '' && strtolower($g) !== 'none';
        }));
        $result = [];
        foreach ($pgs as $gw) {
            $gw_file = rtrim($pg_dir, '/\\') . DIRECTORY_SEPARATOR . $gw . '.php';
            if (!file_exists($gw_file)) {
                continue;
            }
            $gw_lower = strtolower($gw);
            if (!pg_is_gateway_configured($gw_lower, $config)) {
                continue;
            }
            $channel_tpl      = rtrim($pg_dir, '/\\') . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . $gw . '_channel.tpl';
            $requires_channel = file_exists($channel_tpl);
            $channels         = get_gateway_channels($gw, $config, $pg_dir);

            // Jika plugin mewajibkan pemilihan channel (Tripay/Duitku) tapi Admin belum mengaktifkan channel apa pun, lewati.
            if ($requires_channel && empty($channels)) {
                continue;
            }

            if ($gw_lower === 'paypal') {
                $cur  = !empty($config['paypal_currency']) ? $config['paypal_currency'] : 'USD';
                $desc = 'Pembayaran Internasional PayPal (' . $cur . ')';
            } elseif ($gw_lower === 'viaqris') {
                $desc = 'Pembayaran Instan QRIS (DANA, GoPay, OVO, ShopeePay, m-Banking)';
            } elseif (!empty($channels)) {
                $names   = array_column($channels, 'name');
                $preview = implode(', ', array_slice($names, 0, 4));
                if (count($names) > 4) {
                    $preview .= ', dll.';
                }
                $desc = ($requires_channel ? 'Pilih Channel: ' : 'Mendukung: ') . $preview;
            } else {
                $desc = 'Pembayaran Otomatis (' . strtoupper($gw) . ')';
            }

            $result[] = [
                'id'               => $gw,
                'name'             => strtoupper($gw),
                'description'      => $desc,
                'requires_channel' => $requires_channel,
                'channels'         => $requires_channel ? $channels : []
            ];
        }
        return $result;
    }
}

if (!function_exists('calculate_order_cost')) {
    function calculate_order_cost($user_id, $plan, $config)
    {
        $base_price = (float) $plan['price'];
        $is_balance = (strtoupper(trim((string) $plan['type'])) === 'BALANCE' || ($plan['routers'] ?? '') === 'balance');
        if (!$is_balance && ($plan['validity_unit'] ?? '') === 'Period' && class_exists('User') && method_exists('User', 'getAttribute')) {
            $add_inv = User::getAttribute('Invoice', $user_id);
            if (!empty($add_inv) && (float) $add_inv > 0) {
                $base_price = (float) $add_inv;
            }
        }
        $bills    = [];
        $add_cost = 0.0;
        if (!$is_balance && class_exists('User') && method_exists('User', 'getBills')) {
            $bill_res = User::getBills($user_id);
            if (is_array($bill_res)) {
                $bills    = $bill_res[0] ?? [];
                $add_cost = (float) ($bill_res[1] ?? 0);
            }
        }
        $tax        = 0.0;
        $tax_enable = $config['enable_tax'] ?? 'no';
        if ($tax_enable === 'yes' && class_exists('Package') && method_exists('Package', 'tax')) {
            $tax_rate_setting = $config['tax_rate'] ?? null;
            $custom_tax_rate  = isset($config['custom_tax_rate']) ? (float) $config['custom_tax_rate'] : null;
            $tax_rate         = ($tax_rate_setting === 'custom') ? $custom_tax_rate : $tax_rate_setting;
            $tax              = (float) Package::tax($base_price + $add_cost, $tax_rate);
        }
        return [
            'base_price' => $base_price,
            'add_cost'   => $add_cost,
            'tax'        => $tax,
            'bills'      => $bills,
            'total_cost' => $base_price + $add_cost + $tax
        ];
    }
}

if (!function_exists('qris_crc16')) {
    function qris_crc16($str)
    {
        $crc = 0xFFFF;
        for ($c = 0, $len = strlen($str); $c < $len; $c++) {
            $crc ^= ord($str[$c]) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) ? ((($crc << 1) ^ 0x1021) & 0xFFFF) : (($crc << 1) & 0xFFFF);
            }
        }
        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}

if (!function_exists('to_dynamic_qris')) {
    function to_dynamic_qris($qris_raw, $amount)
    {
        $qris = trim((string) $qris_raw);
        if (strpos($qris, '000201') !== 0 || strpos($qris, '5802ID') === false) {
            return $qris;
        }
        $amt_str = (string) ((int) $amount);
        if ((int) $amt_str <= 0) {
            return $qris;
        }
        $crc_pos = strrpos($qris, '6304');
        if ($crc_pos === false || $crc_pos < strlen($qris) - 8) {
            return $qris;
        }
        $body  = preg_replace('/^000201010211/', '000201010212', substr($qris, 0, $crc_pos), 1);
        $parts = explode('5802ID', $body, 2);
        if (count($parts) === 2) {
            $tag54 = '54' . str_pad((string) strlen($amt_str), 2, '0', STR_PAD_LEFT) . $amt_str;
            if (strpos($parts[0], $tag54) === false) {
                $body = $parts[0] . $tag54 . '5802ID' . $parts[1];
            }
        }
        $body .= '6304';
        return $body . qris_crc16($body);
    }
}

if (!function_exists('verify_already_recharged')) {
    function verify_already_recharged(&$trx)
    {
        if (!$trx) {
            return false;
        }
        if ((int) $trx['status'] === 2) {
            return true;
        }
        if (!empty($trx['created_date'])) {
            $created_ts = strtotime((string) $trx['created_date']);
            $recent     = ORM::for_table('tbl_transactions')
                ->where('username', $trx['username'])
                ->where('plan_name', $trx['plan_name'])
                ->order_by_desc('id')
                ->find_one();
            if ($recent && !empty($recent['recharged_on'])) {
                $rech_ts = strtotime($recent['recharged_on'] . ' ' . ($recent['recharged_time'] ?: '00:00:00'));
                if ($rech_ts >= ($created_ts - 5)) {
                    $trx->status = 2;
                    if (empty($trx->paid_date)) {
                        $trx->paid_date = date('Y-m-d H:i:s');
                    }
                    if (empty($trx->trx_invoice) && !empty($recent['invoice'])) {
                        $trx->trx_invoice = $recent['invoice'];
                    }
                    $trx->save();
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('check_and_expire_trx')) {
    function check_and_expire_trx(&$trx)
    {
        if (!$trx || (int) $trx['status'] !== 1) {
            return false;
        }
        if (verify_already_recharged($trx)) {
            return false;
        }
        $gw_lower = strtolower(trim((string) ($trx['gateway'] ?? '')));
        if ($gw_lower !== 'viaqris' && empty($trx['gateway_trx_id']) && empty($trx['pg_url_payment']) && empty($trx['pg_request'])) {
            $trx->status = 4;
            $trx->save();
            return true;
        }
        $now = time();
        if (!empty($trx['expired_date']) && $trx['expired_date'] !== '0000-00-00 00:00:00') {
            $exp_ts = strtotime((string) $trx['expired_date']);
            if ($exp_ts > 0 && $now > $exp_ts) {
                $trx->status = 3;
                $trx->save();
                return true;
            }
        } elseif (!empty($trx['created_date'])) {
            $cr_ts = strtotime((string) $trx['created_date']);
            if ($cr_ts > 0 && ($now - $cr_ts) > 86400) {
                $trx->status = 3;
                $trx->save();
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('pg_detect_bank_code')) {
    /**
     * Memetakan kode metode / nama channel Tripay & Duitku ke kode bank/e-wallet standar
     * agar APK Android dapat memunculkan tombol pintas "Buka Aplikasi Bank / E-Wallet".
     */
    function pg_detect_bank_code($gw_lower, $method_code, $channel_name)
    {
        $m = strtoupper(trim((string) $method_code));
        $c = strtoupper(trim((string) $channel_name));

        if ($gw_lower === 'duitku') {
            $map = [
                'BC' => 'BCA',
                'BR' => 'BRI',
                'M2' => 'MANDIRI',
                'M1' => 'MANDIRI',
                'I1' => 'BNI',
                'BT' => 'PERMATA',
                'B1' => 'CIMB',
                'BV' => 'BSI',
                'DM' => 'DANAMON',
                'VA' => 'MAYBANK',
                'NC' => 'NEOBANK',
                'OV' => 'OVO',
                'DA' => 'DANA',
                'SA' => 'SHOPEEPAY',
                'SL' => 'SHOPEEPAY',
                'LA' => 'LINKAJA',
                'LF' => 'LINKAJA',
                'OL' => 'LINKAJA',
                'SP' => 'QRIS',
                'LQ' => 'QRIS',
                'NQ' => 'QRIS',
                'GQ' => 'QRIS'
            ];
            if (isset($map[$m])) {
                return $map[$m];
            }
        }

        $haystack = $m . ' ' . $c;
        if (strpos($haystack, 'QRIS') !== false) return 'QRIS';
        if (strpos($haystack, 'BCA') !== false) return 'BCA';
        if (strpos($haystack, 'BRI') !== false) return 'BRI';
        if (strpos($haystack, 'MANDIRI') !== false) return 'MANDIRI';
        if (strpos($haystack, 'BNI') !== false) return 'BNI';
        if (strpos($haystack, 'BSI') !== false || strpos($haystack, 'SYARIAH') !== false) return 'BSI';
        if (strpos($haystack, 'PERMATA') !== false) return 'PERMATA';
        if (strpos($haystack, 'CIMB') !== false) return 'CIMB';
        if (strpos($haystack, 'DANAMON') !== false) return 'DANAMON';
        if (strpos($haystack, 'MAYBANK') !== false) return 'MAYBANK';
        if (strpos($haystack, 'MUAMALAT') !== false) return 'MUAMALAT';
        if (strpos($haystack, 'DANA') !== false) return 'DANA';
        if (strpos($haystack, 'OVO') !== false) return 'OVO';
        if (strpos($haystack, 'SHOPEE') !== false) return 'SHOPEEPAY';
        if (strpos($haystack, 'GOPAY') !== false) return 'GOPAY';
        if (strpos($haystack, 'LINKAJA') !== false) return 'LINKAJA';
        if (strpos($haystack, 'PAYPAL') !== false) return 'PAYPAL';
        return '';
    }
}

if (!function_exists('pg_default_qris_instructions')) {
    function pg_default_qris_instructions()
    {
        return [
            [
                'title' => 'Pembayaran via QRIS (Scan Langsung)',
                'steps' => [
                    'Buka aplikasi m-Banking atau E-Wallet (DANA, GoPay, OVO, ShopeePay, LinkAja, BCA, BRI, Mandiri, BNI, BSI)',
                    'Pilih menu Scan / Bayar QRIS lalu pindai kode QR di layar ini',
                    'Pastikan nama merchant dan nominal transaksi sudah sesuai',
                    'Konfirmasi dan selesaikan pembayaran',
                    'Kembali ke aplikasi ini, status pembayaran akan diperiksa otomatis'
                ]
            ],
            [
                'title' => 'Pembayaran via QRIS (Upload Gambar di HP yang Sama)',
                'steps' => [
                    'Ketuk tombol Simpan QR ke Galeri pada layar ini',
                    'Buka aplikasi m-Banking atau E-Wallet Anda lalu pilih menu Scan QRIS',
                    'Pilih ikon Galeri / Upload Gambar lalu pilih file QRIS yang baru disimpan',
                    'Periksa nominal transaksi lalu selesaikan pembayaran',
                    'Kembali ke aplikasi ini untuk aktivasi paket otomatis'
                ]
            ]
        ];
    }
}

if (!function_exists('pg_is_internal_nux_url')) {
    function pg_is_internal_nux_url($url)
    {
        $u = trim((string) $url);
        if ($u === '') {
            return true;
        }
        return (stripos($u, '_route=plugin/') !== false || stripos($u, 'plugin/viaqris_show_payment') !== false || stripos($u, 'plugin/tripay_show_payment') !== false);
    }
}

if (!function_exists('pg_parse_viaqris')) {
    function pg_parse_viaqris($unpaid, $req_json, $config, &$ctx)
    {
        $ctx['ui_mode']      = 'qris';
        $ctx['checkout_url'] = '';

        // 1. Cek langsung field resmi dari plugin Plugin-ViaQris-View-Payment (`url_pambayaran`)
        if (is_array($req_json)) {
            $direct_qr_candidates = [
                $req_json['url_pambayaran'] ?? '',
                $req_json['url_pembayaran'] ?? '',
                $req_json['data']['qr_url'] ?? '',
                $req_json['qr_url'] ?? '',
                $req_json['qris_url'] ?? '',
                $req_json['qr_image'] ?? '',
                $req_json['qris_image'] ?? ''
            ];
            foreach ($direct_qr_candidates as $cand) {
                $v = str_replace('\/', '/', trim((string) $cand));
                if ($v === '') {
                    continue;
                }
                if (strpos($v, '000201') === 0 && strlen($v) > 40) {
                    if ($ctx['qris_string'] === '') {
                        $ctx['qris_string'] = $v;
                    }
                } elseif (strpos($v, 'data:image/') === 0 || preg_match('#^https?://#i', $v)) {
                    if ($ctx['qris_image'] === '') {
                        $ctx['qris_image'] = $v;
                    }
                }
            }

            if (!empty($req_json['harga_transaksi']) && is_numeric($req_json['harga_transaksi']) && (float) $req_json['harga_transaksi'] > 0) {
                $ctx['total_amount'] = (float) $req_json['harga_transaksi'];
            }
            if (!empty($req_json['data']['payment_name'])) {
                $ctx['channel_name'] = trim((string) $req_json['data']['payment_name']);
            }
            $ext_checkout = str_replace('\/', '/', trim((string) ($req_json['data']['checkout_url'] ?? ($req_json['checkout_url'] ?? ''))));
            if ($ext_checkout !== '' && preg_match('#^https?://#i', $ext_checkout) && !pg_is_internal_nux_url($ext_checkout)) {
                $ctx['checkout_url'] = $ext_checkout;
            }
        }

        // 2. Pemindaian rekursif untuk menangkap string EMV QRIS (000201...) / data URI / nominal unik
        foreach (['pg_request', 'pg_paid_response'] as $col) {
            if (empty($unpaid[$col])) {
                continue;
            }
            $raw = trim((string) $unpaid[$col]);
            if ($ctx['qris_string'] === '' && preg_match('/(000201[0-9A-Za-z\.\-\s\/\+]{40,600})/', $raw, $m)) {
                $ctx['qris_string'] = trim($m[1]);
            }
            if ($ctx['qris_image'] === '' && preg_match('/(data:image\/[a-zA-Z0-9\+\/\=;:,]+)/', $raw, $m)) {
                $ctx['qris_image'] = trim($m[1]);
            }
            $j = json_decode($raw, true);
            if (is_array($j)) {
                array_walk_recursive($j, function ($val, $key) use (&$ctx) {
                    $k = strtolower((string) $key);
                    $v = str_replace('\/', '/', trim((string) $val));
                    if (in_array($k, ['amount', 'total', 'nominal', 'total_amount', 'amount_unique', 'bayar', 'harga_transaksi'], true) && is_numeric($v) && (float) $v > 0) {
                        $ctx['total_amount'] = (float) $v;
                    }
                    if ($ctx['qris_string'] === '' && strpos($v, '000201') === 0 && strlen($v) > 40) {
                        $ctx['qris_string'] = $v;
                    } elseif ($ctx['qris_image'] === '' && strpos($v, 'data:image/') === 0) {
                        $ctx['qris_image'] = $v;
                    } elseif ($ctx['qris_image'] === '' && in_array($k, ['url_pambayaran', 'url_pembayaran', 'qr_url', 'qris_url', 'qr_image', 'qris_image'], true) && preg_match('#^https?://#i', $v)) {
                        $ctx['qris_image'] = $v;
                    }
                });
            }
        }

        // 3. Fallback dari konfigurasi QRIS statis (jika admin menyimpannya di tbl_appconfig)
        foreach ($config as $k => $v) {
            if (!is_string($v)) {
                continue;
            }
            $vt = trim($v);
            if ($ctx['qris_string'] === '' && strpos($vt, '000201') === 0) {
                $ctx['qris_string'] = to_dynamic_qris($vt, $ctx['total_amount']);
            }
            if ($ctx['qris_image'] === '' && stripos($k, 'qris') !== false && (stripos($vt, 'http') === 0 || preg_match('/\.(png|jpg|jpeg|webp)$/i', $vt))) {
                $ctx['qris_image'] = (stripos($vt, 'http') === 0) ? $vt : (defined('APP_URL') ? rtrim(APP_URL, '/') . '/' . ltrim($vt, '/') : $vt);
            }
        }

        if ($ctx['qris_string'] !== '') {
            $ctx['qris_string'] = to_dynamic_qris($ctx['qris_string'], $ctx['total_amount']);
            if ($ctx['qris_image'] === '') {
                $ctx['qris_image'] = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&margin=20&data=' . urlencode($ctx['qris_string']);
            }
        }

        if ($ctx['qris_image'] === '' && $ctx['qris_string'] === '' && $ctx['checkout_url'] !== '') {
            $ctx['ui_mode'] = 'checkout_url';
        }

        if (empty($ctx['instructions'])) {
            $ctx['instructions'] = pg_default_qris_instructions();
        }
        if ($ctx['method_code'] === '') {
            $ctx['method_code'] = 'QRIS';
        }
        if ($ctx['channel_name'] === '') {
            $ctx['channel_name'] = 'ViaQRIS';
        }
        $ctx['bank_code'] = 'QRIS';
        $ctx['auto_poll'] = true;
    }
}

if (!function_exists('pg_parse_tripay')) {
    function pg_parse_tripay($unpaid, $req_json, $config, &$ctx)
    {
        $tp = (is_array($req_json) && isset($req_json['data']) && is_array($req_json['data']))
            ? $req_json['data']
            : (is_array($req_json) ? $req_json : []);

        if (!empty($tp['amount']) && is_numeric($tp['amount'])) {
            $ctx['total_amount'] = (float) $tp['amount'];
        }
        if (!empty($tp['payment_method'])) {
            $ctx['method_code'] = trim((string) $tp['payment_method']);
        }
        if (!empty($tp['payment_name'])) {
            $ctx['channel_name'] = trim((string) $tp['payment_name']);
        }
        if (!empty($tp['pay_code'])) {
            $ctx['pay_code'] = trim((string) $tp['pay_code']);
        }

        $pay_url     = !empty($tp['pay_url']) ? trim((string) $tp['pay_url']) : '';
        $tp_checkout = !empty($tp['checkout_url']) ? trim((string) $tp['checkout_url']) : '';
        $ext_url     = $pay_url !== '' ? $pay_url : $tp_checkout;
        if ($ext_url === '' && !pg_is_internal_nux_url($ctx['checkout_url'])) {
            $ext_url = $ctx['checkout_url'];
        }

        if (!empty($tp['instructions']) && is_array($tp['instructions'])) {
            foreach ($tp['instructions'] as $ins) {
                if (!empty($ins['title']) && !empty($ins['steps']) && is_array($ins['steps'])) {
                    $clean_steps = array_map(function ($s) {
                        return trim(strip_tags((string) $s));
                    }, $ins['steps']);
                    $ctx['instructions'][] = [
                        'title' => trim(strip_tags((string) $ins['title'])),
                        'steps' => array_values(array_filter($clean_steps))
                    ];
                }
            }
        }

        $m_up   = strtoupper($ctx['method_code']);
        $qr_str = trim((string) ($tp['qr_string'] ?? ''));
        $qr_url = trim((string) ($tp['qr_url'] ?? ''));

        if ((strpos($m_up, 'QRIS') !== false || $qr_str !== '' || $qr_url !== '') && ($qr_str !== '' || $qr_url !== '')) {
            $ctx['ui_mode']      = 'qris';
            $ctx['qris_string']  = $qr_str;
            $ctx['qris_image']   = $qr_url;
            if ($qr_str !== '' && $qr_url === '') {
                $ctx['qris_image'] = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&margin=20&data=' . urlencode($qr_str);
            }
            $ctx['checkout_url'] = $ext_url;
            if (empty($ctx['instructions'])) {
                $ctx['instructions'] = pg_default_qris_instructions();
            }
        } elseif ($ctx['pay_code'] !== '') {
            $ctx['ui_mode']      = 'va_code';
            $ctx['checkout_url'] = $ext_url;
        } else {
            $ctx['ui_mode']      = 'ewallet_link';
            $ctx['checkout_url'] = $ext_url;
        }

        $ctx['bank_code'] = pg_detect_bank_code('tripay', $ctx['method_code'], $ctx['channel_name']);
        // Tripay Proxy tidak memproses webhook otomatis -> auto-polling wajib aktif
        $ctx['auto_poll'] = true;
    }
}

if (!function_exists('pg_parse_duitku')) {
    function pg_parse_duitku($unpaid, $req_json, $config, &$ctx)
    {
        $dk = is_array($req_json) ? $req_json : [];
        if (!empty($dk['amount']) && is_numeric($dk['amount'])) {
            $ctx['total_amount'] = (float) $dk['amount'];
        }
        if (!empty($dk['vaNumber'])) {
            $ctx['pay_code'] = trim((string) $dk['vaNumber']);
        }
        $dk_url = !empty($dk['paymentUrl']) ? trim((string) $dk['paymentUrl']) : $ctx['checkout_url'];
        $m_up   = strtoupper($ctx['method_code']);
        $qr_str = trim((string) ($dk['qrString'] ?? ''));

        if ((in_array($m_up, ['SP', 'LQ', 'NQ', 'GQ'], true) || $qr_str !== '') && $qr_str !== '') {
            $ctx['ui_mode']      = 'qris';
            $ctx['qris_string']  = $qr_str;
            $ctx['qris_image']   = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&margin=20&data=' . urlencode($qr_str);
            $ctx['checkout_url'] = $dk_url;
            if (empty($ctx['instructions'])) {
                $ctx['instructions'] = pg_default_qris_instructions();
            }
        } elseif ($ctx['pay_code'] !== '') {
            $ctx['ui_mode']      = 'va_code';
            $ctx['checkout_url'] = $dk_url;
            if (empty($ctx['instructions'])) {
                $ctx['instructions'] = [
                    [
                        'title' => 'Panduan Pembayaran Virtual Account',
                        'steps' => [
                            'Salin Nomor Virtual Account di atas',
                            'Buka aplikasi m-Banking atau ATM sesuai bank tujuan (' . ($ctx['channel_name'] ?: $m_up) . ')',
                            'Pilih menu Transfer / Pembayaran Virtual Account dan tempel nomor VA',
                            'Pastikan nominal tagihan sesuai lalu konfirmasi pembayaran',
                            'Kembali ke aplikasi ini, paket akan aktif otomatis setelah pembayaran diterima'
                        ]
                    ]
                ];
            }
        } else {
            $ctx['ui_mode']      = 'ewallet_link';
            $ctx['checkout_url'] = $dk_url;
            if (empty($ctx['instructions'])) {
                $ctx['instructions'] = [
                    [
                        'title' => 'Panduan Pembayaran Online',
                        'steps' => [
                            'Ketuk tombol Lanjutkan Pembayaran di bawah ini',
                            'Selesaikan otorisasi pembayaran pada aplikasi/halaman tujuan',
                            'Setelah selesai, kembali ke aplikasi ini agar status langsung diperbarui'
                        ]
                    ]
                ];
            }
        }

        $ctx['bank_code'] = pg_detect_bank_code('duitku', $ctx['method_code'], $ctx['channel_name']);
        $ctx['auto_poll'] = true;
    }
}

if (!function_exists('pg_parse_xendit')) {
    function pg_parse_xendit($unpaid, $req_json, $config, &$ctx)
    {
        $ctx['ui_mode'] = 'checkout_url';
        $xd             = is_array($req_json) ? $req_json : [];
        if (!empty($xd['amount']) && is_numeric($xd['amount'])) {
            $ctx['total_amount'] = (float) $xd['amount'];
        }
        if (!empty($xd['invoice_url'])) {
            $ctx['checkout_url'] = trim((string) $xd['invoice_url']);
        }
        if ($ctx['method_code'] === '') {
            $ctx['method_code'] = 'XENDIT';
        }
        if ($ctx['channel_name'] === '') {
            $ctx['channel_name'] = 'Xendit Invoice';
        }
        $ctx['instructions'] = [
            [
                'title' => 'Panduan Pembayaran Xendit',
                'steps' => [
                    'Ketuk tombol Bayar Sekarang untuk membuka halaman pembayaran Xendit',
                    'Pilih metode pembayaran yang tersedia (QRIS, Virtual Account, atau E-Wallet)',
                    'Selesaikan pembayaran sesuai instruksi di layar Xendit',
                    'Kembali ke aplikasi ini, status transaksi akan diperiksa otomatis'
                ]
            ]
        ];
        $ctx['auto_poll'] = true;
    }
}

if (!function_exists('pg_parse_paypal')) {
    function pg_parse_paypal($unpaid, $req_json, $config, &$ctx)
    {
        $ctx['ui_mode']  = 'checkout_url';
        $ctx['currency'] = !empty($config['paypal_currency']) ? strtoupper(trim((string) $config['paypal_currency'])) : 'USD';
        if ($ctx['checkout_url'] === '' && is_array($req_json) && !empty($req_json['links']) && is_array($req_json['links'])) {
            foreach ($req_json['links'] as $lnk) {
                if (($lnk['rel'] ?? '') === 'approve' && !empty($lnk['href'])) {
                    $ctx['checkout_url'] = trim((string) $lnk['href']);
                    break;
                }
            }
        }
        if ($ctx['method_code'] === '') {
            $ctx['method_code'] = 'PAYPAL';
        }
        if ($ctx['channel_name'] === '') {
            $ctx['channel_name'] = 'PayPal (' . $ctx['currency'] . ')';
        }
        $ctx['bank_code'] = 'PAYPAL';
        $ctx['instructions'] = [
            [
                'title' => 'Panduan Pembayaran PayPal',
                'steps' => [
                    'Ketuk tombol Bayar dengan PayPal untuk menyetujui pembayaran',
                    'Login ke akun PayPal Anda dan klik Approve / Pay Now',
                    'Setelah disetujui, kembali ke aplikasi ini agar sistem otomatis menangkap (capture) pembayaran Anda'
                ]
            ]
        ];
        // PayPal belum memiliki webhook -> wajib check_payment untuk memicu capture
        $ctx['auto_poll'] = true;
    }
}

if (!function_exists('parse_qris_trx')) {
    function parse_qris_trx($unpaid, $config)
    {
        if (!$unpaid) {
            return null;
        }
        check_and_expire_trx($unpaid);

        $gw_raw   = trim((string) ($unpaid['gateway'] ?? ''));
        $gw_lower = strtolower($gw_raw);
        $req_json = !empty($unpaid['pg_request']) ? json_decode((string) $unpaid['pg_request'], true) : null;

        $ctx = [
            'total_amount' => (float) $unpaid['price'],
            'qris_string'  => '',
            'qris_image'   => '',
            'pay_code'     => '',
            'checkout_url' => trim((string) ($unpaid['pg_url_payment'] ?? '')),
            'instructions' => [],
            'method_code'  => trim((string) ($unpaid['payment_method'] ?? '')),
            'channel_name' => trim((string) ($unpaid['payment_channel'] ?? '')),
            'currency'     => $config['currency_code'] ?? 'Rp',
            'ui_mode'      => 'checkout_url',
            'bank_code'    => '',
            'auto_poll'    => true
        ];

        if ($gw_lower === 'viaqris') {
            pg_parse_viaqris($unpaid, $req_json, $config, $ctx);
        } elseif ($gw_lower === 'tripay') {
            pg_parse_tripay($unpaid, $req_json, $config, $ctx);
        } elseif ($gw_lower === 'duitku') {
            pg_parse_duitku($unpaid, $req_json, $config, $ctx);
        } elseif ($gw_lower === 'xendit') {
            pg_parse_xendit($unpaid, $req_json, $config, $ctx);
        } elseif ($gw_lower === 'paypal') {
            pg_parse_paypal($unpaid, $req_json, $config, $ctx);
        } else {
            $ctx['ui_mode'] = 'checkout_url';
            if ($ctx['method_code'] === '') {
                $ctx['method_code'] = strtoupper($gw_raw);
            }
            if ($ctx['channel_name'] === '') {
                $ctx['channel_name'] = strtoupper($gw_raw);
            }
        }

        $st       = (int) $unpaid['status'];
        $st_label = ($st === 2) ? 'LUNAS' : (($st === 3) ? 'EXPIRED' : (($st === 4) ? 'DIBATALKAN' : 'MENUNGGU PEMBAYARAN'));

        return [
            'id'              => (int) $unpaid['id'],
            'username'        => $unpaid['username'],
            'plan_id'         => (int) $unpaid['plan_id'],
            'plan_name'       => $unpaid['plan_name'],
            'price'           => $ctx['total_amount'],
            'base_price'      => (float) $unpaid['price'],
            'currency'        => $ctx['currency'],
            'gateway'         => $gw_raw,
            'gateway_trx_id'  => $unpaid['gateway_trx_id'] ?? '',
            'ui_mode'         => $ctx['ui_mode'],
            'bank_code'       => $ctx['bank_code'],
            'auto_poll'       => (bool) $ctx['auto_poll'],
            'payment_method'  => $ctx['method_code'] ?: strtoupper($gw_raw),
            'payment_channel' => $ctx['channel_name'] ?: strtoupper($gw_raw),
            'pg_url_payment'  => $ctx['checkout_url'],
            'checkout_url'    => $ctx['checkout_url'],
            'pay_code'        => $ctx['pay_code'],
            'qris_string'     => $ctx['qris_string'],
            'qris_image'      => $ctx['qris_image'],
            'instructions'    => $ctx['instructions'],
            'status'          => $st,
            'status_label'    => $st_label,
            'expired_date'    => $unpaid['expired_date'] ?: '-',
            'created_date'    => $unpaid['created_date'] ?: '-',
            'paid_date'       => $unpaid['paid_date'] ?: '-',
            'trx_invoice'     => $unpaid['trx_invoice'] ?: ('INV-' . $unpaid['id'])
        ];
    }
}
