<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\SymptomSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AlertScenarios;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

/**
 * Proposal §4.4 validation:
 *   - >= 20 simulated patient scenarios across >= 4 trajectory types
 *   - alert accuracy >= 95% against pre-defined expected results
 *   - no false negatives for critical-severity events (score 9-10)
 *
 * Run:  php artisan test --filter=AlertScenarioValidationTest
 * Output for the report: storage/app/validation/scenario-results.{json,md}
 */
class AlertScenarioValidationTest extends TestCase
{
    use RefreshDatabase, ClinicalFixtures;

    /** Accuracy target from the approved proposal (§4.4). */
    private const ACCURACY_TARGET = 0.95;

    public static function scenarios(): array
    {
        return array_map(fn ($s) => [$s], AlertScenarios::all());
    }

    /** Replays a scenario through the real observer + engine and returns the alerts that exist afterwards. */
    private function replay(array $scenario): array
    {
        $start = Carbon::parse('2026-03-02 08:00:00');
        $clinician = $this->makeClinician();
        if ($scenario['rules'] !== null) {
            $this->saveRules($clinician, $scenario['rules']['set'] ?? [], $scenario['rules']['off'] ?? []);
        }
        $patient = $this->makePatient($clinician);

        foreach ($scenario['steps'] as $step) {
            $at = $start->copy()->addDays($step['day'])->setTime($step['hour'] ?? 8, 0);
            if (! empty($step['resolve'])) {
                $this->resolveAll($patient, $clinician);
                continue;
            }
            $this->checkIn($patient, $step['scores'] ?? [], $at);
        }

        $actual = Alert::where('patient_id', $patient->id)->get()->map(fn (Alert $a) => [
            'symptom' => $a->symptom_type,
            'first_day' => (int) round(($a->triggered_at->timestamp - $start->timestamp) / 86400),
            'severity' => (int) $a->severity,
            'resolved' => $a->resolved_at !== null,
        ])->all();

        return $this->normalise($actual);
    }

    private function normalise(array $alerts): array
    {
        usort($alerts, fn ($a, $b) => [$a['symptom'], $a['first_day']] <=> [$b['symptom'], $b['first_day']]);

        return array_values($alerts);
    }

    #[DataProvider('scenarios')]
    public function test_scenario(array $scenario): void
    {
        $this->assertSame(
            $this->normalise($scenario['expected']),
            $this->replay($scenario),
            "{$scenario['id']} {$scenario['name']}"
        );
    }

    public function test_coverage_meets_proposal_criteria(): void
    {
        $all = AlertScenarios::all();
        $this->assertGreaterThanOrEqual(20, count($all), 'need 20+ simulated scenarios');
        $categories = array_unique(array_column($all, 'category'));
        foreach (['stable', 'gradual_worsening', 'acute_spike', 'improving', 'fluctuating'] as $trajectory) {
            $this->assertContains($trajectory, $categories, "missing trajectory type $trajectory");
        }
    }

    public function test_accuracy_and_critical_recall_meet_targets(): void
    {
        $rows = [];
        $passed = $expectedTotal = $matched = $unexpected = $criticalEvents = $criticalMissed = 0;

        foreach (AlertScenarios::all() as $sc) {
            $actual = $this->replay($sc);
            $expected = $this->normalise($sc['expected']);

            $m = count(array_filter($expected, fn ($e) => in_array($e, $actual, true)));
            $u = count(array_filter($actual, fn ($a) => ! in_array($a, $expected, true)));
            $ok = $expected === $actual;

            // Every symptom that ever scored 9-10 must have a critical (>= 9) alert.
            foreach (AlertScenarios::maxScores($sc) as $symptom => $max) {
                if ($max >= 9) {
                    $criticalEvents++;
                    $has = collect($actual)->contains(fn ($a) => $a['symptom'] === $symptom && $a['severity'] >= 9);
                    $criticalMissed += $has ? 0 : 1;
                }
            }

            $passed += $ok ? 1 : 0;
            $expectedTotal += count($expected);
            $matched += $m;
            $unexpected += $u;
            $rows[] = ['id' => $sc['id'], 'category' => $sc['category'], 'name' => $sc['name'],
                'expected' => count($expected), 'matched' => $m, 'unexpected' => $u, 'pass' => $ok];
        }

        $n = count($rows);
        $metrics = [
            'generated_at' => now()->toIso8601String(),
            'scenarios' => $n,
            'scenarios_passed' => $passed,
            'scenario_accuracy' => round($passed / $n, 4),
            'expected_alerts' => $expectedTotal,
            'expected_alerts_found' => $matched,
            'alert_recall' => $expectedTotal ? round($matched / $expectedTotal, 4) : 1.0,
            'unexpected_alerts' => $unexpected,
            'alert_precision' => ($matched + $unexpected) ? round($matched / ($matched + $unexpected), 4) : 1.0,
            'critical_events' => $criticalEvents,
            'critical_missed' => $criticalMissed,
            'target' => self::ACCURACY_TARGET,
        ];
        $this->writeResults($metrics, $rows);

        $this->assertGreaterThanOrEqual(self::ACCURACY_TARGET, $metrics['scenario_accuracy'], 'scenario accuracy below target');
        $this->assertGreaterThanOrEqual(self::ACCURACY_TARGET, $metrics['alert_recall'], 'alert recall below target');
        $this->assertSame(0, $criticalMissed, "$criticalMissed critical event(s) produced no critical alert");
        $this->assertGreaterThan(0, $criticalEvents);
    }

    private function writeResults(array $metrics, array $rows): void
    {
        $dir = storage_path('app/validation');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents("$dir/scenario-results.json", json_encode(['metrics' => $metrics, 'scenarios' => $rows], JSON_PRETTY_PRINT));

        $md = "| ID | Trajectory | Scenario | Expected alerts | Matched | Unexpected | Result |\n|---|---|---|---|---|---|---|\n";
        foreach ($rows as $r) {
            $md .= sprintf("| %s | %s | %s | %d | %d | %d | %s |\n", $r['id'], $r['category'], $r['name'], $r['expected'], $r['matched'], $r['unexpected'], $r['pass'] ? 'PASS' : 'FAIL');
        }
        $md .= sprintf(
            "\nScenario accuracy **%.1f%%** (%d/%d) · alert recall **%.1f%%** (%d/%d) · unexpected alerts **%d** · critical events **%d**, missed **%d**\n",
            $metrics['scenario_accuracy'] * 100, $metrics['scenarios_passed'], $metrics['scenarios'],
            $metrics['alert_recall'] * 100, $metrics['expected_alerts_found'], $metrics['expected_alerts'],
            $metrics['unexpected_alerts'], $metrics['critical_events'], $metrics['critical_missed']
        );
        file_put_contents("$dir/scenario-results.md", $md);
    }
}
