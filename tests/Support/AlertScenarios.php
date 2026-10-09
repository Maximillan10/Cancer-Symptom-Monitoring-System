<?php

namespace Tests\Support;

/**
 * Simulated-patient scenarios for System Testing & Validation (proposal §4.4).
 *
 * Pure data, no framework. The EXPECTED alerts below were worked out by hand from the
 * clinical rules (default limits: absolute >= 7, rise >= 3 within 3 days; one open alert
 * per patient+symptom that escalates instead of duplicating), NOT copied from engine output.
 * That is what makes this a validation of the engine rather than a snapshot of it.
 *
 * Step format:  ['day' => 0, 'hour' => 8, 'scores' => ['pain' => 5]]   (unlisted symptoms default to 1)
 *               ['day' => 1, 'hour' => 12, 'resolve' => true]          (clinician resolves all open alerts)
 * Expected:     ['symptom', 'first_day', 'severity' (final score on the alert), 'resolved']
 * Rules:        null = clinician has saved nothing (system defaults);
 *               otherwise ['set' => ['pain' => ['abs' => 5, 'rise' => [3, 3]]], 'off' => ['pain' => ['abs']]]
 *               = a full saved rule set, defaults for everything not overridden.
 */
final class AlertScenarios
{
    public const BASELINE = 1; // value of every symptom that a step does not mention

    /** Daily check-ins at 08:00 for one symptom, starting day 0. */
    private static function daily(string $symptom, array $values, array $extra = []): array
    {
        $steps = [];
        foreach ($values as $day => $v) {
            $steps[] = ['day' => $day, 'scores' => [$symptom => $v] + ($extra[$day] ?? [])];
        }

        return $steps;
    }

    private static function exp(string $symptom, int $day, int $severity, bool $resolved = false): array
    {
        return ['symptom' => $symptom, 'first_day' => $day, 'severity' => $severity, 'resolved' => $resolved];
    }

