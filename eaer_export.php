<?php
/**
 * Read-only, print-friendly snapshot of an EAER record — the document
 * meant to be printed/saved to PDF and attached to the DocuSign
 * exception-request memo. Admin-only.
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/eaer_db.php';

if (empty($_SESSION['auth'])) {
    header('Location: index.php');
    exit;
}

function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$token = (string)($_GET['token'] ?? '');
$pdo = eaer_pdo();
$stmt = $pdo->prepare("SELECT * FROM eaer_requests WHERE token = ?");
$stmt->execute([$token]);
$record = $stmt->fetch();
if (!$record) { http_response_code(404); die('Not found.'); }

$contribStmt = $pdo->prepare("SELECT section_key, contributor_name, updated_at FROM eaer_contributions WHERE eaer_id = ? ORDER BY updated_at");
$contribStmt->execute([$record['id']]);
$contributions = [];
foreach ($contribStmt->fetchAll() as $c) {
    $contributions[$c['section_key']][] = $c;
}

$sections    = eaer_sections();
$legislation = eaer_legislation();

function eaer_display_value(array $field, $val): string {
    if ($field['type'] === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$val)) {
        return date('F j, Y', strtotime((string)$val));
    }
    if ($field['type'] === 'checkboxes') {
        $items = array_filter(array_map('trim', explode(',', (string)$val)));
        return $items ? implode(', ', $items) : '—';
    }
    return $val !== null && $val !== '' ? (string)$val : '—';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>EAER Export: <?= h($record['eir_name'] ?: 'Untitled') ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <?php eaer_head_assets(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
        }
    </style>
</head>
<body class="bg-[var(--bg)]">

<a href="#main-content" class="skip-link">Skip to exception request</a>

<div class="no-print bg-[var(--topbar)] shadow-sm sticky top-0" role="banner">
    <div class="max-w-4xl mx-auto px-6 py-3 flex justify-between items-center">
        <a href="eaer_admin.php" class="text-sm text-[var(--topbar-subtle)] hover:underline">← Back to admin</a>
        <div class="flex items-center gap-3">
            <?= eaer_theme_toggle() ?>
            <button onclick="window.print()" class="bg-[var(--btn-primary)] hover:bg-[var(--btn-primary-hover)] text-white text-sm font-medium px-4 py-2 rounded-lg">
                Print / Save as PDF
            </button>
        </div>
    </div>
</div>

<main id="main-content" class="max-w-4xl mx-auto bg-[var(--surface)] shadow-sm my-6 p-8 print:shadow-none print:my-0">

    <div class="flex items-center gap-2 mb-2">
        <img src="utsa-logo.svg" alt="UT San Antonio" height="18">
    </div>
    <h1 class="font-brand text-xl font-bold text-[var(--heading)] mb-1">Electronic and Information Resources (EIR) Accessibility Exception Request</h1>
    <p class="text-sm text-[var(--muted)] mb-1">Per UT San Antonio Handbook of Operating Procedures (HOP) 11.10 and Texas Administrative Code (TAC) 213.37</p>
    <p class="text-xs text-[var(--muted)] mb-6">
        Status: <?= $record['status'] === 'exported' ? 'Exported' : 'Draft' ?>
        <?php if ($record['exported_at']): ?> · Exported <?= h(date('M j, Y g:ia', strtotime($record['exported_at']))) ?><?php endif; ?>
    </p>

    <?php foreach ($sections as $sectionKey => $section): ?>
    <div class="mb-6">
        <h2 class="font-brand font-bold text-[var(--heading)] border-b border-[var(--border)] pb-1 mb-2"><?= h($section['title']) ?></h2>
        <dl class="space-y-2">
            <?php foreach ($section['fields'] as $fieldKey => $field): ?>
            <div>
                <dt class="text-xs font-medium text-[var(--muted)]"><?= h($field['label']) ?></dt>
                <dd class="text-sm text-[var(--text)] whitespace-pre-wrap"><?= h(eaer_display_value($field, $record[$fieldKey] ?? null)) ?></dd>
            </div>
            <?php endforeach; ?>
        </dl>
        <?php if (!empty($contributions[$sectionKey])): ?>
        <p class="text-xs text-[var(--muted)] mt-2">
            Contributed by:
            <?= h(implode('; ', array_map(
                fn($c) => $c['contributor_name'] . ' (' . date('M j, Y g:ia', strtotime($c['updated_at'])) . ')',
                $contributions[$sectionKey]
            ))) ?>
        </p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="mb-6">
        <h2 class="font-brand font-bold text-[var(--heading)] border-b border-[var(--border)] pb-1 mb-2">Applicable Disability Policy and Legislation</h2>
        <ul class="text-sm text-[var(--text)] list-disc list-outside pl-6 space-y-1">
            <?php foreach ($legislation as $item): ?>
            <li>
                <?= h($item['text']) ?>
                <?php if (!empty($item['url'])): ?>
                    <span class="block text-xs text-[var(--muted)] break-all"><?= h($item['url']) ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="text-xs text-[var(--muted)] border-t border-[var(--border)] pt-4">
        <p class="mb-1">
            Contributor names above are self-reported; SSO-based authentication was not yet available
            for this tool at the time of submission.
        </p>
        <p>
            Formal approval of this exception request is captured via the associated DocuSign
            exception-request memo (VP for Information Management and Technology, and EIR
            Accessibility Coordinator signatures). This document is a supporting attachment,
            not itself the signed approval record.
        </p>
    </div>

</main>

</body>
</html>
