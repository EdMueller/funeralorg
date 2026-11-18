// Gedenkseite bearbeiten: Portraitbilder wählen und Reihenfolge ändern (Pfeiltasten-Knöpfe, auch für Touch/Tastatur)
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
    cur.forEach(function (li) {
      li.draggable = true;
      var im = li.querySelector('img');
      if (im) im.draggable = false; // gezogen wird der Listeneintrag, nicht das Bild
    });
    var ids = cur.map(function (li) { return li.dataset.id; });
    Array.prototype.forEach.call(root.querySelectorAll('[data-pp-add]'), function (b) {
      var on = ids.indexOf(b.dataset.ppAdd) >= 0;
      b.classList.toggle('picked', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    cur.forEach(function (li, n) {
      li.querySelector('.pp-n').textContent = n + 1;
      li.querySelector('[data-act="up"]').disabled = n === 0;
      li.querySelector('[data-act="down"]').disabled = n === cur.length - 1;
    });
    none.hidden = cur.length > 0;
  }

  function btn(act, cls, icon, label) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'btn btn-sm ' + cls; b.dataset.act = act; b.setAttribute('aria-label', label);
    b.innerHTML = '<i class="bi bi-' + icon + '"></i>';
    return b;
  }

  function add(id, thumb) {
    var li = document.createElement('li');
    li.className = 'pp-item'; li.dataset.id = id;
    var h = document.createElement('input'); h.type = 'hidden'; h.name = 'portraits[]'; h.value = id;
    var n = document.createElement('span'); n.className = 'pp-n';
    var im = document.createElement('img'); im.src = thumb; im.alt = '';
    var bs = document.createElement('span'); bs.className = 'pp-btns';
    bs.appendChild(btn('up', 'btn-outline-secondary', 'arrow-left', root.dataset.lUp));
    bs.appendChild(btn('down', 'btn-outline-secondary', 'arrow-right', root.dataset.lDown));
    bs.appendChild(btn('remove', 'btn-outline-danger', 'x-lg', root.dataset.lRemove));
    [h, n, im, bs].forEach(function (x) { li.appendChild(x); });
    list.appendChild(li);
  }

  list.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-act]');
    if (!b) return;
    var li = b.closest('li');
    if (b.dataset.act === 'up' && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
    if (b.dataset.act === 'down' && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
    if (b.dataset.act === 'remove') li.remove();
    sync();
    var again = list.querySelector('li[data-id="' + li.dataset.id + '"] [data-act="' + b.dataset.act + '"]');
    if (again && !again.disabled) again.focus();
  });

  // Ziehen mit der Maus (Touch/Tastatur: Pfeil-Knöpfe)
  var dragEl = null;
  list.addEventListener('dragstart', function (ev) {
    var li = ev.target.closest && ev.target.closest('li');
    if (!li || li.parentNode !== list) return;
    dragEl = li;
    ev.dataTransfer.effectAllowed = 'move';
    ev.dataTransfer.setData('text/plain', li.dataset.id);
    setTimeout(function () { li.classList.add('dragging'); }, 0);
  });
  list.addEventListener('dragover', function (ev) {
    if (!dragEl) return;
    ev.preventDefault();
    var t = ev.target.closest('li');
    if (!t || t === dragEl || t.parentNode !== list) return;
    var r = t.getBoundingClientRect();
    list.insertBefore(dragEl, ev.clientX > r.left + r.width / 2 ? t.nextSibling : t);
  });
  list.addEventListener('drop', function (ev) { if (dragEl) ev.preventDefault(); });
  list.addEventListener('dragend', function () {
    if (!dragEl) return;
    dragEl.classList.remove('dragging');
    dragEl = null;
    sync();
  });

  root.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-pp-add]');
    if (!b) return;
    var id = b.dataset.ppAdd;
    var li = list.querySelector('li[data-id="' + id + '"]');
    if (li) li.remove();
    else if (items().length < max) add(id, b.dataset.thumb);
    sync();
  });

  sync();
})();
