<?php

namespace Tests\Scenarios;

use App\Services\AlertRules;

/**
 * Runs a scenario (a patient's check-ins plus a clinician's rules) through the alert decision logic,
 * with no database and no framework, so it can run anywhere PHP runs.
 *
 * The decision itself is AlertRules::decide(), the same function AlertEngine calls. This class only
 * reproduces the engine's bookkeeping around it (the "newest check-in only" gate, which alert is open,
 * and the clinician resolving alerts). tests/Feature/ScenarioEngineTest.php runs the same scenarios through
 * the real AlertEngine and database, so the two can be compared.
 *
 * Scenario shape:
 *   'checkins' => list, in ARRIVAL order, of ['t' => hours since BASE_TIME, 'scores' => [symptom => ?int]]
 *   'rules'    => null for the system defaults, or rows from ruleSet()
 *   'events'   => list of ['after' => n, 'resolve' => symptom]  (clinician resolves after the n-th arrival)
 *   'expect'   => [symptom => list of [peak severity, arrival number that created the alert]]
 */
final class ScenarioRunner
{
    public const SYMPTOMS = ['pain', 'fatigue', 'nausea', 'anxiety', 'appetite', 'dyspnea'];

    /** 2026-01-05 00:00:00 UTC. Every scenario is measured in hours from here. */
    public const BASE_TIME = 1767571200;

    /**
     * Rule rows for all six symptoms, in the shape of the alert_thresholds table.
     * A null limit switches that rule off (is_active false), as the settings page does.
     *
     * @param  array<string, array{absolute?:?int, rise?:?int, window?:int}>  $overrides  per-symptom changes
     */
    public static function ruleSet(
        ?int $absolute = AlertRules::DEFAULT_ABSOLUTE,
        ?int $rise = AlertRules::DEFAULT_RISE,
        int $window = AlertRules::DEFAULT_WINDOW,
        array $overrides = []
    ): array {
        $rows = [];

        foreach (self::SYMPTOMS as $symptom) {
            $abs = array_key_exists('absolute', $overrides[$symptom] ?? []) ? $overrides[$symptom]['absolute'] : $absolute;
            $ris = array_key_exists('rise', $overrides[$symptom] ?? []) ? $overrides[$symptom]['rise'] : $rise;
            $win = $overrides[$symptom]['window'] ?? $window;

            $rows[] = [
                'symptom_type' => $symptom,
                'threshold_value' => $abs ?? AlertRules::DEFAULT_ABSOLUTE,
                'window_days' => null,
                'is_active' => $abs !== null,
            ];
            $rows[] = [
                'symptom_type' => $symptom,
                'threshold_value' => $ris ?? AlertRules::DEFAULT_RISE,
                'window_days' => $win,
                'is_active' => $ris !== null,
            ];
        }

        return $rows;
    }

    /**
     * Active rules grouped by symptom, in the shape AlertRules::decide() takes.
     * No rows at all means the clinician has saved nothing, so the system defaults apply (as in AlertEngine).
     */
    public static function activeRules(?array $rows): array
    {
        $rows ??= self::ruleSet();
        $grouped = [];

        foreach ($rows as $row) {
            if (! $row['is_active']) {
                continue;
            }

            $grouped[$row['symptom_type']][] = [
                'threshold_value' => (int) $row['threshold_value'],
                'window_days' => $row['window_days'],
            ];
        }

        return $grouped;
    }

