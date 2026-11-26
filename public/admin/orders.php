<?php
// Staff backend: orders. List with filters, and one order in detail.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();

/** How an order was paid, from its token. */
function pay_provider(string $token): string
{
    if (str_starts_with($token, 'sentoo:')) {
        return 'Sentoo';
    }
    if (str_starts_with($token, 'tr_')) {
        return 'Mollie';
    }
    if (str_starts_with($token, 'pi_')) {
        return 'Stripe (old)';
    }
    return $token === '' ? '–' : 'Other';
}

/** Price of one order row, from the card length (or the old yearly price). */
function row_price(array $r): float
{
    $cur = strtolower($r['currency'] ?: 'eur');
    $st = db()->prepare('SELECT l.eur, l.usd, l.xcg FROM sale_lengths s JOIN card_lengths l ON l.id = s.length_id WHERE s.sale_id = :s');
    $st->execute(['s' => $r['id']]);
    if ($l = $st->fetch()) {
        return (float) ($l[$cur] ?? $l['eur']);
    }
    $p = prices()[(int) $r['package']] ?? null;
    return $p ? (float) ($p[$cur] ?? $p['eur']) : 0.0;
}

$id = (int) ($_GET['id'] ?? 0);

admin_post(function () use ($id) {
    $rows = order_rows($id);
    if (!$rows) {
        throw new RuntimeException('Order not found');
    }
    $token = (string) $rows[0]['token'];
    switch ($_POST['action'] ?? '') {
        case 'recheck':
            if (in_array(pay_provider($token), ['Mollie', 'Sentoo', 'Stripe (old)'], true) && fulfill_payment_intent($token)) {
                admin_log('order_recheck_paid', '#' . $id);
                flash('The payment provider says this order is paid. The account is active and the customer got the email.');
            } else {
                flash('The payment provider says this order is not paid (yet).', 'bad');
            }
            break;
        case 'mark_paid':
            $n = activate_order($token);
            admin_log('order_marked_paid', '#' . $id . ' ' . trim((string) ($_POST['why'] ?? '')));
            flash($n ? 'Marked as paid. The account is active and the customer got the email.' : 'This order was already paid.');
            break;
        case 'resend':
            if ((int) $rows[0]['status'] === 1) {
                send_order_email($rows);
                admin_log('order_email_resent', '#' . $id);
                flash('Confirmation email sent again to ' . $rows[0]['email'] . '.');
            }
            break;
    }
}, '/admin/orders.php?id=' . $id);

