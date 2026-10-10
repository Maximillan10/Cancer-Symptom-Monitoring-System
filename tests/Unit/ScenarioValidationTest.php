<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Scenarios\CohortSimulator;
use Tests\Scenarios\ScenarioLibrary;
use Tests\Scenarios\ScenarioRunner;

/** Proposal targets: at least 50 simulated scenarios, alert detection accuracy of at least 85%. */
class ScenarioValidationTest extends TestCase
{
    public function test_there_are_at_least_fifty_scenarios_with_unique_ids(): void
    {
        $scenarios = ScenarioLibrary::all();

        $this->assertGreaterThanOrEqual(50, count($scenarios));
        $this->assertCount(count($scenarios), array_unique(array_column($scenarios, 'id')));
    }

    public function test_scenarios_cover_the_required_categories(): void
    {
        $categories = array_unique(array_column(ScenarioLibrary::all(), 'category'));

        foreach (['Stable', 'Boundary', 'Rate of change', 'Deterioration', 'Alert fatigue', 'Multiple symptoms', 'Custom thresholds', 'Offline sync', 'Realistic'] as $needed) {
            $this->assertContains($needed, $categories);
        }
    }

    public function test_alert_accuracy_meets_the_proposal_target(): void
    {
        $result = ScenarioRunner::evaluateAll(ScenarioLibrary::all());

        $failed = array_map(fn ($r) => $r['id'], array_filter($result['rows'], fn ($r) => ! $r['pass']));

        $this->assertGreaterThanOrEqual(0.85, $result['scenario_accuracy'], 'Failing scenarios: '.implode(', ', $failed));
        $this->assertGreaterThanOrEqual(0.85, $result['cell_accuracy']);
    }

    public function test_every_scenario_matches_its_hand_derived_expectation(): void
    {
        $result = ScenarioRunner::evaluateAll(ScenarioLibrary::all());

        foreach ($result['rows'] as $row) {
            $this->assertTrue($row['pass'], "{$row['id']} {$row['name']}: expected ".ScenarioRunner::describe($row['expected']).', got '.ScenarioRunner::describe($row['actual']));
        }
    }

    public function test_the_cohort_is_reproducible_for_a_seed(): void
    {
        $this->assertSame(CohortSimulator::generate(7), CohortSimulator::generate(7));
        $this->assertNotSame(CohortSimulator::generate(7), CohortSimulator::generate(8));
    }

    public function test_the_default_rules_detect_most_simulated_events(): void
    {
        $result = CohortSimulator::evaluate(CohortSimulator::generate(), [7, 3, 3]);

        $this->assertGreaterThanOrEqual(50, $result['patients']);
        $this->assertGreaterThanOrEqual(0.85, $result['sensitivity']);
    }

    public function test_adding_a_rule_never_lowers_detection(): void
    {
        $patients = CohortSimulator::generate();
        $base = CohortSimulator::evaluate($patients, [7, null, 3])['detected'];
        $more = CohortSimulator::evaluate($patients, [7, 3, 3])['detected'];

        $this->assertGreaterThanOrEqual($base, $more);
    }
}
