<?php
/**
 * PHPNuxBill Mobile API Plugin - Financial Reports, Broadcast Messages & Static Pages Module
 * Path: system/plugin/mobile_api_modules/core_reports_messages.php
 */

global $config, $_c, $admin, $ui, $UPLOAD_PATH, $phpnux_root;

switch ($action) {
    // =========================================================================
    // LAPORAN TRANSAKSI, PESAN & HALAMAN STATIS
    // =========================================================================
    case 'admin_reports':
        require_admin_session($api_secret);
        $start_date = trim(_req('start_date') ?: date('Y-m-01'));
        $end_date   = trim(_req('end_date') ?: date('Y-m-d'));
        $search     = trim(_req('search'));
        $gw_filter  = trim(_req('gateway') ?: _req('method'));
        $limit      = max(20, min(300, (int)(_req('limit') ?: 150)));

        $base_q = ORM::for_table('tbl_transactions')
            ->where_gte('recharged_on', $start_date)
            ->where_lte('recharged_on', $end_date)
            ->where_not_equal('method', 'Customer - Balance')
            ->where_not_like('method', 'Recharge Balance%');

        if ($search !== '') {
            $like = '%' . $search . '%';
            $base_q->where_raw(
                '(username LIKE ? OR invoice LIKE ? OR plan_name LIKE ? OR method LIKE ?)',
                [$like, $like, $like, $like]
            );
        }

        // Hitung rekap pendapatan per Metode / Payment Gateway pada periode tersebut
        $all_period_rows = (clone $base_q)->find_array();
        $breakdown_map = [];
        foreach ($all_period_rows as $tr) {
            $raw_m = trim((string)($tr['method'] ?? 'Lainnya'));
            $parts = explode('-', $raw_m, 2);
            $gw_name = strtoupper(trim($parts[0] ?: 'LAINNYA'));
            if (!isset($breakdown_map[$gw_name])) {
                $breakdown_map[$gw_name] = ['gateway' => $gw_name, 'count' => 0, 'total' => 0.0];
            }
            $breakdown_map[$gw_name]['count']++;
            $breakdown_map[$gw_name]['total'] += (float)$tr['price'];
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
        $rows = $base_q->order_by_desc('id')->limit($limit)->find_array();

        api_out(true, 'Laporan keuangan berhasil dimuat', [
            'summary' => [
                'total_transactions' => (int)$total_count,
                'total_income'       => (float)$total_income
            ],
            'gateway_breakdown' => $gateway_breakdown,
            'active_gateways'   => get_active_gateways($config),
            'transactions'      => $rows
        ]);
        break;

    case 'admin_send_message':
        @set_time_limit(0);
        $admin       = require_admin_session($api_secret);
        $mode        = strtolower(trim(_post('mode') ?: 'single')); // single | broadcast
        $customer_id = (int)_post('customer_id');
        $group       = strtolower(trim(_post('group') ?: 'all')); // all | active | expired | hotspot | pppoe
        $via         = strtolower(trim(_post('via') ?: 'wa')); // wa | sms | inbox | email | both
        $message_raw = trim(_post('message'));
        $comp_name   = $config['CompanyName'] ?? 'PHPNuxBill';
        $subject     = trim(_post('subject') ?: ('Informasi Layanan ' . $comp_name));

        if (empty($message_raw)) {
            api_out(false, 'Isi pesan tidak boleh kosong.', null, 400);
        }

        if (($via === 'wa' || $via === 'both') && empty($config['wa_url']) && $via === 'wa') {
            api_out(false, 'WhatsApp Gateway URL (wa_url) belum dikonfigurasi di Pengaturan Sistem.', null, 400);
        }
        if ($via === 'sms' && empty($config['sms_url'])) {
            api_out(false, 'SMS Gateway URL (sms_url) belum dikonfigurasi di Pengaturan Sistem.', null, 400);
        }

        if (function_exists('run_hook')) {
            @run_hook($mode === 'single' ? 'send_message_single' : 'send_message_bulk');
        }

        $targets = [];
        if ($mode === 'single') {
            $c = ORM::for_table('tbl_customers')->find_one($customer_id);
            if (!$c && !empty(_post('username'))) {
                $c = ORM::for_table('tbl_customers')->where('username', trim(_post('username')))->find_one();
            }
            if (!$c) {
                api_out(false, 'Pilih pelanggan tujuan terlebih dahulu.', null, 404);
            }
            $targets[] = $c->as_array();
        } else {
            $q = ORM::for_table('tbl_customers');
            if ($group === 'hotspot') {
                $q->where('service_type', 'Hotspot');
            } elseif ($group === 'pppoe') {
                $q->where('service_type', 'PPPoE');
            } elseif ($group === 'active' || $group === 'expired') {
                $st = ($group === 'active') ? 'on' : 'off';
                $urs = ORM::for_table('tbl_user_recharges')->where('status', $st)->find_array();
                $cids = array_values(array_unique(array_column($urs, 'customer_id')));
                if (!empty($cids)) {
                    $q->where_in('id', $cids);
                } else {
                    api_out(false, 'Tidak ada pelanggan dalam kategori ' . $group, null, 404);
                }
            }
            $targets = $q->limit(500)->find_array();
        }

        $sent_count = 0;
        foreach ($targets as $cust) {
            $bal_num = (float)($cust['balance'] ?? 0);
            $bal_fmt = (class_exists('Lang') && method_exists('Lang', 'moneyFormat'))
                ? Lang::moneyFormat($bal_num)
                : (($config['currency_code'] ?? 'Rp.') . ' ' . number_format($bal_num, 0, ',', '.'));

            $raw_phone = trim((string)($cust['phonenumber'] ?? ''));
            $fmt_phone = (!empty($raw_phone) && class_exists('Lang') && method_exists('Lang', 'phoneFormat'))
                ? Lang::phoneFormat($raw_phone)
                : $raw_phone;

            $msg = str_replace(
                [
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
                ],
                [
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
                ],
                $message_raw
            );

            $ok = false;
            if (($via === 'wa' || $via === 'both') && !empty($fmt_phone) && !empty($config['wa_url'])) {
                notify_whatsapp_customer($fmt_phone, $msg);
                $ok = true;
            }
            if (($via === 'sms' || ($via === 'both' && !empty($config['sms_url']) && empty($config['wa_url']))) && !empty($fmt_phone)) {
                notify_sms_customer($fmt_phone, $msg);
                $ok = true;
            }
            if ($via === 'email' && !empty($cust['email']) && class_exists('Message') && method_exists('Message', 'sendEmail')) {
                try {
                    Message::sendEmail($cust['email'], $subject, $msg, $cust['email']);
                    $ok = true;
                } catch (Exception $e) {}
            }
            if ($via === 'inbox' || $via === 'both') {
                if (class_exists('Message') && method_exists('Message', 'addToInbox')) {
                    try {
                        Message::addToInbox($cust['id'], $subject, $msg, 'Admin');
                        $ok = true;
                    } catch (Exception $e) {}
                } else {
                    try {
                        $inb = ORM::for_table('tbl_customers_inbox')->create();
                        $inb->customer_id  = $cust['id'];
                        $inb->date_created = date('Y-m-d H:i:s');
                        $inb->subject      = $subject;
                        $inb->body         = nl2br($msg);
                        $inb->from         = 'Admin';
                        $inb->save();
                        $ok = true;
                    } catch (Exception $e) {}
                }
            }
            if ($ok) {
                $sent_count++;
            }
        }

        write_admin_log($admin, 'Mengirim pesan (' . strtoupper($via) . ') ke ' . $sent_count . ' pelanggan');
        api_out(true, 'Pesan berhasil dikirim ke ' . $sent_count . ' pelanggan.');
        break;

    case 'pages':
    case 'admin_pages':
        require_admin_session($api_secret);
        $root_dir  = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $pages_dir = is_dir($root_dir . '/pages') ? ($root_dir . '/pages') : 'pages';
        $files = glob($pages_dir . '/*.html') ?: [];
        $list = [];
        foreach ($files as $f) {
            $name = basename($f, '.html');
            $list[] = [
                'name'    => $name,
                'title'   => str_replace('_', ' ', $name),
                'content' => @file_get_contents($f) ?: ''
            ];
        }
        api_out(true, 'Halaman statis dimuat', $list);
        break;

    case 'page_save':
    case 'admin_page_save':
        require_admin_session($api_secret, ['SuperAdmin', 'Admin']);
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', _post('name'));
        if (empty($name)) {
            api_out(false, 'Nama halaman tidak valid.', null, 400);
        }
        $root_dir  = !empty($phpnux_root) ? $phpnux_root : dirname(__DIR__, 3);
        $pages_dir = is_dir($root_dir . '/pages') ? ($root_dir . '/pages') : 'pages';
        if (@file_put_contents($pages_dir . '/' . $name . '.html', _post('content')) !== false) {
            api_out(true, 'Halaman ' . str_replace('_', ' ', $name) . ' berhasil disimpan.');
        }
        api_out(false, 'Gagal menulis ke folder pages/. Pastikan permission writable.', null, 500);
        break;
}
