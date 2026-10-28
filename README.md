# Nyathi Tours website

All website files are placed directly in this folder; there are no nested folders. The image files, SQL files, PHP API, and reminder worker use root-level paths.

## GitHub Pages client demo

After publishing this repository with GitHub Pages, share the site URL with `?demo=1&owner=1` appended. The demo owner dashboard is a browser-only simulation; edits are saved in that browser and are not shared with Nyathi Tours.

## Live hosting

GitHub Pages does not run PHP or MySQL. To activate secure owner accounts, shared bookings, invoices, and reminders, upload these same files to a PHP/MySQL host and follow `BACKEND_SETUP.md`. Keep `nyathi-config.php` private; create it from `nyathi-config-example.php` on the server.