<?php
return [
    'mail.default_app' => 'Gedenkseite',
    'mail.reset_subject' => 'Passwort zurücksetzen – {app}',
    'mail.reset_body' => 'Hallo {name},

für dein Konto ({user}) wurde ein neues Passwort angefordert. Mit diesem Link kannst du es festlegen (60 Minuten gültig, nur einmal nutzbar):

{url}

Wenn du das nicht angefordert hast, kannst du diese Mail ignorieren. Dein Passwort bleibt dann unverändert.
',
    'mail.changed_subject' => 'Dein Passwort wurde geändert – {app}',
    'mail.changed_body' => 'Hallo {name},

das Passwort für dein Konto ({user}) wurde am {date} Uhr geändert.
Warst du das nicht, wende dich bitte umgehend an die Familie.
',
];
