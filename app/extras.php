<?php
// Extra features: age rules, discount codes, company details, partner videos, jingle,
// spin the wheel, staff notifications, birthday emails and the daily summary.

/** The website folder (public_html on the server, public locally). */
function public_dir(): string
{
    return is_dir(__DIR__ . '/../public_html') ? __DIR__ . '/../public_html' : __DIR__ . '/../public';
}

// ---------------------------------------------------------------------------
// Age rules per card (by year of birth, as the team asked)
//   Kids 0-12, Teen 13-17, Adult 18+, Senior 60+, Family: at most 3 children up to 17.
// ---------------------------------------------------------------------------
function age_by_year(string $birth): int
{
    return (int) date('Y') - (int) substr($birth, 0, 4);
}

/** Returns an error text when a holder does not fit the card, or null when all is fine. */
function card_age_error(string $card, array $holders): ?string
{
    $kids = 0;
    foreach ($holders as $h) {
        $age = age_by_year($h['birth']);
        $vars = ['name' => $h['name'], 'year' => substr($h['birth'], 0, 4)];
        switch ($card) {
            case 'kids':
                if ($age > 12) {
                    return t('co.age.kids', $vars);
                }
                break;
            case 'teen':
                if ($age < 13 || $age > 17) {
                    return t('co.age.teen', $vars);
                }
                break;
            case 'adult':
                if ($age < 18) {
                    return t('co.age.adult', $vars);
                }
                break;
            case 'senior':
                if ($age < 60) {
                    return t('co.age.senior', $vars);
                }
                break;
            case 'family':
                if ($age <= 17) {
                    $kids++;
                }
                break;
        }
    }
    if ($card === 'family' && $kids > 3) {
        return t('co.age.family');
    }
    return null;
}

