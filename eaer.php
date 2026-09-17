<?php
/**
 * Contributor-facing EAER form. Reached only via a per-record unique
 * token link (no app password/SSO gate — see governance repo conversation
 * log for rationale: VPN-restricted network + unguessable token is the
 * access control for this prototype).
 */
session_start();
require_once __DIR__ . '/eaer_db.php';

function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Click-to-reveal help button + hidden content, for a field's label/legend.
 * Not a hover-only tooltip: works via click or keyboard (Enter/Space on a
 * real <button>), and the help text is always in the DOM for screen readers
 * once expanded, rather than relying on a title="" attribute.
 */
function eaer_help_button(array $field, string $domId): string {
    if (empty($field['help'])) return '';
    $helpId = 'help-' . $domId;
    return '
        <button type="button"
                class="help-toggle inline-flex items-center justify-center w-4 h-4 rounded-full bg-[var(--border)] text-[var(--muted)] text-[11px] font-bold leading-none hover:bg-[var(--accent)] hover:text-[var(--on-accent)] flex-shrink-0"
                aria-expanded="false" aria-controls="' . h($helpId) . '" onclick="toggleHelp(this)">
            <span aria-hidden="true">?</span>
            <span class="sr-only">Help for ' . h($field['label']) . '</span>
        </button>';
}

function eaer_help_text(array $field, string $domId): string {
    if (empty($field['help'])) return '';
    $helpId = 'help-' . $domId;
    return '<p id="' . h($helpId) . '" class="hidden text-xs text-[var(--muted)] bg-[var(--surface-2)] border border-[var(--border)] rounded-lg px-3 py-2 mb-1">' . h($field['help']) . '</p>';
}

$token = (string)($_GET['token'] ?? '');
if ($token === '') { http_response_code(400); die('Missing token.'); }

$pdo = eaer_pdo();
$stmt = $pdo->prepare("SELECT * FROM eaer_requests WHERE token = ?");
$stmt->execute([$token]);
$record = $stmt->fetch();
if (!$record) { http_response_code(404); die('This link is not valid. Confirm you have the correct URL.'); }

// ── Name gate (session-scoped per token; self-reported, no SSO yet) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contributor_name'])) {
    $name = trim((string)$_POST['contributor_name']);
    if ($name !== '') {
        $_SESSION['eaer_name'][$token] = $name;
        header('Location: eaer.php?token=' . urlencode($token));
        exit;
    }
}

$contributorName = trim((string)($_SESSION['eaer_name'][$token] ?? ''));

if ($contributorName === ''): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EAER: Identify Yourself</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <?php eaer_head_assets(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-[var(--bg)] flex items-center justify-center">
    <a href="#main-content" class="skip-link">Skip to form</a>
    <main id="main-content" class="bg-[var(--surface)] rounded-xl shadow-lg p-8 w-full max-w-md">
        <div class="flex items-center justify-between gap-2 mb-1">
            <img src="utsa-logo.svg" alt="UT San Antonio" height="18">
            <?= eaer_theme_toggle(false) ?>
        </div>
        <h1 class="font-brand text-xl font-bold text-[var(--heading)] mb-1">Electronic and Information Resource (EIR) Accessibility Exception Request</h1>
        <p class="text-sm text-[var(--muted)] mb-6"><?= h($record['eir_name'] ?: 'Untitled request') ?></p>
        <p class="text-xs text-[var(--muted)] mb-4">
            Enter your name so your contributions to this form can be attributed.
            SSO is not yet available for this tool, so this is a self-reported name,
            not an authenticated login.
        </p>
        <form method="post">
            <label for="contributor_name" class="block text-sm font-medium text-[var(--text)] mb-1">Your Name</label>
            <input type="text" id="contributor_name" name="contributor_name" autofocus required
                   class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--accent)] mb-4">
            <button type="submit"
                    class="w-full bg-[var(--btn-primary)] hover:bg-[var(--btn-primary-hover)] text-white font-medium py-2 rounded-lg text-sm">
                Continue
            </button>
        </form>
    </main>
</body>
</html>
<?php exit; endif;

$contribStmt = $pdo->prepare("SELECT section_key, contributor_name, updated_at FROM eaer_contributions WHERE eaer_id = ? ORDER BY updated_at");
$contribStmt->execute([$record['id']]);
$contributions = [];
foreach ($contribStmt->fetchAll() as $c) {
    $contributions[$c['section_key']][] = $c;
}

