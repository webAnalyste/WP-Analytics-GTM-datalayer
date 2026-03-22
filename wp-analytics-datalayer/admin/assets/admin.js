/* WP Analytics DataLayer — Admin JS */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
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
