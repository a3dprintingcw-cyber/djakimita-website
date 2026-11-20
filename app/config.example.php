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
    'reminders_since' => '2026-11-20',

    // Mollie (Dashboard > Developers > API keys). When set, Mollie is used instead of Stripe.
    // Webhook needs no setup: every payment tells Mollie to call /mollie-webhook.php.
    'mollie_api_key' => '',

    // Sentoo (merchant portal > merchant account > General info). When set, customers can pay
    // in guilders (XCG) from their local bank. Set the "Payment status URL" in Sentoo to
    // https://djakimita.com/sentoo-webhook.php
    'sentoo_merchant_id' => '',
    'sentoo_secret' => '',
    'sentoo_currency' => 'XCG',

    // Stripe (Dashboard > Developers > API keys). Use test keys on the preview.
    'stripe_publishable_key' => '',
    'stripe_secret_key'      => '',
    'stripe_webhook_secret'  => '',  // Stripe > Webhooks > endpoint /stripe-webhook.php, event payment_intent.succeeded

    // Brevo transactional email
    'brevo_api_key' => '',
    'mail_from'     => 'contact@djakimita.com',
    'mail_from_name' => "Dj'aki mi ta!",

    // Countdown: until this moment (Curaçao time) djakimita.com shows the countdown page with a
    // pre-order button; checkout, payment, login and legal pages stay open. Then the full site
    // opens by itself. Empty = no countdown.
    'launch_at' => '2026-12-01 00:00:00',
    // Secret word so staff can see the full site early: djakimita.com/?peek=<word>
    'countdown_peek' => '',

    // Preview lock: while set, the whole site asks for this login and is hidden
    // from search engines. Leave empty on launch day to go public.
    'preview_user' => 'preview',
    'preview_pass' => '',
];
