<?php

namespace Tests\Feature\Api;

use App\Models\Alert;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

/** /api/patients (index, store, show, update, destroy) */
class PatientEndpointsTest extends TestCase
{
    use RefreshDatabase, ClinicalFixtures;

    private function payload(array $over = []): array
    {
        return $over + ['name' => 'New Patient', 'dob' => '1980-05-05', 'diagnosis' => 'Breast cancer', 'mobile_app_user_id' => 'mob-new-1'];
    }

    // ---- index --------------------------------------------------------------------------

    public function test_index_lists_only_my_patients_with_alert_count_and_latest_checkin(): void
    {
        $me = $this->makeClinician();
        $mine = $this->makePatient($me, ['name' => 'Alpha Mine']);
        $this->makePatient(null, ['name' => 'Zulu Theirs']);
        $this->checkIn($mine, ['pain' => 9], Carbon::parse('2026-03-02 08:00'));
        Sanctum::actingAs($me);

        $r = $this->getJson('/api/patients')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alpha Mine')
            ->assertJsonPath('data.0.unresolved_alerts_count', 1)
            ->assertJsonPath('data.0.latest_submission.pain', 9);
        $this->assertSame(1, $r->json('total'));
    }

    public function test_index_for_admin_shows_everyone_sorted_by_name(): void
    {
        $this->makePatient(null, ['name' => 'Bravo']);
        $this->makePatient(null, ['name' => 'Alpha']);
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/patients')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.name', 'Alpha');
    }

    public function test_index_page_size_is_clamped_between_1_and_100(): void
    {
        Sanctum::actingAs($this->makeClinician());

        $this->assertSame(100, $this->getJson('/api/patients?per_page=1000')->json('per_page'));
        $this->assertSame(1, $this->getJson('/api/patients?per_page=0')->json('per_page'));
        $this->assertSame(15, $this->getJson('/api/patients')->json('per_page'));
    }

    public function test_index_search_is_case_insensitive_and_scoped(): void
    {
        // The controller uses PostgreSQL's ILIKE, so this only runs on a pgsql test database (see FINDING F-06).
        if ($this->app['db']->connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('search uses ILIKE (PostgreSQL only); run the suite against pgsql to cover it');
        }
        $me = $this->makeClinician();
        $this->makePatient($me, ['name' => 'Mwansa Banda']);
        $this->makePatient($me, ['name' => 'Chanda Phiri']);
        $this->makePatient(null, ['name' => 'Mwansa Other']);
        Sanctum::actingAs($me);

        $this->getJson('/api/patients?search=MWANSA')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mwansa Banda');
    }

    // ---- store --------------------------------------------------------------------------

    public function test_clinician_creates_a_patient_assigned_to_themselves(): void
    {
        $me = $this->makeClinician();
        $other = $this->makeClinician();
        Sanctum::actingAs($me);

        $this->postJson('/api/patients', $this->payload(['assigned_clinician_id' => $other->id]))
            ->assertCreated()
            ->assertJsonPath('assigned_clinician_id', $me->id)
            ->assertJsonPath('clinician.id', $me->id);
        $this->assertDatabaseHas('patients', ['name' => 'New Patient', 'assigned_clinician_id' => $me->id]);
    }

    public function test_admin_can_assign_a_patient_to_a_clinician(): void
    {
        $clinician = $this->makeClinician();
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson('/api/patients', $this->payload(['assigned_clinician_id' => $clinician->id]))
            ->assertCreated()->assertJsonPath('assigned_clinician_id', $clinician->id);
    }

    public function test_admin_without_an_assignee_owns_the_patient(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $this->postJson('/api/patients', $this->payload())->assertCreated()->assertJsonPath('assigned_clinician_id', $admin->id);
    }

    public function test_optional_fields_may_be_omitted(): void
    {
        Sanctum::actingAs($this->makeClinician());

        $this->postJson('/api/patients', ['name' => 'Minimal', 'dob' => '1990-01-01'])->assertCreated();
    }

    public function test_store_validation(): void
    {
        Sanctum::actingAs($this->makeClinician());

        $this->postJson('/api/patients', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'dob']);
        $this->postJson('/api/patients', $this->payload(['dob' => now()->addDay()->toDateString()]))
            ->assertUnprocessable()->assertJsonValidationErrors('dob');
        $this->postJson('/api/patients', $this->payload(['assigned_clinician_id' => 999999]))
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_clinician_id');
        $this->postJson('/api/patients', $this->payload(['name' => str_repeat('x', 256)]))
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_mobile_app_user_id_must_be_unique(): void
    {
        Sanctum::actingAs($this->makeClinician());
        $this->postJson('/api/patients', $this->payload())->assertCreated();

        $this->postJson('/api/patients', $this->payload(['name' => 'Second']))
            ->assertUnprocessable()->assertJsonValidationErrors('mobile_app_user_id');
    }

    // ---- show ---------------------------------------------------------------------------

    public function test_show_returns_my_patient_with_clinician_and_alert_count(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me);
        Sanctum::actingAs($me);

        $this->getJson("/api/patients/{$p->id}")->assertOk()
            ->assertJsonPath('id', $p->id)->assertJsonPath('clinician.id', $me->id)->assertJsonPath('unresolved_alerts_count', 0);
    }

    public function test_show_refuses_another_clinicians_patient_and_unknown_ids(): void
    {
        $theirs = $this->makePatient();
        Sanctum::actingAs($this->makeClinician());

        $this->getJson("/api/patients/{$theirs->id}")->assertForbidden();
        $this->getJson('/api/patients/999999')->assertNotFound();
    }

    public function test_admin_can_show_any_patient(): void
    {
        $p = $this->makePatient();
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson("/api/patients/{$p->id}")->assertOk();
    }

    // ---- update -------------------------------------------------------------------------

    public function test_partial_update_changes_only_the_given_fields(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me, ['name' => 'Before', 'diagnosis' => 'Keep me']);
        Sanctum::actingAs($me);

        $this->patchJson("/api/patients/{$p->id}", ['name' => 'After'])->assertOk()->assertJsonPath('name', 'After')->assertJsonPath('diagnosis', 'Keep me');
    }

    public function test_update_can_keep_the_same_mobile_id_but_not_steal_another(): void
    {
        $me = $this->makeClinician();
        $a = $this->makePatient($me, ['mobile_app_user_id' => 'mob-a']);
        $this->makePatient($me, ['mobile_app_user_id' => 'mob-b']);
        Sanctum::actingAs($me);

        $this->putJson("/api/patients/{$a->id}", ['mobile_app_user_id' => 'mob-a'])->assertOk();
        $this->putJson("/api/patients/{$a->id}", ['mobile_app_user_id' => 'mob-b'])->assertUnprocessable()->assertJsonValidationErrors('mobile_app_user_id');
    }

    public function test_only_admins_can_reassign_a_patient(): void
    {
        $me = $this->makeClinician();
        $other = $this->makeClinician();
        $p = $this->makePatient($me);

        Sanctum::actingAs($me);
        $this->patchJson("/api/patients/{$p->id}", ['assigned_clinician_id' => $other->id])->assertOk();
        $this->assertSame($me->id, $p->fresh()->assigned_clinician_id, 'a clinician must not be able to hand a patient to someone else');

        Sanctum::actingAs($this->makeAdmin());
        $this->patchJson("/api/patients/{$p->id}", ['assigned_clinician_id' => $other->id])->assertOk();
        $this->assertSame($other->id, $p->fresh()->assigned_clinician_id);
    }

    public function test_update_and_delete_refuse_another_clinicians_patient(): void
    {
        $theirs = $this->makePatient(null, ['name' => 'Untouched']);
        Sanctum::actingAs($this->makeClinician());

        $this->putJson("/api/patients/{$theirs->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->deleteJson("/api/patients/{$theirs->id}")->assertForbidden();
        $this->assertSame('Untouched', $theirs->fresh()->name);
    }

    // ---- destroy ------------------------------------------------------------------------

    public function test_destroy_removes_the_patient_and_their_data(): void
    {
        $me = $this->makeClinician();
        $p = $this->makePatient($me);
        $this->checkIn($p, ['pain' => 9], Carbon::parse('2026-03-02 08:00'));
        $this->assertSame(1, Alert::count());
        Sanctum::actingAs($me);

        $this->deleteJson("/api/patients/{$p->id}")->assertNoContent();

        $this->assertDatabaseMissing('patients', ['id' => $p->id]);
        $this->assertDatabaseCount('symptom_submissions', 0);
        $this->assertDatabaseCount('alerts', 0);
    }
}
