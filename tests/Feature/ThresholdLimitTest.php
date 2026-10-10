<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertThreshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClinicalData;
use Tests\TestCase;

/** Finding f03: a limit above 9 would let a critical score of 9 pass without an alert. */
class ThresholdLimitTest extends TestCase
{
    use CreatesClinicalData;
    use RefreshDatabase;

    public function test_an_absolute_limit_of_10_is_rejected(): void
    {
        $me = $this->clinician();

        $this->actingAs($me)->put('/settings/thresholds', ['absolute' => ['pain' => 10]])
            ->assertSessionHasErrors('absolute.pain');

        $this->assertSame(0, AlertThreshold::where('clinician_id', $me->id)->count());
    }

    public function test_an_absolute_limit_of_9_is_accepted_and_catches_a_critical_score(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);

        $this->actingAs($me)->put('/settings/thresholds', ['absolute' => ['pain' => 9]])->assertSessionHasNoErrors();
        $this->checkIn($patient, ['pain' => 9]);

        $this->assertSame(1, Alert::where('symptom_type', 'pain')->count());
    }

    public function test_a_blank_box_still_switches_the_rule_off(): void
    {
        $me = $this->clinician();

        $this->actingAs($me)->put('/settings/thresholds', ['absolute' => ['pain' => '']])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('alert_thresholds', ['clinician_id' => $me->id, 'symptom_type' => 'pain', 'window_days' => null, 'is_active' => false]);
    }

    public function test_the_rise_limit_is_not_affected_by_the_cap(): void
    {
        $me = $this->clinician();

        $this->actingAs($me)->put('/settings/thresholds', ['rise' => ['pain' => 10]])->assertSessionHasNoErrors();
    }

    public function test_the_form_does_not_offer_a_limit_above_9(): void
    {
        $this->actingAs($this->clinician())->get('/settings/thresholds')->assertOk()->assertSee('max="9"', false);
    }

    public function test_the_migration_brings_an_existing_limit_of_10_down_to_9(): void
    {
        $me = $this->clinician();
        AlertThreshold::create(['clinician_id' => $me->id, 'symptom_type' => 'pain', 'threshold_value' => 10, 'window_days' => null, 'is_active' => true]);
        AlertThreshold::create(['clinician_id' => $me->id, 'symptom_type' => 'pain', 'threshold_value' => 10, 'window_days' => 3, 'is_active' => true]);

        (require database_path('migrations/2026_10_10_000001_cap_absolute_alert_limit_at_9.php'))->up();

        $this->assertDatabaseHas('alert_thresholds', ['clinician_id' => $me->id, 'window_days' => null, 'threshold_value' => 9]);
        $this->assertDatabaseHas('alert_thresholds', ['clinician_id' => $me->id, 'window_days' => 3, 'threshold_value' => 10]); // rise rule untouched
    }
}
