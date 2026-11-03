<?php
/**
 * Is PHP actually recording errors anywhere? CLI only.
 *
 *   C:\PHP\php.exe C:\websites\governance\saml\check-logging.php
 *
 * A 500 with nothing in a log is usually not a mystery — it is log_errors
 * off, or error_log unset so output goes to stderr and IIS discards it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$ini = php_ini_loaded_file();
echo "php.ini in use : ", $ini ?: '(none)', "\n";
echo "log_errors     : ", ini_get('log_errors') ? 'On' : 'OFF  <-- errors are being discarded', "\n";
echo "error_log      : ", ini_get('error_log') ?: '(unset — goes to stderr, which IIS drops)', "\n";
echo "display_errors : ", ini_get('display_errors') ? 'On' : 'Off (correct for production)', "\n";
echo "error_reporting: ", error_reporting(), "\n\n";

$target = ini_get('error_log');
if ($target && $target !== 'syslog') {
    $dir = dirname($target);
    echo "log directory  : {$dir}\n";
    echo "  exists       : ", is_dir($dir) ? 'yes' : 'NO', "\n";
    echo "  writable     : ", is_writable($dir) ? 'yes' : 'NO  <-- IIS_IUSRS needs Modify here', "\n";
    if (is_file($target)) {
        echo "  file size    : ", number_format(filesize($target)), " bytes\n";
        echo "  last written : ", date('Y-m-d H:i:s', filemtime($target)), "\n";
    } else {
        echo "  file         : does not exist yet\n";
    }
}

error_log('SAML ACS [TEST00] logging check: if you can see this line, PHP error logging works.');
echo "\nWrote a test line. If error_log is set, look for [TEST00] in that file.\n";

if (!ini_get('log_errors') || !ini_get('error_log')) {
    echo "\nTo fix, add to php.ini and run iisreset:\n";
    echo "  log_errors = On\n";
    echo "  error_log = \"C:\\\\PHP\\\\logs\\\\php_errors.log\"\n";
    echo "Create C:\\PHP\\logs and grant IIS_IUSRS Modify on it — the FastCGI\n";
    echo "worker runs as an IIS identity, not as you.\n";
}
