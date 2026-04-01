/**
 * Lightweight toast notifications. No dependencies.
 * Usage: window.Toast && Toast.success('Copied to clipboard');
 */
(function () {
  'use strict';
  var CONTAINER_ID = 'toast-container';
  var DURATION = 3000;

  function ensureContainer() {
    var el = document.getElementById(CONTAINER_ID);
    if (!el) {
      el = document.createElement('div');
      el.id = CONTAINER_ID;
      el.className = 'toast-container';
      document.body.appendChild(el);
    }
    return el;
  }

  function show(type, message) {
    var container = ensureContainer();
    var item = document.createElement('div');
    item.className = 'toast-item toast-' + type;
    item.textContent = message;
    container.appendChild(item);
    setTimeout(function () {
      if (item.parentNode) {
        item.style.opacity = '0';
        item.style.transform = 'translateX(100%)';
        item.style.transition = 'opacity 0.2s ease, transform 0.2s ease';
        setTimeout(function () {
          if (item.parentNode) item.parentNode.removeChild(item);
        }, 200);
      }
    }, DURATION);
  }

  window.Toast = {
    success: function (msg) { show('success', msg); },
    error: function (msg) { show('error', msg); },
    info: function (msg) { show('info', msg); }
  };
})();
