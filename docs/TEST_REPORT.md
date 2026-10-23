# Test report

The full validation write-up is the separate validation document submitted with the project. This file only lists the commands
and the figures they print, so that anyone can reproduce them. See `docs/TESTING.md` for how to run each command.

| What | Command | Result |
|---|---|---|
| Hand-written scenarios | `php tests/Scenarios/run.php` | 55 of 55 passed; 50 true positives, 0 false positives, 0 false negatives, 280 true negatives |
| Simulated cohort (200 patients, 28 days) | `php tests/Scenarios/cohort.php --seed=N` | Default setting, sensitivity: seed 20260105 100%, seed 1 98.5%, seed 2 100%, seed 3 98.4%, seed 4 98.5% |
| False alerts, cohort | `php tests/Scenarios/cohort.php --false-share` | About 54 to 58% of new alerts on the default setting were false alarms (see the validation document for how this is read) |
| PHPUnit suite, cloud workspace | `php artisan test` | 369 tests, 1,047 assertions, 0 failures, in the cloud workspace used for the validation document (a temporary Linux container provided with the Claude AI tool) |
| PHPUnit suite, author's laptop, 17 October 2026 | `php artisan test --exclude-group=performance` | 368 tests passed, 951 assertions, 152.84 s (Windows, PHP 8.3.25). It leaves out the one performance test; the difference between 951 and 1,047 is the performance test: it makes 15 requests to each of six endpoints, so it has 90 request checks plus six median checks (951 + 96 = 1,047) |
| Load test, laptop, 16 October 2026 | `php tests/load/load-test.php ... --concurrency=N` | AMD PRO A10-8700B 1.8 GHz, 8 GB RAM, Windows, PHP 8.3.25, `php artisan serve --no-reload`. 1 user: p95 807 to 1,232 ms. 5 users: p95 about 10 to 10.9 s. 50 users: run failed (117 of 125 requests per endpoint timed out at 30 s). The 500 ms target was not met |
| Load test, cloud workspace | same script | 1 user p95 under 60 ms; 10 users about 300 to 335 ms; 50 users about 1.6 s. The 500 ms target was not met at 50 users |
| Usability (SUS) | Google Form, ten SUS items | 8 University of Zambia students, 11 October 2026: mean 85.9, median 95, range 47.5 to 100; 7 of 8 scored 70 or more. Small convenience sample, not clinicians or patients |

What the results do not show: the scenarios and the cohort are simulated and were written by the project author, so they show that the
code follows the written rules, not that the rules are clinically right. The SUS sample is eight students. No clinician review of the
alert rules has been carried out, and the system has not been used by real patients.
