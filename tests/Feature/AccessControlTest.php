<?php

namespace Tests\Feature;

use App\Models\Alert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ClinicalFixtures;
use Tests\TestCase;

/**
 * Security checks from the proposal's "test report confirming system stability and security":
 * unauthenticated access is refused and clinicians cannot reach each other's patients or alerts.
 * Only uses routes in routes/api.php and routes/web.php; request bodies are deliberately minimal.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase, ClinicalFixtures;

    public function test_health_endpoint_is_public(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_api_requires_a_token(): void
    {
        $p = $this->makePatient();

        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/patients')->assertUnauthorized();
        $this->getJson("/api/patients/{$p->id}")->assertUnauthorized();
        $this->getJson("/api/patients/{$p->id}/symptom-submissions")->assertUnauthorized();
        $this->postJson('/api/symptom-submissions', [])->assertUnauthorized();
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_api_clinician_cannot_read_another_clinicians_patient(): void
    {
        $mine = $this->makePatient($me = $this->makeClinician());
        $theirs = $this->makePatient();
        Sanctum::actingAs($me);

        $this->getJson("/api/patients/{$mine->id}")->assertOk();
        $status = $this->getJson("/api/patients/{$theirs->id}")->status();
        $this->assertContains($status, [403, 404], "expected 403/404 for another clinician's patient, got $status");

        $status = $this->getJson("/api/patients/{$theirs->id}/symptom-submissions")->status();
        $this->assertContains($status, [403, 404], "symptom history leaked, got $status");
    }

    public function test_web_pages_redirect_guests_to_login(): void
    {
        $p = $this->makePatient();
        foreach (['/dashboard', '/alerts', '/settings/thresholds', "/patients/{$p->id}"] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_web_clinician_cannot_open_another_clinicians_patient_page(): void
    {
        $me = $this->makeClinician();
        $theirs = $this->makePatient();

        $status = $this->actingAs($me)->get(route('dashboard.patient', $theirs))->status();

        $this->assertContains($status, [403, 404], "got $status");
    }

    public function test_web_clinician_cannot_resolve_another_clinicians_alert(): void
    {
        $owner = $this->makeClinician();
        $intruder = $this->makeClinician();
        $this->checkIn($this->makePatient($owner), ['pain' => 9], Carbon::parse('2026-03-02 08:00'));
        $alert = Alert::sole();

        $status = $this->actingAs($intruder)->post(route('alerts.resolve', $alert))->status();

        $this->assertContains($status, [403, 404], "got $status");
        $this->assertNull($alert->fresh()->resolved_at, 'alert must still be open');
    }

    public function test_web_clinician_can_resolve_their_own_alert(): void
    {
        $owner = $this->makeClinician();
        $this->checkIn($this->makePatient($owner), ['pain' => 9], Carbon::parse('2026-03-02 08:00'));
        $alert = Alert::sole();

        $status = $this->actingAs($owner)->post(route('alerts.resolve', $alert))->status();

        $this->assertLessThan(400, $status);
        $this->assertNotNull($alert->fresh()->resolved_at);
        $this->assertSame($owner->id, $alert->fresh()->resolved_by);
    }

    public function test_web_alerts_page_lists_only_own_alerts(): void
    {
        $me = $this->makeClinician();
        $other = $this->makeClinician();
        $mine = $this->makePatient($me, ['name' => 'Visible Vera']);
        $theirs = $this->makePatient($other, ['name' => 'Hidden Hank']);
        $this->checkIn($mine, ['pain' => 9], Carbon::parse('2026-03-02 08:00'));
        $this->checkIn($theirs, ['pain' => 9], Carbon::parse('2026-03-02 08:00'));

        $this->actingAs($me)->get('/alerts')->assertOk()->assertSee('Visible Vera')->assertDontSee('Hidden Hank');
    }
}
