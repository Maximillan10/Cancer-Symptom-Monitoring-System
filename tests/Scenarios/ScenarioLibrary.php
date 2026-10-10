<?php

namespace Tests\Scenarios;

/**
 * 54 simulated patient scenarios for validating the alert rules.
 *
 * Every 'expect' was worked out by hand from the rule definitions BEFORE the scenarios were run:
 *   - default rules: alert when a score reaches 7, or rises by 3+ above the lowest reading of the previous 3 days
 *   - a baseline needs at least one earlier reading in the window; a reading exactly 3 x 24 h earlier counts
 *   - one open alert per patient and symptom: a worse score raises the open alert, an equal or lower one is ignored
 *   - only the patient's newest check-in is evaluated
 * 'expect' lists, per symptom, one [final score, arrival number that raised it] pair per alert.
 * Symptoms that are not listed must raise no alert. Symptoms not mentioned in a scenario are held at 2.
 *
 * These scenarios check that the code does what the rules say. They do not prove the rules are clinically right.
 */
final class ScenarioLibrary
{
    public static function all(): array
    {
        return array_merge(
            self::stable(),
            self::boundaries(),
            self::rateOfChange(),
            self::deterioration(),
            self::alertFatigue(),
            self::multipleSymptoms(),
            self::customThresholds(),
            self::offlineSync(),
            self::realistic()
        );
    }

    // ------------------------------------------------------------------ groups

    private static function stable(): array
    {
        return [
            self::s('S01', 'Stable and low for 14 days', 'Stable', self::daily('pain', array_fill(0, 14, 2)), []),
            self::s('S02', 'Mild fluctuation between 2 and 3', 'Stable', self::daily('pain', [2, 3, 2, 3, 2, 3, 2, 3, 2, 3]), []),
            self::s('S03', 'Steady moderate pain of 5 (known limitation: below every limit)', 'Stable', self::daily('pain', array_fill(0, 10, 5)), []),
            self::s('S04', 'Improving, 6 down to 2', 'Stable', self::daily('pain', [6, 5, 4, 3, 2]), []),
            self::s('S05', 'Single check-in, every symptom 3', 'Stable', [self::ci(0, self::allSix(3))], []),
            self::s('S06', 'Every symptom at 6 for a week (one below the limit)', 'Stable', self::multi(array_fill_keys(ScenarioRunner::SYMPTOMS, array_fill(0, 7, 6))), []),
        ];
    }

    private static function boundaries(): array
    {
        return [
            self::s('S07', 'Pain exactly at the limit (7) on the first check-in', 'Boundary', self::daily('pain', [7]), ['pain' => [[7, 1]]]),
            self::s('S08', 'Pain 10 on the first check-in', 'Boundary', self::daily('pain', [10]), ['pain' => [[10, 1]]]),
            self::s('S09', 'Pain 6 then 7 (crosses the limit, rise only 1)', 'Boundary', self::daily('pain', [6, 7]), ['pain' => [[7, 2]]]),
            self::s('S10', 'Dyspnea 7 on a single check-in', 'Boundary', self::daily('dyspnea', [7]), ['dyspnea' => [[7, 1]]]),
            self::s('S11', 'Pain 8 and nausea 9 on the same check-in', 'Boundary', [self::ci(0, ['pain' => 8, 'nausea' => 9])], ['pain' => [[8, 1]], 'nausea' => [[9, 1]]]),
            self::s('S12', 'Pain of 0 for five days, then 7', 'Boundary', self::daily('pain', [0, 0, 0, 0, 0, 7]), ['pain' => [[7, 6]]]),
        ];
    }

