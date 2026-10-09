<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertThreshold;
use App\Models\SymptomSubmission;
use App\Services\AlertEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

/**
 * Behaviour of the alert engine, observer and alerts:scan command, one rule at a time.
 * Tests labelled FINDING document the CURRENT behaviour of something worth a design decision;
 * see docs/TEST_REPORT.md. If you change the behaviour, update the test on purpose.
 */
class AlertEngineBehaviourTest extends TestCase
{
    use RefreshDatabase, ClinicalFixtures;

    private Carbon $t0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t0 = Carbon::parse('2026-03-02 08:00:00');
    }

    private function day(int $n, int $hour = 8, int $minute = 0): Carbon
    {
        return $this->t0->copy()->addDays($n)->setTime($hour, $minute);
    }

    // ---- alert content ------------------------------------------------------------------

    public function test_alert_records_symptom_score_time_and_reason(): void
    {
        $p = $this->makePatient();
        $this->checkIn($p, ['pain' => 8], $this->day(0));

        $a = Alert::where('patient_id', $p->id)->sole();
        $this->assertSame('pain', $a->symptom_type);
        $this->assertSame(8, $a->severity);
        $this->assertTrue($a->triggered_at->equalTo($this->day(0)), 'triggered_at is the check-in time, not the processing time');
        $this->assertStringContainsString('Score 8 reached the limit of 7', $a->threshold_breached);
        $this->assertNull($a->resolved_at);
        $this->assertSame('warning', $a->priority());
    }

    public function test_both_rules_can_fire_and_both_reasons_are_recorded(): void
    {
        $p = $this->makePatient();
        $this->checkIn($p, ['pain' => 2], $this->day(0));
        $this->checkIn($p, ['pain' => 8], $this->day(1));

        $reason = Alert::where('patient_id', $p->id)->sole()->threshold_breached;
        $this->assertStringContainsString('reached the limit of 7', $reason);
        $this->assertStringContainsString('Rose by 6 (2 to 8) within 3 days', $reason);
    }

    // ---- alert-fatigue control ----------------------------------------------------------

    public function test_worse_score_escalates_the_open_alert_in_place(): void
    {
        $p = $this->makePatient();
        $this->checkIn($p, ['pain' => 7], $this->day(0));
        $this->checkIn($p, ['pain' => 9], $this->day(1));

        $a = Alert::where('patient_id', $p->id)->sole();
        $this->assertSame(9, $a->severity);
        $this->assertSame('critical', $a->priority());
        $this->assertStringContainsString('Score 9', $a->threshold_breached);
        // FINDING F-04: the escalated alert keeps its original triggered_at (day 0).
        $this->assertTrue($a->triggered_at->equalTo($this->day(0)));
    }

    public function test_equal_or_lower_score_does_not_touch_the_open_alert(): void
    {
        $p = $this->makePatient();
        $this->checkIn($p, ['pain' => 8], $this->day(0));
        $before = Alert::where('patient_id', $p->id)->sole()->only(['severity', 'threshold_breached']);

        $this->checkIn($p, ['pain' => 8], $this->day(1));
        $this->checkIn($p, ['pain' => 7], $this->day(2));

        $this->assertSame(1, Alert::where('patient_id', $p->id)->count());
        $this->assertSame($before, Alert::where('patient_id', $p->id)->sole()->only(['severity', 'threshold_breached']));
    }

    public function test_alerts_are_tracked_per_symptom(): void
    {
        $p = $this->makePatient();
        $this->checkIn($p, ['pain' => 8], $this->day(0));
        $this->checkIn($p, ['nausea' => 8], $this->day(1));

        $this->assertEqualsCanonicalizing(['pain', 'nausea'], Alert::where('patient_id', $p->id)->pluck('symptom_type')->all());
    }

    // ---- rate-of-change window ----------------------------------------------------------

    public function test_reading_exactly_at_the_window_edge_counts_as_baseline(): void
    {
        $p = $this->makePatient();
        $this->checkIn($p, ['fatigue' => 2], $this->day(0));
        $this->checkIn($p, ['fatigue' => 5], $this->day(3));

        $this->assertSame(1, Alert::where('patient_id', $p->id)->count());
    }

    public function test_reading_one_minute_outside_the_window_is_not_baseline(): void
    {
        $p = $this->makePatient();
        $this->checkIn($p, ['fatigue' => 2], $this->day(0));
        $this->checkIn($p, ['fatigue' => 5], $this->day(3, 8, 1));

        $this->assertSame(0, Alert::where('patient_id', $p->id)->count());
    }

    public function test_another_patients_readings_never_form_a_baseline(): void
    {
        $clinician = $this->makeClinician();
        $a = $this->makePatient($clinician);
        $b = $this->makePatient($clinician);
        $this->checkIn($a, ['fatigue' => 1], $this->day(0));
        $this->checkIn($b, ['fatigue' => 5], $this->day(1));

        $this->assertSame(0, Alert::where('patient_id', $b->id)->count());
    }

    // ---- whose thresholds apply ---------------------------------------------------------

    public function test_each_patient_uses_their_own_clinicians_thresholds(): void
    {
        $strict = $this->makeClinician();
        $this->saveRules($strict, ['pain' => ['abs' => 5, 'rise' => [3, 3]]]);
        $default = $this->makeClinician();

        $strictPatient = $this->makePatient($strict);
        $defaultPatient = $this->makePatient($default);
        foreach ([$strictPatient, $defaultPatient] as $p) {
            $this->checkIn($p, ['pain' => 4], $this->day(0));
            $this->checkIn($p, ['pain' => 5], $this->day(1));
        }

        $this->assertSame(1, Alert::where('patient_id', $strictPatient->id)->count());
        $this->assertSame(0, Alert::where('patient_id', $defaultPatient->id)->count());
    }

    public function test_clinician_can_switch_every_rule_off(): void
    {
        $c = $this->makeClinician();
        $off = [];
        foreach (SymptomSubmission::SYMPTOM_TYPES as $s) {
            $off[$s] = ['abs', 'rise'];
        }
        $this->saveRules($c, [], $off);
        $p = $this->makePatient($c);
        $this->checkIn($p, ['pain' => 6], $this->day(0));
        $this->checkIn($p, ['pain' => 7], $this->day(1));

        $this->assertSame(0, Alert::count());
    }

    /**
     * FINDING F-02: once a clinician has saved ANY rule, defaults no longer apply to the other symptoms.
     * A clinician with only pain rules gets no alerts for fatigue, nausea, anxiety, appetite or dyspnea.
     */
    public function test_finding_f02_partial_rule_set_silences_unlisted_symptoms(): void
    {
        $c = $this->makeClinician();
        AlertThreshold::create(['clinician_id' => $c->id, 'symptom_type' => 'pain', 'threshold_value' => 7, 'window_days' => null, 'is_active' => true]);
        $p = $this->makePatient($c);

        $this->checkIn($p, ['fatigue' => 9], $this->day(0));
        $this->assertSame(0, Alert::count(), 'current behaviour: fatigue 9 raises nothing because no fatigue rule is saved');

        $this->checkIn($p, ['pain' => 9], $this->day(1));
        $this->assertSame(1, Alert::count());
    }

    /**
     * FINDING F-03: critical scores (9-10) are only alerted if the clinician's own limit allows it.
     * With a limit of 10 and the rise rule off, a score of 9 raises no alert.
     */
    public function test_finding_f03_configured_limit_above_9_lets_a_critical_score_through(): void
    {
        $c = $this->makeClinician();
        $this->saveRules($c, ['pain' => ['abs' => 10, 'rise' => [3, 3]]], ['pain' => ['rise']]);
        $p = $this->makePatient($c);
        $this->checkIn($p, ['pain' => 9], $this->day(0));

        $this->assertSame(0, Alert::count());
    }

    // ---- out-of-order sync --------------------------------------------------------------

    /** FINDING F-05: results depend on the order check-ins arrive, by design (onlyIfLatest). */
    public function test_finding_f05_older_checkin_arriving_late_is_not_evaluated(): void
    {
        $p = $this->makePatient();
        $this->checkIn($p, ['pain' => 8], $this->day(2));      // newest arrives first
        $this->checkIn($p, ['pain' => 10], $this->day(0));     // older offline check-in arrives late

        $a = Alert::where('patient_id', $p->id)->sole();
        $this->assertSame(8, $a->severity, 'the late pain-10 reading was not evaluated');
    }

    public function test_oldest_first_sync_evaluates_every_checkin(): void
    {
        $p = $this->makePatient();
        $this->checkIn($p, ['pain' => 10], $this->day(0));
        $this->checkIn($p, ['pain' => 8], $this->day(2));

        $this->assertSame(10, Alert::where('patient_id', $p->id)->sole()->severity);
    }

    // ---- observer and scan command -------------------------------------------------------

    public function test_a_failing_alert_engine_never_loses_the_checkin(): void
    {
        $this->mock(AlertEngine::class, fn ($m) => $m->shouldReceive('evaluate')->andThrow(new \RuntimeException('boom')));
        $p = $this->makePatient();

        $this->checkIn($p, ['pain' => 9], $this->day(0));

        $this->assertSame(1, SymptomSubmission::where('patient_id', $p->id)->count());
        $this->assertSame(0, Alert::count());
    }

    public function test_scan_creates_alerts_for_checkins_saved_without_the_observer(): void
    {
        $p = $this->makePatient();
        $this->checkIn($p, ['pain' => 2], $this->day(0), withoutEvents: true);
        $this->checkIn($p, ['pain' => 2], $this->day(1), withoutEvents: true);
        $this->checkIn($p, ['pain' => 8], $this->day(2), withoutEvents: true);
        $this->assertSame(0, Alert::count());

        $this->artisan('alerts:scan')->expectsOutputToContain('Scan complete: 1 alert(s) created.')->assertExitCode(0);
        $this->assertSame(1, Alert::count());
    }

    public function test_scan_is_repeatable_without_duplicates(): void
    {
        $p = $this->makePatient();
        $this->checkIn($p, ['pain' => 8], $this->day(0), withoutEvents: true);

        $this->artisan('alerts:scan')->assertExitCode(0);
        $this->artisan('alerts:scan')->expectsOutputToContain('Scan complete: 0 alert(s) created.')->assertExitCode(0);
        $this->assertSame(1, Alert::count());
    }

    public function test_scan_fresh_removes_resolved_history_and_rebuilds(): void
    {
        $c = $this->makeClinician();
        $p = $this->makePatient($c);
        $this->checkIn($p, ['pain' => 8], $this->day(0));
        $this->resolveAll($p, $c);
        $this->assertSame(1, Alert::resolved()->count());

        $this->artisan('alerts:scan', ['--fresh' => true])->assertExitCode(0);

        $this->assertSame(0, Alert::resolved()->count());
        $this->assertSame(1, Alert::open()->count());
    }

    // ---- Alert model ---------------------------------------------------------------------

    public function test_resolve_records_who_and_when(): void
    {
        $c = $this->makeClinician();
        $p = $this->makePatient($c);
        $this->checkIn($p, ['pain' => 9], $this->day(0));
        $alert = Alert::sole();

        $alert->resolve($c);

        $alert->refresh();
        $this->assertTrue($alert->isResolved());
        $this->assertSame($c->id, $alert->resolved_by);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame('critical', $alert->priority());
    }

    public function test_alert_visibility_clinician_own_admin_all(): void
    {
        $a = $this->makeClinician();
        $b = $this->makeClinician();
        $this->checkIn($this->makePatient($a), ['pain' => 9], $this->day(0));
        $this->checkIn($this->makePatient($b), ['pain' => 9], $this->day(0));

        $this->assertSame(1, Alert::visibleTo($a)->count());
        $this->assertSame(1, Alert::visibleTo($b)->count());
        $this->assertSame(2, Alert::visibleTo($this->makeAdmin())->count());
    }
}
