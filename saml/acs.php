<?php
/**
 * Assertion Consumer Service — where Entra POSTs the signed assertion.
 *
 * Identity model (see DEPLOYMENT.md "SSO"):
 *   - `objectid` is the durable key. UTS warned that UPN is NOT permanent in
 *     their environment (it changes on a name or role change), so UPN and
 *     email are both unsafe as long-term identifiers.
 *   - `mail` (@utsa.edu) is used ONCE, on first login, to match the assertion
 *     to an existing `employees` row — it is the only field the table has in
 *     common with the assertion. After that match, entra_object_id is stored
 *     and every later login keys off it, so a subsequent UPN or email change
 *     does not orphan the account or its audit history.
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

$upn = claim($attrs, [
    'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/upn',
    'upn', 'userprincipalname',
]);

$displayName = claim($attrs, [
    'http://schemas.microsoft.com/identity/claims/displayname',
    'displayname', 'name',
]);

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

// Durable key first.
$stmt = $pdo->prepare("SELECT TOP (1) id, first_name, last_name, email FROM employees WHERE entra_object_id = ?");
$stmt->execute([$objectId]);
$employee = $stmt->fetch();

// First login for this account: fall back to the one-time email match, then
// record the object id so this branch is never needed again for this person.
if (!$employee && $mail) {
    $stmt = $pdo->prepare("SELECT TOP (1) id, first_name, last_name, email FROM employees WHERE email = ?");
    $stmt->execute([$mail]);
    $employee = $stmt->fetch();

    if ($employee) {
        $pdo->prepare("UPDATE employees SET entra_object_id = ? WHERE id = ?")
            ->execute([$objectId, $employee['id']]);
    }
}

// Authenticated by UTS but not in the directory. Deliberately refused rather
// than auto-created: employees rows carry site roles and VP-area assignments
// that a self-created stub would not have, and silently creating them would
// corrupt the directory this app exists to keep accurate.
if (!$employee) {
    http_response_code(403);
    error_log(sprintf('SAML: no employees row for objectId=%s mail=%s upn=%s', $objectId, (string)$mail, (string)$upn));
    http_response_code(403);
    exit(
        'You signed in successfully, but your account is not in the Website Governance directory yet. '
        . 'Please contact the Web Team to be added.'
    );
}

// Session fixation: the pre-login session id may have been supplied by an
// attacker, so issue a fresh one at the moment privileges change.
session_regenerate_id(true);

$_SESSION['auth']            = true;
$_SESSION['employee_id']     = (int)$employee['id'];
$_SESSION['entra_object_id'] = $objectId;
$_SESSION['user_email']      = $employee['email'];
$_SESSION['user_name']       = $displayName ?: trim($employee['first_name'] . ' ' . $employee['last_name']);
$_SESSION['saml_name_id']    = $auth->getNameId();
$_SESSION['saml_session_ix'] = $auth->getSessionIndex();

// RelayState decides where we land. Restrict to same-origin paths: an
// unchecked value here is an open redirect carrying a fresh session.
$dest = '/app.php';
if (!empty($_POST['RelayState'])) {
    $path = parse_url((string)$_POST['RelayState'], PHP_URL_PATH) ?: '/app.php';
    $host = parse_url((string)$_POST['RelayState'], PHP_URL_HOST);
    if (($host === null || $host === $_SERVER['HTTP_HOST'])
        && preg_match('#^/[A-Za-z0-9_\-./]*$#', $path) && !str_contains($path, '..')) {
        $dest = $path;
    }
}

header('Location: ' . $dest);
exit;
