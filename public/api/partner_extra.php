<?php
// Extra partner info for the app's partner page: "Please note" box and the deal in words. ?id=<company id>
require __DIR__ . '/_db.php';
$id = (int) ($_GET['id'] ?? 0);
try {
    $st = api_db()->prepare('SELECT note, deal_text FROM partner_extra WHERE company_id = :c');
    $st->execute(['c' => $id]);
    $r = $st->fetch() ?: [];
    $note = trim((string) preg_replace('/^\s*(please\s+note|let\s+op|tene\s+kuidou)\s*:?\s*/iu', '', trim((string) ($r['note'] ?? ''))));
    api_json(['note' => $note, 'deal_text' => trim((string) ($r['deal_text'] ?? ''))]);
} catch (Throwable $e) {
    api_json(['note' => '', 'deal_text' => '']);
}
