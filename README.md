# Dj'aki mi ta! · djakimita.com (new website)

New public website for Deals With Discounts / Dj'aki mi ta!.
Plain PHP 8 + the existing MySQL/MariaDB database, so it runs on the current
GoDaddy cPanel hosting and keeps working with the mobile app.

## Structure

```
app/            not public: config, database, translations, layout
  config.example.php   copy to config.local.php and fill in (never commit it)
  cache/               resized partner photos (auto-created, not in git)
public/         the web root (public_html on cPanel)
  index.php            homepage
  partners.php         partner directory with search and categories
  partner.php          partner detail page
  img.php              serves partner photos from the database, resized + cached
  assets/              css and images
```

## Run locally

```
cp app/config.example.php app/config.local.php   # fill in database details
php -S 127.0.0.1:8090 -t public
```

## Preview lock

While `preview_pass` is set in `config.local.php`, the whole site asks for a
login and tells search engines not to index it. On launch day: empty it.

## Languages

English, Dutch and Papiamentu in `app/i18n.php`. Papiamentu texts should be
checked by a native speaker before launch.

## Security rules

- No passwords or API keys in this repository. They live in `app/config.local.php`.
- All database queries use prepared statements.
- PHP errors are logged, never shown to visitors.
