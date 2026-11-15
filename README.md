# John Rey Simple Billing System

Requires PHP 8.1+ with PDO SQLite. From this directory, choose the administrator password, then start the local server.

```sh
/Applications/XAMPP/xamppfiles/bin/php admin.php
/Applications/XAMPP/xamppfiles/bin/php -S 127.0.0.1:8787 -t public
```

Open http://127.0.0.1:8787. Add a client, create their loan, and enter the monthly amount in Philippine pesos plus the first payment date and payment term in months. The first payment counts as month one, and the final due date is calculated automatically. Each installment falls on the same day of the month as the first payment, or the last day of a shorter month. Older loans show month and year until an administrator sets their payment day. Click its status to mark it paid or unpaid. Editing a schedule preserves the amounts of paid installments.

Use **Share link** on the loan to copy the borrower’s read-only statement. Anyone with that specific link can view that loan.

For remote borrowers, serve the public directory at a reachable HTTPS address and copy links from that address. Set BILLING_DATA_DIR to the same persistent, private directory outside the web root for both the web server and admin.php. Give the web server write access there. The default database lives under the running user's ~/.local/share when HOME is available. Run admin.php again to change the password and invalidate existing admin logins.
