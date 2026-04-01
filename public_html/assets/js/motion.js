/**
 * Lightweight motion helpers: scroll reveal (IntersectionObserver), animated counters, optional ripple.
 * No dependencies. Include after DOM ready or at end of body.
 */
(function () {
  'use strict';

  /* ---- Scroll reveal: add .visible when element enters viewport ---- */
  var revealSelectors = '.hero, .feature-card, .dashboard-card, .section, .reveal, .fade-in';
  function initReveal() {
    var els = document.querySelectorAll(revealSelectors);
    if (!els.length) return;
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
          }
        });
      },
      { rootMargin: '0px 0px -40px 0px', threshold: 0.05 }
    );
    els.forEach(function (el) {
      if (!el.classList.contains('visible')) observer.observe(el);
    });
  }

  /* ---- Animate number from 0 to value (900ms) ---- */
  function animateValue(el, end, duration, suffix, prefix) {
    if (!el || typeof end !== 'number') return;
    suffix = suffix || '';
    prefix = prefix || '';
    var start = 0;
    var startTime = null;
    var isDecimal = end % 1 !== 0;
    function step(timestamp) {
      if (!startTime) startTime = timestamp;
      var progress = Math.min((timestamp - startTime) / duration, 1);
      var easeOut = 1 - Math.pow(1 - progress, 2);
      var current = start + (end - start) * easeOut;
      el.textContent = prefix + (isDecimal ? current.toFixed(1) : Math.round(current)) + suffix;
      if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  function initCounters() {
    document.querySelectorAll('[data-count-to]').forEach(function (el) {
      var value = parseFloat(el.getAttribute('data-count-to'), 10);
      if (isNaN(value)) return;
      var duration = parseInt(el.getAttribute('data-count-duration'), 10) || 900;
      var suffix = el.getAttribute('data-count-suffix') || '';
      var prefix = el.getAttribute('data-count-prefix') || '';
      var observer = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              animateValue(el, value, duration, suffix, prefix);
              observer.unobserve(entry.target);
            }
          });
        },
        { threshold: 0.1 }
      );
      observer.observe(el);
    });
  }

  /* ---- Optional: ripple position for .btn-ripple (set --x, --y on click) ---- */
  function initRipple() {
    document.querySelectorAll('.btn-ripple').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        var rect = btn.getBoundingClientRect();
        var x = ((e.clientX - rect.left) / rect.width) * 100;
        var y = ((e.clientY - rect.top) / rect.height) * 100;
        btn.style.setProperty('--x', x + '%');
        btn.style.setProperty('--y', y + '%');
      });
    });
  }

  function init() {
    initReveal();
    initCounters();
    initRipple();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
