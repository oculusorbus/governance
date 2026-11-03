<?php
/**
 * Schema for SSO identity. CLI only — DDL has no business over HTTP.
 *
 *   C:\PHP\php.exe C:\websites\governance\saml\install-schema.php
 *
 * Idempotent: safe to re-run.
 *
 * Design note. `users` and `employees` are deliberately separate tables:
 *
 *   employees  directory CONTENT — site owners, tech leads, admin contacts.
 *              22 rows have no email at all and several are external
 *              contractors who have no VPN and will never log in. They
 *              belong in the directory as contacts; they can never be users.
 *
 *   users      IDENTITY — anyone who authenticates. Created on first SSO.
 *
 * They overlap but neither contains the other, so users.employee_id is a
 * NULLABLE convenience link (matched on email once, at first login), never
 * a precondition for access.
 *
 * Both live in the governance database on purpose. An earlier plan put
 * identity in its own database; SQL Server cannot enforce foreign keys
 * across databases, which would have left the audit trail's referential
 * integrity to application discipline. EAER stays independent by CAPTURING
 * identity on its records rather than joining to this table — which is also
 * what an audit trail should do, since a record must still name who acted
 * even if the account is later deleted.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../config.php';

$pdo = new PDO(
    'sqlsrv:Server=' . DB_HOST . ';Database=' . DB_NAME,
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$steps = [

// Supersedes an earlier attempt that hung entra_object_id off employees.
// That conflated directory content with identity — see the note above.
"IF EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_employees_entra_object_id')
    DROP INDEX UX_employees_entra_object_id ON employees",

"IF COL_LENGTH('employees','entra_object_id') IS NOT NULL
    ALTER TABLE employees DROP COLUMN entra_object_id",

// role: every employee can authenticate (UTS restricts the Entra app to
// employees), so authentication alone grants nothing beyond read access.
// New users land on 'viewer' and an admin elevates them deliberately.
"IF OBJECT_ID('users','U') IS NULL
    CREATE TABLE users (
        id              INT IDENTITY(1,1) PRIMARY KEY,
        entra_object_id NVARCHAR(64)  NOT NULL,
        email           NVARCHAR(255) NULL,
        display_name    NVARCHAR(255) NULL,
        employee_id     INT           NULL,
        role            NVARCHAR(20)  NOT NULL CONSTRAINT DF_users_role DEFAULT 'viewer',
        is_active       BIT           NOT NULL CONSTRAINT DF_users_active DEFAULT 1,
        created_at      DATETIME2     NOT NULL CONSTRAINT DF_users_created DEFAULT SYSUTCDATETIME(),
        last_login_at   DATETIME2     NULL,
        CONSTRAINT UX_users_entra_object_id UNIQUE (entra_object_id),
        CONSTRAINT CK_users_role CHECK (role IN ('viewer','editor','admin')),
        CONSTRAINT FK_users_employee FOREIGN KEY (employee_id) REFERENCES employees(id)
    )",

// Added after the table shipped, so guarded rather than inlined above.
// The assertion carries givenname and surname as separate claims; storing
// only display_name would be lossy, since splitting a rendered name later
// cannot reliably recover middle names, suffixes, multi-word surnames, or
// a "Surname, Given" ordering. employees is keyed on first/last name, so
// the parts are what a future directory-entry match or create would need.
"IF COL_LENGTH('users','first_name') IS NULL
    ALTER TABLE users ADD first_name NVARCHAR(100) NULL",

"IF COL_LENGTH('users','last_name') IS NULL
    ALTER TABLE users ADD last_name NVARCHAR(100) NULL",

// Lookup path for the one-time email match at first login.
"IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_users_email')
    CREATE INDEX IX_users_email ON users(email)",

];

foreach ($steps as $sql) {
    $pdo->exec($sql);
    echo "ok: ", preg_replace('/\s+/', ' ', substr(trim($sql), 0, 72)), "...\n";
}

$count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
echo "\nDone. users table ready ({$count} row(s)).\n";
echo "Remember: the first person to sign in lands on 'viewer' like everyone\n";
echo "else. Promote yourself directly in SQL to bootstrap the first admin:\n";
echo "  UPDATE users SET role = 'admin' WHERE email = 'you@utsa.edu';\n";
