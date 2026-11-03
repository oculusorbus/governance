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

/** The role actually granted to this account, ignoring any preview. */
function actual_role(): string {
    return current_user()['role'] ?? 'viewer';
}

/**
 * Role preview. An admin can view the application as a lesser role to see
 * what other people get, without editing the database and signing out and
 * in again.
 *
 * Two properties make this safe:
 *   - It can only ever DOWNGRADE. A preview at or above the account's real
 *     rank is ignored, so this is never a privilege-escalation path.
 *   - It changes AUTHORISATION, not just the UI. A preview that only
 *     restyled the page while still permitting writes would be worse than
 *     useless — it would report success on a gate it never tested.
 */
function can_preview_roles(): bool {
    $u = current_user();
    return $u !== null && (ROLE_RANK[$u['role']] ?? 0) >= ROLE_RANK['admin'];
}

function preview_role(): ?string {
    $preview = $_SESSION['preview_role'] ?? null;
    if (!is_string($preview) || !isset(ROLE_RANK[$preview])) {
        return null;
    }
    if (!can_preview_roles()) {
        return null;
    }
    // Strictly below the real role, so previewing cannot grant anything.
    if (ROLE_RANK[$preview] >= (ROLE_RANK[actual_role()] ?? 0)) {
        return null;
    }
    return $preview;
}

function is_previewing(): bool {
    return preview_role() !== null;
}

function user_role(): string {
    return preview_role() ?? actual_role();
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

        // An admin previewing a lesser role would otherwise be trapped here:
        // the page refuses them, and the preview bar that would let them
        // switch back never renders. Give them the way out.
        if (is_previewing()) {
            $back = 'preview_role.php?role=clear&return=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/app.php');
            exit(
                '<!doctype html><meta charset="utf-8">'
                . '<title>Preview: access denied</title>'
                . '<body style="font:14px system-ui;margin:3rem auto;max-width:34rem;color:#222">'
                . '<h1 style="font-size:1.1rem">This page is not available to the '
                . htmlspecialchars(ucfirst(user_role()), ENT_QUOTES) . ' role.</h1>'
                . '<p>That is the correct behaviour — you are previewing, and this page requires '
                . htmlspecialchars($needed, ENT_QUOTES) . '.</p>'
                . '<p><a href="' . htmlspecialchars($back, ENT_QUOTES) . '">Exit preview and return</a></p>'
                . '</body>'
            );
        }

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

/**
 * Validates a post-login destination.
 *
 * Must preserve the QUERY STRING, not just the path: EAER is reached via
 * eaer.php?token=<48 hex>, and an earlier version of this check ran the
 * value through parse_url(..., PHP_URL_PATH) and threw the query away —
 * which would have silently dropped the token and landed contributors on an
 * error page. Deep links are EAER's primary entry point.
 *
 * Restricted to same-origin relative paths. An unchecked value here is an
 * open redirect handing an attacker's page a freshly authenticated session.
 */
function saml_safe_return(?string $candidate): string {
    $default = '/app.php';
    if (!is_string($candidate) || $candidate === '') {
        return $default;
    }

    $parts = parse_url($candidate);
    if ($parts === false) {
        return $default;
    }
    // Catches both https://evil.com/x and protocol-relative //evil.com/x.
    if (isset($parts['host']) && $parts['host'] !== ($_SERVER['HTTP_HOST'] ?? '')) {
        return $default;
    }
    if (isset($parts['scheme']) && !in_array($parts['scheme'], ['http', 'https'], true)) {
        return $default;
    }

    $path = $parts['path'] ?? '';
    if ($path === '' || $path[0] !== '/' || str_contains($path, '..')) {
        return $default;
    }
    if (!preg_match('#^/[A-Za-z0-9_\-./]*$#', $path)) {
        return $default;
    }

    // Rebuild the query from parsed pairs rather than passing the raw string
    // through, so only well-formed parameters survive.
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $q);
        if ($q) {
            return $path . '?' . http_build_query($q);
        }
    }
    return $path;
}
