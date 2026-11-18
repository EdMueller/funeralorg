// Portrait-Rahmen mit mehreren Bildern: weiche Überblendung nacheinander (Anzeige 6 s, Überblendung 1,5 s, siehe CSS)
(function () {
  'use strict';
  var frames = Array.prototype.slice.call(document.querySelectorAll('[data-portrait-fade]'));
  if (!frames.length) return;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  frames.forEach(function (frame) {
    var imgs = Array.prototype.slice.call(frame.querySelectorAll('img'));
    if (imgs.length < 2) return;
    frame.classList.add('fading');
    imgs[0].classList.add('on');
    if (reduce) return; // keine Bewegung: erstes Bild bleibt stehen
    var i = 0;
    setInterval(function () {
      if (document.hidden) return;
      imgs[i].classList.remove('on');
      i = (i + 1) % imgs.length;
      imgs[i].classList.add('on');
    }, 6000);
  });
})();
