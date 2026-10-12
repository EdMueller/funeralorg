<?php $pageTitle = t('cart.title'); ?>
<div class="page-head">
  <div><h1><?= e(t('cart.heading')) ?></h1><p class="lead-note"><?= tn('cart.lead', count($images), ['n' => '<span id="cartCount">' . count($images) . '</span>']) ?></p></div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('gallery')) ?>"><?= e(t('cart.more')) ?></a>
    <?php if ($images): ?>
      <form method="post" action="<?= e(url('gallery', ['a' => 'select'])) ?>" data-confirm="<?= e(t('cart.clear_confirm')) ?>"><?= Csrf::field() ?>
        <input type="hidden" name="id" value=""><input type="hidden" name="mode" value="clear">
        <button class="btn btn-sm btn-outline-secondary"><?= e(t('cart.clear')) ?></button></form>
      <a class="btn btn-sm btn-primary" href="<?= e(url('gallery', ['a' => 'zip', 'what' => 'selection'])) ?>"><i class="bi bi-file-zip me-1"></i><?= e(t('cart.zip')) ?></a>
    <?php endif; ?>
  </div>
</div>
<?php if (!$images): ?>
  <div class="empty surface"><i class="bi bi-bag"></i><?= e(t('cart.empty')) ?></div>
<?php else: ?>
  <div class="gallery-grid">
    <?php foreach ($images as $img): ?>
      <div class="g-item picked" data-id="<?= e($img['id']) ?>">
        <a class="open d-block h-100" href="<?= e(url('img', ['id' => $img['id'], 's' => 'orig'])) ?>" title="<?= e(image_tip($img, array_column($albums, 'name', 'id'))) ?>">
          <img src="<?= e(url('img', ['id' => $img['id'], 's' => 'thumb'])) ?>" alt="<?= e($img['caption']) ?>" loading="lazy"></a>
        <button class="pick" type="button" data-pick="<?= e($img['id']) ?>" data-remove-on-unpick aria-pressed="true" aria-label="<?= e(t('cart.remove')) ?>"><i class="bi bi-check-lg"></i></button>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
