<?php

/*
|--------------------------------------------------------------------------
| tdh_user integration
|--------------------------------------------------------------------------
| Users, their sections (this app's "departments") and their per-system
| privileges live in the hospital's shared tdh_user database, read live
| and never written. See docs/superpowers/specs/2026-09-24-tdh-user-integration-design.md.
*/

return [
    // Connection name in config/database.php that points at tdh_user.
    'connection' => 'user',

    // This system's static code in tdh_user.user_priv.syscode (varchar(20)).
    // Rows for other systems (hris, dtr, ...) are ignored.
    'syscode' => 'IR',
];
