<?php $a = (string)($_GET['a'] ?? ''); $tabs = ['' => t('admin.tab.overview'), 'users' => t('admin.tab.users'), 'groups' => t('admin.tab.groups'), 'shares' => t('admin.tab.shares'), 'requests' => t('admin.tab.requests'), 'log' => t('admin.tab.log'), 'backup' => t('admin.tab.backup')];
$openReq = count(Store::read('resets')['requests'] ?? []); ?>
<ul class="nav nav-underline mb-4 flex-nowrap overflow-auto" style="scrollbar-width:thin">
  <?php foreach ($tabs as $k => $lbl): ?>
    <li class="nav-item"><a class="nav-link text-nowrap<?= $a === $k ? ' active' : '' ?>" href="<?= e(url('admin', $k ? ['a' => $k] : [])) ?>"><?= e($lbl) ?><?= $k === 'requests' && $openReq ? ' <span class="badge text-bg-warning">' . $openReq . '</span>' : '' ?></a></li>
  <?php endforeach; ?>
</ul>
