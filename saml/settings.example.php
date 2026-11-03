<?php
/**
 * Template for saml/settings.php.
 *
 * The IdP values below are REAL and committed deliberately — they are the
 * same data Microsoft publishes in the tenant's federation metadata
 * (see the IdP metadata XML in this folder), nothing authenticates with
 * them, and keeping them in git means the server only has to copy this file
 * rather than re-key values by hand:
 *
 *     copy saml\\settings.example.php saml\\settings.php
 *
 * settings.php itself is gitignored because it is where an SP private key
 * would live if request signing is ever enabled, and that distinction should
 * not depend on someone remembering it at the time.
 */

return [
    'strict' => true,
    'debug'  => false,

    'sp' => [
        // Entity ID is an identifier, not necessarily a fetchable URL. This is
        // the value given to UTS when the Entra app was registered, so it must
        // not change without telling them.
        'entityId' => 'https://webgov.utsarr.net/saml/metadata',
        'assertionConsumerService' => [
            'url'     => 'https://webgov.utsarr.net/saml/acs.php',
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
        ],
        'singleLogoutService' => [
            'url'     => 'https://webgov.utsarr.net/saml/sls.php',
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
        'x509cert'     => '',
        'privateKey'   => '',
    ],

    'idp' => [
        'entityId' => 'https://sts.windows.net/3a228dfb-c647-44cb-8835-7b20617fc906/',
        'singleSignOnService' => [
            'url'     => 'https://login.microsoftonline.com/3a228dfb-c647-44cb-8835-7b20617fc906/saml2',
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        'singleLogoutService' => [
            'url'     => 'https://login.microsoftonline.com/3a228dfb-c647-44cb-8835-7b20617fc906/saml2',
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        // The <X509Certificate> inside <KeyDescriptor use="signing"> in the
        // IdP federation metadata XML. Public key only — base64, no PEM
        // header/footer, no line breaks.
        'x509cert' =>
            'MIIC8DCCAdigAwIBAgIQMwLgBzA1DIpM+rX+yYyiyDANBgkqhkiG9w0BAQsFADA0' .
            'MTIwMAYDVQQDEylNaWNyb3NvZnQgQXp1cmUgRmVkZXJhdGVkIFNTTyBDZXJ0aWZp' .
            'Y2F0ZTAeFw0yNjExMDIxNzEwMDZaFw0yOTExMDIxNjEwMDZaMDQxMjAwBgNVBAMT' .
            'KU1pY3Jvc29mdCBBenVyZSBGZWRlcmF0ZWQgU1NPIENlcnRpZmljYXRlMIIBIjAN' .
            'BgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA1sYkuy3A+E1nOLvNJ96I5RJSZ76k' .
            'hgGwMHs8emqzLMn1CcZhQnuCNp5CqHvGe+Mxheg5za9vHAVnxMNFSpLw7PfLx041' .
            '16TFq47UOA9Mv+OZLSfRBnwPtwZBYyZLAR6aJ5O0SVuiZ8jJaofmK3+jRKMlDkxB' .
            'TzaFgUNcsKh4mAyexSPF043UfHrMiU7yBAQVSK4lCCjpBRjrMDGjTPX+rvRcFj1F' .
            'OjmjPWQQVNHwSHjZAZS3ftQm96nSjEvV1vuE0i/nVZeFll2YKIQiqSymUNhKmcyT' .
            'p9x9nIbW1T3Fy2+Hu9OOc6rysBkZzw1w4B3toTTaxQTziCkT59hwkUQDfQIDAQAB' .
            'MA0GCSqGSIb3DQEBCwUAA4IBAQBF2IOBxxePlWPE4mXyECvukhUPG7KOXpa10Fxo' .
            'gOcTpcxxixxwC2Xpc5ZGdxI09co4CQq8I0KcMlKlKKeqfbbISZ446pAxTOAtgIJd' .
            'fc1LDv+FrbAbVNfgJhpmQuqA5Hk1Ukk3di0SYtQTauzB6MDYSoNqVZ9Zotdum2+a' .
            '4pr0TpB0pXXim+yi6jpYeQ9NkKMTiuYrb7fBAdCK7gHccnbRCSGh0AjCUIaDjHaj' .
            '6LZFRcAeuPNXzx/py8JlJYiktWZXvlYPbdIXlO1p5kVRjhXuvwAfoOpACJusgTVD' .
            'vtt/azyGhWSJjfQ2VbVqiNzqJkhq3azF2bS2/0iqyc+EiwGg',
    ],

    'security' => [
        'wantAssertionsSigned'  => true,
        'wantMessagesSigned'    => false,
        'requestedAuthnContext' => false,
    ],
];
