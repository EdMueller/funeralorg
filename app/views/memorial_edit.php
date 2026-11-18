<?php $pageTitle = t('memorial.edit_title'); ?>
<div class="page-head"><div><h1><?= e(t('memorial.edit_title')) ?></h1>
  <p class="lead-note"><?= e(t('memorial.edit_intro')) ?></p></div></div>
<form method="post" action="<?= e(url('home', ['a' => 'edit'])) ?>" class="surface surface-pad">
  <?= Csrf::field() ?>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="f-name"><?= e(t('memorial.name')) ?></label>
      <input class="form-control" id="f-name" name="name" value="<?= e($m['name']) ?>" placeholder="<?= e(t('memorial.name_ph')) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="f-born"><?= e(t('memorial.born')) ?></label>
      <input class="form-control" id="f-born" name="born" value="<?= e($m['born']) ?>" placeholder="<?= e(t('memorial.born_ph')) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label" for="f-died"><?= e(t('memorial.died')) ?></label>
      <input class="form-control" id="f-died" name="died" value="<?= e($m['died']) ?>" placeholder="<?= e(t('memorial.died_ph')) ?>"></div>
    <div class="col-12"><label class="form-label" for="f-place"><?= e(t('memorial.place')) ?></label>
      <input class="form-control" id="f-place" name="place" value="<?= e($m['place'] ?? '') ?>"></div>
    <div class="col-md-8"><label class="form-label" for="f-quote"><?= e(t('memorial.quote')) ?></label>
      <textarea class="form-control" id="f-quote" name="quote" rows="2"><?= e($m['quote']) ?></textarea></div>
    <div class="col-md-4"><label class="form-label" for="f-qb"><?= e(t('memorial.quote_by')) ?></label>
      <input class="form-control" id="f-qb" name="quote_by" value="<?= e($m['quote_by'] ?? '') ?>"></div>
    <div class="col-12"><label class="form-label" for="f-text"><?= e(t('memorial.text')) ?></label>
      <textarea class="form-control" id="f-text" name="text" rows="8"><?= e($m['text']) ?></textarea></div>
  </div>

  <h2 class="h4 mt-4"><?= e(t('memorial.frame')) ?></h2>
  <p class="text-muted small mb-2"><?= e(t('memorial.frame_hint')) ?></p>
  <?php
    $curFrame = Frame::key($m['frame'] ?? '');
    $selected = Portraits::images($m, $images);
    $thumb = $selected[0] ?? null;
  ?>
  <div class="frame-pick" role="radiogroup" aria-label="<?= e(t('memorial.frame')) ?>">
    <?php foreach (Frame::KEYS as $fk): ?>
      <label class="frame-opt">
        <input type="radio" name="frame" value="<?= e($fk) ?>" <?= $curFrame === $fk ? 'checked' : '' ?>>
        <span class="portrait-frame frame-<?= e($fk) ?>">
          <?php if ($thumb): ?>
            <img src="<?= e(url('img', ['id' => $thumb['id'], 's' => 'thumb'])) ?>" alt="">
          <?php else: ?>
            <span class="portrait-empty w-100 h-100" aria-hidden="true"></span>
          <?php endif; ?>
        </span>
        <span><?= e(t('memorial.frame.' . $fk)) ?></span>
      </label>
    <?php endforeach; ?>
  </div>

  <h2 class="h4 mt-4"><?= e(t('memorial.portrait')) ?></h2>
  <p class="text-muted small mb-2"><?= e(t('memorial.portrait_hint')) ?></p>
  <?php if (!$images): ?>
    <p class="text-muted"><?= t('memorial.upload_first', ['link' => '<a href="' . e(url('gallery')) . '">' . e(t('memorial.gallery_link')) . '</a>']) ?></p>
  <?php else: ?>
    <div data-portrait-picker data-max="<?= Portraits::MAX ?>"
         data-l-up="<?= e(t('memorial.pp_up')) ?>" data-l-down="<?= e(t('memorial.pp_down')) ?>" data-l-remove="<?= e(t('memorial.pp_remove')) ?>">
      <h3 class="h6 mb-2"><?= e(t('memorial.pp_selected')) ?></h3>
      <ol class="pp-list" data-pp-list>
        <?php foreach ($selected as $i => $img): ?>
          <li class="pp-item" data-id="<?= e($img['id']) ?>">
            <input type="hidden" name="portraits[]" value="<?= e($img['id']) ?>">
            <span class="pp-n"><?= $i + 1 ?></span>
            <img src="<?= e(url('img', ['id' => $img['id'], 's' => 'thumb'])) ?>" alt="">
            <span class="pp-btns">
              <button type="button" class="btn btn-sm btn-outline-secondary" data-act="up" aria-label="<?= e(t('memorial.pp_up')) ?>"><i class="bi bi-arrow-up"></i></button>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-act="down" aria-label="<?= e(t('memorial.pp_down')) ?>"><i class="bi bi-arrow-down"></i></button>
              <button type="button" class="btn btn-sm btn-outline-danger" data-act="remove" aria-label="<?= e(t('memorial.pp_remove')) ?>"><i class="bi bi-x-lg"></i></button>
            </span>
          </li>
        <?php endforeach; ?>
      </ol>
      <p class="text-muted small" data-pp-none <?= $selected ? 'hidden' : '' ?>><?= e(t('memorial.pp_none')) ?></p>
      <h3 class="h6 mt-3 mb-2"><?= e(t('memorial.pp_pick', ['max' => Portraits::MAX])) ?></h3>
      <div class="gallery-grid" style="grid-template-columns:repeat(auto-fill,minmax(96px,1fr))">
        <?php foreach ($images as $img): ?>
          <button type="button" class="g-item pp-add p-0 border-0" data-pp-add="<?= e($img['id']) ?>" data-thumb="<?= e(url('img', ['id' => $img['id'], 's' => 'thumb'])) ?>"
                  aria-pressed="false" aria-label="<?= e(t('memorial.pick_portrait')) ?>">
            <img src="<?= e(url('img', ['id' => $img['id'], 's' => 'thumb'])) ?>" alt="" loading="lazy">
            <?php if (!empty($img['animated'])): ?><span class="tag">GIF</span><?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
  <div class="mt-4 d-flex gap-2">
    <button class="btn btn-primary" type="submit"><?= e(t('memorial.save')) ?></button>
    <a class="btn btn-outline-secondary" href="<?= e(url('home')) ?>"><?= e(t('common.cancel')) ?></a>
  </div>
</form>
<script src="<?= e(asset('js/portrait-edit.js')) ?>"></script>
