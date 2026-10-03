<?php
// Application settings. Override any value in config/app.local.php (not committed).
return [
    'name'      => 'Civil Registry',
    'office'    => 'Civil Registration Office, Mogadishu Municipal Government',
    'region'    => 'Banadir Region, Somalia',
    'timezone'  => 'Africa/Mogadishu',
    'base_url'  => '/civil-registration/public',   // URL path of the public/ folder
    'debug'     => false,                          // never true in production
    'session'   => ['name' => 'crs_session', 'lifetime' => 1800],   // seconds of inactivity
    'security'  => ['max_failed_logins' => 5, 'lockout_minutes' => 15, 'min_password_length' => 6],
];
