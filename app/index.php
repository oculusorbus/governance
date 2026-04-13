<?php
session_start();
require_once 'config.php';

if (!empty($_SESSION['auth'])) {
    header('Location: app.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['password']) && hash_equals(APP_PASSWORD, $_POST['password'])) {
        $_SESSION['auth'] = true;
        header('Location: app.php');
        exit;
    }
    $error = 'Invalid password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Governance — Login</title>
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
        <form method="post" autocomplete="off">
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input type="password" name="password" autofocus
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent mb-4">
            <button type="submit"
                    class="w-full bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-medium py-2 rounded-lg text-sm transition-colors">
                Sign In
            </button>
        </form>
    </div>
</body>
</html>
