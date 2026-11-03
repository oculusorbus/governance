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

$auth = saml_auth();

try {
    $auth->processResponse();
} catch (Exception $e) {
    http_response_code(400);
    error_log('SAML ACS exception: ' . $e->getMessage());
    exit('Could not process the sign-in response.');
}

$errors = $auth->getErrors();
if (!empty($errors)) {
    http_response_code(400);
    // Detail goes to the log, not the browser: getLastErrorReason() can echo
    // back parts of the assertion.
    error_log('SAML ACS error: ' . implode(', ', $errors) . ' | ' . $auth->getLastErrorReason());
    exit('Sign-in failed. Please try again, or contact the Web Team if it persists.');
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

if ($displayName === null) {
    $given   = claim($attrs, ['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname', 'givenname']);
    $surname = claim($attrs, ['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname', 'surname']);
    $displayName = trim((string)$given . ' ' . (string)$surname) ?: null;
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
    echo "  displayName : ", var_export($displayName, true), "\n\n";
    echo "All attributes as sent:\n";
    print_r($attrs);
    exit;
}

if ($objectId === null || $objectId === '') {
    http_response_code(403);
    error_log('SAML: assertion carried no object identifier');
    exit('Sign-in failed: no unique identifier was provided. Contact the Web Team.');
}

try {
    $pdo = new PDO(
        'sqlsrv:Server=' . DB_HOST . ';Database=' . DB_NAME,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    error_log('SAML ACS DB error: ' . $e->getMessage());
    exit('Sign-in failed: database unavailable.');
}

// Durable key. Never look a user up by email or UPN — both can change.
$stmt = $pdo->prepare("SELECT TOP (1) * FROM users WHERE entra_object_id = ?");
$stmt->execute([$objectId]);
$user = $stmt->fetch();

if ($user) {
    // Refresh the cosmetic fields; a name or address change must never
    // affect identity, which is anchored on entra_object_id.
    $pdo->prepare("UPDATE users SET email = ?, display_name = ?, last_login_at = SYSUTCDATETIME() WHERE id = ?")
        ->execute([$mail, $displayName, $user['id']]);
    $user['email']        = $mail;
    $user['display_name'] = $displayName;
} else {
    // First sign-in. Link to a directory row if one shares this address —
    // a convenience for "my sites", not a requirement. 22 employees have no
    // email at all, so a null link is an ordinary outcome, not an error.
    $employeeId = null;
    if ($mail) {
        $stmt = $pdo->prepare("SELECT TOP (1) id FROM employees WHERE email = ?");
        $stmt->execute([$mail]);
        $employeeId = $stmt->fetchColumn() ?: null;
    }

    $pdo->prepare(
        "INSERT INTO users (entra_object_id, email, display_name, employee_id, role, last_login_at)
         VALUES (?, ?, ?, ?, 'viewer', SYSUTCDATETIME())"
    )->execute([$objectId, $mail, $displayName, $employeeId]);

    $stmt = $pdo->prepare("SELECT TOP (1) * FROM users WHERE entra_object_id = ?");
    $stmt->execute([$objectId]);
    $user = $stmt->fetch();

    error_log(sprintf(
        'SAML: provisioned user id=%d email=%s employee_id=%s role=viewer',
        $user['id'], (string)$mail, $employeeId === null ? 'none' : (string)$employeeId
    ));
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

header('Location: ' . saml_safe_return($_POST['RelayState'] ?? null));
exit;
