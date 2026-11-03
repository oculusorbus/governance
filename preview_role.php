<?php
/**
 * Switches an admin into a lesser role for previewing, or clears it.
 *
 *   preview_role.php?role=viewer&return=/app.php
 *   preview_role.php?role=clear&return=/app.php
 *
 * Only an actual admin may do this, and only downward — preview_role() in
 * auth.php refuses anything at or above the account's real rank, so this
 * cannot be used to gain access. The preview changes authorisation as well
 * as presentation, so what you see is genuinely what that role gets.
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

require_role('admin');

// Guard on the REAL role, not the previewed one — otherwise an admin who
// had already previewed as viewer could not switch back or clear.
if (!can_preview_roles()) {
    http_response_code(403);
    exit('Only administrators can preview roles.');
}

$role = (string)($_GET['role'] ?? 'clear');

if ($role === 'clear' || $role === actual_role()) {
    unset($_SESSION['preview_role']);
} elseif (isset(ROLE_RANK[$role])) {
    $_SESSION['preview_role'] = $role;
}

header('Location: ' . saml_safe_return($_GET['return'] ?? null));
exit;
