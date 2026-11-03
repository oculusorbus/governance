<?php
/**
 * Why didn't an SSO login match an employees row? CLI only.
 *
 *   C:\PHP\php.exe C:\websites\governance\saml\diagnose-match.php jeremiah.barber@utsa.edu
 *
 * Reports on the address the assertion carried, plus the shape of the email
 * column generally, so a format mismatch is obvious rather than inferred.
 * Deliberately does not dump the whole table — it prints domain counts and
 * only rows that plausibly relate to the address given.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../config.php';

$target = trim((string)($argv[1] ?? ''));
if ($target === '') { exit("Usage: php diagnose-match.php <email-from-assertion>\n"); }

$pdo = new PDO(
    'sqlsrv:Server=' . DB_HOST . ';Database=' . DB_NAME,
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

echo "Looking for: {$target}\n", str_repeat('=', 64), "\n\n";

$total = $pdo->query("SELECT COUNT(*) c FROM employees")->fetch()['c'];
$blank = $pdo->query("SELECT COUNT(*) c FROM employees WHERE email IS NULL OR LTRIM(RTRIM(email)) = ''")->fetch()['c'];
echo "employees rows        : {$total}\n";
echo "with no email         : {$blank}\n\n";

// 1. Exact match, as the ACS does it.
$stmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM employees WHERE email = ?");
$stmt->execute([$target]);
$exact = $stmt->fetchAll();
echo "1. Exact match (what acs.php does): ", count($exact), " row(s)\n";
foreach ($exact as $r) { echo "     id={$r['id']} {$r['first_name']} {$r['last_name']} <{$r['email']}>\n"; }

// 2. Trimmed match — catches stored leading/trailing whitespace.
$stmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM employees WHERE LTRIM(RTRIM(email)) = ?");
$stmt->execute([$target]);
$trimmed = $stmt->fetchAll();
echo "\n2. Trimmed match: ", count($trimmed), " row(s)";
echo count($trimmed) > count($exact) ? "  <-- stored value has stray whitespace\n" : "\n";

// 3. Same local part, any domain — the likely case if the convention differs.
$local = explode('@', $target)[0];
$stmt = $pdo->prepare("SELECT id, first_name, last_name, email FROM employees WHERE email LIKE ?");
$stmt->execute([$local . '@%']);
$byLocal = $stmt->fetchAll();
echo "\n3. Same local part '{$local}@...': ", count($byLocal), " row(s)\n";
foreach ($byLocal as $r) { echo "     id={$r['id']} {$r['first_name']} {$r['last_name']} <{$r['email']}>\n"; }

// 4. By surname — catches a completely different address convention.
$surname = explode('.', $local)[count(explode('.', $local)) - 1];
$stmt = $pdo->prepare("SELECT TOP (10) id, first_name, last_name, email FROM employees WHERE last_name LIKE ?");
$stmt->execute(['%' . $surname . '%']);
$bySurname = $stmt->fetchAll();
echo "\n4. Surname like '{$surname}': ", count($bySurname), " row(s)\n";
foreach ($bySurname as $r) { echo "     id={$r['id']} {$r['first_name']} {$r['last_name']} <{$r['email']}>\n"; }

// 5. What do addresses in this table actually look like?
echo "\n5. Email domains in use:\n";
$rows = $pdo->query("
    SELECT LOWER(SUBSTRING(email, CHARINDEX('@', email) + 1, 200)) AS domain, COUNT(*) AS c
    FROM employees
    WHERE email LIKE '%@%'
    GROUP BY LOWER(SUBSTRING(email, CHARINDEX('@', email) + 1, 200))
    ORDER BY COUNT(*) DESC
")->fetchAll();
foreach ($rows as $r) { printf("     %-40s %d\n", $r['domain'], $r['c']); }

// 6. Local-part convention, inferred from a small sample.
echo "\n6. Local-part shape (sample of 5, names masked):\n";
$rows = $pdo->query("SELECT TOP (5) email FROM employees WHERE email LIKE '%@%' ORDER BY id")->fetchAll();
foreach ($rows as $r) {
    $parts = explode('@', $r['email']);
    $shape = preg_replace('/[a-z]+/i', 'x', $parts[0]);
    $shape = preg_replace('/\d+/', '9', $shape);
    printf("     %-20s (e.g. shape of local part)\n", $shape);
}
echo "\n";
