/**
 * GDPR consent banner — PUBLIC site (vanilla, no framework / no Drupal.behaviors
 * dependency, so the identical file drives BOTH the themed render path and the
 * raw-HTML page shell from PageSpikeController).
 *
 * Privacy by default: third-party assets are emitted in a GATED form by the
 * server — a stylesheet as <link ... media="not all" data-consent="fonts"> never
 * fetches until this script flips its media to "all". So nothing reaches Google
 * (or any third party) until the visitor opts in. The choice is stored in a
 * first-party cookie; the gate is applied purely client-side, so the server can
 * cache ONE HTML for every anonymous visitor regardless of consent.
 *
 * Config arrives as a JSON <script id="aincient-consent-config"> (works without
 * drupalSettings). Categories with `active:false` are shown disabled — we never
 * ask for consent we don't currently need.
 */
(function () {
  'use strict';

  var configEl = document.getElementById('aincient-consent-config');
  if (!configEl) {
    return;
  }

  var config;
  try {
    config = JSON.parse(configEl.textContent || '{}');
  } catch (e) {
    return;
  }

  var COOKIE = config.cookie || 'aincient_consent';
  var categories = config.categories || [];
  var nonRequired = categories.filter(function (c) { return !c.required; });
  var activeNonRequired = nonRequired.filter(function (c) { return c.active; });

  // ---- cookie helpers -------------------------------------------------------

  function readCookie() {
    var match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)'));
    if (!match) {
      return null;
    }
    try {
      return JSON.parse(decodeURIComponent(match[1]));
    } catch (e) {
      return null;
    }
  }

  function writeCookie(state) {
    var value = encodeURIComponent(JSON.stringify(state));
    var maxAge = 60 * 60 * 24 * 365; // one year
    var secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = COOKIE + '=' + value + '; Max-Age=' + maxAge + '; Path=/; SameSite=Lax' + secure;
  }

  // ---- apply the granted categories to the gated elements -------------------

  function apply(state) {
    categories.forEach(function (cat) {
      var granted = cat.required || !!state[cat.id];
      var nodes = document.querySelectorAll('[data-consent="' + cat.id + '"]');
      Array.prototype.forEach.call(nodes, function (node) {
        if (!granted) {
          return;
        }
        // Gated stylesheets ship WITHOUT an href (so the browser can't fetch
        // them — Blink downloads even media="not all" sheets at low priority,
        // which would still leak the IP), with the real URL parked in data-href.
        // Setting href now triggers the one and only request.
        if (node.tagName === 'LINK') {
          if (node.getAttribute('data-href') && !node.getAttribute('href')) {
            node.setAttribute('href', node.getAttribute('data-href'));
          } else {
            node.media = 'all';
          }
        }
        else if (node.tagName === 'STYLE') {
          node.media = 'all';
        }
        // Gated inert scripts (future analytics/embeds) carry type="text/plain".
        else if (node.tagName === 'SCRIPT' && node.type === 'text/plain') {
          var s = document.createElement('script');
          if (node.src) { s.src = node.src; } else { s.textContent = node.textContent; }
          node.parentNode.replaceChild(s, node);
        }
      });
    });
  }

  // ---- banner DOM -----------------------------------------------------------

  var root = null;
  var prefsEl = null;
  var reopenBtn = null;

  function grantAll() { commit(allState(true)); }
  function denyAll() { commit(allState(false)); }

  function allState(value) {
    var state = { v: 1 };
    nonRequired.forEach(function (c) { state[c.id] = c.active ? value : false; });
    return state;
  }

  function commit(state) {
    writeCookie(state);
    apply(state);
    hideBanner();
    showReopen();
  }

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (text != null) { node.textContent = text; }
    return node;
  }

  function buildBanner() {
    root = el('div', 'aincient-consent');
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-label', 'Privacy & cookie preferences');
    root.setAttribute('aria-live', 'polite');

    var panel = el('div', 'aincient-consent__panel');
    panel.appendChild(el('h2', 'aincient-consent__title', 'Your privacy'));
    panel.appendChild(el(
      'p',
      'aincient-consent__text',
      'This site can load resources from third parties (such as web fonts from Google), which shares your IP address with them. Choose what to allow — nothing non-essential loads until you do.'
    ));

    // Preferences (built once, toggled by Customize).
    prefsEl = el('div', 'aincient-consent__prefs');
    prefsEl.hidden = true;
    var stored = readCookie() || {};
    nonRequired.concat(categories.filter(function (c) { return c.required; }))
      .sort(function (a, b) { return categories.indexOf(a) - categories.indexOf(b); })
      .forEach(function (cat) {
        var row = el('div', 'aincient-consent__cat');
        var toggleWrap = el('div', 'aincient-consent__cat-toggle');
        var input = document.createElement('input');
        input.type = 'checkbox';
        input.id = 'aincient-consent-' + cat.id;
        input.value = cat.id;
        input.checked = cat.required || (cat.active && !!stored[cat.id]);
        input.disabled = cat.required || !cat.active;
        toggleWrap.appendChild(input);

        var body = document.createElement('div');
        var name = el('label', 'aincient-consent__cat-name', cat.label);
        name.setAttribute('for', input.id);
        if (cat.required) {
          name.appendChild(el('small', null, 'always on'));
        } else if (!cat.active) {
          name.appendChild(el('small', null, 'not in use yet'));
        }
        body.appendChild(name);
        body.appendChild(el('p', 'aincient-consent__cat-desc', cat.desc));

        row.appendChild(toggleWrap);
        row.appendChild(body);
        prefsEl.appendChild(row);
      });
    panel.appendChild(prefsEl);

    // Actions.
    var actions = el('div', 'aincient-consent__actions');
    var accept = el('button', 'aincient-consent__btn aincient-consent__btn--primary', 'Accept all');
    accept.type = 'button';
    accept.addEventListener('click', grantAll);

    var decline = el('button', 'aincient-consent__btn', 'Only necessary');
    decline.type = 'button';
    decline.addEventListener('click', denyAll);

    var customize = el('button', 'aincient-consent__btn aincient-consent__btn--ghost', 'Customize');
    customize.type = 'button';
    var save = el('button', 'aincient-consent__btn aincient-consent__btn--primary', 'Save choices');
    save.type = 'button';
    save.hidden = true;
    save.addEventListener('click', function () {
      var state = { v: 1 };
      nonRequired.forEach(function (c) {
        var input = document.getElementById('aincient-consent-' + c.id);
        state[c.id] = !!(input && input.checked && c.active);
      });
      commit(state);
    });
    customize.addEventListener('click', function () {
      var opening = prefsEl.hidden;
      prefsEl.hidden = !opening;
      save.hidden = !opening;
      customize.textContent = opening ? 'Hide options' : 'Customize';
    });

    actions.appendChild(accept);
    actions.appendChild(decline);
    actions.appendChild(customize);
    actions.appendChild(save);
    panel.appendChild(actions);

    root.appendChild(panel);
    document.body.appendChild(root);
  }

  function showBanner() {
    if (!root) { buildBanner(); }
    root.hidden = false;
    if (reopenBtn) { reopenBtn.hidden = true; }
  }

  function hideBanner() {
    if (root) { root.hidden = true; }
  }

  function showReopen() {
    if (!reopenBtn) {
      reopenBtn = el('button', 'aincient-consent-reopen', 'Privacy & cookies');
      reopenBtn.type = 'button';
      reopenBtn.addEventListener('click', showBanner);
      document.body.appendChild(reopenBtn);
    }
    reopenBtn.hidden = false;
  }

  // ---- boot -----------------------------------------------------------------

  function boot() {
    var stored = readCookie();
    if (stored) {
      apply(stored);
      // Already decided — offer a quiet way to revisit, but don't nag.
      if (activeNonRequired.length) {
        showReopen();
      }
      return;
    }
    // No decision yet: only interrupt the visitor if something actually needs it.
    if (activeNonRequired.length) {
      showBanner();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