$sections    = eaer_sections();
$legislation = eaer_legislation();
$isExported  = $record['status'] === 'exported';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EAER: <?= h($record['eir_name'] ?: 'Untitled') ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <?php eaer_head_assets(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[var(--bg)] min-h-screen pb-20">

<a href="#main-content" class="skip-link">Skip to form</a>

<div id="topbar" role="banner" class="bg-[var(--topbar)] sticky top-0 z-10 shadow-sm">
    <div class="max-w-6xl mx-auto px-6 py-3 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <img src="utsa-logo.svg" alt="UT San Antonio" height="18" class="flex-shrink-0">
            <div>
                <h1 class="font-brand text-base font-bold text-[var(--topbar-text)] leading-tight">Electronic and Information Resource (EIR) Accessibility Exception Request</h1>
                <p class="text-xs text-[var(--topbar-subtle)]">Signed in as <strong><?= h($contributorName) ?></strong></p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <?php if ($isExported): ?>
                <span class="text-xs font-medium bg-[var(--pill-bg)] text-[var(--warn)] px-3 py-1 rounded-full whitespace-nowrap">Exported (read only)</span>
            <?php else: ?>
                <span class="text-xs font-medium bg-[var(--pill-bg)] text-[var(--pill-draft-text)] px-3 py-1 rounded-full whitespace-nowrap">Draft (in progress)</span>
            <?php endif; ?>
            <?= eaer_theme_toggle() ?>
        </div>
    </div>
</div>

<main id="main-content" class="max-w-6xl mx-auto px-6 py-6">

<div class="bg-[var(--surface)] border-l-4 border-[var(--btn-primary)] rounded-xl shadow-sm mb-6 px-6 py-5">
    <h2 class="font-brand font-bold text-[var(--heading)] mb-2">What is this, and why does it matter?</h2>
    <p class="text-sm text-[var(--text)] mb-3">
        UTSA is legally required to make the electronic and information resources it uses accessible
        to people with disabilities. Under Texas Administrative Code (TAC) 213.37, that category
        covers far more than websites and software: it also includes IT hardware and office equipment,
        like copiers, kiosks, and telephones. Under Title II of the Americans with Disabilities Act
        (ADA) and TAC 213.37, when a specific product can't fully meet that standard, the university
        must document why, and how people with disabilities will still get an equitable experience,
        before it can be used. This form <em>is</em> that documentation.
    </p>
    <p class="text-sm text-[var(--text)] mb-3">
        Your input here becomes part of that official record. Each section below shows who entered it and
        when, so your contribution is directly attributed to you, not anonymous, and not optional filler.
    </p>
    <p class="text-sm text-[var(--text)]">
        Once every section is complete, this record is locked and attached as supporting evidence to the
        official exception-request memo, which is routed for signature to the
        <strong>Vice President for Information Management and Technology (VP IMT)</strong> and the
        <strong>EIR Accessibility Coordinator (EIRAC)</strong>, the two university officials whose
        approval makes this exception official. This record, alongside their signatures, is what UTSA
        would produce if this exception were ever audited or challenged.
    </p>
</div>

<?php if ($isExported): ?>
<div class="bg-[var(--surface)] border border-[var(--warn)] text-[var(--warn)] text-sm rounded-lg px-4 py-3 mb-6">
    This request has been exported and attached to its DocuSign exception memo. It is now read-only.
</div>
<?php endif; ?>

<div id="save-status" role="status" aria-live="polite" class="hidden fixed bottom-6 right-6 z-50 text-sm rounded-lg px-4 py-2 shadow-lg"></div>

<?php foreach ($sections as $sectionKey => $section): ?>
<section class="bg-[var(--surface)] rounded-xl shadow-sm mb-6 overflow-hidden" data-section-block="<?= h($sectionKey) ?>" aria-labelledby="h-<?= h($sectionKey) ?>">
    <div class="px-6 py-4 border-b border-[var(--border)] bg-[var(--surface-2)]">
        <h2 id="h-<?= h($sectionKey) ?>" class="font-brand font-bold text-[var(--heading)]"><?= h($section['title']) ?></h2>
    </div>
    <div class="px-6 py-4 grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($section['fields'] as $fieldKey => $field):
            $val   = $record[$fieldKey] ?? '';
            $domId = 'f-' . $fieldKey;
        ?>

        <?php if ($field['type'] === 'radio' || $field['type'] === 'checkboxes'): ?>
        <fieldset class="border-0 p-0 m-0 md:col-span-2">
            <legend class="flex items-center gap-1.5 text-sm font-medium text-[var(--text)] mb-1">
                <?= h($field['label']) ?>
                <?= eaer_help_button($field, $domId) ?>
            </legend>
            <?= eaer_help_text($field, $domId) ?>
            <?php if (!empty($field['note'])): ?>
                <p class="text-xs text-[var(--muted)] mb-1"><?= h($field['note']) ?></p>
            <?php endif; ?>

            <?php if ($field['type'] === 'radio'): ?>
                <div class="<?= ($field['layout'] ?? 'inline') === 'stacked' ? 'flex flex-col gap-1' : 'flex gap-4' ?>">
                    <?php foreach ($field['options'] as $i => $opt): ?>
                    <label class="text-sm flex items-center gap-1 text-[var(--text)]">
                        <input type="radio" id="<?= h($domId . '-' . $i) ?>" name="<?= h($fieldKey) ?>" value="<?= h($opt) ?>" data-field="<?= h($fieldKey) ?>"
                               class="accent-[var(--accent-deep)]"
                               <?= $val === $opt ? 'checked' : '' ?> <?= $isExported ? 'disabled' : '' ?>>
                        <?= h($opt) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            <?php else:
                $selected = array_map('trim', explode(',', (string)$val)); ?>
                <div class="flex flex-col gap-1">
                    <?php foreach ($field['options'] as $i => $opt): ?>
                    <label class="text-sm flex items-center gap-2 text-[var(--text)]">
                        <input type="checkbox" id="<?= h($domId . '-' . $i) ?>" name="<?= h($fieldKey) ?>[]" value="<?= h($opt) ?>" data-field-group="<?= h($fieldKey) ?>"
                               class="accent-[var(--accent-deep)]"
                               <?= in_array($opt, $selected, true) ? 'checked' : '' ?> <?= $isExported ? 'disabled' : '' ?>>
                        <?= h($opt) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </fieldset>

        <?php else: ?>
        <div class="<?= $field['type'] === 'textarea' ? 'md:col-span-2' : '' ?>"
             <?php if (!empty($field['showWhen'])): ?>
             data-show-when-field="<?= h($field['showWhen']['field']) ?>" data-show-when-equals="<?= h($field['showWhen']['equals']) ?>"
             <?php endif; ?>>
            <div class="flex items-center gap-1.5 mb-1">
                <label for="<?= h($domId) ?>" class="text-sm font-medium text-[var(--text)]"><?= h($field['label']) ?></label>
                <?= eaer_help_button($field, $domId) ?>
            </div>
            <?= eaer_help_text($field, $domId) ?>
            <?php if (!empty($field['note'])): ?>
                <p class="text-xs text-[var(--muted)] mb-1"><?= h($field['note']) ?></p>
            <?php endif; ?>

            <?php if ($field['type'] === 'textarea'): ?>
                <textarea id="<?= h($domId) ?>" name="<?= h($fieldKey) ?>" rows="3" <?= $isExported ? 'readonly' : '' ?>
                    oninput="autoGrow(this)"
                    class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--accent)] read-only:bg-[var(--surface-2)] resize-none overflow-hidden"
                    data-field="<?= h($fieldKey) ?>"><?= h($val) ?></textarea>
            <?php else: ?>
                <input type="text" id="<?= h($domId) ?>" name="<?= h($fieldKey) ?>" value="<?= h($val) ?>" data-field="<?= h($fieldKey) ?>"
                       <?= $isExported ? 'readonly' : '' ?>
                       class="w-full border border-[var(--border)] rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--accent)] read-only:bg-[var(--surface-2)]">
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endforeach; ?>

        <div class="text-xs text-[var(--muted)] pt-2 border-t border-[var(--border)] md:col-span-2 <?= empty($contributions[$sectionKey]) ? 'hidden' : '' ?>"
             data-contrib-block="<?= h($sectionKey) ?>">
            Contributed by:
            <span data-contrib-list>
            <?php foreach ($contributions[$sectionKey] ?? [] as $c): ?>
                <span class="inline-block mr-2" data-contrib-name="<?= h($c['contributor_name']) ?>"><?= h($c['contributor_name']) ?> (<?= h(date('M j, Y g:ia', strtotime($c['updated_at']))) ?>)</span>
            <?php endforeach; ?>
            </span>
        </div>

        <?php if (!$isExported): ?>
        <div class="md:col-span-2 pt-2 border-t border-[var(--border)] flex justify-end">
            <button type="button" onclick="saveSection('<?= h($sectionKey) ?>')"
                    class="text-xs bg-[var(--accent-deep)] hover:bg-[var(--accent-deep-hover)] text-white font-medium px-3 py-1.5 rounded-lg">
                Save Section
            </button>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endforeach; ?>