    private static function rateOfChange(): array
    {
        return [
            self::s('S13', 'Gradual rise 2,3,4,5 (rise of exactly 3 on day 4)', 'Rate of change', self::daily('pain', [2, 3, 4, 5]), ['pain' => [[5, 4]]]),
            self::s('S14', 'Rise of only 2 (2,3,4,4,4,4)', 'Rate of change', self::daily('pain', [2, 3, 4, 4, 4, 4]), []),
            self::s('S15', 'Next-day jump from 2 to 5', 'Rate of change', self::daily('pain', [2, 5]), ['pain' => [[5, 2]]]),
            self::s('S16', 'Sudden spike from 2 to 9', 'Rate of change', self::daily('pain', [2, 9]), ['pain' => [[9, 2]]]),
            self::s('S17', 'Slow creep from 2 to 5 over 10 days (known limitation)', 'Rate of change', self::daily('pain', [2, 2, 2, 3, 3, 3, 4, 4, 4, 5]), []),
            self::s('S18', 'Dip then recovery 4,2,5 (rise is measured from the lowest)', 'Rate of change', self::daily('pain', [4, 2, 5]), ['pain' => [[5, 3]]]),
            self::s('S19', 'Four-day gap: no reading inside the 3-day window', 'Rate of change', [self::ci(0, ['pain' => 2]), self::ci(4, ['pain' => 5])], []),
            self::s('S20', 'Exactly three-day gap: the earlier reading still counts', 'Rate of change', [self::ci(0, ['pain' => 2]), self::ci(3, ['pain' => 5])], ['pain' => [[5, 2]]]),
            self::s('S21', 'First-ever check-in with pain 6 (no baseline, below limit)', 'Rate of change', self::daily('pain', [6]), []),
            self::s('S55', 'Rise from a true zero baseline: 0,0,3 (a 0 is a real reading)', 'Rate of change', self::daily('pain', [0, 0, 3]), ['pain' => [[3, 3]]]),
        ];
    }

    private static function deterioration(): array
    {
        $ramp = [2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8];

        return [
            self::s('S22', 'Gradual deterioration over 13 days (limit catches it, escalates to 8)', 'Deterioration', self::daily('pain', $ramp), ['pain' => [[8, 11]]]),
            self::s('S23', 'Pain, fatigue and dyspnea deteriorate together', 'Deterioration', self::multi(['pain' => $ramp, 'fatigue' => $ramp, 'dyspnea' => $ramp]), ['pain' => [[8, 11]], 'fatigue' => [[8, 11]], 'dyspnea' => [[8, 11]]]),
            self::s('S24', 'Recovery after treatment 8,7,6,5,4,3', 'Deterioration', self::daily('pain', [8, 7, 6, 5, 4, 3]), ['pain' => [[8, 1]]]),
            self::s('S25', 'Pain hovering around the limit 6,7,6,7,6,7', 'Deterioration', self::daily('pain', [6, 7, 6, 7, 6, 7]), ['pain' => [[7, 2]]]),
            self::s('S26', 'Rapid dyspnea deterioration 2,3,6,9', 'Deterioration', self::daily('dyspnea', [2, 3, 6, 9]), ['dyspnea' => [[9, 3]]]),
        ];
    }

