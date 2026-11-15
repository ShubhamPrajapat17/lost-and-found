<?php
/**
 * config.php
 * Central configuration: database connection (PDO) + cloud storage settings.
 * Edit the values below to match your environment before deploying.
 */

// ---------------------------------------------------------------------
// Error reporting (turn OFF display_errors in production, log instead)
// ---------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ---------------------------------------------------------------------
// Database configuration
// ---------------------------------------------------------------------
// Your database login is read from the FIRST of these files that exists:
//   1. config.local.php            (inside the project folder)
//   2. ../lostfound.db.php         (next to the project folder, e.g. in htdocs)
// setup.php writes BOTH. The second one exists because people replace the
// whole project folder when they install an updated zip, which deletes file 1
// and used to bring back "Access denied for user 'root'" every single time.
// Both files are plain PHP (they only define 4 constants and print nothing).
// You can also create one by hand: copy the 4 define() lines below into it.
foreach ([__DIR__ . '/config.local.php', dirname(__DIR__) . '/lostfound.db.php'] as $__cfg) {
    if (is_file($__cfg)) {
        require $__cfg;
        break;
    }
}
unset($__cfg);
defined('DB_HOST') || define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
defined('DB_NAME') || define('DB_NAME', getenv('DB_NAME') ?: 'lost_and_found');
defined('DB_USER') || define('DB_USER', getenv('DB_USER') ?: 'root');
defined('DB_PASS') || define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------
// Works out the site's base URL from the current request, so it keeps
// working whatever folder name or port you run it on (XAMPP, php -S, ...).
// Set the APP_URL environment variable to override (e.g. behind a proxy).
// ---------------------------------------------------------------------
function detectAppUrl(): string
{
    if (empty($_SERVER['HTTP_HOST'])) {
        return 'http://localhost/lost-and-found'; // CLI fallback
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $appDir = realpath(__DIR__);
    $path = '';
    if ($docRoot && $appDir && strpos($appDir, $docRoot) === 0) {
        $path = str_replace('\\', '/', substr($appDir, strlen($docRoot)));
    }
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $path;
}

// ---------------------------------------------------------------------
// App configuration
// ---------------------------------------------------------------------
define('APP_NAME', 'Lost & Found');
define('APP_URL', rtrim(getenv('APP_URL') ?: detectAppUrl(), '/'));
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', APP_URL . '/uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('ALLOWED_DOC_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);

// ---------------------------------------------------------------------
// Cloud storage configuration (Cloudinary or AWS S3)
// Set STORAGE_DRIVER to 'local', 'cloudinary', or 's3'.
// When 'local', files are saved into UPLOAD_DIR and served via UPLOAD_URL.
// The upload_to_cloud() helper in includes/functions.php reads these.
// ---------------------------------------------------------------------
define('STORAGE_DRIVER', getenv('STORAGE_DRIVER') ?: 'local');

// Cloudinary settings (https://cloudinary.com/documentation/php_integration)
define('CLOUDINARY_CLOUD_NAME', getenv('CLOUDINARY_CLOUD_NAME') ?: '');
define('CLOUDINARY_API_KEY', getenv('CLOUDINARY_API_KEY') ?: '');
define('CLOUDINARY_API_SECRET', getenv('CLOUDINARY_API_SECRET') ?: '');
define('CLOUDINARY_UPLOAD_PRESET', getenv('CLOUDINARY_UPLOAD_PRESET') ?: '');

// AWS S3 settings (requires aws/aws-sdk-php via Composer if enabled)
define('AWS_S3_BUCKET', getenv('AWS_S3_BUCKET') ?: '');
define('AWS_S3_REGION', getenv('AWS_S3_REGION') ?: 'us-east-1');
define('AWS_ACCESS_KEY_ID', getenv('AWS_ACCESS_KEY_ID') ?: '');
define('AWS_SECRET_ACCESS_KEY', getenv('AWS_SECRET_ACCESS_KEY') ?: '');

// ---------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------------
// Database
//   DB_AUTO_SETUP: when true (default) the app creates the database and
//   tables on first run and applies small upgrades automatically, so
//   "Unknown database" / "table doesn't exist" errors can't happen after a
//   fresh copy. Set the DB_AUTO_SETUP=0 environment variable to turn off.
//   APP_DEBUG: show the real database error on screen. Defaults to ON only
//   for requests from your own machine (localhost), never for visitors.
// ---------------------------------------------------------------------
define('DB_AUTO_SETUP', getenv('DB_AUTO_SETUP') !== '0');
define('APP_DEBUG', getenv('APP_DEBUG') !== false
    ? getenv('APP_DEBUG') === '1'
    : in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true));

require_once __DIR__ . '/includes/db_setup.php';

/** Stop with a clear error. JSON for API calls, a readable page otherwise. */
function dbFail(Throwable $e): void
{
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);

    $wantsJson = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false
        || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    $message = 'Database connection failed. Please try again later.';

    if ($wantsJson) {
        header('Content-Type: application/json');
        $out = ['success' => false, 'message' => $message];
        if (APP_DEBUG) {
            $out['debug'] = $e->getMessage();
        }
        die(json_encode($out));
    }

    $detail = APP_DEBUG
        ? '<p><strong>Details (visible only on localhost):</strong></p><pre style="white-space:pre-wrap;background:#f3f4f6;padding:12px;border-radius:8px;">'
            . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>'
            . '<p><a href="' . htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') . '/setup.php" style="display:inline-block;background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600">Fix it: open the database setup page &rarr;</a></p>'
            . '<ul><li>Is MySQL / MariaDB running? (XAMPP: start <em>MySQL</em> in the control panel)</li>'
            . '<li>Wrong password? The setup page lets you enter your MySQL login and saves it for you.</li>'
            . '<li>It was working before? Then an update probably replaced your saved login. Open the setup page once and it will be remembered across future updates.</li></ul>'
        : '';
    header('Content-Type: text/html; charset=UTF-8');
    die('<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="color-scheme" content="light dark">'
        . '<title>Database error</title></head><body style="font-family:system-ui,sans-serif;max-width:640px;margin:60px auto;padding:0 20px;line-height:1.6">'
        . '<h2>Database connection failed</h2><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>' . $detail . '</body></html>');
}

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $serverDsn = 'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET;

    try {
        try {
            $pdo = new PDO($serverDsn . ';dbname=' . DB_NAME, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // 1049 = "Unknown database": create it on first run.
            $unknownDb = (int) $e->getCode() === 1049 || stripos($e->getMessage(), 'unknown database') !== false;
            if (!$unknownDb || !DB_AUTO_SETUP) {
                throw $e;
            }
            $server = new PDO($serverDsn, DB_USER, DB_PASS, $options);
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME)
                . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = new PDO($serverDsn . ';dbname=' . DB_NAME, DB_USER, DB_PASS, $options);
        }
    } catch (PDOException $e) {
        dbFail($e);
    }

    if (DB_AUTO_SETUP) {
        try {
            ensureDatabaseReady($pdo);
        } catch (Throwable $e) {
            // Never take the whole site down because an upgrade step failed.
            error_log('Database setup/upgrade failed: ' . $e->getMessage());
            if (APP_DEBUG) {
                error_log('Run db_schema.sql manually if tables are missing.');
            }
        }
    }
    return $pdo;
}
