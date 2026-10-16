# Test report

The full validation write-up is the separate validation document submitted with the project. This file only lists the commands
and the figures they print, so that anyone can reproduce them. See `docs/TESTING.md` for how to run each command.

| What | Command | Result |
|---|---|---|
| Hand-written scenarios | `php tests/Scenarios/run.php` | 55 of 55 passed; 50 true positives, 0 false positives, 0 false negatives, 280 true negatives |
| Simulated cohort (200 patients, 28 days) | `php tests/Scenarios/cohort.php --seed=N` | Default setting, sensitivity: seed 20260105 100%, seed 1 98.5%, seed 2 100%, seed 3 98.4%, seed 4 98.5% |
| False alerts, cohort | `php tests/Scenarios/cohort.php --false-share` | About 54 to 58% of new alerts on the default setting were false alarms (see the validation document for how this is read) |
| PHPUnit suite | `php artisan test` | Record the line printed at the end of your own run, with the date and PHP version |

What the results do not show: the scenarios and the cohort are simulated and were written by the project author, so they show that the
code follows the written rules, not that the rules are clinically right. No usability (SUS) study and no clinician review has been carried out.
