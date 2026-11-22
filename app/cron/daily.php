<?php
// Daily job: birthday emails for card holders and the morning summary for the team.
// Run once a day from cron (cPanel "Cron Jobs"), for example at 08:05:
//   php /home/USER/app/cron/daily.php
// Options: --force (run even if it already ran today)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';
$done = daily_jobs(in_array('--force', $argv, true));
echo date('Y-m-d H:i') . ' ' . json_encode($done) . "\n";
