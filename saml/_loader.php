<?php
/**
 * Loads the SAML toolkit.
 *
 * The library is vendored into the repo rather than installed with Composer.
 * WEBGOV has no package manager today, and adding one puts another update
 * surface on a Category 2 host for the sake of a single dependency. The
 * tradeoff is real and worth stating: security updates to php-saml are now
 * something to watch for deliberately, not something `composer update`
 * surfaces. Check https://github.com/SAML-Toolkits/php-saml/releases when
 * doing other patching rounds.
 *
 * To switch to Composer later, replace the require below with
 *   require_once __DIR__ . '/../vendor/autoload.php';
 * and nothing else in this directory needs to change.
 */

$toolkit = __DIR__ . '/lib/php-saml/_toolkit_loader.php';

if (!is_file($toolkit)) {
    http_response_code(500);
    exit(
        "SAML toolkit not installed.\n\n" .
        "Install it on the server with:\n" .
        "  cd C:\\websites\\governance\\saml\n" .
        "  git clone --depth 1 --branch 4.2.0 https://github.com/SAML-Toolkits/php-saml.git lib\\php-saml\n\n" .
        "Pin a tag rather than tracking a branch, so the vendored version is " .
        "an explicit choice visible in git history."
    );
}

require_once $toolkit;
