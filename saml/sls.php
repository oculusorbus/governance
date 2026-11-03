<?php
/**
 * Single logout. Clears the local session, then lets the IdP finish.
 */
require_once __DIR__ . '/_init.php';

try {
    saml_auth()->processSLO(false, null, false, function () {
        $_SESSION = [];
        session_destroy();
    });
    $errors = saml_auth()->getErrors();
    if (!empty($errors)) {
        error_log('SAML SLO error: ' . implode(', ', $errors));
    }
} catch (Exception $e) {
    error_log('SAML SLO exception: ' . $e->getMessage());
}

header('Location: /index.php');
exit;
