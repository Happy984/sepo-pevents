/*
 * Sepo Events — the RSVP dashboard.
 *
 * Every address comes from the data-eva-* attributes the server writes, so
 * this file builds no URLs of its own beyond adding query strings.
 * The stats strip has one owner (loadStats); the RSVP table loads when its
 * tab is opened; destructive actions go through the page's own dialog.
 */
(function () {
  'use strict';

  var T = window.SepoEventsDashboardText || {};

  function fmt(s) {
    var args = Array.prototype.slice.call(arguments, 1);
    var i = 0;
    return String(s || '').replace(/%(\d)\$s|%s|%d/g, function (m, n) {
      var v = n ? args[parseInt(n, 10) - 1] : args[i++];
      return v == null ? '' : String(v);
    });
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function init(root) {
    if (!root || root.getAttribute('data-eva-ready') === '1') { return; }
    root.setAttribute('data-eva-ready', '1');

    var A = function (k) { return root.getAttribute('data-eva-' + k) || ''; };
    var api = A('api').replace(/\/+$/, '');
    var nonce = A('nonce');
    var $ = function (sel) { return root.querySelector(sel); };

    var msgEl = $('[data-eva-msg]');
    var tilesEl = $('[data-eva-tiles]');
    var eventsEl = $('[data-eva-events]');
    var rsvpsEl = $('[data-eva-rsvps]');
    var eventCount = $('[data-eva-event-count]');
    var rsvpCount = $('[data-eva-rsvp-count]');
    var filterEvent = $('[data-eva-filter-event]');
    var filterState = $('[data-eva-filter-status]');
    var searchEl = $('[data-eva-search]');
    var applyBtn = $('[data-eva-apply-filter]');
    var refreshBtn = $('[data-eva-refresh]');
    var purgeBtn = $('[data-eva-purge]');
    var scanLink = $('[data-eva-scan-link]');
    var modalEl = $('[data-eva-modal]');
    var detailModal = $('[data-eva-detail-modal]');
    var tabs = root.querySelectorAll('[data-eva-tab]');
    var panels = root.querySelectorAll('[data-eva-panel]');

    var rsvpsLoaded = false;
    var lastRows = [];
    var initialEvent = A('event') !== '0' ? A('event') : '';
    var msgTimer = 0;

    function show(text, tone) {
      if (!msgEl) { return; }
      msgEl.hidden = false;
      msgEl.setAttribute('data-tone', tone || '');
      msgEl.textContent = text;
      clearTimeout(msgTimer);
      msgTimer = setTimeout(function () { msgEl.hidden = true; }, 6000);
    }

    function qs(params) {
      var parts = [];
      Object.keys(params || {}).forEach(function (k) {
        if (params[k] !== '' && params[k] != null) { parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k])); }
      });
      return parts.length ? parts.join('&') : '';
    }
    function withQuery(url, params) {
      var q = qs(params);
      return q ? url + (url.indexOf('?') === -1 ? '?' : '&') + q : url;
    }

    function req(path, params, opts) {
      opts = opts || {};
      var headers = { 'Accept': 'application/json', 'X-WP-Nonce': nonce };
      if (opts.body) { headers['Content-Type'] = 'application/json'; }
      var url = withQuery(api + path, params);
      return fetch(url, { method: opts.method || 'GET', credentials: 'same-origin', headers: headers, body: opts.body ? JSON.stringify(opts.body) : undefined })
        .then(function (res) {
          return res.text().then(function (text) {
            var data = null;
            try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
            if (!data) { var pe = new Error('HTTP ' + res.status); pe.path = url; throw pe; }
            if (!res.ok || data.status === 'error' || data.code) { var ae = new Error(data.message || ('HTTP ' + res.status)); ae.path = url; throw ae; }
            return data.data || data;
          });
        });
    }

    function sectionError(el, label, err) {
      if (!el) { return; }
      el.innerHTML = '<div class="eva-error">' + esc(fmt(T.couldNotLoad, label, err && err.message ? err.message : '?')) +
        (err && err.path ? '<br><code>' + esc(err.path) + '</code>' : '') + '</div>';
    }

    function fmtTime(utc) {
      if (!utc) { return '—'; }
      try { return new Date(utc * 1000).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return String(utc); }
    }
    /** Date on one line, time beneath, for narrow table cells. */
    function fmtWhen(utc) {
      if (!utc) { return '—'; }
      var d = new Date(utc * 1000);
      try {
        return '<span class="eva-when">' + esc(d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })) + '</span>' +
          '<span class="eva-cell-sub">' + esc(d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })) + '</span>';
      } catch (e) { return esc(String(utc)); }
    }
    function fmtNum(n) {
      var v = Number(n);
      return isFinite(v) ? v.toLocaleString() : '0';
    }
    function fmtMoney(amount, cur) {
      var n = Number(amount);
      if (!isFinite(n) || n === 0) { return ''; }
      return (cur && cur !== '—' ? cur + ' ' : '') + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function looksLikeEmail(s) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(s || '')); }

    // ---------------------------------------------------------------- exports

    function filters() {
      return {
        event: filterEvent && filterEvent.value ? filterEvent.value : '',
        status: filterState && filterState.value ? filterState.value : ''
      };
    }
    function updateLinks() {
      var f = filters();
      var set = function (sel, url) { var el = $(sel); if (el) { el.href = url; } };
      set('[data-eva-export-events-csv]', A('export-events'));
      set('[data-eva-export-events-pdf]', A('print-events'));
      set('[data-eva-export-rsvps-csv]', withQuery(A('export-rsvps'), f));
      set('[data-eva-export-rsvps-pdf]', withQuery(A('print-rsvps'), f));
      if (scanLink) { scanLink.href = withQuery(A('checkin'), { event: f.event }); }
      if (purgeBtn) { purgeBtn.disabled = !f.event; }
    }

    // ---------------------------------------------------------------- tiles

    function moneyStrip(map) {
      var keys = Object.keys(map || {});
      if (!keys.length) { return { value: '—', note: '' }; }
      return {
        value: fmtMoney(map[keys[0]], keys[0]) || '—',
        note: keys.slice(1).map(function (k) { return fmtMoney(map[k], k); }).filter(Boolean).join(' · ')
      };
    }

    function renderTiles(t) {
      if (!tilesEl) { return; }
      t = t || {};
      var v = function (x) { return x == null ? 0 : x; };
      var money = moneyStrip(t.collected);
      var refunded = moneyStrip(t.refundable);
      var tiles = [
        { value: fmtNum(v(t.events)), label: T.events, note: fmt(T.upcomingPast, v(t.upcoming), v(t.past)) },
        { value: fmtNum(v(t.records)), label: T.registrations, tone: 'eva-tile--live', note: fmt(T.ofEvents, v(t.with_rsvps), v(t.events)) },
        { value: fmtNum(v(t.seats_taken)), label: T.seatsTaken, note: v(t.capacity_total) > 0 ? fmt(T.leftOf, fmtNum(v(t.seats_left)), fmtNum(v(t.capacity_total))) : T.noCapped },
        { value: fmtNum(v(t.waitlist)), label: T.waitlist },
        { value: fmtNum(v(t.cancelled)), label: T.cancelled },
        { value: fmtNum(v(t.checked_in)), label: T.checkedIn, tone: 'eva-tile--checkin' },
        { value: fmtNum(v(t.full)), label: T.full, tone: v(t.full) > 0 ? 'eva-tile--alert' : '' },
        { value: money.value, label: T.collected, tone: 'eva-tile--money', note: money.note || (refunded.value !== '—' ? fmt(T.cancelledMoney, refunded.value) : '') }
      ];
      tilesEl.innerHTML = tiles.map(function (x) {
        return '<div class="eva-tile ' + (x.tone || '') + '"><b>' + esc(x.value) + '</b><span>' + esc(x.label) + '</span>' + (x.note ? '<em>' + esc(x.note) + '</em>' : '') + '</div>';
      }).join('');
    }

    // ---------------------------------------------------------------- events table

    function renderEvents(events) {
      if (!eventsEl) { return; }
      events = events || [];
      if (!events.length) {
        eventsEl.innerHTML = '<div class="eva-empty">' + esc(T.noEvents) + ' <a href="' + esc(A('new-event')) + '">' + esc(T.addEvent) + '</a></div>';
        return;
      }
      var now = Math.floor(Date.now() / 1000);
      var rows = events.map(function (e) {
        var capped = Number(e.capacity) > 0;
        var full = capped && e.seats_left !== null && Number(e.seats_left) <= 0;
        var past = e.starts && Number(e.ends || (Number(e.starts) + 3600)) < now;
        var title = e.url && !e.orphan ? '<a class="eva-cell-title" href="' + esc(e.url) + '">' + esc(e.title) + '</a>' : '<span class="eva-cell-title">' + esc(e.title) + '</span>';
        if (e.orphan) { title += ' <span class="eva-tag eva-tag--orphan">' + esc(T.noPage) + '</span>'; }
        if (e.private) { title += ' <span class="eva-tag">' + esc(T.private) + '</span>'; }
        if (e.trashed) { title += ' <span class="eva-tag eva-tag--orphan">' + esc(T.inBin) + '</span>'; }
        if (e.status && e.status !== 'publish' && e.status !== 'private' && e.status !== 'trash') { title += ' <span class="eva-tag">' + esc(T.draft) + '</span>'; }
        if (past) { title += ' <span class="eva-tag eva-tag--past">' + esc(T.past) + '</span>'; }
        var collected = fmtMoney(e.collected, e.currency);
        var actions = '';
        var total = Number(e.records) + Number(e.cancelled);
        var del = function (what, label, cls, orphan) {
          return '<button type="button" class="eva-btn eva-btn--sm ' + (cls || '') + '"' + (orphan ? ' data-eva-orphan="1"' : '') + ' data-eva-event-do="' + what + '" data-eva-id="' + esc(e.id) + '" data-eva-title="' + esc(e.title) + '" data-eva-total="' + total + '">' + esc(label) + '</button>';
        };
        if (e.trashed) {
          actions = e.can_delete ? del('restore', T.restore, '') + del('delete', T.deleteForever, 'eva-btn--danger') : '';
          return '<tr class="eva-row-binned"><td>' + title + '</td><td class="eva-hide-md eva-nowrap">' + fmtWhen(e.starts) + '</td><td class="eva-hide-xl">' + esc(e.location || '—') + '</td>' +
            '<td class="eva-num">' + (capped ? fmtNum(e.capacity) : '∞') + '</td><td class="eva-num">' + fmtNum(e.seats_taken) + '</td><td class="eva-num">—</td>' +
            '<td class="eva-num eva-hide-md">' + fmtNum(e.going) + '</td><td class="eva-num">' + fmtNum(e.waitlist) + '</td><td class="eva-num eva-hide-md">' + fmtNum(e.cancelled) + '</td>' +
            '<td class="eva-num">' + fmtNum(e.checked_in) + '</td><td class="eva-num eva-hide-lg">' + (collected === '' ? '—' : esc(collected)) + '</td>' +
            '<td class="eva-actions-cell"><div class="eva-row-actions">' + actions + '</div></td></tr>';
        }
        if (!e.orphan) {
          actions += '<a class="eva-btn eva-btn--sm" href="' + esc(withQuery(A('cards'), { event: e.id })) + '" target="_blank" rel="noopener">' + esc(T.cards) + '</a>';
          actions += '<a class="eva-btn eva-btn--sm" href="' + esc(withQuery(A('checkin'), { event: e.id })) + '" target="_blank" rel="noopener">' + esc(T.scan) + '</a>';
        }
        if (Number(e.records) + Number(e.cancelled) > 0) {
          actions += '<button type="button" class="eva-btn eva-btn--sm" data-eva-show-event="' + esc(e.id) + '">' + esc(T.rsvps) + '</button>';
        }
        if (e.edit) { actions += '<a class="eva-btn eva-btn--sm" href="' + esc(e.edit) + '">' + esc(T.edit) + '</a>'; }
        if (e.orphan && total > 0) { actions += del('delete', T.removeRsvps, 'eva-btn--danger', true); }
        else if (!e.orphan && e.can_delete) { actions += del('trash', T.delete_, 'eva-btn--danger'); }
        return '<tr>' +
          '<td>' + title + '</td>' +
          '<td class="eva-hide-md eva-nowrap">' + fmtWhen(e.starts) + '</td>' +
          '<td class="eva-hide-xl">' + esc(e.location || '—') + '</td>' +
          '<td class="eva-num">' + (capped ? fmtNum(e.capacity) : '∞') + '</td>' +
          '<td class="eva-num">' + fmtNum(e.seats_taken) + '</td>' +
          '<td class="eva-num">' + (capped ? fmtNum(e.seats_left) : '—') + (full ? ' <span class="eva-tag eva-tag--full">' + esc(T.fullTag) + '</span>' : '') + '</td>' +
          '<td class="eva-num eva-hide-md">' + fmtNum(e.going) + '</td>' +
          '<td class="eva-num">' + fmtNum(e.waitlist) + '</td>' +
          '<td class="eva-num eva-hide-md">' + fmtNum(e.cancelled) + '</td>' +
          '<td class="eva-num">' + fmtNum(e.checked_in) + '</td>' +
          '<td class="eva-num eva-hide-lg">' + (collected === '' ? '—' : esc(collected)) + '</td>' +
          '<td class="eva-actions-cell"><div class="eva-row-actions">' + actions + '</div></td>' +
          '</tr>';
      }).join('');
      eventsEl.innerHTML = '<table class="eva-table"><thead><tr>' +
        '<th>' + esc(T.colEvent) + '</th><th class="eva-hide-md">' + esc(T.colWhen) + '</th><th class="eva-hide-xl">' + esc(T.colLocation) + '</th>' +
        '<th class="eva-num">' + esc(T.colCapacity) + '</th><th class="eva-num">' + esc(T.colTaken) + '</th><th class="eva-num">' + esc(T.colLeft) + '</th>' +
        '<th class="eva-num eva-hide-md">' + esc(T.colGoing) + '</th><th class="eva-num">' + esc(T.colWaitlist) + '</th><th class="eva-num eva-hide-md">' + esc(T.colCancelled) + '</th>' +
        '<th class="eva-num">' + esc(T.colChecked) + '</th><th class="eva-num eva-hide-lg">' + esc(T.colCollected) + '</th><th></th>' +
        '</tr></thead><tbody>' + rows + '</tbody></table>';
    }

    function fillEventFilter(events) {
      if (!filterEvent) { return; }
      var previous = filterEvent.value || initialEvent;
      filterEvent.innerHTML = '<option value="">' + esc(T.allEvents) + '</option>';
      (events || []).forEach(function (e) {
        var opt = document.createElement('option');
        opt.value = e.id;
        opt.textContent = e.title + (Number(e.records) + Number(e.cancelled) ? '' : ' ' + T.none);
        filterEvent.appendChild(opt);
      });
      if (previous && filterEvent.querySelector('option[value="' + previous + '"]')) { filterEvent.value = previous; }
      updateLinks();
    }

    // ---------------------------------------------------------------- RSVP table

    function statusLabel(s) { return s === 'going' ? T.going : (s === 'waitlist' ? T.waitlistS : (s === 'cancelled' ? T.cancelledS : s)); }

    function registrant(r) {
      var name = String(r.name || '').trim();
      var mail = String(r.guest_email || '').trim();
      if (name === '') { name = looksLikeEmail(mail) ? mail : '—'; }
      var h = '<button type="button" class="eva-cell-name" data-eva-open-detail="' + esc(r.id) + '">' + esc(name) + '</button>';
      if (looksLikeEmail(mail) && mail !== name) { h += '<div class="eva-cell-sub eva-mono">' + esc(mail) + '</div>'; }
      if (r.phone) { h += '<div class="eva-cell-sub">' + esc(r.phone) + '</div>'; }
      if (r.checkins && r.checkins.primary) { h += '<div class="eva-cell-sub"><span class="eva-tag eva-tag--checkedin">' + esc(T.checkedInTag) + '</span></div>'; }
      var amt = parseFloat(r.amount);
      if (isFinite(amt) && amt > 0) { h += '<span class="eva-cell-amount">' + esc(fmt(T.paid, fmtMoney(amt, r.currency))) + '</span>'; }
      if (r.proof_files && r.proof_files.length) {
        h += '<div class="eva-cell-sub"><button type="button" class="eva-proof-tag ' + (r.verified_at ? 'is-checked' : 'is-pending') + '" data-eva-open-detail="' + esc(r.id) + '">' +
          esc((r.verified_at ? T.proofChecked : T.proofToCheck) + ' · ' + r.proof_files.length) + '</button></div>';
      }
      return h;
    }

    function attendees(r) {
      var list = Array.isArray(r.attendees) ? r.attendees : [];
      if (!list.length) {
        var seats = parseInt(r.guests, 10) || 1;
        return seats > 1 ? '<ul class="eva-attendees"><li><span class="eva-muted">' + esc(fmt(T.seatsNoDetails, seats)) + '</span></li></ul>' : '<span class="eva-muted">—</span>';
      }
      return '<ul class="eva-attendees">' + list.map(function (a, i) {
        var p = '<span class="eva-att-name">' + esc(a.name || fmt(T.attendeeN, i + 2)) + '</span>';
        if (looksLikeEmail(a.email)) { p += '<span class="eva-att-line eva-mono">' + esc(a.email) + '</span>'; }
        if (a.phone) { p += '<span class="eva-att-line">' + esc(a.phone) + '</span>'; }
        var amt = parseFloat(a.amount);
        if (isFinite(amt) && amt > 0) { p += '<span class="eva-att-amount">' + esc(fmtMoney(amt, r.currency)) + '</span>'; }
        if (r.checkins && r.checkins['attendee_' + i]) { p += ' <span class="eva-tag eva-tag--checkedin">' + esc(T.checkedInTag) + '</span>'; }
        return '<li>' + p + '</li>';
      }).join('') + '</ul>';
    }

    function matches(r, q) {
      if (!q) { return true; }
      var hay = [r.name, r.guest_email, r.phone, r.checkin_code, r.event_title].concat((r.attendees || []).map(function (a) { return [a.name, a.email, a.phone, a.code].join(' '); })).join(' ').toLowerCase();
      var digits = q.replace(/\D+/g, '');
      return hay.indexOf(q) !== -1 || (digits.length >= 6 && hay.replace(/\D+/g, '').indexOf(digits) !== -1);
    }

    function renderRsvps(rows, truncated, limit) {
      if (!rsvpsEl) { return; }
      lastRows = rows || [];
      var q = searchEl ? searchEl.value.trim().toLowerCase() : '';
      var shown = lastRows.filter(function (r) { return matches(r, q); });
      if (!shown.length) { rsvpsEl.innerHTML = '<div class="eva-empty">' + esc(T.noMatch) + '</div>'; return; }
      var body = shown.map(function (r) {
        var actions = '<a class="eva-btn eva-btn--sm" href="' + esc(withQuery(A('cards'), { event: r.event_id, id: r.id })) + '" target="_blank" rel="noopener">' + esc(T.card) + '</a>';
        if (r.status !== 'cancelled') {
          actions += '<button type="button" class="eva-btn eva-btn--sm" data-eva-cancel="' + esc(r.id) + '" data-eva-event="' + esc(r.event_id) + '">' + esc(T.cancel) + '</button>';
        }
        actions += '<button type="button" class="eva-btn eva-btn--danger eva-btn--sm" data-eva-delete="' + esc(r.id) + '" data-eva-event="' + esc(r.event_id) + '">' + esc(T.delete) + '</button>';
        return '<tr>' +
          '<td>' + esc(r.event_title) + '</td>' +
          '<td class="eva-hide-md">' + esc(fmtTime(r.created)) + '</td>' +
          '<td>' + registrant(r) + '</td>' +
          '<td>' + attendees(r) + '</td>' +
          '<td class="eva-num">' + esc(r.guests || 1) + '</td>' +
          '<td><span class="eva-tag eva-tag--' + esc(r.status) + '">' + esc(statusLabel(r.status)) + '</span></td>' +
          '<td><div class="eva-row-actions">' + actions + '</div></td>' +
          '</tr>';
      }).join('');
      rsvpsEl.innerHTML = '<table class="eva-table"><thead><tr>' +
        '<th>' + esc(T.colEvent) + '</th><th class="eva-hide-md">' + esc(T.colRegistered) + '</th><th>' + esc(T.colRegistrant) + '</th>' +
        '<th>' + esc(T.colAttendees) + '</th><th class="eva-num">' + esc(T.colSeats) + '</th><th>' + esc(T.colStatus) + '</th><th></th>' +
        '</tr></thead><tbody>' + body + '</tbody></table>' +
        (truncated ? '<div class="eva-empty">' + esc(fmt(T.newest, fmtNum(limit))) + '</div>' : '');
    }

    // ---------------------------------------------------------------- detail dialog

    function openDetail(id) {
      var row = null;
      lastRows.forEach(function (r) { if (r.id === id) { row = r; } });
      if (!row || !detailModal) { return; }
      root.querySelector('[data-eva-detail-title]').textContent = row.name || (looksLikeEmail(row.guest_email) ? row.guest_email : T.registration);
      var line = function (k, v) { return '<div class="eva-detail-row"><span>' + esc(k) + '</span><b>' + v + '</b></div>'; };
      var h = line(T.dEvent, esc(row.event_title)) + line(T.dStatus, esc(statusLabel(row.status))) + line(T.dSeats, esc(row.guests || 1));
      if (looksLikeEmail(row.guest_email)) { h += line(T.dEmail, esc(row.guest_email)); }
      if (row.phone) { h += line(T.dPhone, esc(row.phone)); }
      var amt = parseFloat(row.amount);
      if (isFinite(amt) && amt > 0) { h += line(T.dPaid, esc(fmtMoney(amt, row.currency))); }
      if (row.checkin_code) { h += line(T.dCode, '<code>' + esc(row.checkin_code) + '</code>'); }
      h += line(T.dRegChecked, esc(row.checkins && row.checkins.primary ? T.yes : T.no));
      var proofs = row.proof_files || [];
      if (proofs.length || isFinite(amt) && amt > 0) {
        h += '<div class="eva-detail-head">' + esc(T.dProof) + '</div>';
        if (!proofs.length) { h += '<p class="eva-cell-sub">' + esc(T.noProof) + '</p>'; }
        h += '<div class="eva-proofs">' + proofs.map(function (p) {
          return '<a class="eva-proof" href="' + esc(p.url) + '" target="_blank" rel="noopener">' +
            (p.image ? '<img src="' + esc(p.url) + '" alt="" loading="lazy">' : '<span class="eva-proof-ext">' + esc((p.name.split('.').pop() || '').toUpperCase()) + '</span>') +
            '<span class="eva-proof-name">' + esc(p.name) + '</span><span class="eva-cell-sub">' + esc(p.size) + '</span></a>';
        }).join('') + '</div>';
        if (proofs.length) {
          if (row.verified_at) {
            h += '<p class="eva-verified">' + esc(fmt(T.checkedBy, row.verified_by_name || '—', fmtTime(row.verified_at))) + '</p>';
          }
          h += '<div class="eva-detail-actions"><button type="button" class="eva-btn ' + (row.verified_at ? '' : 'eva-btn--primary') + '" data-eva-verify="' + esc(row.id) + '" data-eva-event="' + esc(row.event_id) + '" data-eva-on="' + (row.verified_at ? '0' : '1') + '">' +
            esc(row.verified_at ? T.unmark : T.markChecked) + '</button></div>';
        }
      }
      var list = Array.isArray(row.attendees) ? row.attendees : [];
      if (list.length) {
        h += '<div class="eva-detail-head">' + esc(T.dAttendees) + '</div>';
        list.forEach(function (a, i) {
          var inn = !!(row.checkins && row.checkins['attendee_' + i]);
          h += '<div class="eva-detail-attendee"><p class="name">' + esc(a.name || fmt(T.attendeeN, i + 2)) + '</p>';
          if (looksLikeEmail(a.email)) { h += '<div class="eva-cell-sub eva-mono">' + esc(a.email) + '</div>'; }
          if (a.phone) { h += '<div class="eva-cell-sub">' + esc(a.phone) + '</div>'; }
          if (a.code) { h += '<div class="eva-cell-sub eva-mono">' + esc(a.code) + '</div>'; }
          var aa = parseFloat(a.amount);
          if (isFinite(aa) && aa > 0) { h += '<span class="eva-cell-amount">' + esc(fmt(T.paid, fmtMoney(aa, row.currency))) + '</span> '; }
          h += '<span class="eva-tag ' + (inn ? 'eva-tag--checkedin' : '') + '">' + esc(inn ? T.checkedInTag : T.notCheckedIn) + '</span></div>';
        });
      }
      root.querySelector('[data-eva-detail-body]').innerHTML = h;
      detailModal.hidden = false;
      root.querySelector('[data-eva-detail-close]').focus();
    }
    function closeDetail() { if (detailModal) { detailModal.hidden = true; } }
    root.querySelector('[data-eva-detail-close]').addEventListener('click', closeDetail);
    detailModal.addEventListener('click', function (e) {
      var v = e.target.closest ? e.target.closest('[data-eva-verify]') : null;
      if (!v) { return; }
      var on = v.getAttribute('data-eva-on') === '1';
      v.disabled = true;
      req('/admin/verify', null, { method: 'POST', body: { event: Number(v.getAttribute('data-eva-event')), id: v.getAttribute('data-eva-verify'), verified: on } })
        .then(function () {
          show(on ? T.checkedMsg : T.uncheckedMsg, '');
          var id = v.getAttribute('data-eva-verify');
          return loadRsvps().then(function () { openDetail(id); });
        })
        .catch(function (err) { show(err.message, 'error'); v.disabled = false; });
    });
    detailModal.addEventListener('click', function (e) { if (e.target === detailModal) { closeDetail(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !detailModal.hidden) { closeDetail(); } });

    // ---------------------------------------------------------------- loading

    function loadStats() {
      return req('/admin/stats').then(function (data) {
        renderTiles(data.totals);
        renderEvents(data.events);
        fillEventFilter(data.events);
        if (eventCount) { eventCount.textContent = (data.events || []).length; }
        if (rsvpCount && !rsvpsLoaded) {
          var t = data.totals || {};
          rsvpCount.textContent = fmtNum((t.records || 0) + (t.cancelled || 0));
        }
      }).catch(function (err) {
        sectionError(eventsEl, T.events, err);
        renderTiles(null);
        throw err;
      });
    }

    function loadRsvps() {
      var f = filters();
      if (!rsvpsLoaded && rsvpsEl) { rsvpsEl.innerHTML = '<div class="eva-empty">' + esc(T.loadingRsvps) + '</div>'; }
      updateLinks();
      return req('/admin/rsvps', { event: f.event, status: f.status, limit: 500 }).then(function (data) {
        rsvpsLoaded = true;
        renderRsvps(data.rsvps, !!data.truncated, data.limit || 500);
        if (rsvpCount) { rsvpCount.textContent = fmtNum(data.count || 0); }
      }).catch(function (err) {
        sectionError(rsvpsEl, T.rsvps, err);
        throw err;
      });
    }

    function reload() {
      var jobs = [loadStats()];
      if (rsvpsLoaded) { jobs.push(loadRsvps()); }
      return Promise.allSettled(jobs).then(function (results) {
        if (results.some(function (r) { return r.status === 'rejected'; })) { show(T.someFailed, 'error'); }
      });
    }

    // ---------------------------------------------------------------- tabs

    function activate(name) {
      Array.prototype.forEach.call(tabs, function (t) {
        var on = t.getAttribute('data-eva-tab') === name;
        t.classList.toggle('is-on', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      Array.prototype.forEach.call(panels, function (p) { p.classList.toggle('is-on', p.getAttribute('data-eva-panel') === name); });
      if (name === 'rsvps' && !rsvpsLoaded) { loadRsvps().catch(function () {}); }
    }
    Array.prototype.forEach.call(tabs, function (tab) {
      tab.addEventListener('click', function () { activate(tab.getAttribute('data-eva-tab')); });
    });

    // ---------------------------------------------------------------- confirm dialog

    function confirmDialog(o) {
      var titleEl = modalEl.querySelector('[data-eva-modal-title]');
      var bodyEl = modalEl.querySelector('[data-eva-modal-body]');
      var noEl = modalEl.querySelector('[data-eva-modal-cancel]');
      var yesEl = modalEl.querySelector('[data-eva-modal-confirm]');
      titleEl.textContent = o.title || '';
      bodyEl.textContent = o.body || '';
      noEl.textContent = o.cancel || T.keepIt;
      yesEl.textContent = o.confirm || 'OK';
      yesEl.className = 'eva-btn ' + (o.tone === 'danger' ? 'eva-btn--danger' : 'eva-btn--primary');
      modalEl.hidden = false;
      noEl.focus();
      return new Promise(function (resolve) {
        function done(v) {
          modalEl.hidden = true;
          document.removeEventListener('keydown', onKey, true);
          noEl.removeEventListener('click', onNo);
          yesEl.removeEventListener('click', onYes);
          modalEl.removeEventListener('click', onBack);
          resolve(v);
        }
        function onNo() { done(false); }
        function onYes() { done(true); }
        function onBack(e) { if (e.target === modalEl) { done(false); } }
        function onKey(e) {
          if (e.key === 'Escape') { e.stopPropagation(); done(false); }
          if (e.key === 'Tab') { e.preventDefault(); (document.activeElement === yesEl ? noEl : yesEl).focus(); }
        }
        noEl.addEventListener('click', onNo);
        yesEl.addEventListener('click', onYes);
        modalEl.addEventListener('click', onBack);
        document.addEventListener('keydown', onKey, true);
      });
    }

    // ---------------------------------------------------------------- actions

    function afterChange(text, data) {
      var moved = data && data.promoted ? Number(data.promoted) : 0;
      show(text + (moved ? ' ' + fmt(T.movedUp, moved) : ''), '');
      return reload();
    }

    if (applyBtn) { applyBtn.addEventListener('click', function () { loadRsvps().catch(function () {}); }); }
    if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
        refreshBtn.disabled = true;
        reload().then(function () { refreshBtn.disabled = false; });
      });
    }
    if (filterEvent) { filterEvent.addEventListener('change', function () { updateLinks(); if (rsvpsLoaded) { loadRsvps().catch(function () {}); } }); }
    if (filterState) { filterState.addEventListener('change', function () { updateLinks(); if (rsvpsLoaded) { loadRsvps().catch(function () {}); } }); }
    if (searchEl) {
      searchEl.addEventListener('input', function () { if (rsvpsLoaded) { renderRsvps(lastRows, false, 0); } });
      searchEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
    }

    root.addEventListener('click', function (e) {
      var t = e.target && e.target.closest ? e.target.closest('[data-eva-open-detail], [data-eva-show-event], [data-eva-cancel], [data-eva-delete], [data-eva-purge], [data-eva-event-do]') : null;
      if (!t || !root.contains(t)) { return; }

      if (t.hasAttribute('data-eva-event-do')) {
        var what = t.getAttribute('data-eva-event-do');
        var name = t.getAttribute('data-eva-title');
        var orphan = t.hasAttribute('data-eva-orphan');
        var run = function () {
          t.disabled = true;
          req('/admin/event', null, { method: 'POST', body: { event: Number(t.getAttribute('data-eva-id')), do: what } })
            .then(function (d) {
              show(what === 'trash' ? T.trashedMsg : (what === 'restore' ? T.restoredMsg : fmt(T.deletedEventMsg, (d && d.removed) || 0)), '');
              if (filterEvent && filterEvent.value === t.getAttribute('data-eva-id') && what !== 'restore') { filterEvent.value = ''; }
              rsvpsLoaded = false;
              return reload();
            })
            .catch(function (err) { show(fmt(T.failed, t.textContent, err.message), 'error'); t.disabled = false; });
        };
        if (what === 'restore') { run(); return; }
        confirmDialog(what === 'trash'
          ? { title: fmt(T.trashTitle, name), body: T.trashBody, confirm: T.trashYes, cancel: T.keepIt, tone: 'danger' }
          : { title: fmt(orphan ? T.orphanTitle : T.foreverTitle, name), body: fmt(orphan ? T.orphanBody : T.foreverBody, t.getAttribute('data-eva-total')), confirm: orphan ? T.removeRsvps : T.deleteForever, cancel: T.keepIt, tone: 'danger' }
        ).then(function (ok) { if (ok) { run(); } });
        return;
      }

      if (t.hasAttribute('data-eva-open-detail')) { openDetail(t.getAttribute('data-eva-open-detail')); return; }

      if (t.hasAttribute('data-eva-show-event')) {
        if (filterEvent) { filterEvent.value = t.getAttribute('data-eva-show-event'); }
        if (filterState) { filterState.value = ''; }
        rsvpsLoaded = false;
        activate('rsvps');
        return;
      }

      if (t.hasAttribute('data-eva-cancel')) {
        confirmDialog({ title: T.cancelTitle, body: T.cancelBody, confirm: T.cancelYes, cancel: T.keepIt, tone: 'danger' }).then(function (ok) {
          if (!ok) { return; }
          t.disabled = true;
          req('/cancel', null, { method: 'POST', body: { event: Number(t.getAttribute('data-eva-event')), id: t.getAttribute('data-eva-cancel') } })
            .then(function (d) { return afterChange(T.cancelledMsg, d); })
            .catch(function (err) { show(fmt(T.failed, T.cancel, err.message), 'error'); t.disabled = false; });
        });
        return;
      }

      if (t.hasAttribute('data-eva-delete')) {
        confirmDialog({ title: T.deleteTitle, body: T.deleteBody, confirm: T.deleteYes, cancel: T.keepIt, tone: 'danger' }).then(function (ok) {
          if (!ok) { return; }
          t.disabled = true;
          req('/admin/delete', null, { method: 'POST', body: { event: Number(t.getAttribute('data-eva-event')), id: t.getAttribute('data-eva-delete') } })
            .then(function (d) { return afterChange(T.deletedMsg, d); })
            .catch(function (err) { show(fmt(T.failed, T.delete, err.message), 'error'); t.disabled = false; });
        });
        return;
      }

      if (t.hasAttribute('data-eva-purge')) {
        var ev = filterEvent && filterEvent.value;
        if (!ev) { show(T.selectEvent, 'error'); return; }
        var label = filterEvent.options[filterEvent.selectedIndex].textContent;
        confirmDialog({ title: T.purgeTitle, body: fmt(T.purgeBody, label), confirm: T.purgeYes, cancel: T.keepThem, tone: 'danger' }).then(function (ok) {
          if (!ok) { return; }
          t.disabled = true;
          req('/admin/delete', null, { method: 'POST', body: { event: Number(ev), purge: true } })
            .then(function (d) { return afterChange(fmt(T.purgedMsg, (d && d.removed) || 0), null); })
            .catch(function (err) { show(fmt(T.failed, T.purgeYes, err.message), 'error'); })
            .then(function () { updateLinks(); });
        });
      }
    });

    updateLinks();
    loadStats().then(function () {
      if (initialEvent) { activate('rsvps'); }
    }).catch(function () {});
  }

  function start() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-eva-root]'), init);
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
