// v2 web UI: small progressive enhancements. Every page works without this file.
(function () {
  'use strict';
  var PLAY = 'M8 5v14l11-7z', PAUSE = 'M7 5h4v14H7zM13 5h4v14h-4z';

  // A photo that fails to load becomes an initial-letter placeholder.
  function initPhotoFallback() {
    function swap(img) {
      var span = document.createElement('span');
      span.className = img.className + ' placeholder';
      span.setAttribute('aria-hidden', 'true');
      span.textContent = img.getAttribute('data-initial') || '';
      img.replaceWith(span);
    }
    document.addEventListener('error', function (e) {
      var img = e.target;
      if (img && img.tagName === 'IMG' && img.classList.contains('photo')) swap(img);
    }, true);
    // This script is deferred: a photo that failed while the page was parsing (offline, eager
    // hero) fired its error before the listener existed.
    var imgs = document.querySelectorAll('img.photo');
    for (var i = 0; i < imgs.length; i++) {
      if (imgs[i].complete && imgs[i].naturalWidth === 0) swap(imgs[i]);
    }
  }

  // One shared <audio id="player">: a .play button toggles its clip; starting one resets any other.
  function initPlayer() {
    var player = document.getElementById('player'), current = null;
    if (!player) return;
    function show(btn, on) {
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      var p = btn.querySelector('path');
      if (p) p.setAttribute('d', on ? PAUSE : PLAY);
    }
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('button.play[data-src]');
      if (!btn) return;
      if (btn === current && !player.paused) { player.pause(); return; }
      if (current && current !== btn) show(current, false);
      current = btn;
      if (player.getAttribute('src') !== btn.dataset.src) player.src = btn.dataset.src;
      player.play().catch(function () { show(btn, false); });
    });
    player.addEventListener('play', function () { if (current) show(current, true); });
    player.addEventListener('pause', function () { if (current) show(current, false); });
    player.addEventListener('ended', function () { if (current) show(current, false); });
    player.addEventListener('error', function () { if (current) show(current, false); });
  }

  // A select[data-autosubmit] submits its form on change (the form keeps a no-JS submit button).
  function initAutoSubmit() {
    document.addEventListener('change', function (e) {
      var el = e.target;
      if (el && el.matches && el.matches('select[data-autosubmit]') && el.form) el.form.submit();
    });
  }

  // a[data-back] goes back in history when we came from this site; otherwise it follows href.
  function initBackLink() {
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[data-back]');
      if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var same = false;
      try { same = !!document.referrer && new URL(document.referrer).origin === location.origin; } catch (err) { same = false; }
      if (same && history.length > 1) { e.preventDefault(); history.back(); }
    });
  }

  // Status log: the gotty iframe. A failed probe of /log/ shows the "Verbinding verbroken" box;
  // Pauzeer detaches the iframe (about:blank), Hervat reattaches it. No timer.
  function initLog() {
    var frame = document.getElementById('log'), box = document.getElementById('logdown');
    var state = document.getElementById('logstate'), pause = document.getElementById('logpause');
    var retry = document.getElementById('logretry');
    if (!frame || !box) return;
    var src = frame.getAttribute('src'), paused = false;
    function label() { if (state) state.textContent = !box.hidden ? 'verbroken' : paused ? 'gepauzeerd' : 'live'; }
    function probe(onUp) {
      fetch(src, { cache: 'no-store' }).then(function (r) {
        if (!r.ok) throw new Error(r.status);
        frame.hidden = false; box.hidden = true; label();
        if (onUp) onUp();
      }).catch(function () { frame.hidden = true; box.hidden = false; label(); });
    }
    if (pause) pause.addEventListener('click', function () {
      paused = !paused;
      pause.setAttribute('aria-pressed', paused ? 'true' : 'false');
      pause.querySelector('span').textContent = paused ? 'Hervat' : 'Pauzeer';
      pause.querySelector('path').setAttribute('d', paused ? PLAY : PAUSE);
      frame.src = paused ? 'about:blank' : src;
      label();
    });
    if (retry) retry.addEventListener('click', function () { probe(function () { if (!paused) frame.src = src; }); });
    probe();
  }

  document.documentElement.classList.add('js');
  initPhotoFallback();
  initPlayer();
  initAutoSubmit();
  initBackLink();
  initLog();
})();
