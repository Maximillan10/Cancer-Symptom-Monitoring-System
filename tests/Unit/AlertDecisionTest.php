<?php

namespace Tests\Unit;

use App\Services\AlertRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** assess() and decide(): the whole decision for one symptom of one check-in, without a database. */
class AlertDecisionTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const DAY = 86400;

    private const DEFAULTS = [
        ['threshold_value' => 7, 'window_days' => null],
        ['threshold_value' => 3, 'window_days' => 3],
    ];

    private static function earlier(int $daysAgo, ?int $score): array
    {
        return ['at' => self::NOW - $daysAgo * self::DAY, 'score' => $score];
    }

    public static function assessCases(): array
    {
        return [
            'quiet' => [self::DEFAULTS, 3, [self::earlier(1, 3)], false],
            'absolute at the limit' => [self::DEFAULTS, 7, [], true],
            'absolute below the limit' => [self::DEFAULTS, 6, [], false],
            'rise of exactly the limit' => [self::DEFAULTS, 5, [self::earlier(2, 2)], true],
            'rise one short' => [self::DEFAULTS, 4, [self::earlier(2, 2)], false],
            'rise measured from the lowest earlier score' => [self::DEFAULTS, 5, [self::earlier(2, 4), self::earlier(1, 2)], true],
            'baseline exactly at the window edge counts' => [self::DEFAULTS, 5, [self::earlier(3, 2)], true],
            'baseline just outside the window is ignored' => [self::DEFAULTS, 5, [['at' => self::NOW - 3 * self::DAY - 1, 'score' => 2]], false],
            'a later reading is not a baseline' => [self::DEFAULTS, 5, [self::earlier(-1, 2)], false],
            'a missing earlier score is ignored' => [self::DEFAULTS, 5, [self::earlier(1, null)], false],
            'a zero baseline is a real reading' => [self::DEFAULTS, 3, [self::earlier(1, 0)], true],
            'no history, rise rule cannot fire' => [self::DEFAULTS, 6, [], false],
            'no rules at all' => [[], 10, [], false],
            'a 7 day window sees an older baseline' => [[['threshold_value' => 3, 'window_days' => 7]], 5, [self::earlier(6, 2)], true],
        ];
    }

    #[DataProvider('assessCases')]
    public function test_assess(array $rules, int $score, array $history, bool $fires): void
    {
        $reason = AlertRules::assess($rules, $score, self::NOW, $history);

        $fires ? $this->assertNotNull($reason) : $this->assertNull($reason);
    }

    public function test_both_rules_firing_gives_both_reasons(): void
    {
        $reason = AlertRules::assess(self::DEFAULTS, 9, self::NOW, [self::earlier(1, 2)]);

        $this->assertStringContainsString('reached the limit of 7', $reason);
        $this->assertStringContainsString('Rose by 7 (2 to 9)', $reason);
    }

    public static function decideCases(): array
    {
        return [
            'nothing fires' => [3, null, null],
            'first breach creates' => [8, null, AlertRules::CREATE],
            'worse than the open alert escalates' => [9, 8, AlertRules::ESCALATE],
            'equal to the open alert is skipped' => [8, 8, AlertRules::SKIP],
            'lower than the open alert is skipped' => [7, 9, AlertRules::SKIP],
        ];
    }

    #[DataProvider('decideCases')]
    public function test_decide(int $score, ?int $open, ?string $action): void
    {
        $decision = AlertRules::decide(self::DEFAULTS, $score, self::NOW, [], $open);

        $action === null ? $this->assertNull($decision) : $this->assertSame($action, $decision['action']);
    }

    public function test_a_decision_carries_a_readable_reason(): void
    {
        $decision = AlertRules::decide(self::DEFAULTS, 8, self::NOW, [], null);

        $this->assertSame('Score 8 reached the limit of 7', $decision['reason']);
    }

    public static function priorityCases(): array
    {
        return [[10, 'critical'], [9, 'critical'], [8, 'warning'], [7, 'warning'], [6, 'informational'], [0, 'informational']];
    }

    #[DataProvider('priorityCases')]
    public function test_priority_bands(int $severity, string $band): void
    {
        $this->assertSame($band, AlertRules::priority($severity));
    }
}
