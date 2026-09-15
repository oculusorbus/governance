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
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-lg p-8 w-full max-w-sm">
        <h1 class="text-xl font-bold text-gray-800 mb-1">EIR Accessibility Exception Request</h1>
        <p class="text-sm text-gray-500 mb-6"><?= h($record['eir_name'] ?: 'Untitled request') ?></p>
        <p class="text-xs text-gray-500 mb-4">
            Enter your name so your contributions to this form can be attributed.
            SSO is not yet available for this tool, so this is a self-reported name,
            not an authenticated login.
        </p>
        <form method="post">
            <label class="block text-sm font-medium text-gray-700 mb-1">Your Name</label>
            <input type="text" name="contributor_name" autofocus required
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 mb-4">
            <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 rounded-lg text-sm">
                Continue
            </button>
        </form>
    </div>
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
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen pb-20">

<div class="bg-white border-b sticky top-0 z-10 shadow-sm">
    <div class="max-w-3xl mx-auto px-6 py-4 flex items-center justify-between">
        <div>
            <h1 class="text-lg font-bold text-gray-800">EIR Accessibility Exception Request</h1>
            <p class="text-xs text-gray-500">Signed in as <strong><?= h($contributorName) ?></strong> · Per HOP 11.10 / 1 TAC 213.37</p>
        </div>
        <?php if ($isExported): ?>
            <span class="text-xs font-medium bg-amber-100 text-amber-800 px-3 py-1 rounded-full">Exported — read only</span>
        <?php else: ?>
            <span class="text-xs font-medium bg-blue-100 text-blue-700 px-3 py-1 rounded-full">Draft — in progress</span>
        <?php endif; ?>
    </div>
</div>

<div class="max-w-3xl mx-auto px-6 py-6">

<?php if ($isExported): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg px-4 py-3 mb-6">
    This request has been exported and attached to its DocuSign exception memo. It is now read-only.
</div>
<?php endif; ?>

<div id="save-status" class="hidden text-sm rounded-lg px-4 py-2 mb-4"></div>

<?php foreach ($sections as $sectionKey => $section): ?>
<div class="bg-white rounded-xl shadow-sm mb-6 overflow-hidden" data-section-block="<?= h($sectionKey) ?>">
    <div class="px-6 py-4 border-b bg-gray-50 flex items-center justify-between">
        <h2 class="font-semibold text-gray-800"><?= h($section['title']) ?></h2>
        <?php if (!$isExported): ?>
        <button type="button" onclick="saveSection('<?= h($sectionKey) ?>')"
                class="text-xs bg-blue-600 hover:bg-blue-700 text-white font-medium px-3 py-1.5 rounded-lg">
            Save Section
        </button>
        <?php endif; ?>
    </div>
    <div class="px-6 py-4 space-y-4">
        <?php foreach ($section['fields'] as $fieldKey => $field): $val = $record[$fieldKey] ?? ''; ?>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1"><?= h($field['label']) ?></label>
            <?php if (!empty($field['note'])): ?>
                <p class="text-xs text-gray-400 mb-1"><?= h($field['note']) ?></p>
            <?php endif; ?>

            <?php if ($field['type'] === 'textarea'): ?>
                <textarea name="<?= h($fieldKey) ?>" rows="3" <?= $isExported ? 'disabled' : '' ?>
                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                    data-field="<?= h($fieldKey) ?>"><?= h($val) ?></textarea>

            <?php elseif ($field['type'] === 'radio'): ?>
                <div class="flex gap-4">
                    <?php foreach ($field['options'] as $opt): ?>
                    <label class="text-sm flex items-center gap-1">
                        <input type="radio" name="<?= h($fieldKey) ?>" value="<?= h($opt) ?>" data-field="<?= h($fieldKey) ?>"
                               <?= $val === $opt ? 'checked' : '' ?> <?= $isExported ? 'disabled' : '' ?>>
                        <?= h($opt) ?>
                    </label>
                    <?php endforeach; ?>
                </div>

            <?php elseif ($field['type'] === 'checkboxes'):
                $selected = array_map('trim', explode(',', (string)$val)); ?>
                <div class="flex flex-col gap-1">
                    <?php foreach ($field['options'] as $opt): ?>
                    <label class="text-sm flex items-center gap-2">
                        <input type="checkbox" name="<?= h($fieldKey) ?>[]" value="<?= h($opt) ?>" data-field-group="<?= h($fieldKey) ?>"
                               <?= in_array($opt, $selected, true) ? 'checked' : '' ?> <?= $isExported ? 'disabled' : '' ?>>
                        <?= h($opt) ?>
                    </label>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <input type="text" name="<?= h($fieldKey) ?>" value="<?= h($val) ?>" data-field="<?= h($fieldKey) ?>"
                       <?= $isExported ? 'disabled' : '' ?>
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100">
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($contributions[$sectionKey])): ?>
        <div class="text-xs text-gray-400 pt-2 border-t">
            Contributed by:
            <?php foreach ($contributions[$sectionKey] as $c): ?>
                <span class="inline-block mr-2"><?= h($c['contributor_name']) ?> (<?= h(date('M j, Y g:ia', strtotime($c['updated_at']))) ?>)</span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<div class="bg-white rounded-xl shadow-sm mb-6 overflow-hidden">
    <div class="px-6 py-4 border-b bg-gray-50">
        <h2 class="font-semibold text-gray-800">Applicable Disability Policy and Legislation</h2>
    </div>
    <ul class="px-6 py-4 text-sm text-gray-600 list-disc list-inside space-y-1">
        <?php foreach ($legislation as $item): ?>
            <li><?= h($item) ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<p class="text-xs text-gray-400 text-center">
    Formal approval of this exception is captured separately via the DocuSign exception-request memo
    (VP IMT and EIRAC signatures). Contributor names on this form are self-reported; SSO-based
    authentication is not yet available for this tool.
</p>

</div>

<script>
const TOKEN = <?= json_encode($token) ?>;

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
        status.className = 'text-sm rounded-lg px-4 py-2 mb-4 bg-green-50 text-green-700 border border-green-200';
    } catch (e) {
        status.textContent = 'Error saving: ' + e.message;
        status.className = 'text-sm rounded-lg px-4 py-2 mb-4 bg-red-50 text-red-700 border border-red-200';
    }
    status.classList.remove('hidden');
}
</script>

</body>
</html>