<section class="bg-[var(--surface)] rounded-xl shadow-sm mb-6 overflow-hidden" aria-labelledby="h-legislation">
    <div class="px-6 py-4 border-b border-[var(--border)] bg-[var(--surface-2)]">
        <h2 id="h-legislation" class="font-brand font-bold text-[var(--heading)]">Applicable Disability Policy and Legislation</h2>
    </div>
    <ul class="pl-11 pr-6 py-4 text-sm text-[var(--text)] list-disc list-outside space-y-1">
        <?php foreach ($legislation as $item): ?>
            <li><?= h($item) ?></li>
        <?php endforeach; ?>
    </ul>
</section>

<p class="text-xs text-[var(--muted)] text-center">
    Formal approval of this exception is captured separately via the DocuSign exception-request memo
    (VP IMT and EIRAC signatures). Contributor names on this form are self-reported; SSO-based
    authentication is not yet available for this tool.
</p>

</main>

<script>
const TOKEN = <?= json_encode($token) ?>;
const CONTRIBUTOR_NAME = <?= json_encode($contributorName) ?>;

function toggleHelp(btn) {
    const content = document.getElementById(btn.getAttribute('aria-controls'));
    const expanded = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', String(!expanded));
    content.classList.toggle('hidden', expanded);
}

function autoGrow(el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}
// Size every textarea to its existing content on load (not just on typing),
// so a saved long narrative starts expanded instead of scrolled/clipped.
document.querySelectorAll('textarea[data-field]').forEach(autoGrow);

