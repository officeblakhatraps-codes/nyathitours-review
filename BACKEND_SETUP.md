# Nyathi Tours backend setup

The static website still works as a local prototype. For live shared trips, booking requests, invoices, reviews, gallery posts and secure owner accounts, the site needs PHP 8.1+ and MySQL/MariaDB on the web host.

## Configure the HostAfrica account

1. Create a MySQL database and database user in the HostAfrica control panel. Grant that user access to the database.
2. Import `nyathi-schema.sql`, then import `nyathi-seed.sql`. The starter trip capacity is blank on purpose; confirm each real seat limit with the owner before setting it.
3. Copy `nyathi-config-example.php` to `nyathi-config.php`. Fill in the database details, a random installer key of at least 40 characters, and the website root path for uploaded media. Do not publish `nyathi-config.php` or commit it.
4. Upload all website files over HTTPS. Allow PHP to write uploaded `media-*` files into the website root and keep `.htaccess` in place to protect the private config file.
5. While the installer key is configured, open `setup-accounts.html` over HTTPS and create one Owner account and one CTO account. Use different email addresses and strong passwords of at least 14 characters.
6. After the success message, clear `installer_key` in `nyathi-config.php` and remove `setup-accounts.html` from the web host. The database also limits accounts to one per role, so this setup creates the only two allowed accounts.

The owner login is checked by PHP using password hashes, protected sessions, a CSRF token, and login throttling. The website reads published trip data from MySQL, submits booking requests to MySQL, checks seat capacity again before the owner accepts a booking, and creates the invoice in the same database transaction. The local preview accepts mock sign-ins only on localhost; that bypass does not apply to the live domain.

## Client presentation on a static preview

For a GitHub Pages review, append `?demo=1&owner=1` to the site address to open the owner preview entry screen. Choose **Open demo owner dashboard**. This is a browser-only simulation: changes remain in that browser and are not sent to Nyathi Tours. When the configured PHP API is available, the demo entry is hidden and owner sign-in uses the real accounts.

## Payments and reminders

The schema has payment records and a reminder queue. Accepting a booking queues a branded booking email and a trip reminder one week before departure when the guest has supplied an email address. The CLI worker is `nyathi-send-reminders.php`; schedule it in HostAfrica’s cron/scheduled-task panel to run every 15 minutes after configuring and verifying the host email sender. It retries failed jobs up to three times. Payments are not processed yet: choose a provider and configure its server-side credentials before enabling payment links. Do not add merchant secrets to `app.js` or any public website file.

## Local limitations

This computer does not have PHP installed, so the PHP API cannot be executed in the local Python preview. The public site continues to show its browser-only demo data there. Once PHP and MySQL are configured on the host, the same front end automatically uses the shared API. Confirm backups and HTTPS are enabled before accepting real client information.
