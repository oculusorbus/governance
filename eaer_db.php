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
                'requester_name'  => [
                    'label' => 'Requester Name',
                    'type'  => 'text',
                    'help'  => 'The person submitting this request. This is separate from the contributor name you entered to open this form. If you are filling this out on someone else\'s behalf, put their name here.',
                ],
                'requester_dept'  => [
                    'label' => 'Department Name',
                    'type'  => 'text',
                    'help'  => 'The department or unit requesting the exception. This can differ from where the EIR will actually be used day to day.',
                ],
                'requester_email' => [
                    'label' => 'Email',
                    'type'  => 'text',
                    'help'  => 'A UTSA email address where Legal, the EIR Accessibility Coordinator (EIRAC), or an auditor can reach you with follow-up questions.',
                ],
                'requester_phone' => [
                    'label' => 'Phone',
                    'type'  => 'text',
                    'help'  => 'A phone number for time-sensitive follow-up. Include an extension if you have one.',
                ],
            ],
        ],
        'description_eir' => [
            'title'  => 'Description of the EIR (Electronic and Information Resource)',
            'fields' => [
                'eir_name'         => [
                    'label' => 'Enter the Name of the Software, Hardware, or Other Resource (EIR)',
                    'type'  => 'text',
                    'help'  => 'Use the product name as it appears on the vendor invoice or license, not an internal nickname a reviewer might not recognize.',
                ],
                'requisite_number' => [
                    'label' => 'Requisition Number',
                    'type'  => 'text',
                    'help'  => 'The purchase requisition or PO number tied to this EIR, if one exists. Leave this blank if there isn\'t one yet.',
                ],
                'description_use'  => [
                    'label' => 'Description and Use of Tool',
                    'type'  => 'textarea',
                    'help'  => 'Explain in plain language what the tool does and how UTSA staff, faculty, or students will use it. Write for someone who has never heard of this product.',
                ],
                'eir_type'         => [
                    'label'   => 'Type',
                    'type'    => 'radio',
                    'layout'  => 'stacked',
                    'options' => ['Software Application', 'IT Hardware or Office Equipment', 'Other'],
                    'help'    => 'Most requests are Software Application. IT Hardware or Office Equipment covers physical devices like copiers, kiosks, or phones. Only use Other if neither fits.',
                ],
                'eir_type_other'   => [
                    'label'    => 'If "Other," describe',
                    'type'     => 'textarea',
                    'showWhen' => ['field' => 'eir_type', 'equals' => 'Other'],
                    'help'     => 'A short description of the resource type, since Other was selected above.',
                ],
                'vendor_name'      => [
                    'label' => 'Name of Vendor, Agency, or Third Party',
                    'type'  => 'text',
                    'help'  => 'The company or organization that produces or sells this resource. If it was built in-house at UTSA, note that instead.',
                ],
                'is_renewal'       => [
                    'label'   => 'Is this a renewal of an existing contract or subscription?',
                    'type'    => 'radio',
                    'options' => ['Yes', 'No'],
                    'help'    => 'Choose Yes if UTSA already uses this EIR and is renewing an existing contract or subscription. Choose No for a brand-new acquisition.',
                ],
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
                    'help' => 'Check every reason that applies. It is common for more than one to apply, for example both Cost prohibitive and Large programming impact.',
                ],
                'justification_other' => [
                    'label'    => 'If "Other," describe',
                    'type'     => 'textarea',
                    'showWhen' => ['field' => 'justification_reasons', 'equals' => 'Other'],
                    'help'     => 'Describe the reason, since Other was checked above.',
                ],
                'supporting_info'     => [
                    'label' => 'Supporting information to justify the exception',
                    'type'  => 'textarea',
                    'help'  => 'Anything that strengthens the justification: vendor statements, accessibility conformance reports (VPATs), or prior remediation attempts and their outcome.',
                ],
                'alternatives_considered' => [
                    'label' => 'Accessible alternatives considered, and why they were not selected',
                    'type'  => 'textarea',
                    'note'  => 'Added per 1 TAC 213.37 documentation expectations, not in the original draft form.',
                    'help'  => 'List any accessible competing products you looked at before choosing this one, and briefly say why each was rejected (cost, missing features, incompatible with existing systems, etc.). If nothing else was considered, say so and explain why.',
                ],
                'est_cost'                    => [
                    'label' => 'Estimated cost of bringing the EIR into compliance (development cost, time, etc.)',
                    'type'  => 'textarea',
                    'help'  => 'A good-faith estimate in dollars, staff time, or both. A rough figure is fine if a precise number is not available.',
                ],
                'cost_not_estimated_explain'  => [
                    'label' => 'If no cost estimate was completed, explain',
                    'type'  => 'textarea',
                    'help'  => 'For example, the vendor will not quote remediation work, or the fix has not been technically scoped yet.',
                ],
                'resource_impact' => [
                    'label' => 'Impact on program/department resources if this exception is not granted',
                    'type'  => 'textarea',
                    'note'  => 'Added because 1 TAC 213.37 asks institutions to weigh all resources available to the program, not only the isolated remediation cost.',
                    'help'  => 'Describe what happens to the requesting department if this exception is denied, for example a critical function going unsupported, a contractual deadline missed, or a service becoming unavailable.',
                ],
                'remediation_timeline'         => [
                    'label' => 'Remediation timeline',
                    'type'  => 'textarea',
                    'help'  => 'A realistic timeline for fixing the accessibility issues, even if it is the vendor\'s stated timeline rather than one UTSA controls.',
                ],
                'timeline_not_planned_explain' => [
                    'label' => 'If no timeline is planned, explain',
                    'type'  => 'textarea',
                    'help'  => 'For example, the vendor has not committed to a fix, or the issue is still under evaluation.',
                ],
                'planned_compliance_date'      => [
                    'label' => 'Planned accessibility compliance date',
                    'type'  => 'date',
                    'help'  => 'The date this EIR is expected to meet accessibility standards, if known.',
                ],
                'no_date_explain'              => [
                    'label' => 'If no date is planned, explain',
                    'type'  => 'textarea',
                    'help'  => 'For example, remediation depends on a vendor roadmap outside UTSA\'s control.',
                ],
                'other_relevant_info'          => [
                    'label' => 'Other relevant information',
                    'type'  => 'textarea',
                    'help'  => 'Anything else a reviewer should know that does not fit the fields above.',
                ],
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
                    'help'  => 'Check every group who will use this EIR. This determines who else needs to weigh in, for example checking Students should prompt a conversation with Student Disability Services.',
                ],
                'est_users_per_year' => [
                    'label' => 'Estimated number of potential users over 1 year',
                    'type'  => 'text',
                    'help'  => 'A rough estimate is fine. This helps reviewers judge the scale of impact if the exception is approved or denied.',
                ],
                'course_info'        => [
                    'label' => 'If for academic purposes, course name(s)/number(s)',
                    'type'  => 'textarea',
                    'help'  => 'List the course prefix and number(s) so Academic Innovation can identify which sections are affected.',
                ],
            ],
        ],
        'alt_compliance' => [
            'title'  => 'Alternative Compliance Methods',
            'fields' => [
                'eval_date'      => [
                    'label' => 'Date of Accessibility Evaluation',
                    'type'  => 'date',
                    'help'  => 'The date an accessibility evaluation of this EIR was performed, if one was done.',
                ],
                'evaluator_name' => [
                    'label' => 'Name of Evaluator',
                    'type'  => 'text',
                    'help'  => 'Who conducted the evaluation, for example a UTSA staff member, the vendor, or a third-party auditor.',
                ],
                'eval_results'   => [
                    'label' => 'Accessibility Evaluation Results',
                    'type'  => 'textarea',
                    'help'  => 'Summarize what the evaluation found: which accessibility standards were not met and how severe the issues are.',
                ],
                'alt_access_description' => [
                    'label' => 'Describe the alternative means of access',
                    'type'  => 'textarea',
                    'help'  => 'Explain specifically how a person with a disability will still accomplish the same task, for example a staff-assisted alternative, a different accessible tool, or a manual process.',
                ],
                'alt_access_time_expense' => [
                    'label' => 'Time and expense to implement the alternative means of access',
                    'type'  => 'textarea',
                    'help'  => 'How long it will take to stand up the alternative, and any cost involved.',
                ],
                'alt_access_resources'    => [
                    'label' => 'Resources needed for alternative means of access',
                    'type'  => 'textarea',
                    'help'  => 'What staff, equipment, or budget is needed to keep the alternative available for as long as this exception is in effect.',
                ],
                'alt_access_responsible'  => [
                    'label' => 'Persons responsible for implementation of the alternative means of access',
                    'type'  => 'textarea',
                    'help'  => 'Name the specific person or role accountable for making sure the alternative actually works when someone needs it.',
                ],
            ],
        ],
    ];
}

