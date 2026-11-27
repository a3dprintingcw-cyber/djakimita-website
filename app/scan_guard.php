<?php
// Loaded at the very top of api/scan.php (the old app endpoint where a customer scans a partner's QR code).
// The new app sends &dt=<phone key>. If that phone was logged out because the account was opened on
// another phone, the scan is refused. With the setting phones_require_all = 1, scans without a phone key
// (the old store app) are refused too, so set that on once the store update is out.
(function () {
    try {
        $c = require __DIR__ . '/config.local.php';
        $db = new PDO('mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        require_once __DIR__ . '/devices.php';
        $dt = (string) ($_GET['dt'] ?? '');
        $requireAll = dev_setting($db, 'phones_require_all', '0') === '1';
        if ($dt === '' && !$requireAll) {
            return;
        }
        $st = $db->prepare('SELECT users_id FROM clients_package WHERE id = :v');
        $st->execute(['v' => (int) ($_GET['vid'] ?? 0)]);
        $client = (int) $st->fetchColumn();
        if ($client > 0 && dev_check($db, $client, $dt)) {
            return;
        }
        if ($client <= 0 && $dt === '') {
            return; // not a customer scan we know; leave it to scan.php
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(['status' => $dt === '' ? 'update' : 'device']);
        exit;
    } catch (Throwable $e) {
        // never block scans because of this check itself failing
    }
})();
