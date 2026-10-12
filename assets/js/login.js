// Ruhige Hintergrund-Diashow auf der Anmeldeseite (Überblendung alle 8 s)
(function () {
  'use strict';
  var stage = document.getElementById('loginStage');
  if (!stage) return;
  var slides = [];
  try { slides = JSON.parse(stage.dataset.slides || '[]'); } catch (e) { slides = []; }
  if (!slides.length) return;

  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var els = slides.map(function (src) {
    var d = document.createElement('div');
    d.className = 'slide';
    d.dataset.src = src;
    stage.appendChild(d);
    return d;
  });
  var i = 0;

  function load(el, cb) {
    if (el.dataset.loaded) { cb(); return; }
    var img = new Image();
    img.onload = img.onerror = function () {
      el.style.backgroundImage = 'url("' + el.dataset.src + '")';
      el.dataset.loaded = '1';
      cb();
    };
    img.src = el.dataset.src;
  }

  function show(n) {
    var el = els[n];
    load(el, function () {
      els.forEach(function (x) { if (x !== el) x.classList.remove('on'); });
      // Animation neu starten
      el.classList.remove('on'); void el.offsetWidth; el.classList.add('on');
      // nächstes Bild vorladen
      if (els.length > 1) load(els[(n + 1) % els.length], function () {});
    });
  }

  show(0);
  if (els.length > 1 && !reduce) {
    setInterval(function () { i = (i + 1) % els.length; show(i); }, 8000);
  }
})();
