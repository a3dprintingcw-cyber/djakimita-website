<?php
// Partner portal: this partner's own QR code that customers scan in the app (same code as before:
// the partner number). ?download=1 gives the PNG, ?poster=1 a page to print for the counter.
require __DIR__ . '/../../app/bootstrap.php';
$u = pp_require();
$id = (int) $u['company_id'];
$name = (string) $u['company'];

if (!empty($_GET['poster'])) {
    ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>QR · <?= e($name) ?></title>
<style>
  @page { size: A5; margin: 12mm; }
  body { font-family: Arial, Helvetica, sans-serif; text-align: center; color: #0f3d14; margin: 0; padding: 24px; }
  .logo { width: 220px; } h1 { font-size: 28px; margin: 14px 0 4px; } p { font-size: 18px; margin: 6px 0; }
  .qr { width: 300px; height: 300px; image-rendering: pixelated; margin: 16px auto; display: block; }
  .name { font-size: 22px; font-weight: bold; } .hint { color: #4f5d52; font-size: 15px; }
  button { margin-top: 20px; padding: 12px 24px; font-size: 16px; } @media print { button { display: none; } }
</style></head><body>
  <img class="logo" src="/assets/img/logo-word.png" alt="Dj'aki mi ta!">
  <h1>Scan for your discount</h1>
  <p>Open the Dj'aki mi ta! app, tap Scan and scan this code.</p>
  <img class="qr" src="/partner/qr.php" alt="QR code">
  <p class="name"><?= e($name) ?></p>
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
    $file = preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?: 'partner';
    header('Content-Disposition: attachment; filename="djakimita-qr-' . trim($file, '-') . '.png"');
}
QRcode::png((string) $id, false, 'Q', 12, 2);
