<?php

namespace Tests\Unit;

use App\Services\AlertRules;
use PHPUnit\Framework\TestCase;

/** Pure-logic boundary tests for AlertRules (no database, no framework). */
class AlertRulesBoundaryTest extends TestCase
{
    public function test_absolute_rule_boundaries(): void
    {
        $this->assertFalse(AlertRules::absoluteHit(6, 7));
        $this->assertTrue(AlertRules::absoluteHit(7, 7));
        $this->assertTrue(AlertRules::absoluteHit(10, 7));
        $this->assertTrue(AlertRules::absoluteHit(0, 0));
        $this->assertFalse(AlertRules::absoluteHit(0, 1));
    }

    public function test_priority_bands_cover_every_score_0_to_10(): void
    {
        $expected = [0 => 'informational', 1 => 'informational', 2 => 'informational', 3 => 'informational', 4 => 'informational',
            5 => 'informational', 6 => 'informational', 7 => 'warning', 8 => 'warning', 9 => 'critical', 10 => 'critical'];
        foreach ($expected as $score => $band) {
            $this->assertSame($band, AlertRules::priority($score), "score $score");
        }
    }

    public function test_baseline_is_lowest_earlier_score_and_keeps_zero(): void
    {
        $this->assertNull(AlertRules::baseline([]));
        $this->assertNull(AlertRules::baseline([null, null]));
        $this->assertSame(0, AlertRules::baseline([0, 3, 5]));
        $this->assertSame(0, AlertRules::baseline([null, 0]));
        $this->assertSame(2, AlertRules::baseline([5, 2, 4]));
        $this->assertSame(4, AlertRules::baseline([4]));
    }

    public function test_rise_rule_boundaries(): void
    {
        $this->assertFalse(AlertRules::riseHit(4, 2, 3), 'rise 2 < 3');
        $this->assertTrue(AlertRules::riseHit(5, 2, 3), 'rise exactly 3');
        $this->assertTrue(AlertRules::riseHit(9, 2, 3));
        $this->assertTrue(AlertRules::riseHit(3, 0, 3), 'baseline 0 is a real reading');
        $this->assertFalse(AlertRules::riseHit(8, null, 3), 'no earlier reading means no rise can be measured');
        $this->assertFalse(AlertRules::riseHit(2, 5, 3), 'a fall is never a rise');
    }

    public function test_fatigue_control_action_matrix(): void
    {
        $this->assertSame(AlertRules::CREATE, AlertRules::action(null, 7));
        $this->assertSame(AlertRules::CREATE, AlertRules::action(null, 0));
        $this->assertSame(AlertRules::SKIP, AlertRules::action(7, 7), 'equal score is skipped');
        $this->assertSame(AlertRules::SKIP, AlertRules::action(9, 7), 'lower score is skipped');
        $this->assertSame(AlertRules::ESCALATE, AlertRules::action(7, 8), 'worse score escalates');
        $this->assertSame(AlertRules::ESCALATE, AlertRules::action(0, 1));
    }

    public function test_reason_text(): void
    {
        $this->assertSame('Score 8 reached the limit of 7', AlertRules::absoluteReason(8, 7));
        $this->assertSame('Rose by 3 (2 to 5) within 3 days, limit 3', AlertRules::riseReason(5, 2, 3, 3));
        $this->assertSame('Rose by 4 (1 to 5) within 1 day, limit 3', AlertRules::riseReason(5, 1, 1, 3));
    }
}
