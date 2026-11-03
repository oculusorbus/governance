<?php
/**
 * Shared authorisation helpers for every app on this host.
 *
 * All four apps (governance, /dubbot, EAER admin, EAER API) sit on one host
 * sharing one php.ini and one session, so a single SAML login already covers
 * all of them. This file is the one place that decides what a session is
 * allowed to do.
 *
 * Roles
 *   viewer  default for every newly provisioned user. Read-only.
 *   editor  may change directory records.
 *   admin   may administer EAER and assign roles.
 *
 * Why read-only by default: UTS restricts the Entra app to employees, so any
 * employee can authenticate. Authentication therefore proves only employment,
 * not trust — before roles existed, a boolean session let anyone who signed
 * in edit all 374 site records and delete exception requests attached to
 * signed compliance memos.
 */

const ROLE_RANK = ['viewer' => 1, 'editor' => 2, 'admin' => 3];

/**
 * The signed-in user, or null.
 *
 * The legacy APP_PASSWORD gate predates SSO and grants no identity, so it is
 * mapped to admin to preserve current behaviour for the Web Team during the
 * transition. Remove that branch once SSO is the only way in.
 */
function current_user(): ?array {
    if (empty($_SESSION['auth'])) {
        return null;
    }
    if (empty($_SESSION['user_id'])) {
        return [
            'id'           => null,
            'role'         => 'admin',
            'display_name' => 'Shared password session',
            'email'        => null,
            'employee_id'  => null,
            'legacy'       => true,
        ];
    }
    return [
        'id'           => (int)$_SESSION['user_id'],
        'role'         => (string)($_SESSION['user_role'] ?? 'viewer'),
        'display_name' => (string)($_SESSION['user_name'] ?? ''),
        'email'        => $_SESSION['user_email'] ?? null,
        'employee_id'  => $_SESSION['employee_id'] ?? null,
        'legacy'       => false,
    ];
}

function user_role(): string {
    return current_user()['role'] ?? 'viewer';
}

/** True if the session holds at least $needed. */
function user_has_role(string $needed): bool {
    $have = ROLE_RANK[user_role()] ?? 0;
    $want = ROLE_RANK[$needed]     ?? 99;
    return $have >= $want;
}

function can_edit(): bool  { return user_has_role('editor'); }
function is_admin(): bool  { return user_has_role('admin'); }

/**
 * Require a session, bouncing to SSO and returning here afterwards.
 * $_SERVER['REQUEST_URI'] keeps the query string, which matters for EAER
 * token links.
 */
function require_login(): array {
    $user = current_user();
    if ($user === null) {
        $return = $_SERVER['REQUEST_URI'] ?? '/app.php';
        header('Location: /saml/login.php?return=' . urlencode($return));
        exit;
    }
    return $user;
}

/**
 * Require a role. Deliberately a 403 rather than a redirect: the user IS
 * signed in, so bouncing them to SSO would loop. Says plainly what to do.
 */
function require_role(string $needed): array {
    $user = require_login();
    if (!user_has_role($needed)) {
        http_response_code(403);
        exit(
            'Your account has read-only access to this tool. '
            . 'Ask a Web Team administrator to grant you the access you need.'
        );
    }
    return $user;
}

/** JSON equivalent, for api.php / eaer_api.php. */
function api_require_role(string $needed): array {
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    if (!user_has_role($needed)) {
        http_response_code(403);
        echo json_encode(['error' => 'Your account has read-only access. Ask an administrator for edit access.']);
        exit;
    }
    return $user;
}
