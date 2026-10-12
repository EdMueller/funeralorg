<?php
/** @var array $items @var array $albums @var array $images @var bool $isAdmin */
$pageTitle = t('st.title');
$wide = true;

// Zeitachse: erster bis letzter datierter Punkt, mit Rand
$stamps = array_values(array_filter(array_column($items, 'ts'), fn($t) => $t !== null));
$tMin = $stamps ? min($stamps) : 0;
$tMax = $stamps ? max($stamps) : 0;
$pos = function (int $ts) use ($tMin, $tMax): float {
    $f = $tMax > $tMin ? ($ts - $tMin) / ($tMax - $tMin) : 0.5;
    return round(6 + 88 * $f, 2);
};
$ticks = [];
if ($stamps && $tMax > $tMin) {
    $y0 = (int)gmdate('Y', $tMin);
    $y1 = (int)gmdate('Y', $tMax);
    $span = $y1 - $y0;
    $step = $span > 60 ? 10 : ($span > 25 ? 5 : ($span > 12 ? 2 : 1));
    for ($y = (int)(ceil(($y0 + 1) / $step) * $step); $y < $y1; $y += $step) {
        $ticks[$y] = $pos(gmmktime(0, 0, 0, 1, 1, $y));
    }
}

// Legende: je Farbe+Kategorie eine Zeile
$legend = [];
$withPos = 0;
foreach ($items as $it) {
    $legend[$it['color'] . '|' . $it['cat']] = ['color' => $it['color'], 'cat' => $it['cat']];
    $withPos += $it['lat'] !== null ? 1 : 0;
}
$json = array_map(function ($it) {
    unset($it['raw']);
    return $it;
}, $items);
?>
<div class="page-head">
  <div><h1><?= e(t('st.title')) ?></h1><p class="lead-note"><?= e(t('st.lead')) ?></p></div>
  <?php if ($isAdmin): ?>
    <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#stationModal" data-station="{}"><i class="bi bi-plus-lg me-1"></i><?= e(t('st.add')) ?></button>
  <?php endif; ?>
</div>

<section class="surface surface-pad mb-3" aria-label="<?= e(t('st.timeline')) ?>">
  <div class="tl-scroll">
    <div class="tl" id="timeline">
      <div class="tl-axis"></div>
      <?php foreach ($ticks as $y => $x): ?>
        <span class="tl-tick" style="left:<?= $x ?>%"><?= $y ?></span>
      <?php endforeach; ?>
      <?php foreach ($items as $it): if ($it['ts'] === null) continue; ?>
        <button class="tl-mark" type="button" data-sid="<?= e($it['id']) ?>" data-color="<?= e($it['color']) ?>"
                style="left:<?= $pos($it['ts']) ?>%;--c:<?= e($it['color']) ?>"
                <?= $it['lat'] === null ? 'title="' . e(t('st.no_place')) . '"' : '' ?>
                aria-label="<?= e($it['when'] . ': ' . $it['title'] . ($it['place'] ? ', ' . $it['place'] : '')) ?>">
          <span class="tl-dot"></span>
          <span class="tl-label"><b><?= e(substr($it['date'], 0, 4)) ?></b> <?= e($it['title']) ?><?php if ($it['place']): ?><small><?= e($it['place']) ?></small><?php endif; ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!$stamps): ?>
    <p class="text-muted small mb-0"><?= e(t('st.none_dated')) ?> <?= $isAdmin ? e(t('st.none_dated_hint')) : '' ?></p>
  <?php endif; ?>
  <div class="st-legend" id="legend">
    <?php foreach ($legend as $lg): ?>
      <button class="chip" type="button" data-legend="<?= e($lg['color']) ?>" aria-pressed="true"><span class="album-dot" style="background:<?= e($lg['color']) ?>"></span><?= e($lg['cat']) ?></button>
    <?php endforeach; ?>
  </div>
</section>

<section class="mb-3" aria-label="<?= e(t('st.map')) ?>">
  <div class="wmap" id="worldMap">
    <div class="wmap-view">
      <img class="wmap-bg" src="<?= e(asset('vendor/worldmap/world.svg')) ?>" alt="" draggable="false">
      <?php foreach ($items as $it): if ($it['lat'] === null) continue; ?>
        <button class="wpin" type="button" data-sid="<?= e($it['id']) ?>" data-color="<?= e($it['color']) ?>"
                data-lat="<?= e((string)$it['lat']) ?>" data-lon="<?= e((string)$it['lon']) ?>" style="--c:<?= e($it['color']) ?>"
                aria-label="<?= e($it['title'] . ($it['place'] ? ', ' . $it['place'] : '')) ?>"></button>
      <?php endforeach; ?>
    </div>
    <div class="wpop" id="wPop" hidden></div>
    <div class="wmap-ctl" role="group" aria-label="<?= e(t('st.map_view')) ?>">
      <button type="button" data-wz="in" aria-label="<?= e(t('st.zoom_in')) ?>"><i class="bi bi-plus-lg"></i></button>
      <button type="button" data-wz="out" aria-label="<?= e(t('st.zoom_out')) ?>"><i class="bi bi-dash-lg"></i></button>
      <button type="button" data-wz="fit" aria-label="<?= e(t('st.zoom_fit')) ?>"><i class="bi bi-bullseye"></i></button>
      <button type="button" data-wz="world" aria-label="<?= e(t('st.zoom_world')) ?>"><i class="bi bi-globe2"></i></button>
    </div>
  </div>
  <?php if (!$withPos): ?><p class="text-muted small mt-2 mb-0"><?= e(t('st.map_none')) ?></p><?php endif; ?>
</section>

