<?php
$pageTitle = t('gal.title');
$isAdmin = Auth::isAdmin();
$albumNames = array_column($albums, 'name', 'id');
$albumCats = array_column($albums, null, 'id');
$current = $album === '' ? t('gal.all_pictures') : ($album === '_none' ? t('gal.no_album') : ($albumNames[$album] ?? t('gal.album')));
$albumOptions = static function (array $flat, string $selected = '', array $skip = []): string {
    $h = '';
    foreach ($flat as $n) {
        $id = $n['album']['id'];
        $h .= '<option value="' . e($id) . '"' . ($id === $selected ? ' selected' : '') . (in_array($id, $skip, true) ? ' disabled' : '') . '>'
            . e(str_repeat('– ', $n['depth']) . $n['album']['name']) . '</option>';
    }
    return $h;
};
$linkBoxes = static function (array $flat, string $name = 'links[]'): string {
    $h = '';
    foreach ($flat as $n) {
        $h .= '<label class="form-check" style="padding-left:' . (1.75 + $n['depth'] * 1.1) . 'rem"><input class="form-check-input" type="checkbox" name="' . e($name)
            . '" value="' . e($n['album']['id']) . '"> <span class="form-check-label">' . e($n['album']['name']) . '</span></label>';
    }
    return $h ?: '<p class="small text-muted mb-0">' . e(t('gal.no_albums')) . '</p>';
};
$items = [];
foreach ($slides as $img) {
    $items[] = [
        'id'      => $img['id'],
        'src'     => url('img', ['id' => $img['id'], 's' => 'medium']),
        'dl'      => $canDl ? url('img', ['id' => $img['id'], 's' => 'orig']) : '',
        'caption' => $img['caption'],
        'album'   => $img['album'],
        'links'   => array_values($img['links'] ?? []),
        'login'   => !empty($img['login']),
        'alpha'   => !empty($img['alpha']),
        'edit'    => $isAdmin || ($img['uploaded_by_id'] ?? '') === $uid,
        'by'      => $img['uploaded_by'] ?? '',
        'at'      => fmt_date($img['uploaded_at'] ?? ''),
    ];
}
?>
<div class="page-head">
  <div><h1><?= e($current) ?></h1><p class="lead-note"><?= e(tn('gal.lead', count($images))) ?><?= count($slides) > count($images) ? e(t('gal.lead_subs', ['n' => count($slides)])) : '' ?></p></div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($slides): ?>
      <button class="btn btn-sm btn-primary" type="button" data-slideshow><i class="bi bi-play-fill me-1"></i><?= e(t('gal.slideshow')) ?></button>
    <?php endif; ?>
    <?php if ($canDl && $slides): ?>
      <div class="dropdown">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-download me-1"></i><?= e(t('gal.download')) ?></button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php if ($album !== ''): ?>
            <li><a class="dropdown-item" href="<?= e(url('gallery', ['a' => 'zip', 'what' => 'album', 'album' => $album])) ?>"><?= e(t($subs ? 'gal.zip_album_subs' : 'gal.zip_album')) ?></a></li>
          <?php endif; ?>
          <li><a class="dropdown-item" href="<?= e(url('gallery', ['a' => 'zip', 'what' => 'all'])) ?>"><?= e(tn('gal.zip_all', (int)$total)) ?></a></li>
          <li><hr class="dropdown-divider"></li>
          <li><button class="dropdown-item" type="button" data-select-all><?= e(t('gal.select_all')) ?></button></li>
        </ul>
      </div>
    <?php endif; ?>
    <?php if ($canUpload && $album !== '' && count($images) > 1): ?>
      <div class="btn-group btn-group-sm">
        <button class="btn btn-outline-primary" type="button" data-sort-toggle data-done="<?= e(t('gal.sort_done')) ?>"><i class="bi bi-arrows-move me-1"></i><span data-sort-label><?= e(t('gal.sort_mode')) ?></span></button>
        <button class="btn btn-outline-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-label="<?= e(t('gal.sort_by')) ?>"></button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><h6 class="dropdown-header"><?= e(t('gal.sort_by')) ?></h6></li>
          <?php foreach (['uploaded' => 'gal.sort_uploaded', 'uploaded_desc' => 'gal.sort_uploaded_desc', 'name' => 'gal.sort_name', 'caption' => 'gal.sort_caption'] as $by => $label): ?>
            <li><form method="post" action="<?= e(url('gallery', ['a' => 'imgsortby'])) ?>" data-confirm="<?= e(t('gal.sort_confirm')) ?>"><?= Csrf::field() ?>
              <input type="hidden" name="album" value="<?= e($album) ?>"><input type="hidden" name="by" value="<?= e($by) ?>">
              <button class="dropdown-item" type="submit"><?= e(t($label)) ?></button></form></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if ($canUpload): ?>
      <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#uploadBox"><i class="bi bi-cloud-upload me-1"></i><?= e(t('gal.upload')) ?></button>
    <?php endif; ?>
  </div>
