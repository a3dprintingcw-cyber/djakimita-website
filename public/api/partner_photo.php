<?php
// A partner's photo for the app: the first photo from the backend (same as the website),
// else the old main picture. ?id=<company id>&s=s|m|l
require __DIR__ . '/_db.php';
$id = (int) ($_GET['id'] ?? 0);
$s = in_array($_GET['s'] ?? 'm', ['s', 'm', 'l'], true) ? $_GET['s'] : 'm';
$to = '/api/get_image.php?id=' . $id; // old picture (or the old placeholder)
try {
    $st = api_db()->prepare('SELECT i.id FROM voucher_company_images i JOIN voucher_company v ON v.id = i.company_id
                             WHERE i.company_id = :c AND v.active = 1 ORDER BY i.sort_order, i.id LIMIT 1');
    $st->execute(['c' => $id]);
    if ($img = (int) $st->fetchColumn()) {
        $to = '/img.php?i=' . $img . '&s=' . $s;
    } else {
        $st = api_db()->prepare('SELECT (picture IS NOT NULL AND LENGTH(picture) > 0) FROM voucher_company WHERE id = :c');
        $st->execute(['c' => $id]);
        if ((int) $st->fetchColumn()) {
            $to = '/img.php?c=' . $id . '&s=' . $s;
        }
    }
} catch (Throwable $e) {
}
header('Cache-Control: public, max-age=600');
header('Location: ' . $to, true, 302);
