# Test Report: Cancer Symptom Tracker: Clinician Alert Dashboard

Course CSC 4004 · Milestone: System Testing & Validation · Author: Maximillan Soko (2021494314)

> Sections marked **[FILL]** need results from your own run (see `docs/TESTING.md`). Everything else is ready to use.

## 1. Purpose and scope

This report verifies that the dashboard's alert logic, access control and API performance meet the measurable criteria of the approved proposal (§4.4). Scope: `AlertRules`, `AlertEngine`, the check-in observer, the `alerts:scan` command, clinician/alert/patient access control, and API response time. Out of scope (per proposal): the mobile app, real patient data, hospital integration.

## 2. Test environment [FILL]

| Item | Value |
|---|---|
| OS / PHP / Laravel | [FILL] |
| Database for tests | [FILL: SQLite in-memory or PostgreSQL `cancer_tracker_test`] |
| PHPUnit version | [FILL: from `php artisan test` header] |
| Date of run | [FILL] |

## 3. Test strategy

| Level | What | Where |
|---|---|---|
| Unit | Rule logic with no database | `tests/Unit/AlertRulesBoundaryTest.php` |
| Integration | Engine + observer + database + scan command | `tests/Feature/AlertEngineBehaviourTest.php` |
| Scenario validation | 25 simulated patients, expected alerts worked out by hand from the clinical rules before running | `tests/Feature/AlertScenarioValidationTest.php`, `tests/Support/AlertScenarios.php` |
| Security | Authentication and per-clinician isolation | `tests/Feature/AccessControlTest.php` |
| API / endpoint | Auth, patient and symptom-submission endpoints: validation, scoping, error codes, end to end into the alert engine | `tests/Feature/Api/*` |
| Performance | 10 concurrent users against the API | `scripts/load-test.php` |

Simulated data only; no real patient data was used (proposal §3.5).

The 25 scenarios cover the five trajectory types of the proposal (stable, gradual worsening, acute spike, improving, fluctuating) plus boundary cases for each rule, configured thresholds, resolution, multi-symptom and critical-score cases. Expected results use the default limits (absolute ≥ 7, rise ≥ 3 within 3 days) and the one-open-alert-per-symptom rule.

## 4. Scenario results [FILL: paste `storage/app/validation/scenario-results.md`]
| ID | Trajectory | Scenario | Expected alerts | Matched | Unexpected | Result |
|---|---|---|---|---|---|---|
| S01 | stable | Low, steady pain (2-3) | 0 | 0 | 0 | PASS |
| S02 | stable | Constant pain 6, just below the limit of 7 | 0 | 0 | 0 | PASS |
| S03 | stable | Fatigue wobbling 3-5 (max rise 2) | 0 | 0 | 0 | PASS |
| S04 | stable | Pain 4,4,6: rise of 2 and score below 7 | 0 | 0 | 0 | PASS |
| S05 | stable | Fatigue 2,2,4: rise of 2 is below the rise limit of 3 | 0 | 0 | 0 | PASS |
| S06 | stable | Rise of 3 but over 4 days (outside the 3-day window) | 0 | 0 | 0 | PASS |
| S07 | stable | Pain 4,4,5 with default limits | 0 | 0 | 0 | PASS |
| S08 | custom_thresholds | Clinician lowers pain limit to 5; pain 4,4,5 | 1 | 1 | 0 | PASS |
| S09 | custom_thresholds | Clinician switches the pain absolute rule off; first check-in pain 8 | 0 | 0 | 0 | PASS |
| S10 | absolute | Pain jumps to exactly 7 | 1 | 1 | 0 | PASS |
| S11 | absolute | Breathlessness reaches 9 (critical) | 1 | 1 | 0 | PASS |
| S12 | absolute | Very first check-in anxiety 10 (no history) | 1 | 1 | 0 | PASS |
| S13 | absolute | Very first check-in pain 7 | 1 | 1 | 0 | PASS |
| S14 | absolute | Sustained pain 7,7,8,8,9: one alert that escalates | 1 | 1 | 0 | PASS |
| S15 | rise | Fatigue 2,2,5: rise of exactly 3 | 1 | 1 | 0 | PASS |
| S16 | rise | Nausea 0,0,3: a baseline of 0 is a real reading | 1 | 1 | 0 | PASS |
| S17 | rise | Steady climb of 1 point a day (nausea 1..6) | 1 | 1 | 0 | PASS |
| S18 | rise | Two symptoms rise together (fatigue and anxiety 2,2,5) | 2 | 2 | 0 | PASS |
| S19 | gradual_worsening | Pain creeps up one point every two days (2..8) | 1 | 1 | 0 | PASS |
| S20 | acute_spike | Sudden crisis: pain 10 and breathlessness 9 on day 3 | 2 | 2 | 0 | PASS |
| S21 | improving | Starts high and improves (pain 8 down to 2); alert stays open | 1 | 1 | 0 | PASS |
| S22 | fluctuating | Anxiety swings 3 and 7: one alert, no spam | 1 | 1 | 0 | PASS |
| S23 | multi_symptom | Day 2: pain 8, nausea 7, fatigue 3 (+2 only) | 2 | 2 | 0 | PASS |
| S24 | resolution | Alert resolved, then the same breach returns: a new alert | 2 | 2 | 0 | PASS |
| S25 | critical_all | All six symptoms at 9 on the first check-in | 6 | 6 | 0 | PASS |

