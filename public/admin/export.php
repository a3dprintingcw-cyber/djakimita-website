<?php
// Staff backend: download data as CSV (opens in Excel / Google Sheets). Admins only.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
if (admin_role() !== 'admin') {
    http_response_code(403);
    exit('Admins only');
}
$db = db();
$what = (string) ($_GET['what'] ?? '');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : '2000-01-01';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$range = ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];

$queries = [
    'orders' => ["SELECT s.id AS order_row, s.dia_orario AS date, s.email, s.package, l.code AS length, UPPER(COALESCE(s.currency, 'eur')) AS currency,
                    IF(s.status = 1, 'paid', 'not paid') AS status,
                    CASE WHEN s.token LIKE 'tr\\_%' THEN 'Mollie' WHEN s.token LIKE 'sentoo:%' THEN 'Sentoo' WHEN s.token LIKE 'pi\\_%' THEN 'Stripe' ELSE '' END AS paid_with,
                    d.code AS discount_code, d.amount AS discount
                 FROM sale_receits s LEFT JOIN sale_lengths sl ON sl.sale_id = s.id LEFT JOIN card_lengths l ON l.id = sl.length_id
                 LEFT JOIN sale_discounts d ON d.sale_id = s.id
                 WHERE s.dia_orario BETWEEN :f AND :t ORDER BY s.id", true],
    'customers' => ['SELECT c.id, c.username AS first_name, c.lastName AS last_name, c.email, c.phone, IF(c.active = 1, "yes", "no") AS active,
                    (SELECT MAX(p.expire) FROM clients_package p WHERE p.users_id = c.id) AS card_valid_until
                 FROM clients c ORDER BY c.id', false],
    'cards' => ['SELECT p.id, p.users_id AS customer_id, c.email, p.package_id AS card_type, p.expire AS valid_until, IF(p.expire >= CURDATE(), "active", "ended") AS status
                 FROM clients_package p LEFT JOIN clients c ON c.id = p.users_id ORDER BY p.id', false],
    'holders' => ['SELECT h.id, h.name, h.client_birthdate AS birthdate, h.order_id, s.email AS buyer_email, IF(s.status = 1, "paid", "not paid") AS order_status
                 FROM client_package_customers h LEFT JOIN sale_receits s ON s.id = h.order_id ORDER BY h.id', false],
    'scans' => ['SELECT i.date, TRIM(v.name) AS partner, p.package_id AS card_type, c.email AS customer
                 FROM client_package_info i LEFT JOIN voucher_company v ON v.id = i.company_id
                 LEFT JOIN clients_package p ON p.id = i.users_package_id LEFT JOIN clients c ON c.id = p.users_id
                 WHERE i.date BETWEEN :f AND :t ORDER BY i.date', true],
    'views' => ['SELECT h.date, TRIM(v.name) AS partner, SUM(h.view_count) AS views FROM client_view_history h LEFT JOIN voucher_company v ON v.id = h.company_id
                 WHERE h.date BETWEEN :f AND :t GROUP BY h.date, h.company_id ORDER BY h.date', true],
    'partners' => ['SELECT v.id, TRIM(v.name) AS name, TRIM(c.name) AS category, IF(v.active = 1, "on", "off") AS status, IF(v.country = 2, "Rib\'e luga!", "Dj\'aki mi ta!") AS app,
                    v.phone, v.email, v.website, v.location, (SELECT MAX(the_percent) FROM voucher_company_info_deals d WHERE d.company_id = v.id) AS best_discount_pct,
                    (SELECT COUNT(*) FROM voucher_company_images i WHERE i.company_id = v.id) AS photos
                 FROM voucher_company v LEFT JOIN category c ON c.id = v.category_id ORDER BY v.country, v.name', false],
    'freebies' => ['SELECT TRIM(v.name) AS partner, a.v_package_id AS card_type, a.v_amount_total AS freebies
                 FROM voucher_package_amount a JOIN voucher_company v ON v.id = a.v_company_id ORDER BY v.name, a.v_package_id', false],
    'visits' => ['SELECT TRIM(c.name) AS partner, v.planned_on, IF(v.status = 1, "visited", "open") AS status, v.done_at, v.done_by, v.questions, v.notes
                 FROM partner_visits v LEFT JOIN voucher_company c ON c.id = v.company_id ORDER BY v.id', false],
    'codes' => ['SELECT code, percent, valid_until, max_uses, used, IF(active = 1, "on", "off") AS status FROM discount_codes ORDER BY id', false],
    'wheel' => ['SELECT w.created_at AS date, c.email, w.label AS result, IF(w.is_prize = 1, "prize", "") AS prize, IF(w.given = 1, "given", "") AS given
                 FROM wheel_spins w LEFT JOIN clients c ON c.id = w.client_id ORDER BY w.id', false],
];
if (!isset($queries[$what])) {
    http_response_code(404);
    exit('Unknown list');
}
[$sql, $dated] = $queries[$what];
$st = $db->prepare($sql);
$st->execute($dated ? $range : []);
admin_log('export', $what . ($dated ? ' ' . $from . ' to ' . $to : ''));

$names = [1 => 'Kids', 2 => 'Teen', 3 => 'Adult', 4 => 'Family', 5 => 'Senior', 6 => 'Family+', 7 => 'Duo', 8 => 'Couple', 9 => 'Couple', 999 => 'eSIM'];
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="djakimita-' . $what . '-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the accents
$head = false;
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    foreach (['package', 'card_type'] as $k) {
        if (isset($r[$k])) {
            $r[$k] = $names[(int) $r[$k]] ?? $r[$k];
        }
    }
    if (isset($r['questions'])) {
        $q = json_decode((string) $r['questions'], true) ?: [];
        $r['questions'] = implode(' | ', array_map(fn($x) => (!empty($x['ok']) ? '[x] ' : '[ ] ') . $x['q'], $q));
    }
    if (!$head) {
        fputcsv($out, array_keys($r));
        $head = true;
    }
    // Stop spreadsheet formulas from running (cells starting with = + - @)
    fputcsv($out, array_map(fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v, $r));
}
if (!$head) {
    fputcsv($out, ['No data']);
}
fclose($out);
