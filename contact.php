<?php
/**
 * contact.php
 * Lets anyone - logged in or not - send a message to the admin.
 * Guests are allowed on purpose: a blocked user can't log in, but still needs
 * a way to appeal. Logged-in users also see their earlier messages here,
 * together with any reply from the admin.
 */
require_once __DIR__ . '/includes/auth.php';

$user = currentUser();
$categories = contactCategories();
$errors = [];

// Values to (re)fill the form with
$old = [
    'name'     => $user['full_name'] ?? '',
    'email'    => $user['email'] ?? '',
    'category' => $_GET['category'] ?? 'other',
    'subject'  => '',
    'message'  => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid session token. Please refresh the page and try again.';
    } elseif (trim($_POST['website'] ?? '') !== '') {
        // Honeypot field: real people never see or fill it, bots do.
        // Pretend it worked so the bot gets no signal.
        $_SESSION['contact_sent'] = true;
        redirect('contact.php');
    } else {
        $old['name']     = $user ? $user['full_name'] : trim($_POST['name'] ?? '');
        $old['email']    = $user ? $user['email'] : trim($_POST['email'] ?? '');
        $old['category'] = $_POST['category'] ?? 'other';
        $old['subject']  = trim($_POST['subject'] ?? '');
        $old['message']  = trim($_POST['message'] ?? '');

        if (!isset($categories[$old['category']])) {
            $old['category'] = 'other';
        }
        if ($old['name'] === '' || mb_strlen($old['name']) > 150) {
            $errors[] = 'Please enter your name (150 characters at most).';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 150) {
            $errors[] = 'Please enter a valid email address so the admin can reach you.';
        }
        if ($old['subject'] === '' || mb_strlen($old['subject']) > 200) {
            $errors[] = 'Please add a short subject (200 characters at most).';
        }
        if (mb_strlen($old['message']) < 10) {
            $errors[] = 'Please describe the problem in at least 10 characters.';
        } elseif (mb_strlen($old['message']) > 3000) {
            $errors[] = 'Your message is too long (3000 characters at most).';
        }

        // Simple flood control: 5 messages per hour. Logged-in users are counted
        // by account, so people sharing one network (campus, office) don't
        // lock each other out; only anonymous visitors are counted by address.
        if (!$errors) {
            $db = getDB();
            if ($user) {
                $limit = $db->prepare('SELECT COUNT(*) FROM contact_messages WHERE created_at > (NOW() - INTERVAL 1 HOUR) AND user_id = ?');
                $limit->execute([$user['id']]);
            } else {
                $limit = $db->prepare('SELECT COUNT(*) FROM contact_messages WHERE created_at > (NOW() - INTERVAL 1 HOUR) AND user_id IS NULL AND ip_address = ?');
                $limit->execute([clientIp()]);
            }
            if ((int) $limit->fetchColumn() >= 5) {
                $errors[] = 'You have sent several messages recently. Please wait a while before sending another.';
            }
        }

        if (!$errors) {
            $db->prepare(
                'INSERT INTO contact_messages (user_id, name, email, category, subject, message, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $user['id'] ?? null, $old['name'], $old['email'], $old['category'],
                $old['subject'], $old['message'], clientIp(),
            ]);
            logActivity($user['id'] ?? null, 'Sent a support message: ' . mb_substr($old['subject'], 0, 80), 'contact', (int) $db->lastInsertId());
            $_SESSION['contact_sent'] = true;
            redirect('contact.php'); // post/redirect/get: a refresh won't resend the message
        }
    }
}

$sent = !empty($_SESSION['contact_sent']);
unset($_SESSION['contact_sent']);

// The logged-in user's earlier messages
$myMessages = [];
if ($user) {
    $stmt = getDB()->prepare('SELECT * FROM contact_messages WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([$user['id']]);
    $myMessages = $stmt->fetchAll();
}

$pageTitle = 'Contact Admin';
include __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <div class="contact-layout">
    <div class="card">
      <h2>💬 Contact the Admin</h2>
      <p class="text-muted">Ran into a problem, think a report is wrong, or got blocked by mistake? Tell us what happened and the admin will look into it.</p>

      <?php if ($sent): ?>
        <div class="alert alert-success">Thanks — your message has been sent to the admin.
          <?= $user ? 'You can follow its status and any reply below.' : 'They will contact you at the email address you gave.' ?></div>
      <?php endif; ?>
      <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endforeach; ?>

      <form method="POST" action="contact.php" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <div class="hp-field" aria-hidden="true">
          <label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="required" for="c_name">Your name</label>
            <input type="text" id="c_name" name="name" required maxlength="150" value="<?= h($old['name']) ?>" <?= $user ? 'readonly' : '' ?>>
          </div>
          <div class="form-group">
            <label class="required" for="c_email">Your email</label>
            <input type="email" id="c_email" name="email" required maxlength="150" value="<?= h($old['email']) ?>" <?= $user ? 'readonly' : '' ?>>
            <?php if (!$user): ?><div class="form-hint">Use the email of your account so the admin can find it.</div><?php endif; ?>
          </div>
        </div>

        <div class="form-group">
          <label class="required" for="c_category">What is this about?</label>
          <select id="c_category" name="category" required>
            <?php foreach ($categories as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $old['category'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="required" for="c_subject">Subject</label>
          <input type="text" id="c_subject" name="subject" required maxlength="200" value="<?= h($old['subject']) ?>" placeholder="e.g. I can't upload a photo to my report">
        </div>

        <div class="form-group">
          <label class="required" for="c_message">Message</label>
          <textarea id="c_message" name="message" required minlength="10" maxlength="3000" rows="6"
                    placeholder="What were you trying to do, and what happened instead?"><?= h($old['message']) ?></textarea>
          <div class="form-hint"><span id="msgCount">0</span> / 3000</div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Send message</button>
      </form>
    </div>

    <?php if ($user): ?>
    <div class="card" id="my-messages">
      <h3>Your messages</h3>
      <?php if (!$myMessages): ?>
        <p class="text-muted">You haven't contacted the admin yet.</p>
      <?php endif; ?>
      <?php foreach ($myMessages as $m): ?>
        <div class="support-thread">
          <div class="flex-between">
            <strong><?= h($m['subject']) ?></strong>
            <span class="badge badge-support-<?= h($m['status']) ?>"><?= h(supportStatusLabel($m['status'])) ?></span>
          </div>
          <div class="text-muted" style="font-size:.82rem;"><?= h($categories[$m['category']] ?? 'Other') ?> · <?= formatDateTime($m['created_at']) ?></div>
          <p class="support-body"><?= nl2br(h($m['message'])) ?></p>
          <?php if (!empty($m['admin_reply'])): ?>
            <div class="support-reply">
              <strong>Admin reply</strong> <span class="text-muted">· <?= formatDateTime($m['replied_at'] ?? $m['updated_at']) ?></span>
              <p><?= nl2br(h($m['admin_reply'])) ?></p>
            </div>
          <?php else: ?>
            <div class="text-muted" style="font-size:.85rem;">No reply yet — the admin has been notified.</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="card">
      <h3>Have an account?</h3>
      <p class="text-muted"><a href="login.php">Log in</a> before sending your message and you'll be able to see the admin's reply right here on this page.</p>
      <p class="text-muted">Blocked from your account? Send your message from this form using the email you registered with.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var t = document.getElementById('c_message'), n = document.getElementById('msgCount');
  if (t && n) { var u = function () { n.textContent = t.value.length; }; t.addEventListener('input', u); u(); }
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
