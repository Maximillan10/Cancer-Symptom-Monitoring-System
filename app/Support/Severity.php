<?php

namespace App\Support;

use App\Models\SymptomSubmission;

/**
 * Display helper for 0-10 symptom scores (higher = more severe).
 *
 * NOTE: these bands only colour the dashboard. The clinician-configurable
 * alert thresholds (alert_thresholds table) belong to the alert engine milestone.
 */
class Severity
{
    public const HIGH = 7;      // 7-10
    public const MODERATE = 4;  // 4-6, below that is low

    private const ABBR = [
        'pain' => 'Pa', 'fatigue' => 'Fa', 'nausea' => 'Na',
        'anxiety' => 'An', 'appetite' => 'Ap', 'dyspnea' => 'Dy',
    ];

    public static function level(?int $score): string
    {
        if ($score === null) {
            return 'none';
        }

        return $score >= self::HIGH ? 'high' : ($score >= self::MODERATE ? 'moderate' : 'low');
    }

    public static function label(?int $score): string
    {
        return match (self::level($score)) {
            'high' => 'High',
            'moderate' => 'Moderate',
            'low' => 'Low',
            default => 'No data',
        };
    }

    /** Bootstrap badge classes for a score. */
    public static function badge(?int $score): string
    {
        return match (self::level($score)) {
            'high' => 'text-bg-danger',
            'moderate' => 'text-bg-warning',
            'low' => 'text-bg-success',
            default => 'text-bg-secondary',
        };
    }

    public static function abbr(string $type): string
    {
        return self::ABBR[$type] ?? strtoupper(substr($type, 0, 2));
    }

    /** The highest-scoring symptom in a submission: ['type' => 'pain', 'score' => 8], or null. */
    public static function top(?SymptomSubmission $submission): ?array
    {
        if (! $submission) {
            return null;
        }

        $scores = $submission->severities();

        if (empty($scores)) {
            return null;
        }

        arsort($scores);
        $type = array_key_first($scores);

        return ['type' => $type, 'score' => (int) $scores[$type]];
    }
}
