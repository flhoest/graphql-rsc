<?php

declare(strict_types=1);

/*
 * Copy this file to rsc-config.php and replace the placeholder values.
 * Never commit rsc-config.php because it contains a client secret.
 */
return [
    'rsc_url' => 'https://your-tenant.my.rubrik.com',
    'client_id' => 'YOUR_SERVICE_ACCOUNT_CLIENT_ID',
    'client_secret' => 'YOUR_SERVICE_ACCOUNT_CLIENT_SECRET',
    'connect_timeout' => 30,
    'request_timeout' => 120,
    'verify_ssl' => true,

    /*
     * Keep this true unless you deliberately want to submit a live,
     * destructive VMware in-place recovery mutation.
     */
    'restore_dry_run' => true
];