</div>

<?php if ($trail || $album === '_none'): ?>
<nav aria-label="<?= e(t('gal.path')) ?>"><ol class="breadcrumb small mb-2">
  <li class="breadcrumb-item"><a href="<?= e(url('gallery')) ?>"><?= e(t('gal.crumb_all')) ?></a></li>
  <?php foreach ($trail as $k => $t): ?>
    <?php if ($k === count($trail) - 1): ?><li class="breadcrumb-item active" aria-current="page"><?= e($t['name']) ?></li>
    <?php else: ?><li class="breadcrumb-item"><a href="<?= e(url('gallery', ['album' => $t['id']])) ?>"><?= e($t['name']) ?></a></li><?php endif; ?>
  <?php endforeach; ?>
  <?php if ($album === '_none'): ?><li class="breadcrumb-item active" aria-current="page"><?= e(t('gal.no_album')) ?></li><?php endif; ?>
</ol></nav>
<?php endif; ?>
<nav class="album-bar" aria-label="<?= e(t('gal.albums')) ?>">
  <?php if ($album === ''): ?>
    <a href="<?= e(url('gallery')) ?>" class="active"><?= e(t('gal.crumb_all')) ?><small><?= $total ?></small></a>
  <?php endif; ?>
  <?php foreach ($subs as $al): ?>
    <a href="<?= e(url('gallery', ['album' => $al['id']])) ?>"><?php if (!empty($al['color'])): ?><span class="album-dot" style="background:<?= e($al['color']) ?>"></span><?php endif; ?><?= e($al['name']) ?><?= !empty($al['hidden']) ? ' <i class="bi bi-eye-slash" title="' . e(t('gal.hidden_title')) . '"></i>' : '' ?><?= ($al['parent'] ?? '') !== $album && $album !== '' ? ' <i class="bi bi-link-45deg" title="' . e(t('gal.link_title')) . '"></i>' : '' ?><small><?= $counts[$al['id']] ?? 0 ?></small></a>
  <?php endforeach; ?>
  <?php if ($album === '' && !empty($counts['_none']) && $albums): ?>
    <a href="<?= e(url('gallery', ['album' => '_none'])) ?>"><?= e(t('gal.no_album')) ?><small><?= $counts['_none'] ?></small></a>
  <?php endif; ?>
  <?php if ($isAdmin): ?>
    <span class="act-sep"></span>
    <a class="act" href="#" data-bs-toggle="modal" data-bs-target="#albumModal" data-album="<?= e(json_encode(['parent' => $album === '_none' ? '' : $album], JSON_FORCE_OBJECT)) ?>"><i class="bi bi-plus"></i> <?= e(t($album !== '' && $album !== '_none' ? 'gal.subalbum' : 'gal.album')) ?></a>
    <?php if ($album !== '' && $album !== '_none'): ?>
      <a class="act" href="#" data-bs-toggle="modal" data-bs-target="#albumModal" data-album="<?= e(json_encode(['id' => $album, 'name' => $albumNames[$album] ?? '', 'cat' => $albumCats[$album]['cat'] ?? '', 'color' => $albumCats[$album]['color'] ?? '', 'hidden' => !empty($albumCats[$album]['hidden']), 'parent' => $albumCats[$album]['parent'] ?? '', 'links' => $albumCats[$album]['links'] ?? [], 'exclude' => $subtree])) ?>"><i class="bi bi-pencil"></i> <?= e(t('gal.edit_album')) ?></a>
    <?php endif; ?>
  <?php endif; ?>
</nav>

