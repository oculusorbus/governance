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

try { switch ($action) {

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

    // ── Get VP leads for a VP area ────────────────────────────────────────
    // vp_area_leads has no surrogate id; use employee_id as the identifier.
    case 'get_vp_leads':
        $vpAreaId = (int)($input['vp_area_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT e.id AS lead_id, e.first_name, e.last_name, e.email
            FROM vp_area_leads val
            JOIN employees e ON val.employee_id = e.id
            WHERE val.vp_area_id = ?
            ORDER BY e.last_name, e.first_name
        ");
        $stmt->execute([$vpAreaId]);
        echo json_encode(['success' => true, 'leads' => $stmt->fetchAll()]);
        break;

    // ── Add a VP lead ─────────────────────────────────────────────────────
    case 'add_vp_lead':
        $vpAreaId   = (int)($input['vp_area_id']   ?? 0);
        $employeeId = (int)($input['employee_id'] ?? 0);
        $check = $pdo->prepare("SELECT 1 FROM vp_area_leads WHERE vp_area_id=? AND employee_id=?");
        $check->execute([$vpAreaId, $employeeId]);
        if ($check->fetch()) { echo json_encode(['success' => true, 'duplicate' => true]); break; }
        $pdo->prepare("INSERT INTO vp_area_leads (vp_area_id, employee_id) VALUES (?,?)")
            ->execute([$vpAreaId, $employeeId]);
        // Return employee_id as lead_id (used as remove key since table has no surrogate id)
        echo json_encode(['success' => true, 'lead_id' => $employeeId]);
        break;

    // ── Remove a VP lead ──────────────────────────────────────────────────
    case 'remove_vp_lead':
        $employeeId = (int)($input['lead_id']     ?? 0);  // lead_id IS employee_id
        $vpAreaId   = (int)($input['vp_area_id']  ?? 0);
        $pdo->prepare("DELETE FROM vp_area_leads WHERE vp_area_id = ? AND employee_id = ?")
            ->execute([$vpAreaId, $employeeId]);
        echo json_encode(['success' => true]);
        break;

    // ── Update an employee's name or email ───────────────────────────────
    case 'update_employee':
        $empId = (int)($input['employee_id'] ?? 0);
        $field = $input['field'] ?? '';
        $value = trim($input['value'] ?? '');
        $allowed = ['first_name', 'last_name', 'email'];
        if (!in_array($field, $allowed, true) || !$empId) {
            echo json_encode(['error' => 'Invalid field or ID']); break;
        }
        if ($field === 'email') $value = strtolower($value);
        $pdo->prepare("UPDATE employees SET `$field` = ? WHERE id = ?")
            ->execute([$value ?: null, $empId]);
        echo json_encode(['success' => true]);
        break;

    // ── Add a new employee ────────────────────────────────────────────────
    case 'add_employee':
        $first = trim($input['first_name'] ?? '');
        $last  = trim($input['last_name']  ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        if (!$first || !$last || !$email) {
            echo json_encode(['error' => 'First name, last name, and email are required']); break;
        }
        // Return existing record if email already in use
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM employees WHERE email = ?");
        $stmt->execute([$email]);
        $existing = $stmt->fetch();
        if ($existing) {
            echo json_encode(['success' => true, 'id' => (int)$existing['id'],
                'existing' => true, 'match' => 'email',
                'first_name' => $existing['first_name'], 'last_name' => $existing['last_name'],
                'email' => $existing['email']]);
            break;
        }
        // Return existing record if exact name already in use
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM employees WHERE first_name = ? AND last_name = ?");
        $stmt->execute([$first, $last]);
        $existing = $stmt->fetch();
        if ($existing) {
            echo json_encode(['success' => true, 'id' => (int)$existing['id'],
                'existing' => true, 'match' => 'name',
                'first_name' => $existing['first_name'], 'last_name' => $existing['last_name'],
                'email' => $existing['email']]);
            break;
        }
        $pdo->prepare("INSERT INTO employees (first_name, last_name, email) VALUES (?,?,?)")
            ->execute([$first, $last, $email]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
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

} } catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
