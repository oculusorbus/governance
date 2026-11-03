<?php
/**
 * Shared bootstrap for the SAML endpoints: session, toolkit, settings.
 */
session_start();
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
