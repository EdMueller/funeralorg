<?php
return [
    'mail.default_app' => 'Memorial page',
    'mail.reset_subject' => 'Reset your password – {app}',
    'mail.reset_body' => 'Hello {name},

a new password was requested for your account ({user}). You can set it with this link (valid for 60 minutes, usable once):

{url}

If you did not request this, you can ignore this email. Your password will remain unchanged.
',
    'mail.changed_subject' => 'Your password was changed – {app}',
    'mail.changed_body' => 'Hello {name},

the password for your account ({user}) was changed on {date}.
If this was not you, please contact the family immediately.
',
];
