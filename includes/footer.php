</main>
<footer class="site-footer">
  <div class="container footer-wrap">
    <p>&copy; <?= date('Y') ?> <?= h(APP_NAME) ?>. All rights reserved.</p>
    <div class="footer-links">
      <a href="about.php">About</a>
      <a href="about.php#faq">FAQ</a>
      <a href="contact.php">Contact</a>
      <a href="index.php">Home</a>
    </div>
  </div>
</footer>
<script src="<?= h(asset('js/export.js')) ?>"></script>
<script src="<?= h(asset('js/main.js')) ?>"></script>
<script src="<?= h(asset('js/password.js')) ?>"></script>
</body>
</html>
