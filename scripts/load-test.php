<?php

/**
 * Load test for the proposal criterion: average API response time <= 300 ms with 10 concurrent users.
 * Plain PHP (needs the curl extension, which XAMPP/PHP for Windows include). No installs.
 *
 * 1. Start the app with the seeded data:   php artisan serve
 *    (php artisan serve handles one request at a time on some setups; for a fair test run
 *     PHP_CLI_SERVER_WORKERS=10 php artisan serve   (Linux/macOS/WSL/Git Bash)  or test behind XAMPP Apache.)
 * 2. Run from the project root:
 *      php scripts/load-test.php --email=clinician1@example.com --password=secret
 *      php scripts/load-test.php --token=1|abcdef... --requests=300 --concurrency=10
 *
 * Options
 *   --base=http://127.0.0.1:8000     app URL
 *   --token=...                      Sanctum bearer token (skips login)
 *   --email= --password=             log in via POST /api/login to obtain a token
 *   --login-path=/api/login          change if your login route differs
 *   --endpoint=/api/patients         repeatable; default: /api/health, /api/me, /api/patients
 *   --concurrency=10                 simultaneous users
 *   --requests=200                   requests per endpoint
 *   --max-avg=300                    pass mark for the average, in milliseconds
 *   --json=storage/app/validation/load-test.json   where to write the result
 */

if (! extension_loaded('curl')) {
    fwrite(STDERR, "The PHP curl extension is required (enable extension=curl in php.ini).\n");
    exit(2);
}

$opt = getopt('', ['base::', 'token::', 'email::', 'password::', 'login-path::', 'endpoint::', 'concurrency::', 'requests::', 'max-avg::', 'json::']);
$base = rtrim($opt['base'] ?? 'http://127.0.0.1:8000', '/');
$concurrency = max(1, (int) ($opt['concurrency'] ?? 10));
$perEndpoint = max($concurrency, (int) ($opt['requests'] ?? 200));
$maxAvg = (float) ($opt['max-avg'] ?? 300);
$endpoints = isset($opt['endpoint']) ? (array) $opt['endpoint'] : ['/api/health', '/api/me', '/api/patients'];
$jsonOut = $opt['json'] ?? 'storage/app/validation/load-test.json';

function findToken($data): ?string
{
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            if (is_string($v) && is_string($k) && stripos($k, 'token') !== false) {
                return $v;
            }
            if (($t = findToken($v)) !== null) {
                return $t;
            }
        }
    }

    return null;
}

$token = $opt['token'] ?? null;
if (! $token && isset($opt['email'], $opt['password'])) {
    $ch = curl_init($base . ($opt['login-path'] ?? '/api/login'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['email' => $opt['email'], 'password' => $opt['password']]),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $token = findToken(json_decode((string) $body, true));
    if (! $token) {
        fwrite(STDERR, "Login failed (HTTP $code). Pass --token=... or check --login-path / credentials.\n");
        exit(2);
    }
    echo "Logged in; token acquired.\n";
}

function pct(array $sorted, float $p): float
{
    if (! $sorted) {
        return 0.0;
    }

    return $sorted[max(0, (int) ceil($p / 100 * count($sorted)) - 1)];
}

function runEndpoint(string $url, ?string $token, int $concurrency, int $total): array
{
    $mh = curl_multi_init();
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    $times = [];
    $errors = 0;
    $started = 0;
    $active = [];

    $launch = function () use (&$started, &$active, $mh, $url, $headers) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30]);
        curl_multi_add_handle($mh, $ch);
        $active[(int) $ch] = $ch;
        $started++;
    };

    $t0 = microtime(true);
    while ($started < $total && count($active) < $concurrency) {
        $launch();
    }
    while ($active) {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.05);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($info['result'] !== CURLE_OK || $code < 200 || $code >= 300) {
                $errors++;
            } else {
                $times[] = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($active[(int) $ch]);
            if ($started < $total) {
                $launch();
            }
        }
    }
    $wall = microtime(true) - $t0;
    curl_multi_close($mh);

    sort($times);

    return [
        'requests' => $total,
        'errors' => $errors,
        'avg_ms' => $times ? round(array_sum($times) / count($times), 1) : null,
        'p95_ms' => round(pct($times, 95), 1),
        'max_ms' => $times ? round(max($times), 1) : null,
        'req_per_sec' => round($total / max($wall, 0.001), 1),
    ];
}

echo sprintf("\nLoad test: %d concurrent users, %d requests per endpoint, target average <= %d ms\n\n", $concurrency, $perEndpoint, $maxAvg);
printf("%-28s %6s %6s %9s %9s %9s %8s  %s\n", 'Endpoint', 'reqs', 'errors', 'avg ms', 'p95 ms', 'max ms', 'req/s', 'result');

$results = [];
$allPass = true;
foreach ($endpoints as $ep) {
    // warm-up so that the first (cold) request does not distort the average
    runEndpoint($base . $ep, $token, 1, 2);
    $r = runEndpoint($base . $ep, $token, $concurrency, $perEndpoint);
    $pass = $r['errors'] === 0 && $r['avg_ms'] !== null && $r['avg_ms'] <= $maxAvg;
    $allPass = $allPass && $pass;
    $results[$ep] = $r + ['pass' => $pass];
    printf("%-28s %6d %6d %9s %9s %9s %8s  %s\n", $ep, $r['requests'], $r['errors'], $r['avg_ms'] ?? '-', $r['p95_ms'], $r['max_ms'] ?? '-', $r['req_per_sec'], $pass ? 'PASS' : 'FAIL');
}

$payload = ['run_at' => date('c'), 'base' => $base, 'concurrency' => $concurrency, 'requests_per_endpoint' => $perEndpoint, 'target_avg_ms' => $maxAvg, 'overall_pass' => $allPass, 'endpoints' => $results];
if (! is_dir(dirname($jsonOut))) {
    @mkdir(dirname($jsonOut), 0775, true);
}
@file_put_contents($jsonOut, json_encode($payload, JSON_PRETTY_PRINT));
echo "\nOverall: " . ($allPass ? 'PASS' : 'FAIL') . "  (details saved to $jsonOut)\n";
exit($allPass ? 0 : 1);
