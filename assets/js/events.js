/*
 * Sepo Events — event pages: dark mode, tabs, the RSVP form and cancelling.
 * No dependencies.
 */
(function () {
  'use strict';

  var cfg = window.SepoEventsConfig || {};
  var T = cfg.i18n || {};

  function qa(root, sel) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function fmt(s, v) { return String(s || '').replace(/%[ds]/, v); }

  // ------------------------------------------------------------ dark mode

  /** A declared theme marker wins; otherwise the page background decides. */
  function isDark() {
    var nodes = [document.documentElement, document.body];
    var attrs = ['data-theme', 'data-bs-theme', 'data-color-scheme', 'data-mode', 'data-color-mode'];
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (!n) { continue; }
      for (var j = 0; j < attrs.length; j++) {
        var v = (n.getAttribute(attrs[j]) || '').toLowerCase();
        if (v === 'dark') { return true; }
        if (v === 'light') { return false; }
      }
      if (/(^|\s)(dark|dark-mode|theme-dark|is-dark-theme|wp-dark-mode-active)(\s|$)/.test(n.className || '')) { return true; }
    }
    var bg = '';
    nodes.forEach(function (n) {
      if (!n) { return; }
      var c = getComputedStyle(n).backgroundColor;
      if (c && !/rgba\(\s*0,\s*0,\s*0,\s*0\s*\)|transparent/.test(c)) { bg = c; }
    });
    var m = bg.match(/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/);
    return !!m && (0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3]) / 255 < 0.4;
  }
  /** The colour actually painted behind an element: the nearest ancestor with a solid background. */
  function backdrop(el) {
    for (var n = el.parentElement; n; n = n.parentElement) {
      var c = getComputedStyle(n).backgroundColor;
      var m = c && c.match(/rgba?\(([^)]+)\)/);
      if (!m) { continue; }
      var parts = m[1].split(/[,\s/]+/).filter(Boolean);
      if (parts.length < 4 || parseFloat(parts[3]) >= 0.98) { return c; }
    }
    return '';
  }

  /** Height of anything the theme keeps fixed at the top (admin bar, sticky header), so sticky boxes clear it. */
  function topOffset() {
    var bottom = 0;
    qa(document, 'body *').forEach(function (n) {
      if (n.offsetParent === null && getComputedStyle(n).position !== 'fixed') { return; }
      var cs = getComputedStyle(n);
      if (cs.position !== 'fixed' && cs.position !== 'sticky') { return; }
      if (n.closest('.ev-scope, .eva-scope') || cs.visibility === 'hidden' || cs.display === 'none') { return; }
      var r = n.getBoundingClientRect();
      // Only bars pinned to the top that span most of the page width.
      if (r.top <= 1 && r.height > 0 && r.height < window.innerHeight / 3 && r.width > window.innerWidth * 0.6) { bottom = Math.max(bottom, r.bottom); }
    });
    return bottom;
  }

  function paintMode() {
    var dark = isDark();
    qa(document, '.ev-scope, .eva-scope').forEach(function (s) {
      s.classList.toggle(s.classList.contains('eva-scope') ? 'eva-dark' : 'ev-dark', dark);
      var bg = backdrop(s);
      if (bg) { s.style.setProperty('--ev-page-bg', bg); } else { s.style.removeProperty('--ev-page-bg'); }
    });
  }

  function placeSticky() {
    var top = Math.round(topOffset()) + 16;
    qa(document, '.ev-scope').forEach(function (s) { s.style.setProperty('--ev-sticky-top', top + 'px'); });
  }

  // ------------------------------------------------------------ requests

  function post(path, body) {
    var isForm = typeof FormData !== 'undefined' && body instanceof FormData;
    var headers = { 'Accept': 'application/json' };
    if (!isForm) { headers['Content-Type'] = 'application/json'; }
    if (cfg.nonce) { headers['X-WP-Nonce'] = cfg.nonce; }
    return fetch(String(cfg.api || '').replace(/\/$/, '') + path, {
      method: 'POST', credentials: 'same-origin', headers: headers, body: isForm ? body : JSON.stringify(body || {})
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
        if (!data) { throw new Error(T.network || 'Request failed.'); }
        if (!res.ok || data.status === 'error' || data.code) { throw new Error(data.message || 'Request failed.'); }
        return data.data || data;
      });
    }, function () { throw new Error(T.network || 'Request failed.'); });
  }

  // ------------------------------------------------------------ events list

  /**
   * Search, Upcoming / Past / All, category, price, grid or list, and pages
   * for the events list. Cards are already in the page; this shows the ones
   * that match, a page at a time. The layout choice is remembered per visitor.
   */
  function initList(root) {
    if (root.getAttribute('data-ev-ready') === '1') { return; }
    root.setAttribute('data-ev-ready', '1');
    var bar = root.querySelector('[data-ev-toolbar]');
    var grid = root.querySelector('[data-ev-cards]');
    var none = root.querySelector('[data-ev-none]');
    var pager = root.querySelector('[data-ev-pager]');
    if (!grid) { return; }
    var cards = qa(grid, '.ev-card');
    var perPage = parseInt(root.getAttribute('data-per-page'), 10) || 9;
    var hasSeg = !!root.querySelector('[data-ev-when]');
    var state = { when: hasSeg ? (root.getAttribute('data-default') || 'upcoming') : 'all', q: '', cat: '', price: '', page: 1 };
    var view = root.getAttribute('data-view') || 'grid';
    try { view = window.localStorage.getItem('sepoEventsView') || view; } catch (e) { /* storage unavailable */ }
    if (bar) { bar.hidden = false; }

    function fmtn(s, a, b, c) { return String(s || '').replace('%1$s', a).replace('%2$s', b).replace('%3$s', c).replace('%s', a); }

    function setView(v) {
      view = v === 'list' ? 'list' : 'grid';
      grid.classList.toggle('ev-grid--list', view === 'list');
      grid.classList.toggle('ev-grid--grid', view === 'grid');
      qa(root, '[data-ev-view]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-ev-view') === view ? 'true' : 'false'); });
    }

    function matches(card) {
      if (state.when !== 'all' && card.getAttribute('data-when') !== state.when) { return false; }
      if (state.price && card.getAttribute('data-price') !== state.price) { return false; }
      if (state.cat && (' ' + card.getAttribute('data-cats') + ' ').indexOf(' ' + state.cat + ' ') === -1) { return false; }
      if (state.q) {
        var text = card.getAttribute('data-text') || '';
        var words = state.q.split(/\s+/);
        for (var i = 0; i < words.length; i++) { if (text.indexOf(words[i]) === -1) { return false; } }
      }
      return true;
    }

    function pageButton(label, page, opts) {
      opts = opts || {};
      return '<button type="button" data-page="' + page + '"' + (opts.disabled ? ' disabled' : '') + (opts.current ? ' aria-current="page"' : '') +
        ' aria-label="' + esc(opts.aria || fmt(T.page, page)) + '">' + esc(label) + '</button>';
    }

    function apply(scroll) {
      var hits = cards.filter(matches);
      var pages = Math.max(1, Math.ceil(hits.length / perPage));
      state.page = Math.min(Math.max(1, state.page), pages);
      var from = (state.page - 1) * perPage;
      var shown = hits.slice(from, from + perPage);
      cards.forEach(function (c) { c.hidden = shown.indexOf(c) === -1; });
      none.hidden = hits.length !== 0;
      qa(root, '[data-ev-when]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-ev-when') === state.when ? 'true' : 'false'); });

      if (hits.length <= perPage) {
        pager.hidden = true;
      } else {
        var nums = [];
        for (var p = 1; p <= pages; p++) {
          if (p === 1 || p === pages || Math.abs(p - state.page) <= 1) { nums.push(p); }
          else if (nums[nums.length - 1] !== '…') { nums.push('…'); }
        }
        pager.innerHTML = '<span class="ev-pager-info">' + esc(fmtn(T.showing, from + 1, from + shown.length, hits.length)) + '</span>' +
          '<div class="ev-pager-buttons">' +
          pageButton('‹', state.page - 1, { disabled: state.page === 1, aria: T.prev }) +
          nums.map(function (n) { return n === '…' ? '<span class="ev-pager-gap">…</span>' : pageButton(String(n), n, { current: n === state.page }); }).join('') +
          pageButton('›', state.page + 1, { disabled: state.page === pages, aria: T.next }) +
          '</div>';
        pager.hidden = false;
      }
      if (scroll) {
        var top = root.getBoundingClientRect().top;
        if (top < 0) { window.scrollTo({ top: window.pageYOffset + top - 80, behavior: 'smooth' }); }
      }
    }

    qa(root, '[data-ev-when]').forEach(function (b) {
      b.addEventListener('click', function () { state.when = b.getAttribute('data-ev-when'); state.page = 1; apply(false); });
    });
    qa(root, '[data-ev-view]').forEach(function (b) {
      b.addEventListener('click', function () {
        setView(b.getAttribute('data-ev-view'));
        try { window.localStorage.setItem('sepoEventsView', view); } catch (e) { /* storage unavailable */ }
      });
    });
    var search = root.querySelector('[data-ev-search]');
    var timer = 0;
    if (search) {
      search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          state.q = search.value.trim().toLowerCase();
          state.page = 1;
          // Searching looks through every event, not only the selected tab.
          if (state.q && hasSeg) { state.when = 'all'; }
          apply(false);
        }, 120);
      });
    }
    var cat = root.querySelector('[data-ev-cat]');
    if (cat) { cat.addEventListener('change', function () { state.cat = cat.value; state.page = 1; apply(false); }); }
    var price = root.querySelector('[data-ev-price]');
    if (price) { price.addEventListener('change', function () { state.price = price.value; state.page = 1; apply(false); }); }
    pager.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('button[data-page]') : null;
      if (!b || b.disabled) { return; }
      state.page = parseInt(b.getAttribute('data-page'), 10) || 1;
      apply(true);
    });

    setView(view);
    apply(false);
  }

  // ------------------------------------------------------------ proof of payment

  /**
   * A file picker for proof of payment: choose or drop files, see them
   * listed, remove any, and have type, size and count checked before upload.
   * The chosen files live in a list here, so picking twice adds rather than
   * replaces.
   */
  function proofPicker(el, onError) {
    var input = el.querySelector('[data-ev-proof-input]');
    var drop = el.querySelector('[data-ev-drop]');
    var list = el.querySelector('[data-ev-proof-list]');
    var maxMb = parseInt(el.getAttribute('data-max-mb'), 10) || 5;
    var maxFiles = parseInt(el.getAttribute('data-max-files'), 10) || 5;
    var exts = (el.getAttribute('data-exts') || '').split(',').filter(Boolean);
    var files = [];

    function size(n) {
      return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
    }
    function render() {
      list.innerHTML = files.map(function (f, i) {
        var img = /^image\//.test(f.type);
        return '<li>' + (img ? ICON.image : ICON.file) + '<span>' + esc(f.name) + '</span><small>' + esc(size(f.size)) + '</small>' +
          '<button type="button" class="ev-proof-remove" data-i="' + i + '" aria-label="' + esc(fmt(T.removeFile, f.name)) + '">' + ICON.x + '</button></li>';
      }).join('');
      el.classList.remove('has-error');
    }
    function add(picked) {
      Array.prototype.forEach.call(picked || [], function (f) {
        var ext = (f.name.split('.').pop() || '').toLowerCase();
        if (exts.length && exts.indexOf(ext) === -1) { onError(fmt(T.proofType, f.name)); return; }
        if (f.size > maxMb * 1048576) { onError(fmt(fmt(T.proofSize, f.name), maxMb)); return; }
        if (files.length >= maxFiles) { onError(fmt(T.proofCount, maxFiles)); return; }
        files.push(f);
      });
      render();
    }
    input.addEventListener('change', function () { add(input.files); input.value = ''; });
    ['dragenter', 'dragover'].forEach(function (t) {
      drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
      drop.addEventListener(t, function () { drop.classList.remove('is-over'); });
    });
    drop.addEventListener('drop', function (e) {
      e.preventDefault();
      if (e.dataTransfer) { add(e.dataTransfer.files); }
    });
    list.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('.ev-proof-remove') : null;
      if (!b) { return; }
      files.splice(parseInt(b.getAttribute('data-i'), 10), 1);
      render();
    });
    return {
      files: function () { return files.slice(); },
      required: el.getAttribute('data-required') === '1',
      el: el
    };
  }

  var ICON = {
    file: '<svg class="ev-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
    image: '<svg class="ev-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
    x: '<svg class="ev-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
  };

  // ------------------------------------------------------------ one event

  function initEvent(root) {
    if (root.getAttribute('data-ev-ready') === '1') { return; }
    root.setAttribute('data-ev-ready', '1');

    var eventId = parseInt(root.getAttribute('data-ev-id'), 10) || 0;
    var isPaid = root.getAttribute('data-ev-paid') === '1';
    var unitPrice = parseFloat(root.getAttribute('data-ev-price')) || 0;
    var currency = root.getAttribute('data-ev-currency') || '';
    var collectGuests = root.getAttribute('data-ev-allow-guests') === '1';
    var wantsName = root.getAttribute('data-ev-wants-name') === '1';
    var wantsPhone = root.getAttribute('data-ev-wants-phone') === '1';
    var msg = root.querySelector('[data-ev-msg]');

    function show(text, tone) {
      if (!msg) { return; }
      msg.hidden = false;
      msg.className = 'ev-msg ev-msg--' + (tone || 'info');
      msg.textContent = text;
    }

    // Tabs
    var tabs = qa(root, '[data-ev-tab]');
    var panels = qa(root, '[data-ev-panel]');
    function activate(name) {
      tabs.forEach(function (t) {
        var on = t.getAttribute('data-ev-tab') === name;
        t.classList.toggle('active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      panels.forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-ev-panel') === name); });
    }
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () { activate(tab.getAttribute('data-ev-tab')); });
      tab.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
        var i = tabs.indexOf(tab) + (e.key === 'ArrowRight' ? 1 : -1);
        var next = tabs[(i + tabs.length) % tabs.length];
        next.focus();
        activate(next.getAttribute('data-ev-tab'));
      });
    });
    if (window.location.hash === '#rsvp') { activate('rsvps'); }

    // Attendee rows and the expected total
    var form = root.querySelector('[data-ev-form]');
    var guestsSel = form ? form.querySelector('select[name="guests"]') : null;
    var attendeesEl = root.querySelector('[data-ev-attendees]');
    var totalLabel = root.querySelector('[data-ev-total-label]');
    var totalExp = root.querySelector('[data-ev-total-expected]');

    function money(n) { return (currency ? currency + ' ' : '') + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

    function attendeeRow(i) {
      var h = '<div class="ev-attendee" data-attendee="' + i + '"><p class="ev-attendee-head">' + esc(fmt(T.attendee || 'Attendee %d', i + 2)) + '</p>';
      if (wantsName) {
        h += '<label class="ev-field"><span>' + esc(T.fullName || 'Full name') + '</span><input type="text" data-a="name" placeholder="' + esc(T.attendeeName || '') + '"></label>';
      }
      h += '<div class="ev-field-row"><label class="ev-field"><span>' + esc(T.email || 'Email') + '</span><input type="email" data-a="email" placeholder="attendee@example.com"></label>';
      if (wantsPhone) {
        h += '<label class="ev-field"><span>' + esc(T.phone || 'Phone') + '</span><input type="tel" data-a="phone"></label>';
      }
      h += '</div>';
      if (isPaid) {
        h += '<label class="ev-field"><span>' + esc(fmt(T.amountPaid || 'Amount paid (%s)', currency)) + '</span><input type="number" min="0" step="0.01" inputmode="decimal" data-a="amount" value="' + unitPrice.toFixed(2) + '"></label>';
      }
      return h + '</div>';
    }

    function renderAttendees() {
      if (!guestsSel) { return; }
      var seats = Math.max(1, parseInt(guestsSel.value, 10) || 1);
      if (attendeesEl && collectGuests) {
        var kept = qa(attendeesEl, '[data-attendee]').map(function (row) {
          var v = {};
          qa(row, '[data-a]').forEach(function (inp) { v[inp.getAttribute('data-a')] = inp.value; });
          return v;
        });
        var html = '';
        for (var i = 0; i < seats - 1; i++) { html += attendeeRow(i); }
        attendeesEl.innerHTML = html;
        qa(attendeesEl, '[data-attendee]').forEach(function (row, i) {
          if (!kept[i]) { return; }
          qa(row, '[data-a]').forEach(function (inp) {
            var k = inp.getAttribute('data-a');
            if (kept[i][k] != null) { inp.value = kept[i][k]; }
          });
        });
      }
      if (totalLabel) { totalLabel.textContent = fmt(seats === 1 ? T.totalOne : T.totalMany, seats); }
      if (totalExp) { totalExp.textContent = money(unitPrice * seats); }
    }
    if (guestsSel) {
      guestsSel.addEventListener('change', renderAttendees);
      renderAttendees();
    }

    var picker = null;
    var formProof = form ? form.querySelector('[data-ev-proof]') : null;
    if (formProof) { picker = proofPicker(formProof, function (m) { show(m, 'error'); }); }

    // Submit
    if (form) {
      form.addEventListener('input', function (e) {
        var f = e.target && e.target.closest ? e.target.closest('.ev-field.has-error') : null;
        if (f) { f.classList.remove('has-error'); }
        if (msg && !msg.hidden && !form.querySelector('.has-error') && /error/.test(msg.className)) { msg.hidden = true; }
      });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var submit = form.querySelector('[data-ev-submit]');
        var bad = [];
        qa(form, '.ev-field').forEach(function (f) { f.classList.remove('has-error'); });
        qa(form, 'input[required]').forEach(function (inp) {
          var v = inp.value.trim();
          if (v === '' || (inp.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v))) {
            bad.push(inp);
            var f = inp.closest('.ev-field');
            if (f) { f.classList.add('has-error'); }
          }
        });
        if (picker && picker.required && !picker.files().length) {
          picker.el.classList.add('has-error');
          if (!bad.length) {
            show(T.proofNeeded, 'error');
            picker.el.scrollIntoView({ block: 'center', behavior: 'smooth' });
            return;
          }
        }
        if (bad.length) {
          var onlyEmail = bad.length === 1 && bad[0].type === 'email' && bad[0].value.trim() !== '';
          show(onlyEmail ? T.badEmail : T.required, 'error');
          bad[0].focus();
          return;
        }

        var body = { event: eventId, guests: guestsSel ? Math.max(1, parseInt(guestsSel.value, 10) || 1) : 1 };
        ['name', 'email', 'phone', 'amount', 'sf_website'].forEach(function (k) {
          var el = form.querySelector('[name="' + k + '"]');
          if (el) { body[k] = k === 'amount' ? (parseFloat(el.value) || 0) : el.value.trim(); }
        });
        var rows = qa(form, '[data-attendee]');
        if (rows.length) {
          body.attendees = rows.map(function (row) {
            var a = {};
            qa(row, '[data-a]').forEach(function (inp) {
              var k = inp.getAttribute('data-a');
              a[k] = k === 'amount' ? (parseFloat(inp.value) || 0) : inp.value.trim();
            });
            return a;
          });
        }

        var payload = body;
        if (picker && picker.files().length) {
          payload = new FormData();
          Object.keys(body).forEach(function (k) {
            payload.append(k, k === 'attendees' ? JSON.stringify(body[k]) : body[k]);
          });
          picker.files().forEach(function (f) { payload.append('proof[]', f, f.name); });
        }
        var label = submit ? submit.textContent : '';
        if (submit) { submit.disabled = true; submit.textContent = (payload !== body ? T.uploading : T.reserving) || '…'; }
        post('/rsvp', payload)
          .then(function (data) {
            show(data.message || '', data.status === 'going' ? 'good' : 'info');
            window.location.hash = 'rsvp';
            window.location.reload();
          })
          .catch(function (err) {
            show(err.message, 'error');
            if (submit) { submit.disabled = false; submit.textContent = label; }
          });
      });
    }

    // Proof of payment sent after registering
    var proofForm = root.querySelector('[data-ev-proof-form]');
    if (proofForm) {
      var later = proofPicker(proofForm.querySelector('[data-ev-proof]'), function (m) { show(m, 'error'); });
      proofForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!later.files().length) {
          later.el.classList.add('has-error');
          show(T.proofChoose, 'error');
          return;
        }
        var fd = new FormData();
        fd.append('event', eventId);
        later.files().forEach(function (f) { fd.append('proof[]', f, f.name); });
        var btn = proofForm.querySelector('[data-ev-proof-send]');
        var label = btn.textContent;
        btn.disabled = true;
        btn.textContent = T.uploading || '…';
        post('/proof', fd)
          .then(function () { window.location.hash = 'rsvp'; window.location.reload(); })
          .catch(function (err) {
            show(err.message, 'error');
            btn.disabled = false;
            btn.textContent = label;
          });
      });
    }

    // Cancel, through the in-page dialog
    var cancel = root.querySelector('[data-ev-cancel]');
    if (cancel) {
      cancel.addEventListener('click', function () {
        var going = cancel.getAttribute('data-ev-cancel') !== 'waitlist';
        confirmDialog(root, {
          title: going ? T.cancelTitle : T.leaveTitle,
          body: going ? T.cancelBody : T.leaveBody,
          yes: going ? T.confirmCancel : T.confirmLeave,
          no: T.keep
        }).then(function (ok) {
          if (!ok) { return; }
          var label = cancel.textContent;
          cancel.disabled = true;
          cancel.textContent = T.cancelling || '…';
          post('/cancel', { event: eventId })
            .then(function () { window.location.hash = 'rsvp'; window.location.reload(); })
            .catch(function (err) {
              show(err.message, 'error');
              cancel.disabled = false;
              cancel.textContent = label;
            });
        });
      });
    }
  }

  /** The page's own confirm dialog: resolves true on the confirming button. */
  function confirmDialog(root, o) {
    var modal = root.querySelector('[data-ev-modal]');
    if (!modal) { return Promise.resolve(true); }
    var yes = modal.querySelector('[data-ev-modal-yes]');
    var no = modal.querySelector('[data-ev-modal-no]');
    modal.querySelector('[data-ev-modal-title]').textContent = o.title || '';
    modal.querySelector('[data-ev-modal-body]').textContent = o.body || '';
    yes.textContent = o.yes || 'OK';
    no.textContent = o.no || 'Cancel';
    var before = document.activeElement;
    modal.hidden = false;
    no.focus();
    return new Promise(function (resolve) {
      function done(v) {
        modal.hidden = true;
        document.removeEventListener('keydown', onKey, true);
        yes.removeEventListener('click', onYes);
        no.removeEventListener('click', onNo);
        modal.removeEventListener('click', onBack);
        if (before && before.focus) { before.focus(); }
        resolve(v);
      }
      function onYes() { done(true); }
      function onNo() { done(false); }
      function onBack(e) { if (e.target === modal) { done(false); } }
      function onKey(e) {
        if (e.key === 'Escape') { e.stopPropagation(); done(false); }
        if (e.key === 'Tab') {
          e.preventDefault();
          (document.activeElement === yes ? no : yes).focus();
        }
      }
      yes.addEventListener('click', onYes);
      no.addEventListener('click', onNo);
      modal.addEventListener('click', onBack);
      document.addEventListener('keydown', onKey, true);
    });
  }

  function start() {
    paintMode();
    placeSticky();
    var resizeTimer = 0;
    window.addEventListener('resize', function () { clearTimeout(resizeTimer); resizeTimer = setTimeout(placeSticky, 150); });
    qa(document, '[data-ev-root]').forEach(initEvent);
    qa(document, '[data-ev-list]').forEach(initList);
    var mo = new MutationObserver(paintMode);
    var opts = { attributes: true, attributeFilter: ['class', 'data-theme', 'data-bs-theme', 'data-color-scheme', 'data-mode', 'data-color-mode', 'style'] };
    mo.observe(document.documentElement, opts);
    if (document.body) { mo.observe(document.body, opts); }
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq.addEventListener) { mq.addEventListener('change', function () { setTimeout(paintMode, 50); }); }
    }
  }

  window.SepoEvents = { init: function (root) { paintMode(); qa(root || document, '[data-ev-root]').forEach(initEvent); qa(root || document, '[data-ev-list]').forEach(initList); }, paintMode: paintMode };

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
  // Elementor's editor renders widgets after load.
  if (window.jQuery) {
    window.jQuery(window).on('elementor/frontend/init', function () {
      if (window.elementorFrontend && window.elementorFrontend.hooks) {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/global', function ($el) { window.SepoEvents.init($el[0]); });
      }
    });
  }
})();
