/* WP Analytics DataLayer — Admin JS */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {

    // File import input — enable button + show filename
    var fileInputs = document.querySelectorAll('.wadl-file-input');
    fileInputs.forEach(function (input) {
      input.addEventListener('change', function () {
        var label   = input.closest('.wadl-file-label');
        var btn     = document.getElementById('wadl-import-btn');
        var textEl  = label ? label.querySelector('.wadl-file-label__text') : null;
        if ( input.files && input.files.length > 0 ) {
          if ( textEl ) textEl.textContent = input.files[0].name;
          if ( label )  label.classList.add('wadl-file-label--selected');
          if ( btn )    btn.removeAttribute('disabled');
        } else {
          if ( textEl ) textEl.textContent = 'Choisir un fichier JSON\u2026';
          if ( label )  label.classList.remove('wadl-file-label--selected');
          if ( btn )    btn.setAttribute('disabled', 'disabled');
        }
      });
    });

    // Copy JSON button
    var copyBtns = document.querySelectorAll('.wadl-copy-btn');
    copyBtns.forEach(function (btn) {
      // Store original children as cloned nodes before any mutation
      var originalNodes = Array.from(btn.childNodes).map(function (n) { return n.cloneNode(true); });

      btn.addEventListener('click', function () {
        var targetId = btn.getAttribute('data-target');
        var el = document.getElementById(targetId);
        if (!el) return;

        navigator.clipboard.writeText(el.textContent).then(function () {
          btn.classList.add('wadl-copy-btn--copied');

          // Build "Copié !" state with DOM nodes only
          btn.textContent = '';
          var icon = document.createElement('span');
          icon.className = 'dashicons dashicons-yes';
          var text = document.createTextNode(' Copié !');
          btn.appendChild(icon);
          btn.appendChild(text);

          setTimeout(function () {
            btn.classList.remove('wadl-copy-btn--copied');
            btn.textContent = '';
            originalNodes.forEach(function (n) { btn.appendChild(n.cloneNode(true)); });
          }, 2000);
        });
      });
    });
  });
})();
