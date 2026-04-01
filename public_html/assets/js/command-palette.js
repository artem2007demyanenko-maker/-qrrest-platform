/**
 * Lightweight command palette: Cmd+K / Ctrl+K. No dependencies.
 * Include on restaurant and project-admin pages. Data from data-command-links or default set.
 */
(function () {
  'use strict';
  var MODAL_ID = 'command-palette-modal';
  var INPUT_ID = 'command-palette-input';
  var LIST_ID = 'command-palette-list';

  function getLinks() {
    var script = document.getElementById('command-palette-data');
    if (script && script.type === 'application/json') {
      try {
        return JSON.parse(script.textContent || '[]');
      } catch (e) {}
    }
    return [];
  }

  function openModal() {
    var modal = document.getElementById(MODAL_ID);
    if (!modal) {
      var links = getLinks();
      if (links.length === 0) return;
      modal = document.createElement('div');
      modal.id = MODAL_ID;
      modal.className = 'fixed inset-0 z-[100] flex items-start justify-center pt-[15vh] px-4';
      modal.innerHTML =
        '<div class="fixed inset-0 bg-black/60 backdrop-blur-sm" data-cmd-close></div>' +
        '<div class="relative w-full max-w-md rounded-2xl border border-gray-700 bg-[#121826] shadow-2xl overflow-hidden">' +
        '<input id="' + INPUT_ID + '" type="text" placeholder="Search…" class="w-full px-4 py-3 bg-transparent border-b border-gray-800 text-[#F3F4F6] placeholder-gray-500 focus:outline-none focus:ring-0">' +
        '<ul id="' + LIST_ID + '" class="max-h-80 overflow-y-auto py-2"></ul>' +
        '</div>';
      modal.classList.add('hidden');
      document.body.appendChild(modal);
      modal.querySelector('[data-cmd-close]').addEventListener('click', closeModal);
      modal.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
      });
      var input = document.getElementById(INPUT_ID);
      var list = document.getElementById(LIST_ID);
      function render(q) {
        q = (q || '').toLowerCase().trim();
        var filtered = q
          ? links.filter(function (l) {
              return (l.label || '').toLowerCase().indexOf(q) >= 0 || (l.url || '').toLowerCase().indexOf(q) >= 0;
            })
          : links;
        list.innerHTML = filtered
          .slice(0, 12)
          .map(function (l) {
            return '<li><a href="' + (l.url || '#') + '" class="block px-4 py-2.5 text-sm text-gray-300 hover:bg-gray-800 hover:text-[#F3F4F6]">' + (l.label || l.url) + '</a></li>';
          })
          .join('');
      }
      input.addEventListener('input', function () {
        render(this.value);
      });
      input.addEventListener('keydown', function (e) {
        var first = list.querySelector('a');
        if (e.key === 'Enter' && first) {
          first.click();
          closeModal();
        }
      });
      render();
    }
    modal.classList.remove('hidden');
    var input = document.getElementById(INPUT_ID);
    if (input) {
      input.value = '';
      input.focus();
      document.getElementById(LIST_ID).innerHTML = '';
      var links = getLinks();
      document.getElementById(LIST_ID).innerHTML = links
        .slice(0, 12)
        .map(function (l) {
          return '<li><a href="' + (l.url || '#') + '" class="block px-4 py-2.5 text-sm text-gray-300 hover:bg-gray-800 hover:text-[#F3F4F6]">' + (l.label || l.url) + '</a></li>';
        })
        .join('');
    }
  }

  function closeModal() {
    var modal = document.getElementById(MODAL_ID);
    if (modal) modal.classList.add('hidden');
  }

  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
      e.preventDefault();
      openModal();
    }
  });
})();
