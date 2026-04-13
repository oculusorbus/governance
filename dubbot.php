<?php
/**
 * DubBot GraphQL proxy
 * Forwards authenticated requests to https://api.dubbot.com/graphql
 * Requires DUBBOT_API_KEY to be defined in config.php
 *
 * Two modes:
 *   { "query": "..." }
 *       Pass-through for discovery queries.
 *
 *   { "action": "fetchAllStats", "sites": [{siteId, accountId}, …] }
 *       Fetch stats for all sites using server-side curl_multi parallelism.
 *       Tries everything in one request first; on a complexity error it
 *       calculates the safe batch size and fires all batches in parallel.
 */
session_start();
require_once 'config.php';

header('Content-Type: application/json');
set_time_limit(120);   // large site lists can take a while

// Always return valid JSON — catch any uncaught throwable at the top level.
set_exception_handler(function (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
});

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
$payload = json_decode($body, true) ?: [];

// ── Helper: make one cURL handle pointing at the DubBot GraphQL endpoint ──
function db_ch($query) {
    $ch = curl_init('https://api.dubbot.com/graphql');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['query' => $query]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-KEY: ' . DUBBOT_API_KEY,
        ],
    ]);
    return $ch;
}

// ── Helper: run a single synchronous cURL request ─────────────────────────
function db_request($query) {
    $ch  = db_ch($query);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => 'cURL: ' . $err];
    return json_decode($raw, true) ?: ['error' => 'Empty response from DubBot'];
}

// ── Helper: build a single aliased GraphQL query for a batch of sites ─────
function db_build_query(array $batch, int $offset) {
    $frag = '
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
    $aliases = '';
    foreach ($batch as $j => $site) {
        $sid      = addslashes($site['siteId']     ?? '');
        $aid      = addslashes($site['accountId']  ?? '');
        $aliases .= "s_" . ($offset + $j) . ": site(siteId:\"$sid\", accountId:\"$aid\") { $frag }\n";
    }
    return "{ $aliases }";
}

// ── Single GraphQL pass-through (discovery) ───────────────────────────────
if (!empty($payload['query'])) {
    $result = db_request($payload['query']);
    echo json_encode($result);
    exit;
}

// ── Bulk stats with server-side curl_multi ────────────────────────────────
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

// Round 1: try all sites in one request
$result      = db_request(db_build_query($sites, 0));
$complexErr  = null;
foreach ($result['errors'] ?? [] as $err) {
    if (stripos($err['message'] ?? '', 'complexity') !== false) {
        $complexErr = $err;
        break;
    }
}

if (!$complexErr) {
    echo json_encode(['data' => $result['data'] ?? []]);
    exit;
}

// Round 2: complexity limit — parse error, calculate batch size, curl_multi
$batchSize = 5;
if (preg_match('/complexity of (\d+).*max complexity of (\d+)/i',
               $complexErr['message'] ?? '', $m)) {
    $perItem   = (float)$m[1] / count($sites);
    $batchSize = max(1, (int)floor((float)$m[2] / $perItem));
}

$batches = [];
$offset  = 0;
foreach (array_chunk($sites, $batchSize) as $batch) {
    $batches[] = ['batch' => $batch, 'offset' => $offset, 'query' => db_build_query($batch, $offset)];
    $offset   += count($batch);
}

// Fire all batches in parallel
$mh      = curl_multi_init();
$handles = [];
foreach ($batches as $i => $info) {
    $ch = db_ch($info['query']);
    curl_multi_add_handle($mh, $ch);
    $handles[$i] = ['ch' => $ch, 'offset' => $info['offset']];
}

do {
    $status = curl_multi_exec($mh, $active);
    if ($active) {
        $waited = curl_multi_select($mh, 1.0);
        if ($waited === -1) {
            usleep(50000); // select not available — busy-wait briefly
        }
    }
} while ($active && $status === CURLM_OK);

$combined = [];
foreach ($handles as $info) {
    $content = curl_multi_getcontent($info['ch']);
    curl_multi_remove_handle($mh, $info['ch']);
    curl_close($info['ch']);
    if (!$content) continue;
    $batchResult = json_decode($content, true) ?: [];
    foreach ($batchResult['data'] ?? [] as $key => $val) {
        $combined[$key] = $val;
    }
}
curl_multi_cleanup($mh);

echo json_encode(['data' => $combined]);
