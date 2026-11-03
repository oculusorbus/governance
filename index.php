<?php
session_start();
require_once 'config.php';

if (!empty($_SESSION['auth'])) {
    header('Location: app.php');
    exit;
}

/**
 * The shared-password login predates SSO and is kept only as a fallback for
 * the Web Team while SSO settles.
 *
 * Set APP_PASSWORD_LOGIN to false in config.php to turn it off completely —
 * the form is not rendered AND the POST is refused, because hiding the
 * markup while leaving the handler live would not actually disable
 * anything.
 *
 * It defaults to ENABLED when the constant is absent, deliberately. A
 * default of off would disable the only non-SSO way in the moment this code
 * is deployed, which is exactly the wrong failure mode if Entra, the VPN,
 * or the SAML configuration turns out to be broken. Re-enabling is a
 * one-line edit to config.php over RDP.
 */
$passwordLoginEnabled = !defined('APP_PASSWORD_LOGIN') || APP_PASSWORD_LOGIN;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$passwordLoginEnabled) {
        http_response_code(403);
        $error = 'Password sign-in is disabled. Please use single sign-on.';
    } elseif (isset($_POST['password']) && hash_equals(APP_PASSWORD, $_POST['password'])) {
        $_SESSION['auth'] = true;
        header('Location: app.php');
        exit;
    } else {
        $error = 'Invalid password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Governance — Login</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center">
    <div class="bg-white rounded-xl shadow-lg p-8 w-full max-w-sm">
        <h1 class="text-2xl font-bold text-gray-800 mb-1 text-center">Website Governance</h1>
        <p class="text-sm text-gray-400 text-center mb-6">UTSA Web Directory</p>
        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 rounded-lg px-4 py-3 mb-4 text-sm">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php
        // SSO is the primary route. The shared password stays as a fallback
        // until SSO is proven in daily use — it is also the only way in if
        // Entra or the VPN path to it is unavailable. Remove it, and the
        // APP_PASSWORD branch in auth.php, once that confidence exists.
        $ssoReturn = isset($_GET['return']) ? '?return=' . rawurlencode((string)$_GET['return']) : '';
        ?>
        <a href="saml/login.php<?= htmlspecialchars($ssoReturn, ENT_QUOTES) ?>"
           class="block w-full bg-[#0F3B82] hover:bg-[#15509E] text-white font-medium py-2 rounded-lg text-sm text-center transition-colors<?= $passwordLoginEnabled ? ' mb-5' : '' ?>">
            Sign in with UT San Antonio
        </a>

        <?php if ($passwordLoginEnabled): ?>
        <div class="flex items-center gap-3 mb-5" aria-hidden="true">
            <span class="h-px bg-gray-200 flex-1"></span>
            <span class="text-xs text-gray-400">or</span>
            <span class="h-px bg-gray-200 flex-1"></span>
        </div>

        <form method="post" autocomplete="off">
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input type="password" name="password" autofocus
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent mb-4">
            <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-medium py-2 rounded-lg text-sm transition-colors">
                Sign In
            </button>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>
