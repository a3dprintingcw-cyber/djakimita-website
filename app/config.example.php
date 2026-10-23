<?php
// Copy this file to app/config.local.php and fill in the real values.
// config.local.php is in .gitignore: real passwords and keys NEVER go in git.

return [
    // Database (cPanel: MySQL Databases)
    'db_host' => 'localhost',
    'db_name' => 'ab1202_deals_discounts',
    'db_user' => '',
    'db_pass' => '',

    // Full public address of the site, no trailing slash
    'base_url' => 'https://djakimita.com',

    // Which program this site shows (1 = Dj'aki mi ta! / Curaçao)
    'country_id' => 1,

    // Stripe (Dashboard > Developers > API keys). Use test keys on the preview.
    'stripe_publishable_key' => '',
    'stripe_secret_key'      => '',
    'stripe_webhook_secret'  => '',

    // Brevo transactional email
    'brevo_api_key' => '',
    'mail_from'     => 'contact@djakimita.com',
    'mail_from_name' => "Dj'aki mi ta!",

    // Preview lock: while set, the whole site asks for this login and is hidden
    // from search engines. Leave empty on launch day to go public.
    'preview_user' => 'preview',
    'preview_pass' => '',
];
