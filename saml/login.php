<?php
/**
 * Starts an SSO login. Optionally carries a RelayState so the user lands
 * where they were heading rather than always on app.php.
 */
require_once __DIR__ . '/_init.php';

// Only allow same-origin relative paths as a return target — an open
// redirect here would let a crafted link bounce an authenticated user to
// an attacker's page carrying their session.
$return = (string)($_GET['return'] ?? '/app.php');
if (!preg_match('#^/[A-Za-z0-9_\-./]*$#', $return) || str_contains($return, '..')) {
    $return = '/app.php';
}

saml_auth()->login('https://' . $_SERVER['HTTP_HOST'] . $return);