    /**
     * @return array<string, list<array{peak:int, on:int, t:int}>>  alerts per symptom, in creation order.
     *         "peak" is the alert's final score after escalations, "on" the arrival number that created it,
     *         "t" the check-in time (hours) of that arrival.
     */
    public static function run(array $scenario): array
    {
        $rules = self::activeRules($scenario['rules'] ?? null);
        $events = $scenario['events'] ?? [];

        $stored = [];   // every check-in received so far
        $open = [];     // symptom => index into $alerts[symptom] of the currently open alert
        $alerts = [];

        foreach ($scenario['checkins'] as $i => $checkin) {
            $arrival = $i + 1;
            $stored[] = $checkin;
            $at = self::BASE_TIME + $checkin['t'] * 3600;

            // Only the patient's newest check-in is evaluated (AlertEngine::hasNewerSubmission).
            $newerExists = false;
            foreach ($stored as $other) {
                if ($other['t'] > $checkin['t']) {
                    $newerExists = true;
                    break;
                }
            }

            if (! $newerExists) {
                foreach (self::SYMPTOMS as $symptom) {
                    $score = $checkin['scores'][$symptom] ?? null;

                    if ($score === null || ! isset($rules[$symptom])) {
                        continue;
                    }

                    $history = [];
                    foreach ($stored as $j => $other) {
                        if ($j !== $i) {
                            $history[] = [
                                'at' => self::BASE_TIME + $other['t'] * 3600,
                                'score' => $other['scores'][$symptom] ?? null,
                            ];
                        }
                    }

                    $openIndex = $open[$symptom] ?? null;
                    $openSeverity = $openIndex === null ? null : $alerts[$symptom][$openIndex]['peak'];

                    $decision = AlertRules::decide($rules[$symptom], $score, $at, $history, $openSeverity);

                    if ($decision === null) {
                        continue;
                    }

                    if ($decision['action'] === AlertRules::CREATE) {
                        $alerts[$symptom][] = ['peak' => $score, 'on' => $arrival, 't' => $checkin['t']];
                        $open[$symptom] = array_key_last($alerts[$symptom]);
                    } elseif ($decision['action'] === AlertRules::ESCALATE) {
                        $alerts[$symptom][$openIndex]['peak'] = $score;
                    }
                }
            }

            foreach ($events as $event) {
                if ($event['after'] === $arrival) {
                    unset($open[$event['resolve']]);
                }
            }
        }

        return $alerts;
    }

    /** Compact form used for comparing with 'expect': symptom => list of [peak, on], symptoms sorted by name. */
    public static function summarise(array $alerts): array
    {
        $summary = [];

        foreach ($alerts as $symptom => $list) {
            $summary[$symptom] = array_map(fn ($a) => [$a['peak'], $a['on']], array_values($list));
        }

        ksort($summary);

        return $summary;
    }

    public static function normaliseExpected(array $expect): array
    {
        $expect = array_filter($expect, fn ($list) => $list !== []);
        ksort($expect);

        return $expect;
    }

    /**
     * Run every scenario and score the result.
     *
     * Scenario accuracy: the scenario passes only if every alert matches exactly (which symptoms, how many,
     * final score, and on which check-in it was raised).
     * Cell accuracy: each (scenario, symptom) pair is one yes/no decision, "does this symptom raise an alert?".
     */
    public static function evaluateAll(array $scenarios): array
    {
        $rows = [];
        $cells = ['tp' => 0, 'fp' => 0, 'fn' => 0, 'tn' => 0];
        $passed = 0;

        foreach ($scenarios as $scenario) {
            $actual = self::summarise(self::run($scenario));
            $expected = self::normaliseExpected($scenario['expect']);
            $pass = $actual === $expected;
            $passed += $pass ? 1 : 0;

            foreach (self::SYMPTOMS as $symptom) {
                $want = ! empty($expected[$symptom]);
                $got = ! empty($actual[$symptom]);
                $key = $want ? ($got ? 'tp' : 'fn') : ($got ? 'fp' : 'tn');
                $cells[$key]++;
            }

            $rows[] = [
                'id' => $scenario['id'],
                'category' => $scenario['category'],
                'name' => $scenario['name'],
                'pass' => $pass,
                'expected' => $expected,
                'actual' => $actual,
            ];
        }

        $total = count($scenarios);
        $cellTotal = array_sum($cells);

        return [
            'scenarios' => $total,
            'passed' => $passed,
            'scenario_accuracy' => $total ? $passed / $total : 0.0,
            'cells' => $cells,
            'cell_accuracy' => $cellTotal ? ($cells['tp'] + $cells['tn']) / $cellTotal : 0.0,
            'sensitivity' => ($cells['tp'] + $cells['fn']) ? $cells['tp'] / ($cells['tp'] + $cells['fn']) : null,
            'specificity' => ($cells['tn'] + $cells['fp']) ? $cells['tn'] / ($cells['tn'] + $cells['fp']) : null,
            'rows' => $rows,
        ];
    }

    /** "pain 7@1, nausea 9@1" style text for tables. */
    public static function describe(array $summary): string
    {
        if ($summary === []) {
            return 'no alerts';
        }

        $parts = [];
        foreach ($summary as $symptom => $list) {
            foreach ($list as [$peak, $on]) {
                $parts[] = "{$symptom} {$peak}@{$on}";
            }
        }

        return implode(', ', $parts);
    }
}
