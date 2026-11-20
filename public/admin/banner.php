<?php
// Staff backend: shows an app banner picture (home hero or ad) from the database.
require __DIR__ . '/../../app/bootstrap.php';
admin_page();
$kind = ($_GET['k'] ?? '') === 'ad' ? 'ad' : 'hero';
$st = db()->prepare($kind === 'ad'
    ? 'SELECT ad_image AS data, ad_image_type AS type FROM home_advertisement WHERE id = :id'
    : 'SELECT header_image AS data, header_image_type AS type FROM home_hero WHERE id = :id');
$st->execute(['id' => (int) ($_GET['id'] ?? 0)]);
$row = $st->fetch();
if (!$row || !$row['data']) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . (in_array($row['type'], ['image/jpeg', 'image/png', 'image/webp'], true) ? $row['type'] : 'image/jpeg'));
header('Cache-Control: private, max-age=60');
echo $row['data'];
