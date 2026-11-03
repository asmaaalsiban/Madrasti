# Load testing

A small, dependency-free load test for one school, plus a tool that grows a school to a realistic size. It answers: *how many people can one server carry, which endpoint is slow, and which endpoint runs too many queries?*

## Run it

```bash
# 1. A school with data (the demo school) and the servers running as usual.
# 2. Grow it: 50 students per section, 60 school days of attendance, notifications, audit log... and write sign-in tokens.
cd backend
php artisan tenants:run loadtest:seed --tenants=demo --option=per-class=50 --option=tokens=../loadtest/tokens.json

# 3. Run the API like production would (no debug, query counters on, several workers):
APP_DEBUG=false QUERY_STATS=true PHP_CLI_SERVER_WORKERS=8 php -d opcache.enable_cli=1 -S 127.0.0.1:8000 -t public
#    For the best numbers also run: php artisan optimize

# 4. Load it: 200 open browser tabs for a minute; each person opens a screen every ~4 seconds.
node loadtest/run.mjs --tokens loadtest/tokens.json --users 200 --duration 60 --think 4000
```

`run.mjs` options: `--base --school --users --duration --ramp --think --polling badges|old|none --max-p95 --max-errors --json`. It prints, for every endpoint, the calls, errors, p50/p95/max latency, **the average and maximum number of database queries** and the time spent in them (needs `QUERY_STATS=true`, which adds `X-Queries` / `X-Query-Ms` to API answers; never enabled in production). It exits with an error when more than `--max-errors` % of the calls fail or an endpoint is slower than `--max-p95`, so it can run in CI.

`loadtest:seed` never runs in production. Run it against a throw-away school. **Never run the PHP test suite with the demo environment variables (`DB_DATABASE=...`): the tests wipe the database they are pointed at.**

## What we found (4 cores, 16 GB, PHP 8.3 built-in server with 8 workers, SQLite, the load generator on the same machine)

School size: 600 students, 960 accounts, 36 000 attendance rows, 20 000 scores, 17 000 notifications, 50 000 audit-log rows.

| Finding | Before | After |
|---|---|---|
| **Idle tabs were the biggest load.** Every open tab asked 4 questions in the background (chat every 10 s, announcements, notifications, surveys): 11 requests a minute per tab, even if nobody touched it. 1000 open tabs = 183 requests/s of nothing. | 183 req/s per 1000 tabs | **33 req/s** (one `/api/badges` every 30 s) |
| Same 200 tabs + a click every 4 s | 85 req/s | **62 req/s** (-28 %) |
| `/api/payroll/commitment` (My salary) ran a query or two for every working day of the month | 55 queries | **15 queries** (and a test keeps it there) |
| `php artisan optimize` (config + route cache), 50 tabs | p50 165 ms, p95 383 ms | p50 92 ms, p95 262 ms |
| Every other endpoint | 1 to 13 queries each, database time 0.1 to 15 ms | no change needed |
| 1 request, no load | 19 ms for `/school`, 23 to 34 ms for the usual screens (almost all PHP start-up, not the database) | |
| Capacity of this machine | about 150 requests/s before latency climbs; 400 open tabs (a click every 8 s) p95 131 ms, 0 errors | |
| One login with bcrypt cost 12 | 244 ms of CPU (cost 10: 59 ms) | |

### What it means
- One 4-core machine carries a school of 600 to 1000 students comfortably: about 400 open tabs with p95 of 130 ms. Several schools can share a machine, but plan about 150 requests/s of capacity per 4 cores.
- A morning rush of logins costs real CPU: 300 people signing in within a minute is about 75 CPU-seconds with cost 12. Keep sessions long (tokens already are), and consider cost 10 or 11 if the morning rush hurts.
- The next big win is **not** more SQL tuning (queries are cheap). It is the PHP start-up on every request: enable OPcache (`validate_timestamps=0`), run `php artisan optimize` on every deploy, use PHP-FPM with `pm.max_children` about 2 to 4 times the cores, and consider **Laravel Octane** (RoadRunner/Swoole) to boot the app once instead of on every request.
- Chat polling every 10 s on the chat page itself is the next thing to move to Reverb (push) when chat is used a lot.
- These are SQLite numbers on a shared machine: repeat the run on the real MySQL server before the first school goes live.