<?php if ($canUpload): ?>
<div class="collapse mb-3" id="uploadBox">
  <form class="upload-zone" id="uploadForm" method="post" enctype="multipart/form-data" action="<?= e(url('gallery', ['a' => 'upload'])) ?>">
    <?= Csrf::field() ?>
    <p class="mb-2"><i class="bi bi-images fs-3 d-block"></i><?= e(t('gal.drop')) ?></p>
    <input class="form-control mb-2" type="file" name="files[]" multiple accept="image/jpeg,image/png,image/gif,image/webp" id="uploadFiles">
    <div class="d-flex flex-wrap gap-2 justify-content-center align-items-center">
      <label class="small" for="uploadAlbum"><?= e(t('gal.in_album')) ?></label>
      <select class="form-select form-select-sm w-auto" name="album" id="uploadAlbum">
        <option value=""><?= e(t('gal.no_album')) ?></option>
        <?= $albumOptions($flat, $album) ?>
      </select>
      <button class="btn btn-sm btn-primary" type="submit"><?= e(t('gal.upload')) ?></button>
    </div>
    <div class="progress mt-3 d-none" role="progressbar" aria-label="<?= e(t('gal.progress')) ?>"><div class="progress-bar" style="width:0%"></div></div>
    <div class="small mt-2" id="uploadMsg" role="status"></div>
    <p class="small text-muted mb-0 mt-2"><?= e(t('gal.upload_hint', ['mb' => rtrim(rtrim(number_format(upload_limit_bytes() / 1048576, 1, lang_dec(), ''), '0'), lang_dec())])) ?></p>
  </form>
</div>
<?php endif; ?>

<?php if (!$images): ?>
  <div class="empty surface"><i class="bi bi-images"></i><?= e(t($canUpload ? 'gal.empty_upload' : 'gal.empty')) ?></div>
<?php else: ?>
<div class="gallery-grid" id="galleryGrid" data-order-album="<?= e($album) ?>">
  <?php foreach ($images as $n => $img): $picked = in_array($img['id'], $selected, true); ?>
    <div class="g-item<?= $picked ? ' picked' : '' ?>" data-id="<?= e($img['id']) ?>">
      <button class="open" type="button" data-open="<?= $n ?>" title="<?= e(image_tip($img, $albumNames)) ?>" aria-label="<?= e(t('gal.open_pic') . ($img['caption'] ? ': ' . $img['caption'] : '')) ?>">
        <img src="<?= e(url('img', ['id' => $img['id'], 's' => 'thumb'])) ?>" alt="<?= e($img['caption']) ?>" loading="lazy">
      </button>
      <?php if ($canDl): ?>
        <button class="pick" type="button" data-pick="<?= e($img['id']) ?>" aria-pressed="<?= $picked ? 'true' : 'false' ?>" aria-label="<?= e(t('gal.add_to_sel')) ?>"><i class="bi bi-check-lg"></i></button>
      <?php endif; ?>
      <?php if ($canUpload && $album !== ''): ?>
        <span class="mv"><button type="button" data-move="-1" aria-label="<?= e(t('gal.to_front')) ?>"><i class="bi bi-chevron-left"></i></button><button type="button" data-move="1" aria-label="<?= e(t('gal.to_back')) ?>"><i class="bi bi-chevron-right"></i></button></span>
      <?php endif; ?>
      <?php if (!empty($img['animated'])): ?><span class="tag">GIF</span><?php endif; ?>
      <?php if ($isAdmin && (!empty($img['login']) || $img['id'] === $portrait)): ?><span class="tag" style="left:auto;right:.4rem;bottom:.4rem"><?= e(t($img['id'] === $portrait ? 'gal.tag_portrait' : 'gal.tag_login')) ?></span><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($canDl): ?>
<div class="cart-bar" id="cartBar" <?= $selected ? '' : 'hidden' ?>>
  <span><strong id="cartCount"><?= count($selected) ?></strong> <?= e(t('gal.selected')) ?></span>
  <a class="btn btn-sm btn-outline-light" href="<?= e(url('gallery', ['a' => 'cart'])) ?>"><?= e(t('gal.view')) ?></a>
  <a class="btn btn-sm btn-light" href="<?= e(url('gallery', ['a' => 'zip', 'what' => 'selection'])) ?>"><i class="bi bi-file-zip me-1"></i><?= e(t('gal.zip')) ?></a>
  <button class="btn btn-sm btn-light" type="button" data-bs-toggle="modal" data-bs-target="#moveModal"><i class="bi bi-folder-symlink me-1"></i><?= e(t('gal.move')) ?></button>
  <?php if ($isAdmin): ?>
    <form method="post" action="<?= e(url('gallery', ['a' => 'bulkdelete'])) ?>" data-confirm-count="confirm_delete_pics"><?= Csrf::field() ?>
      <button class="btn btn-sm btn-danger"><i class="bi bi-trash me-1"></i><?= e(t('gal.delete')) ?></button></form>
  <?php endif; ?>
