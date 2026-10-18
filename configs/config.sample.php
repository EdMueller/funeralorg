<?php
/**
 * Konfiguration – nach config.php kopieren und anpassen.
 * Liegt in configs/ – dieser Ordner wird per .htaccess gesperrt.
 * Passwörter stehen NICHT hier, der erste Administrator wird über die Einrichtungsseite (r=setup) angelegt.
 */
return [
    'app_name'             => 'In Erinnerung',
    'timezone'             => 'Europe/Berlin',

    // Datenverzeichnis. Möglichst außerhalb des Webroots; liegt es im Webroot,
    // schützen data/.htaccess und die Regeln in .htaccess den Zugriff (Apache).
    'data_dir'             => dirname(__DIR__) . '/data',

    // Öffentliche Basis-URL ohne index.php, z. B. 'https://example.com/beerdigung'.
    // Wird bei der Ersteinrichtung vorgeschlagen und in data/settings.json gespeichert;
    // ein hier gesetzter Wert hat Vorrang. Ohne Basis-URL werden keine Passwort-Reset-Mails
    // verschickt (der Link darf nicht aus dem Host-Header der Anfrage entstehen).
    'base_url'             => '',

    // Session
    'session_name'         => 'gdk_session',
    'session_idle_minutes' => 240,           // automatischer Logout nach Inaktivität

    // Login-Schutz
    'login_max_attempts'   => 5,             // Fehlversuche je IP …
    'login_window_minutes' => 15,            // … innerhalb dieses Zeitfensters
    'login_lock_minutes'   => 15,            // danach gesperrt für

    // Protokolle
    'log_full_ip'          => false,         // false = letztes Oktett / letzte 80 Bit entfernt
    'log_retention_months' => 12,

    // Backups (tägliche Snapshots der JSON-Daten)
    'backup_keep_days'     => 14,

    // Galerie
    'upload_max_mb'        => 40,
    'thumb_size'           => 480,           // längste Kante Vorschaubild
    'medium_size'          => 1800,          // längste Kante Großansicht / Diashow
    'jpeg_quality'         => 88,
    'image_max_pixels'     => 50000000,      // größere Bilder werden abgelehnt (0 = unbegrenzt)
    'plugins_safe_mode'    => false,         // true: kein Plugin-Code wird ausgeführt (Notausgang, siehe docs/plugin-development.md)
    'image_memory_limit'   => '768M',        // GD braucht bei großen Fotos viel RAM

    // Link zur Datenschutzerklärung (optional, wird auf den Seiten „Passwort vergessen“ angezeigt)
    // 'privacy_url' => 'https://www.example.de/datenschutz',

    // Absender für „Passwort vergessen“; ohne Eintrag: noreply@<Domain der Anwendung>
    // 'mail_from' => 'noreply@example.de',

    // Klebestreifen für Zugangsdaten (mm, A4); ohne Eintrag gelten die Standardformate aus app/lib/Access.php
    // 'access_formats' => [
    //     '2x6' => ['name' => '2 × 6 (99,1 × 42 mm)', 'cols' => 2, 'rows' => 6, 'w' => 99.1, 'h' => 42.0,
    //              'top' => 22.0, 'left' => 8.0, 'gap_x' => 0.0, 'gap_y' => 0.0],
    // ],

    // Etikettenformate (mm) für A4
    'label_formats' => [
        '3x8' => ['name' => '3 × 8 (70 × 37 mm)',     'cols' => 3, 'rows' => 8, 'w' => 70.0, 'h' => 37.0,
                  'top' => 0.5, 'left' => 0.0, 'gap_x' => 0.0, 'gap_y' => 0.0],
        '2x7' => ['name' => '2 × 7 (99,1 × 38,1 mm)', 'cols' => 2, 'rows' => 7, 'w' => 99.1, 'h' => 38.1,
                  'top' => 15.1, 'left' => 4.65, 'gap_x' => 2.5, 'gap_y' => 0.0],
        '2x6' => ['name' => '2 × 6 (99,1 × 42 mm)',   'cols' => 2, 'rows' => 6, 'w' => 99.1, 'h' => 42.0,
                  'top' => 22.0, 'left' => 8.0, 'gap_x' => 0.0, 'gap_y' => 0.0],
    ],
];
