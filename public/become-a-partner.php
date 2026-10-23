<?php
require __DIR__ . '/../app/bootstrap.php';

$f = ['company' => '', 'reg' => '', 'contact' => '', 'email' => '', 'phone' => '', 'website' => '', 'cat' => '', 'offer' => '', 'consent' => false];
$errors = [];
$sent = !empty($_GET['sent']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lim = ['company' => 200, 'reg' => 120, 'contact' => 200, 'email' => 200, 'phone' => 80, 'website' => 300, 'cat' => 30, 'offer' => 3000];
    foreach ($lim as $k => $max) {
        $f[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    }
    $f['consent'] = !empty($_POST['consent']);
    if (!in_array($f['cat'], partner_categories(), true)) {
        $f['cat'] = '';
    }
    $ipHash = hash('sha256', 'partner:' . ($_SERVER['REMOTE_ADDR'] ?? ''));

    if (!csrf_check()) {
        $errors[] = t('co.err.session');
    }
    if ((string) ($_POST['website_url'] ?? '') !== '') {
        // Hidden field only bots fill in: pretend it worked.
        header('Location: /become-a-partner.php?sent=1#apply');
        exit;
    }
    if ($f['company'] === '' || $f['reg'] === '' || $f['contact'] === '' || $f['offer'] === '') {
        $errors[] = t('bp.err');
    }
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('bp.err.email');
    }
    if (!$f['consent']) {
        $errors[] = t('bp.err.consent');
    }
    if (!$errors) {
        $st = db()->prepare('SELECT COUNT(*) FROM partner_applications WHERE ip_hash = :h AND created_at > NOW() - INTERVAL 1 HOUR');
        $st->execute(['h' => $ipHash]);
        if ((int) $st->fetchColumn() >= 3) {
            $errors[] = t('bp.err.many');
        }
    }
    if (!$errors) {
        db()->prepare('INSERT INTO partner_applications (company, reg_number, contact_name, email, phone, website, category, offer, ip_hash, created_at)
                       VALUES (:c, :r, :n, :e, :p, :w, :cat, :o, :h, NOW())')
            ->execute(['c' => $f['company'], 'r' => $f['reg'], 'n' => $f['contact'], 'e' => $f['email'], 'p' => $f['phone'],
                       'w' => $f['website'], 'cat' => $f['cat'], 'o' => $f['offer'], 'h' => $ipHash]);
        $rows = '';
        foreach (['Company' => 'company', 'KvK number' => 'reg', 'Contact' => 'contact', 'Email' => 'email', 'Phone' => 'phone',
                  'Website' => 'website', 'Type' => 'cat'] as $label => $k) {
            $rows .= '<tr><td style="padding:6px 12px 6px 0;color:#4f5d52">' . $label . '</td><td style="padding:6px 0"><strong>' . e($f[$k]) . '</strong></td></tr>';
        }
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;color:#17221a">'
            . '<h2 style="color:#187c19">New partner application</h2><table>' . $rows . '</table>'
            . '<h3>Offer</h3><p style="white-space:pre-wrap;background:#f1f7ef;padding:12px;border-radius:8px">' . e($f['offer']) . '</p>'
            . '<p style="color:#4f5d52;font-size:13px">Reply to this email to answer ' . e($f['contact']) . ' directly.</p></div>';
        brevo_send('contact@djakimita.com', 'Partner application: ' . $f['company'], $html, ['email' => $f['email'], 'name' => $f['contact']]);
        $thanks = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;color:#17221a">'
            . '<div style="background:#187c19;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0;font-size:22px;font-weight:bold">Dj\'aki mi ta!</div>'
            . '<div style="border:1px solid #dfe7dc;border-top:0;padding:24px;border-radius:0 0 12px 12px">'
            . '<p>Hi ' . e($f['contact']) . ',</p><p>Thank you for applying with <strong>' . e($f['company']) . '</strong>. We will contact you within a few working days to talk about your deal.</p>'
            . '<p>Kind regards,<br>Team Dj\'aki mi ta!</p></div></div>';
        brevo_send($f['email'], "Dj'aki mi ta!: we received your application", $thanks);
        header('Location: /become-a-partner.php?sent=1#apply');
        exit;
    }
}

page_header(t('bp.title') . " · Dj'aki mi ta!", 'partner', t('bp.lead'));
$req = ' <span class="req" aria-hidden="true">*</span>';
?>
<section class="bp-hero">
  <div class="wrap">
    <p class="kicker"><?= icon('sun', 18) ?> <?= e(t('bp.kicker')) ?></p>
    <h1><?= e(t('bp.h1')) ?></h1>
    <p class="lead"><?= e(t('bp.lead')) ?></p>
    <a class="btn btn-lg btn-gold" href="#apply"><?= e(t('bp.cta')) ?> <?= icon('arrow', 18) ?></a>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <div class="bp-benefits">
      <?php foreach ([['globe', 1], ['sun', 2], ['card', 3], ['qr', 4]] as [$ic, $i]): ?>
        <div class="bp-benefit">
          <span class="bp-ic"><?= icon($ic, 26) ?></span>
          <h2 class="h3"><?= e(t('bp.b' . $i)) ?></h2>
          <p><?= e(t('bp.b' . $i . '.t')) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section tint">
  <div class="wrap">
    <h2><?= e(t('bp.how')) ?></h2>
    <ol class="steps">
      <?php for ($i = 1; $i <= 4; $i++): ?>
        <li><span class="step-n"><?= $i ?></span><div><p class="bp-step"><?= e(t('bp.s' . $i)) ?></p></div></li>
      <?php endfor; ?>
    </ol>
    <blockquote class="bp-quote"><?= e(t('bp.quote')) ?></blockquote>
  </div>
</section>

<section class="section" id="apply">
  <div class="wrap narrow">
    <h2><?= e(t('bp.form')) ?></h2>
    <?php if ($sent): ?>
      <div class="ok-box" role="status"><?= icon('check', 20) ?> <?= e(t('bp.sent')) ?></div>
    <?php else: ?>
      <?php if ($errors): ?>
        <div class="alert" role="alert"><ul><?php foreach (array_unique($errors) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
      <form method="post" action="/become-a-partner.php#apply" class="box bp-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="hp" aria-hidden="true"><label>Website URL<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>
        <div class="field-row">
          <div class="field"><label for="company"><?= e(t('bp.company')) . $req ?></label><input id="company" name="company" autocomplete="organization" required value="<?= e($f['company']) ?>"></div>
          <div class="field"><label for="reg"><?= e(t('bp.reg')) . $req ?></label><input id="reg" name="reg" required value="<?= e($f['reg']) ?>"></div>
        </div>
        <div class="field-row">
          <div class="field"><label for="contact"><?= e(t('bp.contact')) . $req ?></label><input id="contact" name="contact" autocomplete="name" required value="<?= e($f['contact']) ?>"></div>
          <div class="field"><label for="email"><?= e(t('bp.email')) . $req ?></label><input id="email" name="email" type="email" inputmode="email" autocomplete="email" required value="<?= e($f['email']) ?>"></div>
        </div>
        <div class="field-row">
          <div class="field"><label for="phone"><?= e(t('bp.phone')) ?></label><input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" value="<?= e($f['phone']) ?>"></div>
          <div class="field"><label for="website"><?= e(t('bp.website')) ?></label><input id="website" name="website" autocomplete="url" value="<?= e($f['website']) ?>"></div>
        </div>
        <div class="field">
          <label for="cat"><?= e(t('bp.cat')) ?></label>
          <select id="cat" name="cat">
            <option value=""><?= e(t('bp.cat.choose')) ?></option>
            <?php foreach (partner_categories() as $c): ?>
              <option value="<?= e($c) ?>"<?= $f['cat'] === $c ? ' selected' : '' ?>><?= e(t('bp.cat.' . $c)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="offer"><?= e(t('bp.offer')) . $req ?></label>
          <textarea id="offer" name="offer" rows="4" required aria-describedby="offer-hint"><?= e($f['offer']) ?></textarea>
          <p class="hint" id="offer-hint"><?= e(t('bp.offer.hint')) ?></p>
        </div>
        <label class="check"><input type="checkbox" name="consent" value="1"<?= $f['consent'] ? ' checked' : '' ?>> <span><?= e(t('bp.consent')) ?></span></label>
        <button class="btn btn-lg btn-block" type="submit"><?= e(t('bp.send')) ?> <?= icon('arrow', 18) ?></button>
        <p class="hint"><span class="req">*</span> <?= e(t('bp.req')) ?></p>
      </form>
    <?php endif; ?>
  </div>
</section>
<?php page_footer();