</div>
<div class="modal fade" id="moveModal" tabindex="-1" aria-labelledby="moveModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <form class="modal-content" method="post" action="<?= e(url('gallery', ['a' => 'move'])) ?>">
      <?= Csrf::field() ?>
      <div class="modal-header"><h2 class="modal-title h5" id="moveModalTitle"><?= e(t('gal.move_title')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('common.close')) ?>"></button></div>
      <div class="modal-body"><label class="form-label" for="mv-album"><?= e(t('gal.target_album')) ?></label>
        <select class="form-select" id="mv-album" name="album"><option value=""><?= e(t('gal.no_album')) ?></option>
          <?= $albumOptions($flat) ?></select>
        <?php if (!$isAdmin): ?><div class="form-text"><?= e(t('gal.move_own')) ?></div><?php endif; ?></div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary"><?= e(t('gal.move_submit')) ?></button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Diashow -->
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="<?= e(t('gal.lightbox')) ?>" tabindex="-1">
  <div class="lb-top">
    <span class="lb-counter" id="lbCounter"></span>
    <div class="d-flex gap-2">
      <button class="lb-btn" type="button" id="lbPlay"><i class="bi bi-play-fill"></i> <?= e(t('gal.slideshow')) ?></button>
      <button class="lb-btn" type="button" id="lbFull" aria-label="<?= e(t('gal.fullscreen')) ?>"><i class="bi bi-arrows-fullscreen"></i></button>
      <button class="lb-btn" type="button" id="lbClose" aria-label="<?= e(t('common.close')) ?>"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>
  <div class="lb-stage" id="lbStage"><img alt=""><img alt=""></div>
  <button class="lb-nav lb-prev" type="button" id="lbPrev" aria-label="<?= e(t('gal.prev')) ?>"><i class="bi bi-chevron-left"></i></button>
  <button class="lb-nav lb-next" type="button" id="lbNext" aria-label="<?= e(t('gal.next')) ?>"><i class="bi bi-chevron-right"></i></button>
  <div class="lb-bottom">
    <div class="lb-caption" id="lbCaption"></div>
    <div class="lb-actions">
      <?php if ($canDl): ?>
        <button class="lb-btn" type="button" id="lbPick"><i class="bi bi-check-circle"></i> <?= e(t('gal.pick')) ?></button>
        <a class="lb-btn" id="lbDl" href="#"><i class="bi bi-download"></i> <?= e(t('gal.original')) ?></a>
      <?php endif; ?>
      <button class="lb-btn" type="button" id="lbEdit" hidden><i class="bi bi-pencil"></i> <?= e(t('gal.edit')) ?></button>
    </div>
  </div>
