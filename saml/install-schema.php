<?php
/**
 * One-off schema change for SSO. CLI only — this is DDL and has no business
 * being reachable over HTTP.
 *
 * Run on WEBGOV:
 *   C:\PHP\php.exe C:\websites\governance\saml\install-schema.php
 *
 * Idempotent: safe to re-run.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';

$pdo = new PDO(
    'sqlsrv:Server=' . DB_HOST . ';Database=' . DB_NAME,
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// NVARCHAR(64) is ample for an Entra object id (a 36-char GUID). Nullable,
// because rows only gain one when that person first signs in. UNIQUE so two
// employees can never claim the same Entra account — a filtered index is
// used so the many NULLs don't collide under SQL Server's rule that NULLs
// conflict in a plain unique constraint.
$steps = [
    "IF COL_LENGTH('employees','entra_object_id') IS NULL
        ALTER TABLE employees ADD entra_object_id NVARCHAR(64) NULL",

    "IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_employees_entra_object_id')
        CREATE UNIQUE INDEX UX_employees_entra_object_id
            ON employees(entra_object_id)
            WHERE entra_object_id IS NOT NULL",
];

foreach ($steps as $sql) {
    $pdo->exec($sql);
    echo "ok: ", preg_replace('/\s+/', ' ', substr(trim($sql), 0, 70)), "...\n";
}

echo "\nDone. employees.entra_object_id is ready.\n";
