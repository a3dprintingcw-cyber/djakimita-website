<?php
// Visits left at a partner for the logged-in customer, and the price of an extra visit in points.
// ?user_id=&dt=&company=
require __DIR__ . '/_auth.php';
$db = api_db();
$id = api_customer((int) ($_GET['user_id'] ?? 0), (string) ($_GET['dt'] ?? ''));
$acc = pts_main_account($db, $id, (int) ($_GET['vid'] ?? 0));
if (!$acc) {
    api_json(['status' => 'noaccount']);
}
$v = visits_at($db, (int) $acc['id'], (int) $acc['package_id'], (int) ($_GET['company'] ?? 0));
$r = pts_rules($db);
api_json(['status' => 'ok'] + $v + ['cost' => $r['pts_extra_scan'], 'count' => $r['extra_scan_count'], 'points_on' => pts_on($db), 'balance' => pts_on($db) ? pts_balance($db, $id) : 0]);