// ---------------------------------------------------------------------------
// One order
// ---------------------------------------------------------------------------
if ($id) {
    $rows = order_rows($id);
    if (!$rows) {
        http_response_code(404);
        admin_head('Order not found', 'orders');
        echo '<h1>Order not found</h1><p><a href="/admin/orders.php">← All orders</a></p>';
        admin_foot();
        exit;
    }
    $main = $rows[0];
    $token = (string) $main['token'];
    $cur = strtolower($main['currency'] ?: 'eur');
    $client = $db->prepare('SELECT id, username, lastName, email, phone FROM clients WHERE id = :id');
    $client->execute(['id' => $main['user_id']]);
    $client = $client->fetch();
    $holders = $db->prepare('SELECT name, client_birthdate FROM client_package_customers WHERE order_id = :o ORDER BY id');
    $holders->execute(['o' => (int) $main['id']]);
    $holders = $holders->fetchAll();
    $checks = $db->prepare('SELECT name, status, note FROM holder_verifications WHERE order_id = :o ORDER BY id');
    $checks->execute(['o' => (int) $main['id']]);
    $checks = $checks->fetchAll();
    $total = 0.0;
    $lines = [];
    foreach ($rows as $r) {
        $len = $db->prepare('SELECT l.code FROM sale_lengths s JOIN card_lengths l ON l.id = s.length_id WHERE s.sale_id = :s');
        $len->execute(['s' => $r['id']]);
        $code = (string) $len->fetchColumn();
        $exp = null;
        if ((int) $r['client_package_id']) {
            $e = $db->prepare('SELECT expire FROM clients_package WHERE id = :id');
            $e->execute(['id' => $r['client_package_id']]);
            $exp = $e->fetchColumn();
        }
        $price = row_price($r);
        $total += $price;
        $lines[] = ['card' => card_name((int) $r['package']), 'length' => $code ? t('co.len.' . $code) : ((int) $r['package'] === ESIM_PACKAGE ? '' : '1 year'), 'price' => $price, 'expire' => $exp];
    }
    $paid = (int) $main['status'] === 1;
    $provider = pay_provider($token);

    admin_head('Order #' . $id, 'orders');
    ?>
    <p><a href="/admin/orders.php">← All orders</a></p>
    <h1>Order #<?= $id ?> <?= $paid ? '<span class="ad-tag ok">Paid</span>' : '<span class="ad-tag wait">Not paid</span>' ?></h1>
    <div class="ad-two">
      <div class="ad-box">
        <h2 style="margin-top:0">Order</h2>
        <dl>
          <dt><?= $paid ? 'Paid on' : 'Started on' ?></dt><dd><?= e(admin_date($main['dia_orario'], true)) ?></dd>
          <dt>Total</dt><dd><?= e(admin_money($total, $cur)) ?></dd>
          <dt>Paid with</dt><dd><?= e($provider) ?> · <?= e(strtoupper($cur)) ?></dd>
          <dt>Payment reference</dt><dd>
            <?php if ($provider === 'Mollie'): ?><a href="https://my.mollie.com/dashboard/payments/<?= e($token) ?>" target="_blank" rel="noopener"><?= e($token) ?> ↗</a>
            <?php else: ?><?= e($token ?: '–') ?><?php endif; ?></dd>
        </dl>
        <table class="ad-table" style="margin-top:14px">
          <thead><tr><th>Account</th><th>Length</th><th>Price</th><th>Valid until</th></tr></thead>
          <tbody><?php foreach ($lines as $l): ?>
            <tr><td><?= e($l['card']) ?></td><td><?= e($l['length']) ?></td><td><?= e(admin_money($l['price'], $cur)) ?></td><td><?= e($l['expire'] ? admin_date($l['expire']) : '–') ?></td></tr>
          <?php endforeach; ?></tbody>
        </table>
      </div>
      <div class="ad-box">
        <h2 style="margin-top:0">Customer</h2>
        <?php if ($client): ?>
          <dl>
            <dt>Name</dt><dd><a href="/admin/customers.php?id=<?= (int) $client['id'] ?>"><?= e(trim($client['username'] . ' ' . $client['lastName'])) ?></a></dd>
            <dt>Email</dt><dd><a href="mailto:<?= e($client['email']) ?>"><?= e($client['email']) ?></a></dd>
            <dt>Phone</dt><dd><?= e($client['phone'] ?: '–') ?></dd>
          </dl>
        <?php else: ?><p class="ad-muted"><?= e($main['email']) ?> (no account found)</p><?php endif; ?>
        <h2>Account holders</h2>
        <?php if (!$holders): ?><p class="ad-muted">None saved for this order.</p><?php endif; ?>
        <ul>
          <?php foreach ($holders as $h): ?><li><?= e($h['name']) ?> · born <?= e(admin_date($h['client_birthdate'])) ?></li><?php endforeach; ?>
        </ul>
        <?php if ($checks): ?>
          <h2>Account checks</h2>
          <ul><?php foreach ($checks as $c): ?>
            <li><?= e($c['name']) ?>: <?= (int) $c['status'] === 1 ? '<span class="ad-tag ok">Approved</span>' : ((int) $c['status'] === 2 ? '<span class="ad-tag bad">Sent back</span>' : '<span class="ad-tag wait">Waiting</span>') ?></li>
          <?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="ad-box">
      <h2 style="margin-top:0">Actions</h2>
      <div class="ad-actions">
        <?php if (!$paid && in_array($provider, ['Mollie', 'Sentoo', 'Stripe (old)'], true)): ?>
          <form method="post"><?= csrf_field() ?><button class="btn btn-sm" name="action" value="recheck">Ask <?= e($provider) ?> again if it's paid</button></form>
        <?php endif; ?>
        <?php if ($paid): ?>
          <form method="post"><?= csrf_field() ?><button class="btn btn-sm btn-outline" name="action" value="resend">Send the confirmation email again</button></form>
        <?php else: ?>
          <form method="post" data-confirm="Mark order #<?= $id ?> as paid? The account turns on and the customer gets the email. Only do this when the money is really in."><?= csrf_field() ?>
            <input class="ad-in" style="width:260px;display:inline-block" name="why" placeholder="Why? e.g. paid cash at the office" required>
            <button class="btn btn-sm btn-outline" name="action" value="mark_paid">Mark as paid</button></form>
        <?php endif; ?>
      </div>
      <p class="ad-muted">Refunds are done in <?= $provider === 'Sentoo' ? 'the Sentoo portal' : 'Mollie' ?>. To stop an account after a refund, set its end date to today on the customer's page.</p>
    </div>
    <?php
    admin_foot();
    exit;
}

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------
$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'paid');
$via = (string) ($_GET['via'] ?? '');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '';
$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 40;

