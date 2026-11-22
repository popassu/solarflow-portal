<?php
/*
 * Sunbeam Innovation Limited — server settings.
 * Only database, email and the site address live here. Everything else
 * (phone numbers, M-Pesa till, Daraja keys, payment details) is edited in
 * Admin → Settings.
 */
$config = [
    'site_name'     => 'Sunbeam Innovation Limited',
    'site_url'      => 'https://sunbeaminnovation.africa',   // CHANGE if you deploy to another domain/subdomain

    'mail_from'      => 'info@sunbeaminnovation.africa',     // must be a mailbox on this hosting account
    'mail_from_name' => 'Sunbeam Innovation Limited',
    'auto_reply'     => true,                                // email customers a "we got your request" note

    // Outgoing mail through the Hostinger mailbox (hPanel → Emails → info@ → Configuration)
    'smtp' => [
        'enabled' => true,
        'host'    => 'smtp.hostinger.com',
        'port'    => 465,
        'secure'  => 'ssl',                // 'ssl' for 465, 'tls' for 587
        'user'    => 'info@sunbeaminnovation.africa',
        'pass'    => '',          // CHANGE: the info@ mailbox password
    ],

    'db' => [
        'driver' => 'mysql',               // 'mysql' on Hostinger, 'sqlite' for local testing
        'host'   => 'localhost',
        'name'   => 'u671110825_sunbeam',  // Hostinger database (set)
        'user'   => 'u671110825_sunbeam',  // Hostinger database user (set)
        'pass'   => 'CHANGE_ME',           // CHANGE: the database password from hPanel (type it on the server only)
        'sqlite' => __DIR__ . '/../data/site.sqlite',
    ],

    // Secret for the scheduled-invoice cron URL: /cron.php?key=...
    'cron_key'      => 'CHANGE_ME_IN_CONFIG_LOCAL',
    // Encrypts staff authenticator secrets. Set once to a long random string and never change it afterwards.
    'app_key'       => 'CHANGE_ME_IN_CONFIG_LOCAL',

    'max_upload_mb' => 8,     // per photo
    'max_video_mb'  => 60,    // per video (also raise upload_max_filesize in hPanel → Advanced → PHP Configuration)
    'debug'         => false,
];

// Optional local overrides (not shipped): includes/config.local.php returning an array.
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace_recursive($config, require __DIR__ . '/config.local.php');
}

return $config;
