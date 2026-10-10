# Test Report: Cancer Symptom Tracker, Clinician Alert Dashboard

University of Zambia, CSC 4004 | Maximillan Soko (2021494314) | Supervisor: Mr. Mofya Phiri
Milestone: System Testing & Validation

**Read this first.** This report separates what was *executed* from what was *written but not yet run*. The environment where
the tests were prepared could run plain PHP but could not install Laravel, so the database and HTTP tests have to be run on
your machine (Section 5). Fill in the "Your run" columns before you submit. Do not submit this report with the blanks empty.

## 1. Summary against the proposal's evaluation criteria

| Criterion (proposal) | Target | Result | Status |
|---|---|---|---|
| Simulated patient scenarios | at least 50 | 55 scenarios in 9 categories | Met |
| Alert detection accuracy on predefined scenarios | at least 85% | 55 of 55 scenarios (100%); 330 of 330 symptom decisions | Met (executed) |
| Same scenarios through the real engine and database | n/a | `ScenarioEngineTest`: 55 of 55 passed (student's run) | Met (executed) |
| API response time under simulated load | 500 ms or less | Mock-server check of the script only | **Run it yourself** (Section 5) |
| Usability (SUS) | 70 or more | Not measured | Needs real testers (Section 7) |
| Report generation completeness | 95% or more | Not measured | Report feature (proposal Phase 8) is not built yet |

## 2. How the alert accuracy was measured

Each scenario is a short story: one patient's check-ins plus the clinician's rules. The expected alerts were written **by hand,
from the written rules, before running anything** (which symptom alerts, at what final score, on which check-in). A scenario passes only if
every alert matches exactly, so a scenario with a wrong score or the wrong day counts as a failure.

What this proves: the code does what the written rules say. What it does **not** prove: that the rules are clinically right.
Only a clinician or real data can show that. The expectations and the code were written by the same person (me, with you),
so a shared misunderstanding of a rule would not be caught.

Categories (IDs in `tests/Scenarios/ScenarioLibrary.php`): Stable (6), Boundary (6), Rate of change (10), Deterioration (5),
Alert fatigue (8), Multiple symptoms (5), Custom thresholds (8), Offline sync (3), Realistic (4).

Result: **55 / 55 passed.** Run it: `php tests/Scenarios/run.php`

### Did the tests actually test anything? (mutation check)
All 54 first-draft scenarios passed on the first run, which can mean good tests or tests that cannot fail. So I broke the rules on
purpose, one change at a time, and checked that the tests noticed:

| Deliberate fault | Scenarios that failed | Caught by unit tests |
|---|---|---|
| Absolute rule `>=` changed to `>` | 13 | yes |
| Rise rule `>=` changed to `>` | 8 | yes |
| Baseline uses highest earlier score, not lowest | 4 | yes |
| Window edge made exclusive | 5 | yes |
| A score of 0 treated as "no reading" | 1 (after adding S55) | yes |
| Open alert escalated on an equal score | 0 (no visible effect on scenario output) | yes |
| Critical band moved from 9 to 10 | 0 (priority is not scenario output) | yes |

The zero-baseline fault initially **survived**: no scenario depended on it. That was a real gap, so scenario S55 was added.
The last two are covered by the unit tests rather than the scenarios, which is why both layers exist.

## 3. Findings: what the rules do and do not catch

These are limitations of the current design, found by testing. They are not hidden bugs; they are the behaviour the scenarios
(S03, S17, S46, S49) document on purpose. Report them as such.

1. **Slow creep is missed.** A score rising about 1 point every 3 days never rises 3 points inside 3 days, and never reaches 7 until late.
   A 7-day window (S45) catches it, but see Section 4 for the cost.
2. **Steady moderate symptoms never alert.** Pain at 5 or 6 for weeks is below every limit (S03, S06, S52).
3. **A severe old reading synced late is not alerted** (S49). The engine only evaluates the patient's newest check-in, so a phone
   that uploads old offline entries does not raise alerts about the past. Intentional, but a clinician should know it.
4. **A single check-in can raise six alerts at once** (S37). Each symptom has its own alert.
5. **The rise rule can fire on noise**, for example 2 to 5 overnight (S15, S38).
6. **Appetite is treated as "higher is worse"** like the other symptoms. If the mobile app scores appetite the other way round, that rule is inverted. Confirm with Part A.
7. **Missed check-ins delay alerts.** In the simulated cohort, the only true events not detected in time were patients who stopped reporting for two or three days while their symptoms jumped (seeds 1 and 3; none missed in the default seed). The alert came as soon as the next check-in arrived, one to two days late. No rule can alert on data it has not received.

## 4. Simulated cohort and threshold tuning

200 simulated patients over 28 days (seed 20260105, 69 of them with a true event; 15% of days randomly missing, deterministic). A hidden "true" symptom level exists
for each patient, and the rules only see a noisy, incomplete version. **Ground truth is defined from the hidden level, not from the rules**:
an *event* is the true level reaching 8; *detected* means an alert (new or escalated) on or before the event day + 1; a *false alert* is a
new alert for a patient with no event, or more than 7 days before one. Run: `php tests/Scenarios/cohort.php --markdown --detail`

| Setting | Sensitivity | Mean lead (days) | Warned early | False alerts / 100 patient-weeks |
|---|---|---|---|---|
| **Default (7, or +3 in 3 days)** | 100.0% | 1.8 | 36 of 69 | 9.9 |
| Absolute only (7) | 100.0% | 1.2 | 31 of 69 | 8.6 |
| Rise only (+3 in 3 days) | 82.6% | 1.8 | 30 of 69 | 5.6 |
| Rise +4 in 3 days | 100.0% | 1.3 | 31 of 69 | 9.0 |
| Rise +3 in 2 days | 100.0% | 1.7 | 34 of 69 | 9.6 |
| Rise +2 in 3 days | 100.0% | 3.2 | 47 of 69 | **36.0** |
| Absolute 6 | 100.0% | 2.1 | 40 of 69 | 13.1 |
| Absolute 8 | 98.6% | 1.5 | 32 of 69 | 6.5 |
| Default plus 7-day creep rule (+3 in 7 days) | 100.0% | 2.1 | 39 of 69 | 10.4 |

What the table supports (the same pattern appeared in three other seeds, 1, 2 and 3, where default sensitivity was 98.5%, 100% and 98.4%):
- The default rules detected nearly every simulated event, with about 1.8 days of warning on average. The rise rule gives the early warning: absolute-only warned 31 of 69 early, the default 36 of 69.
- Making the rise rule more sensitive (+2) buys about 1.4 more days of warning but costs about 3.6 times as many false alerts (36.0 vs 9.9 per 100 patient-weeks). That is an alert-fatigue cost a clinic would feel.
- The extra 7-day creep rule added little in this cohort, because the creeping patients still crossed 7 and were caught by the absolute rule.
- The "improving" patients start at 5 to 8, so a few begin with a true level that already rounds to 8 on day 0. They count as events, and the rules detect them on the first check-in. That is a quirk of how I defined an event, not a clinical claim.
- Treat all of this as a comparison of settings **inside my simulation**. The archetypes (stable, moderate, improving, episodic, gradual, sudden, slow creep, noisy) and their numbers are my assumptions, not clinical data. It is not evidence of real-world accuracy.

Method note: my first scoring counted only *new* alerts as detections, which made the default look worse than absolute-only. That was wrong; adding a rule cannot lower detection. An early alert stays open, so the later breach shows up as an *escalation* of it, and a clinician does see that. Escalations now count as detections. A unit test (`test_adding_a_rule_never_lowers_detection`) guards this.

## 5. What was executed and what you must run

| Test | Count | Executed here? | Your run |
|---|---|---|---|
| Scenario runner (`php tests/Scenarios/run.php`) | 55 scenarios | **Yes**, 55/55 | |
| Unit tests: AlertRulesTest, AlertDecisionTest, ScenarioValidationTest | 71 cases | **Yes**, with a small stand-in for PHPUnit, not real PHPUnit | |
| Load script (`tests/load/load-test.php`) | n/a | Checked against a **mock** server: pass, fail (slow) and bad-login paths behave correctly. **Never run against your app.** | |
| Feature tests: AuthApiTest, PatientApiTest, SymptomSubmissionApiTest, WebDashboardTest | about 75 | **Yes (student's run):** all passed | |
| ScenarioEngineTest (same 55 scenarios through the real engine and DB) | 55 | **Yes (student's run):** 55/55 | |
| PerformanceTest (median response time, 50 patients x 30 days) | 1 | **Yes (student's run):** passed, slowest median 184.5 ms | |

Student's full run (PHP 8.2, `php artisan test --exclude-group=performance`): 307 tests passed, 3 deprecation warnings (bug 4, now fixed), 0 failures, 754 assertions. The earlier suite in the project (149 tests) is included in that count.

The Laravel starter tests for registration, password reset, email verification and profile pages were deleted, because this app has no such routes. The remaining starter login test was changed to expect a redirect to `/login`.

Student's full run with the performance test included: 311 passed, 850 assertions, 0 failures. Median response times in that test (50 patients x 30 days of check-ins, in the test environment, no network):

| Endpoint | Median |
|---|---|
| GET /api/patients | 68.4 ms |
| GET /api/patients/{id}/symptom-submissions | 53.1 ms |
| POST /api/symptom-submissions | 24.5 ms |
| GET /dashboard | 184.5 ms |
| GET /patients/{id} | 55.0 ms |
| GET /alerts | 17.4 ms |

All are under the 500 ms target. This is a single-user smoke test, not a load test, so it does not yet show behaviour under many simultaneous users. The HTTP load test (TESTING.md, section 4) is still to be run.

## 6. Bugs found and fixed
From the work executed so far:

| # | Found by | Problem | Fixed |
|---|---|---|---|
| 1 | Mutation check | No test covered a rise measured from a baseline of 0 | Scenario S55 added |
| 2 | Cohort comparison | Scoring ignored escalations, understating detection | Metric corrected, unit test added |
| 3 | Lint | `ScenarioLibrary` had two methods named `all()` | Builder renamed `allSix()` |
| 5 | Student's suite, finding f07 | Re-sending the same check-in stored it twice (a phone retrying a sync would create duplicate readings and could repeat alerts) | Same patient + same `submitted_at` + same scores now returns the saved check-in (HTTP 200); same time with different scores returns 409. Tests: `CheckInIdempotencyTest`. Not protected against two requests arriving at the same instant (no database unique index) |
| 6 | Student's suite, finding f03 | A saved limit of 10 let a critical score of 9 pass without an alert | Absolute limit now capped at 9 (form and server-side check); a migration lowers any saved limit of 10 to 9. A clinician can still switch a rule off with a blank box. Scenario S42 changed from limit 10 to limit 9. Tests: `ThresholdLimitTest` |
| 4 | Student's `php artisan test` (PHP 8.2) | The cohort random-number generator overflowed PHP's integer range (3 deprecation warnings, a 120 s test). The corrupted draws also made the first cohort numbers unreliable | Multiplication rewritten to stay in range and checked against an independent reference implementation; every cohort figure in this report was regenerated |

## 7. Not done, and why
- **Real load figures.** The single-user performance test passed (Section 5) but the many-users HTTP load test has not been run. It needs your machine with PostgreSQL. Run the load script (see TESTING.md) and paste the table here.
- **Security testing.** The tests cover the controls that exist: tokens required, wrong password rejected, password never returned or stored in plain text, role cannot be chosen at registration, clinicians cannot read, edit, delete or submit for another clinician's patients, input validation. A penetration test, rate-limit testing and dependency audit were **not** done. Say "access control and authentication tests passed", not "the system is secure".
- **Usability (SUS).** Needs at least a handful of real people (e.g. classmates acting as clinicians) completing tasks and filling in the 10-question SUS form. I can prepare the task list and form if you want.
- **Report generation (95% completeness).** The patient summary report and PDF export from the proposal (Phase 8) are not built, so this criterion cannot be measured yet.
- **Patient search under PostgreSQL.** The search uses `ilike`, which only exists in PostgreSQL, so the automated tests (sqlite by default) do not cover it. Test it manually in Postman.
