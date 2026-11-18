// Gedenkseite bearbeiten: Portraitbilder wählen und Reihenfolge ändern
// Wählen/Entfernen: Klick auf das Bild im Raster. Sortieren: Ziehen (Maus sofort, Touch/Stift nach langem Druck),
// Tastatur: Kachel fokussieren und Pfeiltasten links/rechts.
(function () {
  'use strict';
  var root = document.querySelector('[data-portrait-picker]');
  if (!root) return;
  var list = root.querySelector('[data-pp-list]');
  var none = root.querySelector('[data-pp-none]');
  var max = parseInt(root.dataset.max, 10) || 12;
  var items = function () { return Array.prototype.slice.call(list.children); };

  function sync() {
    var cur = items();
    var ids = cur.map(function (li) { return li.dataset.id; });
    Array.prototype.forEach.call(root.querySelectorAll('[data-pp-add]'), function (b) {
      var on = ids.indexOf(b.dataset.ppAdd) >= 0;
      b.classList.toggle('picked', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    cur.forEach(function (li, n) {
      li.querySelector('.pp-n').textContent = n + 1;
      li.tabIndex = 0;
      var im = li.querySelector('img');
      if (im) im.draggable = false; // gegriffen wird die Kachel, nicht das Bild
    });
    none.hidden = cur.length > 0;
  }

  function add(id, thumb) {
    var li = document.createElement('li');
    li.className = 'pp-item'; li.dataset.id = id;
    var h = document.createElement('input'); h.type = 'hidden'; h.name = 'portraits[]'; h.value = id;
    var n = document.createElement('span'); n.className = 'pp-n';
    var im = document.createElement('img'); im.src = thumb; im.alt = '';
    [h, n, im].forEach(function (x) { li.appendChild(x); });
    list.appendChild(li);
  }

  // Auswahl: Klick auf ein Bild im Raster fügt hinzu bzw. entfernt es wieder
  root.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-pp-add]');
    if (!b) return;
    var id = b.dataset.ppAdd;
    var li = list.querySelector('li[data-id="' + id + '"]');
    if (li) li.remove();
    else if (items().length < max) add(id, b.dataset.thumb);
    sync();
  });

  // Sortieren per Zeigergerät (Maus, Touch, Stift)
  var drag = null, timer = 0, sx = 0, sy = 0;
  function endDrag() {
    clearTimeout(timer);
    if (drag && drag.on) drag.el.classList.remove('dragging');
    drag = null;
    sync();
  }
  list.addEventListener('pointerdown', function (ev) {
    var li = ev.target.closest('li');
    if (!li || li.parentNode !== list || (ev.pointerType === 'mouse' && ev.button !== 0)) return;
    drag = { el: li, id: ev.pointerId, mouse: ev.pointerType === 'mouse', on: false };
    sx = ev.clientX; sy = ev.clientY;
    if (!drag.mouse) {
      var d = drag;
      timer = setTimeout(function () {
        d.on = true; li.classList.add('dragging');
        if (navigator.vibrate) navigator.vibrate(15);
      }, 350);
    }
  });
  list.addEventListener('pointermove', function (ev) {
    if (!drag || ev.pointerId !== drag.id) return;
    if (!drag.on) {
      var moved = Math.abs(ev.clientX - sx) > 6 || Math.abs(ev.clientY - sy) > 6;
      if (!moved) return;
      if (!drag.mouse) { clearTimeout(timer); drag = null; return; } // vor dem langen Druck bewegt: Scrollen
      drag.on = true; drag.el.classList.add('dragging');
    }
    var t = document.elementFromPoint(ev.clientX, ev.clientY);
    t = t && t.closest('li');
    if (!t || t === drag.el || t.parentNode !== list) return;
    var r = t.getBoundingClientRect();
    list.insertBefore(drag.el, ev.clientX > r.left + r.width / 2 ? t.nextSibling : t);
  });
  ['pointerup', 'pointercancel'].forEach(function (n) {
    list.addEventListener(n, function (ev) { if (drag && ev.pointerId === drag.id) endDrag(); });
  });
  // gegriffen: Seite/Liste nicht mehr scrollen; kein Kontextmenü durch langen Druck
  list.addEventListener('touchmove', function (ev) { if (drag && drag.on) ev.preventDefault(); }, { passive: false });
  list.addEventListener('contextmenu', function (ev) { if (drag) ev.preventDefault(); });

  // Tastatur: fokussierte Kachel mit Pfeil links/rechts verschieben
  list.addEventListener('keydown', function (ev) {
    var li = ev.target.closest('li');
    if (!li || (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight')) return;
    ev.preventDefault();
    if (ev.key === 'ArrowLeft' && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
    if (ev.key === 'ArrowRight' && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
    sync();
    li.focus();
  });

  sync();
})();
