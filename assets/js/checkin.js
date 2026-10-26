/*
 * Sepo Events — door check-in.
 *
 * Scan mode reads QR codes with the browser's built-in BarcodeDetector.
 * Manual mode sends a typed code, email or phone. Both post the same
 * request; the site tries the value as a code, then an email, then a phone.
 * Opening this page from a card's QR link (event and code in the address)
 * checks that person in straight away.
 */
(function () {
  'use strict';

  var cfg = window.SepoCheckin || {};
  var T = cfg.i18n || {};
  var $ = function (id) { return document.getElementById(id); };

  var video = $('video');
  var camHint = $('camHint');
  var scanCol = $('scanCol');
  var scanNote = $('scanNote');
  var modeScan = $('modeScan');
  var modeManual = $('modeManual');
  var eventIn = $('eventInput');
  var codeIn = $('codeInput');
  var manualBtn = $('manualBtn');
  var resultEl = $('result');
  var logList = $('logList');

  var mode = 'scan';
  var scanning = false;
  var busy = false;
  var detector = null;
  var lastCode = '';
  var lastAt = 0;

  function fmt(s, v) { return String(s || '').replace('%s', v); }

  function showResult(tone, name, meta, eventLine, tag) {
    resultEl.className = 'result show ' + tone;
    $('resName').textContent = name || '';
    $('resMeta').textContent = meta || '';
    $('resEvent').textContent = eventLine || '';
    $('resTag').textContent = tag || '';
  }

  function addLog(tone, text) {
    var li = document.createElement('li');
    var b = document.createElement('b');
    var s = document.createElement('span');
    b.textContent = text;
    s.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    s.style.color = tone === 'err' ? '#e08a8a' : (tone === 'warn' ? '#e0c58a' : '#7fd99a');
    li.appendChild(b);
    li.appendChild(s);
    logList.insertBefore(li, logList.firstChild);
    while (logList.children.length > 8) { logList.removeChild(logList.lastChild); }
  }

  function eventTitle() {
    var o = eventIn.options[eventIn.selectedIndex];
    return o ? o.textContent.split(' — ')[0] : '';
  }

  function submit(eventId, value) {
    if (busy) { return; }
    if (!eventId || !value) {
      showResult('warn', T.missing, T.missingBody, '', T.incomplete);
      return;
    }
    busy = true;
    manualBtn.disabled = true;
    fetch(cfg.api + '/admin/checkin', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
      body: JSON.stringify({ event: Number(eventId), code: value })
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
        return { ok: res.ok, data: data };
      });
    }).then(function (r) {
      if (!r.data) {
        showResult('err', T.noReply, T.noReplyBody, '', T.error);
        addLog('err', T.noReply);
        return;
      }
      if (!r.ok || r.data.status === 'error' || r.data.code) {
        var msg = r.data.message || T.notIn;
        showResult('err', T.notIn, msg, eventTitle(), T.error);
        addLog('err', msg);
        return;
      }
      var d = r.data.data || {};
      var p = d.person || {};
      var already = !!d.already;
      var waiting = d.status === 'waitlist';
      var meta = [p.email, p.phone].filter(Boolean).join(' · ') || '—';
      if (d.matched_by === 'email') { meta += ' · ' + T.byEmail; }
      if (d.matched_by === 'phone') { meta += ' · ' + T.byPhone; }
      if (already && d.at) { meta += ' · ' + fmt(T.at, new Date(d.at * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })); }
      showResult(already || waiting ? 'warn' : 'ok', p.name || T.noName, meta, (d.event && d.event.title) || eventTitle(), already ? T.already : (waiting ? T.waitlist : T.checkedIn));
      addLog(already || waiting ? 'warn' : 'ok', fmt(already ? T.alreadyLog : T.inLog, p.name || T.guest));
    }).catch(function () {
      showResult('err', T.network, T.networkBody, '', T.error);
      addLog('err', T.network);
    }).then(function () {
      busy = false;
      manualBtn.disabled = false;
      if (mode === 'manual') { codeIn.focus(); codeIn.select(); }
    });
  }

  manualBtn.addEventListener('click', function () { submit(eventIn.value, codeIn.value.trim()); });
  codeIn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); manualBtn.click(); } });

  // ------------------------------------------------------------ mode toggle

  function setMode(next) {
    mode = next === 'manual' ? 'manual' : 'scan';
    var manual = mode === 'manual';
    modeScan.classList.toggle('is-on', !manual);
    modeScan.setAttribute('aria-selected', manual ? 'false' : 'true');
    modeManual.classList.toggle('is-on', manual);
    modeManual.setAttribute('aria-selected', manual ? 'true' : 'false');
    scanCol.hidden = manual;
    scanCol.parentNode.classList.toggle('is-manual', manual);
    if (manual) {
      // Pause the camera: a phone at the door saves its battery.
      scanning = false;
      try { video.pause(); } catch (e) { /* not playing */ }
      codeIn.focus();
    } else if (video.srcObject) {
      try { video.play(); } catch (e) { /* resumes on its own */ }
      scanning = true;
      tick();
    } else {
      startCamera();
    }
  }
  modeScan.addEventListener('click', function () { setMode('scan'); });
  modeManual.addEventListener('click', function () { setMode('manual'); });

  // ------------------------------------------------------------ camera

  function noDetector() {
    scanNote.hidden = false;
    scanNote.textContent = T.noDetector;
    camHint.textContent = '';
  }

  function startCamera() {
    if (!('BarcodeDetector' in window)) { noDetector(); return; }
    var make = window.BarcodeDetector.getSupportedFormats ? window.BarcodeDetector.getSupportedFormats() : Promise.resolve(['qr_code']);
    make.then(function (formats) {
      if (formats.indexOf('qr_code') === -1) { noDetector(); return; }
      detector = new window.BarcodeDetector({ formats: ['qr_code'] });
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        camHint.textContent = T.noCamera;
        return;
      }
      camHint.textContent = T.starting;
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (stream) {
        video.srcObject = stream;
        video.play();
        scanning = true;
        camHint.textContent = T.scanning;
        tick();
      }).catch(function () { camHint.textContent = T.noCamera; });
    }).catch(noDetector);
  }

  function handlePayload(text) {
    var ev = '';
    var code = '';
    try {
      var url = new URL(text, window.location.href);
      ev = url.searchParams.get('event') || '';
      code = url.searchParams.get('code') || '';
    } catch (e) { /* plain text below */ }
    if (!code) {
      code = String(text || '').trim();
      ev = eventIn.value;
    }
    if (!ev || !code) { return; }
    var now = Date.now();
    if (code === lastCode && now - lastAt < 4000) { return; }
    lastCode = code;
    lastAt = now;
    if (eventIn.querySelector('option[value="' + ev + '"]')) { eventIn.value = ev; }
    codeIn.value = code;
    submit(ev, code);
  }

  function tick() {
    if (!scanning || !detector) { return; }
    if (video.readyState >= 2 && !busy) {
      detector.detect(video).then(function (codes) {
        if (codes && codes.length && codes[0].rawValue) { handlePayload(codes[0].rawValue); }
      }).catch(function () { /* next frame */ }).then(function () { setTimeout(tick, 180); });
      return;
    }
    setTimeout(tick, 180);
  }

  // A card opened by a phone's own camera arrives with the event and code: check in at once.
  if (eventIn.value && codeIn.value.trim()) {
    submit(eventIn.value, codeIn.value.trim());
  }
  startCamera();
})();
