<?php
/**
 * Admin panel for EAER prototype: create new exception requests, see all
 * records in progress / exported, generate the shareable contributor link.
 * Gated behind the same app-wide session auth as the rest of governance.
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

$pdo = eaer_pdo();
$newLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_eir_name'])) {
        $eirName = trim((string)$_POST['create_eir_name']);
        $token   = eaer_gen_token();
        $pdo->prepare("INSERT INTO eaer_requests (token, eir_name) VALUES (?, ?)")
            ->execute([$token, $eirName !== '' ? $eirName : null]);
        $newLink = 'eaer.php?token=' . $token;
    } elseif (isset($_POST['export_token'])) {
        $token = (string)$_POST['export_token'];
        $stmt = $pdo->prepare("SELECT id FROM eaer_requests WHERE token = ?");
        $stmt->execute([$token]);
        if ($row = $stmt->fetch()) {
            $pdo->prepare("UPDATE eaer_requests SET status = 'exported', exported_at = SYSUTCDATETIME() WHERE id = ?")
                ->execute([$row['id']]);
        }
    } elseif (isset($_POST['reopen_token'])) {
        $token = (string)$_POST['reopen_token'];
        $stmt = $pdo->prepare("SELECT id FROM eaer_requests WHERE token = ?");
        $stmt->execute([$token]);
        if ($row = $stmt->fetch()) {
            $pdo->prepare("UPDATE eaer_requests SET status = 'draft' WHERE id = ?")
                ->execute([$row['id']]);
        }
    }
}

$records = $pdo->query("
    SELECT r.id, r.token, r.status, r.eir_name, r.vendor_name,
           r.created_at, r.exported_at,
           COUNT(DISTINCT c.contributor_name) AS contributor_count,
           MAX(c.updated_at) AS last_activity
    FROM eaer_requests r
    LEFT JOIN eaer_contributions c ON c.eaer_id = r.id
    GROUP BY r.id, r.token, r.status, r.eir_name, r.vendor_name, r.created_at, r.exported_at
    ORDER BY r.created_at DESC
")->fetchAll();

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EIR Exceptions — Admin</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <?php eaer_head_assets(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#F8F4F1] min-h-screen">

<a href="#main-content" class="skip-link">Skip to exception requests</a>

<div id="topbar" role="banner" class="bg-[#032044] shadow-sm">
    <div class="max-w-5xl mx-auto px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <img src="utsa-logo.svg" alt="UT San Antonio" height="20">
            <h1 class="font-brand text-lg font-bold text-white">EIR Accessibility Exception Requests</h1>
        </div>
        <div class="flex gap-3">
            <a href="app.php" class="text-sm text-[#C8DCFF] hover:underline self-center">← Website Governance</a>
            <a href="logout.php"><button type="button" class="text-sm bg-[#dc2626] hover:bg-[#b91c1c] text-white px-3 py-1.5 rounded-lg">Sign Out</button></a>
        </div>
    </div>
</div>

<main id="main-content" class="max-w-5xl mx-auto px-6 py-6">

<div class="bg-white rounded-xl shadow-sm p-6 mb-6">
    <h2 class="font-brand font-bold text-[#032044] mb-3">Start a New Exception Request</h2>
    <form method="post" class="flex gap-3">
        <label for="create_eir_name" class="sr-only">EIR / product name</label>
        <input type="text" id="create_eir_name" name="create_eir_name" placeholder="EIR / product name (optional — can be filled in later)"
               class="flex-1 border border-[#EBE6E2] rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#265BF7]">
        <button type="submit" class="bg-[#D3430D] hover:bg-[#B94700] text-white font-medium px-4 py-2 rounded-lg text-sm">
            Create
        </button>
    </form>
    <?php if ($newLink): ?>
    <div class="mt-4 bg-white border border-[#15803d] rounded-lg px-4 py-3">
        <p class="text-sm text-[#15803d] mb-1">Created. Share this link with contributors:</p>
        <div class="flex gap-2 items-center">
            <label for="new-link-field" class="sr-only">Shareable link</label>
            <input type="text" id="new-link-field" readonly value="<?= h($baseUrl . '/' . $newLink) ?>" onclick="this.select()"
                   class="flex-1 border border-[#EBE6E2] rounded-lg px-3 py-1.5 text-sm bg-white font-mono">
            <a href="<?= h($newLink) ?>" target="_blank" class="text-sm text-[#265BF7] hover:underline whitespace-nowrap">Open →</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="bg-white rounded-xl shadow-sm overflow-hidden">
    <table class="w-full text-sm">
        <caption class="sr-only">All EIR accessibility exception requests, draft and exported</caption>
        <thead class="bg-[#F8F4F1] text-left text-xs text-[#6B6355] uppercase">
            <tr>
                <th scope="col" class="px-4 py-3">EIR / Vendor</th>
                <th scope="col" class="px-4 py-3">Status</th>
                <th scope="col" class="px-4 py-3">Contributors</th>
                <th scope="col" class="px-4 py-3">Last Activity</th>
                <th scope="col" class="px-4 py-3">Created</th>
                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-[#EBE6E2]">
            <?php if (!$records): ?>
            <tr><td colspan="6" class="px-4 py-6 text-center text-[#6B6355]">No exception requests yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($records as $r): ?>
            <tr>
                <td class="px-4 py-3">
                    <div class="font-medium text-[#332F21]"><?= h($r['eir_name'] ?: 'Untitled') ?></div>
                    <?php if ($r['vendor_name']): ?><div class="text-xs text-[#6B6355]"><?= h($r['vendor_name']) ?></div><?php endif; ?>
                </td>
                <td class="px-4 py-3">
                    <?php if ($r['status'] === 'exported'): ?>
                        <span class="text-xs font-medium bg-[#A06620]/10 text-[#A06620] px-2 py-1 rounded-full">Exported</span>
                    <?php else: ?>
                        <span class="text-xs font-medium bg-[#265BF7]/10 text-[#1B3A6B] px-2 py-1 rounded-full">Draft</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-[#6B6355]"><?= (int)$r['contributor_count'] ?></td>
                <td class="px-4 py-3 text-[#6B6355]"><?= $r['last_activity'] ? h(date('M j, Y g:ia', strtotime($r['last_activity']))) : '—' ?></td>
                <td class="px-4 py-3 text-[#6B6355]"><?= h(date('M j, Y', strtotime($r['created_at']))) ?></td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <a href="eaer.php?token=<?= h($r['token']) ?>" class="text-[#265BF7] hover:underline mr-3">Open</a>
                    <?php if ($r['status'] === 'exported'): ?>
                        <a href="eaer_export.php?token=<?= h($r['token']) ?>" target="_blank" class="text-[#265BF7] hover:underline mr-3">View / Print</a>
                        <form method="post" class="inline" onsubmit="return confirm('Reopen for editing? Contributors will be able to change fields again until it is re-exported.');">
                            <input type="hidden" name="reopen_token" value="<?= h($r['token']) ?>">
                            <button type="submit" class="text-[#A06620] hover:underline">Reopen</button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="inline" onsubmit="return confirm('Export this record? It will become read-only.');">
                            <input type="hidden" name="export_token" value="<?= h($r['token']) ?>">
                            <button type="submit" class="text-[#265BF7] hover:underline">Export</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

</main>
</body>
</html>
