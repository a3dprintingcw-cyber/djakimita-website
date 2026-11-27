<?php
// Checks a discount code for the checkout summary. Says only whether it works and how much it gives.
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
usleep(250000); // slow down guessing
require_once __DIR__ . '/../app/points.php';
$code = (string) ($_GET['code'] ?? '');
if (pts_on(db()) && ($inv = invite_client(db(), $code))) {
    // Invite code of a customer: no discount, both get points after paying
    $st = db()->prepare('SELECT TRIM(username) FROM clients WHERE id = :i');
    $st->execute(['i' => $inv]);
    $name = (string) $st->fetchColumn();
    echo json_encode(['ok' => true, 'percent' => 0, 'invite' => true, 'name' => $name !== '' ? $name : 'a friend',
                      'points' => (int) pts_setting(db(), 'pts_invited', PTS_DEFAULTS['pts_invited'])]);
    exit;
}
$d = discount_find($code);
echo json_encode($d ? ['ok' => true, 'percent' => min(90, (float) $d['percent'])] : ['ok' => false]);
