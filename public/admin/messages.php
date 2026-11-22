<?php
// Staff backend: messages sent with "Send us a message" on the website.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();

admin_post(function () use ($db) {
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'done') {
        $db->prepare('UPDATE contact_messages SET done = 1 - done WHERE id = :id')->execute(['id' => $id]);
        flash('Updated.');
    } elseif (($_POST['action'] ?? '') === 'delete') {
        $db->prepare('DELETE FROM contact_messages WHERE id = :id')->execute(['id' => $id]);
        admin_log('message_deleted', '#' . $id);
        flash('Deleted.');
    }
}, '/admin/messages.php' . (isset($_GET['all']) ? '?all=1' : ''));

$all = isset($_GET['all']);
$rows = $db->query('SELECT * FROM contact_messages' . ($all ? '' : ' WHERE done = 0') . ' ORDER BY id DESC LIMIT 300')->fetchAll();

admin_head('Messages', 'messages');
?>
<h1>Messages</h1>
<p class="ad-muted">Sent with "Send us a message" on the website. The team also gets them by email (Settings → Team emails). Click the email address to answer.
  <?= $all ? '<a href="/admin/messages.php">Show only open</a>' : '<a href="/admin/messages.php?all=1">Show answered too</a>' ?></p>
<?php foreach ($rows as $m): ?>
  <div class="ad-box" style="<?= (int) $m['done'] ? 'opacity:.6' : '' ?>">
    <p style="margin-top:0"><strong><?= e($m['name']) ?></strong> · <a href="mailto:<?= e($m['email']) ?>?subject=<?= rawurlencode("Re: your message to Dj'aki mi ta!") ?>"><?= e($m['email']) ?></a>
      <span class="ad-muted">· <?= e(admin_date($m['created'], true)) ?></span> <?= (int) $m['done'] ? '<span class="ad-tag ok">Answered</span>' : '<span class="ad-tag wait">Open</span>' ?></p>
    <p style="white-space:pre-wrap"><?= e($m['message']) ?></p>
    <form method="post" class="ad-actions"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
      <button class="btn btn-sm btn-outline" name="action" value="done"><?= (int) $m['done'] ? 'Mark as open' : 'Mark as answered' ?></button>
      <button class="btn btn-sm btn-outline" name="action" value="delete" onclick="return confirm('Delete this message?')">Delete</button>
    </form>
  </div>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="ad-muted">No <?= $all ? '' : 'open ' ?>messages.</p><?php endif; ?>
<?php admin_foot();
