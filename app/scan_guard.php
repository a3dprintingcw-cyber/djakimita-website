<?php
// Loaded at the very top of api/scan.php (the old app endpoint where a customer scans a partner's QR code).
// 1. No account picked in the app: clear answer instead of the old crash.
// 2. One account, one phone: the new app sends &dt=<phone key>. A logged-out phone can't scan.
//    With the setting phones_require_all = 1, scans without a phone key (the old store app) are refused too.
// 3. Extra visits bought with points: when the normal visits at this partner are used, an extra visit is
//    registered here; with none left the answer is "novisits" (the app offers to buy one with points).
(function () {
    try {
        $c = require __DIR__ . '/config.local.php';
        $db = new PDO('mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        require_once __DIR__ . '/devices.php';
        require_once __DIR__ . '/points.php';
        $answer = function (array $d) {
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            echo json_encode($d);
            exit;
        };
        $vid = (int) ($_GET['vid'] ?? 0);
        if ($vid <= 0) {
            $answer(['status' => 'noaccount']);
        }
        $dt = (string) ($_GET['dt'] ?? '');
        $requireAll = dev_setting($db, 'phones_require_all', '0') === '1';
        $st = $db->prepare('SELECT id, users_id, package_id, expire FROM clients_package WHERE id = :v');
        $st->execute(['v' => $vid]);
        $acc = $st->fetch(PDO::FETCH_ASSOC);
        $client = $acc ? (int) $acc['users_id'] : 0;
        if ($dt !== '' || $requireAll) {
            if (!$client || !dev_check($db, $client, $dt)) {
                $answer(['status' => $dt === '' ? 'update' : 'device']);
            }
        }

        // Extra visits (only for a valid account and a partner id we understand)
        $company = (string) ($_GET['id'] ?? '');
        if ($acc && ctype_digit($company) && $acc['expire'] >= date('Y-m-d')) {
            $v = visits_at($db, (int) $acc['id'], (int) $acc['package_id'], (int) $company);
            if ($v['total'] > 0 && $v['used'] >= $v['total']) {
                if ($v['left'] > 0) {
                    $db->prepare('INSERT INTO client_package_info (users_package_id, company_id, amount, date) VALUES (:a, :c, 1, NOW())')
                        ->execute(['a' => $acc['id'], 'c' => (int) $company]);
                    $answer(['status' => 'success', 'extra' => 1]);
                }
                $answer(['status' => 'novisits', 'company' => (int) $company]);
            }
        }
    } catch (Throwable $e) {
        // never block scans because of this check itself failing
    }
})();