$where = ["s.token IS NOT NULL", "s.token <> ''", "s.status <> 2"]; // status 2 = names for a given (free) account, not an order
$params = [];
if ($status === 'paid') {
    $where[] = 's.status = 1';
} elseif ($status === 'open') {
    $where[] = 's.status = 0';
}
if ($via === 'mollie') {
    $where[] = "s.token LIKE 'tr\\_%'";
} elseif ($via === 'sentoo') {
    $where[] = "s.token LIKE 'sentoo:%'";
} elseif ($via === 'stripe') {
    $where[] = "s.token LIKE 'pi\\_%'";
}
if ($from !== '') {
    $where[] = 's.dia_orario >= :from';
    $params['from'] = $from . ' 00:00:00';
}
if ($q !== '') {
    if (ctype_digit(ltrim($q, '#'))) {
        $where[] = 's.token = (SELECT token FROM sale_receits WHERE id = :qid)';
        $params['qid'] = (int) ltrim($q, '#');
    } else {
        $where[] = 's.email LIKE :q';
        $params['q'] = '%' . $q . '%';
    }
}
$w = implode(' AND ', $where);
$count = $db->prepare("SELECT COUNT(DISTINCT s.token) FROM sale_receits s WHERE $w");
$count->execute($params);
$total = (int) $count->fetchColumn();
$st = $db->prepare("SELECT s.token, MIN(s.id) id, MIN(s.email) email, MIN(s.currency) currency, MAX(s.status) status, MIN(s.dia_orario) d,
        GROUP_CONCAT(s.package ORDER BY s.id) packages
    FROM sale_receits s WHERE $w GROUP BY s.token ORDER BY MIN(s.id) DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($params);
$orders = $st->fetchAll();

admin_head('Orders', 'orders');
?>
<h1>Orders</h1>
<form class="ad-filter" method="get">
  <input name="q" value="<?= e($q) ?>" placeholder="Email or order number" style="min-width:240px">
  <select name="status">
    <option value="paid"<?= $status === 'paid' ? ' selected' : '' ?>>Paid</option>
    <option value="open"<?= $status === 'open' ? ' selected' : '' ?>>Not paid</option>
    <option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All</option>
  </select>
  <select name="via">
    <option value="">All payment ways</option>
    <option value="mollie"<?= $via === 'mollie' ? ' selected' : '' ?>>Mollie</option>
    <option value="sentoo"<?= $via === 'sentoo' ? ' selected' : '' ?>>Sentoo</option>
    <option value="stripe"<?= $via === 'stripe' ? ' selected' : '' ?>>Stripe (old site)</option>
  </select>
  <label class="ad-muted">from <input type="date" name="from" value="<?= e($from) ?>"></label>
  <button class="btn btn-sm">Search</button>
  <span class="ad-muted"><?= $total ?> order<?= $total === 1 ? '' : 's' ?></span>
</form>
<div class="ad-wrap">
<table class="ad-table">
  <thead><tr><th>Order</th><th>Date</th><th>Email</th><th>Accounts</th><th>Paid with</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr>
      <td><a href="/admin/orders.php?id=<?= (int) $o['id'] ?>">#<?= (int) $o['id'] ?></a></td>
      <td><?= e(admin_date($o['d'], true)) ?></td>
      <td><?= e($o['email']) ?></td>
      <td><?= e(implode(', ', array_map(fn($p) => card_name((int) $p), explode(',', (string) $o['packages'])))) ?></td>
      <td><?= e(pay_provider((string) $o['token'])) ?> · <?= e(strtoupper($o['currency'] ?: 'eur')) ?></td>
      <td><?= (int) $o['status'] === 1 ? '<span class="ad-tag ok">Paid</span>' : '<span class="ad-tag wait">Not paid</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="6" class="ad-muted">No orders found.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= pager($total, $page, $per, array_filter(['q' => $q, 'status' => $status, 'via' => $via, 'from' => $from])) ?>
<?php admin_foot();
