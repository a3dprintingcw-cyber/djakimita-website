<?php
// The logged-in customer of an app request: client id + this phone's key (one account, one phone).
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/../../app/devices.php';
require_once __DIR__ . '/../../app/points.php';

function api_customer(int $clientId, string $token): int
{
    if ($clientId <= 0 || !dev_check(api_db(), $clientId, $token)) {
        api_json(['status' => 'out'], 401);
    }
    return $clientId;
}
