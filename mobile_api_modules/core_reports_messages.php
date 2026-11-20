<?php

/**
 * PHPNuxBill Mobile API Plugin - Financial Reports, Broadcast Messages & Static Pages Module
 * Path: system/plugin/mobile_api_modules/core_reports_messages.php
 **/

global $config, $_c, $admin, $ui, $UPLOAD_PATH, $PAGES_PATH, $phpnux_root, $_app_stage, $api_secret;

switch ($action) {
    // =========================================================================
    // 1. LAPORAN KEUANGAN & RINCIAN PER PAYMENT GATEWAY (SELARAS reports.php)
    // =========================================================================
    case 'admin_reports':
        require_admin_session($api_secret);

        $reset_day = (int) ($config['reset_day'] ?? 1);
        if ($reset_day < 1 || $reset_day > 28) {
            $reset_day = 1;
        }
        $reset_day_pad     = str_pad((string) $reset_day, 2, '0', STR_PAD_LEFT);
        $default_start_day = ((int) date('d') >= $reset_day)
            ? date('Y-m-' . $reset_day_pad)
            : date('Y-m-' . $reset_day_pad, strtotime('-1 MONTH'));

        $start_date  = trim((string) (_req('start_date') ?: _req('sd') ?: $default_start_day));
        $end_date    = trim((string) (_req('end_date') ?: _req('ed') ?: date('Y-m-d')));
        $search      = trim((string) _req('search'));
        $gw_filter   = trim((string) (_req('gateway') ?: _req('method')));
        $type_filter = trim((string) _req('type'));
        $rtr_filter  = trim((string) (_req('router') ?: _req('routers')));
        $limit       = max(20, min(300, (int) (_req('limit') ?: 150)));

        $base_q = ORM::for_table('tbl_transactions')
            ->where_gte('recharged_on', $start_date)
            ->where_lte('recharged_on', $end_date)
            ->where_not_equal('method', 'Customer - Balance')
            ->where_not_like('method', 'Recharge Balance%');

        if ($type_filter !== '' && strtolower($type_filter) !== 'all') {
            $base_q->where('type', $type_filter);
        }
        if ($rtr_filter !== '' && strtolower($rtr_filter) !== 'all') {
            $base_q->where('routers', $rtr_filter);
        }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $base_q->where_raw(
                '(username LIKE ? OR invoice LIKE ? OR plan_name LIKE ? OR method LIKE ?)',
                [$like, $like, $like, $like]
            );
        }

        // Hitung rekap pendapatan per Metode / Payment Gateway pada periode tersebut
        $all_period_rows = (clone $base_q)->find_array();
        $breakdown_map   = [];
        foreach ($all_period_rows as $tr) {
            $raw_m   = trim((string) ($tr['method'] ?? 'Lainnya'));
            $parts   = explode('-', $raw_m, 2);
            $gw_name = strtoupper(trim($parts[0] ?: 'LAINNYA'));
            if (!isset($breakdown_map[$gw_name])) {
                $breakdown_map[$gw_name] = ['gateway' => $gw_name, 'count' => 0, 'total' => 0.0];
            }
            $breakdown_map[$gw_name]['count']++;
            $breakdown_map[$gw_name]['total'] += (float) $tr['price'];
        }
        $gateway_breakdown = array_values($breakdown_map);
        usort($gateway_breakdown, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        if ($gw_filter !== '' && strtolower($gw_filter) !== 'all') {
            $base_q->where_like('method', $gw_filter . '%');
        }

        $total_income = (clone $base_q)->sum('price') ?: 0;
        $total_count  = (clone $base_q)->count();
        $rows         = $base_q->order_by_desc('id')->limit($limit)->find_array();

        api_out(true, 'Laporan keuangan berhasil dimuat', [
            'period' => [
                'start_date' => $start_date,
                'end_date'   => $end_date
            ],
            'summary' => [
                'total_transactions' => (int) $total_count,
                'total_income'       => (float) $total_income
            ],
            'gateway_breakdown' => $gateway_breakdown,
            'active_gateways'   => get_active_gateways($config),
            'transactions'      => $rows
        ]);
        break;

    // =========================================================================
    // 2. KIRIM PESAN PERSONAL & BROADCAST MASSAL (SELARAS message.php)
    // =========================================================================
    case 'admin_send_message':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin', 'Agent', 'Sales']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }
        @set_time_limit(0);

        $mode        = strtolower(trim((string) (_post('mode') ?: 'single'))); // single | broadcast
        $customer_id = (int) (_post('customer_id') ?: _post('id_customer'));
        $group       = strtolower(trim((string) (_post('group') ?: 'all'))); // all | active | expired | hotspot | pppoe
        $via         = strtolower(trim((string) (_post('via') ?: 'wa'))); // wa | sms | inbox | email | both | all
        $message_raw = trim((string) _post('message'));
        $comp_name   = $config['CompanyName'] ?? 'PHPNuxBill';
        $subject_raw = trim((string) (_post('subject') ?: ('Informasi Layanan ' . $comp_name)));

        if ($message_raw === '') {
            api_out(false, class_exists('Lang') ? Lang::T('Message is required') : 'Isi pesan tidak boleh kosong.', null, 400);
        }
        if ($via === 'wa' && empty($config['wa_url'])) {
            api_out(false, 'WhatsApp Gateway URL (wa_url) belum dikonfigurasi di Pengaturan Sistem.', null, 400);
        }
        if ($via === 'sms' && empty($config['sms_url'])) {
            api_out(false, 'SMS Gateway URL (sms_url) belum dikonfigurasi di Pengaturan Sistem.', null, 400);
        }

        if (function_exists('run_hook')) {
            try {
                @run_hook($mode === 'single' ? 'send_message_single' : 'send_message_bulk');
            } catch (Throwable $e) {
            }
        }

        $targets = [];
        if ($mode === 'single') {
            $c = ORM::for_table('tbl_customers')->find_one($customer_id);
            if (!$c && !empty(_post('username'))) {
                $c = ORM::for_table('tbl_customers')->where('username', trim((string) _post('username')))->find_one();
            }
            if (!$c) {
                api_out(false, class_exists('Lang') ? Lang::T('Customer not found') : 'Pilih pelanggan tujuan terlebih dahulu.', null, 404);
            }
            $targets[] = $c->as_array();
        } else {
            $q = ORM::for_table('tbl_customers');
            if ($group === 'hotspot') {
                $q->where('service_type', 'Hotspot');
            } elseif ($group === 'pppoe') {
                $q->where('service_type', 'PPPoE');
            } elseif ($group === 'active' || $group === 'expired') {
                $st   = ($group === 'active') ? 'on' : 'off';
                $urs  = ORM::for_table('tbl_user_recharges')->where('status', $st)->find_array();
                $cids = array_values(array_unique(array_column($urs, 'customer_id')));
                if (!empty($cids)) {
                    $q->where_in('id', $cids);
                } else {
                    api_out(false, 'Tidak ada pelanggan dalam kategori ' . $group, null, 404);
                }
            }
            $targets = $q->limit(500)->find_array();
        }

        // Lepaskan lock sesi PHP agar akses web admin tidak tertahan selama pengiriman massal
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        $clean_root_url = defined('APP_URL') ? preg_replace('#/system/?$#i', '', rtrim((string) APP_URL, '/')) : '';
        $has_pay_link   = (strpos($message_raw, '[[payment_link]]') !== false);
        $sent_count     = 0;

        foreach ($targets as $cust) {
            $bal_num = (float) ($cust['balance'] ?? 0);
            $bal_fmt = (class_exists('Lang') && method_exists('Lang', 'moneyFormat'))
                ? Lang::moneyFormat($bal_num)
                : (($config['currency_code'] ?? 'Rp.') . ' ' . number_format($bal_num, 0, ',', '.'));

            $raw_phone = trim((string) ($cust['phonenumber'] ?? ''));
            $fmt_phone = ($raw_phone !== '' && class_exists('Lang') && method_exists('Lang', 'phoneFormat'))
                ? Lang::phoneFormat($raw_phone)
                : $raw_phone;

            $search_tags = [
                '[[name]]',
                '[[user_name]]',
                '[[username]]',
                '[[phone]]',
                '[[email]]',
                '[[address]]',
                '[[company_name]]',
                '[[company]]',
                '[[balance]]',
                '[[current_balance]]'
            ];
            $replace_vals = [
                $cust['fullname'] ?: $cust['username'],
                $cust['username'],
                $cust['username'],
                $raw_phone,
                $cust['email'] ?? '',
                $cust['address'] ?? '',
                $comp_name,
                $comp_name,
                $bal_fmt,
                $bal_fmt
            ];

            $msg     = str_replace($search_tags, $replace_vals, $message_raw);
            $subject = str_replace($search_tags, $replace_vals, $subject_raw);

            if ($has_pay_link) {
                $pay_url = '';
                if (class_exists('User') && method_exists('User', 'generateToken')) {
                    try {
                        $tok_data = User::generateToken($cust['id'], 1);
                        $tur      = ORM::for_table('tbl_user_recharges')->where('customer_id', $cust['id'])->order_by_desc('id')->find_one();
                        if (!empty($tok_data['token']) && $tur) {
                            $prefix_url = ($clean_root_url !== '') ? ($clean_root_url . '/') : '';
                            $pay_url    = $prefix_url . '?_route=home&recharge=' . $tur['id'] . '&uid=' . urlencode($tok_data['token']);
                        }
                    } catch (Throwable $e) {
                    }
                }
                $msg = str_replace('[[payment_link]]', $pay_url, $msg);
            }

            $ok = false;
            if (($via === 'wa' || $via === 'both' || $via === 'all') && $fmt_phone !== '' && !empty($config['wa_url'])) {
                notify_whatsapp_customer($fmt_phone, $msg, false);
                $ok = true;
            }
            if (($via === 'sms' || $via === 'all' || ($via === 'both' && !empty($config['sms_url']) && empty($config['wa_url']))) && $fmt_phone !== '' && !empty($config['sms_url'])) {
                notify_sms_customer($fmt_phone, $msg, false);
                $ok = true;
            }
            if (($via === 'email' || $via === 'all') && !empty($cust['email']) && class_exists('Message') && method_exists('Message', 'sendEmail')) {
                try {
                    Message::sendEmail($cust['email'], $subject, $msg, $cust['email']);
                    $ok = true;
                } catch (Throwable $e) {
                }
            }
            if ($via === 'inbox' || $via === 'both' || $via === 'all') {
                if (class_exists('Message') && method_exists('Message', 'addToInbox')) {
                    try {
                        Message::addToInbox($cust['id'], $subject, $msg, 'Admin');
                        $ok = true;
                    } catch (Throwable $e) {
                    }
                } else {
                    try {
                        $inb               = ORM::for_table('tbl_customers_inbox')->create();
                        $inb->customer_id  = $cust['id'];
                        $inb->date_created = date('Y-m-d H:i:s');
                        $inb->subject      = $subject;
                        $inb->body         = nl2br($msg);
                        $inb->from         = 'Admin';
                        $inb->save();
                        $ok = true;
                    } catch (Throwable $e) {
                    }
                }
            }

            if ($ok) {
                $sent_count++;
            }
        }

        write_admin_log($admin, 'Mengirim pesan (' . strtoupper($via) . ') ke ' . $sent_count . ' pelanggan');
        api_out(true, 'Pesan berhasil dikirim ke ' . $sent_count . ' pelanggan.', [
            'sent_count'   => $sent_count,
            'target_total' => count($targets)
        ]);
        break;

    // =========================================================================
    // 3. HALAMAN STATIS & TEMPLATE VOUCHER HTML (SELARAS pages.php)
    // =========================================================================
    case 'pages':
    case 'admin_pages':
        require_admin_session($api_secret);
        $root_dir  = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $rel_pages = !empty($PAGES_PATH) ? $PAGES_PATH : 'pages';
        $pages_dir = is_dir($root_dir . '/' . ltrim($rel_pages, '/\\')) ? ($root_dir . '/' . ltrim($rel_pages, '/\\')) : $rel_pages;
        $tpl_dir   = $root_dir . '/pages_template';

        $page_files = [];
        foreach (glob($pages_dir . '/*.html') ?: [] as $f) {
            $page_files[basename($f, '.html')] = $f;
        }
        foreach (glob($tpl_dir . '/*.html') ?: [] as $tf) {
            $b = basename($tf, '.html');
            if (!isset($page_files[$b])) {
                $page_files[$b] = $tf;
            }
        }

        $list = [];
        foreach ($page_files as $name => $fPath) {
            $list[] = [
                'name'    => $name,
                'title'   => str_replace('_', ' ', $name),
                'content' => @file_get_contents($fPath) ?: ''
            ];
        }
        api_out(true, 'Halaman statis dimuat', $list);
        break;

    case 'page_save':
    case 'admin_page_save':
        $admin = require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        if (strtolower((string) ($_app_stage ?? '')) === 'demo') {
            api_out(false, 'You cannot perform this action in Demo mode', null, 403);
        }

        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) _post('name'));
        if ($name === '') {
            api_out(false, 'Nama halaman tidak valid.', null, 400);
        }
        $root_dir  = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $rel_pages = !empty($PAGES_PATH) ? $PAGES_PATH : 'pages';
        $pages_dir = is_dir($root_dir . '/' . ltrim($rel_pages, '/\\')) ? ($root_dir . '/' . ltrim($rel_pages, '/\\')) : $rel_pages;
        if (!is_dir($pages_dir)) {
            @mkdir($pages_dir, 0755, true);
        }

        if (function_exists('run_hook')) {
            try {
                @run_hook('save_pages');
            } catch (Throwable $e) {
            }
        }

        if (@file_put_contents($pages_dir . '/' . $name . '.html', (string) _post('content')) !== false) {
            write_admin_log($admin, 'Menyimpan halaman statis: ' . $name);
            api_out(true, 'Halaman ' . str_replace('_', ' ', $name) . ' berhasil disimpan.');
        }
        api_out(false, 'Gagal menulis ke folder pages/. Pastikan permission writable.', null, 500);
        break;
}
