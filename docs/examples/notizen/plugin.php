<?php
declare(strict_types=1);

return function (PluginApi $p): void {
    $p->route('notizen', 'controllers/notizen.php', 'ctrl_notizen');
    $p->nav('notizen', 'nz.nav', 'bi-sticky', fn() => Perm::memorial(), 'checklist');
    // Beispiel für eine Datenmigration nach einem Marktplatz-Update: $von = vorherige Version
    $p->onUpdate(function (string $von): void {
        Log::add('admin', 'Notizen aktualisiert von ' . $von);
    });
};
