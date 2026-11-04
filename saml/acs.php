<?php
/**
 * Assertion Consumer Service — where Entra POSTs the signed assertion.
 *
 * Identity model (see DEPLOYMENT.md "SSO"):
 *   - `objectid` is the durable key. UTS warned UPN is NOT permanent in
 *     their environment, and this tenant does not release a UPN claim at
 *     all (verified against a live assertion 2026-11-03).
 *   - A `users` row is created on FIRST login, at role 'viewer'. UTS limits
 *     the Entra app to employees, so authentication proves employment and
 *     nothing more; an admin elevates deliberately. Nobody is ever refused
 *     for not being pre-registered — that would duplicate the access control
 *     UTS already maintains and put onboarding on the Web Team.
 *   - `employees` is directory CONTENT, not identity. The one-time match on
 *     `mail` links a user to their directory row when one exists, but a
 *     missing match is normal and never blocks sign-in.
 */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../config.php';

/**
 * Every failure past this point gets a short reference the user can quote,
 * with the detail going to the log rather than the browser.
 *
 * Sign-in failures are reported by people who cannot read the server log,
 * often hours later ("it 500'd around 4:17"). Without a reference, matching
 * a report to a log line means guessing from timestamps.
 */
function acs_fail(string $context, Throwable|string $detail, int $code = 500): never {
    $ref = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $msg = $detail instanceof Throwable
        ? get_class($detail) . ': ' . $detail->getMessage()
          . ' @ ' . $detail->getFile() . ':' . $detail->getLine()
        : $detail;
    error_log("SAML ACS [{$ref}] {$context}: {$msg}");
    http_response_code($code);
    exit(
        "Sign-in could not be completed.\n\n"
        . "Reference: {$ref}\n\n"
        . "Please pass that reference to the Web Team — it identifies this exact failure in the server log."
    );
}

$auth = saml_auth();

try {
    $auth->processResponse();
} catch (Throwable $e) {
    acs_fail('processResponse', $e, 400);
}

$errors = $auth->getErrors();
if (!empty($errors)) {
    // Detail goes to the log, not the browser: getLastErrorReason() can echo
    // back parts of the assertion.
    acs_fail('assertion rejected',
             implode(', ', $errors) . ' | ' . $auth->getLastErrorReason(), 400);
}

if (!$auth->isAuthenticated()) {
    http_response_code(401);
    exit('Not authenticated.');
}

$attrs = $auth->getAttributes();

/**
 * Reads a claim by any of several possible names. Entra usually sends the
 * long schema URIs, but short names appear depending on how the app is
 * configured, so try both rather than hardcoding one shape.
 */
function claim(array $attrs, array $names): ?string {
    foreach ($names as $n) {
        if (!empty($attrs[$n][0])) return (string)$attrs[$n][0];
    }
    return null;
}

$objectId = claim($attrs, [
    'http://schemas.microsoft.com/identity/claims/objectidentifier',
    'objectidentifier', 'objectid', 'oid',
]) ?: $auth->getNameId();   // Brian described objectid as "the unique
                            // identifier", which may mean it is the NameID.

$mail = claim($attrs, [
    'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
    'emailaddress', 'email', 'mail',
]);

// Confirmed 2026-11-03 against a live assertion: this tenant does NOT
// release a UPN claim, despite UTS believing it did. Kept as an optional
// lookup in case that changes; nothing depends on it. Deliberately NOT
// falling back to the 'name' claim, which carries the email here, not a UPN.
$upn = claim($attrs, [
    'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/upn',
    'upn', 'userprincipalname',
]);

// 'name' is deliberately absent from this chain. At this tenant the 'name'
// claim holds the email address, so using it as a fallback would silently
// put "jeremiah.barber@utsa.edu" in the topbar if the displayname claim ever
// went away. givenname/surname are both released and degrade sensibly.
$displayName = claim($attrs, [
    'http://schemas.microsoft.com/identity/claims/displayname',
    'displayname', 'DisplayName',
]);

// Captured in their own right, not just as a display-name fallback:
// employees is keyed on first/last name, and a rendered display name
// cannot be split back into parts reliably.
$firstName = claim($attrs, ['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname', 'givenname']);
$lastName  = claim($attrs, ['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname', 'surname']);

if ($displayName === null) {
    $displayName = trim((string)$firstName . ' ' . (string)$lastName) ?: null;
}

// ── First-run diagnostic ─────────────────────────────────────────────────
// Set SAML_DEBUG_ASSERTION in config.php for the first test login. Prints
// exactly what Entra sent, which settles whether objectid arrives as the
// NameID or as a claim, and whether mail actually came through. Turn it off
// before anyone else uses this: the dump contains real identity data.
if (defined('SAML_DEBUG_ASSERTION') && SAML_DEBUG_ASSERTION) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "SAML assertion received\n";
    echo str_repeat('=', 60), "\n\n";
    echo "NameId        : ", var_export($auth->getNameId(), true), "\n";
    echo "NameIdFormat  : ", var_export($auth->getNameIdFormat(), true), "\n";
    echo "SessionIndex  : ", var_export($auth->getSessionIndex(), true), "\n\n";
    echo "Resolved:\n";
    echo "  objectId    : ", var_export($objectId, true), "\n";
    echo "  mail        : ", var_export($mail, true), "\n";
    echo "  upn         : ", var_export($upn, true), "\n";
    echo "  displayName : ", var_export($displayName, true), "\n";
    echo "  firstName   : ", var_export($firstName, true), "\n";
    echo "  lastName    : ", var_export($lastName, true), "\n\n";
    echo "All attributes as sent:\n";
    print_r($attrs);
    exit;
}

