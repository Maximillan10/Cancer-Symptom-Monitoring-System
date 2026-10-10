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

    /** System defaults, used until a clinician saves their own thresholds. */
    public const DEFAULT_ABSOLUTE = 7;   // alert when a score reaches 7
    public const DEFAULT_RISE = 3;       // alert when a score rises by 3 ...
    public const DEFAULT_WINDOW = 3;     // ... within 3 days

    /** Highest absolute limit a clinician may save. A limit of 10 would let a critical score of 9 pass without an alert. */
    public const MAX_ABSOLUTE_LIMIT = self::CRITICAL_FROM;

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

    /**
     * Do the clinician's rules fire for one symptom score?
     *
     * @param  array<int, array{threshold_value:int, window_days:?int}>  $rules    active rules for this symptom
     *                                         (window_days null = absolute rule, set = rise within that many days)
     * @param  int  $at       when the score was measured (unix seconds)
     * @param  array<int, array{at:int, score:?int}>  $history  the patient's OTHER readings of this symptom, any order
     * @return string|null    the reasons joined by "; ", or null when no rule fires
     */
    public static function assess(array $rules, int $score, int $at, array $history): ?string
    {
        $reasons = [];

        foreach ($rules as $rule) {
            $limit = (int) $rule['threshold_value'];
            $days = $rule['window_days'] ?? null;

            if ($days === null) {
                if (self::absoluteHit($score, $limit)) {
                    $reasons[] = self::absoluteReason($score, $limit);
                }

                continue;
            }

            $from = $at - ((int) $days * 86400);
            $earlier = [];

            foreach ($history as $reading) {
                if ($reading['score'] !== null && $reading['at'] >= $from && $reading['at'] <= $at) {
                    $earlier[] = (int) $reading['score'];
                }
            }

            $baseline = self::baseline($earlier);

            if (self::riseHit($score, $baseline, $limit)) {
                $reasons[] = self::riseReason($score, (int) $baseline, (int) $days, $limit);
            }
        }

        return $reasons === [] ? null : implode('; ', $reasons);
    }

    /**
     * The full decision for one symptom of one check-in: did a rule fire, and if so create, escalate or skip?
     *
     * @param  int|null  $openSeverity  score of the patient's open alert for this symptom, or null if none is open
     * @return array{action:string, reason:string}|null  null when no rule fired
     */
    public static function decide(array $rules, int $score, int $at, array $history, ?int $openSeverity): ?array
    {
        $reason = self::assess($rules, $score, $at, $history);

        if ($reason === null) {
            return null;
        }

        return ['action' => self::action($openSeverity, $score), 'reason' => $reason];
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
