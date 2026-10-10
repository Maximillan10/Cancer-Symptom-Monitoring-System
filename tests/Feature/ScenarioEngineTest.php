<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertThreshold;
use App\Models\SymptomSubmission;
use App\Services\AlertEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesClinicalData;
use Tests\Scenarios\ScenarioLibrary;
use Tests\Scenarios\ScenarioRunner;
use Tests\TestCase;

/**
 * Runs every simulated patient scenario through the REAL AlertEngine and database (not the in-memory runner),
 * and compares the alerts with the hand-derived expectations. If this passes and tests/Scenarios/run.php passes,
 * the engine and the pure rules agree.
 */
class ScenarioEngineTest extends TestCase
{
    use CreatesClinicalData;
    use RefreshDatabase;

    public static function scenarios(): array
    {
        $cases = [];
        foreach (ScenarioLibrary::all() as $scenario) {
            $cases["{$scenario['id']} {$scenario['name']}"] = [$scenario];
        }

        return $cases;
    }

    #[DataProvider('scenarios')]
    public function test_the_real_engine_matches_the_expected_alerts(array $scenario): void
    {
        $clinician = $this->clinician();
        $patient = $this->patientFor($clinician);

        foreach ($scenario['rules'] ?? [] as $row) {
            AlertThreshold::create($row + ['clinician_id' => $clinician->id]);
        }

        $engine = app(AlertEngine::class);
        $arrivalOfAlert = [];

        foreach ($scenario['checkins'] as $i => $checkin) {
            $arrival = $i + 1;

            // Create without the observer so the engine is called exactly once, explicitly.
            $submission = SymptomSubmission::withoutEvents(fn () => SymptomSubmission::create(
                $checkin['scores'] + [
                    'patient_id' => $patient->id,
                    'submitted_at' => Carbon::createFromTimestampUTC(ScenarioRunner::BASE_TIME + $checkin['t'] * 3600),
                ]
            ));

            $engine->evaluate($submission);

            foreach (Alert::pluck('id') as $id) {
                $arrivalOfAlert[$id] ??= $arrival;
            }

            foreach ($scenario['events'] ?? [] as $event) {
                if ($event['after'] === $arrival) {
                    Alert::open()->where('symptom_type', $event['resolve'])->get()->each->resolve($clinician);
                }
            }
        }

        $actual = [];
        foreach (Alert::orderBy('id')->get() as $alert) {
            $actual[$alert->symptom_type][] = [(int) $alert->severity, $arrivalOfAlert[$alert->id]];
        }
        ksort($actual);

        $this->assertSame(
            ScenarioRunner::normaliseExpected($scenario['expect']),
            $actual,
            "{$scenario['id']} {$scenario['name']}"
        );
    }
}
