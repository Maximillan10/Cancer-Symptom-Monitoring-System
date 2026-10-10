<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\SymptomSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\CreatesClinicalData;
use Tests\TestCase;

/**
 * Response-time check against the proposal target (API under 500 ms).
 *
 * This measures the application code on whatever database phpunit.xml uses (sqlite in memory by default),
 * with no network and one user, so it is a smoke test, not a load test. For the load numbers run
 * tests/load/load-test.php against `php artisan serve` with PostgreSQL.
 *
 * Run only this:  php artisan test --group=performance
 */
#[Group('performance')]
class PerformanceTest extends TestCase
{
    use CreatesClinicalData;
    use RefreshDatabase;

    private const LIMIT_MS = 500;
    private const REPEATS = 15;

    private function median(callable $request): float
    {
        $times = [];
        for ($i = 0; $i < self::REPEATS; $i++) {
            $start = hrtime(true);
            $request();
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);

        return $times[intdiv(count($times), 2)];
    }

    public function test_the_main_endpoints_answer_within_500_ms_with_a_realistic_data_set(): void
    {
        $me = $this->clinician();
        $rows = [];
        $first = null;

        foreach (range(1, 50) as $n) {
            $patient = $this->patientFor($me);
            $first ??= $patient;
            foreach (range(0, 29) as $day) {
                $rows[] = [
                    'patient_id' => $patient->id,
                    'submitted_at' => now()->subDays(29 - $day)->setTime(9, 0),
                    'pain' => ($n + $day) % 10, 'fatigue' => ($n * 2 + $day) % 10, 'nausea' => $day % 10,
                    'anxiety' => ($n + 3) % 10, 'appetite' => ($day + 5) % 10, 'dyspnea' => $n % 10,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            SymptomSubmission::withoutEvents(fn () => SymptomSubmission::insert($chunk));
        }

        Sanctum::actingAs($me);

        $results = [
            'GET /api/patients' => $this->median(fn () => $this->getJson('/api/patients')->assertOk()),
            'GET /api/patients/{id}/symptom-submissions' => $this->median(fn () => $this->getJson("/api/patients/{$first->id}/symptom-submissions")->assertOk()),
            'POST /api/symptom-submissions' => $this->median(fn () => $this->postJson('/api/symptom-submissions', ['patient_id' => $first->id, 'pain' => 3, 'fatigue' => 4])->assertCreated()),
        ];

        $this->actingAs($me);
        $results['GET /dashboard'] = $this->median(fn () => $this->get('/dashboard')->assertOk());
        $results['GET /patients/{id}'] = $this->median(fn () => $this->get("/patients/{$first->id}")->assertOk());
        $results['GET /alerts'] = $this->median(fn () => $this->get('/alerts')->assertOk());

        foreach ($results as $name => $ms) {
            fwrite(STDERR, sprintf("  %-48s median %6.1f ms\n", $name, $ms));
            $this->assertLessThan(self::LIMIT_MS, $ms, "{$name} median {$ms} ms is over the ".self::LIMIT_MS.' ms target');
        }
    }
}
