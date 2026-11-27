<?php
// Rewards customers can get with points: ?country=1|2
require __DIR__ . '/_auth.php';
$db = api_db();
if (!pts_on($db)) {
    api_json([]);
}
$country = (int) ($_GET['country'] ?? 1) === 2 ? 2 : 1;
try {
    $st = $db->prepare('SELECT r.id, r.title, r.description, r.points, r.icon, r.stock, r.partner_id, TRIM(v.name) AS partner
                        FROM rewards r LEFT JOIN voucher_company v ON v.id = r.partner_id
                        WHERE r.active = 1 AND r.country = :c AND (r.stock IS NULL OR r.stock > 0) ORDER BY r.sort, r.points, r.id');
    $st->execute(['c' => $country]);
    api_json(array_map(fn ($r) => [
        'id' => (int) $r['id'], 'title' => $r['title'], 'description' => $r['description'], 'points' => (int) $r['points'],
        'icon' => $r['icon'] ?: 'gift', 'partner' => $r['partner'] ?: "Dj'aki mi ta!", 'partner_id' => $r['partner_id'] ? (int) $r['partner_id'] : null,
        'country' => $country, 'left' => $r['stock'] !== null ? (int) $r['stock'] : null,
    ], $st->fetchAll()));
} catch (Throwable $e) {
    api_json([]);
}
