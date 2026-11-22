# Old partner dashboard (/Dashboard) guard

Live since 22 Nov 2026: `public_html/Dashboard/djk_guard.php` is required at the very top of
`MyCompany/index.php` and `com_companies/{delete,edit,oldedit,create}.php`.

For logged-in partners (session `mycomp` or a `users_properties` row) it:
- blocks saving discounts (`action=save_deals_bulk`, `was`, `after`),
- keeps the "Active" switch at the value in the database (partners can't switch their page off),
- blocks the company create/edit/delete pages.

Description, opening hours, address, contact details and photos still work.
Originals of the five changed files: `/home/hcoluob1zfxp/djk_backups/` (outside the web folder).