    private static function alertFatigue(): array
    {
        return [
            self::s('S27', 'Pain of 8 for seven days gives one alert, not seven', 'Alert fatigue', self::daily('pain', array_fill(0, 7, 8)), ['pain' => [[8, 1]]]),
            self::s('S28', 'Escalation 7,8,9,10 updates the same alert', 'Alert fatigue', self::daily('pain', [7, 8, 9, 10]), ['pain' => [[10, 1]]]),
            self::s('S29', 'A lower score never lowers an open alert (9,7,7)', 'Alert fatigue', self::daily('pain', [9, 7, 7]), ['pain' => [[9, 1]]]),
            self::s('S30', 'Alternating 7 and 9 for eight days gives one alert', 'Alert fatigue', self::daily('pain', [7, 9, 7, 9, 7, 9, 7, 9]), ['pain' => [[9, 1]]]),
            self::s('S31', 'Resolved, then the same score again raises a new alert', 'Alert fatigue', self::daily('pain', [8, 8]), ['pain' => [[8, 1], [8, 2]]], null, [['after' => 1, 'resolve' => 'pain']]),
            self::s('S32', 'Resolved, then the score improves: nothing new', 'Alert fatigue', self::daily('pain', [8, 5, 4]), ['pain' => [[8, 1]]], null, [['after' => 1, 'resolve' => 'pain']]),
            self::s('S33', 'Resolved at 7, then worse at 9: a second alert', 'Alert fatigue', self::daily('pain', [7, 9]), ['pain' => [[7, 1], [9, 2]]], null, [['after' => 1, 'resolve' => 'pain']]),
            self::s('S34', 'Two symptoms keep independent open alerts', 'Alert fatigue', [self::ci(0, ['pain' => 8]), self::ci(1, ['pain' => 8, 'nausea' => 8])], ['pain' => [[8, 1]], 'nausea' => [[8, 2]]]),
        ];
    }

    private static function multipleSymptoms(): array
    {
        return [
            self::s('S35', 'Only pain reported, the other symptoms not reported', 'Multiple symptoms', [self::ci(0, ['pain' => 7], 9, null)], ['pain' => [[7, 1]]]),
            self::s('S36', 'Missed items: pain 2, not reported, not reported, 6', 'Multiple symptoms', self::daily('pain', [2, null, null, 6]), ['pain' => [[6, 4]]]),
            self::s('S37', 'Alert storm: all six symptoms at 7 on one check-in', 'Multiple symptoms', [self::ci(0, self::allSix(7))], array_fill_keys(ScenarioRunner::SYMPTOMS, [[7, 1]])),
            self::s('S38', 'Anxiety jumps from 2 to 5', 'Multiple symptoms', self::daily('anxiety', [2, 5]), ['anxiety' => [[5, 2]]]),
            self::s('S39', 'Two check-ins on the same day, 2 then 6', 'Multiple symptoms', [self::ci(0, ['pain' => 2], 9), self::ci(0, ['pain' => 6], 18)], ['pain' => [[6, 2]]]),
        ];
    }

    private static function customThresholds(): array
    {
        $slow = [self::ci(0, ['pain' => 2]), self::ci(3, ['pain' => 3]), self::ci(5, ['pain' => 4]), self::ci(7, ['pain' => 5])];

        return [
            self::s('S40', 'Lower limit of 5: pain 5 raises an alert', 'Custom thresholds', self::daily('pain', [5]), ['pain' => [[5, 1]]], ScenarioRunner::ruleSet(5, 3, 3)),
            self::s('S41', 'Higher limit of 9: 7 and 8 stay quiet, 9 alerts', 'Custom thresholds', self::daily('pain', [7, 8, 9]), ['pain' => [[9, 3]]], ScenarioRunner::ruleSet(9, 3, 3)),
            self::s('S42', 'Rise rule switched off, limit 9: a jump from 2 to 8 is ignored', 'Custom thresholds', self::daily('pain', [2, 8]), [], ScenarioRunner::ruleSet(9, null, 3)),
            self::s('S43', 'Limit switched off, rise only: 2 to 9 alerts once', 'Custom thresholds', self::daily('pain', [2, 9, 9]), ['pain' => [[9, 2]]], ScenarioRunner::ruleSet(null, 3, 3)),
            self::s('S44', 'Sensitive rise rule (2 within 2 days)', 'Custom thresholds', self::daily('pain', [2, 3, 4]), ['pain' => [[4, 3]]], ScenarioRunner::ruleSet(10, 2, 2)),
            self::s('S45', 'Slow rise caught by a 7-day window', 'Custom thresholds', $slow, ['pain' => [[5, 4]]], ScenarioRunner::ruleSet(10, 3, 7)),
            self::s('S46', 'The same slow rise is missed by the default 3-day window', 'Custom thresholds', $slow, []),
            self::s('S47', 'Every rule switched off: even pain 10 stays quiet', 'Custom thresholds', self::daily('pain', [10]), [], ScenarioRunner::ruleSet(null, null, 3)),
        ];
    }

