<?php
/**
 * setup.php - one-time database login setup (localhost only).
 *
 * Use this when the site says "Access denied for user 'root'@'localhost'".
 * Enter the MySQL username and password you use for phpMyAdmin. The page
 * tests the login, creates the database if needed, and saves the details to
 * config.local.php so you never have to edit config.php.
 *
 * It refuses to run for anyone who isn't browsing from the same computer,
 * so it is safe to leave in place on your own machine. DELETE this file
 * before putting the site on a public server.
 */
require_once __DIR__ . '/includes/functions.php'; // also loads config.php (no DB connection is made)

$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$local) {
    http_response_code(403);
    exit('The setup page only works when opened from the same computer that runs the site (localhost). '
       . 'On a live server, edit config.php or set the DB_* environment variables instead.');
}

$msg = '';
$ok = false;
$keptOutside = false;
$values = [
    'host' => DB_HOST,
    'user' => DB_USER,
    'name' => DB_NAME,
    'pass' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $msg = 'Session expired. Please reload this page and try again.';
    } else {
        $values['host'] = trim($_POST['host'] ?? '') ?: 'localhost';
        $values['user'] = trim($_POST['user'] ?? '') ?: 'root';
        $values['name'] = trim($_POST['name'] ?? '') ?: 'lost_and_found';
        $values['pass'] = (string) ($_POST['pass'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $values['name'])) {
            $msg = 'Database name may only contain letters, numbers, _ and -.';
        } else {
            try {
                $pdo = new PDO('mysql:host=' . $values['host'] . ';charset=utf8mb4', $values['user'], $values['pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                try {
                    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $values['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                } catch (PDOException $e) {
                    // This user may not be allowed to create databases. That's fine
                    // if the database already exists, so check by connecting to it.
                    new PDO('mysql:host=' . $values['host'] . ';dbname=' . $values['name'] . ';charset=utf8mb4',
                        $values['user'], $values['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                }

                $php = "<?php\n// Written by setup.php. Your database login. Keep this file private.\n"
                     . 'define(\'DB_HOST\', ' . var_export($values['host'], true) . ");\n"
                     . 'define(\'DB_NAME\', ' . var_export($values['name'], true) . ");\n"
                     . 'define(\'DB_USER\', ' . var_export($values['user'], true) . ");\n"
                     . 'define(\'DB_PASS\', ' . var_export($values['pass'], true) . ");\n";

                // Second copy outside the project folder so a re-extracted zip can't wipe it.
                $kept = @file_put_contents(dirname(__DIR__) . '/lostfound.db.php', $php) !== false;

                if (@file_put_contents(__DIR__ . '/config.local.php', $php) === false && !$kept) {
                    $msg = 'Login works, but this page could not save config.local.php (folder not writable). '
                         . 'Create a file named config.local.php next to config.php with exactly this content:' . "\n\n" . $php;
                } else {
                    $ok = true;
                    $keptOutside = $kept;
                }
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (strpos($msg, '1045') !== false) {
                    $msg .= "\n\nMySQL says that username/password pair is wrong. Use the same login that works in phpMyAdmin. "
                          . "Common ones: user root with an EMPTY password (XAMPP), or root / root (MAMP).";
                } elseif (strpos($msg, '2002') !== false) {
                    $msg .= "\n\nMySQL is not running or the host is wrong. Start MySQL in XAMPP/MAMP, or try host 127.0.0.1.";
                }
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark"><title>Database setup</title>
<style>
 body{font-family:system-ui,sans-serif;max-width:520px;margin:48px auto;padding:0 20px;line-height:1.55}
 label{display:block;font-weight:600;margin:14px 0 4px}
 input{width:100%;padding:10px;font-size:1rem;border:1px solid #9ca3af;border-radius:8px;box-sizing:border-box}
 button,a.btn{display:inline-block;margin-top:18px;background:#2563eb;color:#fff;border:0;border-radius:8px;padding:11px 20px;font-size:1rem;font-weight:600;cursor:pointer;text-decoration:none}
 pre{white-space:pre-wrap;background:rgba(220,38,38,.12);padding:12px;border-radius:8px}
 .ok{background:rgba(22,163,74,.15);padding:14px;border-radius:8px}
 small{opacity:.75}
</style></head><body>
<h2>Database setup</h2>
<?php if ($ok): ?>
  <div class="ok"><strong>Connected and saved.</strong> The database <code><?= h($values['name']) ?></code> is ready; the tables are created automatically when you open the site.</div>
  <a class="btn" href="index.php">Open the site &rarr;</a>
  <p><small>Your login is saved in <code>config.local.php</code><?= $keptOutside ? ' and also in <code>' . h(basename(dirname(__DIR__))) . '/lostfound.db.php</code>, so installing a new zip won\'t make you enter it again' : '' ?>. You can delete <code>setup.php</code> once you're done.</small></p>
<?php else: ?>
  <p>Enter the MySQL login you use for phpMyAdmin. It is tested first, then saved.</p>
  <?php if ($msg): ?><pre><?= h($msg) ?></pre><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
    <label>MySQL host</label><input name="host" value="<?= h($values['host']) ?>">
    <label>MySQL username</label><input name="user" value="<?= h($values['user']) ?>">
    <label>MySQL password <small>(leave empty if you have none)</small></label><input type="password" name="pass" value="">
    <label>Database name</label><input name="name" value="<?= h($values['name']) ?>">
    <button type="submit">Test &amp; save</button>
  </form>
<?php endif; ?>
</body></html>
