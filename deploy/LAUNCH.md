# Launch on djakimita.com (GoDaddy cPanel)

Planned: 1 December 2026. Do it early in the morning (Curaçao time), when traffic is lowest.

## Before the day
- [ ] Client approved terms + privacy, filled in company details (legal name, KvK, address)
- [ ] Papiamentu texts checked
- [ ] Card validity set to 12 months in Dashboard > Website prices
- [ ] Stripe LIVE keys ready (new ones, the old secret key is leaked)
- [ ] New Brevo API key (the old one is leaked)
- [ ] cPanel: PHP version for the domain is 8.1 or newer (MultiPHP Manager), extensions pdo_mysql, gd, exif, curl on

## On the day
1. **Backup**: cPanel > Backup > full backup, download it. Also export the database (phpMyAdmin or Backup).
2. **Keep** these folders in `public_html` untouched: `Dashboard/`, `api/` (the app and the dashboard use them).
3. Move all other old files from `public_html` into a folder outside it, e.g. `~/old_site_2026-12-01/`
   (this also removes the public zip and the test files).
4. Upload the new site:
   - `app/` goes OUTSIDE public_html, e.g. `~/djakimita_app/app/` (never reachable from the web)
   - the contents of `public/` go INTO `public_html/`
   - in `public_html/*.php` the line `require __DIR__ . '/../app/bootstrap.php'` must point to the app folder;
     if `app/` sits at `~/djakimita_app/app`, put a `~/app` symlink, or place it at `~/app` directly.
5. Create `app/config.local.php` from `config.example.php`:
   - real database name/user and a NEW database password (cPanel > MySQL Databases > change password,
     then also update `Dashboard/include/config.php` and `api/config.php` with the new password)
   - `base_url` = `https://djakimita.com`
   - `app_secret` = new random value (`php -r 'echo bin2hex(random_bytes(32));'`), never change it afterwards
   - Stripe live keys, Brevo key, `admin_users` (hash: `php -r 'echo password_hash("...", PASSWORD_DEFAULT);'`)
   - `preview_pass` EMPTY (this makes the site public)
6. Run the database changes in phpMyAdmin, in order: `deploy/migrations/001 ... 004`. They only add new tables.
7. Make `app/storage/` and `app/cache/` writable (755 or 775).
8. Stripe dashboard > Developers > Webhooks: add `https://djakimita.com/stripe-webhook.php`,
   event `payment_intent.succeeded`, copy the signing secret into `stripe_webhook_secret`.
9. cPanel > Cron Jobs, once a day: `php ~/app/cron/reminders.php` (use the full path of the app folder).
10. Test: homepage in 3 languages, partners, a REAL purchase of the cheapest card (refund it in Stripe after),
    order email arrives, verify page with photos, /admin login and approve, login + my account, 404 page,
    old link `/purchase.php?plan=adult` forwards to checkout.
11. Revoke the old Stripe secret key and the old Brevo key.
12. GoDaddy DNS: delete the `new` record (the preview). Turn off the preview containers on the DigitalOcean server.
13. Google Search Console: submit `https://djakimita.com/sitemap.xml`.
