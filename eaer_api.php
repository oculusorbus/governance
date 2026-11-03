<?php
/**
 * JSON API for the EAER prototype.
 *
 * Auth model:
 *   - 'create', 'list', 'export'      → require the main app session ($_SESSION['auth']).
 *   - 'get', 'save_section'           → require a valid token, plus a contributor
 *                                        name already established in-session for
 *                                        that token (set by eaer.php's name gate).
 *     No SSO yet, so this is a self-reported name, not an authenticated identity —
 *     acceptable for an interim/prototype tool where binding approval happens via
 *     the separate DocuSign exception-request memo (VP IMT + EIRAC).
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/eaer_db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? '');
$pdo    = eaer_pdo();

function eaer_fail(int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

/**
 * Administering exception requests — creating, exporting, reopening,
 * deleting — is admin-only. These act on records attached to signed
 * compliance memos, so a signed-in employee with no granted role must not
 * reach them. Contributing to a record is separate and governed by holding
 * its token.
 */
function eaer_require_admin(): void {
    api_require_role('admin');
}

function eaer_find_by_token(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare("SELECT * FROM eaer_requests WHERE token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Token-authenticated requests also need an established contributor name. */
function eaer_require_contributor(string $token): string {
    $name = trim((string)($_SESSION['eaer_name'][$token] ?? ''));
    if ($name === '') eaer_fail(403, 'No contributor name set for this token');
    return $name;
}

switch ($action) {

    // ── Admin: create a new EAER record, returns token + shareable link ────
    case 'create':
        eaer_require_admin();
        $eirName = trim((string)($input['eir_name'] ?? ''));
        $token   = eaer_gen_token();
        $pdo->prepare("INSERT INTO eaer_requests (token, eir_name) VALUES (?, ?)")
            ->execute([$token, $eirName !== '' ? $eirName : null]);
        echo json_encode([
            'success' => true,
            'token'   => $token,
            'link'    => 'eaer.php?token=' . $token,
        ]);
        break;

    // ── Admin: list all records with a contributor/activity summary ────────
    case 'list':
        eaer_require_admin();
        $rows = $pdo->query("
            SELECT r.id, r.token, r.status, r.eir_name, r.vendor_name,
                   r.created_at, r.exported_at,
                   COUNT(DISTINCT c.contributor_name) AS contributor_count,
                   MAX(c.updated_at) AS last_activity
            FROM eaer_requests r
            LEFT JOIN eaer_contributions c ON c.eaer_id = r.id
            GROUP BY r.id, r.token, r.status, r.eir_name, r.vendor_name, r.created_at, r.exported_at
            ORDER BY r.created_at DESC
        ")->fetchAll();
        echo json_encode(['success' => true, 'records' => $rows]);
        break;

    // ── Admin: mark a record exported (attach-ready snapshot) ──────────────
    case 'export':
        eaer_require_admin();
        $token = (string)($input['token'] ?? '');
        $rec   = eaer_find_by_token($pdo, $token);
        if (!$rec) eaer_fail(404, 'Not found');
        $pdo->prepare("UPDATE eaer_requests SET status = 'exported', exported_at = SYSUTCDATETIME() WHERE id = ?")
            ->execute([$rec['id']]);
        echo json_encode(['success' => true]);
        break;

    // ── Admin: reopen an exported record for further editing ───────────────
    // exported_at is left as-is (last export time), not cleared — it's
    // reused as the "last exported" marker if re-exported later.
    case 'reopen':
        eaer_require_admin();
        $token = (string)($input['token'] ?? '');
        $rec   = eaer_find_by_token($pdo, $token);
        if (!$rec) eaer_fail(404, 'Not found');
        $pdo->prepare("UPDATE eaer_requests SET status = 'draft' WHERE id = ?")
            ->execute([$rec['id']]);
        echo json_encode(['success' => true]);
        break;

    // ── Admin: permanently delete a record (test data / false starts) ──────
    case 'delete':
        eaer_require_admin();
        $token = (string)($input['token'] ?? '');
        $rec   = eaer_find_by_token($pdo, $token);
        if (!$rec) eaer_fail(404, 'Not found');
        $pdo->prepare("DELETE FROM eaer_contributions WHERE eaer_id = ?")->execute([$rec['id']]);
        $pdo->prepare("DELETE FROM eaer_requests WHERE id = ?")->execute([$rec['id']]);
        echo json_encode(['success' => true]);
        break;

    // ── Token or admin: fetch a record + its contributor attribution ───────
    case 'get':
        $token = (string)($input['token'] ?? ($_GET['token'] ?? ''));
        $rec   = eaer_find_by_token($pdo, $token);
        if (!$rec) eaer_fail(404, 'Not found');
        if (empty($_SESSION['auth'])) eaer_require_contributor($token);

        $stmt = $pdo->prepare("
            SELECT section_key, contributor_name, updated_at
            FROM eaer_contributions WHERE eaer_id = ?
            ORDER BY section_key, updated_at
        ");
        $stmt->execute([$rec['id']]);
        $contributions = [];
        foreach ($stmt->fetchAll() as $c) {
            $contributions[$c['section_key']][] = [
                'name'       => $c['contributor_name'],
                'updated_at' => $c['updated_at'],
            ];
        }
        echo json_encode(['success' => true, 'record' => $rec, 'contributions' => $contributions]);
        break;

    // ── Token or admin: save one section's fields, stamp contributor ───────
    case 'save_section':
        $token      = (string)($input['token'] ?? '');
        $sectionKey = (string)($input['section'] ?? '');
        $fields     = $input['fields'] ?? [];

        $rec = eaer_find_by_token($pdo, $token);
        if (!$rec) eaer_fail(404, 'Not found');

        // A signed-in employee contributes under their verified identity.
        // Anyone else falls back to the self-reported name gate, and the
        // record keeps the distinction: entra_object_id NULL means the
        // attribution was not verified.
        $me        = current_user();
        $objectId  = null;
        $email     = null;
        if ($me !== null && !empty($_SESSION['entra_object_id'])) {
            $objectId = (string)$_SESSION['entra_object_id'];
            $email    = $_SESSION['user_email'] ?? null;
            $name     = trim((string)($_SESSION['user_name'] ?? '')) ?: (string)$email;
        } else {
            $name = eaer_require_contributor($token);
        }

        $sections = eaer_sections();
        if (!isset($sections[$sectionKey])) eaer_fail(400, 'Unknown section');
        $allowedCols = array_keys($sections[$sectionKey]['fields']);

        $setParts = [];
        $vals     = [];
        foreach ($fields as $col => $val) {
            if (!in_array($col, $allowedCols, true)) continue; // whitelist only
            $setParts[] = "[$col] = ?";
            $vals[]     = is_array($val) ? implode(', ', $val) : (string)$val;
        }
        if ($setParts) {
            $vals[] = $rec['id'];
            $pdo->prepare("UPDATE eaer_requests SET " . implode(', ', $setParts) . " WHERE id = ?")
                ->execute($vals);
        }

        // Still keyed on the name, not the object id: the unique constraint
        // predates SSO and a self-reported contributor has no object id to
        // key on. The identity columns are refreshed on match so a
        // previously self-reported contribution becomes verified once that
        // person signs in and saves again.
        $pdo->prepare("
            MERGE eaer_contributions AS target
            USING (SELECT ? AS eaer_id, ? AS section_key, ? AS contributor_name) AS src
                ON target.eaer_id = src.eaer_id
               AND target.section_key = src.section_key
               AND target.contributor_name = src.contributor_name
            WHEN MATCHED THEN
                UPDATE SET updated_at = SYSUTCDATETIME(),
                           entra_object_id = ?,
                           contributor_email = ?
            WHEN NOT MATCHED THEN
                INSERT (eaer_id, section_key, contributor_name, entra_object_id, contributor_email)
                VALUES (src.eaer_id, src.section_key, src.contributor_name, ?, ?);
        ")->execute([$rec['id'], $sectionKey, $name, $objectId, $email, $objectId, $email]);

        echo json_encode(['success' => true]);
        break;

    default:
        eaer_fail(400, 'Unknown action');
}
