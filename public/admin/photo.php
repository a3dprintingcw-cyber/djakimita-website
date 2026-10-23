<?php
// Serves verification photos to logged-in staff only. The files live outside the website folder.
require __DIR__ . '/../../app/bootstrap.php';

$f = (string) ($_GET['f'] ?? '');
if (!admin_user() || !preg_match('/^[a-f0-9]{32}\.jpg$/', $f) || !is_file(verify_dir() . '/' . $f)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex');
readfile(verify_dir() . '/' . $f);
