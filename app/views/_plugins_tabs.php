<?php /** @var string $ptab */ ?>
<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link<?= $ptab === 'installed' ? ' active' : '' ?>" href="<?= e(url('admin', ['a' => 'plugins'])) ?>"><i class="bi bi-puzzle me-1"></i><?= e(t('market.tab_installed')) ?></a></li>
  <li class="nav-item"><a class="nav-link<?= $ptab === 'search' ? ' active' : '' ?>" href="<?= e(url('admin', ['a' => 'pluginsearch'])) ?>"><i class="bi bi-search me-1"></i><?= e(t('market.tab_search')) ?></a></li>
</ul>
