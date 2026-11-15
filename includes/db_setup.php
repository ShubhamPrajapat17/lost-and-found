<?php
/**
 * includes/db_setup.php
 * First-run database setup + automatic upgrades.
 *
 * Called from getDB() (config.php). On a brand-new database it creates every
 * table from db_schema.sql. On an older database it adds whatever columns /
 * tables the newer code needs (theme, report claims, user blocking, contact
 * messages). Each upgrade is idempotent, and a marker row in
 * `schema_migrations` makes later requests skip all of this with one cheap
 * query.
 *
 * You can still import db_schema.sql by hand if you prefer; both routes
 * produce the same result.
 */

/** Bump when you add a new upgrade step below. */
const SCHEMA_VERSION = '2026_10_29_support_blocking';

/**
 * Split a .sql file into single statements. Understands -- comments and
 * quoted strings, so a ';' inside a string or comment doesn't break things.
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $quote = null;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];

        if ($quote !== null) {
            $buffer .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $buffer .= $sql[++$i];
            } elseif ($c === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buffer .= $c;
            continue;
        }
        if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {      // -- line comment
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($c === ';') {
            if (trim($buffer) !== '') {
                $statements[] = trim($buffer);
            }
            $buffer = '';
            continue;
        }
        $buffer .= $c;
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

function dbColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function dbTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Add a column only if it is missing. $definition is everything after the name. */
function dbEnsureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!dbColumnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function ensureDatabaseReady(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Fast path: already upgraded to the current version.
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE name = ?');
        $stmt->execute([SCHEMA_VERSION]);
        if ($stmt->fetchColumn()) {
            return;
        }
    } catch (Throwable $e) {
        // schema_migrations doesn't exist yet -> fall through and set up.
    }

    // 1) Create any missing tables (every statement is CREATE TABLE IF NOT EXISTS).
    $schemaFile = __DIR__ . '/../db_schema.sql';
    if (is_readable($schemaFile)) {
        foreach (splitSqlStatements((string) file_get_contents($schemaFile)) as $statement) {
            // The database name is controlled by config.php, not by the .sql file.
            if (preg_match('/^\s*(CREATE\s+DATABASE|USE)\b/i', $statement)) {
                continue;
            }
            $pdo->exec($statement);
        }
    }

    // 2) Upgrade tables that were created by an older version of the app.
    dbEnsureColumn($pdo, 'users', 'theme',
        "ENUM('default','dark','light') NOT NULL DEFAULT 'default' AFTER profile_image");
    dbEnsureColumn($pdo, 'users', 'blocked_reason', 'VARCHAR(255) DEFAULT NULL');
    dbEnsureColumn($pdo, 'users', 'blocked_at', 'DATETIME DEFAULT NULL');

    dbEnsureColumn($pdo, 'items', 'admin_claimed_by', 'INT DEFAULT NULL');
    dbEnsureColumn($pdo, 'items', 'admin_claimed_at', 'DATETIME DEFAULT NULL');
    dbEnsureColumn($pdo, 'items', 'owner_claimed_at', 'DATETIME DEFAULT NULL');

    // 3) Record that this version is in place.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(100) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );
    $pdo->prepare('INSERT IGNORE INTO schema_migrations (name) VALUES (?)')->execute([SCHEMA_VERSION]);
}
