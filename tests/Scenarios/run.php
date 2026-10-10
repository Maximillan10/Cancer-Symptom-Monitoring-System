<?php

/**
 * Runs every simulated patient scenario through the alert rules, with no database and no framework.
 *
 *   php tests/Scenarios/run.php           table of results
 *   php tests/Scenarios/run.php --json    machine-readable results
 *   php tests/Scenarios/run.php --failed  only failing scenarios
 *
 * Exit code 0 when scenario accuracy is at least 85% (the proposal's target), 1 otherwise.
 */

$root = dirname(__DIR__, 2);

if (is_file($root.'/vendor/autoload.php')) {
    require $root.'/vendor/autoload.php';
} else {
    require_once $root.'/app/Services/AlertRules.php';
}

require_once __DIR__.'/ScenarioRunner.php';
require_once __DIR__.'/ScenarioLibrary.php';

use Tests\Scenarios\ScenarioLibrary;
use Tests\Scenarios\ScenarioRunner;

$result = ScenarioRunner::evaluateAll(ScenarioLibrary::all());
$args = array_slice($argv, 1);

if (in_array('--json', $args, true)) {
    echo json_encode($result, JSON_PRETTY_PRINT), PHP_EOL;
    exit($result['scenario_accuracy'] >= 0.85 ? 0 : 1);
}

$onlyFailed = in_array('--failed', $args, true);

foreach ($result['rows'] as $row) {
    if ($onlyFailed && $row['pass']) {
        continue;
    }

    printf(
        "%-4s %-4s %-18s %s\n",
        $row['pass'] ? 'PASS' : 'FAIL',
        $row['id'],
        $row['category'],
        $row['name']
    );

    if (! $row['pass']) {
        echo '       expected: '.ScenarioRunner::describe($row['expected']).PHP_EOL;
        echo '       actual:   '.ScenarioRunner::describe($row['actual']).PHP_EOL;
    }
}

$c = $result['cells'];
printf("\nScenarios passed:   %d / %d (%.1f%%)\n", $result['passed'], $result['scenarios'], $result['scenario_accuracy'] * 100);
printf("Symptom decisions:  %.1f%% correct (TP %d, FP %d, FN %d, TN %d)\n", $result['cell_accuracy'] * 100, $c['tp'], $c['fp'], $c['fn'], $c['tn']);
printf("Sensitivity:        %s\n", $result['sensitivity'] === null ? 'n/a' : sprintf('%.1f%%', $result['sensitivity'] * 100));
printf("Specificity:        %s\n", $result['specificity'] === null ? 'n/a' : sprintf('%.1f%%', $result['specificity'] * 100));

exit($result['scenario_accuracy'] >= 0.85 ? 0 : 1);
