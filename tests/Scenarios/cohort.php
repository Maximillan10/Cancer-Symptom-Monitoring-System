<?php

/**
 * Simulated-cohort comparison of alert-rule settings.
 *   php tests/Scenarios/cohort.php [--seed=N] [--detail] [--markdown]
 */

$root = dirname(__DIR__, 2);

if (is_file($root.'/vendor/autoload.php')) {
    require $root.'/vendor/autoload.php';
} else {
    require_once $root.'/app/Services/AlertRules.php';
}

require_once __DIR__.'/ScenarioRunner.php';
require_once __DIR__.'/CohortSimulator.php';

use Tests\Scenarios\CohortSimulator;

$seed = 20260105;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--seed=')) {
        $seed = (int) substr($arg, 7);
    }
}

$patients = CohortSimulator::generate($seed);
$results = CohortSimulator::compare($patients);
$markdown = in_array('--markdown', $argv, true);

$events = array_values($results)[0]['with_event'];
echo "Cohort: ".count($patients)." simulated patients x ".CohortSimulator::DAYS." days, seed {$seed}, "
    .(CohortSimulator::MISSING_RATE * 100)."% of days missing. Patients with a true event: {$events}.\n\n";

$fmt = fn ($v, $f) => $v === null ? 'n/a' : sprintf($f, $v);

if ($markdown) {
    echo "| Setting | Sensitivity | Mean lead (days) | Warned early | False alerts / 100 patient-weeks |\n|---|---|---|---|---|\n";
}

foreach ($results as $name => $r) {
    if ($markdown) {
        printf("| %s | %s | %s | %d of %d | %.1f |\n", $name, $fmt($r['sensitivity'] === null ? null : $r['sensitivity'] * 100, '%.1f%%'),
            $fmt($r['mean_lead_days'], '%.1f'), $r['early_warned'], $r['with_event'], $r['false_per_100_patient_weeks']);
    } else {
        printf("%-48s sens %6s  lead %5s  early %3d/%-3d  false/100pw %6.1f\n", $name,
            $fmt($r['sensitivity'] === null ? null : $r['sensitivity'] * 100, '%.1f%%'),
            $fmt($r['mean_lead_days'], '%.1f'), $r['early_warned'], $r['with_event'], $r['false_per_100_patient_weeks']);
    }
}

if (in_array('--detail', $argv, true)) {
    echo "\nDefault setting by archetype (detected / with event / patients):\n";
    foreach (array_values($results)[0]['by_type'] as $type => $t) {
        printf("  %-12s %3d / %3d / %3d\n", $type, $t['detected'], $t['event'], $t['patients']);
    }
}

// Share of new alerts that were false alarms, for the default setting. The proposal's false-positive target is below 15%.
if (in_array('--false-share', $argv, true)) {
    $default = array_values($results)[0];
    printf("\nDefault setting: %d new alerts, %d false, false-alert share %.1f%% (proposal target: below 15%%).\n",
        $default['alerts'], $default['false_alerts'], $default['alerts'] ? $default['false_alerts'] / $default['alerts'] * 100 : 0);
}
