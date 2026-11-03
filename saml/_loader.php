<?php
/**
 * Autoloads the SAML toolkit and its dependency.
 *
 * The libraries are vendored rather than installed with Composer: WEBGOV has
 * no package manager, and adding one puts another update surface on a
 * Category 2 host for the sake of two dependencies.
 *
 * The real cost of that choice is here. php-saml does not ship its
 * dependency — it requires robrichards/xmlseclibs, which Composer would
 * resolve automatically and which must otherwise be vendored alongside it.
 * php-saml's own _toolkit_loader.php only registers its own classes, so a
 * clone of php-saml alone fails at runtime with
 * "Class RobRichards\XMLSecLibs\XMLSecurityKey not found" — not at startup,
 * but at the first attempt to build Settings.
 *
 * So rather than depending on the toolkit's loader, both namespaces are
 * mapped explicitly below. Adding a third library means adding a line here.
 *
 * VERSION PINNING: php-saml 4.x requires xmlseclibs ^3.1 — the xmlseclibs
 * 4.x line dropped compatibility and will NOT work here. Keep xmlseclibs on
 * 3.1.x unless php-saml's own constraint changes.
 *
 * Security updates to both are now a deliberate thing to watch rather than
 * something `composer update` surfaces. Check these when doing other
 * patching rounds:
 *   https://github.com/SAML-Toolkits/php-saml/releases
 *   https://github.com/robrichards/xmlseclibs/releases
 *
 * To move to Composer later, replace this whole file's body with
 *   require_once __DIR__ . '/../vendor/autoload.php';
 * and nothing else in this directory changes.
 */

const SAML_VENDOR_MAP = [
    'OneLogin\\Saml2\\'         => '/lib/php-saml/src/Saml2/',
    'RobRichards\\XMLSecLibs\\' => '/lib/xmlseclibs/src/',
];

foreach (SAML_VENDOR_MAP as $prefix => $dir) {
    if (!is_dir(__DIR__ . $dir)) {
        http_response_code(500);
        $lib = explode('/', trim($dir, '/'))[1];
        exit(
            "SAML dependency '{$lib}' is not installed ({$dir} missing).\n\n" .
            "Install both on the server, pinning tags rather than branches:\n" .
            "  cd C:\\websites\\governance\\saml\n" .
            "  git clone --depth 1 --branch 4.2.0 https://github.com/SAML-Toolkits/php-saml.git lib\\php-saml\n" .
            "  git clone --depth 1 --branch 3.1.5 https://github.com/robrichards/xmlseclibs.git lib\\xmlseclibs\n"
        );
    }
}

spl_autoload_register(function (string $class): void {
    foreach (SAML_VENDOR_MAP as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        $file     = __DIR__ . $dir . $relative;
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});