/**
 * Static legislation list rendered on the form/export — not stored per-record.
 *
 * Each entry may carry a 'url' to the authoritative text. The form links it;
 * the export prints the URL alongside the citation, since a PDF attached to
 * the DocuSign memo can't be clicked. An entry with no 'url' renders as plain
 * text, so a citation without a stable public link degrades gracefully.
 */
function eaer_legislation(): array {
    return [
        [
            'text' => 'Section 504 of the Rehabilitation Act of 1973',
            'url'  => 'https://www.dol.gov/agencies/oasam/centers-offices/civil-rights-center/statutes/section-504-rehabilitation-act-of-1973',
        ],
        [
            'text' => 'Americans with Disabilities Act (ADA), Title II: 28 C.F.R. Part 35, Subpart H (Web and Mobile Accessibility; WCAG 2.1 Level AA); compliance date extended by interim final rule to April 26, 2027 for entities serving populations of 50,000+',
            'url'  => 'https://www.ada.gov/resources/2024-03-08-web-rule/',
        ],
        [
            'text' => 'UTSA Handbook of Operating Procedures (HOP) 11.10: Web and Digital Accessibility Compliance',
            'url'  => 'https://www.utsa.edu/hop/chapter11/11.10.html',
        ],
        [
            'text' => 'UT System Policy UTS 150: Access by Persons with Disabilities to Electronic and Information Resources',
            'url'  => 'https://www.utsystem.edu/sites/policy-library/policies/uts-150-access-persons-disabilities-electronic-and-information-resources-procured-or-developed-university-of-texas-system-administration-and-university-of-texas-system-institutions',
        ],
        [
            'text' => 'Texas Administrative Code (TAC), 1 TAC 213.37: Compliance Exceptions and Exemptions',
            'url'  => 'https://www.law.cornell.edu/regulations/texas/1-Tex-Admin-Code-SS-213-37',
        ],
        [
            'text' => 'Texas Government Code § 2054.460: Exception for Significant Difficulty or Expense; Alternate Methods',
            'url'  => 'https://statutes.capitol.texas.gov/Docs/GV/htm/GV.2054.htm#2054.460',
        ],
    ];
}

