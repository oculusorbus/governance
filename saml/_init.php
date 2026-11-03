<?php
/**
 * Shared bootstrap for the SAML endpoints: session, toolkit, settings.
 */
session_start();
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/_loader.php';

$settingsFile = __DIR__ . '/settings.php';
if (!is_file($settingsFile)) {
    http_response_code(500);
    exit('saml/settings.php is missing. Copy settings.example.php and fill it in.');
}

/** @var array $SAML_SETTINGS */
$SAML_SETTINGS = require $settingsFile;

function saml_auth(): OneLogin\Saml2\Auth {
    global $SAML_SETTINGS;
    static $auth = null;
    if ($auth === null) {
        $auth = new OneLogin\Saml2\Auth($SAML_SETTINGS);
    }
    return $auth;
}

// saml_safe_return() lives in auth.php so pages outside this directory
// can validate a redirect target without loading the SAML toolkit.
