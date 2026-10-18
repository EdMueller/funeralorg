<?php
declare(strict_types=1);

return function (PluginApi $p): void {
    $p->route('stations', 'controllers/stations.php', 'ctrl_stations');
    $p->nav('stations', 'nav.stations', 'bi-geo-alt', fn() => Perm::memorial(), 'home');
};
