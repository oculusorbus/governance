<?php
/**
 * User and role administration.
 *
 * Every employee can authenticate (UTS restricts the Entra app to staff),
 * so rows appear here by themselves as people sign in — this screen is
 * about what they may DO, never about who may get in.
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
// Borrows the EAER pages' shared head assets purely for the theme tokens and
// the skip-link/focus pattern. eaer_pdo() is lazy, so no EAER database
// connection is opened by including this.
require_once __DIR__ . '/eaer_db.php';

$me = require_role('admin');

$pdo = new PDO(
    'sqlsrv:Server=' . DB_HOST . ';Database=' . DB_NAME,
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetId = (int)($_POST['user_id'] ?? 0);

    // Self-lockout guard. An admin demoting or deactivating themselves could
    // leave nobody able to grant roles, recoverable only by hand in SQL.
    $isSelf = $me['id'] !== null && $targetId === (int)$me['id'];

    if (isset($_POST['new_role'])) {
        $newRole = (string)$_POST['new_role'];
        if (!isset(ROLE_RANK[$newRole])) {
            $error = 'Unknown role.';
        } elseif ($isSelf && $newRole !== 'admin') {
            $error = 'You cannot remove your own admin access. Ask another administrator to do it.';
        } else {
            $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $targetId]);
            $notice = 'Role updated. It takes effect the next time they sign in.';
        }
    } elseif (isset($_POST['toggle_active'])) {
        if ($isSelf) {
            $error = 'You cannot deactivate your own account.';
        } else {
            $pdo->prepare("UPDATE users SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?")
                ->execute([$targetId]);
            $notice = 'Account status updated.';
        }
    }
}

$users = $pdo->query("
    SELECT u.id, u.email, u.display_name, u.first_name, u.last_name,
           u.role, u.is_active, u.created_at, u.last_login_at,
           u.employee_id, e.first_name AS emp_first, e.last_name AS emp_last
    FROM users u
    LEFT JOIN employees e ON e.id = u.employee_id
    ORDER BY CASE u.role WHEN 'admin' THEN 1 WHEN 'editor' THEN 2 ELSE 3 END,
             u.last_name, u.first_name, u.email
")->fetchAll();

$counts = ['admin' => 0, 'editor' => 0, 'viewer' => 0];
foreach ($users as $u) { $counts[$u['role']] = ($counts[$u['role']] ?? 0) + 1; }

$ROLE_HELP = [
    'viewer' => 'View the directory. Cannot change anything.',
    'editor' => 'Edit site records, roles and VP leads.',
    'admin'  => 'Everything, plus EIR exceptions and these role assignments.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users &amp; Roles</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <?php eaer_head_assets(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[var(--bg)] min-h-screen">

<a href="#main-content" class="skip-link">Skip to users</a>

<?php require __DIR__ . '/preview_bar.php'; ?>

<div id="topbar" role="banner" class="bg-[var(--topbar)] shadow-sm">
    <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <img src="utsa-logo.svg" alt="UT San Antonio" height="20">
            <h1 class="font-brand text-lg font-bold text-[var(--topbar-text)]">Users &amp; Roles</h1>
        </div>
        <div class="flex gap-3">
            <a href="app.php" class="text-sm text-[var(--topbar-subtle)] hover:underline self-center">← Website Governance</a>
            <?= eaer_theme_toggle() ?>
        </div>
    </div>
</div>

<main id="main-content" class="max-w-6xl mx-auto px-6 py-6">

<?php if ($notice): ?>
<div class="bg-[var(--surface)] border border-[var(--success)] text-[var(--success)] text-sm rounded-lg px-4 py-3 mb-4"><?= h($notice) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="bg-[var(--surface)] border border-[var(--danger)] text-[var(--danger-text)] text-sm rounded-lg px-4 py-3 mb-4"><?= h($error) ?></div>
<?php endif; ?>

<div class="bg-[var(--surface)] rounded-xl shadow-sm px-6 py-5 mb-6">
    <h2 class="font-brand font-bold text-[var(--heading)] mb-2">How access works here</h2>
    <p class="text-sm text-[var(--text)] mb-3">
        Any UT San Antonio employee can sign in — UTS controls that, not this application.
        Everyone starts as a <strong>Viewer</strong> and appears in this list automatically after
        their first sign-in. Granting someone more than read access is the only thing done here.
    </p>
    <ul class="text-sm text-[var(--text)] list-disc list-outside pl-5 space-y-1">
        <?php foreach ($ROLE_HELP as $r => $desc): ?>
        <li><strong class="capitalize"><?= h($r) ?></strong> — <?= h($desc) ?> (<?= (int)($counts[$r] ?? 0) ?>)</li>
        <?php endforeach; ?>
    </ul>
    <p class="text-xs text-[var(--muted)] mt-3">
        A role change takes effect the next time that person signs in, because the role is read
        into their session at login.
    </p>
</div>

<div class="bg-[var(--surface)] rounded-xl shadow-sm overflow-hidden">
    <table class="w-full text-sm">
        <caption class="sr-only">All users who have signed in, and their roles</caption>
        <thead class="bg-[var(--surface-2)] text-left text-xs text-[var(--muted)] uppercase">
            <tr>
                <th scope="col" class="px-4 py-3">Name</th>
                <th scope="col" class="px-4 py-3">Email</th>
                <th scope="col" class="px-4 py-3">Directory entry</th>
                <th scope="col" class="px-4 py-3">Last sign-in</th>
                <th scope="col" class="px-4 py-3">Role</th>
                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-[var(--border)]">
            <?php if (!$users): ?>
            <tr><td colspan="6" class="px-4 py-6 text-center text-[var(--muted)]">
                Nobody has signed in yet. Users appear here after their first sign-in.
            </td></tr>
            <?php endif; ?>
            <?php foreach ($users as $u):
                $isSelf = $me['id'] !== null && (int)$u['id'] === (int)$me['id']; ?>
            <tr<?= empty($u['is_active']) ? ' class="opacity-50"' : '' ?>>
                <td class="px-4 py-3">
                    <div class="font-medium text-[var(--text)]">
                        <?= h($u['display_name'] ?: trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: '—') ?>
                        <?php if ($isSelf): ?><span class="text-xs text-[var(--muted)]">(you)</span><?php endif; ?>
                    </div>
                    <?php if (empty($u['is_active'])): ?>
                        <div class="text-xs text-[var(--danger-text)]">Deactivated</div>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-[var(--muted)]"><?= h($u['email'] ?: '—') ?></td>
                <td class="px-4 py-3 text-[var(--muted)]">
                    <?php if ($u['employee_id']): ?>
                        <?= h(trim(($u['emp_first'] ?? '') . ' ' . ($u['emp_last'] ?? ''))) ?>
                    <?php else: ?>
                        <span title="This person is not listed in the employees directory. That is normal and does not affect their access.">Not linked</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-[var(--muted)]">
                    <?= $u['last_login_at'] ? h(date('M j, Y g:ia', strtotime($u['last_login_at']))) : '—' ?>
                </td>
                <td class="px-4 py-3">
                    <form method="post" class="inline">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <label class="sr-only" for="role-<?= (int)$u['id'] ?>">Role for <?= h($u['email']) ?></label>
                        <select id="role-<?= (int)$u['id'] ?>" name="new_role" onchange="this.form.submit()"
                                <?= $isSelf ? 'disabled title="You cannot change your own role"' : '' ?>
                                class="border border-[var(--field-border)] rounded-lg px-2 py-1 text-sm bg-[var(--input-bg)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                            <?php foreach (array_keys($ROLE_HELP) as $r): ?>
                            <option value="<?= h($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="text-xs underline">Save</button></noscript>
                    </form>
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap">
                    <?php if (!$isSelf): ?>
                    <form method="post" class="inline"
                          onsubmit="return confirm('<?= empty($u['is_active']) ? 'Reactivate' : 'Deactivate' ?> this account?');">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <input type="hidden" name="toggle_active" value="1">
                        <button type="submit" class="<?= empty($u['is_active']) ? 'text-[var(--accent)]' : 'text-[var(--danger-text)]' ?> hover:underline">
                            <?= empty($u['is_active']) ? 'Reactivate' : 'Deactivate' ?>
                        </button>
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
