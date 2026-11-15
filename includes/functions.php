<?php
/**
 * includes/functions.php
 * Shared helper functions used across the application.
 */

require_once __DIR__ . '/../config.php';

/** Escape output for safe HTML rendering. */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Send a JSON response and terminate. */
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** Redirect helper. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Generate / validate CSRF token. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(?string $token): bool
{
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Escape the LIKE wildcards % and _ so user input is matched literally.
 * Uses "!" as the escape character, so pair it with ESCAPE '!' in the SQL:
 *     col LIKE ? ESCAPE '!'
 * (A backslash escape character is fragile because MySQL, MariaDB and the
 * PHP string layer each treat backslashes differently.)
 * Without this, searching for "50%" or "a_b" silently matches everything.
 */
function escapeLike(string $value): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
}

/** True if $value is a real calendar date in YYYY-MM-DD form. */
function isValidDate(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

/** The allowed UI themes. 'default' follows the visitor's OS preference. */
function allowedThemes(): array
{
    return ['default', 'dark', 'light'];
}

/** Coerce any value to a valid theme name. */
function normalizeTheme(?string $theme): string
{
    return in_array($theme, allowedThemes(), true) ? $theme : 'default';
}

/** Mask an email address for public display, e.g. jo***@example.com */
function maskEmail(string $email): string
{
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return '***';
    }
    [$name, $domain] = $parts;
    $strlen = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
    $substr = function_exists('mb_substr') ? 'mb_substr' : 'substr';
    $nameLen = $strlen($name);
    $visible = $substr($name, 0, min(2, $nameLen));
    return $visible . str_repeat('*', max(3, $nameLen - 2)) . '@' . $domain;
}

/** Mask a phone number for public display, e.g. ******7890 */
function maskPhone(string $phone): string
{
    $len = strlen($phone);
    if ($len <= 4) {
        return str_repeat('*', $len);
    }
    return str_repeat('*', $len - 4) . substr($phone, -4);
}

/**
 * Handle a file upload and store it either locally or via the configured
 * cloud storage driver. Returns ['path' => localPathOrNull, 'url' => publicUrl].
 */
function uploadFile(array $file, array $allowedTypes = null): array
{
    $allowedTypes = $allowedTypes ?? ALLOWED_IMAGE_TYPES;

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload failed with error code ' . ($file['error'] ?? 'unknown'));
    }
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        throw new RuntimeException('File exceeds maximum allowed size of ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowedTypes, true)) {
        throw new RuntimeException('Unsupported file type: ' . $mime);
    }

    // Pick the extension from the *verified* MIME type, never from the
    // client-supplied filename. Otherwise an image-looking file named
    // "shell.php" would be saved as .php and could be executed.
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Unsupported file type: ' . $mime);
    }
    $safeName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];

    if (STORAGE_DRIVER === 'cloudinary') {
        return uploadToCloudinary($file['tmp_name'], $safeName);
    }
    if (STORAGE_DRIVER === 's3') {
        return uploadToS3($file['tmp_name'], $safeName, $mime);
    }

    // Default: local storage
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $destination = UPLOAD_DIR . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Failed to move uploaded file.');
    }

    return ['path' => 'uploads/' . $safeName, 'url' => UPLOAD_URL . $safeName];
}

/**
 * Upload a file to Cloudinary using the unsigned upload preset.
 * Requires CLOUDINARY_* constants to be configured in config.php.
 */
function uploadToCloudinary(string $tmpPath, string $fileName): array
{
    if (empty(CLOUDINARY_CLOUD_NAME) || empty(CLOUDINARY_UPLOAD_PRESET)) {
        throw new RuntimeException('Cloudinary is not configured. Set CLOUDINARY_* values in config.php.');
    }
    $url = "https://api.cloudinary.com/v1_1/" . CLOUDINARY_CLOUD_NAME . "/auto/upload";
    $cfile = new CURLFile($tmpPath, mime_content_type($tmpPath), $fileName);
    $postFields = [
        'file' => $cfile,
        'upload_preset' => CLOUDINARY_UPLOAD_PRESET,
        'public_id' => pathinfo($fileName, PATHINFO_FILENAME),
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Cloudinary upload failed: ' . $error);
    }
    $data = json_decode($response, true);
    if (empty($data['secure_url'])) {
        throw new RuntimeException('Cloudinary upload failed: ' . ($data['error']['message'] ?? 'unknown error'));
    }
    return ['path' => null, 'url' => $data['secure_url']];
}

/**
 * Upload a file to AWS S3.
 * Requires the AWS SDK for PHP (composer require aws/aws-sdk-php) and
 * AWS_* constants configured in config.php. This function assumes the
 * S3Client class is autoloaded via vendor/autoload.php if present.
 */
function uploadToS3(string $tmpPath, string $fileName, string $mime): array
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload) || empty(AWS_S3_BUCKET)) {
        throw new RuntimeException('AWS S3 is not configured. Install aws/aws-sdk-php and set AWS_* values in config.php.');
    }
    require_once $autoload;

    $s3 = new Aws\S3\S3Client([
        'version' => 'latest',
        'region' => AWS_S3_REGION,
        'credentials' => [
            'key' => AWS_ACCESS_KEY_ID,
            'secret' => AWS_SECRET_ACCESS_KEY,
        ],
    ]);

    $key = 'lost-and-found/' . $fileName;
    $result = $s3->putObject([
        'Bucket' => AWS_S3_BUCKET,
        'Key' => $key,
        'SourceFile' => $tmpPath,
        'ContentType' => $mime,
        'ACL' => 'public-read',
    ]);

    return ['path' => null, 'url' => (string) $result['ObjectURL']];
}

