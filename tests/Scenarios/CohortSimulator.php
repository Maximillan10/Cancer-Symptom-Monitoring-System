<?php

namespace Tests\Scenarios;

use App\Services\AlertRules;

/**
 * Simulated cohort with ground truth, used to compare alert-rule settings.
 *
 * Every patient has a hidden "true" symptom level for 28 days (the latent series). The app only sees a noisy,
 * partly missing version of it (what a patient would report). Ground truth is defined from the hidden series,
 * NOT from the alert rules, so the rules can be scored against something they did not produce:
 *
 *   event          = the true level reaches EVENT_LEVEL (8) on some day. This is the "clinician needed to know" moment.
 *   detected       = an alert was raised on or before the event day + GRACE_DAYS (1).
 *   lead time      = event day - first alert day (positive = warned early).
 *   false alert    = an alert for a patient with no event, or raised more than EARLY_DAYS (7) before an event.
 *
 * IMPORTANT: this is a simulation built on my own assumptions about how symptoms behave (archetypes below).
 * It compares settings against each other; it is not clinical validation and the numbers are not
 * claims about real patients. Real validation needs clinician-labelled data.
 */
final class CohortSimulator
{
    public const DAYS = 28;
    public const EVENT_LEVEL = 8;
    public const GRACE_DAYS = 1;
    public const EARLY_DAYS = 7;
    public const MISSING_RATE = 0.15;

    /** archetype => number of patients in the default cohort (200 patients). */
    public const MIX = [
        'stable' => 40,
        'moderate' => 30,
        'improving' => 25,
        'episodic' => 25,
        'gradual' => 25,
        'sudden' => 25,
        'slow_creep' => 15,
        'noisy_high' => 15,
    ];

