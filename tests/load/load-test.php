<?php

/**
 * HTTP load test for the clinician API. Plain PHP (curl extension), no extra tools.
 *
 *   php artisan serve                       (in another terminal)
 *   php tests/load/load-test.php --email=you@example.com --password=secret
 *
 * Options:
 *   --base=http://127.0.0.1:8000   server address
 *   --email= --password=           an existing clinician (needs at least one patient; run DemoSeeder first)
 *   --concurrency=10               simultaneous requests
 *   --requests=300                 total requests
 *   --target=500                   target in ms for the 95th percentile (proposal: under 500 ms)
 *   --no-writes                    skip POST /api/symptom-submissions (otherwise it adds low-score check-ins, pain 3)
 *
 * Exit code 0 when there are no failed requests and every endpoint's p95 is under the target, otherwise 1.
 * Note: `php artisan serve` handles one request at a time unless PHP_CLI_SERVER_WORKERS is set, so high concurrency
 * mostly measures queueing. Start the server with  PHP_CLI_SERVER_WORKERS=4 php artisan serve  for a fairer test.
 */

$opts = ['base' => 'http://127.0.0.1:8000', 'email' => null, 'password' => null, 'concurrency' => 10, 'requests' => 300, 'target' => 500];
$writes = true;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-writes') {
        $writes = false;
    } elseif (preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $opts)) {
        $opts[$m[1]] = is_int($opts[$m[1]]) ? (int) $m[2] : $m[2];
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
}

if (! extension_loaded('curl')) {
    fwrite(STDERR, "The PHP curl extension is required (enable extension=curl in php.ini).\n");
    exit(2);
}
if (! $opts['email'] || ! $opts['password']) {
    fwrite(STDERR, "Give --email and --password of an existing clinician.\n");
    exit(2);
}

$base = rtrim($opts['base'], '/');

function call(string $method, string $url, ?array $json = null, ?string $token = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    if ($token) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'body' => json_decode((string) $body, true), 'error' => $error];
}

// ---- set up: sign in once, find a patient ----
$login = call('POST', "{$base}/api/login", ['email' => $opts['email'], 'password' => $opts['password']]);
if ($login['status'] !== 200 || empty($login['body']['token'])) {
    fwrite(STDERR, "Could not sign in (HTTP {$login['status']}) {$login['error']}. Is the server running at {$base}, and are the credentials right?\n");
    exit(2);
}
$token = $login['body']['token'];

$patients = call('GET', "{$base}/api/patients?per_page=1", null, $token);
$patientId = $patients['body']['data'][0]['id'] ?? null;
if (! $patientId) {
    fwrite(STDERR, "This clinician has no patients. Run the demo seeder or create a patient first.\n");
    exit(2);
}

$endpoints = [
    'GET /api/health' => ['GET', '/api/health', null, false],
    'GET /api/patients' => ['GET', '/api/patients', null, true],
    'GET /api/patients/{id}/symptom-submissions' => ['GET', "/api/patients/{$patientId}/symptom-submissions", null, true],
];
if ($writes) {
    $endpoints['POST /api/symptom-submissions'] = ['POST', '/api/symptom-submissions', ['patient_id' => $patientId, 'pain' => 3, 'fatigue' => 3], true];
}

// Round-robin plan of requests.
$names = array_keys($endpoints);
$plan = [];
for ($i = 0; $i < $opts['requests']; $i++) {
    $plan[] = $names[$i % count($names)];
}

// ---- run with curl_multi ----
$mh = curl_multi_init();
$active = [];
$stats = [];
foreach ($names as $n) {
    $stats[$n] = ['ms' => [], 'ok' => 0, 'fail' => 0, 'throttled' => 0];
}

$start = microtime(true);
$next = 0;

$launch = function () use (&$next, &$active, $plan, $endpoints, $base, $token, $mh) {
    $name = $plan[$next++];
    [$method, $path, $json, $auth] = $endpoints[$name];
    $ch = curl_init($base.$path);
    $headers = ['Accept: application/json'];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    if ($auth) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    curl_multi_add_handle($mh, $ch);
    $active[(int) $ch] = ['name' => $name, 'ch' => $ch];
};

while ($next < count($plan) && count($active) < $opts['concurrency']) {
    $launch();
}

while ($active) {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.05);

    while ($done = curl_multi_info_read($mh)) {
        $ch = $done['handle'];
        $entry = $active[(int) $ch];
        $name = $entry['name'];
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ms = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;

        $stats[$name]['ms'][] = $ms;
        if ($done['result'] === CURLE_OK && $status >= 200 && $status < 300) {
            $stats[$name]['ok']++;
        } elseif ($status === 429) {
            $stats[$name]['throttled']++;
        } else {
            $stats[$name]['fail']++;
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($active[(int) $ch]);

        if ($next < count($plan)) {
            $launch();
        }
    }
}
curl_multi_close($mh);
$elapsed = microtime(true) - $start;

// ---- report ----
$pct = function (array $values, float $p): float {
    if (! $values) {
        return 0.0;
    }
    sort($values);

    return $values[(int) min(count($values) - 1, max(0, ceil($p / 100 * count($values)) - 1))];
};

printf("\n%d requests, concurrency %d, %.1f s, %.1f requests/second\n\n", count($plan), $opts['concurrency'], $elapsed, count($plan) / $elapsed);
printf("%-46s %5s %5s %5s %8s %8s %8s %8s\n", 'Endpoint', 'ok', 'fail', '429', 'p50 ms', 'p95 ms', 'p99 ms', 'max ms');

$pass = true;
foreach ($stats as $name => $s) {
    $p95 = $pct($s['ms'], 95);
    printf("%-46s %5d %5d %5d %8.1f %8.1f %8.1f %8.1f\n", $name, $s['ok'], $s['fail'], $s['throttled'], $pct($s['ms'], 50), $p95, $pct($s['ms'], 99), $s['ms'] ? max($s['ms']) : 0);

    if ($s['fail'] > 0 || $p95 >= $opts['target']) {
        $pass = false;
    }
}

$throttled = array_sum(array_column($stats, 'throttled'));
if ($throttled > 0) {
    echo "\nNote: {$throttled} requests got HTTP 429 (rate limited). They are not counted as failures but the test did not measure them.\n";
}

echo $pass ? "\nRESULT: PASS (no failed requests, p95 under {$opts['target']} ms for every endpoint)\n" : "\nRESULT: FAIL (failed requests, or p95 at or over {$opts['target']} ms)\n";
exit($pass ? 0 : 1);
