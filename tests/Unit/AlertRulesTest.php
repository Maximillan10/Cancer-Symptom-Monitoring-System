<?php

namespace Tests\Unit;

use App\Services\AlertRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the alert rules. They need no database, so they run fast:
 *   php artisan test --filter=AlertRulesTest
 */
class AlertRulesTest extends TestCase
{
    // ---- absolute rule: alert when score >= limit ----

    #[DataProvider('absoluteCases')]
    public function test_absolute_rule(int $score, int $limit, bool $expected): void
    {
        $this->assertSame($expected, AlertRules::absoluteHit($score, $limit));
    }

    public static function absoluteCases(): array
    {
        return [
            'well below the limit' => [3, 7, false],
            'one below the limit' => [6, 7, false],
            'exactly at the limit' => [7, 7, true],
            'above the limit' => [9, 7, true],
            'maximum score' => [10, 7, true],
            'zero never alerts at limit 7' => [0, 7, false],
            'low custom limit reached' => [4, 4, true],
            'limit 10 only at 10' => [9, 10, false],
        ];
    }

    // ---- baseline: the lowest earlier reading in the window ----

    #[DataProvider('baselineCases')]
    public function test_baseline(array $earlier, ?int $expected): void
    {
        $this->assertSame($expected, AlertRules::baseline($earlier));
    }

    public static function baselineCases(): array
    {
        return [
            'no earlier readings' => [[], null],
            'one earlier reading' => [[4], 4],
            'lowest of several' => [[5, 2, 6], 2],
            'zero is a real reading' => [[3, 0, 2], 0],
            'nulls are ignored' => [[null, 5, null, 3], 3],
            'only nulls means no baseline' => [[null, null], null],
        ];
    }

    // ---- rate-of-change rule: alert when score rose by >= limit above the baseline ----

    #[DataProvider('riseCases')]
    public function test_rise_rule(int $score, ?int $baseline, int $limit, bool $expected): void
    {
        $this->assertSame($expected, AlertRules::riseHit($score, $baseline, $limit));
    }

    public static function riseCases(): array
    {
        return [
            'no baseline cannot alert' => [9, null, 3, false],
            'rise of exactly the limit' => [5, 2, 3, true],
            'rise above the limit' => [8, 2, 3, true],
            'rise just below the limit' => [4, 2, 3, false],
            'no change' => [5, 5, 3, false],
            'score fell' => [2, 6, 3, false],
            'from zero to three' => [3, 0, 3, true],
            'small limit of one' => [4, 3, 1, true],
        ];
    }

    // ---- priority bands ----

    #[DataProvider('priorityCases')]
    public function test_priority(int $severity, string $expected): void
    {
        $this->assertSame($expected, AlertRules::priority($severity));
    }

    public static function priorityCases(): array
    {
        return [
            'score 4 is informational' => [4, 'informational'],
            'score 6 is informational' => [6, 'informational'],
            'score 7 is warning' => [7, 'warning'],
            'score 8 is warning' => [8, 'warning'],
            'score 9 is critical' => [9, 'critical'],
            'score 10 is critical' => [10, 'critical'],
        ];
    }

    // ---- alert-fatigue control: one open alert per patient and symptom ----

    #[DataProvider('actionCases')]
    public function test_action_when_an_alert_may_already_be_open(?int $openSeverity, int $newSeverity, string $expected): void
    {
        $this->assertSame($expected, AlertRules::action($openSeverity, $newSeverity));
    }

    public static function actionCases(): array
    {
        return [
            'nothing open creates an alert' => [null, 7, AlertRules::CREATE],
            'worse than the open alert escalates it' => [7, 9, AlertRules::ESCALATE],
            'same as the open alert is skipped' => [7, 7, AlertRules::SKIP],
            'better than the open alert is skipped' => [8, 7, AlertRules::SKIP],
            'score 0 open then 1 escalates' => [0, 1, AlertRules::ESCALATE],
        ];
    }

    // ---- wording shown to the clinician ----

    public function test_absolute_reason_text(): void
    {
        $this->assertSame('Score 8 reached the limit of 7', AlertRules::absoluteReason(8, 7));
    }

    public function test_rise_reason_text(): void
    {
        $this->assertSame(
            'Rose by 4 (2 to 6) within 3 days, limit 3',
            AlertRules::riseReason(6, 2, 3, 3)
        );
    }

    public function test_rise_reason_uses_singular_for_one_day(): void
    {
        $this->assertStringContainsString('within 1 day,', AlertRules::riseReason(6, 2, 1, 3));
    }

    // ---- a realistic sequence, the way the engine would see it ----

    public function test_deteriorating_patient_sequence(): void
    {
        // Pain scores on consecutive days: 2, 3, 4, 7. Limit 7, rise limit 3 within 3 days.
        $history = [2, 3, 4];
        $today = 7;

        $this->assertTrue(AlertRules::absoluteHit($today, 7));
        $this->assertTrue(AlertRules::riseHit($today, AlertRules::baseline($history), 3));

        // The day before (score 4) neither rule fires yet.
        $this->assertFalse(AlertRules::absoluteHit(4, 7));
        $this->assertFalse(AlertRules::riseHit(4, AlertRules::baseline([2, 3]), 3));
    }
}
