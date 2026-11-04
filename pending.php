<?php
/**
 * Holding page for an authenticated account that has not been approved.
 *
 * Deliberately not a 403. The person has done nothing wrong and nothing is
 * broken — their account exists and is waiting on a decision. A refusal
 * page would read as a failure and generate support traffic.
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/eaer_db.php';   // head assets + theme tokens only

// current_user(), not require_login(): require_login() redirects pending
// accounts here, so calling it would loop.
$me = current_user();
if ($me === null) {
    header('Location: /saml/login.php?return=/app.php');
    exit;
}

// Already approved — nothing to wait for.
if (($me['role'] ?? '') !== 'pending') {
    header('Location: /app.php');
    exit;
}

function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Pending</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <?php eaer_head_assets(); ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[var(--bg)] min-h-screen flex items-center justify-center px-4">

<main class="bg-[var(--surface)] rounded-xl shadow-lg p-8 w-full max-w-lg">
    <div class="flex items-center justify-between gap-2 mb-4">
        <img src="utsa-logo.svg" alt="UT San Antonio" height="18">
        <?= eaer_theme_toggle(false) ?>
    </div>

    <h1 class="font-brand text-xl font-bold text-[var(--heading)] mb-2">
        Your account is set up
    </h1>

    <p class="text-sm text-[var(--text)] mb-4">
        You've signed in successfully and your account has been created. An
        administrator needs to approve your access to the Website Governance
        Directory before you can use it.
    </p>

    <div class="bg-[var(--surface-2)] border border-[var(--border)] rounded-lg px-4 py-3 mb-4 text-sm">
        <div class="text-[var(--text)] font-medium"><?= h($me['display_name'] ?: '—') ?></div>
        <div class="text-[var(--muted)] text-xs"><?= h($me['email'] ?: '—') ?></div>
    </div>

    <p class="text-sm text-[var(--muted)] mb-6">
        Nothing further is needed from you — the request is already in the
        administrators' queue. If it's urgent, contact the Web Team directly
        and mention the email address above.
    </p>

    <a href="/logout.php"
       class="inline-block text-sm text-[var(--accent)] hover:underline">Sign out</a>
</main>

</body>
</html>
