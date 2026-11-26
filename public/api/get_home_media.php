<?php
// App home screen pictures, as set in the backend (App home screen): ?type=hero|ad&id=<country 1|2>
require __DIR__ . '/_db.php';
$country = (int) ($_GET['id'] ?? 1) === 2 ? 2 : 1;
$hero = ($_GET['type'] ?? '') !== 'ad';
try {
    $st = $hero
        ? api_db()->prepare('SELECT header_image AS b, header_image_type AS t FROM home_hero WHERE country = :c AND active = 1 AND header_image IS NOT NULL ORDER BY id LIMIT 1')
        : api_db()->prepare('SELECT ad_image AS b, ad_image_type AS t FROM home_advertisement WHERE country = :c AND active = 1 AND ad_image IS NOT NULL ORDER BY sort_order, id LIMIT 1');
    $st->execute(['c' => $country]);
    $r = $st->fetch();
} catch (Throwable $e) {
    $r = null;
}
if (!$r || !$r['b']) {
    http_response_code(404);
    exit;
}
$type = in_array($r['t'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) ? $r['t'] : 'image/jpeg';
header('Content-Type: ' . $type);
header('Cache-Control: public, max-age=300');
header('Access-Control-Allow-Origin: *');
echo $r['b'];
