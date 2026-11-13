# Security

What protects the platform, what you must set before going live, and what to do if a key leaks.
Written after the security review of the whole project (see the report in the pull request).

## What is in place

| Area | How |
|---|---|
| Sign-in | Sanctum tokens, one per school (tokens live in each school's own database); passwords hashed (bcrypt, `hashed` cast); one generic error for a wrong login (no account guessing) |
| Sign-in abuse | `login`: 5 tries a minute per school + login + IP and 30 per IP; forgot / reset / Super Admin code steps have their own limits; every failed sign-in is logged (`LoginMonitor`, no password, login only as a short hash) and one `alert` entry is written when one address fails 10 times in 15 minutes |
| Reset codes | 6 digits, 15 minutes, 5 tries, single use, all tokens revoked after the change |
| Sessions | A school sign-in token lasts 30 days (`SANCTUM_EXPIRATION`, in minutes), then the person signs in again; logout, a password change or a reset revokes it at once |
| Super Admin | 14+ character password, then an emailed code (10 minutes, 5 tries); 8-hour token |
| Rate limit | Every signed-in school route: 300 requests a minute per person (`school-api`); public routes have their own |
| Authorization | Role middleware and policies on the server; a parent only reaches their own children; schools are separate databases |
| SQL | Eloquent / query builder with bindings; no user text is concatenated into SQL |
| XSS | React escapes text. The one `dangerouslySetInnerHTML` (math in the question bank) is KaTeX output with `trust: false`, so it cannot make links, images or attributes |
| Uploads | Type (real content, not only the extension) and size checked; stored on a private disk, never in `public/` |
| Payments | The server decides the amount; the webhook is accepted only with a valid HMAC signature (`hash_equals`) |
| Headers | Every API answer: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, a locked-down `Content-Security-Policy`; `Strict-Transport-Security` over HTTPS |
| HTTPS | In production plain HTTP is redirected to HTTPS and links are built as `https` (`FORCE_HTTPS`, default on in production) |
| CORS | `config/cors.php`: only the websites in `CORS_ALLOWED_ORIGINS`; never with cookies |
| Debug | In production debug mode is forced off whatever the `.env` says (a `critical` log line is written) |

## Before going live

1. `.env` on the server: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, a new `APP_KEY`, real `DB_*` with a database user that can only touch this project's databases (plus create/drop for new schools), `MAIL_*`, `PAYTABS_*`, `DEFAULT_ACCOUNT_PASSWORD` long and random.
2. `TRUSTED_PROXIES` = your load balancer's address, otherwise every visitor looks like the same IP to the rate limits.
3. `SESSION_SECURE_COOKIE=true`. If the frontend is on another address: `CORS_ALLOWED_ORIGINS=https://…`.
4. Never put a secret in a `VITE_*` variable: everything with that prefix is shipped to the browser.
5. Send the **website** headers from the web server that serves the frontend (the API headers above are not enough for pages). A starting point for nginx, to be tried first with `Content-Security-Policy-Report-Only`:

```nginx
add_header Strict-Transport-Security "max-age=31536000" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
add_header Content-Security-Policy-Report-Only "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; media-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'" always;
```

   Add the API address to `connect-src` if it is on another address. Move to a real `Content-Security-Policy` once the browser console shows no violations on every screen.
6. Keep `.env`, `storage/`, backups and `.git` out of the web root (only `backend/public` and the built frontend are served).
7. Wire the `alert` log level to something you read (e-mail or chat channel in `config/logging.php`).
8. Run `composer audit` and `npm audit` before each release.

## If a key leaks

Rotate it at the provider first, then put the new one in the server's `.env` (never in git), then restart the workers.
- **APP_KEY**: new key invalidates stored encrypted values and sessions; do it only with a plan.
- **Mail password / app password**: Google account > Security > App passwords > delete and create again.
- **PayTabs server key**: PayTabs dashboard > regenerate; update the school's setting.
- **VAPID keys** (web push): `php artisan push:generate-keys`; every device must subscribe again.
- **Database password**: change it in MySQL, then in `.env`.
A key that was ever committed counts as leaked even after it is deleted from the code: the git history keeps it. The history of this repository was searched for common key formats (AWS, Stripe, GitHub, Slack, Google, SendGrid, private keys) and for `.env` files and found none; that was a pattern search, not a full `gitleaks` scan, so run `gitleaks detect` once before going live.