    public static function all(): array
    {
        $S = [];
        $add = function (string $id, string $category, string $name, array $steps, array $expected, ?array $rules = null) use (&$S) {
            $S[$id] = compact('id', 'category', 'name', 'steps', 'expected', 'rules');
        };

        // ---- stable: nothing should alert -------------------------------------------------
        $add('S01', 'stable', 'Low, steady pain (2-3)', self::daily('pain', [2, 3, 2, 3, 2, 3, 2]), []);
        $add('S02', 'stable', 'Constant pain 6, just below the limit of 7', self::daily('pain', array_fill(0, 7, 6)), []);
        $add('S03', 'stable', 'Fatigue wobbling 3-5 (max rise 2)', self::daily('fatigue', [3, 5, 4, 5, 3, 4]), []);
        $add('S04', 'stable', 'Pain 4,4,6: rise of 2 and score below 7', self::daily('pain', [4, 4, 6]), []);
        $add('S05', 'stable', 'Fatigue 2,2,4: rise of 2 is below the rise limit of 3', self::daily('fatigue', [2, 2, 4]), []);
        $add('S06', 'stable', 'Rise of 3 but over 4 days (outside the 3-day window)',
            [['day' => 0, 'scores' => ['fatigue' => 2]], ['day' => 4, 'scores' => ['fatigue' => 5]]], []);
        $add('S07', 'stable', 'Pain 4,4,5 with default limits', self::daily('pain', [4, 4, 5]), []);

        // ---- configured thresholds -------------------------------------------------------
        $add('S08', 'custom_thresholds', 'Clinician lowers pain limit to 5; pain 4,4,5',
            self::daily('pain', [4, 4, 5]), [self::exp('pain', 2, 5)], ['set' => ['pain' => ['abs' => 5, 'rise' => [3, 3]]]]);
        $add('S09', 'custom_thresholds', 'Clinician switches the pain absolute rule off; first check-in pain 8',
            self::daily('pain', [8]), [], ['off' => ['pain' => ['abs']]]);

        // ---- absolute rule ---------------------------------------------------------------
        $add('S10', 'absolute', 'Pain jumps to exactly 7', self::daily('pain', [2, 2, 7]), [self::exp('pain', 2, 7)]);
        $add('S11', 'absolute', 'Breathlessness reaches 9 (critical)', self::daily('dyspnea', [2, 2, 9]), [self::exp('dyspnea', 2, 9)]);
        $add('S12', 'absolute', 'Very first check-in anxiety 10 (no history)', self::daily('anxiety', [10]), [self::exp('anxiety', 0, 10)]);
        $add('S13', 'absolute', 'Very first check-in pain 7', self::daily('pain', [7]), [self::exp('pain', 0, 7)]);
        $add('S14', 'absolute', 'Sustained pain 7,7,8,8,9: one alert that escalates',
            self::daily('pain', [7, 7, 8, 8, 9]), [self::exp('pain', 0, 9)]);

        // ---- rate-of-change rule ---------------------------------------------------------
        $add('S15', 'rise', 'Fatigue 2,2,5: rise of exactly 3', self::daily('fatigue', [2, 2, 5]), [self::exp('fatigue', 2, 5)]);
        $add('S16', 'rise', 'Nausea 0,0,3: a baseline of 0 is a real reading', self::daily('nausea', [0, 0, 3]), [self::exp('nausea', 2, 3)]);
        $add('S17', 'rise', 'Steady climb of 1 point a day (nausea 1..6)', self::daily('nausea', [1, 2, 3, 4, 5, 6]), [self::exp('nausea', 3, 6)]);
        $add('S18', 'rise', 'Two symptoms rise together (fatigue and anxiety 2,2,5)',
            self::daily('fatigue', [2, 2, 5], [0 => ['anxiety' => 2], 1 => ['anxiety' => 2], 2 => ['anxiety' => 5]]),
            [self::exp('fatigue', 2, 5), self::exp('anxiety', 2, 5)]);

        // ---- trajectories ----------------------------------------------------------------
        $add('S19', 'gradual_worsening', 'Pain creeps up one point every two days (2..8)',
            self::daily('pain', [2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8]), [self::exp('pain', 10, 8)]);
        $add('S20', 'acute_spike', 'Sudden crisis: pain 10 and breathlessness 9 on day 3',
            self::daily('pain', [2, 2, 2, 10], [3 => ['dyspnea' => 9]]), [self::exp('dyspnea', 3, 9), self::exp('pain', 3, 10)]);
        $add('S21', 'improving', 'Starts high and improves (pain 8 down to 2); alert stays open',
            self::daily('pain', [8, 7, 6, 5, 4, 3, 2]), [self::exp('pain', 0, 8)]);
        $add('S22', 'fluctuating', 'Anxiety swings 3 and 7: one alert, no spam',
            self::daily('anxiety', [3, 7, 3, 7, 3, 7]), [self::exp('anxiety', 1, 7)]);
        $add('S23', 'multi_symptom', 'Day 2: pain 8, nausea 7, fatigue 3 (+2 only)',
            [['day' => 0, 'scores' => []], ['day' => 1, 'scores' => []],
             ['day' => 2, 'scores' => ['pain' => 8, 'nausea' => 7, 'fatigue' => 3]]],
            [self::exp('nausea', 2, 7), self::exp('pain', 2, 8)]);
        $add('S24', 'resolution', 'Alert resolved, then the same breach returns: a new alert',
            [['day' => 0, 'scores' => ['pain' => 8]], ['day' => 1, 'hour' => 12, 'resolve' => true], ['day' => 2, 'scores' => ['pain' => 8]]],
            [self::exp('pain', 0, 8, true), self::exp('pain', 2, 8)]);
        $add('S25', 'critical_all', 'All six symptoms at 9 on the first check-in',
            [['day' => 0, 'scores' => ['pain' => 9, 'fatigue' => 9, 'nausea' => 9, 'anxiety' => 9, 'appetite' => 9, 'dyspnea' => 9]]],
            array_map(fn ($s) => self::exp($s, 0, 9), ['anxiety', 'appetite', 'dyspnea', 'fatigue', 'nausea', 'pain']));

        return $S;
    }

    /** Highest score per symptom across a scenario's steps (used for the critical-recall check). */
    public static function maxScores(array $scenario): array
    {
        $max = [];
        foreach ($scenario['steps'] as $step) {
            foreach ($step['scores'] ?? [] as $symptom => $v) {
                $max[$symptom] = max($max[$symptom] ?? 0, $v);
            }
        }

        return $max;
    }
}
