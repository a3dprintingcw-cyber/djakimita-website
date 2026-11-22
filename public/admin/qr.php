<?php
// Staff backend: the partner's QR code (same code as the old dashboard makes: the partner number).
// ?id=…            show the QR image
// ?id=…&download=1 download it as a PNG
// ?id=…&poster=1   a page to print for the counter
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare('SELECT id, TRIM(name) name FROM voucher_company WHERE id = :id AND active = 1');
$st->execute(['id' => $id]);
$p = $st->fetch();
if (!$p) {
    http_response_code(404);
    exit('Partner not found or switched off');
}

if (!empty($_GET['poster'])) {
    ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>QR · <?= e($p['name']) ?></title>
<style>
  @page { size: A5; margin: 12mm; }
  body { font-family: Arial, Helvetica, sans-serif; text-align: center; color: #0f3d14; margin: 0; padding: 24px; }
  .logo { width: 110px; } h1 { font-size: 28px; margin: 10px 0 4px; } p { font-size: 18px; margin: 6px 0; }
  .qr { width: 300px; height: 300px; image-rendering: pixelated; margin: 16px auto; display: block; }
  .name { font-size: 22px; font-weight: bold; } .hint { color: #4f5d52; font-size: 15px; }
  button { margin-top: 20px; padding: 10px 22px; font-size: 16px; } @media print { button { display: none; } }
</style></head><body>
  <img class="logo" src="/assets/img/logo-full.png" alt="Dj'aki mi ta!">
  <h1>Scan for your discount</h1>
  <p>Open the Dj'aki mi ta! app and scan this code.</p>
  <img class="qr" src="/admin/qr.php?id=<?= (int) $p['id'] ?>" alt="QR code">
  <p class="name"><?= e($p['name']) ?></p>
  <p class="hint">Sken pa bo deskuento · Scan voor je korting</p>
  <button onclick="window.print()">Print</button>
</body></html>
    <?php
    exit;
}

$lib = public_dir() . '/Dashboard/vendors/phpqrcode/qrlib.php';
if (!is_file($lib)) {
    http_response_code(500);
    exit('QR library not found');
}
require_once $lib;
header('Content-Type: image/png');
header('Cache-Control: private, max-age=3600');
if (!empty($_GET['download'])) {
    $file = preg_replace('/[^A-Za-z0-9]+/', '-', $p['name']) ?: 'partner';
    header('Content-Disposition: attachment; filename="qr-' . trim($file, '-') . '.png"');
    admin_log('partner_qr_download', '#' . $p['id'] . ' ' . $p['name']);
}
QRcode::png((string) $p['id'], false, 'Q', 12, 2);
exit;
