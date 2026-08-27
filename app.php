<?php
session_start();
require_once 'config.php';

if (empty($_SESSION['auth'])) {
    header('Location: index.php');
    exit;
}

try {
    $pdo = new PDO(
        'sqlsrv:Server=' . DB_HOST . ';Database=' . DB_NAME,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die('Database connection failed.');
}

// ── Ensure dubbot_stats table exists ─────────────────────────────────────
$pdo->exec("
    IF OBJECT_ID('dubbot_stats','U') IS NULL
    CREATE TABLE dubbot_stats (
        site_id         INT PRIMARY KEY,
        score           DECIMAL(6,2),
        accessibility   DECIMAL(6,2),
        best_practices  DECIMAL(6,2),
        web_governance  DECIMAL(6,2),
        seo             DECIMAL(6,2),
        bad_links       DECIMAL(6,2),
        spelling        DECIMAL(6,2),
        pages_count     INT,
        updated_at      DATETIME2 DEFAULT SYSUTCDATETIME()
    )
");

// ── Ensure employees.dubbot_enrolled tracking columns exist ──────────────
// NULL = never checked; 0 = checked, not a DubBot user; 1 = checked, enrolled.
$pdo->exec("
    IF COL_LENGTH('employees','dubbot_enrolled') IS NULL
        ALTER TABLE employees ADD dubbot_enrolled BIT NULL, dubbot_checked_at DATETIME2 NULL
");

// ── Fetch all sites with joined display values ────────────────────────────
$sites = $pdo->query("
    SELECT s.id, s.url, s.site_name, s.description, s.is_active,
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
           e.id AS emp_id, e.first_name, e.last_name, e.email, e.dubbot_enrolled
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
    return $pdo->query("SELECT id, [$labelField] AS label FROM [$table] ORDER BY [$labelField]")->fetchAll();
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

// Human-readable fallback label derived from the raw column key, so the
// icon-only filter/sort buttons get a real accessible name instead of a
// bare, identical-everywhere "Filter"/"Sort" (WCAG 4.1.2 Name, Role, Value —
// a screen reader landing on 20 buttons all named "Filter" can't tell them
// apart without opening each one).
function columnLabel(string $col): string {
    $label = ucwords(str_replace(['_', '-'], ' ', $col));
    $label = preg_replace('/^Db /', 'DubBot ', $label);
    return preg_replace('/^Vp /', 'VP ', $label);
}

// Accessible name for a role/VP-lead cell — these are keyboard-activatable
// (role="button") but have no visible text label of their own beyond the
// person badges (which are themselves aria-hidden, see renderBadges() —
// this cell-level label is what actually carries the information now), so
// this needs to include not just who's assigned but, for the two roles that
// get the DubBot-enrollment check, the same "not enrolled" flag the visual
// marker conveys — otherwise that information exists for sighted users only.
function roleCellAriaLabel(string $roleLabel, array $people, bool $checkDubbot = false): string {
    if (!$people) return h($roleLabel . ': none assigned — activate to edit');
    $parts = [];
    foreach ($people as $p) {
        $name    = trim($p['first_name'] . ' ' . $p['last_name']);
        $missing = $checkDubbot && array_key_exists('dubbot_enrolled', $p)
                   && $p['dubbot_enrolled'] !== null && (int)$p['dubbot_enrolled'] === 0;
        $parts[] = $name . ($missing ? ' (not enrolled in DubBot)' : '');
    }
    return h($roleLabel . ': ' . implode(', ', $parts) . ' — activate to edit');
}

function filterBtn(string $col, ?string $label = null): string {
    $label = h($label ?? columnLabel($col));
    return '<button class="filter-btn" data-col="' . $col . '" aria-pressed="false"'
         . ' aria-label="Filter ' . $label . '" onclick="openFilter(event,\'' . $col . '\')" title="Filter">'
         . '<svg width="9" height="9" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
         . '<path d="M10 18h4v-2h-4v2zM3 6v2h18V6H3zm3 7h12v-2H6v2z"/>'
         . '</svg></button>';
}

function sortBtn(string $col, ?string $label = null): string {
    $label = h($label ?? columnLabel($col));
    return '<button class="sort-btn" data-col="' . $col . '" aria-label="Sort by ' . $label . '"'
         . ' onclick="toggleSort(event,\'' . $col . '\')" title="Sort">'
         . '<svg width="9" height="9" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">'
         . '<path class="sort-up" d="M12 2L20 13H4Z"/>'
         . '<path class="sort-dn" d="M12 22L4 11H20Z"/>'
         . '</svg></button>';
}

// Not-enrolled-in-DubBot marker: DubBot's own "Dashboards" nav icon, rendered
// small as a solid silhouette (the original's fine gear/ribbon detail doesn't
// survive shrinking, so a flat fill reads better than trying to keep its
// original multi-color styling). The empty `clip-path` DubBot's markup used
// is dropped — it only clipped to the icon's own full canvas, so it did
// nothing worth keeping. `aria-hidden`: purely decorative, the actual text
// equivalent is the " · Not enrolled in DubBot" appended to the tooltip below.
function dubbotMissingMarkerSvg(): string {
    return '<svg class="db-missing-icon" viewBox="0 0 114 114" aria-hidden="true" focusable="false" fill="#B91C1C">'
         . '<path d="M74.45 98.9l.2-.3.2-.3c.2-.4.5-1.1.7-1.9.2-.7.4-1.5.6-1.9l5.8-23.1 5.8-23.1c.1-.3 0-.5-.2-.7-.2-.2-.4-.3-.7-.3h-6.8l-.2.1-11 3.1-6.7 1.9c-3.1.9-6.5.9-9.6 0l-6.7-1.9-11-3.1c-.1 0-.1-.1-.2-.1h-6.6c-.3 0-.5.1-.7.3-.2.2-.2.5-.2.7l5.7 23.1 5.7 23.1c.2.4.4 1.2.6 1.9.2.7.4 1.4.7 1.8l.2.3.2.3-1.7 2.1-1.7 2.1c-.1.1-.1.2-.1.3 0 .1 0 .2.1.3l2.5 2.4 2.5 2.4c.1.1.2.1.3.1.1 0 .2 0 .3-.1l2.2-1.7 2.2-1.7.3.2.3.2c.8.4 1.6.8 2.4 1.1.8.3 1.7.6 2.5.8l.3.1.3.1.3 2.7.3 2.7c0 .1.1.2.2.3.1.1.2.1.3.1h7c.1 0 .2 0 .3-.1.1-.1.1-.2.2-.3l.3-2.4.3-2.4c0-.2.1-.3.2-.5.1-.1.3-.2.5-.3.9-.2 1.7-.5 2.5-.8.8-.3 1.6-.7 2.4-1.1h.2c.2-.1.3-.1.5-.1s.3.1.5.2l1.9 1.5 1.9 1.5c.1.1.2.1.3.1.1 0 .2 0 .3-.1l2.2-2.1 2.2-2.1c.2-.2.4-.5.4-.8 0-.3-.1-.6-.3-.9l-1.5-1.8-1.9-1.9zm-9.4-9.1c0 .1 0 .2-.1.3-.1.1-.2.1-.3.2l-.7.1-.7.1c-.1.3-.2.6-.3.8-.1.3-.2.5-.4.8l.5.6.5.6c.1.1.1.2.1.3 0 .1-.1.2-.1.3l-.8.8-.8.8c-.1.1-.2.1-.3.1-.1 0-.2 0-.3-.1l-.5-.4-.6-.4c-.2.1-.5.3-.8.4-.3.1-.6.2-1 .2l-.1.7-.1.7c0 .1-.1.2-.2.3-.1.1-.2.1-.3.1h-2.2c-.1 0-.2 0-.3-.1-.1-.1-.1-.2-.2-.3l-.1-.7-.1-.7c-.3-.1-.6-.2-.8-.3-.3-.1-.5-.2-.8-.4l-.6.5-.6.5c-.1.1-.2.1-.3.1-.1 0-.2-.1-.3-.1l-.8-.8-.8-.8c-.1-.1-.1-.2-.1-.3 0-.1 0-.2.1-.3l.5-.6.5-.6c-.1-.2-.3-.5-.4-.8-.1-.3-.2-.5-.3-.8l-.7-.1-.7-.1c-.1 0-.2-.1-.3-.2-.1-.1-.1-.2-.1-.3v-1.4h1.6l3.1-15 3.1-15c.2-.3.5-.4.8-.4.3 0 .6.2.8.4l2.9 15 2.9 15h1.5v1.3zM101.75 95.7v-4.6c0-.2 0-.5-.1-.7v-.1c0-2-1.6-3.7-3.5-3.8.1-.3.1-.6.1-1 0-1.4-.7-2.6-1.8-3.2 1.1-.6 1.9-1.9 1.8-3.3 0-1.6-1-3-2.4-3.5 1.2-.6 2.1-1.9 2.1-3.4 0-1.4-.8-2.7-1.9-3.3 1-.7 1.6-1.8 1.6-3.1 0-1.4-.7-2.6-1.9-3.3 1.1-.6 1.9-1.9 1.9-3.3 0-2.1-1.6-3.7-3.6-3.7h-2.3c-2 0-3.6 1.7-3.6 3.7 0 1.4.7 2.6 1.8 3.3-1.1.7-1.9 1.9-1.9 3.3s.8 2.6 1.9 3.3c-1 .7-1.6 1.8-1.6 3.1 0 1.6 1 3 2.4 3.5-1.2.6-2.1 1.9-2.1 3.4 0 1.4.7 2.6 1.9 3.3-1.1.6-1.9 1.9-1.9 3.3 0 .3.1.6.1.9-2 0-3.6 1.7-3.6 3.8v5.4c0 1.7 1.3 3 2.9 3 1.6 0 2.9-1.3 2.9-3v-1.6h4.8v1.6c0 1.7 1.3 3 2.9 3.1 1.8-.1 3.1-1.4 3.1-3.1z"></path>'
         . '<circle cx="85.55" cy="60.5" r="7.2"></circle>'
         . '<path d="M12.35 95.7c0 1.7 1.3 3 2.9 3.1 1.6 0 2.9-1.4 2.9-3.1v-1.6h4.8v1.6c0 1.7 1.3 3 2.9 3 1.6 0 2.9-1.4 2.9-3v-5.4c0-2.1-1.6-3.8-3.6-3.8.1-.3.1-.6.1-.9 0-1.4-.7-2.6-1.9-3.3 1.1-.6 1.9-1.8 1.9-3.3 0-1.5-.8-2.8-2.1-3.4 1.4-.5 2.4-1.9 2.4-3.5 0-1.3-.6-2.4-1.6-3.1 1.1-.7 1.9-1.9 1.9-3.3s-.8-2.6-1.9-3.3c1.1-.6 1.8-1.9 1.8-3.3 0-2.1-1.6-3.8-3.6-3.7h-2.3c-2 0-3.6 1.7-3.6 3.7 0 1.4.8 2.6 1.9 3.3-1.1.6-1.8 1.9-1.9 3.3 0 1.3.6 2.4 1.6 3.1-1.1.6-1.9 1.9-1.9 3.3 0 1.5.9 2.8 2.1 3.4-1.4.5-2.4 1.9-2.4 3.5 0 1.4.8 2.6 1.8 3.3-1.1.6-1.9 1.8-1.8 3.2 0 .3 0 .7.1 1-1.9.1-3.5 1.7-3.5 3.8v.1c.1.2.1.4.1.7v4.6z"></path>'
         . '<circle cx="28.75" cy="60.5" r="7.2"></circle>'
         . '<path d="M73.05 29.4c0 1.4-.6 2.7-1.5 3.6-.9.9-2.2 1.5-3.6 1.5s-2.7-.6-3.6-1.5c-.9-.9-1.5-2.2-1.5-3.6s.6-2.7 1.5-3.6c.9-.9 2.2-1.5 3.6-1.5s2.7.6 3.6 1.5c1 1 1.5 2.2 1.5 3.6zM51.45 29.4c0 1.4-.6 2.7-1.5 3.6-.9.9-2.2 1.5-3.6 1.5s-2.7-.6-3.6-1.5c-.9-.9-1.5-2.2-1.5-3.6s.6-2.7 1.5-3.6c.9-.9 2.2-1.5 3.6-1.5s2.7.6 3.6 1.5c1 1 1.5 2.2 1.5 3.6z"></path>'
         . '<path d="M89.95 28.8l-3.1-.4-3.1-.4c-.3-1.2-.7-2.3-1.2-3.4s-1-2.2-1.6-3.2l1.9-2.4 1.9-2.4c.3-.4.4-.9.4-1.3 0-.5-.2-.9-.5-1.3l-3.2-3.2-3.1-3.2c-.3-.3-.8-.5-1.3-.6-.5-.1-.9.1-1.3.4l-2.4 2-2.4 1.9c-1-.6-2.1-1.1-3.2-1.6s-2.2-.8-3.4-1.1l-.4-3.1-.4-3.1c-.1-.5-.3-.9-.6-1.2-.4-.3-.8-.5-1.3-.5h-9c-.5 0-.9.2-1.3.5s-.6.7-.6 1.2l-.4 3.1-.4 3.1c-1.2.3-2.3.7-3.4 1.2s-2.2 1-3.2 1.6l-2.4-1.9-2.4-1.9c-.4-.3-.8-.4-1.3-.4s-.9.2-1.3.5l-3.2 3.2-3.2 3.2c-.3.3-.5.8-.6 1.3-.1.5.1.9.4 1.3l1.9 2.4 1.9 2.4c-.6 1-1.1 2.1-1.6 3.2s-.8 2.2-1.2 3.4l-3.1.4-3.1.4c-.5.1-.9.3-1.2.6-.3.3-.5.8-.5 1.3v8.9c0 .5.2.9.5 1.3.3.3.7.6 1.2.6l3.1.4 3.1.4 21.1 5.4c3.6.9 7.4.9 11 0l21.1-5.4 3.1-.4 3.1-.4c.5-.1.9-.3 1.2-.7.3-.3.5-.8.5-1.3v-8.9c0-.5-.2-.9-.5-1.3-.1-.3-.5-.6-1-.6zm-13.5 4.4c0 .1-.1.2-.2.3-.1.1-.2.1-.4.1l-.8-.1-.8-.1c-.2.3-.3.5-.6.8-.2.2-.4.5-.7.7l.3.8.3.8v.4c0 .1-.1.2-.2.3l-1 .4-1.1.6c-.1.1-.3.1-.4 0-.1 0-.2-.1-.3-.2l-.5-.7-.5-.7c-.3.1-.6.1-.9.2h-1l-.3.8-.3.8c-.1.1-.1.2-.3.3-.1.1-.2.1-.4 0l-1.1-.3-1.1-.3c-.1 0-.2-.1-.3-.2-.1-.1-.1-.2-.1-.4l.1-.8.1-.8c-.3-.2-.5-.4-.8-.6-.2-.2-.5-.4-.7-.7l-.8.3-.8.3h-.4c-.1 0-.2-.1-.3-.2l-.6-1.1-.6-1.1c-.1-.1-.1-.2-.1-.4 0-.1.1-.2.2-.3l.7-.5.7-.5c-.1-.3-.1-.6-.2-.9v-1l-.8-.3-.8-.3c-.1-.1-.2-.1-.3-.3-.1-.1-.1-.2 0-.4l.3-1.2.3-1.2c0-.1.1-.2.2-.3.1-.1.2-.1.4-.1l.8.1.8.1c.2-.3.4-.5.6-.8.2-.3.4-.5.7-.7l-.3-.8-.3-.8v-.4c0-.1.1-.2.2-.3l1.1-.6 1.1-.6c.1-.1.2-.1.4 0 .1 0 .2.1.3.2l.5.7.5.7c.3-.1.6-.1.9-.2h1l.3-.8.3-.8c0-.1.1-.2.3-.3.1-.1.3-.1.4 0l1.1.3 1.1.3c.1 0 .2.1.3.2.1.1.1.2.1.4l-.1.8-.1.8c.3.2.5.4.8.6.2.2.5.4.7.6l.8-.3.8-.3h.4c.1 0 .2.1.3.2l.6 1.1.6 1.1c.1.1.1.2 0 .4 0 .1-.1.2-.2.3l-.7.5-.5.9c.1.3.1.6.2.9v1l.8.3.8.3c.1 0 .2.1.3.3.1.1.1.2 0 .4l-.3 1.2-.5 1.1zm-21.2-6.8c.1.1.1.2 0 .4 0 .1-.1.2-.2.3l-.7.4-.7.5c.1.3.1.6.1 1v1l.8.4.8.4.3.3c0 .1.1.2 0 .4l-.4 1.1-.4 1.1c0 .1-.1.2-.2.3-.1.1-.2.1-.4.1l-.8-.4-.8-.2c-.2.3-.4.5-.6.8-.2.3-.4.5-.7.7l.3.8.3.8v.4c0 .1-.1.2-.2.3l-1.2.4-1.1.5c-.1.1-.2.1-.4 0-.1 0-.2-.1-.3-.2l-.4-.6-.4-.7c-.3.1-.6.1-1 .1h-1l-.4.8-.4.8c0 .1-.1.2-.3.2-.1 0-.3.1-.4 0l-1.1-.4-1-.4c-.1 0-.2-.1-.3-.2-.1-.1-.1-.2-.1-.4l.2-.8.2-.8c-.3-.2-.5-.4-.8-.6-.2-.2-.5-.4-.7-.7l-.8.3-.7.3h-.4c-.1 0-.2-.1-.3-.2l-.5-1.1-.5-1.1c-.1-.1-.1-.3 0-.4 0-.1.1-.2.2-.3l.7-.5.7-.5c-.1-.3-.1-.6-.1-1v-1l-.8-.4-.8-.4-.3-.3c0-.1-.1-.2 0-.4l.4-1.1.4-1.1c0-.1.1-.2.2-.3.1-.1.2-.1.4-.1l.8.2.8.2c.2-.3.4-.5.6-.8.2-.2.4-.5.7-.7l-.3-.8-.3-.8v-.4c0-.2.1-.2.3-.3l1.1-.5 1.1-.5c.1-.1.2-.1.4 0 .1 0 .2.1.3.2l.5.7.5.7c.3-.1.6-.1 1-.1h1l.4-.8.4-.8c.1-.1.2-.2.3-.2.1 0 .2-.1.4 0l.7.7 1.1.4c.1 0 .2.1.3.2.1.1.1.2.1.4l-.2.8-.2.8c.3.2.5.4.8.6.2.2.5.4.7.7l.8-.3.8-.3h.4c.1 0 .2.1.3.2l.5 1.1.5 1.1z"></path>'
         . '</svg>';
}

// $checkDubbot: when true, badges for people confirmed (via a prior DubBot
// refresh) NOT to have a DubBot account get a red ring. Only meaningful for
// roles that are actually expected to work inside DubBot (content/tech lead).
function renderBadges(array $people, bool $checkDubbot = false): string {
    if (!$people) return '<span class="empty-cell">—</span>';
    $out = '';
    foreach ($people as $p) {
        $ini     = initials((string)$p['first_name'], (string)$p['last_name']);
        $color   = badgeColor($p['last_name'] . $p['first_name']);
        $missing = $checkDubbot && array_key_exists('dubbot_enrolled', $p)
                   && $p['dubbot_enrolled'] !== null && (int)$p['dubbot_enrolled'] === 0;
        $tip     = h(trim($p['last_name'] . ', ' . $p['first_name'])
                   . ($p['email'] ? ' · ' . $p['email'] : '')
                   . ($missing ? ' · Not enrolled in DubBot' : ''));
        $cls     = 'badge' . ($missing ? ' db-missing' : '');
        $email   = h(strtolower((string)($p['email'] ?? '')));
        $empId   = (int)($p['emp_id'] ?? 0);
        $marker  = $missing ? dubbotMissingMarkerSvg() : '';
        // aria-hidden: the parent role-cell's own aria-label (see
        // roleCellAriaLabel()) already carries full names + enrollment
        // status — without this, a screen reader would additionally read
        // each badge's bare initials, which is redundant noise at best and
        // actively confusing (2-letter fragments) at worst.
        $out    .= "<span class=\"$cls\" style=\"background:$color\" data-tip=\"$tip\" aria-hidden=\"true\""
                 . " data-email=\"$email\" data-emp-id=\"$empId\">$ini$marker</span>";
    }
    return $out;
}

// Full "First Last" names for a list of people, semicolon-separated —
// used for export so files carry full names instead of badge initials.
function fullNames(array $people): string {
    $names = [];
    foreach ($people as $p) {
        $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
        if ($name !== '') $names[] = $name;
    }
    return implode('; ', $names);
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
    <link rel="icon" href="favicon.ico" type="image/x-icon">

    <!-- UTSA Brand Fonts (Arsenal = headline, Libre Franklin = body) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Arsenal:wght@400;700&family=Libre+Franklin:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">

    <!-- Tom Select -->
    <link  rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

    <!-- SheetJS (XLSX export) -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <style>
        /*
         * UTSA Brand Colors
         * Midnight       #032044   River Mist   #C8DCFF
         * UTSA Orange    #F15A22   Talavera Blue #265BF7
         * Access. Orange #D3430D   Mission Clay  #DBB485
         * Brass          #A06620   Limestone     #F8F4F1
         * Concrete       #EBE6E2   Smoke         #7A6A5A
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
        #btn-export { background:#1B3A6B; color:#fff; display:inline-flex; align-items:center; gap:5px; }
        #btn-export:hover { background:#254e8f; }
        #btn-logout { background:#dc2626; color:#fff; }
        #btn-logout:hover { background:#b91c1c; }
        #row-count { font-size:12px; color:#C8DCFF; }

        /* ── Export menu ─────────────────────────────────────────────── */
        #export-wrap { position:relative; }
        #export-menu { display:none; position:absolute; top:calc(100% + 6px); right:0;
                       background:#fff; border:1px solid #EBE6E2; border-radius:8px;
                       box-shadow:0 6px 20px rgba(3,32,68,.18); z-index:10000;
                       min-width:160px; padding:6px; flex-direction:column; gap:2px; }
        #export-menu.open { display:flex; }
        #export-menu button { background:none; border:none; text-align:left; padding:7px 10px;
                              border-radius:6px; font-size:12px; font-weight:600; color:#332F21;
                              cursor:pointer; font-family:'Libre Franklin', system-ui, sans-serif; }
        #export-menu button:hover { background:#F8F4F1; color:#032044; }

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

        /* ── Accessibility: skip link + focus visibility ─────────────── */
        .skip-link {
            position:absolute; left:8px; top:-40px; z-index:1000;
            background:#032044; color:#fff; padding:8px 14px; border-radius:0 0 6px 6px;
            font-size:13px; font-weight:600; text-decoration:none; transition:top .15s;
        }
        .skip-link:focus { top:0; }

        /* Many controls below remove the native outline for visual reasons
           (some via inline style="", which needs !important to override from
           here) — this restores a clearly visible replacement for keyboard
           users on every focusable element in the app, native or custom. */
        :focus-visible {
            outline:2px solid #265BF7 !important;
            outline-offset:2px;
        }
        /* Tight-fitting controls (inline filter/sort icon buttons, small
           inputs) get a smaller offset so the ring doesn't get clipped by a
           parent's overflow:hidden. */
        .filter-btn:focus-visible, .sort-btn:focus-visible { outline-offset:1px; }

        /* ── Column filter / sort buttons ────────────────────────────── */
        .filter-btn, .sort-btn { position:absolute; top:50%; transform:translateY(-50%);
                      display:inline-flex; align-items:center; background:none; border:none;
                      cursor:pointer; padding:2px; opacity:.35; color:inherit;
                      border-radius:3px; transition:opacity .15s, color .15s; }
        .filter-btn { right:4px; }
        .sort-btn   { right:18px; }
        .filter-btn:hover, .sort-btn:hover { opacity:.8; }
        .filter-btn.filter-active { opacity:1; color:#D3430D; }
        .sort-btn.sort-asc, .sort-btn.sort-desc { opacity:1; color:#265BF7; }
        .sort-btn .sort-up, .sort-btn .sort-dn { transition:opacity .15s; }
        .sort-btn.sort-asc  .sort-dn  { opacity:.2; }
        .sort-btn.sort-desc .sort-up  { opacity:.2; }

        /* DubBot score columns are sort-only (no filter button), so they
           don't need the padding/offset reserved for a second icon — push
           the sort icon to the true edge and reclaim the padding, since
           these columns are narrow enough that the default spacing crowds
           the header text. */
        thead tr.headers th.col-db-score,         thead tr.headers th.col-db-accessibility,
        thead tr.headers th.col-db-badlinks,      thead tr.headers th.col-db-seo,
        thead tr.headers th.col-db-spelling,      thead tr.headers th.col-db-bestpractices,
        thead tr.headers th.col-db-webgovernance, thead tr.headers th.col-db-pages {
            padding-right:20px;
        }
        .col-db-score .sort-btn,         .col-db-accessibility .sort-btn,
        .col-db-badlinks .sort-btn,      .col-db-seo .sort-btn,
        .col-db-spelling .sort-btn,      .col-db-bestpractices .sort-btn,
        .col-db-webgovernance .sort-btn, .col-db-pages .sort-btn {
            right:4px;
        }

        /* ── Filter popover ───────────────────────────────────────────── */
        #filter-popover { display:none; position:fixed; background:#fff;
                          border:1px solid #EBE6E2; border-radius:8px;
                          box-shadow:0 6px 20px rgba(3,32,68,.12); z-index:10000;
                          padding:10px; width:230px; max-height:360px;
                          flex-direction:column; }
        #filter-popover.open { display:flex; }
        #filter-pop-search, #filter-pop-text {
            width:100%; padding:5px 8px; border:1px solid #7A6A5A; border-radius:6px;
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
        #filter-pop-copy-row { display:flex; gap:6px; }
        #filter-pop-copy-btn, #filter-pop-copy-btn2 {
                               flex:1; padding:5px; border-radius:6px; border:none;
                               cursor:pointer; font-size:12px; font-weight:600;
                               background:#F8F4F1; color:#332F21; transition:background .15s, color .15s; }
        #filter-pop-copy-btn:hover, #filter-pop-copy-btn2:hover { background:#EBE6E2; }
        #filter-pop-copy-btn.copied, #filter-pop-copy-btn2.copied { background:#15803d !important; color:#fff; }
        #filter-pop-copy-btn.copy-failed, #filter-pop-copy-btn2.copy-failed { background:#B91C1C !important; color:#fff; }
        #btn-clear-filters { background:#D3430D; color:#fff; }

        /* ── Active / inactive status filter ─────────────────────────── */
        #status-filter { display:flex; border-radius:6px; overflow:hidden; border:1px solid rgba(255,255,255,.2); flex-shrink:0; }
        .status-filter-btn { background:transparent; color:rgba(255,255,255,.6); border:none; padding:4px 10px;
                             font-size:12px; font-weight:600; cursor:pointer; transition:background .15s, color .15s;
                             font-family:'Libre Franklin', system-ui, sans-serif; }
        .status-filter-btn:not(:last-child) { border-right:1px solid rgba(255,255,255,.2); }
        .status-filter-btn.active { background:rgba(255,255,255,.15); color:#fff; }
        .status-filter-btn:hover:not(.active) { background:rgba(255,255,255,.08); color:#fff; }

        /* ── Inactive row styling ─────────────────────────────────────── */
        tr.site-inactive td { opacity:.55; }
        tr.site-inactive td a { color:#6B9FD4; }
        .inactive-badge { display:inline-block; margin-left:6px; padding:1px 6px; font-size:10px;
                          font-weight:700; background:#FEF3C7; color:#92400E; border-radius:4px;
                          text-transform:uppercase; letter-spacing:.04em; vertical-align:middle; }

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
                              background:#F8F4F1; padding:6px 36px 6px 8px; white-space:nowrap;
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
        .col-url                { min-width:280px; max-width:280px; }
        td.col-url              { overflow:hidden; padding:4px 8px; }
        td.col-site             { position:relative; }
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
        td.editable:not(.editing):hover::after { content:'✎'; font-size:10px; color:#7A6A5A;
                                                position:absolute; right:4px; top:50%;
                                                transform:translateY(-50%); pointer-events:none; }

        /* Site column (combined URL + Site Name) */
        td.col-site { overflow:hidden; padding:4px 8px; }
        .site-inner { display:flex; align-items:center; gap:4px; overflow:hidden; }
        .site-inner a, .site-inner > span { flex:1; min-width:0; overflow:hidden;
            text-overflow:ellipsis; white-space:nowrap; text-decoration:none; }
        .site-inner a { color:#265BF7; }
        .site-inner > span.empty-cell { color:#7A6A5A; }
        .site-edit-btn { position:absolute; right:4px; top:50%; transform:translateY(-50%);
                         opacity:0; background:none; border:none;
                         cursor:pointer; color:#7A6A5A; font-size:11px;
                         padding:2px 3px; border-radius:3px; line-height:1;
                         transition:opacity .1s, color .1s; }
        td.col-site:hover .site-edit-btn,
        td.col-url:hover  .site-edit-btn { opacity:1; }
        .site-edit-btn:hover { color:#265BF7 !important; background:#EBE6E2; }

        /* Editing state */
        td.editing { padding:2px 4px; overflow:visible; }
        td.editing input[type=text] {
            width:100%; font-size:13px; border:2px solid #265BF7; border-radius:4px;
            padding:3px 6px; outline:none; background:#fff; }

        /* Badges */
        .badge { position:relative; display:inline-flex; align-items:center; justify-content:center;
                 width:26px; height:26px; border-radius:50%; color:#fff;
                 font-size:10px; font-weight:700; cursor:pointer;
                 margin:1px; transition:transform .1s; }
        .badge:hover { transform:scale(1.15); }
        /* Not-enrolled-in-DubBot marker: a "!" glyph, not color alone (WCAG 1.4.1) —
           the ring is a secondary reinforcement, the glyph is what actually carries
           the meaning so it still reads correctly for colorblind users/in grayscale. */
        .badge.db-missing { box-shadow:0 0 0 2px #fff, 0 0 0 4px #EF4444; }
        .db-missing-icon {
            position:absolute; top:-6px; right:-6px;
            width:15px; height:15px; border-radius:50%;
            background:#fff; border:1.5px solid #B91C1C; padding:1.5px;
            box-shadow:0 1px 2px rgba(0,0,0,.35);
        }
        .empty-cell { color:#7A6A5A; }

        /* Link cells */
        .link-cell { cursor:pointer; text-align:center; }
        .link-cell:hover { background:rgba(200,220,255,.35) !important; }
        .link-cell-icon { display:inline-flex; align-items:center; justify-content:center;
                          width:26px; height:26px; border-radius:6px; background:#C8DCFF;
                          font-size:14px; pointer-events:none; }
        .link-cell-add  { display:inline-flex; align-items:center; justify-content:center;
                          width:22px; height:22px; border-radius:6px; background:#EBE6E2;
                          color:#7A6A5A; font-size:16px; font-weight:300; pointer-events:none; }

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
        #modal-box .subtitle { color:#7A6A5A; font-size:12px; margin-bottom:16px; }
        .modal-person { display:flex; align-items:center; gap:8px; padding:6px 0;
                        border-bottom:1px solid #EBE6E2; }
        .modal-person:last-child { border:none; }
        .modal-person-info { flex:1; }
        .modal-person-info .name { font-weight:600; font-size:13px; }
        .modal-person-info .email { font-size:11px; color:#7A6A5A; }
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
        .emp-editable.emp-email { color:#7A6A5A; font-size:11px; }
        .emp-editable.emp-no-email { color:#7A6A5A; font-size:10px; }
        .emp-editable.emp-no-email::after { content:'+ email'; }
        .emp-field-input { font-size:13px; border:1px solid #265BF7; border-radius:3px;
                           padding:1px 5px; outline:none; min-width:80px; max-width:160px; }
        .emp-field-input[data-field="email"] { font-size:11px; color:#7A6A5A; max-width:200px; }

        /* New person form */
        .modal-new-toggle { margin-top:10px; text-align:center; }
        #btn-new-person-toggle { background:none; border:none; color:#265BF7; font-size:12px;
                                 cursor:pointer; padding:0; font-weight:600; }
        #btn-new-person-toggle:hover { text-decoration:underline; }
        #new-person-form { background:#F8F4F1; border:1px solid #EBE6E2; border-radius:8px;
                           padding:12px; margin-top:8px; }
        .new-person-fields { display:grid; grid-template-columns:1fr 1fr; gap:6px;
                             margin-bottom:10px; }
        .new-person-fields input { padding:5px 8px; border:1px solid #7A6A5A; border-radius:6px;
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

        /* ── Global search ────────────────────────────────────────────── */
        #global-search { width:220px; padding:5px 10px; border:none; border-radius:6px;
                         font-size:12px; font-family:'Libre Franklin', system-ui, sans-serif;
                         background:rgba(255,255,255,.15); color:#fff; outline:none;
                         transition:background .15s, box-shadow .15s; }
        #global-search::placeholder { color:rgba(255,255,255,.5); }
        #global-search:focus { background:rgba(255,255,255,.25);
                               box-shadow:0 0 0 2px rgba(200,220,255,.4); }
        #global-search::-webkit-search-cancel-button { cursor:pointer; }
    </style>
    <!-- Hide default-hidden columns before first paint to prevent flash.
         JS re-applies localStorage prefs on DOMContentLoaded. -->
    <style id="col-hide-defaults">.col-description,.col-site { display:none; }</style>
</head>
<body>

<a href="#table-wrap" class="skip-link">Skip to site table</a>

<!-- ── Cell tooltip ─────────────────────────────────────────────────────── -->
<div id="cell-tooltip"></div>

<!-- ── Top bar ──────────────────────────────────────────────────────────── -->
<div id="topbar">
    <img src="utsa-logo.svg" alt="UT San Antonio" height="20" style="flex-shrink:0">
    <h1>Website Governance Directory</h1>
    <input id="global-search" type="search" placeholder="Search all columns…" autocomplete="off" aria-label="Search all columns">
    <span id="row-count" role="status" aria-live="polite"></span>
    <div id="status-filter" role="group" aria-label="Filter sites by status">
        <button class="status-filter-btn" data-status="active"   aria-pressed="false" onclick="setStatusFilter('active')">Active</button>
        <button class="status-filter-btn" data-status="inactive" aria-pressed="false" onclick="setStatusFilter('inactive')">Inactive</button>
        <button class="status-filter-btn" data-status="all"      aria-pressed="false" onclick="setStatusFilter('all')">All</button>
    </div>
    <button id="btn-cols"         onclick="toggleColPanel()">Columns</button>
    <button id="btn-clear-filters" onclick="clearAllFilters()" style="display:none">✕ Filters</button>
    <button id="btn-add"          onclick="addSite()">+ Add Site</button>
    <div id="export-wrap">
        <button id="btn-export" onclick="toggleExportMenu(event)">Export ▾</button>
        <div id="export-menu">
            <button onclick="exportTable('csv')">Export as CSV</button>
            <button onclick="exportTable('xlsx')">Export as XLSX</button>
        </div>
    </div>
    <a href="logout.php"><button id="btn-logout">Sign Out</button></a>
</div>

<!-- ── Column visibility panel ──────────────────────────────────────────── -->
<?php
$colGroups = [
    'Website'        => ['url' => 'URL', 'site' => 'Site Name', 'description' => 'Description'],
    'Governance'     => ['vp_area'=>'VP Area','vp_lead'=>'VP Lead','college_dept'=>'College/Dept'],
    'People'         => ['college_communicator'=>'Communicator','site_owner'=>'Site Owner',
                         'content_lead'=>'Content Lead','tech_lead'=>'Tech Lead','admin_contact'=>'Admin Contact'],
    'Support'        => ['support_intake_url'=>'Intake URL'],
    'Technical'      => ['datastudio_url'=>'Datastudio','server'=>'Server','platform'=>'Platform'],
    'Classification' => ['audience'=>'Audience','category'=>'Category','second_category'=>'2nd Category'],
    'DubBot'         => ['db-score'=>'Score','db-accessibility'=>'Accessibility',
                         'db-badlinks'=>'Bad Links','db-seo'=>'SEO','db-spelling'=>'Spelling',
                         'db-bestpractices'=>'Best Practices','db-webgovernance'=>'Web Gov.',
                         'db-pages'=>'Pages'],
];
$toggleCols    = array_merge(...array_values(array_map('array_keys', $colGroups)));
$defaultHidden = ['site', 'description'];
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
<div id="table-wrap" tabindex="-1">
<table id="main-table">
<thead>
    <!-- Group headers -->
    <tr class="groups">
        <th scope="colgroup" colspan="1" class="grp-identity sticky-1 col-url">Website</th>
        <th scope="colgroup" colspan="1" class="grp-identity col-site">&#8203;</th>
        <th scope="colgroup" colspan="1" class="grp-identity col-description">&#8203;</th>
        <th scope="colgroup" colspan="3" class="grp-governance">Governance</th>
        <th scope="colgroup" colspan="5" class="grp-people">People</th>
        <th scope="colgroup" colspan="1" class="grp-support">Support</th>
        <th scope="colgroup" colspan="3" class="grp-technical">Technical</th>
        <th scope="colgroup" colspan="3" class="grp-classification">Classification</th>
        <th scope="colgroup" colspan="8" class="grp-dubbot" id="grp-dubbot">DubBot
            <span id="db-status-live" role="status" aria-live="polite"><?php if ($dbLastUpdated): ?><span class="db-hdr-status">Updated <?= h(date('M j, Y', strtotime($dbLastUpdated))) ?></span><?php endif; ?></span>
            <button class="db-refresh-btn" id="db-refresh-btn" onclick="loadDubBotData()" title="Refresh DubBot data from API">↻ Refresh</button>
        </th>
    </tr>
    <!-- Column headers -->
    <tr class="headers">
        <th scope="col" class="sticky-1 col-url">URL <?= sortBtn('url') ?><?= filterBtn('url') ?></th>
        <th scope="col" class="col-site">Site Name <?= sortBtn('site') ?><?= filterBtn('site') ?></th>
        <th scope="col" class="col-description">Description <?= sortBtn('description') ?><?= filterBtn('description') ?></th>
        <th scope="col" class="col-vp_area">VP Area <?= sortBtn('vp_area') ?><?= filterBtn('vp_area') ?></th>
        <th scope="col" class="col-vp_lead">VP Lead <?= sortBtn('vp_lead') ?><?= filterBtn('vp_lead') ?></th>
        <th scope="col" class="col-college_dept">College/Dept <?= sortBtn('college_dept') ?><?= filterBtn('college_dept') ?></th>
        <th scope="col" class="col-college_communicator">Communicator <?= sortBtn('college_communicator') ?><?= filterBtn('college_communicator') ?></th>
        <th scope="col" class="col-site_owner">Owner <?= sortBtn('site_owner') ?><?= filterBtn('site_owner') ?></th>
        <th scope="col" class="col-content_lead">Content Lead <?= sortBtn('content_lead') ?><?= filterBtn('content_lead') ?></th>
        <th scope="col" class="col-tech_lead">Tech Lead <?= sortBtn('tech_lead') ?><?= filterBtn('tech_lead') ?></th>
        <th scope="col" class="col-admin_contact">Admin Contact <?= sortBtn('admin_contact') ?><?= filterBtn('admin_contact') ?></th>
        <th scope="col" class="col-support_intake_url">Intake</th>
        <th scope="col" class="col-datastudio_url">Studio</th>
        <th scope="col" class="col-server">Server <?= sortBtn('server') ?><?= filterBtn('server') ?></th>
        <th scope="col" class="col-platform">Platform <?= sortBtn('platform') ?><?= filterBtn('platform') ?></th>
        <th scope="col" class="col-audience">Audience <?= sortBtn('audience') ?><?= filterBtn('audience') ?></th>
        <th scope="col" class="col-category">Category <?= sortBtn('category') ?><?= filterBtn('category') ?></th>
        <th scope="col" class="col-second_category">2nd Category <?= sortBtn('second_category') ?><?= filterBtn('second_category') ?></th>
        <th scope="col" class="col-db-score">Score <?= sortBtn('db-score') ?></th>
        <th scope="col" class="col-db-accessibility">Accessibility <?= sortBtn('db-accessibility') ?></th>
        <th scope="col" class="col-db-badlinks">Bad Links <?= sortBtn('db-badlinks') ?></th>
        <th scope="col" class="col-db-seo">SEO <?= sortBtn('db-seo') ?></th>
        <th scope="col" class="col-db-spelling">Spelling <?= sortBtn('db-spelling') ?></th>
        <th scope="col" class="col-db-bestpractices">Best Prac. <?= sortBtn('db-bestpractices') ?></th>
        <th scope="col" class="col-db-webgovernance">Web Gov. <?= sortBtn('db-webgovernance') ?></th>
        <th scope="col" class="col-db-pages">Pages <?= sortBtn('db-pages') ?></th>
    </tr>
</thead>
<tbody>
<?php foreach ($sites as $site):
    $sid      = $site['id'];
    $siteRoles= $rolesBySite[$sid] ?? [];
    $vpLeads  = $site['vp_area_id'] ? ($vpByArea[$site['vp_area_id']] ?? []) : [];

    // Aggregated search string for global search box
    $searchParts = [
        $site['url'] ?? '', $site['site_name'] ?? '', $site['description'] ?? '',
        $site['vp_area'] ?? '', $site['college_dept'] ?? '', $site['server'] ?? '',
        $site['platform'] ?? '', $site['audience'] ?? '',
        $site['category'] ?? '', $site['second_category'] ?? '',
    ];
    foreach ($vpLeads as $p) {
        $searchParts[] = ($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '');
        if (!empty($p['email'])) $searchParts[] = $p['email'];
    }
    foreach ($peopleRoles as $role) {
        foreach ($siteRoles[$role] ?? [] as $p) {
            $searchParts[] = ($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '');
            if (!empty($p['email'])) $searchParts[] = $p['email'];
        }
    }
    $searchStr = strtolower(implode(' ', array_filter($searchParts)));

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
        'data-is_active="'            . (int)($site['is_active'] ?? 1)                                                    . '"',
    ]);
    $isActive = (int)($site['is_active'] ?? 1);
?>
    <tr data-id="<?= $sid ?>" data-search="<?= h($searchStr) ?>" <?= $da ?><?= $isActive ? '' : ' class="site-inactive"' ?>>

        <!-- Site (combined URL + Site Name, sticky) -->
        <?php
        $display   = $site['site_name'] ?: $site['url'];
        $href      = $site['url'] ? 'https://' . h($site['url']) : '';
        $flags     = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT;
        $siteNameJ = json_encode((string)($site['site_name'] ?? ''), $flags);
        $urlJ      = json_encode((string)($site['url']       ?? ''), $flags);
        ?>
        <?php $tooltip = $display . ($site['url'] && $site['site_name'] ? "\n" . $site['url'] : ''); ?>
        <!-- URL (primary sticky column) -->
        <td class="sticky-1 col-url" data-value="<?= h($site['url']) ?>" title="<?= h($site['url']) ?>">
            <div class="site-inner">
                <?php if ($site['url']): ?>
                    <a href="https://<?= h($site['url']) ?>" target="_blank"
                       onclick="event.stopPropagation()"><?= h($site['url']) ?></a>
                <?php else: ?>
                    <span class="empty-cell">—</span>
                <?php endif; ?>
                <?php if (!$isActive): ?><span class="inactive-badge">Inactive</span><?php endif; ?>
            </div>
            <button class="site-edit-btn"
                    onclick="event.stopPropagation();openSiteEditModal(<?= $sid ?>,<?= h($siteNameJ) ?>,<?= h($urlJ) ?>,<?= $isActive ?>)">✎</button>
        </td>

        <!-- Site (name + edit button) -->
        <td class="col-site" data-site-id="<?= $sid ?>" data-value="<?= h($display) ?>"
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
                    onclick="event.stopPropagation();openSiteEditModal(<?= $sid ?>,<?= h($siteNameJ) ?>,<?= h($urlJ) ?>,<?= $isActive ?>)">✎</button>
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
            data-names="<?= h(fullNames($vpLeads)) ?>"
            tabindex="0" role="button" aria-label="<?= roleCellAriaLabel('VP Lead', $vpLeads) ?>"
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
            data-names="<?= h(fullNames($siteRoles[$role] ?? [])) ?>"
            tabindex="0" role="button" aria-label="<?= roleCellAriaLabel(columnLabel($role), $siteRoles[$role] ?? [], in_array($role, ['content_lead', 'tech_lead'], true)) ?>"
            onclick="openPeopleModal(<?= $sid ?>, '<?= $role ?>', this)">
            <?= renderBadges($siteRoles[$role] ?? [], in_array($role, ['content_lead', 'tech_lead'], true)) ?>
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
            tabindex="0" role="button"
            aria-label="<?= $intakeUrl ? h('Support intake URL: ' . $intakeUrl . ($spName ? ' · ' . $spName : '') . ' — activate to edit') : 'Set support intake URL' ?>"
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
            data-url="<?= h($dsUrl) ?>"
            tabindex="0" role="button"
            aria-label="<?= $dsUrl ? h('Datastudio URL: ' . $dsUrl . ' — activate to edit') : 'Set Datastudio URL' ?>"
            onclick="editLink(<?= $sid ?>, 'datastudio', <?= h($dsJ) ?>)"
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
        <td class="col-db-pages"         data-db-col="pages"
            data-value="<?= $site['db_pages_count'] !== null ? (int)$site['db_pages_count'] : '' ?>"><?php
            if ($dbHas && $site['db_pages_count'] !== null)
                echo number_format((int)$site['db_pages_count']); ?></td>

    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- ── People Modal ──────────────────────────────────────────────────────── -->
<div id="modal-overlay" onclick="if(event.target===this) closePeopleModal()">
    <div id="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-title" tabindex="-1">
        <h2 id="modal-title"></h2>
        <div class="subtitle" id="modal-subtitle"></div>
        <div id="modal-people-list"></div>
        <div class="modal-add">
            <label for="employee-ts">Add Person</label>
            <select id="employee-ts" placeholder="Search by name or email…" aria-label="Add person by name or email"></select>
        </div>
        <div class="modal-new-toggle">
            <button id="btn-new-person-toggle" onclick="toggleNewPersonForm()">+ Not in system?</button>
        </div>
        <div id="new-person-form" style="display:none">
            <div class="new-person-fields">
                <input type="text" id="np-first" placeholder="First name" aria-label="First name">
                <input type="text" id="np-last"  placeholder="Last name" aria-label="Last name">
                <input type="text" id="np-email" placeholder="Email" class="full-width" aria-label="Email">
            </div>
            <button id="btn-create-emp" onclick="createAndAddEmployee()">Add to Role</button>
        </div>
        <button id="modal-close" onclick="closePeopleModal()">Close</button>
    </div>
</div>

<!-- ── Link Edit Modal ───────────────────────────────────────────────────── -->
<div id="link-overlay" onclick="if(event.target===this)closeLinkModal()"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center">
    <div role="dialog" aria-modal="true" aria-labelledby="link-modal-title" style="background:#fff;border-radius:12px;padding:24px;width:500px;box-shadow:0 20px 60px rgba(0,0,0,.25)">
        <h2 id="link-modal-title" style="margin:0 0 4px;font-size:15px;font-weight:700;color:#032044"></h2>
        <p id="link-modal-site" style="margin:0 0 14px;font-size:12px;color:#7A6A5A"></p>
        <div id="link-current-wrap" style="display:none;margin-bottom:14px;padding:10px 12px;background:#F8F4F1;border-radius:8px;border:1px solid #EBE6E2">
            <div style="font-size:11px;font-weight:600;color:#7A6A5A;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Current URL</div>
            <a id="link-current-url" href="#" target="_blank"
               style="font-size:12px;color:#265BF7;word-break:break-all;text-decoration:none;">
            </a>
        </div>
        <div id="link-platform-wrap" style="display:none;margin-bottom:14px">
            <label for="link-platform-select" style="font-size:12px;font-weight:600;color:#332F21;display:block;margin-bottom:4px">Support Platform</label>
            <select id="link-platform-select" style="width:100%;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;outline:none;background:#fff;margin-bottom:5px">
                <option value="">— none —</option>
                <?php foreach ($lookups['support_platforms'] as $sp): ?>
                <option value="<?= $sp['id'] ?>"><?= h($sp['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <div id="link-platform-add-row" style="display:flex;gap:6px;align-items:center">
                <input id="link-platform-new" type="text" placeholder="Add new platform…" aria-label="Add new support platform"
                       style="flex:1;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;padding:4px 8px;outline:none">
                <button onclick="addLinkPlatform()" style="padding:4px 10px;font-size:12px;font-weight:600;background:#265BF7;color:#fff;border:none;border-radius:6px;cursor:pointer;white-space:nowrap">+ Add</button>
            </div>
        </div>
        <label for="link-input" style="font-size:12px;font-weight:600;color:#332F21;display:block;margin-bottom:4px" id="link-input-label">New URL</label>
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
    <div role="dialog" aria-modal="true" aria-labelledby="add-site-title" style="background:#fff;border-radius:12px;padding:24px;width:480px;box-shadow:0 20px 60px rgba(3,32,68,.3)">
        <h2 id="add-site-title" style="font-family:'Arsenal',system-ui,sans-serif;margin:0 0 4px;font-size:17px;font-weight:700;color:#032044">Add New Site</h2>
        <p style="margin:0 0 16px;font-size:12px;color:#7A6A5A">Enter the domain without https:// (e.g. newsite.utsa.edu)</p>
        <input id="add-site-input" type="text" placeholder="newsite.utsa.edu" aria-label="Site domain, without https://"
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
    <div role="dialog" aria-modal="true" aria-labelledby="site-edit-title" style="background:#fff;border-radius:12px;padding:24px;width:480px;box-shadow:0 20px 60px rgba(3,32,68,.3)">
        <h2 id="site-edit-title" style="font-family:'Arsenal',system-ui,sans-serif;margin:0 0 16px;font-size:17px;font-weight:700;color:#032044">Edit Site</h2>

        <!-- Edit fields -->
        <div id="site-edit-fields">
            <label for="site-edit-name" style="display:block;font-size:11px;font-weight:600;color:#6B6355;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Site Name</label>
            <input id="site-edit-name" type="text" placeholder="My Site Name"
                   style="width:100%;font-size:13px;border:2px solid #265BF7;border-radius:6px;padding:7px 10px;margin-bottom:14px;outline:none;box-sizing:border-box">
            <label for="site-edit-url" style="display:block;font-size:11px;font-weight:600;color:#6B6355;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">URL <span style="font-weight:400;text-transform:none;color:#7A6A5A">(without https://)</span></label>
            <input id="site-edit-url" type="text" placeholder="site.utsa.edu"
                   style="width:100%;font-size:13px;border:1px solid #7A6A5A;border-radius:6px;padding:7px 10px;margin-bottom:14px;outline:none;box-sizing:border-box">
            <p id="site-edit-error" style="display:none;margin:0 0 10px;font-size:12px;color:#dc2626;font-weight:600"></p>
            <div style="display:flex;gap:8px">
                <button id="site-edit-save" onclick="saveSiteEditModal()"
                        style="flex:1;padding:8px;background:#D3430D;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">Save</button>
                <button onclick="closeSiteEditModal()"
                        style="flex:1;padding:8px;background:#EBE6E2;color:#332F21;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">Cancel</button>
            </div>
            <div style="margin-top:14px;padding-top:14px;border-top:1px solid #EBE6E2;display:flex;justify-content:space-between;align-items:center">
                <button id="site-deactivate-btn" onclick="showDeactivateConfirm()"
                        style="background:none;border:none;color:#92400E;font-size:12px;cursor:pointer;padding:0;font-weight:600;text-decoration:underline">
                    Deactivate this site…
                </button>
                <button onclick="showDeleteConfirm()"
                        style="background:none;border:none;color:#dc2626;font-size:12px;cursor:pointer;padding:0;font-weight:600;text-decoration:underline">
                    Delete this site…
                </button>
            </div>
        </div>

        <!-- Deactivate / Reactivate confirmation (hidden by default) -->
        <div id="site-deactivate-confirm" style="display:none">
            <div id="site-deactivate-warning" style="background:#fffbeb;border:2px solid #fcd34d;border-radius:8px;padding:14px;margin-bottom:16px">
                <p style="margin:0;font-size:12px;color:#78350f;line-height:1.5">
                    The site will be hidden from the default view but all data (roles, DubBot stats) will be preserved.
                    You can reactivate it at any time.
                </p>
            </div>
            <div id="site-reactivate-warning" style="display:none;background:#f0fdf4;border:2px solid #86efac;border-radius:8px;padding:14px;margin-bottom:16px">
                <p style="margin:0;font-size:12px;color:#14532d;line-height:1.5">
                    The site will be restored to the active view.
                </p>
            </div>
            <p style="margin:0 0 14px;font-size:13px;color:#332F21">
                <span id="site-deactivate-action-label">Deactivate</span> <strong id="site-deactivate-label"></strong>?
            </p>
            <div style="display:flex;gap:8px">
                <button id="site-deactivate-confirm-btn" onclick="confirmToggleSiteActive()"
                        style="flex:1;padding:8px;background:#D97706;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700">
                    Yes, deactivate
                </button>
                <button onclick="hideDeactivateConfirm()"
                        style="flex:1;padding:8px;background:#EBE6E2;color:#332F21;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">
                    Cancel
                </button>
            </div>
        </div>

        <!-- Delete confirmation (hidden by default) -->
        <div id="site-delete-confirm" style="display:none">
            <div style="background:#fef2f2;border:2px solid #fca5a5;border-radius:8px;padding:14px;margin-bottom:16px">
                <p style="margin:0 0 6px;font-size:13px;font-weight:700;color:#991b1b">⚠ This cannot be undone.</p>
                <p style="margin:0;font-size:12px;color:#7f1d1d;line-height:1.5">
                    Deleting this site will permanently remove the site record and all role
                    <em>assignments</em> tied to it (Owner, Tech Lead, etc.). The people themselves
                    are not affected and remain in the system. DubBot stats for this site will also
                    be removed.
                </p>
            </div>
            <p style="margin:0 0 14px;font-size:13px;color:#332F21">
                Are you sure you want to delete <strong id="site-delete-label"></strong>?
            </p>
            <div style="display:flex;gap:8px">
                <button onclick="confirmDeleteSite()"
                        style="flex:1;padding:8px;background:#dc2626;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700">
                    Yes, delete permanently
                </button>
                <button onclick="hideDeleteConfirm()"
                        style="flex:1;padding:8px;background:#EBE6E2;color:#332F21;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Column filter popover ────────────────────────────────────────────── -->
<div id="filter-popover" role="dialog" aria-label="Column filter options">
    <div id="filter-pop-content"></div>
    <div class="filter-pop-actions">
        <button class="btn-clear" onclick="clearFilterFromPop()">Clear</button>
        <button class="btn-apply" onclick="applyFilterFromPop()">Apply</button>
    </div>
    <div class="filter-pop-sep"></div>
    <div id="filter-pop-copy-row">
        <button id="filter-pop-copy-btn"  aria-live="polite" onclick="copyColumnData(filterPopCol, 'name')">Copy column</button>
        <button id="filter-pop-copy-btn2" aria-live="polite" onclick="copyColumnData(filterPopCol, 'url')" style="display:none">Copy URLs</button>
    </div>
</div>

<script>
// ── Data from PHP ──────────────────────────────────────────────────────────
const LOOKUPS        = <?= $lookupsJson ?>;
const EMPLOYEES      = <?= $employeesJson ?>;
const PEOPLE_OPTIONS = <?= $filterPeopleJson ?>;
// Same markup renderBadges() emits server-side, so the live post-refresh
// toggle in checkDubbotEnrollment() below stays in sync with a fresh page load.
const DB_MISSING_ICON_SVG = <?= json_encode(dubbotMissingMarkerSvg(), JSON_HEX_TAG | JSON_HEX_APOS) ?>;

// ── Column visibility ──────────────────────────────────────────────────────
const ALL_TOGGLE_COLS = ['url','site','description','vp_area','vp_lead','college_dept',
    'college_communicator','site_owner','content_lead','tech_lead','admin_contact',
    'support_intake_url','datastudio_url',
    'server','platform','audience','category','second_category',
    'db-score','db-accessibility','db-badlinks','db-seo',
    'db-spelling','db-bestpractices','db-webgovernance','db-pages'];

const DEFAULT_HIDDEN  = ['site', 'description'];
const COLS_VERSION    = '2'; // bump whenever DEFAULT_HIDDEN changes
const storedCols      = localStorage.getItem('colsVersion') === COLS_VERSION
                          ? localStorage.getItem('hiddenCols') : null;
const hiddenCols      = new Set(storedCols !== null ? JSON.parse(storedCols) : DEFAULT_HIDDEN);

function toggleColPanel() {
    document.getElementById('col-panel').classList.toggle('open');
}

function toggleCol(key, visible) {
    if (visible) hiddenCols.delete(key);
    else         hiddenCols.add(key);
    localStorage.setItem('hiddenCols', JSON.stringify([...hiddenCols]));
    localStorage.setItem('colsVersion', COLS_VERSION);
    applyColVisibility();
}

function toggleGroup(groupKey, visible) {
    document.querySelectorAll(`#col-panel input[data-col][data-group="${groupKey}"]`).forEach(cb => {
        if (visible) hiddenCols.delete(cb.dataset.col);
        else         hiddenCols.add(cb.dataset.col);
    });
    localStorage.setItem('hiddenCols', JSON.stringify([...hiddenCols]));
    localStorage.setItem('colsVersion', COLS_VERSION);
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
        // Website group header is col-url th (already handled by col class above)
        if (grpKey !== 'website') {
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

// ── Export (CSV / XLSX) ──────────────────────────────────────────────────
// Exports exactly what's currently on screen: visible columns (per the
// Columns panel), in their displayed order, for rows passing the active
// status filter / search / column filters. People columns export full
// names instead of the badge initials shown in the UI.
const COLUMN_LABELS = {
    url: 'URL', site: 'Site Name', description: 'Description',
    vp_area: 'VP Area', vp_lead: 'VP Lead', college_dept: 'College/Dept',
    college_communicator: 'Communicator', site_owner: 'Site Owner',
    content_lead: 'Content Lead', tech_lead: 'Tech Lead', admin_contact: 'Admin Contact',
    support_intake_url: 'Support Intake URL', datastudio_url: 'Datastudio URL',
    server: 'Server', platform: 'Platform', audience: 'Audience',
    category: 'Category', second_category: '2nd Category',
    'db-score': 'DubBot Score', 'db-accessibility': 'DubBot Accessibility',
    'db-badlinks': 'DubBot Bad Links', 'db-seo': 'DubBot SEO',
    'db-spelling': 'DubBot Spelling', 'db-bestpractices': 'DubBot Best Practices',
    'db-webgovernance': 'DubBot Web Governance', 'db-pages': 'DubBot Pages',
};
const PEOPLE_EXPORT_COLS = new Set(['vp_lead','college_communicator','site_owner','content_lead','tech_lead','admin_contact']);

function extractCellValue(key, td) {
    if (!td) return '';
    if (PEOPLE_EXPORT_COLS.has(key)) return td.dataset.names || '';
    if (key === 'support_intake_url' || key === 'datastudio_url') return td.dataset.url || '';
    if (td.dataset.value !== undefined) return td.dataset.value;
    if (key.startsWith('db-')) return td.dataset.dbSaved !== undefined ? td.dataset.dbSaved : '';
    return (td.textContent || '').trim();
}

function buildExportTable() {
    const cols    = ALL_TOGGLE_COLS.filter(k => !hiddenCols.has(k));
    const headers = cols.map(k => COLUMN_LABELS[k] || k);
    const rows    = [...document.querySelectorAll('#main-table tbody tr[data-id]')]
        .filter(row => row.style.display !== 'none')
        .map(row => cols.map(k => extractCellValue(k, row.querySelector('.col-' + k))));
    return { headers, rows };
}

function exportFilename(ext) {
    const stamp = new Date().toISOString().slice(0, 10);
    return `website-governance-${stamp}.${ext}`;
}

function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a   = document.createElement('a');
    a.href = url; a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function csvEscape(v) {
    v = String(v ?? '');
    return /[",\r\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
}

function exportTable(format) {
    const { headers, rows } = buildExportTable();
    if (format === 'csv') {
        const lines = [headers, ...rows].map(r => r.map(csvEscape).join(','));
        downloadBlob(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' }),
                     exportFilename('csv'));
    } else {
        const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Website Governance');
        XLSX.writeFile(wb, exportFilename('xlsx'));
    }
    closeExportMenu();
}

function toggleExportMenu(e) {
    e.stopPropagation();
    document.getElementById('export-menu').classList.toggle('open');
}
function closeExportMenu() {
    document.getElementById('export-menu').classList.remove('open');
}
document.addEventListener('click', e => {
    const wrap = document.getElementById('export-wrap');
    if (wrap && !wrap.contains(e.target)) closeExportMenu();
});

window.addEventListener('DOMContentLoaded', () => {
    applyColVisibility();
    initStatusFilter(); // now applies the filter too, and updates the row count itself
    document.querySelectorAll('#main-table tbody tr[data-id]').forEach((r, i) => r.dataset.origIndex = i);
    document.getElementById('global-search').addEventListener('input', e => {
        searchQuery = e.target.value.toLowerCase().trim();
        applyFilters();
    });
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
    url:                  { type:'text' },
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
let searchQuery      = '';  // global search box
let filterPopCol     = null;
let filterPopPending = null;

// ── Active / inactive status filter ───────────────────────────────────────
let activeStatusFilter = localStorage.getItem('statusFilter') || 'active';

function setStatusFilter(val) {
    activeStatusFilter = val;
    localStorage.setItem('statusFilter', val);
    document.querySelectorAll('.status-filter-btn').forEach(btn => {
        const on = btn.dataset.status === val;
        btn.classList.toggle('active', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    applyFilters();
}

// Initialise button state on load (called after DOMContentLoaded)
function initStatusFilter() {
    document.querySelectorAll('.status-filter-btn').forEach(btn => {
        const on = btn.dataset.status === activeStatusFilter;
        btn.classList.toggle('active', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    // Unlike setStatusFilter(), this used to only update the button's look —
    // it never actually hid inactive rows, so "Active" appeared selected on
    // load while inactive sites stayed visible until some other filter action
    // happened to trigger applyFilters() (e.g. clicking All then Active).
    applyFilters();
}

function applyFilters() {
    let visible = 0;
    document.querySelectorAll('#main-table tbody tr[data-id]').forEach(row => {
        let show = true;
        const isActive = row.dataset.is_active === '1';
        if (activeStatusFilter === 'active'   && !isActive) show = false;
        if (activeStatusFilter === 'inactive' &&  isActive) show = false;
        if (searchQuery && !(row.dataset.search || '').includes(searchQuery)) show = false;
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
        (Object.keys(activeFilters).length || searchQuery) ? '' : 'none';
}

function clearAllFilters() {
    for (const col of Object.keys(activeFilters)) {
        delete activeFilters[col];
        markFilterBtn(col, false);
    }
    searchQuery = '';
    document.getElementById('global-search').value = '';
    applyFilters();
}

// ── Sorting ────────────────────────────────────────────────────────────────
let sortCol = null;
let sortDir = null; // 'asc' | 'desc'

// DubBot score/pages columns: sort col name -> the data-db-col value used on
// the <td> (see dbFillRow()/PHP row markup). Numeric, so they need their own
// comparator below rather than the generic localeCompare path — string-
// sorting "100.0" vs "9.5" would put 100 before 9, which is wrong.
const DB_SCORE_COLS = {
    'db-score': 'score', 'db-accessibility': 'accessibility', 'db-badlinks': 'badLinks',
    'db-seo': 'seo', 'db-spelling': 'spelling', 'db-bestpractices': 'bestPractices',
    'db-webgovernance': 'webGovernance', 'db-pages': 'pages',
};

function getSortValue(row, col) {
    if (col === 'site') return row.dataset.site_name || row.dataset.url || '';
    if (PEOPLE_COLS.has(col)) {
        const td = row.querySelector('td.col-' + col);
        if (!td) return '';
        const first = td.querySelector('.badge[data-tip]');
        return first ? first.dataset.tip.split('·')[0].trim().toLowerCase() : '';
    }
    if (DB_SCORE_COLS[col]) {
        const td  = row.querySelector(`td[data-db-col="${DB_SCORE_COLS[col]}"]`);
        const raw = td ? (DB_SCORE_COLS[col] === 'pages' ? td.dataset.value : td.dataset.dbSaved) : undefined;
        return (raw !== undefined && raw !== '') ? parseFloat(raw) : null;
    }
    return (row.dataset[col] || '').toLowerCase();
}

function toggleSort(event, col) {
    event.stopPropagation();
    if (sortCol === col) {
        sortDir = sortDir === 'asc' ? 'desc' : null;
        if (!sortDir) sortCol = null;
    } else {
        sortCol = col; sortDir = 'asc';
    }
    applySort();
    updateSortBtns();
}

function applySort() {
    const tbody = document.querySelector('#main-table tbody');
    const rows  = [...tbody.querySelectorAll('tr[data-id]')];
    if (!sortCol) {
        rows.sort((a, b) => +a.dataset.origIndex - +b.dataset.origIndex);
    } else if (DB_SCORE_COLS[sortCol]) {
        rows.sort((a, b) => {
            const av = getSortValue(a, sortCol);
            const bv = getSortValue(b, sortCol);
            // Distinguish "no data yet" (null) from a genuine 0 — a falsy
            // check here would wrongly bury a real zero score/page-count.
            const aMissing = av === null, bMissing = bv === null;
            if (aMissing && !bMissing) return 1;
            if (!aMissing && bMissing) return -1;
            if (aMissing && bMissing)  return 0;
            return sortDir === 'asc' ? av - bv : bv - av;
        });
    } else {
        rows.sort((a, b) => {
            const av = getSortValue(a, sortCol);
            const bv = getSortValue(b, sortCol);
            if (!av && bv)  return 1;   // blanks always last
            if (av  && !bv) return -1;
            if (!av && !bv) return 0;
            const cmp = av.localeCompare(bv, undefined, { sensitivity:'base' });
            return sortDir === 'asc' ? cmp : -cmp;
        });
    }
    rows.forEach(r => tbody.appendChild(r));
}

function updateSortBtns() {
    document.querySelectorAll('.sort-btn').forEach(btn => {
        const isAsc  = btn.dataset.col === sortCol && sortDir === 'asc';
        const isDesc = btn.dataset.col === sortCol && sortDir === 'desc';
        btn.classList.toggle('sort-asc',  isAsc);
        btn.classList.toggle('sort-desc', isDesc);
        // aria-sort belongs on the <th> itself, not the button inside it —
        // that's the standard way a screen reader is told a column's sort
        // state (WCAG 4.1.2 / WAI-ARIA sortable-table pattern).
        const th = btn.closest('th');
        if (th) th.setAttribute('aria-sort', isAsc ? 'ascending' : isDesc ? 'descending' : 'none');
    });
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

    const visCount = [...document.querySelectorAll('#main-table tbody tr[data-id]')]
        .filter(r => r.style.display !== 'none').length;
    const n = visCount, s = n !== 1 ? 's' : '';
    const copyBtn  = document.getElementById('filter-pop-copy-btn');
    const copyBtn2 = document.getElementById('filter-pop-copy-btn2');
    copyBtn.classList.remove('copied');
    copyBtn2.classList.remove('copied');
    if (col === 'site') {
        copyBtn.textContent  = `Copy ${n} name${s}`;
        copyBtn2.textContent = `Copy ${n} URL${s}`;
        copyBtn2.style.display = '';
    } else {
        copyBtn.textContent    = `Copy ${n} row${s}`;
        copyBtn2.style.display = 'none';
    }

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
        const hint        = col === 'site' ? '<div style="font-size:10px;color:#7A6A5A;margin-bottom:2px">Searches both site name and URL</div>' : '';
        content.innerHTML =
            `${hint}<input type="text" id="filter-pop-text" placeholder="${placeholder}" aria-label="${escHtml(placeholder)}"
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
            `<input type="text" id="filter-pop-search" placeholder="Search options…" aria-label="Search filter options"
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

const PEOPLE_COLS = new Set(['vp_lead','college_communicator','site_owner','content_lead','tech_lead','admin_contact']);

function copyColumnData(col, variant) {
    const rows = [...document.querySelectorAll('#main-table tbody tr[data-id]')]
        .filter(r => r.style.display !== 'none');

    const lines = rows.map(row => {
        const td = row.querySelector(`td.col-${col}`);
        if (!td) return '';

        if (col === 'url') {
            const a = td.querySelector('.site-inner a');
            return a ? a.href : '';
        }

        if (col === 'site') {
            const a = td.querySelector('.site-inner a');
            if (variant === 'url') {
                return a ? a.href : '';
            } else {
                if (a) return a.textContent.trim();
                const span = td.querySelector('.site-inner > span:not(.empty-cell)');
                return span ? span.textContent.trim() : '';
            }
        }

        if (PEOPLE_COLS.has(col)) {
            const badges = [...td.querySelectorAll('.badge[data-tip]')];
            if (!badges.length) return '';
            // data-tip format: "Last, First · email" — take name before ·
            return badges.map(b => b.dataset.tip.split('·')[0].trim()).join(', ');
        }

        // Editable cells store original-case value in data-value
        if (td.dataset.value !== undefined) return td.dataset.value;

        const text = td.textContent.trim();
        return text === '—' ? '' : text;
    });

    const btn = variant === 'url'
        ? document.getElementById('filter-pop-copy-btn2')
        : document.getElementById('filter-pop-copy-btn');
    const orig = btn.textContent;

    copyToClipboard(lines.join('\n')).then(() => {
        btn.classList.add('copied');
        btn.textContent = '✓ Copied!';
        setTimeout(() => { btn.textContent = orig; btn.classList.remove('copied'); }, 1500);
    }).catch(err => {
        // navigator.clipboard.writeText() silently rejects on an insecure
        // (plain HTTP) origin — copyToClipboard() falls back to execCommand
        // for that case, so a rejection here means both paths failed.
        console.error('Copy failed:', err);
        btn.classList.add('copy-failed');
        btn.textContent = '✗ Copy failed';
        setTimeout(() => { btn.textContent = orig; btn.classList.remove('copy-failed'); }, 2000);
    });
}

// navigator.clipboard requires a secure context (HTTPS or localhost) and
// fails silently (rejected promise, no visible error) otherwise — WEBGOV is
// still HTTP-only pending its TLS cert, so this falls back to the older
// execCommand approach, which has no such restriction.
function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text);
    }
    return new Promise((resolve, reject) => {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        let ok = false;
        try { ok = document.execCommand('copy'); }
        catch (e) { document.body.removeChild(ta); reject(e); return; }
        document.body.removeChild(ta);
        ok ? resolve() : reject(new Error('execCommand("copy") returned false'));
    });
}

function markFilterBtn(col, active) {
    document.querySelectorAll(`.filter-btn[data-col="${col}"]`).forEach(btn => {
        btn.classList.toggle('filter-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
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
    rememberFocus();
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
    restoreFocus();
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('link-overlay').style.display !== 'none') {
        closeLinkModal();
    }
});

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

// ── Modal accessibility: focus management + keyboard trap ──────────────────
// Shared across all four overlay-based dialogs (people, link, add-site,
// site-edit) so a keyboard user's focus never gets lost in/behind them.
let modalReturnFocusEl = null;

function rememberFocus() {
    modalReturnFocusEl = document.activeElement;
}

function restoreFocus() {
    if (modalReturnFocusEl && document.body.contains(modalReturnFocusEl)) modalReturnFocusEl.focus();
    modalReturnFocusEl = null;
}

function focusableIn(container) {
    return [...container.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )].filter(el => el.offsetParent !== null);
}

// One shared Tab handler for whichever dialog is currently visible, so Tab
// cycles within it instead of escaping into the (still-present, just
// visually covered) page behind it.
document.addEventListener('keydown', e => {
    if (e.key !== 'Tab') return;
    const openDialog = document.querySelector(
        '#modal-overlay.open [role="dialog"], ' +
        '#link-overlay[style*="flex"] [role="dialog"], ' +
        '#add-site-overlay[style*="flex"] [role="dialog"], ' +
        '#site-edit-overlay[style*="flex"] [role="dialog"]'
    );
    if (!openDialog) return;
    const list = focusableIn(openDialog);
    if (!list.length) return;
    const first = list[0], last = list[list.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
});

// role-cells and link-cells are plain <td>s made keyboard-operable via
// tabindex="0" role="button" — unlike a real <button>, a <td> doesn't
// natively respond to Enter/Space, so this re-fires the same onclick.
document.addEventListener('keydown', e => {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const target = e.target.closest('[role="button"]');
    if (!target || target.tagName === 'BUTTON' || target.tagName === 'A') return;
    e.preventDefault();
    target.click();
});

async function openPeopleModal(siteId, role, cell) {
    rememberFocus();
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
    document.getElementById('modal-box').focus();
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
    restoreFocus();
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && document.getElementById('modal-overlay').classList.contains('open')) {
        closePeopleModal();
    }
});

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
            `<span class="badge" aria-hidden="true" style="background:${badgeColor(p.last_name+p.first_name)}"
                   title="${escHtml(p.last_name+', '+p.first_name+(p.email?' · '+p.email:''))}">
                ${initials(p.first_name, p.last_name)}
            </span>`).join('')
        : '<span class="empty-cell">—</span>';
    // Keep the cell's own accessible name (its real label now that badges
    // are aria-hidden — see renderBadges()) in sync after a live edit.
    // Note: doesn't re-check DubBot enrollment status here (that data isn't
    // part of modalState.roles) — a full page reload re-derives it from the
    // DB correctly; this only covers names going stale mid-session.
    const names = people.map(p => `${p.first_name} ${p.last_name}`.trim()).join(', ');
    cell.setAttribute('aria-label', `${COLUMN_LABELS[role] || role}: ${names || 'none assigned'} — activate to edit`);
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
            `<span class="badge" aria-hidden="true" style="background:${badgeColor(p.last_name+p.first_name)}"
                   title="${escHtml(p.last_name+', '+p.first_name+(p.email?' · '+p.email:''))}">
                ${initials(p.first_name, p.last_name)}
            </span>`).join('')
        : '<span class="empty-cell">—</span>';
    const names = people.map(p => `${p.first_name} ${p.last_name}`.trim()).join(', ');
    cell.setAttribute('aria-label', `VP Lead: ${names || 'none assigned'} — activate to edit`);
}

// ── Site edit modal (URL + Site Name) ──────────────────────────────────────
let siteEditId       = null;
let siteEditIsActive = true;

function openSiteEditModal(siteId, siteName, url, isActive) {
    rememberFocus();
    siteEditId       = siteId;
    siteEditIsActive = isActive !== 0 && isActive !== false;
    document.getElementById('site-edit-name').value  = siteName || '';
    document.getElementById('site-edit-url').value   = url      || '';
    document.getElementById('site-edit-error').style.display = 'none';
    const deactivateBtn = document.getElementById('site-deactivate-btn');
    if (siteEditIsActive) {
        deactivateBtn.textContent = 'Deactivate this site…';
        deactivateBtn.style.color = '#92400E';
    } else {
        deactivateBtn.textContent = 'Reactivate this site…';
        deactivateBtn.style.color = '#15803d';
    }
    const overlay = document.getElementById('site-edit-overlay');
    overlay.style.display = 'flex';
    setTimeout(() => document.getElementById('site-edit-name').focus(), 50);
}

function closeSiteEditModal() {
    document.getElementById('site-edit-overlay').style.display = 'none';
    hideDeleteConfirm();
    hideDeactivateConfirm();
    siteEditId = null;
    restoreFocus();
}

function showDeactivateConfirm() {
    const name = document.getElementById('site-edit-name').value.trim()
               || document.getElementById('site-edit-url').value.trim()
               || 'this site';
    document.getElementById('site-deactivate-label').textContent = name;
    const isDeactivating = siteEditIsActive;
    document.getElementById('site-deactivate-warning').style.display   = isDeactivating ? '' : 'none';
    document.getElementById('site-reactivate-warning').style.display   = isDeactivating ? 'none' : '';
    document.getElementById('site-deactivate-action-label').textContent = isDeactivating ? 'Deactivate' : 'Reactivate';
    const confirmBtn = document.getElementById('site-deactivate-confirm-btn');
    confirmBtn.disabled     = false;
    confirmBtn.textContent   = isDeactivating ? 'Yes, deactivate' : 'Yes, reactivate';
    confirmBtn.style.background = isDeactivating ? '#D97706' : '#15803d';
    document.getElementById('site-edit-fields').style.display    = 'none';
    document.getElementById('site-deactivate-confirm').style.display = 'block';
}

function hideDeactivateConfirm() {
    document.getElementById('site-edit-fields').style.display        = 'block';
    document.getElementById('site-deactivate-confirm').style.display = 'none';
}

async function confirmToggleSiteActive() {
    const siteId    = siteEditId;
    const newActive = siteEditIsActive ? 0 : 1;
    const btn       = document.getElementById('site-deactivate-confirm-btn');
    btn.disabled    = true;
    btn.textContent = siteEditIsActive ? 'Deactivating…' : 'Reactivating…';
    const res = await api({ action: 'toggle_site_active', site_id: siteId, is_active: newActive });
    if (res.error) {
        btn.disabled    = false;
        btn.textContent = siteEditIsActive ? 'Yes, deactivate' : 'Yes, reactivate';
        document.getElementById('site-edit-error').textContent   = res.error;
        document.getElementById('site-edit-error').style.display = 'block';
        hideDeactivateConfirm();
        return;
    }
    // Update row in the table
    const row = document.querySelector(`tr[data-id="${siteId}"]`);
    if (row) {
        row.dataset.is_active = String(newActive);
        row.classList.toggle('site-inactive', newActive === 0);
        const badge = row.querySelector('.inactive-badge');
        if (newActive === 0 && !badge) {
            const siteInner = row.querySelector('.sticky-1 .site-inner');
            if (siteInner) {
                const b = document.createElement('span');
                b.className   = 'inactive-badge';
                b.textContent = 'Inactive';
                siteInner.appendChild(b);
            }
        } else if (newActive === 1 && badge) {
            badge.remove();
        }
        // Update pencil button onclick for both URL and site name cells
        row.querySelectorAll('.site-edit-btn').forEach(editBtn => {
            editBtn.onclick = e => {
                e.stopPropagation();
                openSiteEditModal(siteId,
                    document.getElementById('site-edit-name').value,
                    document.getElementById('site-edit-url').value,
                    newActive);
            };
        });
    }
    // Re-apply filters so the row hides/shows per the current status filter
    applyFilters();
    closeSiteEditModal();
}

function showDeleteConfirm() {
    const name = document.getElementById('site-edit-name').value.trim()
               || document.getElementById('site-edit-url').value.trim()
               || 'this site';
    document.getElementById('site-delete-label').textContent = name;
    document.getElementById('site-edit-fields').style.display  = 'none';
    document.getElementById('site-delete-confirm').style.display = 'block';
}

function hideDeleteConfirm() {
    document.getElementById('site-edit-fields').style.display    = 'block';
    document.getElementById('site-delete-confirm').style.display = 'none';
}

async function confirmDeleteSite() {
    const siteId = siteEditId;
    const btn = document.querySelector('#site-delete-confirm button');
    btn.disabled = true;
    btn.textContent = 'Deleting…';
    const res = await api({ action: 'delete_site', site_id: siteId });
    if (res.error) {
        btn.disabled = false;
        btn.textContent = 'Yes, delete permanently';
        document.getElementById('site-edit-error').textContent = res.error;
        document.getElementById('site-edit-error').style.display = 'block';
        hideDeleteConfirm();
        return;
    }
    // Remove the row from the table
    const row = document.querySelector(`tr[data-id="${siteId}"]`);
    if (row) row.remove();
    updateRowCount(document.querySelectorAll('#main-table tbody tr[data-id]:not([style*="display: none"])').length);
    closeSiteEditModal();
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
                openSiteEditModal(siteId, name, url, siteEditIsActive ? 1 : 0);
            };

            // Update URL td
            const urlTd    = row.querySelector('td.col-url');
            const urlInner = urlTd.querySelector('.site-inner');
            let urlA = urlInner.querySelector('a');
            if (url) {
                if (!urlA) {
                    urlA = document.createElement('a');
                    urlA.target = '_blank';
                    urlA.addEventListener('click', e => e.stopPropagation());
                    urlInner.innerHTML = '';
                    urlInner.appendChild(urlA);
                    // Re-append inactive badge if present
                    const existingBadge = row.dataset.is_active === '0'
                        ? (() => { const b = document.createElement('span'); b.className = 'inactive-badge'; b.textContent = 'Inactive'; return b; })()
                        : null;
                    if (existingBadge) urlInner.appendChild(existingBadge);
                }
                urlA.textContent = url;
                urlA.href = 'https://' + url;
            } else {
                urlInner.innerHTML = '<span class="empty-cell">—</span>';
            }
            urlTd.title = url;
            const urlEditBtn = urlTd.querySelector('.site-edit-btn');
            if (urlEditBtn) urlEditBtn.onclick = e => {
                e.stopPropagation();
                openSiteEditModal(siteId, name, url, siteEditIsActive ? 1 : 0);
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
    rememberFocus();
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
    restoreFocus();
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
    // Updates a persistent aria-live span rather than replacing the whole
    // header cell's innerHTML (which used to destroy/recreate the refresh
    // button on every status change — bad for a live region, since some
    // screen readers only reliably announce content changes on an element
    // that already existed, not one that was just re-inserted, and it also
    // silently dropped focus if the button had it when a status came in).
    const live = document.getElementById('db-status-live');
    const btn  = document.getElementById('db-refresh-btn');
    if (!live || !btn) return;
    btn.disabled = (type === 'loading');
    if (type === 'loading') {
        live.innerHTML = `<span class="db-hdr-spin" aria-hidden="true"></span><span class="db-hdr-status">${escHtml(msg)}</span>`;
    } else if (type === 'error') {
        live.innerHTML = `<span class="db-hdr-error">⚠ ${escHtml(msg)}</span>`;
    } else {
        live.innerHTML = `<span class="db-hdr-status">${escHtml(msg)}</span>`;
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

    // Independent of the site-stats fetch below — never blocks or fails it.
    checkDubbotEnrollment(accounts).catch(e => console.error('DubBot enrollment check failed:', e));

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

// ── Check which Content/Tech Leads have a DubBot account ───────────────────
// Pulls every user across all discovered DubBot accounts, matches by email
// against the Content Lead / Tech Lead badges on screen, rings the ones with
// no match, and persists the result to employees.dubbot_enrolled so it
// survives until the next refresh (see renderBadges()/PHP-side rendering).
async function checkDubbotEnrollment(accounts) {
    const emails = new Set();

    for (const acc of accounts) {
        let page = 1;
        for (;;) {
            const data = await dbGql(`{
                users(accountId:"${acc.id}", page:${page}, perPage:200, sortBy: name, sortOrder: asc) {
                    currentPage
                    totalPages
                    nodes { email }
                }
            }`);
            const res = data?.users;
            dbExtractList(res?.nodes).forEach(u => {
                if (u?.email) emails.add(u.email.toLowerCase());
            });
            if (!res || !res.totalPages || res.currentPage >= res.totalPages) break;
            page++;
        }
    }

    // One entry per employee (a person can be Content/Tech Lead on many
    // sites — only need to check/save their enrollment status once).
    const byEmpId = new Map();
    document.querySelectorAll('td.col-content_lead .badge[data-emp-id], td.col-tech_lead .badge[data-emp-id]')
        .forEach(badge => {
            const empId = parseInt(badge.dataset.empId, 10);
            if (!empId) return;
            const email    = badge.dataset.email || '';
            const enrolled = email ? emails.has(email) : false;
            badge.classList.toggle('db-missing', !enrolled);

            // The corner marker is a real SVG element (PHP renders it on
            // page load), not pure CSS — keep it in sync here too, since
            // toggling the class alone won't insert/remove it.
            const hasIcon = !!badge.querySelector('.db-missing-icon');
            if (!enrolled && !hasIcon) badge.insertAdjacentHTML('beforeend', DB_MISSING_ICON_SVG);
            else if (enrolled && hasIcon) badge.querySelector('.db-missing-icon').remove();

            byEmpId.set(empId, enrolled);
        });

    if (!byEmpId.size) return;
    const updates = [...byEmpId.entries()].map(([emp_id, enrolled]) => ({ emp_id, enrolled }));
    await api({ action: 'save_dubbot_enrollment', updates });
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

    function tipTarget(el) {
        // Prefer innermost data-tip (badges), fall back to td[title]
        return el.closest('[data-tip]') || el.closest('td[title]');
    }

    tbody.addEventListener('mouseover', e => {
        const target = tipTarget(e.target);
        if (!target) { hide(); return; }
        const text = target.dataset.tip || target.getAttribute('title');
        if (!text) { hide(); return; }
        show(text, e.clientX, e.clientY);
    });

    tbody.addEventListener('mousemove', e => {
        if (!shown) return;
        position(e.clientX, e.clientY);
    });

    tbody.addEventListener('mouseout', e => {
        const target = tipTarget(e.target);
        if (!target) return;
        if (!target.contains(e.relatedTarget)) hide();
    });

    // Hide if user clicks (going into edit mode)
    tbody.addEventListener('click', hide);
})();
</script>
</body>
</html>
