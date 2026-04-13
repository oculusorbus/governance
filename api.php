<?php
session_start();
require_once 'config.php';

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? '');

// Whitelisted direct text columns on sites
const TEXT_FIELDS = ['url', 'site_name', 'description'];

// Whitelisted FK columns on sites -> which lookup table they reference
const FK_FIELDS = [
    'vp_area_id'            => 'vp_areas',
    'college_dept_id'       => 'colleges_depts',
    'support_platform_id'   => 'support_platforms',
    'server_id'             => 'servers',
    'platform_id'           => 'platforms',
    'audience_id'           => 'audiences',
    'category_id'           => 'categories',
    'second_category_id'    => 'categories',
];

switch ($action) {

    // ── Update a plain text or FK field on a site ─────────────────────────
    case 'update_site':
        $siteId = (int)($input['site_id'] ?? 0);
        $field  = $input['field'] ?? '';
        $value  = $input['value'] ?? null;

        if (in_array($field, TEXT_FIELDS, true)) {
            $pdo->prepare("UPDATE sites SET `$field` = ? WHERE id = ?")
                ->execute([$value ?: null, $siteId]);
            echo json_encode(['success' => true]);

        } elseif (array_key_exists($field, FK_FIELDS)) {
            $val = ($value !== '' && $value !== null) ? (int)$value : null;
            $pdo->prepare("UPDATE sites SET `$field` = ? WHERE id = ?")
                ->execute([$val, $siteId]);
            echo json_encode(['success' => true]);

        } else {
            echo json_encode(['error' => 'Invalid field']);
        }
        break;

    // ── Update a URL-type field (support_intake_url or datastudio) ────────
    // These live in their own lookup tables; find-or-create the record.
    case 'update_link':
        $siteId   = (int)($input['site_id'] ?? 0);
        $linkType = $input['link_type'] ?? '';
        $url      = trim($input['url'] ?? '');

        $map = [
            'intake'    => ['support_intake_urls', 'support_intake_url_id'],
            'datastudio'=> ['datastudios',          'datastudio_id'],
        ];

        if (!isset($map[$linkType])) { echo json_encode(['error' => 'Invalid link type']); break; }
        [$table, $fkCol] = $map[$linkType];

        if ($url === '') {
            $pdo->prepare("UPDATE sites SET `$fkCol` = NULL WHERE id = ?")->execute([$siteId]);
            echo json_encode(['success' => true, 'id' => null, 'url' => '']);
            break;
        }

        $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE url = ?");
        $stmt->execute([$url]);
        $row = $stmt->fetch();
        if ($row) {
            $linkId = $row['id'];
        } else {
            $pdo->prepare("INSERT INTO `$table` (url) VALUES (?)")->execute([$url]);
            $linkId = (int)$pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE sites SET `$fkCol` = ? WHERE id = ?")->execute([$linkId, $siteId]);
        echo json_encode(['success' => true, 'id' => $linkId, 'url' => $url]);
        break;

    // ── Get all roles for a site ──────────────────────────────────────────
    case 'get_roles':
        $siteId = (int)($input['site_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT sr.id AS role_id, sr.role,
                   e.id  AS employee_id, e.first_name, e.last_name, e.email
            FROM site_roles sr
            JOIN employees e ON sr.employee_id = e.id
            WHERE sr.site_id = ?
            ORDER BY sr.role, e.last_name, e.first_name
        ");
        $stmt->execute([$siteId]);
        echo json_encode(['success' => true, 'roles' => $stmt->fetchAll()]);
        break;

    // ── Add an employee to a site role ────────────────────────────────────
    case 'add_role':
        $siteId     = (int)($input['site_id']     ?? 0);
        $employeeId = (int)($input['employee_id'] ?? 0);
        $role       = $input['role'] ?? '';
        $validRoles = ['college_communicator','site_owner','content_lead','tech_lead','admin_contact'];

        if (!in_array($role, $validRoles, true)) { echo json_encode(['error' => 'Invalid role']); break; }

        // Prevent duplicate assignment
        $check = $pdo->prepare("SELECT id FROM site_roles WHERE site_id=? AND employee_id=? AND role=?");
        $check->execute([$siteId, $employeeId, $role]);
        if ($check->fetch()) { echo json_encode(['success' => true, 'duplicate' => true]); break; }

        $pdo->prepare("INSERT INTO site_roles (site_id, employee_id, role) VALUES (?,?,?)")
            ->execute([$siteId, $employeeId, $role]);
        echo json_encode(['success' => true, 'role_id' => (int)$pdo->lastInsertId()]);
        break;

    // ── Remove an employee from a site role ───────────────────────────────
    case 'remove_role':
        $roleId = (int)($input['role_id'] ?? 0);
        $pdo->prepare("DELETE FROM site_roles WHERE id = ?")->execute([$roleId]);
        echo json_encode(['success' => true]);
        break;

    // ── Employee search (for people modal autocomplete) ───────────────────
    case 'search_employees':
        $q = '%' . ($input['q'] ?? '') . '%';
        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, email
            FROM employees
            WHERE last_name LIKE ? OR first_name LIKE ? OR email LIKE ?
            ORDER BY last_name, first_name
            LIMIT 25
        ");
        $stmt->execute([$q, $q, $q]);
        echo json_encode($stmt->fetchAll());
        break;

    // ── Add a new site row ────────────────────────────────────────────────
    case 'add_site':
        $url = trim($input['url'] ?? '');
        if ($url === '') { echo json_encode(['error' => 'URL is required']); break; }
        $pdo->prepare("INSERT INTO sites (url) VALUES (?)")->execute([$url]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}
