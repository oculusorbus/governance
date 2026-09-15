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
    <title>EAER — Identify Yourself</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <?php eaer_head_assets(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-[#F8F4F1] flex items-center justify-center">
    <a href="#main-content" class="skip-link">Skip to form</a>
    <main id="main-content" class="bg-white rounded-xl shadow-lg p-8 w-full max-w-md">
        <div class="flex items-center gap-2 mb-1">
            <img src="utsa-logo.svg" alt="UT San Antonio" height="18">
        </div>
        <h1 class="font-brand text-xl font-bold text-[#032044] mb-1">EIR Accessibility Exception Request</h1>
        <p class="text-sm text-[#6B6355] mb-6"><?= h($record['eir_name'] ?: 'Untitled request') ?></p>
        <p class="text-xs text-[#6B6355] mb-4">
            Enter your name so your contributions to this form can be attributed.
            SSO is not yet available for this tool, so this is a self-reported name,
            not an authenticated login.
        </p>
        <form method="post">
            <label for="contributor_name" class="block text-sm font-medium text-[#332F21] mb-1">Your Name</label>
            <input type="text" id="contributor_name" name="contributor_name" autofocus required
                   class="w-full border border-[#EBE6E2] rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#265BF7] mb-4">
            <button type="submit"
                    class="w-full bg-[#D3430D] hover:bg-[#B94700] text-white font-medium py-2 rounded-lg text-sm">
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
    <title>EAER — <?= h($record['eir_name'] ?: 'Untitled') ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <?php eaer_head_assets(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#F8F4F1] min-h-screen pb-20">

<a href="#main-content" class="skip-link">Skip to form</a>

<div id="topbar" role="banner" class="bg-[#032044] sticky top-0 z-10 shadow-sm">
    <div class="max-w-6xl mx-auto px-6 py-3 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <img src="utsa-logo.svg" alt="UT San Antonio" height="18" class="flex-shrink-0">
            <div>
                <h1 class="font-brand text-base font-bold text-white leading-tight">EIR Accessibility Exception Request</h1>
                <p class="text-xs text-[#C8DCFF]">Signed in as <strong><?= h($contributorName) ?></strong> · Per HOP 11.10 / 1 TAC 213.37</p>
            </div>
        </div>
        <?php if ($isExported): ?>
            <span class="text-xs font-medium bg-white text-[#A06620] px-3 py-1 rounded-full whitespace-nowrap">Exported — read only</span>
        <?php else: ?>
            <span class="text-xs font-medium bg-white text-[#1B3A6B] px-3 py-1 rounded-full whitespace-nowrap">Draft — in progress</span>
        <?php endif; ?>
    </div>
</div>

<main id="main-content" class="max-w-6xl mx-auto px-6 py-6">

<?php if ($isExported): ?>
<div class="bg-white border border-[#A06620] text-[#A06620] text-sm rounded-lg px-4 py-3 mb-6">
    This request has been exported and attached to its DocuSign exception memo. It is now read-only.
</div>
<?php endif; ?>

<div id="save-status" role="status" aria-live="polite" class="hidden fixed bottom-6 right-6 z-50 text-sm rounded-lg px-4 py-2 shadow-lg"></div>

<?php foreach ($sections as $sectionKey => $section): ?>
<section class="bg-white rounded-xl shadow-sm mb-6 overflow-hidden" data-section-block="<?= h($sectionKey) ?>" aria-labelledby="h-<?= h($sectionKey) ?>">
    <div class="px-6 py-4 border-b border-[#EBE6E2] bg-[#F8F4F1]">
        <h2 id="h-<?= h($sectionKey) ?>" class="font-brand font-bold text-[#032044]"><?= h($section['title']) ?></h2>
    </div>
    <div class="px-6 py-4 grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($section['fields'] as $fieldKey => $field):
            $val   = $record[$fieldKey] ?? '';
            $domId = 'f-' . $fieldKey;
        ?>

        <?php if ($field['type'] === 'radio' || $field['type'] === 'checkboxes'): ?>
        <fieldset class="border-0 p-0 m-0 md:col-span-2">
            <legend class="text-sm font-medium text-[#332F21] mb-1"><?= h($field['label']) ?></legend>
            <?php if (!empty($field['note'])): ?>
                <p class="text-xs text-[#6B6355] mb-1"><?= h($field['note']) ?></p>
            <?php endif; ?>

            <?php if ($field['type'] === 'radio'): ?>
                <div class="flex gap-4">
                    <?php foreach ($field['options'] as $i => $opt): ?>
                    <label class="text-sm flex items-center gap-1 text-[#332F21]">
                        <input type="radio" id="<?= h($domId . '-' . $i) ?>" name="<?= h($fieldKey) ?>" value="<?= h($opt) ?>" data-field="<?= h($fieldKey) ?>"
                               class="accent-[#1B3A6B]"
                               <?= $val === $opt ? 'checked' : '' ?> <?= $isExported ? 'disabled' : '' ?>>
                        <?= h($opt) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            <?php else:
                $selected = array_map('trim', explode(',', (string)$val)); ?>
                <div class="flex flex-col gap-1">
                    <?php foreach ($field['options'] as $i => $opt): ?>
                    <label class="text-sm flex items-center gap-2 text-[#332F21]">
                        <input type="checkbox" id="<?= h($domId . '-' . $i) ?>" name="<?= h($fieldKey) ?>[]" value="<?= h($opt) ?>" data-field-group="<?= h($fieldKey) ?>"
                               class="accent-[#1B3A6B]"
                               <?= in_array($opt, $selected, true) ? 'checked' : '' ?> <?= $isExported ? 'disabled' : '' ?>>
                        <?= h($opt) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </fieldset>

        <?php else: ?>
        <div class="<?= $field['type'] === 'textarea' ? 'md:col-span-2' : '' ?>">
            <label for="<?= h($domId) ?>" class="block text-sm font-medium text-[#332F21] mb-1"><?= h($field['label']) ?></label>
            <?php if (!empty($field['note'])): ?>
                <p class="text-xs text-[#6B6355] mb-1"><?= h($field['note']) ?></p>
            <?php endif; ?>

            <?php if ($field['type'] === 'textarea'): ?>
                <textarea id="<?= h($domId) ?>" name="<?= h($fieldKey) ?>" rows="3" <?= $isExported ? 'readonly' : '' ?>
                    oninput="autoGrow(this)"
                    class="w-full border border-[#EBE6E2] rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#265BF7] read-only:bg-[#F8F4F1] resize-none overflow-hidden"
                    data-field="<?= h($fieldKey) ?>"><?= h($val) ?></textarea>
            <?php else: ?>
                <input type="text" id="<?= h($domId) ?>" name="<?= h($fieldKey) ?>" value="<?= h($val) ?>" data-field="<?= h($fieldKey) ?>"
                       <?= $isExported ? 'readonly' : '' ?>
                       class="w-full border border-[#EBE6E2] rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#265BF7] read-only:bg-[#F8F4F1]">
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endforeach; ?>

        <?php if (!empty($contributions[$sectionKey])): ?>
        <div class="text-xs text-[#6B6355] pt-2 border-t border-[#EBE6E2] md:col-span-2">
            Contributed by:
            <?php foreach ($contributions[$sectionKey] as $c): ?>
                <span class="inline-block mr-2"><?= h($c['contributor_name']) ?> (<?= h(date('M j, Y g:ia', strtotime($c['updated_at']))) ?>)</span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$isExported): ?>
        <div class="md:col-span-2 pt-2 border-t border-[#EBE6E2] flex justify-end">
            <button type="button" onclick="saveSection('<?= h($sectionKey) ?>')"
                    class="text-xs bg-[#1B3A6B] hover:bg-[#254e8f] text-white font-medium px-3 py-1.5 rounded-lg">
                Save Section
            </button>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endforeach; ?>

<section class="bg-white rounded-xl shadow-sm mb-6 overflow-hidden" aria-labelledby="h-legislation">
    <div class="px-6 py-4 border-b border-[#EBE6E2] bg-[#F8F4F1]">
        <h2 id="h-legislation" class="font-brand font-bold text-[#032044]">Applicable Disability Policy and Legislation</h2>
    </div>
    <ul class="px-6 py-4 text-sm text-[#332F21] list-disc list-inside space-y-1">
        <?php foreach ($legislation as $item): ?>
            <li><?= h($item) ?></li>
        <?php endforeach; ?>
    </ul>
</section>

<p class="text-xs text-[#6B6355] text-center">
    Formal approval of this exception is captured separately via the DocuSign exception-request memo
    (VP IMT and EIRAC signatures). Contributor names on this form are self-reported; SSO-based
    authentication is not yet available for this tool.
</p>

</main>

<script>
const TOKEN = <?= json_encode($token) ?>;

function autoGrow(el) {
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 'px';
}
// Size every textarea to its existing content on load (not just on typing),
// so a saved long narrative starts expanded instead of scrolled/clipped.
document.querySelectorAll('textarea[data-field]').forEach(autoGrow);

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
        status.textContent = 'Saved — reload to see attribution update.';
        status.className = 'fixed bottom-6 right-6 z-50 text-sm rounded-lg px-4 py-2 shadow-lg bg-green-50 text-green-800 border border-green-700';
    } catch (e) {
        status.textContent = 'Error saving: ' + e.message;
        status.className = 'fixed bottom-6 right-6 z-50 text-sm rounded-lg px-4 py-2 shadow-lg bg-red-50 text-red-800 border border-red-700';
    }
    status.classList.remove('hidden');
    clearTimeout(window.eaerStatusTimeout);
    window.eaerStatusTimeout = setTimeout(() => status.classList.add('hidden'), 4000);
}
</script>

</body>
</html>
