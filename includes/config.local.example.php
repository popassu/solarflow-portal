<?php
/*
 * Copy this file to config.local.php (same folder) and fill in the real values.
 * config.local.php is never included in the website zip, so uploading a new
 * version of the site will not wipe your passwords or keys.
 */
return [
    'db' => [
        'pass' => 'TYPE_THE_DATABASE_PASSWORD_HERE',
    ],
    'smtp' => [
        'pass' => 'TYPE_THE_INFO_MAILBOX_PASSWORD_HERE',
    ],
    'cron_key' => 'A_LONG_RANDOM_STRING',
    'app_key'  => 'ANOTHER_LONG_RANDOM_STRING_SET_ONCE_AND_NEVER_CHANGE',
    // 'debug' => true,   // show errors on screen while testing; remove afterwards
];
