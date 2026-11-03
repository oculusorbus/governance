<?php
/**
 * Starts an SSO login, carrying the caller's destination as RelayState so
 * the user lands where they were heading rather than always on app.php.
 */
require_once __DIR__ . '/_init.php';

$return = saml_safe_return($_GET['return'] ?? null);

saml_auth()->login('https://' . $_SERVER['HTTP_HOST'] . $return);
