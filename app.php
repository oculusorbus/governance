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
        s.second_category_id,    cat2.name AS second_category
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

// ── Helpers ───────────────────────────────────────────────────────────────
function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
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

    <!-- Tom Select -->
    <link  rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body { margin: 0; font-family: system-ui, sans-serif; font-size: 13px;
               background: #f8fafc; color: #1e293b; }

        /* ── Header ───────────────────────────────────────────────────── */
        #topbar { display:flex; align-items:center; gap:12px; padding:10px 16px;
                  background:#1e3a5f; color:#fff; position:sticky; top:0; z-index:50; }
        #topbar h1 { font-size:15px; font-weight:700; margin:0; flex:1; }
        #topbar button { font-size:12px; padding:5px 12px; border:none; border-radius:6px;
                         cursor:pointer; font-weight:600; transition:background .15s; }
        #btn-cols  { background:#2d5282; color:#fff; }
        #btn-cols:hover  { background:#2c4a7c; }
        #btn-add   { background:#16a34a; color:#fff; }
        #btn-add:hover   { background:#15803d; }
        #btn-logout { background:#dc2626; color:#fff; }
        #btn-logout:hover { background:#b91c1c; }
        #row-count { font-size:12px; color:#93c5fd; }

        /* ── Column visibility panel ──────────────────────────────────── */
        #col-panel { display:none; background:#fff; border-bottom:1px solid #e2e8f0;
                     padding:12px 16px; flex-wrap:wrap; gap:8px; }
        #col-panel.open { display:flex; }
        #col-panel label { display:flex; align-items:center; gap:5px; font-size:12px;
                           cursor:pointer; user-select:none; }
        #col-panel input[type=checkbox] { accent-color:#3b82f6; }

        /* ── Column filter buttons ────────────────────────────────────── */
        .filter-btn { position:absolute; right:4px; top:50%; transform:translateY(-50%);
                      display:inline-flex; align-items:center; background:none; border:none;
                      cursor:pointer; padding:2px; opacity:.35; color:inherit;
                      border-radius:3px; transition:opacity .15s, color .15s; }
        .filter-btn:hover { opacity:.8; }
        .filter-btn.filter-active { opacity:1; color:#3b82f6; }

        /* ── Filter popover ───────────────────────────────────────────── */
        #filter-popover { display:none; position:fixed; background:#fff;
                          border:1px solid #e2e8f0; border-radius:8px;
                          box-shadow:0 6px 20px rgba(0,0,0,.13); z-index:10000;
                          padding:10px; width:230px; max-height:360px;
                          flex-direction:column; }
        #filter-popover.open { display:flex; }
        #filter-pop-search, #filter-pop-text {
            width:100%; padding:5px 8px; border:1px solid #cbd5e1; border-radius:6px;
            font-size:12px; outline:none; margin-bottom:6px; box-sizing:border-box; }
        #filter-pop-search:focus, #filter-pop-text:focus { border-color:#3b82f6; }
        .filter-pop-list { overflow-y:auto; max-height:200px; margin-bottom:6px; }
        .filter-pop-item { display:flex; align-items:center; gap:6px; padding:3px 4px;
                           border-radius:4px; cursor:pointer; font-size:12px; user-select:none; }
        .filter-pop-item:hover { background:#f1f5f9; }
        .filter-pop-item input { flex-shrink:0; cursor:pointer; accent-color:#3b82f6; }
        .filter-pop-sep { height:1px; background:#e2e8f0; margin:4px 0; flex-shrink:0; }
        .filter-pop-actions { display:flex; gap:6px; padding-top:8px;
                              border-top:1px solid #e2e8f0; flex-shrink:0; }
        .filter-pop-actions button { flex:1; padding:5px; border-radius:6px; border:none;
                                     cursor:pointer; font-size:12px; font-weight:600; }
        .filter-pop-actions .btn-clear { background:#f1f5f9; color:#475569; }
        .filter-pop-actions .btn-clear:hover { background:#e2e8f0; }
        .filter-pop-actions .btn-apply { background:#3b82f6; color:#fff; }
        .filter-pop-actions .btn-apply:hover { background:#2563eb; }
        #btn-clear-filters { background:#f59e0b; color:#fff; }

        /* ── Table wrapper ────────────────────────────────────────────── */
        /* position:relative + z-index:1 creates a stacking context that
           scopes all sticky-cell z-indices inside it, so the body-level
           Tom Select dropdown (z-index 99999) paints above the table. */
        #table-wrap { overflow-x:auto; overflow-y:auto; max-height:calc(100vh - 140px);
                      position:relative; z-index:1; }

        /* ── Table ────────────────────────────────────────────────────── */
        table { border-collapse:collapse; width:max-content; }

        /* Group header row */
        thead tr.groups th { font-size:11px; font-weight:700; text-transform:uppercase;
                              letter-spacing:.05em; color:#fff; padding:5px 8px;
                              border-right:2px solid rgba(255,255,255,.3); }
        .grp-identity       { background:#1e3a5f; }
        .grp-governance     { background:#1e4d5f; }
        .grp-people         { background:#3b4f6d; }
        .grp-support        { background:#4a5568; }
        .grp-technical      { background:#2d4a3e; }
        .grp-classification { background:#4a3728; }

        /* Column header row */
        thead tr.headers th { font-size:11px; font-weight:600; color:#475569;
                              background:#f8fafc; padding:6px 20px 6px 8px; white-space:nowrap;
                              border-bottom:2px solid #e2e8f0; border-right:1px solid #e2e8f0;
                              position:sticky; top:28px; z-index:10; overflow:hidden; }

        /* Data cells */
        td { padding:5px 8px; border-bottom:1px solid #f1f5f9;
             border-right:1px solid #f1f5f9; vertical-align:middle;
             max-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

        tr:hover td { background:#f0f7ff; }
        tr:hover td.sticky { background:#e8f0fe; }

        /* Sticky columns */
        .sticky-1 { position:sticky; left:0;     z-index:5; background:#fff; min-width:220px; max-width:220px; }
        .sticky-2 { position:sticky; left:220px; z-index:5; background:#fff; min-width:200px; max-width:200px; }
        thead .sticky-1, thead .sticky-2 { z-index:15; }

        /* Column widths */
        .col-description        { min-width:240px; max-width:240px; }
        .col-vp_area            { min-width:100px; max-width:100px; }
        .col-vp_lead            { min-width:110px; max-width:110px; }
        .col-college_dept       { min-width:140px; max-width:140px; }
        .col-college_communicator,
        .col-site_owner,
        .col-content_lead,
        .col-tech_lead,
        .col-admin_contact      { min-width:100px; max-width:100px; }
        .col-support_platform   { min-width:140px; max-width:140px; }
        .col-support_intake_url,
        .col-datastudio_url     { min-width:62px;  max-width:62px;  text-align:center; }
        .col-server             { min-width:130px; max-width:130px; }
        .col-platform           { min-width:130px; max-width:130px; }
        .col-audience           { min-width:90px;  max-width:90px; }
        .col-category           { min-width:130px; max-width:130px; }
        .col-second_category    { min-width:130px; max-width:130px; }

        /* Editable cells */
        td.editable { cursor:pointer; }
        td.editable:hover { background:#eff6ff !important; }
        td.editable:hover::after { content:'✎'; font-size:10px; color:#93c5fd;
                                    margin-left:4px; float:right; }

        /* Editing state */
        td.editing { padding:2px 4px; overflow:visible; }
        td.editing input[type=text] {
            width:100%; font-size:13px; border:2px solid #3b82f6; border-radius:4px;
            padding:3px 6px; outline:none; background:#fff; }

        /* Badges */
        .badge { display:inline-flex; align-items:center; justify-content:center;
                 width:26px; height:26px; border-radius:50%; color:#fff;
                 font-size:10px; font-weight:700; cursor:pointer;
                 margin:1px; transition:transform .1s; }
        .badge:hover { transform:scale(1.15); }
        .empty-cell { color:#cbd5e1; }

        /* Link icon */
        .link-icon { display:inline-flex; align-items:center; justify-content:center;
                     width:26px; height:26px; border-radius:6px; background:#e0f2fe;
                     color:#0284c7; font-size:14px; cursor:pointer; text-decoration:none; }
        .link-icon:hover { background:#bae6fd; }
        .link-edit-btn { display:inline-flex; align-items:center; justify-content:center;
                         width:26px; height:26px; border-radius:6px; background:#f1f5f9;
                         color:#94a3b8; font-size:14px; cursor:pointer; border:none; }
        .link-edit-btn:hover { background:#e2e8f0; color:#64748b; }

        /* ── Tom Select overrides ─────────────────────────────────────── */
        .ts-wrapper { min-width:100%; }
        .ts-control  { font-size:12px; border:2px solid #3b82f6 !important;
                       border-radius:4px !important; min-height:28px !important;
                       padding:2px 6px !important; box-shadow:none !important; }
        .ts-dropdown { font-size:12px; z-index:99999 !important; background:#fff !important; }
        .ts-dropdown .option:hover,
        .ts-dropdown .option.active { background:#eff6ff !important; color:#1e293b !important; cursor:pointer; }

        /* ── Modal ────────────────────────────────────────────────────── */
        #modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
                         z-index:200; align-items:center; justify-content:center; }
        #modal-overlay.open { display:flex; }
        #modal-box { background:#fff; border-radius:12px; padding:24px; width:460px;
                     max-height:80vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.25); }
        #modal-box h2 { margin:0 0 4px; font-size:15px; font-weight:700; }
        #modal-box .subtitle { color:#94a3b8; font-size:12px; margin-bottom:16px; }
        .modal-person { display:flex; align-items:center; gap:8px; padding:6px 0;
                        border-bottom:1px solid #f1f5f9; }
        .modal-person:last-child { border:none; }
        .modal-person-info { flex:1; }
        .modal-person-info .name { font-weight:600; font-size:13px; }
        .modal-person-info .email { font-size:11px; color:#94a3b8; }
        .btn-remove { background:none; border:none; color:#ef4444; font-size:18px;
                      cursor:pointer; padding:0 4px; line-height:1; }
        .btn-remove:hover { color:#b91c1c; }
        .modal-add { margin-top:16px; }
        .modal-add label { display:block; font-size:12px; font-weight:600;
                           color:#64748b; margin-bottom:6px; }
        #modal-close { width:100%; margin-top:16px; padding:8px; background:#f1f5f9;
                       border:none; border-radius:8px; cursor:pointer; font-size:13px;
                       font-weight:600; color:#475569; }
        #modal-close:hover { background:#e2e8f0; }

        /* Editable employee name/email spans */
        .emp-editable { cursor:pointer; padding:1px 3px; border-radius:3px;
                        transition:background .1s; display:inline; }
        .emp-editable:hover { background:#eff6ff; }
        .emp-editable.emp-email { color:#94a3b8; font-size:11px; }
        .emp-editable.emp-no-email { color:#cbd5e1; font-size:10px; }
        .emp-editable.emp-no-email::after { content:'+ email'; }
        .emp-field-input { font-size:13px; border:1px solid #3b82f6; border-radius:3px;
                           padding:1px 5px; outline:none; min-width:80px; max-width:160px; }
        .emp-field-input[data-field="email"] { font-size:11px; color:#94a3b8; max-width:200px; }

        /* New person form */
        .modal-new-toggle { margin-top:10px; text-align:center; }
        #btn-new-person-toggle { background:none; border:none; color:#3b82f6; font-size:12px;
                                 cursor:pointer; padding:0; font-weight:600; }
        #btn-new-person-toggle:hover { text-decoration:underline; }
        #new-person-form { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;
                           padding:12px; margin-top:8px; }
        .new-person-fields { display:grid; grid-template-columns:1fr 1fr; gap:6px;
                             margin-bottom:10px; }
        .new-person-fields input { padding:5px 8px; border:1px solid #cbd5e1; border-radius:6px;
                                   font-size:12px; outline:none; }
        .new-person-fields input:focus { border-color:#3b82f6; }
        .new-person-fields input.full-width { grid-column:1/-1; }
        #btn-create-emp { width:100%; padding:7px; background:#3b82f6; color:#fff; border:none;
                          border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; }
        #btn-create-emp:hover:not(:disabled) { background:#2563eb; }
        #btn-create-emp:disabled { background:#93c5fd; cursor:not-allowed; }

        /* Zebra stripe */
        tbody tr:nth-child(even) td { background:#fafbfc; }
        tbody tr:nth-child(even) td.sticky { background:#f5f7fa; }
        tbody tr:hover td { background:#eff6ff !important; }
        tbody tr:hover td.sticky { background:#e8f0fe !important; }
    </style>
</head>
<body>

<!-- ── Top bar ──────────────────────────────────────────────────────────── -->
<div id="topbar">
    <h1>Website Governance Directory</h1>
    <span id="row-count"></span>
    <button id="btn-cols"         onclick="toggleColPanel()">Columns</button>
    <button id="btn-clear-filters" onclick="clearAllFilters()" style="display:none">✕ Filters</button>
    <button id="btn-add"          onclick="addSite()">+ Add Site</button>
    <a href="logout.php"><button id="btn-logout">Sign Out</button></a>
</div>

<!-- ── Column visibility panel ──────────────────────────────────────────── -->
<div id="col-panel">
<?php
$toggleCols = [
    'description','vp_area','vp_lead','college_dept',
    'college_communicator','site_owner','content_lead','tech_lead','admin_contact',
    'support_platform','support_intake_url','datastudio_url',
    'server','platform','audience','category','second_category',
];
$colLabels = [
    'description'=>'Description','vp_area'=>'VP Area','vp_lead'=>'VP Lead',
    'college_dept'=>'College/Dept','college_communicator'=>'Communicator',
    'site_owner'=>'Site Owner','content_lead'=>'Content Lead','tech_lead'=>'Tech Lead',
    'admin_contact'=>'Admin Contact','support_platform'=>'Support Platform',
    'support_intake_url'=>'Intake URL','datastudio_url'=>'Datastudio',
    'server'=>'Server','platform'=>'Platform','audience'=>'Audience',
    'category'=>'Category','second_category'=>'2nd Category',
];
$defaultHidden = ['description'];
foreach ($toggleCols as $key):
    $chk = in_array($key, $defaultHidden) ? '' : ' checked';
?>
    <label>
        <input type="checkbox" data-col="<?= $key ?>"<?= $chk ?> onchange="toggleCol('<?= $key ?>', this.checked)">
        <?= h($colLabels[$key]) ?>
    </label>
<?php endforeach; ?>
</div>


<!-- ── Table ────────────────────────────────────────────────────────────── -->
<div id="table-wrap">
<table id="main-table">
<thead>
    <!-- Group headers -->
    <tr class="groups">
        <th colspan="2" class="grp-identity sticky-1">Identity</th>
        <th colspan="1" class="grp-identity col-description">&#8203;</th>
        <th colspan="3" class="grp-governance">Governance</th>
        <th colspan="5" class="grp-people">People</th>
        <th colspan="2" class="grp-support">Support</th>
        <th colspan="3" class="grp-technical">Technical</th>
        <th colspan="3" class="grp-classification">Classification</th>
    </tr>
    <!-- Column headers -->
    <tr class="headers">
        <th class="sticky-1 col-url">URL <?= filterBtn('url') ?></th>
        <th class="sticky-2 col-site_name">Site Name <?= filterBtn('site_name') ?></th>
        <th class="col-description">Description <?= filterBtn('description') ?></th>
        <th class="col-vp_area">VP Area <?= filterBtn('vp_area') ?></th>
        <th class="col-vp_lead">VP Lead <?= filterBtn('vp_lead') ?></th>
        <th class="col-college_dept">College/Dept <?= filterBtn('college_dept') ?></th>
        <th class="col-college_communicator">Communicator <?= filterBtn('college_communicator') ?></th>
        <th class="col-site_owner">Owner <?= filterBtn('site_owner') ?></th>
        <th class="col-content_lead">Content Lead <?= filterBtn('content_lead') ?></th>
        <th class="col-tech_lead">Tech Lead <?= filterBtn('tech_lead') ?></th>
        <th class="col-admin_contact">Admin Contact <?= filterBtn('admin_contact') ?></th>
        <th class="col-support_platform">Support Platform <?= filterBtn('support_platform') ?></th>
        <th class="col-support_intake_url">Intake</th>
        <th class="col-datastudio_url">Studio</th>
        <th class="col-server">Server <?= filterBtn('server') ?></th>
        <th class="col-platform">Platform <?= filterBtn('platform') ?></th>
        <th class="col-audience">Audience <?= filterBtn('audience') ?></th>
        <th class="col-category">Category <?= filterBtn('category') ?></th>
        <th class="col-second_category">2nd Category <?= filterBtn('second_category') ?></th>
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
        'data-support_platform="' . h(strtolower($site['support_platform'] ?? ''))  . '"',
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

        <!-- URL (sticky) -->
        <td class="sticky-1 col-url editable"
            data-site-id="<?= $sid ?>" data-field="url" data-type="text"
            data-value="<?= h($site['url']) ?>"
            title="<?= h($site['url']) ?>">
            <?php if ($site['url']): ?>
                <a href="https://<?= h($site['url']) ?>" target="_blank"
                   style="color:#0284c7;text-decoration:none"
                   onclick="event.stopPropagation()"><?= h($site['url']) ?></a>
            <?php endif; ?>
        </td>

        <!-- Site Name (sticky) -->
        <td class="sticky-2 col-site_name editable"
            data-site-id="<?= $sid ?>" data-field="site_name" data-type="text"
            data-value="<?= h($site['site_name']) ?>"
            title="<?= h($site['site_name']) ?>">
            <?= h($site['site_name']) ?>
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

        <!-- Support Platform -->
        <td class="col-support_platform editable"
            data-site-id="<?= $sid ?>" data-field="support_platform" data-fk-field="support_platform_id"
            data-type="fk" data-lookup="support_platforms"
            data-fk-id="<?= (int)$site['support_platform_id'] ?>"
            data-value="<?= h($site['support_platform']) ?>">
            <?= h($site['support_platform']) ?>
        </td>

        <!-- Support Intake URL -->
        <td class="col-support_intake_url">
            <?php if ($site['support_intake_url']): ?>
                <?php if (str_contains($site['support_intake_url'], '/')): ?>
                    <a class="link-icon" href="<?= h($site['support_intake_url']) ?>"
                       target="_blank" title="<?= h($site['support_intake_url']) ?>">🔗</a>
                <?php else: ?>
                    <a class="link-icon" href="mailto:<?= h($site['support_intake_url']) ?>"
                       title="<?= h($site['support_intake_url']) ?>">✉</a>
                <?php endif; ?>
            <?php else: ?>
                <button class="link-edit-btn" title="Set intake URL"
                        onclick="editLink(<?= $sid ?>, 'intake', '')">+</button>
            <?php endif; ?>
        </td>

        <!-- Datastudio URL -->
        <td class="col-datastudio_url">
            <?php if ($site['datastudio_url']): ?>
                <a class="link-icon" href="<?= h($site['datastudio_url']) ?>"
                   target="_blank" title="<?= h($site['datastudio_url']) ?>">📊</a>
            <?php else: ?>
                <button class="link-edit-btn" title="Set Datastudio URL"
                        onclick="editLink(<?= $sid ?>, 'datastudio', '')">+</button>
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
        <h2 id="link-modal-title" style="margin:0 0 12px;font-size:15px;font-weight:700"></h2>
        <input id="link-input" type="text" style="width:100%;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;margin-bottom:12px;outline:none"
               placeholder="https://… or email address">
        <div style="display:flex;gap:8px">
            <button onclick="saveLinkModal()" style="flex:1;padding:8px;background:#3b82f6;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600">Save</button>
            <button onclick="closeLinkModal()" style="flex:1;padding:8px;background:#f1f5f9;border:none;border-radius:8px;cursor:pointer;font-weight:600">Cancel</button>
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
    'support_platform','support_intake_url','datastudio_url',
    'server','platform','audience','category','second_category'];

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

function applyColVisibility() {
    ALL_TOGGLE_COLS.forEach(key => {
        const hide = hiddenCols.has(key);
        document.querySelectorAll('.col-' + key).forEach(el => el.style.display = hide ? 'none' : '');
    });
    // Sync checkboxes
    document.querySelectorAll('#col-panel input[data-col]').forEach(cb => {
        cb.checked = !hiddenCols.has(cb.dataset.col);
    });
}

window.addEventListener('DOMContentLoaded', () => {
    applyColVisibility();
    updateRowCount();
    // Close any open inline edit when the table scrolls (prevents stale dropdown position)
    document.getElementById('table-wrap').addEventListener('scroll', () => {
        if (activeCell) cancelEdit();
    });
});

// ── Column filters ─────────────────────────────────────────────────────────
// type:'text'   → substring match on data attr
// type:'set'    → data attr must be in selected Set of lowercase labels
// type:'people' → pipe-delimited emp IDs; row shown if any ID is in selected Set
const FILTER_COLS = {
    url:                  { type:'text' },
    site_name:            { type:'text' },
    description:          { type:'text' },
    vp_area:              { type:'set',    lookup:'vp_areas' },
    vp_lead:              { type:'people' },
    college_dept:         { type:'set',    lookup:'colleges_depts' },
    college_communicator: { type:'people' },
    site_owner:           { type:'people' },
    content_lead:         { type:'people' },
    tech_lead:            { type:'people' },
    admin_contact:        { type:'people' },
    support_platform:     { type:'set',    lookup:'support_platforms' },
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
            const v = (row.dataset[col] || '').toLowerCase();
            if (f.type === 'text' && !v.includes(f.value)) { show = false; break; }
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
        content.innerHTML =
            `<input type="text" id="filter-pop-text" placeholder="Filter…"
                    value="${escHtml(filterPopPending.value)}">`;
        const inp = document.getElementById('filter-pop-text');
        inp.focus(); inp.select();
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter')  applyFilterFromPop();
            if (e.key === 'Escape') closeFilter();
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
            onChange(val) {
                saveFkEdit(td, val, lookupKey, fkField);
            },
            onBlur() {
                setTimeout(() => { if (activeCell === td) cancelEdit(); }, 300);
            },
        });
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
            style="color:#0284c7;text-decoration:none"
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

function editLink(siteId, linkType, currentUrl) {
    linkState = { siteId, linkType };
    document.getElementById('link-modal-title').textContent =
        linkType === 'intake' ? 'Support Intake URL' : 'Datastudio URL';
    document.getElementById('link-input').value = currentUrl || '';
    const ov = document.getElementById('link-overlay');
    ov.style.display = 'flex';
    setTimeout(() => document.getElementById('link-input').focus(), 50);
}

function closeLinkModal() {
    document.getElementById('link-overlay').style.display = 'none';
    linkState = {};
}

async function saveLinkModal() {
    const url = document.getElementById('link-input').value.trim();
    const { siteId, linkType } = linkState;
    const res = await api({ action: 'update_link', site_id: siteId, link_type: linkType, url });
    if (res.success) location.reload(); // simplest refresh for link cells
    closeLinkModal();
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

    // Get site name from row
    const row      = cell.closest('tr');
    const nameCell = row.querySelector('td.col-site_name');
    const siteName = nameCell ? nameCell.dataset.value || nameCell.textContent.trim() : '';
    const urlCell  = row.querySelector('td.col-url');
    const siteUrl  = urlCell ? urlCell.dataset.value || '' : '';

    document.getElementById('modal-title').textContent    = ROLE_LABELS[role] || role;
    document.getElementById('modal-subtitle').textContent = siteName || siteUrl;

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

    const row      = cell.closest('tr');
    const urlCell  = row.querySelector('td.col-url');
    const nameCell = row.querySelector('td.col-site_name');
    document.getElementById('modal-title').textContent    = 'VP Lead';
    document.getElementById('modal-subtitle').textContent =
        (nameCell?.dataset.value || urlCell?.dataset.value || '');

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

// ── Add new site ───────────────────────────────────────────────────────────
async function addSite() {
    const url = prompt('Enter the URL for the new site (e.g. newsite.utsa.edu):');
    if (!url || !url.trim()) return;
    const res = await api({ action: 'add_site', url: url.trim() });
    if (res.success) location.reload();
    else alert('Error: ' + (res.error || 'Unknown error'));
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
    const palette = ['#3B82F6','#10B981','#8B5CF6','#EF4444','#F59E0B',
                     '#EC4899','#6366F1','#14B8A6','#F97316','#06B6D4'];
    let h = 0;
    for (const c of String(name||'')) h = (h * 31 + c.charCodeAt(0)) & 0x7fffffff;
    return palette[h % palette.length];
}
</script>
</body>
</html>
