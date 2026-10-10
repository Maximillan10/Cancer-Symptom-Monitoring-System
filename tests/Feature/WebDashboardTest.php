<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertThreshold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesClinicalData;
use Tests\TestCase;

class WebDashboardTest extends TestCase
{
    use CreatesClinicalData;
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page(): void
    {
        foreach (['/dashboard', '/alerts', '/settings/thresholds'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_the_login_page_loads(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_a_clinician_can_sign_in_and_reach_the_dashboard(): void
    {
        $user = $this->clinician(['password' => 'password123']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password123'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_does_not_sign_in(): void
    {
        $user = $this->clinician(['password' => 'password123']);

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'nope'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_signs_the_clinician_out(): void
    {
        $this->actingAs($this->clinician())->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_dashboard_lists_only_my_patients(): void
    {
        $me = $this->clinician();
        $this->patientFor($me, ['name' => 'Visible Vera']);
        $this->patientFor($this->clinician(), ['name' => 'Hidden Hank']);

        $this->actingAs($me)->get('/dashboard')->assertOk()->assertSee('Visible Vera')->assertDontSee('Hidden Hank');
    }

    public function test_an_admin_sees_every_patient_on_the_dashboard(): void
    {
        $this->patientFor($this->clinician(), ['name' => 'Visible Vera']);
        $this->patientFor($this->clinician(), ['name' => 'Visible Hank']);

        $this->actingAs($this->admin())->get('/dashboard')->assertOk()->assertSee('Visible Vera')->assertSee('Visible Hank');
    }

    public function test_the_dashboard_search_filters_patients(): void
    {
        $me = $this->clinician();
        $this->patientFor($me, ['name' => 'Alice Banda']);
        $this->patientFor($me, ['name' => 'Brian Phiri']);

        $this->actingAs($me)->get('/dashboard?search=alice')->assertOk()->assertSee('Alice Banda')->assertDontSee('Brian Phiri');
    }

    public function test_a_patient_page_opens_for_the_assigned_clinician_only(): void
    {
        $me = $this->clinician();
        $mine = $this->patientFor($me);
        $theirs = $this->patientFor($this->clinician());
        $this->checkIn($mine, ['pain' => 4]);

        $this->actingAs($me)->get("/patients/{$mine->id}")->assertOk();
        $this->actingAs($me)->get("/patients/{$theirs->id}")->assertForbidden();
    }

    public function test_a_patient_page_with_no_check_ins_still_loads(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);

        $this->actingAs($me)->get("/patients/{$patient->id}")->assertOk();
    }

    public function test_the_alert_list_shows_open_alerts_for_my_patients_only(): void
    {
        $me = $this->clinician();
        $mine = $this->patientFor($me, ['name' => 'Mine Mary']);
        $theirs = $this->patientFor($this->clinician(), ['name' => 'Theirs Tom']);
        $this->checkIn($mine, ['pain' => 9]);
        $this->checkIn($theirs, ['pain' => 9]);

        $this->actingAs($me)->get('/alerts')->assertOk()->assertSee('Mine Mary')->assertDontSee('Theirs Tom');
    }

    public function test_resolving_an_alert_records_who_and_when(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        $this->checkIn($patient, ['pain' => 9]);
        $alert = Alert::firstOrFail();

        $this->actingAs($me)->post("/alerts/{$alert->id}/resolve")->assertRedirect();

        $alert->refresh();
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame($me->id, (int) $alert->resolved_by);
    }

    public function test_a_clinician_cannot_resolve_another_clinicians_alert(): void
    {
        $patient = $this->patientFor($this->clinician());
        $this->checkIn($patient, ['pain' => 9]);
        $alert = Alert::firstOrFail();

        $this->actingAs($this->clinician())->post("/alerts/{$alert->id}/resolve")->assertForbidden();
        $this->assertNull($alert->fresh()->resolved_at);
    }

    public function test_after_resolving_the_next_severe_check_in_raises_a_new_alert(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        $this->checkIn($patient, ['pain' => 9], now()->subDays(2));
        $this->actingAs($me)->post('/alerts/'.Alert::firstOrFail()->id.'/resolve');

        $this->checkIn($patient, ['pain' => 9], now()->subDay());

        $this->assertSame(2, Alert::count());
        $this->assertSame(1, Alert::open()->count());
    }

    public function test_the_alert_tabs_split_open_and_resolved(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me, ['name' => 'Tabbed Tina']);
        $this->checkIn($patient, ['pain' => 9]);
        Alert::firstOrFail()->resolve($me);

        $this->actingAs($me)->get('/alerts?status=open')->assertOk()->assertDontSee('Tabbed Tina');
        $this->actingAs($me)->get('/alerts?status=resolved')->assertOk()->assertSee('Tabbed Tina');
        $this->actingAs($me)->get('/alerts?status=nonsense')->assertOk(); // unknown tab falls back to "open"
    }

    public function test_the_thresholds_page_loads_with_defaults(): void
    {
        $this->actingAs($this->clinician())->get('/settings/thresholds')->assertOk();
    }

    public function test_saving_thresholds_stores_two_rules_per_symptom(): void
    {
        $me = $this->clinician();

        $this->actingAs($me)->put('/settings/thresholds', [
            'absolute' => ['pain' => 5, 'fatigue' => 8],
            'rise' => ['pain' => 2],
            'window' => ['pain' => 5],
        ])->assertRedirect(route('thresholds.edit'));

        $this->assertSame(12, AlertThreshold::where('clinician_id', $me->id)->count());
        $this->assertDatabaseHas('alert_thresholds', [
            'clinician_id' => $me->id, 'symptom_type' => 'pain', 'window_days' => null, 'threshold_value' => 5, 'is_active' => true,
        ]);
        $this->assertDatabaseHas('alert_thresholds', [
            'clinician_id' => $me->id, 'symptom_type' => 'pain', 'window_days' => 5, 'threshold_value' => 2, 'is_active' => true,
        ]);
        // A blank box switches a rule off but keeps the row.
        $this->assertDatabaseHas('alert_thresholds', ['clinician_id' => $me->id, 'symptom_type' => 'nausea', 'window_days' => null, 'is_active' => false]);
    }

    public function test_saved_thresholds_change_what_raises_an_alert(): void
    {
        $me = $this->clinician();
        $patient = $this->patientFor($me);
        $this->actingAs($me)->put('/settings/thresholds', ['absolute' => ['pain' => 5]]);

        $this->checkIn($patient, ['pain' => 5]);

        $this->assertSame(1, Alert::where('symptom_type', 'pain')->count());
    }

    public function test_invalid_thresholds_are_rejected(): void
    {
        $me = $this->clinician();

        $this->actingAs($me)->from('/settings/thresholds')->put('/settings/thresholds', ['absolute' => ['pain' => 11]])
            ->assertSessionHasErrors('absolute.pain');
        $this->actingAs($me)->put('/settings/thresholds', ['window' => ['pain' => 0]])->assertSessionHasErrors('window.pain');
        $this->assertSame(0, AlertThreshold::count());
    }

    public function test_thresholds_belong_to_one_clinician_and_do_not_leak(): void
    {
        $a = $this->clinician();
        $b = $this->clinician();
        $this->actingAs($a)->put('/settings/thresholds', ['absolute' => ['pain' => 3]]);

        $this->assertSame(0, AlertThreshold::where('clinician_id', $b->id)->count());

        $patientOfB = $this->patientFor($b);
        $this->checkIn($patientOfB, ['pain' => 5]);
        $this->assertSame(0, Alert::count()); // B still has the defaults (limit 7)
    }

    public function test_reset_returns_to_the_defaults(): void
    {
        $me = $this->clinician();
        $this->actingAs($me)->put('/settings/thresholds', ['absolute' => ['pain' => 3]]);

        $this->actingAs($me)->delete('/settings/thresholds')->assertRedirect(route('thresholds.edit'));

        $this->assertSame(0, AlertThreshold::where('clinician_id', $me->id)->count());
    }
}
