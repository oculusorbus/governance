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

// Deliberately require_login() and NOT require_role('admin'): require_role
// resolves against the PREVIEWED role, so asking it for 'admin' here would
// lock this page the instant a preview was active — including the "exit
// preview" link, which loops straight back to it.
//
// can_preview_roles() reads the real granted role out of the session, so it
// still refuses anyone who is not genuinely an admin.
require_login();

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
