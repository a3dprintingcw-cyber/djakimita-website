<?php
// Budget planner in the app: deals with a normal price and an account price. ?country=1|2
// One line per partner (the Adult price when there is one), grouped by the partner's category.
require __DIR__ . '/_db.php';
$country = (int) ($_GET['country'] ?? 1) === 2 ? 2 : 1;
try {
    $rows = api_db()->query("
        SELECT v.id AS company_id, TRIM(v.name) AS name, TRIM(COALESCE(c.name, '')) AS category,
               COALESCE(px.deal_text, '') AS deal_text, d.voucher_id, d.was, d.after, d.the_percent
        FROM voucher_company v
        JOIN voucher_company_info_deals d ON d.company_id = v.id
        LEFT JOIN category c ON c.id = v.category_id
        LEFT JOIN partner_extra px ON px.company_id = v.id
        WHERE v.active = 1 AND v.country = $country AND d.was > 0 AND d.after < d.was
        ORDER BY v.id, (d.voucher_id = 3) DESC, d.voucher_id")->fetchAll();
} catch (Throwable $e) {
    api_json([]);
}
$out = [];
$seen = [];
foreach ($rows as $r) {
    if (isset($seen[$r['company_id']])) {
        continue;
    }
    $seen[$r['company_id']] = true;
    $pct = (int) round((float) $r['the_percent']);
    $out[] = [
        'company_id' => (int) $r['company_id'],
        'name' => $r['name'],
        'category' => $r['category'] !== '' ? $r['category'] : 'Other',
        'deal' => $r['deal_text'] !== '' ? $r['deal_text'] : ($pct >= 100 ? 'Free with your account' : $pct . '% off with your account'),
        'was' => round((float) $r['was'], 2),
        'after' => round((float) $r['after'], 2),
    ];
}
api_json($out);