<?php if ($isAdmin): ?>
<details class="surface surface-pad mb-3">
  <summary class="fw-semibold"><?= e(t('st.manage', ['n' => count($items)])) ?></summary>
  <div class="table-responsive mt-2">
    <table class="table table-sm align-middle mb-0">
      <thead><tr><th><?= e(t('st.col_date')) ?></th><th><?= e(t('st.col_station')) ?></th><th><?= e(t('st.col_place')) ?></th><th><?= e(t('st.col_cat')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td class="text-nowrap"><?= $it['when'] !== '' ? e($it['when']) : '<span class="text-warning-emphasis">' . e(t('st.missing')) . '</span>' ?></td>
          <td><?= e($it['title']) ?></td>
          <td><?= $it['place'] !== '' ? e($it['place']) : '' ?><?= $it['lat'] === null ? ' <span class="text-warning-emphasis small">' . e(t('st.no_pos')) . '</span>' : '' ?></td>
          <td><span class="album-dot" style="background:<?= e($it['color']) ?>"></span><?= e($it['cat']) ?></td>
          <td class="text-end"><button class="btn btn-sm btn-link" type="button" data-bs-toggle="modal" data-bs-target="#stationModal"
                data-station="<?= e(json_encode($it['raw'], JSON_UNESCAPED_UNICODE)) ?>" aria-label="<?= e(t('st.edit_aria', ['title' => $it['title']])) ?>"><i class="bi bi-pencil"></i></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small text-muted mb-0 mt-2"><?= e(t('st.manage_hint')) ?></p>
</details>

<div class="modal fade" id="stationModal" tabindex="-1" aria-labelledby="stationModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form class="modal-content" method="post" action="<?= e(url('stations', ['a' => 'save'])) ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="id">
      <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true"><?= e(t('st.save')) ?></button><!-- Enter speichert, nie „Löschen“ -->
      <div class="modal-header"><h2 class="modal-title h4" id="stationModalTitle"><?= e(t('st.modal_title')) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(t('common.close')) ?>"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-7" data-st-fixed><label class="form-label" for="st-title"><?= e(t('st.f_title')) ?></label>
            <input class="form-control" id="st-title" name="title" maxlength="120" required placeholder="<?= e(t('st.f_title_ph')) ?>"></div>
          <div class="col-md-5" data-st-fixed><label class="form-label" for="st-date"><?= e(t('st.f_date')) ?></label>
            <input class="form-control" id="st-date" name="date" maxlength="40" placeholder="<?= e(t('st.f_date_ph')) ?>">
            <div class="form-text"><?= e(t('st.f_date_hint')) ?></div></div>
          <div class="col-12"><label class="form-label" for="st-place"><?= e(t('st.f_place')) ?></label>
            <input class="form-control" id="st-place" name="place" maxlength="120" placeholder="<?= e(t('st.f_place_ph')) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label" for="st-lat"><?= e(t('st.f_lat')) ?></label>
            <input class="form-control" id="st-lat" name="lat" inputmode="decimal" placeholder="53.55"></div>
          <div class="col-6 col-md-3"><label class="form-label" for="st-lon"><?= e(t('st.f_lon')) ?></label>
            <input class="form-control" id="st-lon" name="lon" inputmode="decimal" placeholder="9.99"></div>
          <div class="col-md-6 d-flex align-items-end"><p class="small text-muted mb-0"><?= e(t('st.pick_hint')) ?></p></div>
          <div class="col-12"><div class="wmap wmap-pick" id="pickMap">
            <div class="wmap-view"><img class="wmap-bg" src="<?= e(asset('vendor/worldmap/world.svg')) ?>" alt="" draggable="false">
              <span class="wpin wpin-pick" hidden></span></div>
            <div class="wmap-ctl" role="group" aria-label="<?= e(t('st.map_view')) ?>">
              <button type="button" data-wz="in" aria-label="<?= e(t('st.zoom_in')) ?>"><i class="bi bi-plus-lg"></i></button>
              <button type="button" data-wz="out" aria-label="<?= e(t('st.zoom_out')) ?>"><i class="bi bi-dash-lg"></i></button>
              <button type="button" data-wz="world" aria-label="<?= e(t('st.zoom_world')) ?>"><i class="bi bi-globe2"></i></button>
            </div></div></div>
          <div class="col-md-6"><label class="form-label" for="st-album"><?= e(t('st.f_album')) ?></label>
            <select class="form-select" id="st-album" name="album"><option value=""><?= e(t('st.no_album')) ?></option>
              <?php foreach (Albums::walk($albums) as $n): $al = $n['album']; ?><option value="<?= e($al['id']) ?>"><?= e(str_repeat('– ', $n['depth']) . $al['name']) ?><?= !empty($al['cat']) ? ' (' . e($al['cat']) . ')' : '' ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label" for="st-image"><?= e(t('st.f_image')) ?></label>
            <select class="form-select" id="st-image" name="image"><option value=""><?= e(t('st.first_image')) ?></option>
              <?php foreach (array_reverse($images) as $im): ?><option value="<?= e($im['id']) ?>"><?= e($im['caption'] ?: $im['orig_name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><label class="form-label" for="st-text"><?= e(t('st.f_text')) ?></label>
            <textarea class="form-control" id="st-text" name="text" rows="3" maxlength="600"></textarea></div>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="submit" class="btn btn-outline-danger btn-sm" data-delete-station formaction="<?= e(url('stations', ['a' => 'delete'])) ?>" formnovalidate
                data-confirm-click="<?= e(t('st.delete_confirm')) ?>"><?= e(t('common.delete')) ?></button>
        <button type="submit" class="btn btn-primary"><?= e(t('st.save')) ?></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script type="application/json" id="stationsData"><?= json_encode(['items' => $json], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('js/stations.js')) ?>" defer></script>
