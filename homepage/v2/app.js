// v2 web UI: small progressive enhancements. Every page works without this file.
(function () {
  'use strict';
  var PLAY = 'M8 5v14l11-7z', PAUSE = 'M7 5h4v14H7zM13 5h4v14h-4z';

  // A photo that fails to load becomes an initial-letter placeholder.
  function initPhotoFallback() {
    document.addEventListener('error', function (e) {
      var img = e.target;
      if (!img || img.tagName !== 'IMG' || !img.classList.contains('photo')) return;
      var span = document.createElement('span');
      span.className = img.className + ' placeholder';
      span.setAttribute('aria-hidden', 'true');
      span.textContent = img.getAttribute('data-initial') || '';
      img.replaceWith(span);
    }, true);
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
  }

  initPhotoFallback();
  initPlayer();
})();