    private static function offlineSync(): array
    {
        return [
            self::s('S48', 'Offline batch arrives newest-first: only the newest is evaluated', 'Offline sync', [self::ci(5, ['pain' => 8]), self::ci(1, ['pain' => 2]), self::ci(2, ['pain' => 3]), self::ci(3, ['pain' => 4])], ['pain' => [[8, 1]]]),
            self::s('S49', 'A severe old reading synced late is not alerted (design limitation)', 'Offline sync', [self::ci(5, ['pain' => 2]), self::ci(3, ['pain' => 9])], []),
            self::s('S50', 'Out-of-order batch: late readings still count as baseline', 'Offline sync', [self::ci(1, ['pain' => 2]), self::ci(3, ['pain' => 4]), self::ci(2, ['pain' => 3]), self::ci(5, ['pain' => 7])], ['pain' => [[7, 4]]]),
        ];
    }

    private static function realistic(): array
    {
        return [
            self::s('S51', 'Chemotherapy nausea cycle (peaks at 8), fatigue stays mild', 'Realistic', self::multi(['nausea' => [2, 6, 8, 6, 3, 2, 2], 'fatigue' => [3, 4, 5, 5, 4, 3, 3]]), ['nausea' => [[8, 2]]]),
            self::s('S52', 'Palliative steady pain of 6 with one breakthrough spike to 9', 'Realistic', self::daily('pain', [6, 6, 6, 6, 6, 9, 6, 6]), ['pain' => [[9, 6]]]),
            self::s('S53', 'Episodic anxiety, clinician resolves between episodes', 'Realistic', self::daily('anxiety', [2, 8, 2, 2, 8, 2]), ['anxiety' => [[8, 2], [8, 5]]], null, [['after' => 3, 'resolve' => 'anxiety']]),
            self::s('S54', 'Missed days then a high score: the limit still fires', 'Realistic', [self::ci(0, ['pain' => 3]), self::ci(4, ['pain' => 8])], ['pain' => [[8, 2]]]),
        ];
    }

    // ---------------------------------------------------------------- builders

    private static function s(string $id, string $name, string $category, array $checkins, array $expect, ?array $rules = null, array $events = []): array
    {
        return compact('id', 'name', 'category', 'checkins', 'expect', 'rules', 'events');
    }

    /** One check-in on a given day at a given hour. Symptoms not given are held at $fill (null = not reported). */
    private static function ci(int $day, array $scores, int $hour = 9, ?int $fill = 2): array
    {
        $row = [];
        foreach (ScenarioRunner::SYMPTOMS as $symptom) {
            $row[$symptom] = array_key_exists($symptom, $scores) ? $scores[$symptom] : $fill;
        }

        return ['t' => $day * 24 + $hour, 'scores' => $row];
    }

    private static function allSix(int $value): array
    {
        return array_fill_keys(ScenarioRunner::SYMPTOMS, $value);
    }

    /** One check-in per day at 09:00 for one symptom; null means "not reported that day". */
    private static function daily(string $symptom, array $values, int $startDay = 0): array
    {
        $out = [];
        foreach ($values as $i => $value) {
            $out[] = self::ci($startDay + $i, [$symptom => $value]);
        }

        return $out;
    }

    /** Daily check-ins for several symptoms at once, all series the same length. */
    private static function multi(array $bySymptom): array
    {
        $length = count(reset($bySymptom));
        $out = [];

        for ($i = 0; $i < $length; $i++) {
            $scores = [];
            foreach ($bySymptom as $symptom => $values) {
                $scores[$symptom] = $values[$i];
            }
            $out[] = self::ci($i, $scores);
        }

        return $out;
    }
}
