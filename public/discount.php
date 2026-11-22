<?php
// Checks a discount code for the checkout summary. Says only whether it works and how much it gives.
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
usleep(250000); // slow down guessing
$d = discount_find((string) ($_GET['code'] ?? ''));
echo json_encode($d ? ['ok' => true, 'percent' => min(90, (float) $d['percent'])] : ['ok' => false]);
