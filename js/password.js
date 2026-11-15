/* =====================================================================
   Lost & Found — password.js
   1. Live strength meter + rules checklist for inputs marked
      [data-password-policy]. The same rules are enforced again on the
      server (passwordPolicyErrors() in includes/functions.php), so this is
      feedback for the user, not the security boundary.
   2. "Confirm password" inputs marked [data-confirm-for="<id>"] show a live
      match message and refuse copy / cut / paste / drag-and-drop, so the
      password has to be typed a second time.
   ===================================================================== */

(function () {
  'use strict';

  // Keep in step with passwordPolicyRules() in includes/functions.php
  var RULES = [
    { id: 'length', label: 'At least 8 characters',                test: function (p) { return p.length >= 8; } },
    { id: 'lower',  label: 'A lowercase letter (a-z)',             test: function (p) { return /\p{Ll}/u.test(p); } },
    { id: 'upper',  label: 'An uppercase letter (A-Z)',            test: function (p) { return /\p{Lu}/u.test(p); } },
    { id: 'digit',  label: 'A number (0-9)',                       test: function (p) { return /\p{N}/u.test(p); } },
    { id: 'symbol', label: 'A symbol (e.g. ! @ # $ % ^ & * _ ~)',  test: function (p) { return /[^\p{L}\p{N}\s]/u.test(p); } }
  ];
  var MAX_BYTES = 72; // bcrypt limit; longer passwords are rejected server-side

  var LEVELS = ['Too weak', 'Weak', 'Fair', 'Good', 'Strong', 'Very strong'];

  function byteLength(str) {
    return new TextEncoder().encode(str).length;
  }

  function evaluate(password) {
    var results = RULES.map(function (r) { return { rule: r, ok: r.test(password) }; });
    var passed = results.filter(function (r) { return r.ok; }).length;
    var tooLong = byteLength(password) > MAX_BYTES;
    return { results: results, passed: passed, valid: passed === RULES.length && !tooLong, tooLong: tooLong };
  }

  /* ----------------------------- Strength meter ----------------------------- */

  function setupPolicy(input) {
    var wrap = document.createElement('div');
    wrap.className = 'pw-meter';
    wrap.innerHTML =
      '<div class="pw-bar" aria-hidden="true"><span></span></div>' +
      '<div class="pw-label" aria-live="polite"></div>' +
      '<ul class="pw-rules"></ul>';
    input.insertAdjacentElement('afterend', wrap);

    var bar = wrap.querySelector('.pw-bar span');
    var label = wrap.querySelector('.pw-label');
    var list = wrap.querySelector('.pw-rules');
    list.innerHTML = RULES.map(function (r) {
      return '<li data-rule="' + r.id + '"><span class="pw-mark" aria-hidden="true"></span>' + r.label + '</li>';
    }).join('');

    function update() {
      var pw = input.value;
      var ev = evaluate(pw);
      ev.results.forEach(function (r) {
        var li = list.querySelector('[data-rule="' + r.rule.id + '"]');
        li.classList.toggle('ok', r.ok);
      });

      // Bonus level for long passwords that satisfy every rule.
      var level = pw === '' ? 0 : ev.passed;
      if (ev.valid && pw.length >= 14) level = 5;
      else if (ev.valid) level = Math.min(level, 4);
      else level = Math.min(level, 3);

      wrap.dataset.level = String(level);
      bar.style.width = (pw === '' ? 0 : Math.max(8, (level / 5) * 100)) + '%';
      label.textContent = pw === '' ? '' : (ev.tooLong ? 'Too long (72 characters at most)' : LEVELS[level]);

      input.setCustomValidity(ev.valid || pw === '' ? '' : 'Please choose a stronger password.');
      // Keep the confirm field's match message fresh when this one changes.
      var confirm = document.querySelector('[data-confirm-for="' + input.id + '"]');
      if (confirm) confirm.dispatchEvent(new Event('input'));
    }

    input.addEventListener('input', update);
    update();
  }

  /* ------------------------- Confirm field behaviour ------------------------ */

  function setupConfirm(input) {
    var source = document.getElementById(input.getAttribute('data-confirm-for'));
    if (!source) return;

    var msg = document.createElement('div');
    msg.className = 'form-hint pw-match';
    msg.setAttribute('aria-live', 'polite');
    input.insertAdjacentElement('afterend', msg);

    function update() {
      var a = source.value, b = input.value;
      msg.classList.remove('ok', 'bad');
      if (b === '') {
        msg.textContent = 'Type your password again to confirm it (copy and paste are turned off here).';
        input.setCustomValidity(a !== '' ? 'Please confirm your password.' : '');
        return;
      }
      if (a === b) {
        msg.textContent = '✓ Passwords match';
        msg.classList.add('ok');
        input.setCustomValidity('');
      } else {
        msg.textContent = 'Passwords do not match yet';
        msg.classList.add('bad');
        input.setCustomValidity('Passwords do not match.');
      }
    }
    input.addEventListener('input', update);
    update();

    var notice;
    function warn() {
      msg.classList.remove('ok');
      msg.classList.add('bad');
      msg.textContent = 'Copy and paste are disabled here — please type the password again.';
      clearTimeout(notice);
      notice = setTimeout(update, 2500);
    }

    function block(e) { e.preventDefault(); warn(); }

    ['copy', 'cut', 'paste', 'drop', 'dragstart', 'contextmenu'].forEach(function (evt) {
      input.addEventListener(evt, block);
    });
    // Catches paste/drop from the browser's own menus and mobile keyboards too.
    input.addEventListener('beforeinput', function (e) {
      if (/^insertFrom(Paste|Drop|Yank)/.test(e.inputType || '')) block(e);
    });
    input.addEventListener('keydown', function (e) {
      var k = (e.key || '').toLowerCase();
      var mod = e.ctrlKey || e.metaKey;
      if ((mod && (k === 'v' || k === 'c' || k === 'x' || k === 'insert')) || (e.shiftKey && k === 'insert')) {
        block(e);
      }
    });
    input.setAttribute('autocomplete', 'off');
  }

  /* -------------------------------- Wire up -------------------------------- */

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-password-policy]').forEach(setupPolicy);
    document.querySelectorAll('[data-confirm-for]').forEach(setupConfirm);

    // Don't let the form leave with a weak / mismatched password.
    document.querySelectorAll('form').forEach(function (form) {
      var policyInput = form.querySelector('[data-password-policy]');
      if (!policyInput) return;
      form.addEventListener('submit', function (e) {
        if (policyInput.value === '' && !policyInput.required) return; // optional field left blank
        if (!form.checkValidity()) {
          e.preventDefault();
          form.reportValidity();
        }
      });
    });
  });
})();