function eaer_gen_token(): string {
    return bin2hex(random_bytes(24));
}

/**
 * Resolves and applies the colour theme before anything paints.
 *
 * Runs inline in <head> rather than at the end of <body> deliberately: if it
 * ran later the page would paint in the wrong theme first and visibly flash.
 * Dark is the default — an unset, unreadable, or unrecognised stored value
 * all resolve to dark, so the only way to get light is to have explicitly
 * chosen it. Contributors fill this form repeatedly, so the low-glare theme
 * is the one that should require no action.
 */
function eaer_theme_boot(): void {
    ?>
    <script>
    (function () {
        var t;
        try { t = localStorage.getItem('eaer-theme'); } catch (e) { /* private mode / blocked storage */ }
        document.documentElement.setAttribute('data-theme', t === 'light' ? 'light' : 'dark');
    })();

    function eaerSyncToggle(theme) {
        // The button advertises what it will do, not what is currently active —
        // "Switch to light mode" while dark is showing.
        var label = theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';
        var glyph = theme === 'dark' ? '☀' : '☾';
        document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
            var icon = btn.querySelector('[data-theme-icon]');
            if (icon) icon.textContent = glyph;
        });
    }

    function eaerToggleTheme() {
        var el = document.documentElement;
        var next = el.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        el.setAttribute('data-theme', next);
        try { localStorage.setItem('eaer-theme', next); } catch (e) { /* preference just won't persist */ }
        eaerSyncToggle(next);
    }

    document.addEventListener('DOMContentLoaded', function () {
        eaerSyncToggle(document.documentElement.getAttribute('data-theme'));
    });
    </script>
    <?php
}