/** Record an entry in the activity log. */
function logActivity(?int $userId, string $action, ?string $referenceType = null, ?int $referenceId = null): void
{
    try {
        $stmt = getDB()->prepare(
            'INSERT INTO activity_log (user_id, action, reference_type, reference_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $action, $referenceType, $referenceId]);
    } catch (Throwable $e) {
        error_log('Failed to log activity: ' . $e->getMessage());
    }
}

/** Format a DateTime-ish value for display. */
function formatDate(string $date): string
{
    return date('M j, Y', strtotime($date));
}

function formatDateTime(string $datetime): string
{
    return date('M j, Y g:i A', strtotime($datetime));
}


/* =====================================================================
   Password policy
   ===================================================================== */

/** Human-readable rules, shared by the server check and the JS meter. */
function passwordPolicyRules(): array
{
    return [
        'length' => 'At least 8 characters',
        'lower'  => 'A lowercase letter (a-z)',
        'upper'  => 'An uppercase letter (A-Z)',
        'digit'  => 'A number (0-9)',
        'symbol' => 'A symbol (e.g. ! @ # $ % ^ & * _ ~)',
    ];
}

/**
 * Check a password against the policy. Returns a list of problems; an empty
 * list means the password is acceptable.
 *
 * Example of an accepted password:  Abcd~!@#$%^&*()_123456789
 */
function passwordPolicyErrors(string $password): array
{
    $errors = [];
    if (mb_strlen($password) < 8) {
        $errors[] = 'at least 8 characters';
    }
    // bcrypt silently ignores everything after 72 bytes, so refuse longer input
    // rather than store a password that is weaker than the user thinks.
    if (strlen($password) > 72) {
        $errors[] = 'no more than 72 characters';
    }
    if (!preg_match('/\p{Ll}/u', $password)) {
        $errors[] = 'a lowercase letter';
    }
    if (!preg_match('/\p{Lu}/u', $password)) {
        $errors[] = 'an uppercase letter';
    }
    if (!preg_match('/\p{N}/u', $password)) {
        $errors[] = 'a number';
    }
    if (!preg_match('/[^\p{L}\p{N}\s]/u', $password)) {
        $errors[] = 'a symbol (such as ! @ # $ % ^ & * _ ~)';
    }
    return $errors;
}

/** Turn the error list into one sentence, or '' when the password is fine. */
function passwordPolicyMessage(string $password): string
{
    $errors = passwordPolicyErrors($password);
    if (!$errors) {
        return '';
    }
    if (strlen($password) > 72) {
        return 'Password is too long. Please use 72 characters or fewer.';
    }
    return 'Password is too weak. It needs ' . implode(', ', $errors) . '.';
}

/* =====================================================================
   Items: image URLs and deletion
   ===================================================================== */

/**
 * Public URL for an item's image. Built from the stored file name each time
 * so images keep working if the site moves to another folder, port or domain
 * (older rows saved a full URL that then went stale).
 */
function itemImageUrl(array $item): ?string
{
    if (!empty($item['image_path'])) {
        return UPLOAD_URL . basename($item['image_path']);
    }
    return !empty($item['image_url']) ? $item['image_url'] : null;
}

/** Delete a file from /uploads. Ignores cloud URLs and anything outside it. */
function removeLocalUpload(?string $path): void
{
    if (!$path || preg_match('#^https?://#i', $path)) {
        return;
    }
    $file = UPLOAD_DIR . basename($path);
    if (is_file($file)) {
        @unlink($file);
    }
}

/**
 * Delete reports and everything hanging off them (claims and chats go via
 * ON DELETE CASCADE) plus their image / proof files on disk.
 * Returns how many items were actually deleted. The caller is responsible
 * for checking that the person is allowed to delete these ids.
 */
function deleteItemsByIds(PDO $db, array $ids): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));

    $files = [];
    $stmt = $db->prepare("SELECT image_path FROM items WHERE id IN ($in)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $files[] = $row['image_path'];
    }
    $stmt = $db->prepare("SELECT proof_file FROM claims WHERE item_id IN ($in)");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $files[] = $row['proof_file'];
    }

    $del = $db->prepare("DELETE FROM items WHERE id IN ($in)");
    $del->execute($ids);
    $deleted = $del->rowCount();

    foreach ($files as $f) {
        removeLocalUpload($f);
    }
    return $deleted;
}

/* =====================================================================
   Support / contact
   ===================================================================== */

function contactCategories(): array
{
    return [
        'bug'     => 'Something is not working (bug)',
        'account' => 'My account / login / blocked account',
        'report'  => 'A lost or found report',
        'claim'   => 'A claim or chat',
        'other'   => 'Something else',
    ];
}

function supportStatusLabel(string $status): string
{
    return ['new' => 'New', 'in_progress' => 'In progress', 'resolved' => 'Resolved'][$status] ?? ucfirst($status);
}

/** What the admin panel calls a user's status. DB value 'suspended' = Blocked. */
function accountStatusLabel(string $status): string
{
    return $status === 'active' ? 'Active' : 'Blocked';
}

/** Best-effort client IP (used only for contact-form rate limiting). */
function clientIp(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}


/**
 * URL for a local CSS/JS file with its last-modified time appended
 * (css/style.css?v=1761700000). Browsers keep old-dated files for weeks without
 * re-checking, so after an update they kept running the OLD script against the
 * NEW page, and buttons silently did nothing. A changed file now gets a new URL.
 */
function asset(string $path): string
{
    $file = __DIR__ . '/../' . $path;
    return $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}