Scenario accuracy **100.0%** (25/25) · alert recall **100.0%** (26/26) · unexpected alerts **0** · critical events **11**, missed **0**

Pre-run check by the author of the tests: the same scenarios were replayed through an in-memory port of the engine that uses the real `AlertRules` code. All 25 matched (26 of 26 expected alerts, 0 unexpected, 11 critical events, 0 missed), which confirms the expectations are consistent with the rules. The official result is the run against the real application in your environment.

## 5. Acceptance criteria (proposal §4.4)

| Criterion | Target | Method | Result |
|---|---|---|---|
| Alert detection accuracy | ≥ 95% of expected alerts; no false negatives for critical events | `AlertScenarioValidationTest` | [FILL] |
| Simulated patient coverage | ≥ 20 patients, ≥ 4 trajectory types | Scenario count and categories | 25 patients; 5 trajectory types |
| API response time | Average ≤ 300 ms at 10 concurrent users | `scripts/load-test.php` | [FILL] |
| API test coverage | ≥ 80% of API endpoints | `php artisan test --coverage`; all 11 routes in `routes/api.php` have endpoint tests | [FILL: coverage %] |
| Report completeness | 100% reports contain chart, alert history, summary | Pending report-generation code | [PENDING] |
| Dashboard usability | SUS ≥ 70 with 3–5 evaluators | Questionnaire | [FILL] (manual) |

**Source of the targets: confirm with your supervisor.** The numeric targets above (95% accuracy, ≤ 300 ms at 10 users, SUS ≥ 70, ≥ 80% coverage) appear in `Cancer_Symptom_Tracker_Proposal_FINAL.docx` (§4.4, 26-week plan). The 22-week proposal text dated 24 March 2026 contains no numeric evaluation table, and an earlier working note quoted 85% accuracy. The test gate uses 95% (one constant, `ACCURACY_TARGET` in `AlertScenarioValidationTest`). Use whichever version your supervisor approved and say so in the report.

## 6. Findings and recommendations

Found by reading the code and confirmed or documented by the tests listed. None stops the system working; F-02, F-03 and F-07 are the most worth fixing before submission.

| ID | Severity | Finding | Evidence | Recommendation |
|---|---|---|---|---|
| F-01 | Low (design) | Slow deterioration is only detected when the score reaches the absolute limit. The default rise rule needs +3 within 3 days, so a patient whose pain climbs 1 point every 2 days is not flagged until it reaches 7. | Scenario S19 (first alert on day 10) | Add a longer-window rise rule (e.g. +3 within 7 days) or document as a limitation of rule-based logic |
| F-02 | Medium | Once a clinician saves any threshold, symptoms with no saved rule get no alerts at all (defaults are not applied per symptom). | `test_finding_f02_…` | In `rulesFor()`, fall back to default rules for any symptom that has no saved rows, while still honouring saved inactive rules |
| F-03 | Medium | A configured limit above 9 (or rules switched off) lets a critical score (9–10) pass without an alert, which conflicts with "no false negatives for critical events". | `test_finding_f03_…` | Always alert on scores ≥ 9, or restrict the settings form (limit ≤ 9) and warn when switching a symptom off |
| F-04 | Low | An escalated alert is updated in place: `triggered_at` stays at the first breach and escalation is not reported as new, so a warning that becomes critical is easy to miss and `alerts:scan` does not count it. | `test_worse_score_escalates_the_open_alert_in_place` | Show "escalated" on the alert (e.g. `escalated_at` or severity history) and count escalations in the scan summary |
| F-05 | Low/Medium | Evaluation depends on arrival order: if a newer check-in is already stored, an older one that syncs late is ignored, even if it was a critical score. | `test_finding_f05_…` | Always apply the absolute/critical rule to late check-ins (within a sensible age), skip only the rise rule |
| F-06 | Low | The patient search uses `ILIKE`, which exists only in PostgreSQL, so the endpoint errors on SQLite/MySQL and cannot be tested on the fast in-memory database. | `test_index_search_is_case_insensitive_and_scoped` (skipped off PostgreSQL) | Use `whereRaw('LOWER(name) LIKE ?', …)` for portability, or state PostgreSQL as a hard requirement |
| F-07 | Medium | The mobile sync endpoint has no duplicate protection: re-sending the same check-in (normal after a dropped connection) stores it twice, skewing trends and reports. It also accepts only one check-in per request. | `test_finding_f07_resending_the_same_checkin_stores_it_twice` | Make the endpoint idempotent on (patient, submitted_at) or a client-generated id; consider a batch endpoint for offline queues |

## 7. Limitations

- Tests use simulated trajectories and hand-written expectations; they validate rule behaviour, not clinical effectiveness.
- Load figures from `php artisan serve` underestimate a production web server.
- SUS usability testing and clinician review are manual and not automated here.
- Alert delivery is dashboard-only; there is no email/SMS channel to test.

## 8. Conclusion [FILL after your run]

State whether each criterion in section 5 was met, list any failed tests with their fix, and cite the findings above as recommendations for future work.
