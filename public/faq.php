<?php
require __DIR__ . '/../app/bootstrap.php';

$months = card_months();
$schema = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
foreach (FAQ_KEYS as $k) {
    $schema['mainEntity'][] = ['@type' => 'Question', 'name' => t('f.' . $k . '.q'),
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => t('f.' . $k . '.a', ['m' => $months])]];
}

page_header(t('faqp.title') . " · Dj'aki mi ta!", 'faq', t('faqp.lead'));
?>
<script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<section class="page-head tint">
  <div class="wrap narrow">
    <h1><?= e(t('faqp.title')) ?></h1>
    <p class="lead"><?= e(t('faqp.lead')) ?></p>
  </div>
</section>
<section class="section pt-sm">
  <div class="wrap narrow">
    <?php foreach (FAQ_KEYS as $i => $k): ?>
      <details class="faq" id="<?= e($k) ?>"<?= $i === 0 ? ' open' : '' ?>>
        <summary><?= e(t('f.' . $k . '.q')) ?> <?= icon('chevron', 18) ?></summary>
        <p><?php
            $a = e(t('f.' . $k . '.a', ['m' => $months]));
            echo str_replace('contact@djakimita.com', '<a href="mailto:contact@djakimita.com">contact@djakimita.com</a>', $a);
        ?></p>
      </details>
    <?php endforeach; ?>

    <div class="box faq-still">
      <h2 class="h3"><?= icon('mail', 22) ?> <?= e(t('faqp.still')) ?></h2>
      <p><?= e(t('faqp.still.t')) ?></p>
      <a class="btn" href="mailto:contact@djakimita.com">contact@djakimita.com</a>
    </div>
  </div>
</section>
<?php page_footer();
