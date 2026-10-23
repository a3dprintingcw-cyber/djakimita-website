<?php
// Serves partner photos from the database, resized and cached on disk.
// First request per photo/size reads the database; after that it's a plain file.
require __DIR__ . '/../app/bootstrap.php';

$sizes = ['s' => 360, 'm' => 720, 'l' => 1400];
$size = $_GET['s'] ?? 'm';
$width = $sizes[$size] ?? $sizes['m'];
$imageId = isset($_GET['i']) ? (int) $_GET['i'] : 0;
$companyId = isset($_GET['c']) ? (int) $_GET['c'] : 0;

if ($imageId <= 0 && $companyId <= 0) {
    http_response_code(404);
    exit;
}

$cacheDir = __DIR__ . '/../app/cache/img';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cacheFile = sprintf('%s/%s%d-%s.jpg', $cacheDir, $imageId ? 'i' : 'c', $imageId ?: $companyId, $size);

if (!is_file($cacheFile)) {
    if ($imageId) {
        $st = db()->prepare('SELECT i.image_blob AS data FROM voucher_company_images i
                             JOIN voucher_company v ON v.id = i.company_id AND v.active = 1
                             WHERE i.id = :id');
        $st->execute(['id' => $imageId]);
    } else {
        $st = db()->prepare('SELECT picture AS data FROM voucher_company WHERE id = :id AND active = 1');
        $st->execute(['id' => $companyId]);
    }
    $data = $st->fetchColumn();
    $src = $data ? @imagecreatefromstring($data) : false;
    if (!$src) {
        http_response_code(404);
        exit;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w > $width) {
        $nh = (int) round($h * $width / $w);
        $dst = imagecreatetruecolor($width, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $nh, $w, $h);
    } else {
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
    }
    imagejpeg($dst, $cacheFile . '.tmp', 80);
    rename($cacheFile . '.tmp', $cacheFile);
}

$etag = '"' . md5_file($cacheFile) . '"';
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=2592000');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($cacheFile));
readfile($cacheFile);
