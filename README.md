# SolarFlow Portal

A solar energy company website and staff portal in plain PHP 8 + MySQL, built for shared hosting (Hostinger/cPanel).
Public site (solutions, shop, quotes) plus a staff portal (`/admin`) with roles,
CAPTCHA + TOTP 2FA, work orders, invoices/receipts (PDF via bundled Dompdf),
M-Pesa STK and WaafiPay EVC Plus payments.

## Secrets are not in this repository

`includes/config.php` ships with blank / `CHANGE_ME` values. Real values go in
`includes/config.local.php` on the server only (git-ignored):

```bash
cp includes/config.local.example.php includes/config.local.php
# then edit: db.pass, smtp.pass, cron_key, app_key
```

Generate keys with `openssl rand -hex 32`. Never change `app_key` after staff
have enrolled 2FA, or their authenticator secrets cannot be decrypted.
Payment keys (Daraja, WaafiPay), till and bank details are stored in the
database via Admin → Settings, not in files.

## Deploy

Upload the repository contents to `public_html`, add `config.local.php`, then run
`/install.php` once to create the owner account. `data/` and `uploads/` are
runtime folders and are not tracked. Check `/admin/health.php` after deploying.
