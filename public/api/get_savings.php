<?php
// "My savings" in the app: what an account saved at partners, from the registered visits.
// ?id=<account id as stored by the app>&country=1|2. Saved per visit = normal price - account price.
require __DIR__ . '/_db.php';
$id = (int) ($_GET['id'] ?? 0);
$country = (int) ($_GET['country'] ?? 1) === 2 ? 2 : 1;
$empty = ['total' => 0, 'this_year' => 0, 'visits' => 0, 'partners' => 0, 'by_month' => [], 'top' => []];
if ($id <= 0) {
    api_json($empty);
}
try {
    $st = api_db()->prepare("
        SELECT i.date, i.amount, v.id AS cid, TRIM(v.name) AS name,
               COALESCE((SELECT GREATEST(d.was - d.after, 0) FROM voucher_company_info_deals d
                         WHERE d.company_id = i.company_id AND d.voucher_id = p.package_id ORDER BY d.id LIMIT 1), 0) AS saved
        FROM client_package_info i
        JOIN clients_package p ON p.id = i.users_package_id
        JOIN voucher_company v ON v.id = i.company_id
        WHERE i.users_package_id = :a AND v.country = :c");
    $st->execute(['a' => $id, 'c' => $country]);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    api_json($empty);
}
$total = 0; $year = 0; $visits = 0; $months = []; $top = [];
$y = date('Y');
foreach ($rows as $r) {
    $n = max(1, (int) $r['amount']);
    $s = (float) $r['saved'] * $n;
    $total += $s; $visits += $n;
    if (substr((string) $r['date'], 0, 4) === $y) {
        $year += $s;
    }
    $m = substr((string) $r['date'], 0, 7);
    $months[$m] = ($months[$m] ?? 0) + $s;
    $top[$r['cid']] = ['name' => $r['name'], 'saved' => ($top[$r['cid']]['saved'] ?? 0) + $s, 'visits' => ($top[$r['cid']]['visits'] ?? 0) + $n];
}
ksort($months);
$by = [];
foreach (array_slice($months, -6, null, true) as $m => $s) {
    $by[] = ['month' => $m, 'saved' => round($s, 2)];
}
usort($top, fn ($a, $b) => $b['saved'] <=> $a['saved']);
api_json(['total' => round($total, 2), 'this_year' => round($year, 2), 'visits' => $visits, 'partners' => count($top),
    'by_month' => $by, 'top' => array_map(fn ($t) => ['name' => $t['name'], 'saved' => round($t['saved'], 2), 'visits' => $t['visits']], array_slice($top, 0, 5))]);