    /** Deterministic generator (mulberry32) so a given seed always produces the same cohort. */
    private static function rng(int $seed): \Closure
    {
        $state = $seed & 0xFFFFFFFF;

        return function () use (&$state): float {
            $state = ($state + 0x6D2B79F5) & 0xFFFFFFFF;
            $t = $state;
            $t = self::imul($t ^ ($t >> 15), $t | 1);
            $t ^= ($t + self::imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF;

            return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
        };
    }

    private static function imul(int $a, int $b): int
    {
        // Low 32 bits of a*b without ever leaving PHP's 64-bit integer range (a plain a*b overflows to float).
        $high = ($a >> 16) & 0xFFFF;
        $low = $a & 0xFFFF;

        return (($low * $b) + ((($high * $b) & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }

    /** @return list<float> true level per day */
    private static function latent(string $type, \Closure $rand): array
    {
        $days = range(0, self::DAYS - 1);
        $between = fn (float $lo, float $hi) => $lo + ($hi - $lo) * $rand();

        switch ($type) {
            case 'stable':
                $level = $between(1, 4);
                return array_map(fn () => $level, $days);

            case 'moderate':
                $level = $between(5, 6.4);
                return array_map(fn () => $level, $days);

            case 'improving':
                $start = $between(5, 8);
                $end = $between(1, 3);
                return array_map(fn ($d) => $start + ($end - $start) * $d / (self::DAYS - 1), $days);

            case 'episodic': // low, one short flare of 3-5 days to 6-7.4 (below the event level)
                $base = $between(2, 4);
                $peak = $between(6, 7.4);
                $start = (int) $between(4, 20);
                $len = (int) $between(3, 6);
                return array_map(fn ($d) => ($d >= $start && $d < $start + $len) ? $peak : $base, $days);

            case 'gradual': // low, then climbs about 1 point a day to the event level over 5-8 days
                $base = $between(2, 4);
                $start = (int) $between(5, 14);
                $slope = $between(0.7, 1.3);
                return array_map(fn ($d) => min(10, $base + max(0, $d - $start) * $slope), $days);

            case 'sudden': // low, then jumps 4-7 points within a day or two
                $base = $between(2, 4);
                $day = (int) $between(5, 22);
                $peak = min(10, $base + $between(4.5, 7));
                return array_map(fn ($d) => $d >= $day ? $peak : $base, $days);

            case 'slow_creep': // about 1 point every 3-4 days, reaching the event level late
                $base = $between(2, 3.5);
                $slope = $between(0.26, 0.36);
                return array_map(fn ($d) => min(10, $base + $d * $slope), $days);

            case 'noisy_high': // hovers 6-7, no true event, reports jump around
                $level = $between(6, 7);
                return array_map(fn () => $level, $days);
        }

        throw new \InvalidArgumentException("Unknown archetype {$type}");
    }

    /**
     * @return list<array{type:string, latent:list<float>, observed:list<?int>, event_day:?int}>
     */
    public static function generate(int $seed = 20260105, array $mix = self::MIX): array
    {
        $rand = self::rng($seed);
        $patients = [];

        foreach ($mix as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                $latent = self::latent($type, $rand);
                $noise = $type === 'noisy_high' ? 2 : 1;
                $observed = [];

                foreach ($latent as $level) {
                    if ($rand() < self::MISSING_RATE) {
                        $observed[] = null;
                        continue;
                    }

                    $jitter = (int) round(($rand() * 2 - 1) * $noise);
                    $observed[] = max(0, min(10, (int) round($level) + $jitter));
                }

                $eventDay = null;
                foreach ($latent as $day => $level) {
                    if (round($level) >= self::EVENT_LEVEL) {
                        $eventDay = $day;
                        break;
                    }
                }

                $patients[] = compact('type', 'latent', 'observed') + ['event_day' => $eventDay];
            }
        }

        return $patients;
    }

    /**
     * One patient's alerts under a rule set, check-ins arriving in order, one per day.
     * The clinician resolves an open alert once two consecutive reported scores are below 5.
     *
     * @param  list<array{threshold_value:int, window_days:?int}>  $rules
     * @return list<array{day:int, action:string}> every alert the clinician would see: new alerts and escalations
     */
    public static function alertDays(array $observed, array $rules): array
    {
        $history = [];
        $open = null;
        $quiet = 0;
        $created = [];

        foreach ($observed as $day => $score) {
            if ($score === null) {
                continue;
            }

            $at = ScenarioRunner::BASE_TIME + $day * 86400;
            $decision = AlertRules::decide($rules, $score, $at, $history, $open);

            if ($decision !== null) {
                if ($decision['action'] === AlertRules::CREATE) {
                    $created[] = ['day' => $day, 'action' => AlertRules::CREATE];
                    $open = $score;
                } elseif ($decision['action'] === AlertRules::ESCALATE) {
                    $created[] = ['day' => $day, 'action' => AlertRules::ESCALATE];
                    $open = $score;
                }
            }

            $quiet = $score < 5 ? $quiet + 1 : 0;
            if ($quiet >= 2) {
                $open = null;
            }

            $history[] = ['at' => $at, 'score' => $score];
        }

        return $created;
    }

    /** Named settings compared in the tuning table. [absolute limit|null, rise limit|null, window days] */
    public static function configs(): array
    {
        return [
            'Default (7, or +3 in 3 days)' => [7, 3, 3],
            'Absolute only (7)' => [7, null, 3],
            'Rise only (+3 in 3 days)' => [null, 3, 3],
            'Rise +4 in 3 days (less sensitive)' => [7, 4, 3],
            'Rise +3 in 2 days' => [7, 3, 2],
            'Rise +2 in 3 days (more sensitive)' => [7, 2, 3],
            'Absolute 6' => [6, 3, 3],
            'Absolute 8' => [8, 3, 3],
            'Default plus 7-day creep rule (+3 in 7 days)' => [7, 3, 3, [3, 7]],
        ];
    }

    private static function rulesFor(array $config): array
    {
        [$absolute, $rise, $window] = $config;
        $rules = [];

        if ($absolute !== null) {
            $rules[] = ['threshold_value' => $absolute, 'window_days' => null];
        }
        if ($rise !== null) {
            $rules[] = ['threshold_value' => $rise, 'window_days' => $window];
        }
        if (isset($config[3])) {
            $rules[] = ['threshold_value' => $config[3][0], 'window_days' => $config[3][1]];
        }

        return $rules;
    }

    /** Score one configuration over a cohort. */
    public static function evaluate(array $patients, array $config): array
    {
        $rules = self::rulesFor($config);
        $withEvent = 0;
        $detected = 0;
        $leads = [];
        $falseAlerts = 0;
        $trueAlerts = 0;
        $totalAlerts = 0;
        $patientDays = 0;
        $byType = [];

        foreach ($patients as $patient) {
            $shown = self::alertDays($patient['observed'], $rules);
            $days = array_column($shown, 'day');
            $event = $patient['event_day'];
            $patientDays += self::DAYS;
            $totalAlerts += count(array_filter($shown, fn ($a) => $a['action'] === AlertRules::CREATE));
            $type = $patient['type'];

            $byType[$type] ??= ['patients' => 0, 'event' => 0, 'detected' => 0];
            $byType[$type]['patients']++;

            // Escalations count towards detection (the clinician sees the alert get worse) but only new alerts
            // can be false alarms or true alerts, because only they add to the clinician's workload.
            foreach ($shown as ['day' => $day, 'action' => $action]) {
                if ($action !== AlertRules::CREATE) {
                    continue;
                }

                if ($event !== null && $day >= $event - self::EARLY_DAYS && $day <= $event + self::GRACE_DAYS) {
                    $trueAlerts++;
                } elseif ($event !== null && $day > $event + self::GRACE_DAYS) {
                    // After the event the clinician already knows; a follow-on alert is neither a miss nor a false alarm.
                    continue;
                } else {
                    $falseAlerts++;
                }
            }

            if ($event !== null) {
                $withEvent++;
                $byType[$type]['event']++;

                $hit = array_values(array_filter($days, fn ($d) => $d >= $event - self::EARLY_DAYS && $d <= $event + self::GRACE_DAYS));

                if ($hit !== []) {
                    $detected++;
                    $byType[$type]['detected']++;
                    $leads[] = $event - $hit[0];
                }
            }
        }

        return [
            'patients' => count($patients),
            'with_event' => $withEvent,
            'detected' => $detected,
            'sensitivity' => $withEvent ? $detected / $withEvent : null,
            'mean_lead_days' => $leads ? array_sum($leads) / count($leads) : null,
            'early_warned' => count(array_filter($leads, fn ($l) => $l > 0)),
            'alerts' => $totalAlerts,
            'false_alerts' => $falseAlerts,
            'false_per_100_patient_weeks' => $patientDays ? $falseAlerts / ($patientDays / 7) * 100 : 0.0,
            'by_type' => $byType,
        ];
    }

    /** @return array<string, array> results keyed by config name */
    public static function compare(array $patients): array
    {
        $out = [];
        foreach (self::configs() as $name => $config) {
            $out[$name] = self::evaluate($patients, $config);
        }

        return $out;
    }
}