</div>
<script type="application/json" id="galleryData"><?= json_encode(['items' => $items, 'open' => (string)get('open'), 'play' => !empty($play)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>

<!-- Bild bearbeiten -->
<div class="modal fade" id="imgModal" tabindex="-1" aria-labelledby="imgModalTitle" aria-hidden="true" style="z-index:1070">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= e(url('gallery', ['a' => 'edit'])) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="id">
      <div class="modal-header"><h2 class="modal-title h4" id="imgModalTitle"><?= e(t('gal.edit_pic')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('common.close')) ?>"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label" for="im-cap"><?= e(t('gal.caption')) ?></label>
          <input class="form-control" id="im-cap" name="caption" maxlength="300"></div>
        <div class="mb-3"><label class="form-label" for="im-alb"><?= e(t('gal.album')) ?></label>
          <select class="form-select" id="im-alb" name="album"><option value=""><?= e(t('gal.no_album')) ?></option>
            <?= $albumOptions($flat) ?></select></div>
        <?php if ($albums): ?>
        <div class="mb-3" id="im-links"><span class="form-label d-block"><?= e(t('gal.also_in')) ?></span>
          <div class="border rounded p-2" style="max-height:10rem;overflow:auto"><?= $linkBoxes($flat) ?></div>
          <div class="form-text"><?= e(t('gal.also_hint')) ?></div></div>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="im-login" name="login" value="1">
            <label class="form-check-label" for="im-login"><?= e(t('gal.on_login')) ?></label>
            <div class="form-text"><?= e(t('gal.on_login_hint')) ?></div></div>
        <?php endif; ?>
        <p class="small text-muted mt-3 mb-0" id="im-meta"></p>
      </div>
      <div class="modal-footer justify-content-between">
        <?php if ($isAdmin): ?>
          <button class="btn btn-sm btn-outline-danger" type="submit" formaction="<?= e(url('gallery', ['a' => 'delete'])) ?>" formnovalidate
                  data-confirm-click="<?= e(t('gal.delete_pic_confirm')) ?>"><?= e(t('gal.delete_pic')) ?></button>
        <?php else: ?><span></span><?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= e(t('gal.save_pic')) ?></button>
      </div>
    </form>
  </div>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="albumModal" tabindex="-1" aria-labelledby="albumTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-album">
    <form class="modal-content" method="post" action="<?= e(url('gallery', ['a' => 'albumsave'])) ?>">
      <?= Csrf::field() ?><input type="hidden" name="id">
      <div class="modal-header"><h2 class="modal-title h5" id="albumTitle"><?= e(t('gal.album')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('common.close')) ?>"></button></div>
      <div class="modal-body"><div class="row g-4">
        <div class="col-md-6"><label class="form-label" for="al-name"><?= e(t('gal.album_name')) ?></label>
          <input class="form-control" id="al-name" name="name" required maxlength="100">
          <label class="form-label mt-3" for="al-parent"><?= e(t('gal.parent')) ?></label>
          <select class="form-select" id="al-parent" name="parent"><option value=""><?= e(t('gal.parent_none')) ?></option>
            <?= $albumOptions($flat) ?></select>
          <label class="form-label mt-3" for="al-cat"><?= e(t('gal.cat')) ?></label>
          <input class="form-control" id="al-cat" name="cat" maxlength="40" placeholder="<?= e(t('gal.cat_ph')) ?>">
          <div class="form-text"><?= e(t('gal.cat_hint')) ?></div>
          <label class="form-label mt-3" for="al-color"><?= e(t('gal.color')) ?></label>
          <select class="form-select" id="al-color" name="color"><option value=""><?= e(t('gal.color_none')) ?></option>
            <?php foreach (Albums::COLORS as $hex): ?><option value="<?= e($hex) ?>"><?= e(Albums::colorName($hex)) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="al-hidden" name="hidden" value="1">
          <label class="form-check-label" for="al-hidden"><?= e(t('gal.hide')) ?></label>
          <div class="form-text"><?= e(t('gal.hide_hint')) ?></div></div></div>
        <div class="col-md-6" id="al-links"><span class="form-label d-block"><?= e(t('gal.also_under')) ?></span>
          <div class="border rounded p-2" style="max-height:19rem;overflow:auto"><?= $linkBoxes($flat) ?></div>
          <div class="form-text"><?= e(t('gal.also_under_hint')) ?></div></div>
      </div></div>
      <div class="modal-footer justify-content-between flex-wrap">
        <button class="btn btn-primary order-3" type="submit"><?= e(t('gal.album_save')) ?></button>
        <span class="btn-group btn-group-sm order-2" data-album-order role="group" aria-label="<?= e(t('gal.order')) ?>">
          <button class="btn btn-outline-secondary" type="submit" formaction="<?= e(url('gallery', ['a' => 'albummove', 'dir' => 'up'])) ?>" formnovalidate><i class="bi bi-arrow-left"></i> <?= e(t('gal.to_front')) ?></button>
          <button class="btn btn-outline-secondary" type="submit" formaction="<?= e(url('gallery', ['a' => 'albummove', 'dir' => 'down'])) ?>" formnovalidate><?= e(t('gal.to_back')) ?> <i class="bi bi-arrow-right"></i></button>
        </span>
        <button class="btn btn-sm btn-outline-danger order-1" type="submit" data-album-delete formaction="<?= e(url('gallery', ['a' => 'albumdelete'])) ?>" formnovalidate
                data-confirm-click="<?= e(t('gal.album_delete_confirm')) ?>"><?= e(t('gal.album_delete')) ?></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
