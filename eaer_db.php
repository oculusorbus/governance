<?php
/**
 * Shared PDO connector + schema bootstrap + field definitions for the
 * Electronic and Information Resources (EIR) Accessibility Exception
 * Request (EAER) prototype. See HOP 11.10 and 1 TAC 213.37.
 */
require_once __DIR__ . '/config.php';

function eaer_pdo(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $pdo = new PDO(
        // Deliberately separate database from governance's DB_NAME — EAER is
        // an independent compliance/audit-trail system, not site-directory
        // data. Shares the same login/instance (EAER_DB_NAME set in config.php).
        'sqlsrv:Server=' . DB_HOST . ';Database=' . EAER_DB_NAME,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $pdo->exec("
        IF OBJECT_ID('eaer_requests','U') IS NULL
        CREATE TABLE eaer_requests (
            id                          INT IDENTITY(1,1) PRIMARY KEY,
            token                       NVARCHAR(64) NOT NULL UNIQUE,
            status                      NVARCHAR(20) NOT NULL DEFAULT 'draft',
            eir_name                    NVARCHAR(255),
            requisite_number            NVARCHAR(100),
            description_use             NVARCHAR(MAX),
            eir_type                    NVARCHAR(100),
            eir_type_other              NVARCHAR(MAX),
            vendor_name                 NVARCHAR(255),
            is_renewal                  NVARCHAR(10),
            requester_name              NVARCHAR(255),
            requester_dept              NVARCHAR(255),
            requester_email             NVARCHAR(255),
            requester_phone             NVARCHAR(50),
            justification_reasons       NVARCHAR(MAX),
            justification_other         NVARCHAR(MAX),
            supporting_info             NVARCHAR(MAX),
            alternatives_considered     NVARCHAR(MAX),
            est_cost                    NVARCHAR(MAX),
            cost_not_estimated_explain  NVARCHAR(MAX),
            resource_impact             NVARCHAR(MAX),
            remediation_timeline        NVARCHAR(MAX),
            timeline_not_planned_explain NVARCHAR(MAX),
            planned_compliance_date     NVARCHAR(100),
            no_date_explain             NVARCHAR(MAX),
            other_relevant_info         NVARCHAR(MAX),
            user_types                  NVARCHAR(255),
            est_users_per_year          NVARCHAR(100),
            course_info                 NVARCHAR(MAX),
            eval_date                   NVARCHAR(100),
            evaluator_name              NVARCHAR(255),
            eval_results                NVARCHAR(MAX),
            alt_access_description      NVARCHAR(MAX),
            alt_access_time_expense     NVARCHAR(MAX),
            alt_access_resources        NVARCHAR(MAX),
            alt_access_responsible      NVARCHAR(MAX),
            created_at                  DATETIME2 DEFAULT SYSUTCDATETIME(),
            exported_at                 DATETIME2 NULL
        )
    ");

    $pdo->exec("
        IF OBJECT_ID('eaer_contributions','U') IS NULL
        CREATE TABLE eaer_contributions (
            id                INT IDENTITY(1,1) PRIMARY KEY,
            eaer_id           INT NOT NULL,
            section_key       NVARCHAR(100) NOT NULL,
            contributor_name  NVARCHAR(255) NOT NULL,
            updated_at        DATETIME2 DEFAULT SYSUTCDATETIME(),
            CONSTRAINT UQ_eaer_section_contrib UNIQUE (eaer_id, section_key, contributor_name),
            FOREIGN KEY (eaer_id) REFERENCES eaer_requests(id)
        )
    ");

    // Schema evolution for already-deployed eaer_requests tables — the
    // CREATE TABLE guard above only fires once, so later columns need an
    // explicit ALTER guard (same pattern app.php uses for `employees`).
    $pdo->exec("
        IF COL_LENGTH('eaer_requests','eir_type_other') IS NULL
            ALTER TABLE eaer_requests ADD eir_type_other NVARCHAR(MAX) NULL
    ");
    // Widen if an earlier version of this code already added it as NVARCHAR(255).
    $pdo->exec("
        IF COL_LENGTH('eaer_requests','eir_type_other') = 255
            ALTER TABLE eaer_requests ALTER COLUMN eir_type_other NVARCHAR(MAX) NULL
    ");

    return $pdo;
}

/**
 * Section/field definitions driving the form, the API's whitelist of
 * savable columns, and the export view. Keep in one place so all three
 * stay in sync.
 */
function eaer_sections(): array {
    return [
        'requester_info' => [
            'title'  => 'Requester Information',
            'fields' => [
                'requester_name'  => ['label' => 'Requester Name',   'type' => 'text'],
                'requester_dept'  => ['label' => 'Department Name',  'type' => 'text'],
                'requester_email' => ['label' => 'Email',            'type' => 'text'],
                'requester_phone' => ['label' => 'Phone',            'type' => 'text'],
            ],
        ],
        'description_eir' => [
            'title'  => 'Description of the EIR (Electronic and Information Resource)',
            'fields' => [
                'eir_name'         => ['label' => 'Enter the Name of the Software, Hardware, or Other Resource (EIR)', 'type' => 'text'],
                'requisite_number' => ['label' => 'Requisition Number', 'type' => 'text'],
                'description_use'  => ['label' => 'Description and Use of Tool', 'type' => 'textarea'],
                'eir_type'         => [
                    'label'   => 'Type',
                    'type'    => 'radio',
                    'layout'  => 'stacked',
                    'options' => ['Software Application', 'IT Hardware or Office Equipment', 'Other'],
                ],
                'eir_type_other'   => [
                    'label'    => 'If "Other," describe',
                    'type'     => 'textarea',
                    'showWhen' => ['field' => 'eir_type', 'equals' => 'Other'],
                ],
                'vendor_name'      => ['label' => 'Name of Vendor, Agency, or Third Party', 'type' => 'text'],
                'is_renewal'       => ['label' => 'Is this EIR a contract or subscription renewal?', 'type' => 'radio', 'options' => ['Yes', 'No']],
            ],
        ],
        'justification' => [
            'title'  => 'Justification for Exception',
            'fields' => [
                'justification_reasons' => [
                    'label' => 'Reason(s) for requesting this exception',
                    'type'  => 'checkboxes',
                    'options' => [
                        'Adequate skilled resources unavailable',
                        'Nearing end of life cycle',
                        'Underlying EIR technology platform not accessible',
                        'Large programming impact',
                        'Marketplace exception (sole source of EIR)',
                        'Cost prohibitive',
                        'Fundamental alteration',
                        'Other',
                    ],
                ],
                'justification_other' => [
                    'label'    => 'If "Other," describe',
                    'type'     => 'textarea',
                    'showWhen' => ['field' => 'justification_reasons', 'equals' => 'Other'],
                ],
                'supporting_info'     => ['label' => 'Supporting information to justify the exception', 'type' => 'textarea'],
                'alternatives_considered' => [
                    'label' => 'Accessible alternatives considered, and why they were not selected',
                    'type'  => 'textarea',
                    'note'  => 'Added per 1 TAC 213.37 documentation expectations, not in the original draft form.',
                ],
                'est_cost'                    => ['label' => 'Estimated cost of bringing the EIR into compliance (development cost, time, etc.)', 'type' => 'textarea'],
                'cost_not_estimated_explain'  => ['label' => 'If no cost estimate was completed, explain', 'type' => 'textarea'],
                'resource_impact' => [
                    'label' => 'Impact on program/department resources if this exception is not granted',
                    'type'  => 'textarea',
                    'note'  => 'Added because 1 TAC 213.37 asks institutions to weigh all resources available to the program, not only the isolated remediation cost.',
                ],
                'remediation_timeline'         => ['label' => 'Remediation timeline', 'type' => 'textarea'],
                'timeline_not_planned_explain' => ['label' => 'If no timeline is planned, explain', 'type' => 'textarea'],
                'planned_compliance_date'      => ['label' => 'Planned accessibility compliance date', 'type' => 'text'],
                'no_date_explain'              => ['label' => 'If no date is planned, explain', 'type' => 'textarea'],
                'other_relevant_info'          => ['label' => 'Other relevant information', 'type' => 'textarea'],
            ],
        ],
        'user_info' => [
            'title'  => 'User Information',
            'fields' => [
                'user_types' => [
                    'label' => 'Type of users',
                    'type'  => 'checkboxes',
                    'options' => ['Faculty', 'Staff', 'Students', 'Members of the Public'],
                    'note'  => 'If "Students" is selected, route to Student Disability Services for input (see HOP 11.10 roles).',
                ],
                'est_users_per_year' => ['label' => 'Estimated number of potential users over 1 year', 'type' => 'text'],
                'course_info'        => ['label' => 'If for academic purposes, course name(s)/number(s)', 'type' => 'textarea'],
            ],
        ],
        'alt_compliance' => [
            'title'  => 'Alternative Compliance Methods',
            'fields' => [
                'eval_date'      => ['label' => 'Date of Accessibility Evaluation', 'type' => 'text'],
                'evaluator_name' => ['label' => 'Name of Evaluator', 'type' => 'text'],
                'eval_results'   => ['label' => 'Accessibility Evaluation Results', 'type' => 'textarea'],
                'alt_access_description' => ['label' => 'Describe the alternative means of access', 'type' => 'textarea'],
                'alt_access_time_expense' => ['label' => 'Time and expense to implement the alternative means of access', 'type' => 'textarea'],
                'alt_access_resources'    => ['label' => 'Resources needed for alternative means of access', 'type' => 'textarea'],
                'alt_access_responsible'  => ['label' => 'Persons responsible for implementation of the alternative means of access', 'type' => 'textarea'],
            ],
        ],
    ];
}

/** Static legislation list rendered on the form/export — not stored per-record. */
function eaer_legislation(): array {
    return [
        'Section 504 of the Rehabilitation Act of 1973',
        'Americans with Disabilities Act (ADA), Title II: 28 C.F.R. Part 35, Subpart H (Web and Mobile Accessibility; WCAG 2.1 Level AA); compliance date extended by interim final rule to April 26, 2027 for entities serving populations of 50,000+',
        'UTSA Handbook of Operating Procedures (HOP) 11.10: Web and Digital Accessibility Compliance',
        'UT System Policy 150: Access by Persons with Disabilities to Electronic and Information Resources',
        'Texas Administrative Code (TAC), 1 TAC 213.37: Compliance Exceptions and Exemptions',
        'Texas Government Code § 2054.460',
    ];
}

function eaer_gen_token(): string {
    return bin2hex(random_bytes(24));
}

/**
 * Shared <head> assets: UTSA brand fonts + the skip-link/focus-visible
 * accessibility pattern, copied verbatim from app.php's proven WCAG 2.1 AA
 * pass so the EAER pages inherit the same contrast-checked palette rather
 * than re-deriving it.
 */
function eaer_head_assets(): void {
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Arsenal:wght@400;700&family=Libre+Franklin:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Libre Franklin', system-ui, sans-serif; }
        .font-brand { font-family: 'Arsenal', system-ui, sans-serif; }

        /* ── Accessibility: skip link + focus visibility (from app.php) ── */
        .skip-link {
            position:absolute; left:8px; top:-40px; z-index:1000;
            background:#032044; color:#fff; padding:8px 14px; border-radius:0 0 6px 6px;
            font-size:13px; font-weight:600; text-decoration:none; transition:top .15s;
        }
        .skip-link:focus { top:0; }
        :focus-visible {
            outline:2px solid #265BF7 !important;
            outline-offset:2px;
        }
    </style>
    <?php
}
