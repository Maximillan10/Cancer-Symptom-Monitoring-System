# System Testing & Validation: how to run it

Written against your actual code (`AlertEngine`, `AlertRules`, `SymptomSubmissionObserver`, `ScanAlerts`, the models and routes you sent).

## 1. Copy the files in (carefully)

Open the extracted `validation-pack` folder. Select the three folders **inside** it (`tests`, `scripts`, `docs`) and paste them into `C:\dev\symptom-tracker`, the folder that contains `artisan`. Choose **Merge** when asked. Do not paste the `validation-pack` folder itself.

Check in Git Bash:

```bash
cd /c/dev/symptom-tracker
ls tests/Support tests/Feature tests/Unit scripts
find . -path ./vendor -prune -o -type d \( -path "*/tests/tests" -o -path "*/scripts/scripts" -o -path "*/docs/docs" \) -print   # must print nothing
composer dump-autoload
```

You should see `AlertScenarios.php` and `ClinicalFixtures.php` in `tests/Support`. These files only add tests and a script, and overwrite nothing of yours, unless you already have files with the same names.

## 2. WARNING: use a test database

The feature tests use `RefreshDatabase`, which **wipes the configured database**. Laravel's default `phpunit.xml` points tests at in-memory SQLite, which is safe. If your migrations use PostgreSQL-only SQL (for example partial unique indexes), SQLite will fail to migrate. In that case:

1. Create a separate database, e.g. `cancer_tracker_test`, in PostgreSQL.
2. In `phpunit.xml`, set `DB_CONNECTION=pgsql` and `DB_DATABASE=cancer_tracker_test` (and host, user, password).

Never point the tests at your development database.

## 3. Run

```bash
php artisan test --filter=AlertRulesBoundaryTest        # pure rule logic
php artisan test --filter=AlertScenarioValidationTest   # 25 scenarios + accuracy gate
php artisan test --filter=AlertEngineBehaviourTest      # engine, observer, alerts:scan
php artisan test --filter=AccessControlTest             # security
php artisan test tests/Feature/Api                      # auth, patients, symptom-submission endpoints
php artisan test                                        # everything
php artisan test --coverage --min=80                    # needs Xdebug or PCOV installed
```

The scenario test writes `storage/app/validation/scenario-results.md` and `.json`. Paste the table into section 4 of `docs/TEST_REPORT.md`.

## 4. Load test (≤ 300 ms average, 10 concurrent users)

Start the app, then in a second terminal:

```bash
php artisan serve
php scripts/load-test.php --email=YOUR_CLINICIAN_EMAIL --password=YOUR_PASSWORD
# or with an existing Sanctum token:
php scripts/load-test.php --token="1|abc..." --requests=300 --concurrency=10 --endpoint=/api/patients --endpoint=/api/me
```

Notes:
- `php artisan serve` is single-process on Windows, so 10 simultaneous users queue and the numbers look worse than a real server. For the official run, serve the app through XAMPP Apache, or state this limitation in the report.
- Seed realistic data first (dozens of patients with a month of check-ins) so `/api/patients` is not trivially fast.
- Results are saved to `storage/app/validation/load-test.json`. The script exits non-zero when any endpoint fails.
- If your login route or response differs, use `--login-path` or `--token`.

## 5. What each test file proves

| File | Purpose |
|---|---|
| `Unit/AlertRulesBoundaryTest` | Exact boundaries of every rule (6 vs 7, rise 2 vs 3, baseline 0, escalate vs skip) |
| `Feature/AlertScenarioValidationTest` | 25 simulated patients with hand-derived expected alerts; accuracy, recall, critical recall |
| `Feature/AlertEngineBehaviourTest` | Window edge, per-clinician thresholds, resolve, scan command, observer failure safety, plus the findings F-02 to F-05 |
| `Feature/AccessControlTest` | Guests refused; clinicians isolated from each other's patients and alerts |
| `Feature/Api/AuthEndpointsTest` | Register/login/logout/me: validation, hashing, no self-registration as admin, 401 for bad logins, rate limit |
| `Feature/Api/PatientEndpointsTest` | CRUD, per-clinician scoping, admin assignment rules, page-size clamp, validation, cascade delete |
| `Feature/Api/SymptomSubmissionEndpointsTest` | Mobile sync endpoint: patient lookup, 0-10 validation, partial scores, future timestamps, access control, API → alert end to end, history filters |
| `Support/AlertScenarios` | The scenario data. Add your own by copying a line, working out the expected alerts **by hand first**, then running the suite |

Tests marked FINDING assert today's behaviour on purpose. If you fix the issue, update that test.

## 6. Notes on the API tests

- The patient search test uses PostgreSQL's `ILIKE` (as your controller does), so it is skipped on SQLite and runs on a PostgreSQL test database.
- `test_destroy_removes_the_patient_and_their_data` expects the database to cascade-delete a patient's check-ins and alerts. If it fails with a foreign-key error, add `cascadeOnDelete()` to those foreign keys (or delete the children in the controller).
- Tests that depend on your authorization policies (`viewAny`, `create`, `view`, `update`, `delete` on `Patient`) expect: clinician → own patients only, admin → all. If a policy method is missing, Laravel returns 403 for everything and many of these tests will fail together; that points at the policy, not the tests.

## 7. Not covered yet

The "report completeness" criterion needs your report-generation code (the `Report` model is there, but I haven't seen what builds `content_json`). Send it and I'll add those tests.
