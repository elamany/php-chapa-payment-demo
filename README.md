# Chapa PHP Demo

<p>
  <img src="https://img.shields.io/badge/PHP-8.1+-777BB4?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/MySQL-5.7+-4479A1?logo=mysql&logoColor=white" alt="MySQL">
  <img src="https://img.shields.io/badge/Apache-2.4-D22128?logo=apache&logoColor=white" alt="Apache">
  <img src="https://img.shields.io/badge/Chapa-API-1B7F5A?logo=stripe&logoColor=white" alt="Chapa">
  <img src="https://img.shields.io/badge/HTML5-E34F26?logo=html5&logoColor=white" alt="HTML5">
  <img src="https://img.shields.io/badge/CSS3-1572B6?logo=css3&logoColor=white" alt="CSS3">
  <img src="https://img.shields.io/badge/JavaScript-F7DF1E?logo=javascript&logoColor=black" alt="JavaScript">
  <img src="https://img.shields.io/badge/Font_Awesome-6.5-528DD7?logo=fontawesome&logoColor=white" alt="FontAwesome">
</p>

Minimal PHP + MySQL demo showing a full Chapa payment flow: init → hosted checkout → verify → persist.

## Features

- Chapa `transaction/initialize` and `transaction/verify`
- Persistent transaction log with idempotent status updates
- CSRF, throttling, prepared statements, escaped output
- Clean URLs (`/checkout`, `/process`, `/callback`, `/return`)
- External CSS/JS, FontAwesome icons

## Requirements

- PHP 8.1+ (`pdo_mysql`, `curl`, `mbstring`)
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite`, `mod_headers`
- Chapa account + secret key

## Clone project

```bash
git clone https://github.com/elamany/php-chapa-payment-demo.git
cd php-chapa-payment-demo
```

Then proceed to **Install** below.

## Install

1. **Clone and set up config**

   ```bash
   cp .env.example .env
   chmod 600 .env
   ```

   Open `.env` and fill in:

   ```dotenv
   CHAPA_SECRET_KEY=CHASECK_TEST-your_test_key_here
   BASE_URL=http://localhost/php-chapa-payment-demo
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=chapa_demo
   DB_USER=root
   DB_PASS=
   DB_CHARSET=utf8mb4
   ```

2. **Create the database**

   Create an empty database named `chapa_demo` (via phpMyAdmin, TablePlus, DBeaver, or your preferred tool), using charset `utf8mb4` / collation `utf8mb4_unicode_ci`.

3. **Import `schema.sql`**

   Import the `schema.sql` file from this repo into the `chapa_demo` database using your local DB tool:

   - **phpMyAdmin:** select `chapa_demo` → **Import** tab → choose `schema.sql` → **Go**
   - **TablePlus / DBeaver / HeidiSQL:** right-click the database → **Import SQL** → pick `schema.sql`
   - **CLI (optional):** `mysql -u root -p chapa_demo < schema.sql`

4. **Point your web server at the project folder**

   The folder containing `index.php`, `process.php`, `callback.php`, `return.php`, `config.php`, and `.htaccess`.

## Files

| File | Purpose |
|---|---|
| `index.php` | Payment form |
| `process.php` | Validate + init transaction, insert pending row |
| `callback.php` | Chapa server-to-server notification |
| `return.php` | Post-checkout status page |
| `config.php` | Env loader, DB, CSRF, Chapa client |
| `schema.sql` | `donations` table |
| `.htaccess` | Security + clean-URL rewrites |

## Flow

```
index.php ──POST──▶ process.php ──▶ Chapa /initialize
                                        │
                                        ▼
                                  Chapa checkout (user pays)
                                        │
                    ┌───────────────────┴───────────────────┐
                    ▼                                       ▼
              callback.php                              return.php
        (verify, set paid)                        (verify, render status)
```

Status: `pending → paid | failed`. Re-hits are no-ops.

## URLs

| Path | Handler |
|---|---|
| `/` | `index.php` |
| `/process` | `process.php` |
| `/callback` | `callback.php` |
| `/return?tx_ref=…` | `return.php` |

## Environment

| Key | Description |
|---|---|
| `CHAPA_SECRET_KEY` | Chapa API secret |
| `BASE_URL` | Public base URL (empty = auto-detect) |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` | MySQL |

## Test

```sql
SELECT tx_ref, chapa_ref, status, paid_at, amount, email
  FROM donations ORDER BY id DESC LIMIT 1;
```

After form submit → `pending`. After Chapa success → `paid` + `paid_at` set.

Local testing: expose callback via [ngrok](https://ngrok.com) and set `BASE_URL` to the tunnel URL or run locally in localhost.

## Security

- `.env` git-ignored and blocked by `.htaccess`
- CSRF token + `hash_equals`
- 3-second throttle between submits
- Server-side verify on every callback/return
- Prepared statements, `htmlspecialchars`, `nosniff` / `SAMEORIGIN`

## License

MIT