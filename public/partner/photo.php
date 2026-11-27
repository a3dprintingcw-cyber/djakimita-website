<?php
// Selfie from the approved account check, shown to a logged-in partner only while they look at
// that account (v = check id, a = account). Nothing else can be opened with this.
require __DIR__ . '/../../app/bootstrap.php';
$u = pp_user();
$vid = (int) ($_GET['v'] ?? 0);
$acc = (int) ($_GET['a'] ?? 0);
$a = ($u && pp_may_see($acc, (int) $u['company_id'])) ? pp_lookup($acc, (int) $u['company_id']) : null;
$ok = false;
foreach (($a['holders'] ?? []) as $h) {
    if ((int) $h['photo'] === $vid && $vid > 0) {
        $ok = true;
    }
}
$st = db()->prepare('SELECT face_file FROM holder_verifications WHERE id = :id AND status = :s');
$st->execute(['id' => $vid, 's' => VERIFY_APPROVED]);
$f = (string) $st->fetchColumn();
if (!$ok || !preg_match('/^[a-f0-9]{32}\.jpg$/', $f) || !is_file(verify_dir() . '/' . $f)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex');
readfile(verify_dir() . '/' . $f);
