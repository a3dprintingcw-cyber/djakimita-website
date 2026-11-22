<?php
// Robot check for public forms (contact, become a partner).
// Layers: hidden honeypot field, a signed "form opened at" time (too fast = bot),
// an "I am not a robot" tick box that only works with JavaScript, and a limit per IP.
// If Cloudflare Turnstile keys are in config.php (turnstile_site / turnstile_secret),
// the Turnstile widget is used instead of the tick box.

function bot_key(): string
{
    $k = (string) config('app_secret');
    return $k !== '' ? $k : (string) config('db_pass') . '|djk-bot';
}

function turnstile_on(): bool
{
    return (string) config('turnstile_site', '') !== '' && (string) config('turnstile_secret', '') !== '';
}

/** Hidden fields + the robot tick box. Put this inside the <form>. */
function bot_fields(): string
{
    $t = (string) time();
    $sig = substr(hash_hmac('sha256', 'bt' . $t, bot_key()), 0, 20);
    $h = '<div class="hp" aria-hidden="true"><label>Website URL<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>'
        . '<input type="hidden" name="bt" value="' . e($t . '.' . $sig) . '">';
    if (turnstile_on()) {
        return $h . '<div class="cf-turnstile" data-sitekey="' . e((string) config('turnstile_site')) . '"></div>'
            . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
    }
    $k = substr(hash_hmac('sha256', 'hk' . $t, bot_key()), 0, 16);
    return $h . '<input type="hidden" name="hk" value="">'
        . '<label class="robot"><input type="checkbox" required onchange="this.form.hk.value=this.checked?\'' . e($k) . '\':\'\'"> <span>' . e(t('ct.human')) . '</span></label>';
}

/** '' when it looks human, 'bot' for a silent fake success, or a translation key for the error. */
function bot_check(string $scope, int $maxPerHour = 5): string
{
    if ((string) ($_POST['website_url'] ?? '') !== '') {
        return 'bot';
    }
    [$t, $sig] = array_pad(explode('.', (string) ($_POST['bt'] ?? ''), 2), 2, '');
    if (!ctype_digit($t) || !hash_equals(substr(hash_hmac('sha256', 'bt' . $t, bot_key()), 0, 20), $sig)) {
        return 'ct.bot';
    }
    $age = time() - (int) $t;
    if ($age < 3) {
        return 'ct.slow';
    }
    if ($age > 6 * 3600) {
        return 'co.err.session';
    }
    if (turnstile_on()) {
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => (string) config('turnstile_secret'),
                'response' => (string) ($_POST['cf-turnstile-response'] ?? ''), 'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''])]);
        $r = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        if (empty($r['success'])) {
            return 'ct.bot';
        }
    } elseif (!hash_equals(substr(hash_hmac('sha256', 'hk' . $t, bot_key()), 0, 16), (string) ($_POST['hk'] ?? ''))) {
        return 'ct.bot';
    }
    // Per IP limit, kept in the settings table so no extra table is needed
    $ip = hash('sha256', $scope . ':' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    try {
        $db = db();
        $db->exec('CREATE TABLE IF NOT EXISTS bot_hits (h CHAR(64) NOT NULL, at DATETIME NOT NULL, KEY h (h, at)) ENGINE=InnoDB');
        $db->exec('DELETE FROM bot_hits WHERE at < NOW() - INTERVAL 1 DAY');
        $st = $db->prepare('SELECT COUNT(*) FROM bot_hits WHERE h = :h AND at > NOW() - INTERVAL 1 HOUR');
        $st->execute(['h' => $ip]);
        if ((int) $st->fetchColumn() >= $maxPerHour) {
            return 'ct.slow';
        }
        $db->prepare('INSERT INTO bot_hits (h, at) VALUES (:h, NOW())')->execute(['h' => $ip]);
    } catch (Throwable $e) {
        error_log('bot_check: ' . $e->getMessage());
    }
    return '';
}