// "Other, describe" fields only show when their controlling radio/checkbox
// is set to "Other" — driven by data-show-when-* attributes from the
// field's 'showWhen' definition in eaer_sections(), not hardcoded per field.
function updateConditionalFields() {
    document.querySelectorAll('[data-show-when-field]').forEach(el => {
        const controlling = el.dataset.showWhenField;
        const expected = el.dataset.showWhenEquals;
        const radios = document.querySelectorAll(`input[type="radio"][data-field="${controlling}"]`);
        const checkboxes = document.querySelectorAll(`input[type="checkbox"][data-field-group="${controlling}"]`);
        let matched = false;
        radios.forEach(r => { if (r.checked && r.value === expected) matched = true; });
        checkboxes.forEach(c => { if (c.checked && c.value === expected) matched = true; });
        el.classList.toggle('hidden', !matched);
    });
}
updateConditionalFields();
document.addEventListener('change', e => {
    if (e.target.matches('input[type="radio"], input[type="checkbox"]')) updateConditionalFields();
});

function collectSectionFields(sectionKey) {
    const block = document.querySelector(`[data-section-block="${sectionKey}"]`);
    const fields = {};
    block.querySelectorAll('[data-field]').forEach(el => {
        if (el.type === 'radio') { if (el.checked) fields[el.dataset.field] = el.value; }
        else { fields[el.dataset.field] = el.value; }
    });
    const groups = {};
    block.querySelectorAll('[data-field-group]').forEach(el => {
        const key = el.dataset.fieldGroup;
        groups[key] = groups[key] || [];
        if (el.checked) groups[key].push(el.value);
    });
    Object.assign(fields, groups);
    return fields;
}

// Updates the "Contributed by" line for a section immediately after a
// successful save, so the contributor sees their own attribution without
// needing to reload the page.
function markContributed(sectionKey) {
    const block = document.querySelector(`[data-contrib-block="${sectionKey}"]`);
    if (!block) return;
    const list = block.querySelector('[data-contrib-list]');
    const now = new Date();
    const stamp = now.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
        + ' ' + now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });

    let entry = null;
    list.querySelectorAll('[data-contrib-name]').forEach(el => {
        if (el.dataset.contribName === CONTRIBUTOR_NAME) entry = el;
    });
    if (!entry) {
        entry = document.createElement('span');
        entry.className = 'inline-block mr-2';
        entry.dataset.contribName = CONTRIBUTOR_NAME;
        list.appendChild(entry);
    }
    entry.textContent = `${CONTRIBUTOR_NAME} (${stamp})`;
    block.classList.remove('hidden');
}

async function saveSection(sectionKey) {
    const fields = collectSectionFields(sectionKey);
    const status = document.getElementById('save-status');
    try {
        const res = await fetch('eaer_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'save_section', token: TOKEN, section: sectionKey, fields }),
        });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'Save failed');
        markContributed(sectionKey);
        status.textContent = 'Saved.';
        status.className = 'fixed bottom-6 right-6 z-50 text-sm rounded-lg px-4 py-2 shadow-lg bg-[var(--status-ok-bg)] text-[var(--status-ok-text)] border border-[var(--status-ok-border)]';
    } catch (e) {
        status.textContent = 'Error saving: ' + e.message;
        status.className = 'fixed bottom-6 right-6 z-50 text-sm rounded-lg px-4 py-2 shadow-lg bg-[var(--status-err-bg)] text-[var(--status-err-text)] border border-[var(--status-err-border)]';
    }
    status.classList.remove('hidden');
    clearTimeout(window.eaerStatusTimeout);
    window.eaerStatusTimeout = setTimeout(() => status.classList.add('hidden'), 4000);
}
</script>

</body>
</html>
