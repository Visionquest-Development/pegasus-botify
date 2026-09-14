/**
 * Pegasus Botify — collapsible sections on the CMB2 options page.
 *
 * CMB2 `title` fields are just headings, not accordions. This turns each section title into a
 * clickable toggle that shows/hides the fields between it and the next title. State persists
 * per section in localStorage. UI-only — it never touches the stored data.
 */
(function () {
  'use strict';

  // Show a live "N px" readout next to each range slider.
  function initRangeReadouts(wrap) {
    wrap.querySelectorAll('input.pegasus-botify-range').forEach(function (input) {
      var out = document.createElement('span');
      out.className = 'pegasus-range-value';
      var update = function () {
        out.textContent = ' ' + input.value + ' px';
      };
      update();
      input.insertAdjacentElement('afterend', out);
      input.addEventListener('input', update);
    });
  }

  function init() {
    var wrap = document.querySelector('.cmb2-options-page') || document;
    initRangeReadouts(wrap);
    var titles = wrap.querySelectorAll('.cmb-row.cmb-type-title');
    if (!titles.length) {
      return;
    }

    titles.forEach(function (title, idx) {
      // Collect the field rows that belong to this section (until the next title).
      var rows = [];
      var el = title.nextElementSibling;
      while (el && !el.classList.contains('cmb-type-title')) {
        rows.push(el);
        el = el.nextElementSibling;
      }

      title.classList.add('pegasus-section-header');
      title.setAttribute('role', 'button');
      title.setAttribute('tabindex', '0');

      var key = 'pegasus_botify_section_' + idx;
      var collapsed = localStorage.getItem(key) === '1';

      function apply() {
        rows.forEach(function (row) {
          row.style.display = collapsed ? 'none' : '';
        });
        title.classList.toggle('pegasus-collapsed', collapsed);
      }

      function toggle() {
        collapsed = !collapsed;
        try {
          localStorage.setItem(key, collapsed ? '1' : '0');
        } catch (e) {
          /* private mode / storage disabled — ignore */
        }
        apply();
      }

      apply();
      title.addEventListener('click', toggle);
      title.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          toggle();
        }
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
