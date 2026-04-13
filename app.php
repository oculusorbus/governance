<?php
session_start();
require_once 'config.php';

if (empty($_SESSION['auth'])) {
    header('Location: index.php');
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die('Database connection failed.');
}

// ── Ensure dubbot_stats table exists ─────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS dubbot_stats (
        site_id         INT PRIMARY KEY,
        score           DECIMAL(6,2),
        accessibility   DECIMAL(6,2),
        best_practices  DECIMAL(6,2),
        web_governance  DECIMAL(6,2),
        seo             DECIMAL(6,2),
        bad_links       DECIMAL(6,2),
        spelling        DECIMAL(6,2),
        pages_count     INT,
        updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
");

// ── Fetch all sites with joined display values ────────────────────────────
$sites = $pdo->query("
    SELECT s.id, s.url, s.site_name, s.description,
        s.vp_area_id,            va.code  AS vp_area,
        s.college_dept_id,       cd.name  AS college_dept,
        s.support_platform_id,   sp.name  AS support_platform,
        s.support_intake_url_id, siu.url  AS support_intake_url,
        s.datastudio_id,         ds.url   AS datastudio_url,
        s.server_id,             sv.name  AS server,
        s.platform_id,           pl.name  AS platform,
        s.audience_id,           au.name  AS audience,
        s.category_id,           cat.name AS category,
        s.second_category_id,    cat2.name AS second_category,
        db.score           AS db_score,
        db.accessibility   AS db_accessibility,
        db.best_practices  AS db_best_practices,
        db.web_governance  AS db_web_governance,
        db.seo             AS db_seo,
        db.bad_links       AS db_bad_links,
        db.spelling        AS db_spelling,
        db.pages_count     AS db_pages_count,
        db.updated_at      AS db_updated_at
    FROM sites s
    LEFT JOIN vp_areas va           ON s.vp_area_id            = va.id
    LEFT JOIN colleges_depts cd     ON s.college_dept_id        = cd.id
    LEFT JOIN support_platforms sp  ON s.support_platform_id    = sp.id
    LEFT JOIN support_intake_urls siu ON s.support_intake_url_id= siu.id
    LEFT JOIN datastudios ds        ON s.datastudio_id          = ds.id
    LEFT JOIN servers sv            ON s.server_id              = sv.id
    LEFT JOIN platforms pl          ON s.platform_id            = pl.id
    LEFT JOIN audiences au          ON s.audience_id            = au.id
    LEFT JOIN categories cat        ON s.category_id            = cat.id
    LEFT JOIN categories cat2       ON s.second_category_id     = cat2.id
    LEFT JOIN dubbot_stats db       ON s.id                     = db.site_id
    ORDER BY s.url
")->fetchAll();

// ── Fetch roles indexed by site_id ────────────────────────────────────────
$rolesBySite = [];
foreach ($pdo->query("
    SELECT sr.id AS role_id, sr.site_id, sr.role,
           e.id AS emp_id, e.first_name, e.last_name, e.email
    FROM site_roles sr JOIN employees e ON sr.employee_id = e.id
    ORDER BY sr.site_id, sr.role, e.last_name, e.first_name
") as $r) {
    $rolesBySite[$r['site_id']][$r['role']][] = $r;
}

// ── VP leads indexed by vp_area_id ────────────────────────────────────────
$vpByArea = [];
foreach ($pdo->query("
    SELECT val.vp_area_id, e.id AS emp_id, e.first_name, e.last_name, e.email
    FROM vp_area_leads val JOIN employees e ON val.employee_id = e.id
    ORDER BY val.vp_area_id, e.last_name
") as $r) {
    $vpByArea[$r['vp_area_id']][] = $r;
}

// ── Lookup tables for FK dropdowns ───────────────────────────────────────
function fetchLookup(PDO $pdo, string $table, string $labelField): array {
    return $pdo->query("SELECT id, `$labelField` AS label FROM `$table` ORDER BY `$labelField`")->fetchAll();
}
$lookups = [
    'vp_areas'          => fetchLookup($pdo, 'vp_areas',          'code'),
    'colleges_depts'    => fetchLookup($pdo, 'colleges_depts',    'name'),
    'support_platforms' => fetchLookup($pdo, 'support_platforms', 'name'),
    'servers'           => fetchLookup($pdo, 'servers',           'name'),
    'platforms'         => fetchLookup($pdo, 'platforms',         'name'),
    'audiences'         => fetchLookup($pdo, 'audiences',         'name'),
    'categories'        => fetchLookup($pdo, 'categories',        'name'),
];

$employees = $pdo->query("
    SELECT id, first_name, last_name, email
    FROM employees ORDER BY last_name, first_name
")->fetchAll();

// ── Most recent DubBot sync timestamp ────────────────────────────────────
$dbLastUpdated = $pdo->query("SELECT MAX(updated_at) FROM dubbot_stats")->fetchColumn();

// ── Helpers ───────────────────────────────────────────────────────────────
function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function dbScoreBadge(float $score): string {
    $cls = $score >= 90 ? 'db-good' : ($score >= 70 ? 'db-ok' : 'db-poor');
    return '<span class="db-score ' . $cls . '">' . number_format($score, 1) . '%</span>';
}

function dbScoreAttr($val): string {
    return $val !== null ? ' data-db-saved="' . (float)$val . '"' : '';
}

function initials(string $first, string $last): string {
    return strtoupper(substr($first, 0, 1) . substr($last, 0, 1)) ?: '?';
}

function badgeColor(string $name): string {
    $palette = ['#3B82F6','#10B981','#8B5CF6','#EF4444','#F59E0B',
                '#EC4899','#6366F1','#14B8A6','#F97316','#06B6D4'];
    $h = 0;
    for ($i = 0; $i < strlen($name); $i++) {
        $h = ($h * 31 + ord($name[$i])) & 0x7fffffff;
    }
    return $palette[$h % count($palette)];
}

function filterBtn(string $col): string {
    return '<button class="filter-btn" data-col="' . $col . '" onclick="openFilter(event,\'' . $col . '\')" title="Filter">'
         . '<svg width="9" height="9" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
         . '<path d="M10 18h4v-2h-4v2zM3 6v2h18V6H3zm3 7h12v-2H6v2z"/>'
         . '</svg></button>';
}

function renderBadges(array $people): string {
    if (!$people) return '<span class="empty-cell">—</span>';
    $out = '';
    foreach ($people as $p) {
        $ini   = initials((string)$p['first_name'], (string)$p['last_name']);
        $color = badgeColor($p['last_name'] . $p['first_name']);
        $tip   = h(trim($p['last_name'] . ', ' . $p['first_name']) . ($p['email'] ? ' · ' . $p['email'] : ''));
        $out  .= "<span class=\"badge\" style=\"background:$color\" title=\"$tip\">$ini</span>";
    }
    return $out;
}

// ── Employee options per people column (for filter popovers) ─────────────
$peopleRoles = ['college_communicator','site_owner','content_lead','tech_lead','admin_contact'];
$filterPeople = ['vp_lead' => []];
foreach ($peopleRoles as $r) $filterPeople[$r] = [];
$seen = array_fill_keys(array_keys($filterPeople), []);

foreach ($vpByArea as $leads) {
    foreach ($leads as $l) {
        if (!isset($seen['vp_lead'][$l['emp_id']])) {
            $seen['vp_lead'][$l['emp_id']] = true;
            $filterPeople['vp_lead'][] = ['id' => (int)$l['emp_id'],
                'label' => $l['last_name'] . ', ' . $l['first_name']];
        }
    }
}
foreach ($rolesBySite as $roles) {
    foreach ($peopleRoles as $role) {
        foreach ($roles[$role] ?? [] as $p) {
            if (!isset($seen[$role][$p['emp_id']])) {
                $seen[$role][$p['emp_id']] = true;
                $filterPeople[$role][] = ['id' => (int)$p['emp_id'],
                    'label' => $p['last_name'] . ', ' . $p['first_name']];
            }
        }
    }
}
foreach ($filterPeople as &$opts) usort($opts, fn($a,$b) => strcmp($a['label'],$b['label']));
unset($opts);

$lookupsJson      = json_encode($lookups,       JSON_HEX_TAG | JSON_HEX_APOS);
$employeesJson    = json_encode($employees,     JSON_HEX_TAG | JSON_HEX_APOS);
$filterPeopleJson = json_encode($filterPeople,  JSON_HEX_TAG | JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Governance</title>

    <!-- UTSA Brand Fonts (Arsenal = headline, Libre Franklin = body) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Arsenal:wght@400;700&family=Libre+Franklin:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">

    <!-- Tom Select -->
    <link  rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

    <style>
        /*
         * UTSA Brand Colors
         * Midnight       #032044   River Mist   #C8DCFF
         * UTSA Orange    #F15A22   Talavera Blue #265BF7
         * Access. Orange #D3430D   Mission Clay  #DBB485
         * Brass          #A06620   Limestone     #F8F4F1
         * Concrete       #EBE6E2   Smoke         #D5CFC8
         * Brand Black    #332F21
         */

        *, *::before, *::after { box-sizing: border-box; }

        body { margin: 0; font-family: 'Libre Franklin', system-ui, sans-serif; font-size: 13px;
               background: #F8F4F1; color: #332F21; }

        /* ── Header ───────────────────────────────────────────────────── */
        #topbar { display:flex; align-items:center; gap:12px; padding:10px 16px;
                  background:#032044; color:#fff; position:sticky; top:0; z-index:50; }
        #topbar h1 { font-family:'Arsenal', system-ui, sans-serif; font-size:16px;
                     font-weight:700; margin:0; flex:1; letter-spacing:-.01em; position:relative; top:-1px; }
        #topbar button { font-size:12px; padding:5px 12px; border:none; border-radius:6px;
                         cursor:pointer; font-weight:600; transition:background .15s;
                         font-family:'Libre Franklin', system-ui, sans-serif; }
        #btn-cols  { background:#1B3A6B; color:#fff; }
        #btn-cols:hover  { background:#254e8f; }
        #btn-add   { background:#D3430D; color:#fff; }
        #btn-add:hover   { background:#B94700; }
        #btn-logout { background:#dc2626; color:#fff; }
        #btn-logout:hover { background:#b91c1c; }
        #row-count { font-size:12px; color:#C8DCFF; }

        /* ── Column visibility panel ──────────────────────────────────── */
        #col-panel { display:none; background:#032044; border-bottom:1px solid #021333;
                     padding:10px 16px; }
        #col-panel.open { display:flex; flex-wrap:wrap; align-items:flex-start; gap:6px; }
        .col-group { display:flex; flex-direction:column; gap:3px; min-width:130px; padding:6px 8px;
                     border:1px solid rgba(255,255,255,.12); border-radius:6px;
                     background:rgba(255,255,255,.07); }
        .col-group-header { display:flex; align-items:center; gap:5px; font-size:11px;
                            font-weight:700; color:#C8DCFF; text-transform:uppercase;
                            letter-spacing:.05em; cursor:pointer; user-select:none; }
        .col-group-header input[type=checkbox] { accent-color:#D3430D; }
        .col-group-children { display:flex; flex-direction:column; gap:2px;
                              padding-top:4px; margin-top:3px;
                              border-top:1px solid rgba(255,255,255,.1); }
        .col-group-children label { display:flex; align-items:center; gap:5px; font-size:12px;
                                    color:#E8E4FF; cursor:pointer; user-select:none; padding:1px 0; }
        .col-group-children input[type=checkbox] { accent-color:#D3430D; }

        /* ── Column filter buttons ────────────────────────────────────── */
        .filter-btn { position:absolute; right:4px; top:50%; transform:translateY(-50%);
                      display:inline-flex; align-items:center; background:none; border:none;
                      cursor:pointer; padding:2px; opacity:.35; color:inherit;
                      border-radius:3px; transition:opacity .15s, color .15s; }
        .filter-btn:hover { opacity:.8; }
        .filter-btn.filter-active { opacity:1; color:#D3430D; }

        /* ── Filter popover ───────────────────────────────────────────── */
        #filter-popover { display:none; position:fixed; background:#fff;
                          border:1px solid #EBE6E2; border-radius:8px;
                          box-shadow:0 6px 20px rgba(3,32,68,.12); z-index:10000;
                          padding:10px; width:230px; max-height:360px;
                          flex-direction:column; }
        #filter-popover.open { display:flex; }
        #filter-pop-search, #filter-pop-text {
            width:100%; padding:5px 8px; border:1px solid #D5CFC8; border-radius:6px;
            font-size:12px; outline:none; margin-bottom:6px; box-sizing:border-box; }
        #filter-pop-search:focus, #filter-pop-text:focus { border-color:#265BF7; }
        .filter-pop-list { overflow-y:auto; max-height:200px; margin-bottom:6px; }
        .filter-pop-item { display:flex; align-items:center; gap:6px; padding:3px 4px;
                           border-radius:4px; cursor:pointer; font-size:12px; user-select:none; }
        .filter-pop-item:hover { background:#F8F4F1; }
        .filter-pop-item input { flex-shrink:0; cursor:pointer; accent-color:#D3430D; }
        .filter-pop-sep { height:1px; background:#EBE6E2; margin:4px 0; flex-shrink:0; }
        .filter-pop-actions { display:flex; gap:6px; padding-top:8px;
                              border-top:1px solid #EBE6E2; flex-shrink:0; }
        .filter-pop-actions button { flex:1; padding:5px; border-radius:6px; border:none;
                                     cursor:pointer; font-size:12px; font-weight:600; }
        .filter-pop-actions .btn-clear { background:#EBE6E2; color:#332F21; }
        .filter-pop-actions .btn-clear:hover { background:#D5CFC8; }
        .filter-pop-actions .btn-apply { background:#265BF7; color:#fff; }
        .filter-pop-actions .btn-apply:hover { background:#1847BF; }
        #btn-clear-filters { background:#D3430D; color:#fff; }

        /* ── Table wrapper ────────────────────────────────────────────── */
        /* position:relative + z-index:1 creates a stacking context that
           scopes all sticky-cell z-indices inside it, so the body-level
           Tom Select dropdown (z-index 99999) paints above the table. */
        #table-wrap { overflow-x:auto; overflow-y:auto; max-height:calc(100vh - 66px);
                      position:relative; z-index:1; }

        /* ── Table ────────────────────────────────────────────────────── */
        table { border-collapse:collapse; width:max-content; }

        /* Group header row */
        thead tr.groups th { font-family:'Arsenal', system-ui, sans-serif;
                              font-size:11px; font-weight:700; text-transform:uppercase;
                              letter-spacing:.06em; color:#fff; padding:5px 8px;
                              line-height:18px;
                              border-right:2px solid rgba(255,255,255,.25);
                              position:sticky; top:0; z-index:10; }
        .grp-identity       { background:#0D3B6E; } /* Midnight lighter */
        .grp-governance     { background:#0D3B6E; } /* Midnight lighter */
        .grp-people         { background:#265BF7; } /* Talavera Blue */
        .grp-support        { background:#D3430D; } /* Accessible Orange */
        .grp-technical      { background:#0D3B6E; } /* Midnight lighter */
        .grp-classification { background:#A06620; } /* Brass */

        /* Column header row */
        thead tr.headers th { font-size:11px; font-weight:600; color:#6B6355;
                              background:#F8F4F1; padding:6px 20px 6px 8px; white-space:nowrap;
                              border-bottom:2px solid #EBE6E2; border-right:1px solid #EBE6E2;
                              position:sticky; top:28px; z-index:10; overflow:hidden; }

        /* Data cells */
        td { padding:5px 8px; border-bottom:1px solid #EBE6E2;
             border-right:1px solid #EBE6E2; vertical-align:middle;
             max-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
             background:#fff; }

        tr:hover td { background:rgba(200,220,255,.2); }
        tr:hover td.sticky-1 { background:#DCE8FF; }

        /* Sticky columns
           z-index hierarchy:
             20 — sticky header cells (both axes pinned, must win over everything)
             15 — sticky body cells (pinned left; must cover scrolling body cells
                  AND non-sticky header cells as they slide over the frozen columns)
             10 — non-sticky header cells (pinned top; covers scrolling body rows) */
        .sticky-1 { position:sticky; left:0; z-index:15; background:#fff; min-width:280px; max-width:280px; }
        /* Must beat specificity of "thead tr.headers th { z-index:10 }" ([0,1,3])
           so the override uses two classes + the element chain → [0,2,3] */
        thead tr.headers th.sticky-1 { z-index:20; }
        thead tr.groups  th.sticky-1 { z-index:20; }
        thead tr.groups .sticky-1 { background:#0D3B6E; }

        /* Column widths */
        .col-site               { min-width:280px; max-width:280px; }
        .col-description        { min-width:240px; max-width:240px; }
        .col-vp_area            { min-width:100px; max-width:100px; }
        .col-vp_lead            { min-width:120px; max-width:120px; }
        .col-college_dept       { min-width:140px; max-width:140px; }
        .col-college_communicator,
        .col-site_owner,
        .col-content_lead,
        .col-tech_lead,
        .col-admin_contact      { min-width:110px; max-width:110px; }
        .col-support_intake_url,
        .col-datastudio_url     { min-width:62px;  max-width:62px;  text-align:center; }
        .col-server             { min-width:130px; max-width:130px; }
        .col-platform           { min-width:130px; max-width:130px; }
        .col-audience           { min-width:90px;  max-width:90px; }
        .col-category           { min-width:130px; max-width:130px; }
        .col-second_category    { min-width:130px; max-width:130px; }

        /* Editable cells */
        td.editable { cursor:pointer; position:relative; }
        td.editable:hover { background:rgba(200,220,255,.2) !important; }
        td.editable:not(.editing):hover::after { content:'✎'; font-size:10px; color:#D5CFC8;
                                                position:absolute; right:4px; top:50%;
                                                transform:translateY(-50%); pointer-events:none; }

        /* Site column (combined URL + Site Name) */
        td.col-site { overflow:hidden; padding:4px 8px; }
        .site-inner { display:flex; align-items:center; gap:4px; overflow:hidden; }
        .site-inner a, .site-inner > span { flex:1; min-width:0; overflow:hidden;
            text-overflow:ellipsis; white-space:nowrap; text-decoration:none; }
        .site-inner a { color:#265BF7; }
        .site-inner > span.empty-cell { color:#D5CFC8; }
        .site-edit-btn { position:absolute; right:4px; top:50%; transform:translateY(-50%);
                         opacity:0; background:none; border:none;
                         cursor:pointer; color:#A09080; font-size:11px;
                         padding:2px 3px; border-radius:3px; line-height:1;
                         transition:opacity .1s, color .1s; }
        td.col-site:hover .site-edit-btn { opacity:1; }
        .site-edit-btn:hover { color:#265BF7 !important; background:#EBE6E2; }

        /* Editing state */
        td.editing { padding:2px 4px; overflow:visible; }
        td.editing input[type=text] {
            width:100%; font-size:13px; border:2px solid #265BF7; border-radius:4px;
            padding:3px 6px; outline:none; background:#fff; }

        /* Badges */
        .badge { display:inline-flex; align-items:center; justify-content:center;
                 width:26px; height:26px; border-radius:50%; color:#fff;
                 font-size:10px; font-weight:700; cursor:pointer;
                 margin:1px; transition:transform .1s; }
        .badge:hover { transform:scale(1.15); }
        .empty-cell { color:#D5CFC8; }

        /* Link cells */
        .link-cell { cursor:pointer; text-align:center; }
        .link-cell:hover { background:rgba(200,220,255,.35) !important; }
        .link-cell-icon { display:inline-flex; align-items:center; justify-content:center;
                          width:26px; height:26px; border-radius:6px; background:#C8DCFF;
                          font-size:14px; pointer-events:none; }
        .link-cell-add  { display:inline-flex; align-items:center; justify-content:center;
                          width:22px; height:22px; border-radius:6px; background:#EBE6E2;
                          color:#A09080; font-size:16px; font-weight:300; pointer-events:none; }

        /* ── Tom Select overrides ─────────────────────────────────────── */
        .ts-wrapper { min-width:100%; }
        .ts-control  { font-size:12px; border:2px solid #265BF7 !important;
                       border-radius:4px !important; min-height:28px !important;
                       padding:2px 6px !important; box-shadow:none !important; }
        .ts-dropdown { font-size:12px; z-index:99999 !important; background:#fff !important; }
        .ts-dropdown .option:hover,
        .ts-dropdown .option.active { background:#C8DCFF !important; color:#032044 !important; cursor:pointer; }

        /* ── Modal ────────────────────────────────────────────────────── */
        #modal-overlay { display:none; position:fixed; inset:0; background:rgba(3,32,68,.5);
                         z-index:200; align-items:center; justify-content:center; }
        #modal-overlay.open { display:flex; }
        #modal-box { background:#fff; border-radius:12px; padding:24px; width:460px;
                     max-height:80vh; overflow-y:auto; box-shadow:0 20px 60px rgba(3,32,68,.3); }
        #modal-box h2 { font-family:'Arsenal', system-ui, sans-serif;
                        margin:0 0 4px; font-size:17px; font-weight:700; color:#032044; }
        #modal-box .subtitle { color:#A09080; font-size:12px; margin-bottom:16px; }
        .modal-person { display:flex; align-items:center; gap:8px; padding:6px 0;
                        border-bottom:1px solid #EBE6E2; }
        .modal-person:last-child { border:none; }
        .modal-person-info { flex:1; }
        .modal-person-info .name { font-weight:600; font-size:13px; }
        .modal-person-info .email { font-size:11px; color:#A09080; }
        .btn-remove { background:none; border:none; color:#ef4444; font-size:18px;
                      cursor:pointer; padding:0 4px; line-height:1; }
        .btn-remove:hover { color:#b91c1c; }
        .modal-add { margin-top:16px; }
        .modal-add label { display:block; font-size:12px; font-weight:600;
                           color:#6B6355; margin-bottom:6px; }
        #modal-close { width:100%; margin-top:16px; padding:8px; background:#EBE6E2;
                       border:none; border-radius:8px; cursor:pointer; font-size:13px;
                       font-weight:600; color:#332F21; }
        #modal-close:hover { background:#D5CFC8; }

        /* Editable employee name/email spans */
        .emp-editable { cursor:pointer; padding:1px 3px; border-radius:3px;
                        transition:background .1s; display:inline; }
        .emp-editable:hover { background:#C8DCFF; }
        .emp-editable.emp-email { color:#A09080; font-size:11px; }
        .emp-editable.emp-no-email { color:#D5CFC8; font-size:10px; }
        .emp-editable.emp-no-email::after { content:'+ email'; }
        .emp-field-input { font-size:13px; border:1px solid #265BF7; border-radius:3px;
                           padding:1px 5px; outline:none; min-width:80px; max-width:160px; }
        .emp-field-input[data-field="email"] { font-size:11px; color:#A09080; max-width:200px; }

        /* New person form */
        .modal-new-toggle { margin-top:10px; text-align:center; }
        #btn-new-person-toggle { background:none; border:none; color:#265BF7; font-size:12px;
                                 cursor:pointer; padding:0; font-weight:600; }
        #btn-new-person-toggle:hover { text-decoration:underline; }
        #new-person-form { background:#F8F4F1; border:1px solid #EBE6E2; border-radius:8px;
                           padding:12px; margin-top:8px; }
        .new-person-fields { display:grid; grid-template-columns:1fr 1fr; gap:6px;
                             margin-bottom:10px; }
        .new-person-fields input { padding:5px 8px; border:1px solid #D5CFC8; border-radius:6px;
                                   font-size:12px; outline:none; }
        .new-person-fields input:focus { border-color:#265BF7; }
        .new-person-fields input.full-width { grid-column:1/-1; }
        #btn-create-emp { width:100%; padding:7px; background:#D3430D; color:#fff; border:none;
                          border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; }
        #btn-create-emp:hover:not(:disabled) { background:#B94700; }
        #btn-create-emp:disabled { background:#DBB485; cursor:not-allowed; }

        /* Zebra stripe */
        tbody tr:nth-child(even) td           { background:#F8F4F1; }
        tbody tr:nth-child(even) td.sticky-1  { background:#F2EDE9; }
        tbody tr:hover td                     { background:rgba(200,220,255,.25) !important; }
        tbody tr:hover td.sticky-1            { background:#DCE8FF !important; }

        /* ── DubBot columns ──────────────────────────────────────────── */
        .grp-dubbot { background:#0D7A5F; }
        .col-db-score         { min-width:68px;  max-width:68px;  text-align:center; }
        .col-db-accessibility { min-width:100px; max-width:100px; text-align:center; }
        .col-db-bestpractices { min-width:88px;  max-width:88px;  text-align:center; }
        .col-db-webgovernance { min-width:80px;  max-width:80px;  text-align:center; }
        .col-db-badlinks,
        .col-db-seo,
        .col-db-spelling      { min-width:72px;  max-width:72px;  text-align:center; }
        .col-db-pages         { min-width:60px;  max-width:60px;  text-align:right; }
        .db-score { font-size:12px; font-weight:600; }
        .db-good  { color:#15803d; }
        .db-ok    { color:#b45309; }
        .db-poor  { color:#dc2626; }
        @keyframes spin { to { transform:rotate(360deg); } }
        .db-hdr-spin { display:inline-block; width:9px; height:9px;
                       border:2px solid rgba(255,255,255,.3); border-top-color:#fff;
                       border-radius:50%; animation:spin .7s linear infinite;
                       vertical-align:middle; margin:0 3px; }
        .db-hdr-status { font-size:10px; font-weight:400; letter-spacing:0; opacity:.85; }
        .db-hdr-error  { font-size:10px; font-weight:400; letter-spacing:0; color:#fca5a5; }
        .db-refresh-btn { margin-left:6px; padding:1px 7px; font-size:10px; font-weight:600;
                          background:rgba(255,255,255,.18); color:#fff; border:1px solid rgba(255,255,255,.4);
                          border-radius:4px; cursor:pointer; vertical-align:middle; }
        .db-refresh-btn:hover:not(:disabled) { background:rgba(255,255,255,.3); }
        .db-refresh-btn:disabled { opacity:.4; cursor:not-allowed; }

        /* ── Cell tooltip ─────────────────────────────────────────────── */
        #cell-tooltip { position:fixed; pointer-events:none; z-index:99998;
                        background:#032044; color:#fff; font-size:12px; line-height:1.4;
                        padding:5px 9px; border-radius:6px; max-width:420px;
                        white-space:pre-wrap; word-break:break-all;
                        box-shadow:0 4px 14px rgba(3,32,68,.35);
                        opacity:0; transition:opacity .1s; }
        #cell-tooltip.visible { opacity:1; }
    </style>
    <!-- Hide default-hidden columns before first paint to prevent flash.
         JS re-applies localStorage prefs on DOMContentLoaded. -->
    <style id="col-hide-defaults">.col-description { display:none; }</style>
</head>
<body>

<!-- ── Cell tooltip ─────────────────────────────────────────────────────── -->
<div id="cell-tooltip"></div>

<!-- ── Top bar ──────────────────────────────────────────────────────────── -->
<div id="topbar">
    <img src="utsa-logo.svg" alt="UT San Antonio" height="20" style="flex-shrink:0">
    <h1>Website Governance Directory</h1>
    <span id="row-count"></span>
    <button id="btn-cols"         onclick="toggleColPanel()">Columns</button>
    <button id="btn-clear-filters" onclick="clearAllFilters()" style="display:none">✕ Filters</button>
    <button id="btn-add"          onclick="addSite()">+ Add Site</button>
    <a href="logout.php"><button id="btn-logout">Sign Out</button></a>
</div>

<!-- ── Column visibility panel ──────────────────────────────────────────── -->
<?php
$colGroups = [
    'General'        => ['description' => 'Description'],
    'Governance'     => ['vp_area'=>'VP Area','vp_lead'=>'VP Lead','college_dept'=>'College/Dept'],
    'People'         => ['college_communicator'=>'Communicator','site_owner'=>'Site Owner',
                         'content_lead'=>'Content Lead','tech_lead'=>'Tech Lead','admin_contact'=>'Admin Contact'],
    'Support'        => ['support_intake_url'=>'Intake URL','datastudio_url'=>'Datastudio'],
    'Technical'      => ['server'=>'Server','platform'=>'Platform','audience'=>'Audience'],
    'Classification' => ['category'=>'Category','second_category'=>'2nd Category'],
    'DubBot'         => ['db-score'=>'Score','db-accessibility'=>'Accessibility',
                         'db-badlinks'=>'Bad Links','db-seo'=>'SEO','db-spelling'=>'Spelling',
                         'db-bestpractices'=>'Best Practices','db-webgovernance'=>'Web Gov.',
                         'db-pages'=>'Pages'],
];
$toggleCols    = array_merge(...array_values(array_map('array_keys', $colGroups)));
$defaultHidden = ['description'];
?>
<div id="col-panel">
<?php foreach ($colGroups as $groupName => $cols):
    $groupKey = strtolower(preg_replace('/\W+/', '-', $groupName));
?>
<div class="col-group" data-group="<?= $groupKey ?>">
    <label class="col-group-header">
        <input type="checkbox" data-group-cb="<?= $groupKey ?>"
               onchange="toggleGroup('<?= $groupKey ?>', this.checked)">
        <?= h($groupName) ?>
    </label>
    <div class="col-group-children">
    <?php foreach ($cols as $key => $label):
        $chk = in_array($key, $defaultHidden) ? '' : ' checked';
    ?>
        <label>
            <input type="checkbox" data-col="<?= $key ?>" data-group="<?= $groupKey ?>"<?= $chk ?>
                   onchange="toggleCol('<?= $key ?>', this.checked)">
            <?= h($label) ?>
        </label>
    <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
</div>


<!-- ── Table ────────────────────────────────────────────────────────────── -->
<div id="table-wrap">
<table id="main-table">
<thead>
    <!-- Group headers -->
    <tr class="groups">
        <th colspan="1" class="grp-identity sticky-1">Website</th>
        <th colspan="1" class="grp-identity col-description">&#8203;</th>
        <th colspan="3" class="grp-governance">Governance</th>
        <th colspan="5" class="grp-people">People</th>
        <th colspan="1" class="grp-support">Support</th>
        <th colspan="3" class="grp-technical">Technical</th>
        <th colspan="3" class="grp-classification">Classification</th>
        <th colspan="8" class="grp-dubbot" id="grp-dubbot">DubBot <?php if ($dbLastUpdated): ?><span class="db-hdr-status">Updated <?= h(date('M j, Y', strtotime($dbLastUpdated))) ?></span><?php endif; ?> <button class="db-refresh-btn" id="db-refresh-btn" onclick="loadDubBotData()" title="Refresh DubBot data from API">↻ Refresh</button></th>
    </tr>
    <!-- Column headers -->
    <tr class="headers">
        <th class="sticky-1 col-site">Site <?= filterBtn('site') ?></th>
        <th class="col-description">Description <?= filterBtn('description') ?></th>
        <th class="col-vp_area">VP Area <?= filterBtn('vp_area') ?></th>
        <th class="col-vp_lead">VP Lead <?= filterBtn('vp_lead') ?></th>
        <th class="col-college_dept">College/Dept <?= filterBtn('college_dept') ?></th>
        <th class="col-college_communicator">Communicator <?= filterBtn('college_communicator') ?></th>
        <th class="col-site_owner">Owner <?= filterBtn('site_owner') ?></th>
        <th class="col-content_lead">Content Lead <?= filterBtn('content_lead') ?></th>
        <th class="col-tech_lead">Tech Lead <?= filterBtn('tech_lead') ?></th>
        <th class="col-admin_contact">Admin Contact <?= filterBtn('admin_contact') ?></th>
        <th class="col-support_intake_url">Intake</th>
        <th class="col-datastudio_url">Studio</th>
        <th class="col-server">Server <?= filterBtn('server') ?></th>
        <th class="col-platform">Platform <?= filterBtn('platform') ?></th>
        <th class="col-audience">Audience <?= filterBtn('audience') ?></th>
        <th class="col-category">Category <?= filterBtn('category') ?></th>
        <th class="col-second_category">2nd Category <?= filterBtn('second_category') ?></th>
        <th class="col-db-score">Score</th>
        <th class="col-db-accessibility">Accessibility</th>
        <th class="col-db-badlinks">Bad Links</th>
        <th class="col-db-seo">SEO</th>
        <th class="col-db-spelling">Spelling</th>
        <th class="col-db-bestpractices">Best Prac.</th>
        <th class="col-db-webgovernance">Web Gov.</th>
        <th class="col-db-pages">Pages</th>
    </tr>
</thead>
<tbody>
<?php foreach ($sites as $site):
    $sid      = $site['id'];
    $siteRoles= $rolesBySite[$sid] ?? [];
    $vpLeads  = $site['vp_area_id'] ? ($vpByArea[$site['vp_area_id']] ?? []) : [];

    // Data attributes for JS filtering (lowercase for case-insensitive match)
    $da = implode(' ', [
        'data-url="'              . h(strtolower($site['url'] ?? ''))               . '"',
        'data-site_name="'        . h(strtolower($site['site_name'] ?? ''))         . '"',
        'data-description="'      . h(strtolower($site['description'] ?? ''))       . '"',
        'data-vp_area="'          . h(strtolower($site['vp_area'] ?? ''))           . '"',
        'data-college_dept="'     . h(strtolower($site['college_dept'] ?? ''))      . '"',
        'data-server="'           . h(strtolower($site['server'] ?? ''))            . '"',
        'data-platform="'         . h(strtolower($site['platform'] ?? ''))          . '"',
        'data-audience="'         . h(strtolower($site['audience'] ?? ''))          . '"',
        'data-category="'         . h(strtolower($site['category'] ?? ''))          . '"',
        'data-second_category="'      . h(strtolower($site['second_category'] ?? ''))                          . '"',
        'data-vp_lead="'              . h(implode('|', array_column($vpLeads, 'emp_id')))                        . '"',
        'data-college_communicator="' . h(implode('|', array_column($siteRoles['college_communicator'] ?? [], 'emp_id'))) . '"',
        'data-site_owner="'           . h(implode('|', array_column($siteRoles['site_owner']           ?? [], 'emp_id'))) . '"',
        'data-content_lead="'         . h(implode('|', array_column($siteRoles['content_lead']         ?? [], 'emp_id'))) . '"',
        'data-tech_lead="'            . h(implode('|', array_column($siteRoles['tech_lead']            ?? [], 'emp_id'))) . '"',
        'data-admin_contact="'        . h(implode('|', array_column($siteRoles['admin_contact']        ?? [], 'emp_id'))) . '"',
    ]);
?>
    <tr data-id="<?= $sid ?>" <?= $da ?>>

        <!-- Site (combined URL + Site Name, sticky) -->
        <?php
        $display   = $site['site_name'] ?: $site['url'];
        $href      = $site['url'] ? 'https://' . h($site['url']) : '';
        $flags     = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT;
        $siteNameJ = json_encode((string)($site['site_name'] ?? ''), $flags);
        $urlJ      = json_encode((string)($site['url']       ?? ''), $flags);
        ?>
        <?php $tooltip = $display . ($site['url'] && $site['site_name'] ? "\n" . $site['url'] : ''); ?>
        <td class="sticky-1 col-site" data-site-id="<?= $sid ?>"
            title="<?= h($tooltip) ?>">
            <div class="site-inner">
                <?php if ($href): ?>
                    <a href="<?= $href ?>" target="_blank"
                       onclick="event.stopPropagation()"><?= h($display) ?></a>
                <?php elseif ($display): ?>
                    <span><?= h($display) ?></span>
                <?php else: ?>
                    <span class="empty-cell">—</span>
                <?php endif; ?>
            </div>
            <button class="site-edit-btn"
                    onclick="event.stopPropagation();openSiteEditModal(<?= $sid ?>,<?= h($siteNameJ) ?>,<?= h($urlJ) ?>)">✎</button>
        </td>

        <!-- Description -->
        <td class="col-description editable"
            data-site-id="<?= $sid ?>" data-field="description" data-type="text"
            data-value="<?= h($site['description']) ?>"
            title="<?= h($site['description']) ?>">
            <?= h($site['description']) ?>
        </td>

        <!-- VP Area -->
        <td class="col-vp_area editable"
            data-site-id="<?= $sid ?>" data-field="vp_area" data-fk-field="vp_area_id"
            data-type="fk" data-lookup="vp_areas"
            data-fk-id="<?= (int)$site['vp_area_id'] ?>"
            data-value="<?= h($site['vp_area']) ?>">
            <?= h($site['vp_area']) ?>
        </td>

        <!-- VP Lead -->
        <td class="col-vp_lead role-cell"
            data-site-id="<?= $sid ?>"
            data-vp-area-id="<?= (int)$site['vp_area_id'] ?>"
            onclick="openVpLeadModal(<?= $sid ?>, <?= (int)$site['vp_area_id'] ?>, this)">
            <?= renderBadges($vpLeads) ?>
        </td>

        <!-- College/Dept -->
        <td class="col-college_dept editable"
            data-site-id="<?= $sid ?>" data-field="college_dept" data-fk-field="college_dept_id"
            data-type="fk" data-lookup="colleges_depts"
            data-fk-id="<?= (int)$site['college_dept_id'] ?>"
            data-value="<?= h($site['college_dept']) ?>">
            <?= h($site['college_dept']) ?>
        </td>

        <!-- Role columns -->
        <?php foreach (['college_communicator','site_owner','content_lead','tech_lead','admin_contact'] as $role): ?>
        <td class="col-<?= $role ?> role-cell"
            data-site-id="<?= $sid ?>" data-role="<?= $role ?>"
            onclick="openPeopleModal(<?= $sid ?>, '<?= $role ?>', this)">
            <?= renderBadges($siteRoles[$role] ?? []) ?>
        </td>
        <?php endforeach; ?>

        <!-- Support Intake URL -->
        <?php
        $intakeUrl  = $site['support_intake_url'] ?? '';
        $intakeJ    = json_encode($intakeUrl, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT);
        $spId       = (int)($site['support_platform_id'] ?? 0);
        $spName     = $site['support_platform'] ?? '';
        $spJ        = json_encode(['id' => $spId, 'name' => $spName], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT);
        ?>
        <td class="col-support_intake_url link-cell" data-link-type="intake" data-site-id="<?= $sid ?>"
            data-url="<?= h($intakeUrl) ?>" data-sp-id="<?= $spId ?>"
            onclick="editLink(<?= $sid ?>, 'intake', <?= h($intakeJ) ?>, <?= h($spJ) ?>)"
            title="<?= $intakeUrl ? h($intakeUrl) . ($spName ? ' · ' . h($spName) : '') : 'Set intake URL' ?>">
            <?php if ($intakeUrl): ?>
                <span class="link-cell-icon"><?= str_contains($intakeUrl, '/') ? '🔗' : '✉' ?></span>
            <?php else: ?>
                <span class="link-cell-add">+</span>
            <?php endif; ?>
        </td>

        <!-- Datastudio URL -->
        <?php $dsUrl = $site['datastudio_url'] ?? ''; $dsJ = json_encode($dsUrl, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>
        <td class="col-datastudio_url link-cell" data-link-type="datastudio" data-site-id="<?= $sid ?>"
            data-url="<?= h($dsUrl) ?>" onclick="editLink(<?= $sid ?>, 'datastudio', <?= h($dsJ) ?>)"
            title="<?= $dsUrl ? h($dsUrl) : 'Set Datastudio URL' ?>">
            <?php if ($dsUrl): ?>
                <span class="link-cell-icon">📊</span>
            <?php else: ?>
                <span class="link-cell-add">+</span>
            <?php endif; ?>
        </td>

        <!-- Server -->
        <td class="col-server editable"
            data-site-id="<?= $sid ?>" data-field="server" data-fk-field="server_id"
            data-type="fk" data-lookup="servers"
            data-fk-id="<?= (int)$site['server_id'] ?>"
            data-value="<?= h($site['server']) ?>">
            <?= h($site['server']) ?>
        </td>

        <!-- Platform -->
        <td class="col-platform editable"
            data-site-id="<?= $sid ?>" data-field="platform" data-fk-field="platform_id"
            data-type="fk" data-lookup="platforms"
            data-fk-id="<?= (int)$site['platform_id'] ?>"
            data-value="<?= h($site['platform']) ?>">
            <?= h($site['platform']) ?>
        </td>

        <!-- Audience -->
        <td class="col-audience editable"
            data-site-id="<?= $sid ?>" data-field="audience" data-fk-field="audience_id"
            data-type="fk" data-lookup="audiences"
            data-fk-id="<?= (int)$site['audience_id'] ?>"
            data-value="<?= h($site['audience']) ?>">
            <?= h($site['audience']) ?>
        </td>

        <!-- Category -->
        <td class="col-category editable"
            data-site-id="<?= $sid ?>" data-field="category" data-fk-field="category_id"
            data-type="fk" data-lookup="categories"
            data-fk-id="<?= (int)$site['category_id'] ?>"
            data-value="<?= h($site['category']) ?>">
            <?= h($site['category']) ?>
        </td>

        <!-- 2nd Category -->
        <td class="col-second_category editable"
            data-site-id="<?= $sid ?>" data-field="second_category" data-fk-field="second_category_id"
            data-type="fk" data-lookup="categories"
            data-fk-id="<?= (int)$site['second_category_id'] ?>"
            data-value="<?= h($site['second_category']) ?>">
            <?= h($site['second_category']) ?>
        </td>

        <!-- DubBot (pre-populated from DB; refreshable via JS) -->
        <?php $dbHas = $site['db_score'] !== null; ?>
        <td class="col-db-score"         data-db-col="score"<?= dbScoreAttr($site['db_score']) ?>><?php
            if ($dbHas) echo dbScoreBadge((float)$site['db_score']); ?></td>
        <td class="col-db-accessibility" data-db-col="accessibility"<?= dbScoreAttr($site['db_accessibility']) ?>><?php
            if ($dbHas) echo dbScoreBadge((float)$site['db_accessibility']); ?></td>
        <td class="col-db-badlinks"      data-db-col="badLinks"<?= dbScoreAttr($site['db_bad_links']) ?>><?php
            if ($dbHas) echo dbScoreBadge((float)$site['db_bad_links']); ?></td>
        <td class="col-db-seo"           data-db-col="seo"<?= dbScoreAttr($site['db_seo']) ?>><?php
            if ($dbHas) echo dbScoreBadge((float)$site['db_seo']); ?></td>
        <td class="col-db-spelling"      data-db-col="spelling"<?= dbScoreAttr($site['db_spelling']) ?>><?php
            if ($dbHas) echo dbScoreBadge((float)$site['db_spelling']); ?></td>
        <td class="col-db-bestpractices" data-db-col="bestPractices"<?= dbScoreAttr($site['db_best_practices']) ?>><?php
            if ($dbHas) echo dbScoreBadge((float)$site['db_best_practices']); ?></td>
        <td class="col-db-webgovernance" data-db-col="webGovernance"<?= dbScoreAttr($site['db_web_governance']) ?>><?php
            if ($dbHas) echo dbScoreBadge((float)$site['db_web_governance']); ?></td>
        <td class="col-db-pages"         data-db-col="pages"><?php
            if ($dbHas && $site['db_pages_count'] !== null)
                echo number_format((int)$site['db_pages_count']); ?></td>

    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- ── People Modal ──────────────────────────────────────────────────────── -->
<div id="modal-overlay" onclick="if(event.target===this) closePeopleModal()">
    <div id="modal-box">
        <h2 id="modal-title"></h2>
        <div class="subtitle" id="modal-subtitle"></div>
        <div id="modal-people-list"></div>
        <div class="modal-add">
            <label>Add Person</label>
            <select id="employee-ts" placeholder="Search by name or email…"></select>
        </div>
        <div class="modal-new-toggle">
            <button id="btn-new-person-toggle" onclick="toggleNewPersonForm()">+ Not in system?</button>
        </div>
        <div id="new-person-form" style="display:none">
            <div class="new-person-fields">
                <input type="text" id="np-first" placeholder="First name">
                <input type="text" id="np-last"  placeholder="Last name">
                <input type="text" id="np-email" placeholder="Email" class="full-width">
            </div>
            <button id="btn-create-emp" onclick="createAndAddEmployee()">Add to Role</button>
        </div>
        <button id="modal-close" onclick="closePeopleModal()">Close</button>
    </div>
</div>

<!-- ── Link Edit Modal ───────────────────────────────────────────────────── -->
<div id="link-overlay" onclick="if(event.target===this)closeLinkModal()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;width:500px;box-shadow:0 20px 60px rgba(0,0,0,.25)">
        <h2 id="link-modal-title" style="margin:0 0 4px;font-size:15px;font-weight:700;color:#032044"></h2>
        <p id="link-modal-site" style="margin:0 0 14px;font-size:12px;color:#A09080"></p>
        <div id="link-current-wrap" style="display:none;margin-bottom:14px;padding:10px 12px;background:#F8F4F1;border-radius:8px;border:1px solid #EBE6E2">
            <div style="font-size:11px;font-weight:600;color:#A09080;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Current URL</div>
            <a id="link-current-url" href="#" target="_blank"
               style="font-size:12px;color:#265BF7;word-break:break-all;text-decoration:none;">
            </a>
        </div>
        <div id="link-platform-wrap" style="display:none;margin-bottom:14px">
            <label style="font-size:12px;font-weight:600;color:#332F21;display:block;margin-bottom:4px">Support Platform</label>
            <select id="link-platform-select" style="width:100%;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;outline:none;background:#fff;margin-bottom:5px">
                <option value="">— none —</option>
                <?php foreach ($lookups['support_platforms'] as $sp): ?>
                <option value="<?= $sp['id'] ?>"><?= h($sp['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <div id="link-platform-add-row" style="display:flex;gap:6px;align-items:center">
                <input id="link-platform-new" type="text" placeholder="Add new platform…"
                       style="flex:1;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;padding:4px 8px;outline:none">
                <button onclick="addLinkPlatform()" style="padding:4px 10px;font-size:12px;font-weight:600;background:#265BF7;color:#fff;border:none;border-radius:6px;cursor:pointer;white-space:nowrap">+ Add</button>
            </div>
        </div>
        <label style="font-size:12px;font-weight:600;color:#332F21;display:block;margin-bottom:4px" id="link-input-label">New URL</label>
        <input id="link-input" type="text" style="width:100%;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;margin-bottom:12px;outline:none;box-sizing:border-box"
               placeholder="https://…">
        <div style="display:flex;gap:8px">
            <button onclick="saveLinkModal()" style="flex:1;padding:8px;background:#265BF7;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600">Save</button>
            <button id="link-clear-btn" onclick="clearLinkModal()" style="padding:8px 14px;background:#fff;color:#dc2626;border:1px solid #fca5a5;border-radius:8px;cursor:pointer;font-weight:600">Clear</button>
            <button onclick="closeLinkModal()" style="flex:1;padding:8px;background:#F2EDE9;border:none;border-radius:8px;cursor:pointer;font-weight:600">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Add Site Modal ───────────────────────────────────────────────────── -->
<div id="add-site-overlay" onclick="if(event.target===this)closeAddSiteModal()"
     style="display:none;position:fixed;inset:0;background:rgba(3,32,68,.5);z-index:200;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;width:480px;box-shadow:0 20px 60px rgba(3,32,68,.3)">
        <h2 style="font-family:'Arsenal',system-ui,sans-serif;margin:0 0 4px;font-size:17px;font-weight:700;color:#032044">Add New Site</h2>
        <p style="margin:0 0 16px;font-size:12px;color:#A09080">Enter the domain without https:// (e.g. newsite.utsa.edu)</p>
        <input id="add-site-input" type="text" placeholder="newsite.utsa.edu"
               style="width:100%;font-size:13px;border:2px solid #265BF7;border-radius:6px;padding:7px 10px;margin-bottom:14px;outline:none;box-sizing:border-box">
        <p id="add-site-error" style="display:none;margin:0 0 10px;font-size:12px;color:#dc2626;font-weight:600"></p>
        <div style="display:flex;gap:8px">
            <button onclick="saveAddSiteModal()"
                    style="flex:1;padding:8px;background:#D3430D;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">Add Site</button>
            <button onclick="closeAddSiteModal()"
                    style="flex:1;padding:8px;background:#EBE6E2;color:#332F21;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Site Edit Modal ──────────────────────────────────────────────────── -->
<div id="site-edit-overlay" onclick="if(event.target===this)closeSiteEditModal()"
     style="display:none;position:fixed;inset:0;background:rgba(3,32,68,.5);z-index:200;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:24px;width:480px;box-shadow:0 20px 60px rgba(3,32,68,.3)">
        <h2 style="font-family:'Arsenal',system-ui,sans-serif;margin:0 0 16px;font-size:17px;font-weight:700;color:#032044">Edit Site</h2>
        <label style="display:block;font-size:11px;font-weight:600;color:#6B6355;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Site Name</label>
        <input id="site-edit-name" type="text" placeholder="My Site Name"
               style="width:100%;font-size:13px;border:2px solid #265BF7;border-radius:6px;padding:7px 10px;margin-bottom:14px;outline:none;box-sizing:border-box">
        <label style="display:block;font-size:11px;font-weight:600;color:#6B6355;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">URL <span style="font-weight:400;text-transform:none;color:#A09080">(without https://)</span></label>
        <input id="site-edit-url" type="text" placeholder="site.utsa.edu"
               style="width:100%;font-size:13px;border:1px solid #D5CFC8;border-radius:6px;padding:7px 10px;margin-bottom:14px;outline:none;box-sizing:border-box">
        <p id="site-edit-error" style="display:none;margin:0 0 10px;font-size:12px;color:#dc2626;font-weight:600"></p>
        <div style="display:flex;gap:8px">
            <button id="site-edit-save" onclick="saveSiteEditModal()"
                    style="flex:1;padding:8px;background:#D3430D;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">Save</button>
            <button onclick="closeSiteEditModal()"
                    style="flex:1;padding:8px;background:#EBE6E2;color:#332F21;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Column filter popover ────────────────────────────────────────────── -->
<div id="filter-popover">
    <div id="filter-pop-content"></div>
    <div class="filter-pop-actions">
        <button class="btn-clear" onclick="clearFilterFromPop()">Clear</button>
        <button class="btn-apply" onclick="applyFilterFromPop()">Apply</button>
    </div>
</div>

<script>
// ── Data from PHP ──────────────────────────────────────────────────────────
const LOOKUPS        = <?= $lookupsJson ?>;
const EMPLOYEES      = <?= $employeesJson ?>;
const PEOPLE_OPTIONS = <?= $filterPeopleJson ?>;

// ── Column visibility ──────────────────────────────────────────────────────
const ALL_TOGGLE_COLS = ['description','vp_area','vp_lead','college_dept',
    'college_communicator','site_owner','content_lead','tech_lead','admin_contact',
    'support_intake_url','datastudio_url',
    'server','platform','audience','category','second_category',
    'db-score','db-accessibility','db-badlinks','db-seo',
    'db-spelling','db-bestpractices','db-webgovernance','db-pages'];

const DEFAULT_HIDDEN = ['description'];
const storedCols     = localStorage.getItem('hiddenCols');
const hiddenCols     = new Set(storedCols !== null ? JSON.parse(storedCols) : DEFAULT_HIDDEN);

function toggleColPanel() {
    document.getElementById('col-panel').classList.toggle('open');
}

function toggleCol(key, visible) {
    if (visible) hiddenCols.delete(key);
    else         hiddenCols.add(key);
    localStorage.setItem('hiddenCols', JSON.stringify([...hiddenCols]));
    applyColVisibility();
}

function toggleGroup(groupKey, visible) {
    document.querySelectorAll(`#col-panel input[data-col][data-group="${groupKey}"]`).forEach(cb => {
        if (visible) hiddenCols.delete(cb.dataset.col);
        else         hiddenCols.add(cb.dataset.col);
    });
    localStorage.setItem('hiddenCols', JSON.stringify([...hiddenCols]));
    applyColVisibility();
}

function applyColVisibility() {
    // Remove the server-side flash-prevention style so JS inline styles take full control
    document.getElementById('col-hide-defaults')?.remove();

    ALL_TOGGLE_COLS.forEach(key => {
        const hide = hiddenCols.has(key);
        document.querySelectorAll('.col-' + key).forEach(el => el.style.display = hide ? 'none' : '');
    });

    // Hide group header <th> when every column in that group is hidden
    document.querySelectorAll('#col-panel .col-group').forEach(group => {
        const grpKey  = group.dataset.group;
        const kids    = [...group.querySelectorAll('input[data-col]')];
        const allHide = kids.length > 0 && kids.every(cb => hiddenCols.has(cb.dataset.col));
        // General group header is col-description th (already handled by col class above)
        if (grpKey !== 'general') {
            const th = document.querySelector(`th.grp-${grpKey}`);
            if (th) th.style.display = allHide ? 'none' : '';
        }
    });

    // Sync column checkboxes
    document.querySelectorAll('#col-panel input[data-col]').forEach(cb => {
        cb.checked = !hiddenCols.has(cb.dataset.col);
    });
    // Sync group aggregate checkboxes
    document.querySelectorAll('#col-panel input[data-group-cb]').forEach(gcb => {
        const grp  = gcb.dataset.groupCb;
        const kids = [...document.querySelectorAll(`#col-panel input[data-col][data-group="${grp}"]`)];
        const numChecked = kids.filter(c => c.checked).length;
        gcb.checked       = numChecked === kids.length;
        gcb.indeterminate = numChecked > 0 && numChecked < kids.length;
    });
}

window.addEventListener('DOMContentLoaded', () => {
    applyColVisibility();
    updateRowCount();
    // Close any open inline edit when the table scrolls (prevents stale dropdown position)
    document.getElementById('table-wrap').addEventListener('scroll', () => {
        // Don't cancel while a TomSelect dropdown is open (its onDropdownOpen
        // restores scroll position, which itself fires this scroll event)
        if (activeCell && !activeTomSelect) cancelEdit();
    });
});

// ── Column filters ─────────────────────────────────────────────────────────
// type:'text'   → substring match on data attr
// type:'set'    → data attr must be in selected Set of lowercase labels
// type:'people' → pipe-delimited emp IDs; row shown if any ID is in selected Set
const FILTER_COLS = {
    site:                 { type:'text' },   // searches both data-url and data-site_name
    description:          { type:'text' },
    vp_area:              { type:'set',    lookup:'vp_areas' },
    vp_lead:              { type:'people' },
    college_dept:         { type:'set',    lookup:'colleges_depts' },
    college_communicator: { type:'people' },
    site_owner:           { type:'people' },
    content_lead:         { type:'people' },
    tech_lead:            { type:'people' },
    admin_contact:        { type:'people' },
    server:               { type:'set',    lookup:'servers' },
    platform:             { type:'set',    lookup:'platforms' },
    audience:             { type:'set',    lookup:'audiences' },
    category:             { type:'set',    lookup:'categories' },
    second_category:      { type:'set',    lookup:'categories' },
};

const activeFilters = {};   // col → { type, value } | { type, values:Set }
let filterPopCol     = null;
let filterPopPending = null;

function applyFilters() {
    let visible = 0;
    document.querySelectorAll('#main-table tbody tr[data-id]').forEach(row => {
        let show = true;
        for (const [col, f] of Object.entries(activeFilters)) {
            if (f.type === 'text') {
                let match;
                if (col === 'site') {
                    // Search across both URL and site name
                    const uv = (row.dataset['url']       || '').toLowerCase();
                    const nv = (row.dataset['site_name'] || '').toLowerCase();
                    match = uv.includes(f.value) || nv.includes(f.value);
                } else {
                    match = (row.dataset[col] || '').toLowerCase().includes(f.value);
                }
                if (!match) { show = false; break; }
            }
            const v = (row.dataset[col] || '').toLowerCase();
            if (f.type === 'set'  && !f.values.has(v))     { show = false; break; }
            if (f.type === 'people') {
                const ids = v.split('|').filter(Boolean);
                if (ids.length === 0) {
                    if (!f.values.has('')) { show = false; break; }
                } else if (!ids.some(id => f.values.has(id))) { show = false; break; }
            }
        }
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    updateRowCount(visible);
    document.getElementById('btn-clear-filters').style.display =
        Object.keys(activeFilters).length ? '' : 'none';
}

function clearAllFilters() {
    for (const col of Object.keys(activeFilters)) {
        delete activeFilters[col];
        markFilterBtn(col, false);
    }
    applyFilters();
}

// ── Filter popover ─────────────────────────────────────────────────────────
function openFilter(event, col) {
    event.stopPropagation();
    const pop = document.getElementById('filter-popover');
    if (filterPopCol === col && pop.classList.contains('open')) {
        closeFilter(); return;
    }
    filterPopCol = col;
    const def     = FILTER_COLS[col];
    const current = activeFilters[col] || null;

    if (def.type === 'text') {
        filterPopPending = { type:'text', value: current ? current.value : '' };
    } else {
        const opts   = getPopoverOptions(col, def);
        const active = current ? current.values : new Set(opts.map(o => o.val));
        filterPopPending = { type: def.type, values: new Set(active) };
    }

    buildFilterPopover(col, def);

    const rect = event.currentTarget.getBoundingClientRect();
    pop.style.top  = (rect.bottom + 4) + 'px';
    pop.style.left = rect.left + 'px';
    pop.classList.add('open');

    // Nudge left if it overflows the right edge
    requestAnimationFrame(() => {
        const pr = pop.getBoundingClientRect();
        if (pr.right > window.innerWidth - 8)
            pop.style.left = (window.innerWidth - pr.width - 8) + 'px';
    });
}

function allSetOptions(lookupKey) {
    const items = (LOOKUPS[lookupKey] || []).map(o => ({ val: o.label.toLowerCase(), label: o.label }));
    return [{ val:'', label:'(None)' }, ...items];
}

function allPeopleOptions(col) {
    const items = (PEOPLE_OPTIONS[col] || []).map(o => ({ val: String(o.id), label: o.label }));
    return [{ val:'', label:'(None)' }, ...items];
}

function getPopoverOptions(col, def) {
    if (def.type === 'set')    return allSetOptions(def.lookup);
    if (def.type === 'people') return allPeopleOptions(col);
    return [];
}

function buildFilterPopover(col, def) {
    const content = document.getElementById('filter-pop-content');
    if (def.type === 'text') {
        const placeholder = col === 'site' ? 'Filter by site name or URL…' : 'Filter…';
        const hint        = col === 'site' ? '<div style="font-size:10px;color:#A09080;margin-bottom:2px">Searches both site name and URL</div>' : '';
        content.innerHTML =
            `${hint}<input type="text" id="filter-pop-text" placeholder="${placeholder}"
                    value="${escHtml(filterPopPending.value)}">`;
        const inp = document.getElementById('filter-pop-text');
        inp.focus(); inp.select();
        inp.addEventListener('input', () => {
            const val = inp.value.trim().toLowerCase();
            filterPopPending.value = val;
            if (val) activeFilters[col] = { type: 'text', value: val };
            else     delete activeFilters[col];
            markFilterBtn(col, !!activeFilters[col]);
            applyFilters();
        });
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter')  closeFilter();
            if (e.key === 'Escape') { inp.value = ''; inp.dispatchEvent(new Event('input')); closeFilter(); }
        });
    } else {
        const opts = getPopoverOptions(col, def);
        const allChecked = opts.every(o => filterPopPending.values.has(o.val));
        const rows = opts.map(o => {
            const chk = filterPopPending.values.has(o.val) ? 'checked' : '';
            return `<label class="filter-pop-item">
                <input type="checkbox" value="${escHtml(o.val)}" ${chk} onchange="filterPopToggle(this)">
                ${escHtml(o.label)}
            </label>`;
        }).join('');
        content.innerHTML =
            `<input type="text" id="filter-pop-search" placeholder="Search options…"
                    oninput="filterPopSearch(this.value)">
             <label class="filter-pop-item filter-pop-all">
                 <input type="checkbox" id="filter-pop-selall"
                        ${allChecked ? 'checked' : ''}
                        onchange="filterPopSelectAll(this.checked)">
                 <strong>Select All</strong>
             </label>
             <div class="filter-pop-sep"></div>
             <div class="filter-pop-list" id="filter-pop-list">${rows}</div>`;
    }
}

function filterPopSearch(q) {
    const lq = q.toLowerCase();
    document.querySelectorAll('#filter-pop-list .filter-pop-item').forEach(el => {
        el.style.display = el.textContent.trim().toLowerCase().includes(lq) ? '' : 'none';
    });
    syncSelectAll();
}

function filterPopToggle(cb) {
    if (cb.checked) filterPopPending.values.add(cb.value);
    else            filterPopPending.values.delete(cb.value);
    syncSelectAll();
}

function filterPopSelectAll(checked) {
    document.querySelectorAll('#filter-pop-list .filter-pop-item').forEach(item => {
        if (item.style.display === 'none') return;
        const cb = item.querySelector('input');
        cb.checked = checked;
        if (checked) filterPopPending.values.add(cb.value);
        else         filterPopPending.values.delete(cb.value);
    });
    syncSelectAll();
}

function syncSelectAll() {
    const sa = document.getElementById('filter-pop-selall');
    if (!sa) return;
    const visible = [...document.querySelectorAll('#filter-pop-list .filter-pop-item')]
        .filter(el => el.style.display !== 'none')
        .map(el => el.querySelector('input'));
    sa.checked       = visible.length > 0 && visible.every(cb => cb.checked);
    sa.indeterminate = !sa.checked && visible.some(cb => cb.checked);
}

function applyFilterFromPop() {
    const col = filterPopCol;
    const def = FILTER_COLS[col];
    if (def.type === 'text') {
        const val = document.getElementById('filter-pop-text').value.trim().toLowerCase();
        if (val) activeFilters[col] = { type:'text', value: val };
        else     delete activeFilters[col];
    } else {
        const allVals = new Set(getPopoverOptions(col, def).map(o => o.val));
        const isAll   = [...allVals].every(v => filterPopPending.values.has(v));
        if (isAll) delete activeFilters[col];
        else       activeFilters[col] = { type: def.type, values: new Set(filterPopPending.values) };
    }
    markFilterBtn(col, !!activeFilters[col]);
    applyFilters();
    closeFilter();
}

function clearFilterFromPop() {
    delete activeFilters[filterPopCol];
    markFilterBtn(filterPopCol, false);
    applyFilters();
    closeFilter();
}

function closeFilter() {
    document.getElementById('filter-popover').classList.remove('open');
    filterPopCol = null; filterPopPending = null;
}

function markFilterBtn(col, active) {
    document.querySelectorAll(`.filter-btn[data-col="${col}"]`).forEach(btn => {
        btn.classList.toggle('filter-active', active);
    });
}

function updateRowCount(n) {
    const total = document.querySelectorAll('#main-table tbody tr[data-id]').length;
    const shown = n !== undefined ? n : total;
    document.getElementById('row-count').textContent =
        shown === total ? `${total} sites` : `${shown} of ${total} sites`;
}

// ── Inline editing ─────────────────────────────────────────────────────────
let activeTomSelect = null;
let activeCell = null;

document.addEventListener('click', e => {
    // Close filter popover on outside click
    if (filterPopCol !== null &&
        !e.target.closest('#filter-popover') &&
        !e.target.closest('.filter-btn')) {
        closeFilter();
    }

    const td = e.target.closest('td.editable');
    if (!td) {
        if (activeCell && !e.target.closest('.ts-dropdown')) cancelEdit();
        return;
    }
    if (td === activeCell) return;
    if (activeCell) cancelEdit();
    openEdit(td);
});

function openEdit(td) {
    activeCell = td;
    td.classList.add('editing');
    const type     = td.dataset.type;
    const field    = td.dataset.field;
    const siteId   = td.dataset.siteId;
    const origText = td.dataset.value || '';

    if (type === 'text') {
        const input = document.createElement('input');
        input.type  = 'text';
        input.value = origText;
        input.dataset.orig = origText;
        td.innerHTML = '';
        td.appendChild(input);
        input.focus();
        input.select();

        input.addEventListener('keydown', e => {
            if (e.key === 'Enter')  saveTextEdit(td, input.value);
            if (e.key === 'Escape') cancelEdit();
        });
        input.addEventListener('blur', () => saveTextEdit(td, input.value));

    } else if (type === 'fk') {
        const lookupKey = td.dataset.lookup;
        const fkField   = td.dataset.fkField;
        const currentId = td.dataset.fkId || '';

        const select = document.createElement('select');
        select.id = 'ts-active';
        td.innerHTML = '';
        td.appendChild(select);

        activeTomSelect = new TomSelect(select, {
            valueField: 'id',
            labelField: 'label',
            searchField: 'label',
            options: [{ id: '', label: '— clear —' }, ...LOOKUPS[lookupKey]],
            items: currentId ? [String(currentId)] : [],
            create(input, callback) {
                const label = input.trim();
                if (!label) { callback(); return; }
                api({ action: 'add_lookup', key: lookupKey, value: label }).then(res => {
                    if (res.id) {
                        const opt = { id: res.id, label };
                        if (!res.existing) LOOKUPS[lookupKey].push(opt);
                        callback(opt);
                    } else {
                        callback();
                    }
                });
            },
            dropdownParent: 'body',
            onDropdownOpen(dropdown) {
                const rect = this.control.getBoundingClientRect();
                Object.assign(dropdown.style, {
                    position: 'fixed',
                    zIndex:   '99999',
                    top:  rect.bottom + 'px',
                    left: rect.left   + 'px',
                });
            },
            onChange(val) {
                saveFkEdit(td, val, lookupKey, fkField);
            },
            onBlur() {
                setTimeout(() => { if (activeCell === td) cancelEdit(); }, 300);
            },
        });

        // Prevent the browser's scroll-into-view when TomSelect focuses its
        // internal input — without this the table jumps left on every open.
        const ci = activeTomSelect.control_input;
        const _nativeFocus = ci.focus.bind(ci);
        ci.focus = (opts) => _nativeFocus({ preventScroll: true });

        activeTomSelect.focus();
    }
}

function cancelEdit() {
    if (!activeCell) return;
    if (activeTomSelect) { activeTomSelect.destroy(); activeTomSelect = null; }
    const td = activeCell;
    activeCell = null;
    td.classList.remove('editing');
    restoreCellDisplay(td);
}

function restoreCellDisplay(td) {
    const val = td.dataset.value || '';
    if (td.dataset.field === 'url' && val) {
        td.innerHTML = `<a href="https://${escHtml(val)}" target="_blank"
            style="color:#265BF7;text-decoration:none"
            onclick="event.stopPropagation()">${escHtml(val)}</a>`;
    } else {
        td.textContent = val;
    }
}

async function saveTextEdit(td, newVal) {
    if (activeTomSelect) { activeTomSelect.destroy(); activeTomSelect = null; }
    td.classList.remove('editing');
    activeCell = null;

    const siteId = td.dataset.siteId;
    const field  = td.dataset.field;

    const res  = await api({ action: 'update_site', site_id: siteId, field, value: newVal });
    if (res.success) {
        td.dataset.value = newVal;
        // Update filter data attribute on the row
        const row = td.closest('tr');
        if (row && row.dataset[field] !== undefined) row.dataset[field] = newVal.toLowerCase();
    }
    restoreCellDisplay(td);
}

async function saveFkEdit(td, val, lookupKey, fkField) {
    if (activeTomSelect) { activeTomSelect.destroy(); activeTomSelect = null; }
    td.classList.remove('editing');
    activeCell = null;

    const siteId = td.dataset.siteId;
    const res = await api({ action: 'update_site', site_id: siteId, field: fkField, value: val || null });

    if (res.success) {
        const opt = LOOKUPS[lookupKey]?.find(o => String(o.id) === String(val));
        const label = opt ? opt.label : '';
        td.dataset.fkId  = val || '';
        td.dataset.value = label;
        const row = td.closest('tr');
        if (row && td.dataset.field && row.dataset[td.dataset.field] !== undefined) {
            row.dataset[td.dataset.field] = label.toLowerCase();
        }
    }
    restoreCellDisplay(td);
}

// ── Link editing ───────────────────────────────────────────────────────────
let linkState = {};

function editLink(siteId, linkType, currentUrl, platform) {
    linkState = { siteId, linkType, currentUrl: currentUrl || '', platform: platform || null };
    const label = linkType === 'intake' ? 'Support Intake URL' : 'Datastudio URL';
    document.getElementById('link-modal-title').textContent = label;

    // Show site name in subtitle
    const row  = document.querySelector(`tr[data-id="${siteId}"]`);
    const site = row?.querySelector('td.col-site a, td.col-site span')?.textContent?.trim() || '';
    document.getElementById('link-modal-site').textContent = site;

    // Show current URL as clickable link
    const curWrap = document.getElementById('link-current-wrap');
    const curLink = document.getElementById('link-current-url');
    if (currentUrl) {
        curLink.href        = currentUrl.includes('/') ? currentUrl : 'mailto:' + currentUrl;
        curLink.textContent = currentUrl;
        curWrap.style.display = 'block';
    } else {
        curWrap.style.display = 'none';
    }

    // Support Platform selector (intake only)
    const platWrap = document.getElementById('link-platform-wrap');
    const platSel  = document.getElementById('link-platform-select');
    if (linkType === 'intake') {
        platSel.value      = platform?.id || '';
        platWrap.style.display = 'block';
    } else {
        platWrap.style.display = 'none';
    }

    // Label and clear button
    const urlLabel = linkType === 'intake'
        ? (currentUrl ? 'Replace with new URL or email address' : 'URL or email address')
        : (currentUrl ? 'Replace with new URL' : 'URL');
    document.getElementById('link-input-label').textContent = urlLabel;
    document.getElementById('link-input').placeholder = linkType === 'intake' ? 'https://… or email address' : 'https://…';
    document.getElementById('link-clear-btn').style.display = currentUrl ? '' : 'none';
    document.getElementById('link-input').value = '';

    document.getElementById('link-overlay').style.display = 'flex';
    setTimeout(() => document.getElementById('link-input').focus(), 50);
}

function closeLinkModal() {
    document.getElementById('link-overlay').style.display = 'none';
    linkState = {};
}

async function clearLinkModal() {
    const { siteId, linkType } = linkState;
    const ops = [api({ action: 'update_link', site_id: siteId, link_type: linkType, url: '' })];
    if (linkType === 'intake') ops.push(api({ action: 'update_site', site_id: siteId, field: 'support_platform_id', value: '' }));
    await Promise.all(ops);
    updateLinkCell(siteId, linkType, '', null);
    closeLinkModal();
}

async function saveLinkModal() {
    const url = document.getElementById('link-input').value.trim();
    const { siteId, linkType, currentUrl } = linkState;
    const finalUrl = url || currentUrl;
    const platId   = linkType === 'intake' ? (document.getElementById('link-platform-select').value || '') : null;

    const ops = [];
    if (finalUrl) ops.push(api({ action: 'update_link', site_id: siteId, link_type: linkType, url: finalUrl }));
    if (platId !== null) ops.push(api({ action: 'update_site', site_id: siteId, field: 'support_platform_id', value: platId }));
    await Promise.all(ops);

    if (finalUrl || platId !== null) {
        const platName = platId ? document.getElementById('link-platform-select').selectedOptions[0]?.text : '';
        updateLinkCell(siteId, linkType, finalUrl, platId ? { id: platId, name: platName } : null);
    }
    closeLinkModal();
}

async function addLinkPlatform() {
    const input = document.getElementById('link-platform-new');
    const name  = input.value.trim();
    if (!name) return;
    const res = await api({ action: 'add_lookup', key: 'support_platforms', value: name });
    if (!res.id) return;
    const sel = document.getElementById('link-platform-select');
    // Add option if not already present
    if (!sel.querySelector(`option[value="${res.id}"]`)) {
        const opt = document.createElement('option');
        opt.value       = res.id;
        opt.textContent = name;
        sel.appendChild(opt);
    }
    sel.value  = res.id;
    input.value = '';
}

function updateLinkCell(siteId, linkType, url, platform) {
    const col = linkType === 'intake' ? 'col-support_intake_url' : 'col-datastudio_url';
    const td  = document.querySelector(`tr[data-id="${siteId}"] td.${col}`);
    if (!td) return;
    td.dataset.url  = url;
    td.dataset.spId = platform?.id || '';
    const platHint  = platform?.name ? ' · ' + platform.name : '';
    td.title = url ? url + platHint : (linkType === 'intake' ? 'Set intake URL' : 'Set Datastudio URL');
    td.onclick = () => editLink(parseInt(siteId), linkType, url, platform);
    if (url) {
        const icon = linkType === 'datastudio' ? '📊' : (url.includes('/') ? '🔗' : '✉');
        td.innerHTML = `<span class="link-cell-icon">${icon}</span>`;
    } else {
        td.innerHTML = `<span class="link-cell-add">+</span>`;
    }
}

// ── People modal ───────────────────────────────────────────────────────────
let modalState = {};
let empTs = null;

const ROLE_LABELS = {
    college_communicator: 'College Communicator',
    site_owner:           'Site Owner',
    content_lead:         'Content Lead',
    tech_lead:            'Tech Lead',
    admin_contact:        'Admin Contact',
};

async function openPeopleModal(siteId, role, cell) {
    modalState = { siteId, role, cell };

    const row       = cell.closest('tr');
    const siteCell  = row.querySelector('td.col-site');
    const siteLabel = siteCell ? (siteCell.title || siteCell.textContent.trim()) : '';

    document.getElementById('modal-title').textContent    = ROLE_LABELS[role] || role;
    document.getElementById('modal-subtitle').textContent = siteLabel;

    const data = await api({ action: 'get_roles', site_id: siteId });
    modalState.roles = data.roles || [];
    renderModalPeople(role);

    // Tom Select for employee search
    const tsEl = document.getElementById('employee-ts');
    if (empTs) { empTs.destroy(); empTs = null; }
    tsEl.innerHTML = '';
    empTs = new TomSelect(tsEl, {
        valueField: 'id',
        labelField: 'label',
        searchField: 'label',
        placeholder: 'Search by name or email…',
        options: EMPLOYEES.map(e => ({
            id:    e.id,
            label: `${e.last_name}, ${e.first_name}${e.email ? ' · ' + e.email : ''}`,
        })),
        create: false,
        dropdownParent: 'body',
        onDropdownOpen(dropdown) {
            const rect = this.control.getBoundingClientRect();
            Object.assign(dropdown.style, {
                position: 'fixed',
                zIndex:   '99999',
                top:  rect.bottom + 'px',
                left: rect.left   + 'px',
            });
        },
        onItemAdd(val) {
            addPersonToRole(parseInt(val));
            empTs.clear(true);
        },
    });

    document.getElementById('modal-overlay').classList.add('open');
}

function renderModalPeople(role) {
    const list = document.getElementById('modal-people-list');
    const people = (modalState.roles || []).filter(r => r.role === role);
    if (!people.length) {
        list.innerHTML = '<p style="color:#94a3b8;font-size:12px;margin:0 0 8px">No one assigned yet.</p>';
        return;
    }
    const removeFunc = modalState.isVpLead ? 'removeVpLead' : 'removePersonFromRole';
    list.innerHTML = people.map(p => {
        const eid = p.employee_id;
        const emailClass = p.email ? 'emp-editable emp-email' : 'emp-editable emp-no-email';
        return `
        <div class="modal-person">
            <span class="badge" style="background:${badgeColor(p.last_name + p.first_name)};flex-shrink:0">
                ${initials(p.first_name, p.last_name)}
            </span>
            <div class="modal-person-info">
                <div class="name">
                    <span class="emp-editable" data-field="last_name" data-emp-id="${eid}" onclick="editEmpField(this)">${escHtml(p.last_name)}</span>,
                    <span class="emp-editable" data-field="first_name" data-emp-id="${eid}" onclick="editEmpField(this)">${escHtml(p.first_name)}</span>
                </div>
                <div class="email">
                    <span class="${emailClass}" data-field="email" data-emp-id="${eid}" onclick="editEmpField(this)">${escHtml(p.email || '')}</span>
                </div>
            </div>
            <button class="btn-remove" onclick="${removeFunc}(${p.role_id})" title="Remove">×</button>
        </div>`;
    }).join('');
}

async function addPersonToRole(employeeId) {
    const { siteId, role } = modalState;
    const res = await api({ action: 'add_role', site_id: siteId, role, employee_id: employeeId });
    if (res.success && !res.duplicate) {
        const emp = EMPLOYEES.find(e => e.id == employeeId);
        if (emp) {
            modalState.roles.push({
                role_id: res.role_id, role,
                employee_id: emp.id,
                first_name: emp.first_name, last_name: emp.last_name, email: emp.email,
            });
        }
        renderModalPeople(role);
        refreshRoleCell(siteId, role);
    }
}

async function removePersonFromRole(roleId) {
    const res = await api({ action: 'remove_role', role_id: roleId });
    if (res.success) {
        modalState.roles = modalState.roles.filter(r => r.role_id != roleId);
        renderModalPeople(modalState.role);
        refreshRoleCell(modalState.siteId, modalState.role);
    }
}

function closePeopleModal() {
    if (empTs) { empTs.destroy(); empTs = null; }
    document.getElementById('modal-overlay').classList.remove('open');
    // Reset new-person form
    document.getElementById('new-person-form').style.display = 'none';
    document.getElementById('btn-new-person-toggle').textContent = '+ Not in system?';
    document.getElementById('np-first').value = '';
    document.getElementById('np-last').value  = '';
    document.getElementById('np-email').value = '';
    modalState = {};
}

// ── Inline employee name / email editing ───────────────────────────────────
function editEmpField(span) {
    const field   = span.dataset.field;
    const empId   = span.dataset.empId;
    const origVal = span.classList.contains('emp-no-email') ? '' : span.textContent.trim();

    const input = document.createElement('input');
    input.type  = 'text';
    input.value = origVal;
    input.className = 'emp-field-input';
    input.dataset.field   = field;
    input.dataset.empId   = empId;
    input.dataset.origVal = origVal;
    if (field === 'email') input.setAttribute('data-field', 'email');

    span.replaceWith(input);
    input.focus();
    input.select();

    let saved = false;
    const done = () => { if (!saved) { saved = true; saveEmpField(input); } };
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter')  { e.preventDefault(); done(); }
        if (e.key === 'Escape') { saved = true; restoreEmpSpan(input); }
    });
    input.addEventListener('blur', done);
}

function restoreEmpSpan(input) {
    if (!input.parentNode) return;
    const span = buildEmpSpan(input.dataset.field, input.dataset.empId, input.dataset.origVal);
    input.replaceWith(span);
}

function buildEmpSpan(field, empId, val) {
    const span = document.createElement('span');
    const isEmail = field === 'email';
    if (isEmail) {
        span.className = val ? 'emp-editable emp-email' : 'emp-editable emp-no-email';
    } else {
        span.className = 'emp-editable';
    }
    span.dataset.field = field;
    span.dataset.empId = empId;
    span.textContent   = val;
    span.onclick       = function() { editEmpField(this); };
    return span;
}

async function saveEmpField(input) {
    if (!input.parentNode) return;
    const field   = input.dataset.field;
    const empId   = input.dataset.empId;
    const origVal = input.dataset.origVal;
    const newVal  = input.value.trim();

    // Restore span optimistically
    const span = buildEmpSpan(field, empId, newVal);
    input.replaceWith(span);

    if (newVal === origVal) return;

    const res = await api({ action: 'update_employee', employee_id: parseInt(empId), field, value: newVal });
    if (res.success) {
        // Update in-memory EMPLOYEES
        const emp = EMPLOYEES.find(e => e.id == empId);
        if (emp) emp[field] = newVal || null;
        // Update modalState.roles so badge re-renders correctly
        (modalState.roles || []).forEach(r => { if (r.employee_id == empId) r[field] = newVal; });
        // Re-render to update the badge initials/color if name changed
        if (field !== 'email') renderModalPeople(modalState.role || '_vp_lead');
    } else {
        span.textContent = origVal;
        if (field === 'email') {
            span.className = origVal ? 'emp-editable emp-email' : 'emp-editable emp-no-email';
        }
        console.error('update_employee error:', res.error);
    }
}

// ── New person form ────────────────────────────────────────────────────────
function toggleNewPersonForm() {
    const form = document.getElementById('new-person-form');
    const btn  = document.getElementById('btn-new-person-toggle');
    const open = form.style.display === 'none' || form.style.display === '';
    form.style.display = open ? 'block' : 'none';
    btn.textContent    = open ? '− Cancel' : '+ Not in system?';
    if (open) document.getElementById('np-first').focus();
}

async function createAndAddEmployee() {
    const first = document.getElementById('np-first').value.trim();
    const last  = document.getElementById('np-last').value.trim();
    const email = document.getElementById('np-email').value.trim();

    if (!first || !last || !email) {
        alert('First name, last name, and email are all required.');
        return;
    }

    const btn = document.getElementById('btn-create-emp');
    btn.disabled = true;
    btn.textContent = 'Adding…';

    try {
        const res = await api({ action: 'add_employee', first_name: first, last_name: last, email });
        if (res.error) { alert('Error: ' + res.error); return; }

        let emp;
        if (res.existing) {
            // Use the record that already exists in the system
            emp = EMPLOYEES.find(e => e.id === res.id);
            if (!emp) {
                // Employee was created after page load — add to local array
                emp = { id: res.id, first_name: res.first_name, last_name: res.last_name, email: res.email };
                EMPLOYEES.push(emp);
                if (empTs) empTs.addOption({ id: emp.id,
                    label: `${emp.last_name}, ${emp.first_name} · ${emp.email}` });
            }
            const matchDesc = res.match === 'email' ? 'email address' : 'name';
            btn.textContent = `Assigning existing…`;
        } else {
            emp = { id: res.id, first_name: first, last_name: last, email };
            EMPLOYEES.push(emp);
            if (empTs) empTs.addOption({ id: emp.id,
                label: `${last}, ${first} · ${email}` });
        }

        // Assign to role (duplicate guard is handled server-side)
        if (modalState.isVpLead) {
            await addVpLead(emp.id);
        } else {
            await addPersonToRole(emp.id);
        }

        // Reset form
        document.getElementById('np-first').value = '';
        document.getElementById('np-last').value  = '';
        document.getElementById('np-email').value = '';
        toggleNewPersonForm();
    } finally {
        btn.disabled = false;
        btn.textContent = 'Add to Role';
    }
}

function refreshRoleCell(siteId, role) {
    const row  = document.querySelector(`tr[data-id="${siteId}"]`);
    if (!row) return;
    const cell = row.querySelector(`td[data-role="${role}"]`);
    if (!cell) return;
    const people = (modalState.roles || []).filter(r => r.role === role);
    cell.innerHTML = people.length
        ? people.map(p =>
            `<span class="badge" style="background:${badgeColor(p.last_name+p.first_name)}"
                   title="${escHtml(p.last_name+', '+p.first_name+(p.email?' · '+p.email:''))}">
                ${initials(p.first_name, p.last_name)}
            </span>`).join('')
        : '<span class="empty-cell">—</span>';
}

// ── VP Lead modal (reuses people modal UI, different API actions) ──────────
async function openVpLeadModal(siteId, vpAreaId, cell) {
    if (!vpAreaId) { alert('Assign a VP Area to this site first.'); return; }
    modalState = { siteId, role: '_vp_lead', vpAreaId, cell, isVpLead: true };

    const row       = cell.closest('tr');
    const siteCell  = row.querySelector('td.col-site');
    const siteLabel = siteCell ? (siteCell.title || siteCell.textContent.trim()) : '';
    document.getElementById('modal-title').textContent    = 'VP Lead';
    document.getElementById('modal-subtitle').textContent = siteLabel;

    // Open the modal immediately with an empty list so the UI is never blocked
    modalState.roles = [];
    renderModalPeople('_vp_lead');

    const tsEl = document.getElementById('employee-ts');
    if (empTs) { empTs.destroy(); empTs = null; }
    tsEl.innerHTML = '';
    empTs = new TomSelect(tsEl, {
        valueField: 'id',
        labelField: 'label',
        searchField: 'label',
        placeholder: 'Search by name or email…',
        options: EMPLOYEES.map(e => ({
            id:    e.id,
            label: `${e.last_name}, ${e.first_name}${e.email ? ' · ' + e.email : ''}`,
        })),
        create: false,
        dropdownParent: 'body',
        onDropdownOpen(dropdown) {
            const rect = this.control.getBoundingClientRect();
            Object.assign(dropdown.style, {
                position: 'fixed',
                zIndex:   '99999',
                top:  rect.bottom + 'px',
                left: rect.left   + 'px',
            });
        },
        onItemAdd(val) {
            addVpLead(parseInt(val));
            empTs.clear(true);
        },
    });
    document.getElementById('modal-overlay').classList.add('open');

    // Load existing leads after the modal is visible
    try {
        const data = await api({ action: 'get_vp_leads', vp_area_id: vpAreaId });
        if (data.error) throw new Error(data.error);
        modalState.roles = (data.leads || []).map(l => ({ ...l, role_id: l.lead_id, role: '_vp_lead', employee_id: l.lead_id }));
        renderModalPeople('_vp_lead');
    } catch(e) {
        console.error('VP leads load failed:', e);
        document.getElementById('modal-people-list').innerHTML =
            `<p style="color:#ef4444;font-size:12px;margin:0 0 8px">Error: ${escHtml(e.message)}</p>`;
    }
}

async function addVpLead(employeeId) {
    const { vpAreaId } = modalState;
    const res = await api({ action: 'add_vp_lead', vp_area_id: vpAreaId, employee_id: employeeId });
    if (res.success && !res.duplicate) {
        const emp = EMPLOYEES.find(e => e.id == employeeId);
        if (emp) {
            modalState.roles.push({
                role_id: res.lead_id, role: '_vp_lead',
                employee_id: emp.id,
                first_name: emp.first_name, last_name: emp.last_name, email: emp.email,
            });
        }
        renderModalPeople('_vp_lead');
        refreshVpLeadCell(modalState.siteId);
    }
}

async function removeVpLead(leadId) {
    const res = await api({ action: 'remove_vp_lead', lead_id: leadId, vp_area_id: modalState.vpAreaId });
    if (res.success) {
        modalState.roles = modalState.roles.filter(r => r.role_id != leadId);
        renderModalPeople('_vp_lead');
        refreshVpLeadCell(modalState.siteId);
    }
}

function refreshVpLeadCell(siteId) {
    const row  = document.querySelector(`tr[data-id="${siteId}"]`);
    if (!row) return;
    const cell = row.querySelector('td[data-vp-area-id]');
    if (!cell) return;
    const people = modalState.roles || [];
    cell.innerHTML = people.length
        ? people.map(p =>
            `<span class="badge" style="background:${badgeColor(p.last_name+p.first_name)}"
                   title="${escHtml(p.last_name+', '+p.first_name+(p.email?' · '+p.email:''))}">
                ${initials(p.first_name, p.last_name)}
            </span>`).join('')
        : '<span class="empty-cell">—</span>';
}

// ── Site edit modal (URL + Site Name) ──────────────────────────────────────
let siteEditId = null;

function openSiteEditModal(siteId, siteName, url) {
    siteEditId = siteId;
    document.getElementById('site-edit-name').value  = siteName || '';
    document.getElementById('site-edit-url').value   = url      || '';
    document.getElementById('site-edit-error').style.display = 'none';
    const overlay = document.getElementById('site-edit-overlay');
    overlay.style.display = 'flex';
    setTimeout(() => document.getElementById('site-edit-name').focus(), 50);
}

function closeSiteEditModal() {
    document.getElementById('site-edit-overlay').style.display = 'none';
    siteEditId = null;
}

async function saveSiteEditModal() {
    const siteId = siteEditId;
    const name   = document.getElementById('site-edit-name').value.trim();
    const url    = document.getElementById('site-edit-url').value.trim();
    const errEl  = document.getElementById('site-edit-error');
    const btn    = document.getElementById('site-edit-save');

    if (!url) {
        errEl.textContent = 'URL is required.';
        errEl.style.display = 'block';
        document.getElementById('site-edit-url').focus();
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Saving…';
    try {
        const [r1, r2] = await Promise.all([
            api({ action:'update_site', site_id:siteId, field:'site_name', value:name }),
            api({ action:'update_site', site_id:siteId, field:'url',       value:url  }),
        ]);
        if (r1.error || r2.error) {
            errEl.textContent = r1.error || r2.error;
            errEl.style.display = 'block';
            return;
        }

        // Update the DOM cell
        const row = document.querySelector(`tr[data-id="${siteId}"]`);
        if (row) {
            const td      = row.querySelector('td.col-site');
            const inner   = td.querySelector('.site-inner');
            const display = name || url;
            let a = inner.querySelector('a');
            if (!a) {
                a = document.createElement('a');
                a.target = '_blank';
                a.addEventListener('click', e => e.stopPropagation());
                inner.innerHTML = '';
                inner.appendChild(a);
            }
            a.textContent = display;
            a.href = 'https://' + url;
            td.title = display + (name && url ? '\n' + url : '');

            // Refresh the button's onclick with fresh values
            const editBtn = td.querySelector('.site-edit-btn');
            if (editBtn) editBtn.onclick = e => {
                e.stopPropagation();
                openSiteEditModal(siteId, name, url);
            };

            // Keep row data attrs in sync for filtering
            row.dataset.url       = url.toLowerCase();
            row.dataset.site_name = name.toLowerCase();
        }
        closeSiteEditModal();
    } finally {
        btn.disabled = false;
        btn.textContent = 'Save';
    }
}

// ── Add new site ───────────────────────────────────────────────────────────
function addSite() {
    const overlay = document.getElementById('add-site-overlay');
    const input   = document.getElementById('add-site-input');
    const err     = document.getElementById('add-site-error');
    input.value   = '';
    err.style.display = 'none';
    overlay.style.display = 'flex';
    setTimeout(() => input.focus(), 50);
}

function closeAddSiteModal() {
    document.getElementById('add-site-overlay').style.display = 'none';
}

async function saveAddSiteModal() {
    const input = document.getElementById('add-site-input');
    const err   = document.getElementById('add-site-error');
    const url   = input.value.trim();

    if (!url) {
        err.textContent = 'Please enter a URL.';
        err.style.display = 'block';
        input.focus();
        return;
    }

    const btn = document.querySelector('#add-site-overlay button');
    btn.disabled = true;
    btn.textContent = 'Adding…';

    try {
        const res = await api({ action: 'add_site', url });
        if (res.success) {
            location.reload();
        } else {
            err.textContent = res.error || 'An error occurred.';
            err.style.display = 'block';
        }
    } finally {
        btn.disabled = false;
        btn.textContent = 'Add Site';
    }
}

// Allow Enter key to submit the add-site modal
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('add-site-input').addEventListener('keydown', e => {
        if (e.key === 'Enter')  saveAddSiteModal();
        if (e.key === 'Escape') closeAddSiteModal();
    });
    ['site-edit-name','site-edit-url'].forEach(id => {
        document.getElementById(id).addEventListener('keydown', e => {
            if (e.key === 'Enter')  saveSiteEditModal();
            if (e.key === 'Escape') closeSiteEditModal();
        });
    });
});

// ── DubBot integration ─────────────────────────────────────────────────────

// Raw GraphQL call — returns full {data, errors} without throwing.
// Used for stats fetches so complexity errors can be inspected.
async function dbFetch(query) {
    const res = await fetch('dubbot.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ query }),
    });
    const json = await res.json();
    if (json.error) throw new Error(json.error);
    return json;  // {data, errors}
}

// Error-checked GraphQL call — throws on proxy errors or GraphQL errors.
// Used only for discovery queries.
async function dbGql(query) {
    const json = await dbFetch(query);
    if (json.errors) throw new Error(json.errors.map(e => e.message).join('; '));
    return json.data;
}

function dbExtractList(raw) {
    if (!raw) return [];
    if (Array.isArray(raw)) return raw;
    for (const k of ['nodes','items','results','data']) {
        if (Array.isArray(raw[k])) return raw[k];
    }
    if (Array.isArray(raw.edges)) return raw.edges.map(e => e.node).filter(Boolean);
    return [raw];
}

function dbNorm(url) {
    return (url || '').replace(/^https?:\/\//i, '').replace(/\/+$/, '').toLowerCase();
}

function dbSetStatus(type, msg) {
    const el  = document.getElementById('grp-dubbot');
    const btn = document.getElementById('db-refresh-btn');
    if (!el) return;
    const btnHtml = `<button class="db-refresh-btn" id="db-refresh-btn" onclick="loadDubBotData()" title="Refresh DubBot data from API">↻ Refresh</button>`;
    if (type === 'loading') {
        el.innerHTML = `DubBot <span class="db-hdr-spin"></span><span class="db-hdr-status">${escHtml(msg)}</span> <button class="db-refresh-btn" id="db-refresh-btn" disabled>↻ Refresh</button>`;
    } else if (type === 'error') {
        el.innerHTML = `DubBot <span class="db-hdr-error">⚠ ${escHtml(msg)}</span> ${btnHtml}`;
    } else {
        el.innerHTML = `DubBot <span class="db-hdr-status">${escHtml(msg)}</span> ${btnHtml}`;
    }
}

function dbScoreHtml(score, total) {
    if (score === null || score === undefined) return '<span class="empty-cell">—</span>';
    const cls = score >= 90 ? 'db-good' : score >= 70 ? 'db-ok' : 'db-poor';
    const tip  = total != null ? ` title="${total.toLocaleString()} issues"` : '';
    return `<span class="db-score ${cls}"${tip}>${score.toFixed(1)}%</span>`;
}

function dbFillRow(row, site) {
    const snap = site?.latestStatsSnapshot;
    row.querySelectorAll('td[data-db-col]').forEach(td => {
        const col = td.dataset.dbCol;
        if (col === 'pages') {
            td.innerHTML = site?.pagesCount != null
                ? site.pagesCount.toLocaleString()
                : '<span class="empty-cell">—</span>';
        } else if (col === 'score') {
            td.innerHTML = dbScoreHtml(snap?.score);
        } else {
            const cat = snap?.[col];
            td.innerHTML = dbScoreHtml(cat?.score, cat?.total);
        }
    });
}

async function loadDubBotData() {
    dbSetStatus('loading', 'Connecting…');

    // ── Step 1: discover accounts and their sites ──────────────────────────
    let accounts = [];
    const discoveryStrategies = [
        // A: siteMemberships — group by account
        async () => {
            const data = await dbGql(`{
                currentUser {
                    siteMemberships { site { id name url } account { id name } }
                }
            }`);
            const mems = dbExtractList(data?.currentUser?.siteMemberships);
            if (!mems.length) return [];
            const map = new Map();
            for (const m of mems) {
                const site = m.site || m;
                const acc  = m.account || { id: '__default__', name: 'My Sites' };
                if (!map.has(acc.id)) map.set(acc.id, { ...acc, sites: [] });
                if (site?.id) map.get(acc.id).sites.push(site);
            }
            return [...map.values()];
        },
        // B: memberships → account.sites[]
        async () => {
            const data = await dbGql(`{
                currentUser {
                    memberships { account { id name sites { id name url } } }
                }
            }`);
            return dbExtractList(data?.currentUser?.memberships)
                .map(m => m.account).filter(Boolean)
                .map(a => ({ ...a, sites: dbExtractList(a.sites) }));
        },
        // C: membership (singular)
        async () => {
            const data = await dbGql(`{
                currentUser {
                    membership { account { id name sites { id name url } } }
                }
            }`);
            const acc = data?.currentUser?.membership?.account;
            return acc ? [{ ...acc, sites: dbExtractList(acc.sites) }] : [];
        },
        // D: currentMembership
        async () => {
            const data = await dbGql(`{
                currentUser {
                    currentMembership { account { id name sites { id name url } } }
                }
            }`);
            const acc = data?.currentUser?.currentMembership?.account;
            return acc ? [{ ...acc, sites: dbExtractList(acc.sites) }] : [];
        },
    ];

    for (const strategy of discoveryStrategies) {
        try {
            const result = await strategy();
            if (result.length) { accounts = result; break; }
        } catch (_) { /* try next strategy */ }
    }

    if (!accounts.length) {
        dbSetStatus('error', 'No DubBot accounts found');
        document.querySelectorAll('td[data-db-col]').forEach(td => {
            td.innerHTML = '<span class="empty-cell">—</span>';
        });
        return;
    }

    const totalDbSites = accounts.reduce((n, a) => n + a.sites.length, 0);
    dbSetStatus('loading', `Matching ${totalDbSites} DubBot sites…`);

    // ── Step 2: build normalized URL → {siteId, accountId} map ───────────
    const urlMap = {};
    for (const acc of accounts) {
        for (const site of acc.sites) {
            const norm = dbNorm(site.url);
            if (norm) urlMap[norm] = { siteId: site.id, accountId: acc.id };
        }
    }

    // ── Step 3: match governance table rows ────────────────────────────────
    const allRows = [...document.querySelectorAll('#main-table tbody tr[data-id]')];
    const matched = [];
    allRows.forEach(row => {
        const entry = urlMap[dbNorm(row.dataset.url)];
        if (entry) matched.push({ row, ...entry });
        else       dbFillRow(row, null);
    });

    if (!matched.length) {
        dbSetStatus('done', `0 / ${allRows.length} matched`);
        return;
    }

    // ── Step 4: fetch stats with adaptive parallel batching ──────────────
    const DB_FRAGMENT = `
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
    `;

    async function fetchBatch(batch, offset) {
        const aliases = batch.map((r, j) =>
            `s_${offset + j}: site(siteId:"${r.siteId}", accountId:"${r.accountId}") { ${DB_FRAGMENT} }`
        ).join('\n');
        return dbFetch(`{ ${aliases} }`);  // returns {data, errors}
    }

    function applyBatch(data, batch, offset) {
        batch.forEach((r, j) => dbFillRow(r.row, data?.[`s_${offset + j}`] ?? null));
    }

    dbSetStatus('loading', `Fetching stats for ${matched.length} matched site${matched.length !== 1 ? 's' : ''}…`);

    try {
        // First attempt: all sites in one request
        const first = await fetchBatch(matched, 0);
        const complexErr = (first.errors || []).find(e => /complexity/i.test(e.message));

        if (!complexErr) {
            applyBatch(first.data, matched, 0);
            await dbSaveStats(matched);
            dbSetStatus('done', `${matched.length} / ${allRows.length} matched — saved`);
            return;
        }

        // Parse complexity error → calculate safe batch size
        let batchSize = 5;
        const cm = complexErr.message.match(/complexity of (\d+).*max complexity of (\d+)/i);
        if (cm) {
            const perItem = parseInt(cm[1]) / matched.length;
            batchSize = Math.max(1, Math.floor(parseInt(cm[2]) / perItem));
        }

        dbSetStatus('loading', `Fetching in batches of ${batchSize}…`);

        // Split and fire ALL batches in parallel
        const batches = [];
        for (let i = 0; i < matched.length; i += batchSize)
            batches.push({ rows: matched.slice(i, i + batchSize), offset: i });

        const results = await Promise.all(
            batches.map(b => fetchBatch(b.rows, b.offset).catch(() => null))
        );
        results.forEach((json, bi) => {
            if (json?.data) applyBatch(json.data, batches[bi].rows, batches[bi].offset);
        });
        await dbSaveStats(matched);
        dbSetStatus('done', `${matched.length} / ${allRows.length} matched — saved`);

    } catch (e) {
        console.error('DubBot load failed:', e);
        dbSetStatus('error', e.message);
        matched.forEach(r => dbFillRow(r.row, null));
    }
}

// ── Persist DubBot stats for changed rows ──────────────────────────────────
async function dbSaveStats(matched) {
    // Collect rows where any value differs from the data-db-saved attribute
    const COL_MAP = {
        score: 'score', accessibility: 'accessibility', badLinks: 'bad_links',
        seo: 'seo', spelling: 'spelling', bestPractices: 'best_practices',
        webGovernance: 'web_governance',
    };

    const updates = [];
    for (const { row } of matched) {
        const siteId = row.dataset.id;
        const stats  = { site_id: parseInt(siteId) };
        let   changed = false;

        // Score-type columns
        for (const [dbCol, dbField] of Object.entries(COL_MAP)) {
            const td  = row.querySelector(`td[data-db-col="${dbCol}"]`);
            if (!td) continue;
            const span = td.querySelector('.db-score');
            const newVal = span ? parseFloat(span.textContent) : null;
            const oldVal = td.dataset.dbSaved !== undefined ? parseFloat(td.dataset.dbSaved) : null;
            stats[dbField] = newVal;
            if (newVal !== oldVal) changed = true;
        }

        // Pages
        const pagesTd = row.querySelector('td[data-db-col="pages"]');
        const pagesNew = pagesTd?.textContent.trim().replace(/,/g, '');
        const pagesVal = pagesNew ? parseInt(pagesNew) : null;
        const pagesOld = pagesTd?.dataset.dbSaved !== undefined ? parseInt(pagesTd.dataset.dbSaved) : null;
        stats.pages_count = pagesVal;
        if (pagesVal !== pagesOld) changed = true;

        if (changed) updates.push(stats);
    }

    if (!updates.length) return;

    await api({ action: 'save_dubbot_stats', stats: updates });

    // Update data-db-saved attrs so next refresh detects changes correctly
    for (const { row } of matched) {
        for (const dbCol of [...Object.keys(COL_MAP), 'pages']) {
            const td   = row.querySelector(`td[data-db-col="${dbCol}"]`);
            if (!td) continue;
            if (dbCol === 'pages') {
                const v = td.textContent.trim().replace(/,/g, '');
                td.dataset.dbSaved = v ? parseInt(v) : '';
            } else {
                const span = td.querySelector('.db-score');
                td.dataset.dbSaved = span ? parseFloat(span.textContent) : '';
            }
        }
    }
}

// ── Utilities ──────────────────────────────────────────────────────────────
async function api(payload) {
    const res = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    return res.json();
}

function escHtml(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;')
                            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function initials(first, last) {
    return ((first||'')[0]||'').toUpperCase() + ((last||'')[0]||'').toUpperCase() || '?';
}

function badgeColor(name) {
    // Palette anchored to UTSA brand colors, filled out with accessible complements
    const palette = ['#032044','#265BF7','#D3430D','#A06620','#F15A22',
                     '#8B5CF6','#10B981','#EC4899','#6366F1','#0891B2'];
    let h = 0;
    for (const c of String(name||'')) h = (h * 31 + c.charCodeAt(0)) & 0x7fffffff;
    return palette[h % palette.length];
}

// ── Cell tooltip ───────────────────────────────────────────────────────────
(function () {
    const tip   = document.getElementById('cell-tooltip');
    const PAD   = 12;   // px gap from cursor
    let   shown = false;

    function show(text, x, y) {
        tip.textContent = text;
        tip.classList.add('visible');
        shown = true;
        position(x, y);
    }

    function hide() {
        tip.classList.remove('visible');
        shown = false;
    }

    function position(cx, cy) {
        // Try below-right first; flip if it would clip the viewport
        tip.style.left = '0';
        tip.style.top  = '0';
        const tw = tip.offsetWidth;
        const th = tip.offsetHeight;
        const vw = window.innerWidth;
        const vh = window.innerHeight;

        let x = cx + PAD;
        let y = cy + PAD;
        if (x + tw > vw - 4) x = cx - tw - PAD;
        if (y + th > vh - 4) y = cy - th - PAD;
        tip.style.left = x + 'px';
        tip.style.top  = y + 'px';
    }

    // Event delegation on the whole table body
    const tbody = document.querySelector('#main-table tbody');
    if (!tbody) return;

    tbody.addEventListener('mouseover', e => {
        const td = e.target.closest('td[title]');
        if (!td) { hide(); return; }
        const text = td.getAttribute('title');
        if (!text) { hide(); return; }
        show(text, e.clientX, e.clientY);
    });

    tbody.addEventListener('mousemove', e => {
        if (!shown) return;
        position(e.clientX, e.clientY);
    });

    tbody.addEventListener('mouseout', e => {
        // Only hide when we leave a td[title] entirely (not into a child)
        const td = e.target.closest('td[title]');
        if (!td) return;
        if (!td.contains(e.relatedTarget)) hide();
    });

    // Hide if user clicks (going into edit mode)
    tbody.addEventListener('click', hide);
})();
</script>
</body>
</html>