// ---------------------------------------------------------------------------
// Discount codes (a percentage off the card; the eSIM is never discounted)
// ---------------------------------------------------------------------------
function discount_find(string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    try {
        $st = db()->prepare('SELECT * FROM discount_codes WHERE code = :c AND active = 1
                             AND (valid_until IS NULL OR valid_until >= CURDATE()) AND (max_uses IS NULL OR used < max_uses)');
        $st->execute(['c' => $code]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Count a used code once the order is paid. */
function discount_count_used(int $mainId): void
{
    try {
        $st = db()->prepare('SELECT code_id FROM sale_discounts WHERE sale_id = :s AND counted = 0');
        $st->execute(['s' => $mainId]);
        $codeId = (int) $st->fetchColumn();
        if ($codeId) {
            db()->prepare('UPDATE sale_discounts SET counted = 1 WHERE sale_id = :s')->execute(['s' => $mainId]);
            db()->prepare('UPDATE discount_codes SET used = used + 1 WHERE id = :id')->execute(['id' => $codeId]);
        }
    } catch (Throwable $e) {
    }
}

// ---------------------------------------------------------------------------
// Company details (Settings in the backend), shown in the footer and legal pages
// ---------------------------------------------------------------------------
function company_info(): array
{
    return [
        'name' => trim((string) setting('company_name', '')),
        'kvk' => trim((string) setting('company_kvk', '')),
        'address' => trim((string) setting('company_address', '')),
    ];
}

function company_line(): string
{
    $c = company_info();
    $parts = array_filter([$c['name'], $c['kvk'] !== '' ? 'KvK ' . $c['kvk'] : '', $c['address']]);
    return implode(' · ', $parts);
}

// ---------------------------------------------------------------------------
// Partner video (YouTube, Vimeo, or any link)
// ---------------------------------------------------------------------------
function partner_video(int $companyId): string
{
    try {
        $st = db()->prepare('SELECT video_url FROM partner_extra WHERE company_id = :c');
        $st->execute(['c' => $companyId]);
        return (string) $st->fetchColumn();
    } catch (Throwable $e) {
        return '';
    }
}

function video_embed(string $url, string $label = 'Video'): string
{
    if (preg_match('~(?:youtube\.com/(?:watch\?v=|shorts/|embed/)|youtu\.be/)([A-Za-z0-9_-]{6,15})~', $url, $m)) {
        return '<div class="video"><iframe src="https://www.youtube-nocookie.com/embed/' . e($m[1]) . '" title="' . e($label) . '" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';
    }
    if (preg_match('~vimeo\.com/(?:video/)?(\d{5,12})~', $url, $m)) {
        return '<div class="video"><iframe src="https://player.vimeo.com/video/' . e($m[1]) . '" title="' . e($label) . '" loading="lazy" allow="fullscreen; picture-in-picture" allowfullscreen></iframe></div>';
    }
    if (preg_match('~^https?://~i', $url)) {
        return '<a class="btn btn-outline" href="' . e($url) . '" target="_blank" rel="noopener">▶ ' . e($label) . '</a>';
    }
    return '';
}

// ---------------------------------------------------------------------------
// Jingle: plays once per visit, after the first tap or click (phones block sound before that)
// ---------------------------------------------------------------------------
function jingle_file(): ?string
{
    foreach (['mp3', 'm4a', 'ogg', 'wav'] as $ext) {
        if (is_file(public_dir() . '/assets/media/jingle.' . $ext)) {
            return '/assets/media/jingle.' . $ext;
        }
    }
    return null;
}

function jingle_html(): string
{
    if (setting('jingle_on', '0') !== '1' || !($f = jingle_file())) {
        return '';
    }
    $v = (int) @filemtime(public_dir() . $f);
    return '<script>(function(){try{if(sessionStorage.getItem("djk_jingle"))return;}catch(e){}'
        . 'var a=new Audio(' . json_encode($f . '?v=' . $v) . ');a.volume=.6;'
        . 'function go(){document.removeEventListener("pointerdown",go,true);document.removeEventListener("keydown",go,true);'
        . 'a.play().then(function(){try{sessionStorage.setItem("djk_jingle","1")}catch(e){}}).catch(function(){});}'
        . 'document.addEventListener("pointerdown",go,true);document.addEventListener("keydown",go,true);})();</script>';
}

// ---------------------------------------------------------------------------
// Spin the wheel (in the customer's account, for people with an active card)
// ---------------------------------------------------------------------------
function wheel_prizes(): array
{
    try {
        return db()->query('SELECT * FROM wheel_prizes WHERE active = 1 AND chance > 0 ORDER BY sort, id')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function wheel_on(): bool
{
    return setting('wheel_on', '0') === '1' && count(wheel_prizes()) >= 2;
}

function wheel_every_days(): int
{
    return max(1, (int) setting('wheel_days', '30'));
}

/** When this customer may spin again (null = now). */
function wheel_next(int $clientId): ?string
{
    $st = db()->prepare('SELECT MAX(created_at) FROM wheel_spins WHERE client_id = :c');
    $st->execute(['c' => $clientId]);
    $last = $st->fetchColumn();
    if (!$last) {
        return null;
    }
    $next = strtotime($last . ' +' . wheel_every_days() . ' days');
    return $next > time() ? date('Y-m-d H:i:s', $next) : null;
}

/** Picks a prize by chance, saves the spin and returns [index on the wheel, prize row]. */
function wheel_spin(int $clientId): array
{
    $prizes = wheel_prizes();
    $total = array_sum(array_map(fn($p) => (int) $p['chance'], $prizes));
    $r = random_int(1, max(1, $total));
    $pick = 0;
    foreach ($prizes as $i => $p) {
        $r -= (int) $p['chance'];
        if ($r <= 0) {
            $pick = $i;
            break;
        }
    }
    $p = $prizes[$pick];
    db()->prepare('INSERT INTO wheel_spins (client_id, prize_id, label, is_prize, given, created_at) VALUES (:c, :p, :l, :w, 0, NOW())')
        ->execute(['c' => $clientId, 'p' => $p['id'], 'l' => $p['label'], 'w' => (int) $p['is_prize']]);
    if ((int) $p['is_prize']) {
        $st = db()->prepare('SELECT email, username, lastName FROM clients WHERE id = :id');
        $st->execute(['id' => $clientId]);
        $c = $st->fetch() ?: [];
        notify_staff('Spin the wheel: ' . $p['label'],
            dm_mail('Someone won on the wheel', '<p><strong>' . e($p['label']) . '</strong></p><p>' . e(trim(($c['username'] ?? '') . ' ' . ($c['lastName'] ?? ''))) . ' · ' . e($c['email'] ?? '') . '</p>'
                . '<p><a href="' . e(rtrim((string) config('base_url'), '/') . '/admin/wheel.php') . '">See all wins in the backend</a></p>'));
    }
    return [$pick, $p];
}

// ---------------------------------------------------------------------------
// Emails
// ---------------------------------------------------------------------------
function dm_mail(string $title, string $body): string
{
    return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#17221a">'
        . '<div style="background:#187c19;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0;font-size:22px;font-weight:bold">Dj\'aki mi ta!</div>'
        . '<div style="border:1px solid #dfe7dc;border-top:0;padding:24px;border-radius:0 0 12px 12px">'
        . '<h1 style="font-size:20px;margin:0 0 12px">' . $title . '</h1>' . $body . '</div></div>';
}

/** Where staff notifications go (Settings → Notifications). Several addresses can be separated by commas. */
function staff_emails(): array
{
    $list = (string) setting('notify_email', (string) config('notify_email', 'contact@djakimita.com'));
    return array_values(array_filter(array_map('trim', explode(',', $list)), fn($m) => filter_var($m, FILTER_VALIDATE_EMAIL)));
}

function notify_staff(string $subject, string $html, string $kind = 'any'): void
{
    if ($kind !== 'any' && setting('notify_' . $kind, '1') !== '1') {
        return;
    }
    foreach (staff_emails() as $to) {
        brevo_send($to, $subject, $html);
    }
}

/** Short email to staff when a card or eSIM is paid. */
function notify_staff_order(array $rows): void
{
    $first = $rows[0];
    $items = [];
    $esim = false;
    foreach ($rows as $r) {
        if ((int) $r['package'] === ESIM_PACKAGE) {
            $esim = true;
            $items[] = 'Flow eSIM';
        } else {
            $items[] = ucfirst(package_label((int) $r['package'])) . ' card';
        }
    }
    $who = db()->prepare('SELECT username, lastName, phone FROM clients WHERE id = :id');
    $who->execute(['id' => $first['user_id']]);
    $who = $who->fetch() ?: [];
    $disc = '';
    try {
        $d = db()->prepare('SELECT code, percent FROM sale_discounts WHERE sale_id = :s');
        $d->execute(['s' => $first['id']]);
        if ($d = $d->fetch()) {
            $disc = '<p>Discount code: <strong>' . e($d['code']) . '</strong> (' . (float) $d['percent'] . '%)</p>';
        }
    } catch (Throwable $e) {
    }
    $base = rtrim((string) config('base_url'), '/');
    $body = '<p><strong>' . e(implode(' + ', $items)) . '</strong> · paid in ' . e(strtoupper((string) ($first['currency'] ?: 'eur'))) . '</p>'
        . '<p>' . e(trim(($who['username'] ?? '') . ' ' . ($who['lastName'] ?? ''))) . ' · ' . e($first['email']) . ($esim && !empty($who['phone']) ? ' · ' . e($who['phone']) : '') . '</p>'
        . $disc
        . ($esim ? '<p style="background:#fbf3dc;padding:10px;border-radius:8px">This order has an eSIM: send it and mark it as sent in the backend.</p>' : '')
        . '<p><a href="' . e($base . '/admin/orders.php?id=' . (int) $first['id']) . '">Open order #' . (int) $first['id'] . '</a></p>';
    $what = $esim ? (count($items) > 1 ? 'Card + eSIM' : 'eSIM') : $items[0];
    notify_staff('New order: ' . $what . ' (#' . (int) $first['id'] . ')', dm_mail('New order paid', $body), $esim ? 'esim' : 'orders');
}

// ---------------------------------------------------------------------------
// Daily jobs: birthday emails and the daily summary for staff.
// Run by cron (app/cron/daily.php) and, as a backup, the first time staff open the backend after 08:00.
// ---------------------------------------------------------------------------
function daily_jobs(bool $force = false): array
{
    $today = date('Y-m-d');
    if (!$force && (setting('daily_last', '') === $today || (int) date('G') < 8)) {
        return [];
    }
    set_setting('daily_last', $today);
    $done = [];
    $base = rtrim((string) config('base_url'), '/');

    // 1. Birthdays: card holders with an active card, once a year
    if (setting('birthday_on', '1') === '1') {
        $kind = 'bday' . date('Y');
        $st = db()->prepare("SELECT h.id, h.name, s.email FROM client_package_customers h
            JOIN sale_receits s ON s.id = h.order_id AND s.status = 1
            JOIN clients_package cp ON cp.id = s.client_package_id AND cp.expire >= CURDATE()
            WHERE DATE_FORMAT(h.client_birthdate, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')");
        $st->execute();
        $sentTo = [];
        foreach ($st->fetchAll() as $h) {
            $seen = db()->prepare('SELECT 1 FROM reminders_sent WHERE kind = :k AND ref_id = :r');
            $seen->execute(['k' => $kind, 'r' => $h['id']]);
            if ($seen->fetchColumn()) {
                continue;
            }
            $first = explode(' ', trim((string) $h['name']))[0] ?: 'there';
            $msg = trim((string) setting('birthday_text', '')) ?: "Today is your day! The whole Dj'aki mi ta! team wishes you a happy birthday. Enjoy it, and don't forget to use your card today.";
            $html = dm_mail('Felis kumpleaño, ' . e($first) . '! 🎂', '<p>' . nl2br(e($msg)) . '</p><p><a href="' . e($base . '/partners.php') . '">See where you can go today</a></p>');
            if (brevo_send((string) $h['email'], "Felis kumpleaño, " . $first . "! 🎂", $html)) {
                db()->prepare('INSERT INTO reminders_sent (kind, ref_id, sent_at) VALUES (:k, :r, NOW())')->execute(['k' => $kind, 'r' => $h['id']]);
                $sentTo[] = $h['name'];
            }
        }
        $done['birthdays'] = $sentTo;
    }

    // 2. Daily summary for staff: yesterday's sales, visits at partners and app views
    if (setting('notify_daily', '1') === '1' && staff_emails()) {
        $y = date('Y-m-d', strtotime('-1 day'));
        $q = fn(string $sql) => (function () use ($sql, $y) { $s = db()->prepare($sql); $s->execute(['d' => $y]); return $s; })();
        $orders = $q("SELECT LOWER(COALESCE(currency, 'eur')) cur, COUNT(DISTINCT token) n FROM sale_receits WHERE status = 1 AND DATE(dia_orario) = :d GROUP BY cur")->fetchAll();
        $scans = $q('SELECT TRIM(v.name) name, COUNT(*) n FROM client_package_info i JOIN voucher_company v ON v.id = i.company_id WHERE DATE(i.date) = :d GROUP BY v.id ORDER BY n DESC')->fetchAll();
        $views = (int) $q('SELECT COALESCE(SUM(view_count), 0) FROM client_view_history WHERE date = :d')->fetchColumn();
        $topViews = $q('SELECT TRIM(v.name) name, SUM(h.view_count) n FROM client_view_history h JOIN voucher_company v ON v.id = h.company_id WHERE h.date = :d GROUP BY v.id ORDER BY n DESC LIMIT 5')->fetchAll();
        $checks = (int) db()->query('SELECT COUNT(*) FROM holder_verifications WHERE status = 0')->fetchColumn();
        $esims = (int) db()->query('SELECT COUNT(*) FROM esim WHERE confirmed = 1')->fetchColumn();
        $li = fn(array $rows) => $rows ? '<ul>' . implode('', array_map(fn($r) => '<li>' . e($r['name']) . ': ' . (int) $r['n'] . '</li>', $rows)) . '</ul>' : '<p style="color:#4f5d52">None.</p>';
        $body = '<p style="color:#4f5d52">' . e(date('l j F Y', strtotime($y))) . '</p>'
            . '<h2 style="font-size:16px">Paid orders</h2>' . ($orders ? '<ul>' . implode('', array_map(fn($o) => '<li>' . (int) $o['n'] . ' in ' . e(strtoupper($o['cur'])) . '</li>', $orders)) . '</ul>' : '<p style="color:#4f5d52">None.</p>')
            . '<h2 style="font-size:16px">Card used at partners (' . array_sum(array_column($scans, 'n')) . ')</h2>' . $li($scans)
            . '<h2 style="font-size:16px">Partner pages opened in the app (' . $views . ')</h2>' . $li($topViews)
            . ($checks || $esims ? '<p style="background:#fbf3dc;padding:10px;border-radius:8px">Waiting for you: ' . $checks . ' card check(s), ' . $esims . ' eSIM(s) to send.</p>' : '')
            . '<p><a href="' . e($base . '/admin/stats.php') . '">See charts in the backend</a></p>';
        notify_staff("Dj'aki mi ta! yesterday: " . date('j M', strtotime($y)), dm_mail('Yesterday at Dj\'aki mi ta!', $body));
        $done['summary'] = true;
    }
    return $done;
}
