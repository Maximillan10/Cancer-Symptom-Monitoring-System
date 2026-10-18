# How to run the tests

All commands are run from the project folder in Git Bash. PHP 8.3 or later is required (`composer.json` requires `^8.3`, as does Laravel 13).
Check your own version with `php -v` and record it in the validation document.

## 1. Tests that need no database
```bash
php tests/Scenarios/run.php                        # 55 hand-written scenarios, with accuracy figures
php tests/Scenarios/run.php --failed               # only the failures
php tests/Scenarios/cohort.php                     # simulated cohort, default seed 20260105
php tests/Scenarios/cohort.php --seed=1            # other seeds (1 to 4 were used in the report)
php tests/Scenarios/cohort.php --false-share       # adds the share of new alerts that were false alarms
php artisan test tests/Unit                        # unit tests of the alert rules
```

## 2. Full PHPUnit suite (needs PostgreSQL)
`phpunit.xml` uses a PostgreSQL database named `symptom_tracker_test`. Create it once in pgAdmin, then:
```bash
php artisan test
php artisan test --group=performance               # the single response-time test
```

## 3. Load test
Terminal 1 (Windows: PHP's built-in server runs one process there and does not support several workers):
```bash
php artisan serve --no-reload
```
On Linux or in Docker, `PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload` starts four workers.

Terminal 2 (needs an editor account and at least one patient; `php artisan demo:seed` creates demo patients):
```bash
php tests/load/load-test.php --email=EDITOR_EMAIL --password=EDITOR_PASSWORD --requests=100 --concurrency=1
php tests/load/load-test.php --email=EDITOR_EMAIL --password=EDITOR_PASSWORD --requests=100 --concurrency=5
```
It signs in once and prints p50, p95 and p99 per endpoint. It adds low-score check-ins (pain 3) to one patient, so use a
development database, or add `--no-writes`. With a single-process server, high concurrency (for example 50) mostly measures
queueing and timeouts: on the author's Windows laptop a 50-user run failed for that reason (see `docs/TEST_REPORT.md`). Wait about a
minute between runs: the login route allows 6 attempts a minute.

## 4. API (Postman)
Import `postman/symptom-tracker.postman_collection.json`, start the app with `php artisan serve`, and run the collection.
Wait a minute between runs for the same reason (login rate limit).
