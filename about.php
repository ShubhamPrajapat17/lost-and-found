<?php
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'About & FAQ';
include __DIR__ . '/includes/header.php';
?>
<div class="container section">
  <div class="card mb-1">
    <h2>About Lost & Found</h2>
    <p>Lost & Found is a community platform that helps people report, search for, and reclaim lost belongings. Whether you've misplaced your wallet on the subway or found a set of keys on a park bench, our platform makes it simple to connect finders with owners — safely and privately.</p>
    <p>Every report submitted goes through an admin review before it's published, and personal contact information stays hidden until a claim has been verified. This keeps the community trustworthy and protects everyone's privacy.</p>
  </div>

  <div class="card" id="faq">
    <h2>Frequently Asked Questions</h2>

    <div class="faq-item">
      <h3>Something isn't working, or I was blocked by mistake. What do I do?</h3>
      <p class="text-muted">Use the <a href="contact.php">Contact page</a> to message the admin. You can do this even if you are logged out or blocked. Describe what happened and use the email you registered with. Logged-in users can see the admin's reply on that same page.</p>
    </div>

    <div class="faq-item">
      <h3>I made a mistake in my report. Can I remove it?</h3>
      <p class="text-muted">Yes. Open <em>My Dashboard &rarr; My Reports</em> (or the <em>My List View</em> on the Lost / Found Items pages) and click <strong>Delete</strong> next to the report.</p>
    </div>

    <div class="faq-item">
      <h3>How do I report a lost or found item?</h3>
      <p class="text-muted">Log in or create an account, then click "Report Lost Item" or "Report Found Item" from the navigation bar. Fill in the date, time, specific location, category, description, and your contact details.</p>
    </div>

    <div class="faq-item">
      <h3>Why isn't my report showing up immediately?</h3>
      <p class="text-muted">Every submission is reviewed by an admin before going public. This usually takes a short time and helps prevent spam or false reports.</p>
    </div>

    <div class="faq-item">
      <h3>How does claiming a found item work?</h3>
      <p class="text-muted">Open the found item's listing and click "Claim This Item." You'll need to provide your name, phone, email, and either a secret identifying detail or a proof document. The finder or an admin reviews your claim before approving it.</p>
    </div>

    <div class="faq-item">
      <h3>Will my phone number and email be public?</h3>
      <p class="text-muted">No. Your contact details are masked on public listings and are only revealed once a claim has been verified and approved.</p>
    </div>

    <div class="faq-item">
      <h3>How do I communicate with the other party?</h3>
      <p class="text-muted">Once a claim is approved, an in-app chat unlocks between the finder and claimant so you can arrange a handover without sharing your phone number upfront.</p>
    </div>

    <div class="faq-item">
      <h3>What happens after an item is returned?</h3>
      <p class="text-muted">Mark the report as "Resolved" or "Handed Over" from your dashboard so others know the item is no longer available.</p>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
