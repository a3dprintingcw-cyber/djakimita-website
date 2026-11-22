<?php
// Spin the wheel for a logged-in customer with an active card. Returns where the wheel stops.
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$me = current_client();
$fail = function (string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    $fail(t('co.err.session'));
}
if (!$me) {
    $fail('Not logged in', 401);
}
if (!wheel_on()) {
    $fail('Off');
}
$active = array_filter(client_cards((int) $me['id']), fn($c) => $c['active']);
if (!$active) {
    $fail(t('w.need'));
}
// One spin at a time per customer
$lock = db()->prepare('SELECT GET_LOCK(:k, 5)');
$lock->execute(['k' => 'wheel' . (int) $me['id']]);
if ($next = wheel_next((int) $me['id'])) {
    $fail(t('w.next', ['date' => date('d-m-Y', strtotime($next))]));
}
[$index, $prize] = wheel_spin((int) $me['id']);
db()->prepare('SELECT RELEASE_LOCK(:k)')->execute(['k' => 'wheel' . (int) $me['id']]);
$next = wheel_next((int) $me['id']);
echo json_encode([
    'ok' => true,
    'index' => $index,
    'prize' => (bool) (int) $prize['is_prize'],
    'text' => (int) $prize['is_prize'] ? t('w.won', ['prize' => $prize['label']]) : t('w.lost', ['prize' => $prize['label']]),
    'sub' => (int) $prize['is_prize'] ? t('w.won.t') : '',
    'next' => $next ? t('w.next', ['date' => date('d-m-Y', strtotime($next))]) : '',
]);