if ($objectId === null || $objectId === '') {
    http_response_code(403);
    error_log('SAML: assertion carried no object identifier');
    exit('Sign-in failed: no unique identifier was provided. Contact the Web Team.');
}

// Everything from here to the redirect is wrapped. Previously only the
// connection was guarded, so a failure in any later query — or any PHP
// Error — became a bare 500 with nothing recorded.
try {
    $pdo = new PDO(
        'sqlsrv:Server=' . DB_HOST . ';Database=' . DB_NAME,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    acs_fail('database connection', $e);
}

try {

// Durable key. Never look a user up by email or UPN — both can change.
$stmt = $pdo->prepare("SELECT TOP (1) * FROM users WHERE entra_object_id = ?");
$stmt->execute([$objectId]);
$user = $stmt->fetch();

if ($user) {
    // Refresh the cosmetic fields; a name or address change must never
    // affect identity, which is anchored on entra_object_id.
    $pdo->prepare(
        "UPDATE users SET email = ?, display_name = ?, first_name = ?, last_name = ?,
                          last_login_at = SYSUTCDATETIME()
         WHERE id = ?"
    )->execute([$mail, $displayName, $firstName, $lastName, $user['id']]);
    $user['email']        = $mail;
    $user['display_name'] = $displayName;
    $user['first_name']   = $firstName;
    $user['last_name']    = $lastName;
} else {
    // First sign-in. Does this address already appear in the directory?
    $employeeId = null;
    if ($mail) {
        $stmt = $pdo->prepare("SELECT TOP (1) id FROM employees WHERE email = ?");
        $stmt->execute([$mail]);
        $employeeId = $stmt->fetchColumn() ?: null;
    }

    // Already recorded as a site contact — owner, tech lead, admin contact —
    // so they have demonstrable involvement and gatekeeping a read-only view
    // of a directory they are listed in is busywork. Auto-granted 'viewer'.
    //
    // Deliberately NOT 'editor'. employees is content that any editor can
    // add rows to, so auto-editor would let editors mint editors through
    // ordinary content work. Roles are global too, so a contact for one
    // site would gain edit rights over all 374 records — far wider than the
    // responsibility their listing actually evidences.
    //
    // Everyone else starts at 'pending': authenticated, approved for
    // nothing, and shown a holding page until an admin decides.
    $role = $employeeId !== null ? 'viewer' : 'pending';

    // role_granted_by NULL with role_granted_at set reads as "granted
    // automatically"; an admin's grant records their id instead.
    $pdo->prepare(
        "INSERT INTO users (entra_object_id, email, display_name, first_name, last_name,
                            employee_id, role, role_granted_by, role_granted_at, last_login_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULL, SYSUTCDATETIME(), SYSUTCDATETIME())"
    )->execute([$objectId, $mail, $displayName, $firstName, $lastName, $employeeId, $role]);

    $stmt = $pdo->prepare("SELECT TOP (1) * FROM users WHERE entra_object_id = ?");
    $stmt->execute([$objectId]);
    $user = $stmt->fetch();

    error_log(sprintf(
        'SAML: provisioned user id=%d email=%s employee_id=%s role=%s',
        $user['id'], (string)$mail,
        $employeeId === null ? 'none' : (string)$employeeId, $role
    ));
}

} catch (Throwable $e) {
    acs_fail('user provisioning', $e);
}

if (empty($user['is_active'])) {
    http_response_code(403);
    exit('Your access to this tool has been disabled. Contact the Web Team.');
}

// Session fixation: the pre-login session id may have been supplied by an
// attacker, so issue a fresh one at the moment privileges change.
session_regenerate_id(true);

$_SESSION['auth']            = true;
$_SESSION['user_id']         = (int)$user['id'];
$_SESSION['user_role']       = (string)$user['role'];
$_SESSION['user_email']      = $user['email'];
$_SESSION['user_name']       = $user['display_name'] ?: (string)$mail;
$_SESSION['employee_id']     = $user['employee_id'] !== null ? (int)$user['employee_id'] : null;
$_SESSION['entra_object_id'] = $objectId;
$_SESSION['saml_name_id']    = $auth->getNameId();
$_SESSION['saml_session_ix'] = $auth->getSessionIndex();

// A pending account goes to the holding page regardless of where it was
// heading — otherwise it would land on the requested page and bounce.
if (($user['role'] ?? '') === 'pending') {
    header('Location: /pending.php');
    exit;
}

header('Location: ' . saml_safe_return($_POST['RelayState'] ?? null));
exit;
