<?php $pageTitle = ''; ?>
<section class="memorial-hero">
  <div class="portrait-oval">
    <?php if ($portrait): ?>
      <img src="<?= e(url('img', ['id' => $portrait['id'], 's' => 'medium'])) ?>" alt="<?= e(t('memorial.portrait_of', ['name' => $m['name']])) ?>">
    <?php else: ?>
      <div class="portrait-empty w-100 h-100"><?= e(mb_substr($m['name'] ?: '·', 0, 1)) ?></div>
    <?php endif; ?>
  </div>
  <div>
    <h1><?= e($m['name'] ?: t('memorial.default_title')) ?></h1>
    <?php if ($m['born'] || $m['died']): ?>
      <p class="life-dates mb-0">
        <?php if ($m['born']): ?><span>* <?= e($m['born']) ?></span><?php endif; ?>
        <?php if ($m['died']): ?><span>† <?= e($m['died']) ?></span><?php endif; ?>
      </p>
    <?php endif; ?>
    <?php if (!empty($m['place'])): ?><p class="text-muted mb-0 mt-1"><?= e($m['place']) ?></p><?php endif; ?>
    <?php if (!empty($m['quote'])): ?>
      <blockquote class="memorial-quote"><?= e($m['quote']) ?>
        <?php if (!empty($m['quote_by'])): ?><cite><?= e($m['quote_by']) ?></cite><?php endif; ?>
      </blockquote>
    <?php endif; ?>
    <?php if (Auth::isAdmin()): ?>
      <a class="btn btn-sm btn-outline-primary mt-3" href="<?= e(url('home', ['a' => 'edit'])) ?>"><i class="bi bi-pencil me-1"></i><?= e(t('memorial.edit_title')) ?></a>
    <?php endif; ?>
  </div>
</section>

<?php if (!empty($m['text'])): ?>
  <div class="memorial-text"><?= e($m['text']) ?></div>
<?php elseif (Auth::isAdmin()): ?>
  <p class="text-muted my-4"><?= e(t('memorial.empty_text')) ?></p>
<?php endif; ?>

<?php if ($summary): ?>
  <h2 class="h3 mt-4 mb-3"><?= e(t('home.upcoming')) ?></h2>
  <div class="status-list mb-4">
    <?php if (isset($summary['tasks_open'])): ?>
      <a href="<?= e(url('checklist', ['show' => 'open'])) ?>"><strong><?= $summary['tasks_open'] ?></strong><small><?= e(tn('home.tasks_open', (int)$summary['tasks_open'], ['total' => $summary['tasks_total']])) ?></small></a>
    <?php endif; ?>
    <?php if (isset($summary['called_open'])): ?>
      <a href="<?= e(url('contacts', ['filter' => 'called_open'])) ?>"><strong><?= $summary['called_open'] ?></strong><small><?= e(tn('home.called_open', (int)$summary['called_open'])) ?></small></a>
    <?php endif; ?>
    <?php if (isset($summary['rsvp'])): $s = $summary['rsvp']; ?>
      <a href="<?= e(url('rsvp')) ?>"><strong><?= $s['adults'] + $s['children'] ?></strong><small><?= e(tn('home.rsvp_yes', $s['adults'] + $s['children'], ['a' => $s['adults'], 'c' => $s['children'], 'o' => $s['open']])) ?></small></a>
    <?php endif; ?>
    <?php if (isset($summary['thanks_open'])): ?>
      <a href="<?= e(url('condolences', ['filter' => 'thanks_open'])) ?>"><strong><?= $summary['thanks_open'] ?></strong><small><?= e(tn('home.thanks_open', (int)$summary['thanks_open'])) ?></small></a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($recent): ?>
  <div class="d-flex align-items-baseline justify-content-between mt-4 mb-2">
    <h2 class="h3 m-0"><?= e(t('home.recent')) ?></h2>
    <a href="<?= e(url('gallery')) ?>"><?= e(t('home.all_pictures')) ?></a>
  </div>
  <div class="thumb-strip">
    <?php foreach ($recent as $img): ?>
      <a href="<?= e(url('gallery', ['open' => $img['id']])) ?>"><img src="<?= e(url('img', ['id' => $img['id'], 's' => 'thumb'])) ?>" alt="<?= e($img['caption']) ?>" loading="lazy"></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
