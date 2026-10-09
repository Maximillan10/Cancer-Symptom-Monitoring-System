<?php

namespace App\Services;

/**
 * The alert rules as plain PHP: no database, no framework.
 * AlertEngine reads the data and passes it in, so these rules can be unit-tested directly
 * (see tests/Unit/AlertRulesTest.php).
 */
final class AlertRules
{
    /** Priority bands, based on the symptom score (0-10) that raised the alert. */
    public const CRITICAL_FROM = 9;
    public const WARNING_FROM = 7;

    public const CREATE = 'create';
    public const ESCALATE = 'escalate';
    public const SKIP = 'skip';

    /** Absolute rule: alert when the score is at or above the limit. */
    public static function absoluteHit(int $score, int $limit): bool
    {
        return $score >= $limit;
    }

    /**
     * Baseline for the rate-of-change rule: the lowest earlier score in the window.
     * Returns null when there is no earlier reading, because a rise cannot be measured without one.
     * A score of 0 is a real reading and is kept.
     */
    public static function baseline(array $earlierScores): ?int
    {
        $scores = array_values(array_filter($earlierScores, fn ($score) => $score !== null));

        return $scores === [] ? null : (int) min($scores);
    }

    /** Rate-of-change rule: alert when the score has risen by at least $limit points above the baseline. */
    public static function riseHit(int $score, ?int $baseline, int $limit): bool
    {
        return $baseline !== null && ($score - $baseline) >= $limit;
    }

    /** critical (9-10), warning (7-8), informational (below 7). */
    public static function priority(int $severity): string
    {
        if ($severity >= self::CRITICAL_FROM) {
            return 'critical';
        }

        if ($severity >= self::WARNING_FROM) {
            return 'warning';
        }

        return 'informational';
    }

    /**
     * Alert-fatigue control. While an alert for the same patient and symptom is still open:
     *  - a worse score escalates the open alert (its score is raised) instead of adding a duplicate,
     *  - an equal or lower score is skipped.
     * Once the alert is resolved, the next breach creates a new alert.
     */
    public static function action(?int $openSeverity, int $newSeverity): string
    {
        if ($openSeverity === null) {
            return self::CREATE;
        }

        return $newSeverity > $openSeverity ? self::ESCALATE : self::SKIP;
    }

    public static function absoluteReason(int $score, int $limit): string
    {
        return "Score {$score} reached the limit of {$limit}";
    }

    public static function riseReason(int $score, int $baseline, int $days, int $limit): string
    {
        $rise = $score - $baseline;
        $dayWord = $days === 1 ? 'day' : 'days';

        return "Rose by {$rise} ({$baseline} to {$score}) within {$days} {$dayWord}, limit {$limit}";
    }
}
