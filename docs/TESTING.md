# How to run the tests

All commands from the project folder (`C:\dev\symptom-tracker`) in Git Bash.

## 1. Install the files
Copy the **contents** of this package into the project, keeping the folder structure (`app/`, `tests/`, `docs/`).
Do not copy the `testing-validation` folder itself into the project, or you will get `app/app/...` problems again.
Files that already exist (`AlertRules.php`, `AlertEngine.php`, `AlertThreshold.php`) are replaced by the new versions.

```bash
composer dump-autoload
```

The scenario files live in `tests/Scenarios/` with namespace `Tests\Scenarios`. Laravel's default `composer.json` maps `Tests\` to `tests/`, so no change is needed. If the classes are not found, check `autoload-dev` in `composer.json`.

## 2. Tests that need no database (fast)
```bash
php tests/Scenarios/run.php                 # 55 scenarios, table + accuracy
php tests/Scenarios/run.php --failed        # only failures
php tests/Scenarios/cohort.php --markdown   # threshold comparison table for the report
php artisan test tests/Unit
```

## 3. Full Laravel test suite (uses an in-memory sqlite database, not your PostgreSQL data)
```bash
php artisan test
```
Needs `pdo_sqlite` enabled in XAMPP's `php.ini`. Check with: `php -r "echo extension_loaded('pdo_sqlite') ? 'ok' : 'MISSING';"`.
If tests fail, send me the first failure and I will work out whether the test or the app is wrong.

Performance smoke test only: `php artisan test --group=performance`

## 4. Load test against the real app on PostgreSQL
Terminal 1:
```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve
```
Terminal 2 (needs a clinician with at least one patient, e.g. from the demo seeder):
```bash
php tests/load/load-test.php --email=you@example.com --password=yourpassword --requests=300 --concurrency=10
```
It signs in once and prints p50, p95 and p99 per endpoint, and exits 0 only if p95 is under 500 ms everywhere with no failures.
It adds low-score check-ins (pain 3) to one patient; use `--no-writes` to avoid that. Run it on your development database, not on anything you care about.
If you see HTTP 429, the API is rate limiting; those requests are reported separately.

## Known risk when running on sqlite
The alert-system migration that allows two rules per symptom changes an index on `alert_thresholds`. If it behaves differently on sqlite, `php artisan test` will fail on migration for every feature test at once. If that happens, send me the error; the fix is either in that migration or to point `phpunit.xml` at a separate PostgreSQL test database (`symptom_tracker_test`).

## After the f03 / f07 fixes
- Run `php artisan migrate` once (it lowers any saved absolute limit of 10 to 9).
- Your own earlier test "finding f07 resending the same checkin stores it twice" asserts the OLD behaviour and will now fail. Delete that one test method (find it with `grep -rn "f07" tests`). `CheckInIdempotencyTest` replaces it.
- Your test "finding f03 configured limit above 9 lets a critical score through" still passes: it writes the limit straight into the database, which the engine still honours. Rename it if you like; `ThresholdLimitTest` covers the form.
