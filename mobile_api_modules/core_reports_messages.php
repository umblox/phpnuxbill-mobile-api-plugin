<?php
/**
 * PHPNuxBill Mobile API Plugin - Financial Reports, Broadcast Messages & Static Pages Module
 * Path: system/plugin/mobile_api_modules/core_reports_messages.php
 */

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
            $base_q->where_raw('(username LIKE ? OR invoice LIKE ? OR plan_name LIKE ? OR method LIKE ?)', [$like, $like, $like, $like]);
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
        usort($gateway_breakdown, function($a, $b) { return $b['total'] <=> $a['total']; });

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
        $admin = require_admin_session($api_secret);
        $mode        = _post('mode') ?: 'single';
        $customer_id = (int)_post('customer_id');
        $group       = _post('group') ?: 'all';
        $via         = _post('via') ?: 'wa';
        $message_raw = trim(_post('message'));
        $subject     = trim(_post('subject') ?: ('Informasi ' . ($config['CompanyName'] ?? 'Arneta.ID')));

        if (empty($message_raw)) {
            api_out(false, 'Isi pesan tidak boleh kosong.', null, 400);
        }

        $targets = [];
        if ($mode === 'single') {
            $c = ORM::for_table('tbl_customers')->find_one($customer_id);
            if (!$c && !empty(_post('username'))) {
                $c = ORM::for_table('tbl_customers')->where('username', trim(_post('username')))->find_one();
            }
            if (!$c) api_out(false, 'Pilih pelanggan tujuan terlebih dahulu.', null, 404);
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
                $cids = array_unique(array_column($urs, 'customer_id'));
                if (!empty($cids)) {
                    $q->where_in('id', $cids);
                } else {
                    api_out(false, 'Tidak ada pelanggan dalam kategori ' . $group, null, 404);
                }
            }
            $targets = $q->limit(300)->find_array();
        }

        $sent_count = 0;
        foreach ($targets as $cust) {
            $msg = str_replace(
                ['[[name]]', '[[user_name]]', '[[phone]]', '[[company_name]]', '[[balance]]'],
                [$cust['fullname'], $cust['username'], $cust['phonenumber'], $config['CompanyName'] ?? 'Arneta.ID', number_format((float)$cust['balance'], 0, ',', '.')],
                $message_raw
            );

            $ok = false;
            if (($via === 'wa' || $via === 'both') && !empty($cust['phonenumber'])) {
                notify_whatsapp_customer($cust['phonenumber'], $msg);
                $ok = true;
            }
            if (($via === 'sms' || $via === 'both') && !empty($cust['phonenumber'])) {
                notify_sms_customer($cust['phonenumber'], $msg);
                $ok = true;
            }
            if ($via === 'inbox' || $via === 'both') {
                if (class_exists('Message') && method_exists('Message', 'addToInbox')) {
                    @Message::addToInbox($cust['id'], $subject, $msg, 'Admin');
                    $ok = true;
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
            if ($ok) $sent_count++;
        }

        write_admin_log($admin, 'Mengirim pesan (' . strtoupper($via) . ') ke ' . $sent_count . ' pelanggan');
        api_out(true, 'Pesan berhasil dikirim ke ' . $sent_count . ' pelanggan.');
        break;

    case 'pages':
    case 'admin_pages':
        require_admin_session($api_secret);
        $pages_dir = is_dir($phpnux_root . '/pages') ? ($phpnux_root . '/pages') : 'pages';
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
        if (empty($name)) api_out(false, 'Nama halaman tidak valid.', null, 400);
        $pages_dir = is_dir($phpnux_root . '/pages') ? ($phpnux_root . '/pages') : 'pages';
        if (@file_put_contents($pages_dir . '/' . $name . '.html', _post('content')) !== false) {
            api_out(true, 'Halaman ' . str_replace('_', ' ', $name) . ' berhasil disimpan.');
        }
        api_out(false, 'Gagal menulis ke folder pages/. Pastikan permission writable.', null, 500);
        break;
}
