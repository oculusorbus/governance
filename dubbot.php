<?php
/**
 * DubBot GraphQL proxy
 * Forwards authenticated requests to https://api.dubbot.com/graphql
 * Requires DUBBOT_API_KEY to be defined in config.php
 *
 * Two modes:
 *   • { "query": "..." }              — pass-through for discovery queries
 *   • { "action": "fetchAllStats",
 *       "sites": [{siteId, accountId}, …] }
 *                                      — fetch stats for every site using
 *                                        server-side curl_multi parallelism;
 *                                        adapts batch size from complexity errors
 */
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!defined('DUBBOT_API_KEY') || DUBBOT_API_KEY === '') {
    http_response_code(500);
    echo json_encode(['error' => 'DUBBOT_API_KEY not set in config.php']);
    exit;
}

$body    = file_get_contents('php://input');
$payload = json_decode($body, true);

// ── Single GraphQL pass-through (used for discovery) ─────────────────────
if (!empty($payload['query'])) {
    $ch = curl_init('https://api.dubbot.com/graphql');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-KEY: ' . DUBBOT_API_KEY,
        ],
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        http_response_code(502);
        echo json_encode(['error' => 'cURL: ' . $curlError]);
        exit;
    }
    http_response_code($httpCode);
    echo $response;
    exit;
}

// ── Bulk stats fetch with server-side curl_multi batching ────────────────
if (($payload['action'] ?? '') !== 'fetchAllStats') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing query or unknown action']);
    exit;
}

$sites = $payload['sites'] ?? [];
if (empty($sites)) {
    echo json_encode(['data' => []]);
    exit;
}

const DB_FRAGMENT = '
  pagesCount online
  latestStatsSnapshot {
    score
    accessibility  { score total }
    bestPractices  { score total }
    webGovernance  { score total }
    seo            { score total }
    badLinks       { score total }
    spelling       { score total }
  }
';

function buildQuery(array $batch, int $offset): string {
    $aliases = '';
    foreach ($batch as $j => $site) {
        $sid = addslashes($site['siteId']);
        $aid = addslashes($site['accountId']);
        $aliases .= "s_" . ($offset + $j) . ": site(siteId:\"$sid\", accountId:\"$aid\") { " . DB_FRAGMENT . " }\n";
    }
    return "{ $aliases }";
}

function dubbot_ch(string $query): CurlHandle {
    $ch = curl_init('https://api.dubbot.com/graphql');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['query' => $query]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-KEY: ' . DUBBOT_API_KEY,
        ],
    ]);
    return $ch;
}

// Round 1: try all sites in one request
$ch       = dubbot_ch(buildQuery($sites, 0));
$raw      = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['error' => 'cURL: ' . $curlErr]);
    exit;
}

$json        = json_decode($raw, true) ?? [];
$complexErr  = null;
foreach ($json['errors'] ?? [] as $err) {
    if (stripos($err['message'] ?? '', 'complexity') !== false) {
        $complexErr = $err;
        break;
    }
}

if (!$complexErr) {
    // All sites fit in one request — done.
    echo json_encode(['data' => $json['data'] ?? []]);
    exit;
}

// Round 2: parse complexity error → calculate batch size → curl_multi
$batchSize = 5;
if (preg_match('/complexity of (\d+).*max complexity of (\d+)/i', $complexErr['message'] ?? '', $m)) {
    $perItem   = (float)$m[1] / count($sites);
    $batchSize = max(1, (int)floor((float)$m[2] / $perItem));
}

$batches = [];
$offset  = 0;
foreach (array_chunk($sites, $batchSize) as $batch) {
    $batches[] = ['batch' => $batch, 'offset' => $offset];
    $offset   += count($batch);
}

// Fire all batches in parallel via curl_multi
$mh      = curl_multi_init();
$handles = [];
foreach ($batches as $i => $info) {
    $ch = dubbot_ch(buildQuery($info['batch'], $info['offset']));
    curl_multi_add_handle($mh, $ch);
    $handles[$i] = ['ch' => $ch, 'offset' => $info['offset']];
}

do {
    $status = curl_multi_exec($mh, $active);
    if ($active) curl_multi_select($mh);
} while ($active && $status === CURLM_OK);

$combined = [];
foreach ($handles as $info) {
    $content = curl_multi_getcontent($info['ch']);
    curl_multi_remove_handle($mh, $info['ch']);
    curl_close($info['ch']);
    $batchJson = json_decode($content, true) ?? [];
    foreach ($batchJson['data'] ?? [] as $key => $val) {
        $combined[$key] = $val;
    }
}
curl_multi_cleanup($mh);

echo json_encode(['data' => $combined]);
