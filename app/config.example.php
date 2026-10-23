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

    // Long random secret that signs links in emails (verify, order). Generate once, NEVER change:
    //   php -r 'echo bin2hex(random_bytes(32));'
    'app_secret' => '',

    // First day of the new website: only orders from then on get "verify your card" reminders.
    'reminders_since' => '2026-12-01',

    // Stripe (Dashboard > Developers > API keys). Use test keys on the preview.
    'stripe_publishable_key' => '',
    'stripe_secret_key'      => '',
    'stripe_webhook_secret'  => '',  // Stripe > Webhooks > endpoint /stripe-webhook.php, event payment_intent.succeeded

    // Brevo transactional email
    'brevo_api_key' => '',
    'mail_from'     => 'contact@djakimita.com',
    'mail_from_name' => "Dj'aki mi ta!",

    // Preview lock: while set, the whole site asks for this login and is hidden
    // from search engines. Leave empty on launch day to go public.
    'preview_user' => 'preview',
    'preview_pass' => '',
];
