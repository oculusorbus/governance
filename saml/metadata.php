<?php
/**
 * Serves this SP's metadata, for UTS to consume if they ever need to
 * re-import it. The Entity ID given to UTS is /saml/metadata (no .php);
 * an Entity ID is only an identifier and need not resolve, but if you want
 * that exact URL to serve this file, add to web.config:
 *
 *   <rule name="SAML metadata" stopProcessing="true">
 *     <match url="^saml/metadata$" />
 *     <action type="Rewrite" url="saml/metadata.php" />
 *   </rule>
 */
require_once __DIR__ . '/_init.php';

try {
    $settings = new OneLogin\Saml2\Settings($SAML_SETTINGS, true);
    $metadata = $settings->getSPMetadata();
    $errors   = $settings->validateMetadata($metadata);

    if (!empty($errors)) {
        http_response_code(500);
        exit('Invalid SP metadata: ' . implode(', ', $errors));
    }

    header('Content-Type: text/xml');
    echo $metadata;
} catch (Exception $e) {
    http_response_code(500);
    exit('Metadata error: ' . $e->getMessage());
}