/**
 * Theme toggle button. $inTopbar picks the colour set: the topbar sits on the
 * navy --topbar surface, everywhere else sits on the page background.
 */
function eaer_theme_toggle(bool $inTopbar = true): string {
    $classes = $inTopbar
        ? 'border-[var(--topbar-border)] text-[var(--topbar-subtle)] hover:text-[var(--topbar-text)]'
        : 'border-[var(--border)] text-[var(--muted)] hover:text-[var(--text)]';
    return '<button type="button" data-theme-toggle onclick="eaerToggleTheme()"
            class="inline-flex items-center justify-center w-8 h-8 rounded-lg border text-sm leading-none flex-shrink-0 ' . $classes . '"
            aria-label="Switch colour theme" title="Switch colour theme">
            <span data-theme-icon aria-hidden="true">&#9728;</span>
        </button>';
}

/**
 * Shared <head> assets: theme boot + tokens, UTSA brand fonts, and the
 * skip-link/focus-visible accessibility pattern originally copied verbatim
 * from app.php's WCAG 2.1 AA pass. The light token set below is that same
 * contrast-checked palette; the dark set is its counterpart, with UTSA navy
 * demoted from a text colour to a background-only one (see --heading).
 */
function eaer_head_assets(): void {
    eaer_theme_boot();
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Arsenal:wght@400;700&family=Libre+Franklin:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <style>
        /* ── Theme tokens ─────────────────────────────────────────────── */
        /* The EAER pages reference every colour as bg-[var(--token)] /
           text-[var(--token)] instead of a literal hex, so both themes are
           defined here once and nowhere else. Adding a colour to a page means
           adding a token here, not a dark: variant at the call site.

           :root carries the dark values so the default still holds if the
           boot script above is blocked and no data-theme is ever set. */
        :root,
        [data-theme="dark"] {
            color-scheme: dark;

            --bg:                #14161A;
            --surface:           #1D2026;
            --surface-2:         #262A31;
            --border:            #363B44;
            --field-border:      #6B7280;
            --text:              #E6E3DE;
            --muted:             #A29C92;

            /* UTSA navy (#032044) is unreadable as text on a dark ground;
               these are its legible counterparts. Navy survives as --topbar,
               where it is a background and still reads as UTSA. */
            --heading:           #BFD4F5;
            --topbar:            #0A1830;
            --topbar-text:       #F0F3F8;
            --topbar-subtle:     #9DBAE8;
            --topbar-border:     #5E76A6;

            --accent:            #7BA5FF;
            --on-accent:         #10141A;
            --accent-deep:       #2E5FA8;
            --accent-deep-hover: #3B72C4;

            --btn-primary:       #D3430D;
            --btn-primary-hover: #B94700;

            --warn:              #D9A441;
            --danger:            #DC2626;
            --danger-hover:      #B91C1C;
            --danger-text:       #F87171;
            --success:           #4ADE80;

            --pill-bg:           #2A2F37;
            --pill-warn-bg:      #3A2F1B;
            --pill-draft-bg:     #1E2A43;
            --pill-draft-text:   #A8C5F5;

            --help-bg:           #7A8290;
            --help-text:         #10141A;

            --input-bg:          #171A1F;
            --status-ok-bg:      #14301F;
            --status-ok-text:    #86EFAC;
            --status-ok-border:  #2F6B45;
            --status-err-bg:     #3A1A1A;
            --status-err-text:   #FCA5A5;
            --status-err-border: #8A3A3A;
        }

        [data-theme="light"] {
            color-scheme: light;

            --bg:                #F8F4F1;
            --surface:           #FFFFFF;
            --surface-2:         #F8F4F1;
            --border:            #EBE6E2;
            --field-border:      #EBE6E2;
            --text:              #332F21;
            --muted:             #6B6355;

            --heading:           #032044;
            --topbar:            #032044;
            --topbar-text:       #FFFFFF;
            --topbar-subtle:     #C8DCFF;
            --topbar-border:     #5A79B5;

            --accent:            #265BF7;
            --on-accent:         #FFFFFF;
            --accent-deep:       #1B3A6B;
            --accent-deep-hover: #254E8F;

            --btn-primary:       #D3430D;
            --btn-primary-hover: #B94700;

            --warn:              #A06620;
            --danger:            #DC2626;
            --danger-hover:      #B91C1C;
            --danger-text:       #DC2626;
            --success:           #15803D;

            --pill-bg:           #FFFFFF;
            --pill-warn-bg:      #F5ECDD;
            --pill-draft-bg:     #E4ECFE;
            --pill-draft-text:   #1B3A6B;

            --help-bg:           #6B6355;
            --help-text:         #FFFFFF;

            --input-bg:          #FFFFFF;
            --status-ok-bg:      #F0FDF4;
            --status-ok-text:    #166534;
            --status-ok-border:  #15803D;
            --status-err-bg:     #FEF2F2;
            --status-err-text:   #991B1B;
            --status-err-border: #B91C1C;
        }

        /* Print always uses the light palette regardless of the on-screen
           theme. eaer_export.php output is attached to the DocuSign exception
           memo — a dark-background PDF would be unreadable as a printed
           record and would flood a printer with toner. */
        @media print {
            :root,
            [data-theme="dark"],
            [data-theme="light"] {
                color-scheme: light;
                --bg:              #FFFFFF;
                --surface:         #FFFFFF;
                --surface-2:       #F8F4F1;
                --border:          #EBE6E2;
                --text:            #332F21;
                --muted:           #6B6355;
                --heading:         #032044;
                --accent:          #265BF7;
                --accent-deep:     #1B3A6B;
                --warn:            #A06620;
                --danger-text:     #DC2626;
                --field-border:    #EBE6E2;
                --pill-bg:         #FFFFFF;
                --pill-warn-bg:    #F5ECDD;
                --pill-draft-bg:   #E4ECFE;
                --pill-draft-text: #1B3A6B;
                --help-bg:         #6B6355;
                --help-text:       #FFFFFF;
                --input-bg:        #FFFFFF;
            }
        }

        body {
            font-family: 'Libre Franklin', system-ui, sans-serif;
            color: var(--text);
        }
        .font-brand { font-family: 'Arsenal', system-ui, sans-serif; }

        /* Tailwind's preflight leaves text inputs on the user-agent default
           background, which stays white under a dark theme in some browsers.
           Set it explicitly rather than relying on color-scheme alone. The
           read-only:bg-* utility at the call site still wins on specificity. */
        input[type="text"],
        input[type="date"],
        textarea {
            background-color: var(--input-bg);
            color: var(--text);
        }
        input[type="text"]::placeholder,
        textarea::placeholder { color: var(--muted); }

        /* ── Accessibility: skip link + focus visibility (from app.php) ── */
        .skip-link {
            position:absolute; left:8px; top:-40px; z-index:1000;
            background:var(--topbar); color:var(--topbar-text); padding:8px 14px; border-radius:0 0 6px 6px;
            font-size:13px; font-weight:600; text-decoration:none; transition:top .15s;
        }
        .skip-link:focus { top:0; }
        :focus-visible {
            outline:2px solid var(--accent) !important;
            outline-offset:2px;
        }
    </style>
    <?php
}
