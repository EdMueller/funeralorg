/* Gedenkseite – Client-Logik (Vanilla JS, keine Abhängigkeiten außer Bootstrap/Chart.js) */
(function () {
  'use strict';

  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  /** POST per fetch, liefert JSON. */
  /* ---------- Texte (Sprachdatei-Auszug aus dem Layout: <script id="i18n">) ---------- */
  var I18N = {};
  try { I18N = JSON.parse((document.getElementById('i18n') || {}).textContent || '{}'); } catch (e) { I18N = {}; }
  var LANG = I18N._lang || document.documentElement.lang || 'de';
  /** Text zum Schlüssel; Plural (de/en) über n, {n} und weitere Platzhalter als {name}. */
  function tr(key, params, n) {
    var v = I18N[key];
    if (v === undefined) return key;
    if (v && typeof v === 'object') v = (n === 1 ? v.one : v.other) || v.other;
    params = params || {};
    if (n !== undefined && params.n === undefined) params.n = n;
    return String(v).replace(/\{(\w+)\}/g, function (m, k) { return params[k] !== undefined ? params[k] : m; });
  }

  function send(url, data) {
    var fd = data instanceof FormData ? data : new FormData();
    if (!(data instanceof FormData)) Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    fd.set('_csrf', csrf);
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'X-CSRF-Token': csrf } })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false, error: tr('err_response', { status: r.status }) }; })
          .then(function (j) { if (!r.ok || j.ok === false) throw new Error(j.error || tr('err_status', { status: r.status })); return j; });
      });
  }
  function fail(e) { window.alert(e.message || tr('save_failed')); }
  function u(r, a) { return 'index.php?r=' + r + (a ? '&a=' + a : ''); }

  /* ---------- Bestätigungen ---------- */
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    var btn = ev.submitter;
    var msg = (btn && btn.dataset.confirmClick) || f.dataset.confirm;
    if (!msg && f.dataset.confirmCount) {
      var n = document.getElementById('cartCount');
      var cnt = n ? parseInt(n.textContent, 10) || 0 : 0;
      msg = tr(f.dataset.confirmCount, {}, cnt);
    }
    if (msg && !window.confirm(msg)) ev.preventDefault();
  });

  /* ---------- Filter automatisch absenden ---------- */
  $$('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });

  /* ---------- Clientseitige Suche ---------- */
  $$('[data-search]').forEach(function (input) {
    var sel = input.dataset.search;
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      $$(sel).forEach(function (el) {
        var t = el.dataset.text || el.textContent.toLowerCase();
        el.hidden = q !== '' && t.indexOf(q) === -1;
      });
    });
  });

  /* ---------- Sortierbare Tabellen ---------- */
  $$('table[data-sortable]').forEach(function (table) {
    var ths = $$('thead th', table);
    var coll = new Intl.Collator(LANG, { numeric: true, sensitivity: 'base' });
    ths.forEach(function (th, i) {
      th.classList.add('th-sort');
      th.tabIndex = 0;
      th.setAttribute('role', 'button');
      var sort = function () {
        var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
        ths.forEach(function (o) { o.removeAttribute('aria-sort'); });
        th.setAttribute('aria-sort', dir);
        var body = table.tBodies[0];
        var rows = $$('tr', body).filter(function (r) { return r.cells.length === ths.length; });
        var val = function (r) { var c = r.cells[i]; return c.dataset.v !== undefined ? parseFloat(c.dataset.v) : c.textContent.trim(); };
        rows.sort(function (a, b) {
          var x = val(a), y = val(b);
          var d = typeof x === 'number' && typeof y === 'number' ? x - y : coll.compare(String(x), String(y));
          return dir === 'ascending' ? d : -d;
        });
        rows.forEach(function (r) { body.appendChild(r); });
      };
      th.addEventListener('click', sort);
      th.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); sort(); } });
    });
  });

  /* ---------- Zwischenablage ---------- */
  $$('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var txt = b.dataset.copy;
      var done = function () { var o = b.innerHTML; b.textContent = tr('copied'); setTimeout(function () { b.innerHTML = o; }, 1500); };
      if (navigator.clipboard) navigator.clipboard.writeText(txt).then(done, function () { window.prompt(tr('copy_prompt'), txt); });
      else window.prompt(tr('copy_prompt'), txt);
    });
  });

  /* ---------- Checklisten ---------- */
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-toggle-task]');
    if (!b || b.disabled) return;
    var row = b.closest('.task');
    b.disabled = true;
    send(u('checklist', 'toggle'), { id: b.dataset.toggleTask }).then(function (j) {
      row.classList.toggle('done', j.done);
      b.setAttribute('aria-pressed', j.done ? 'true' : 'false');
      var info = $('.done-info', row);
      if (info) info.textContent = j.done ? tr('done_info', { by: j.by, at: j.at }) : '';
      var cat = $('[data-cat-id="' + j.cat + '"] .cat-progress');
      if (cat) cat.textContent = j.cat_progress;
      var par = j.parent && $('.task[data-task-id="' + j.parent + '"] .sub-count');
      if (par) par.textContent = j.parent_progress;
      setLead(j.lead);
    }).catch(fail).finally(function () { b.disabled = false; });
  });

  /* ---------- Unteraufgaben aufklappen (Zustand pro Browser) ---------- */
  var subKey = 'funeralorg.subtasks.open';
  var subOpen = [];
  try { subOpen = JSON.parse(localStorage.getItem(subKey) || '[]'); } catch (e) { subOpen = []; }
  function setSub(btn, open) {
    var box = document.getElementById(btn.dataset.subToggle);
    if (!box) return;
    box.classList.toggle('d-none', !open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  function restoreSubs(scope) {
    $$('[data-sub-toggle]', scope).forEach(function (b) {
      var id = b.dataset.subToggle.replace(/^sub-/, '');
      if (b.getAttribute('aria-expanded') !== 'true' && subOpen.indexOf(id) >= 0) setSub(b, true);
    });
  }
  restoreSubs(document);
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-sub-toggle]');
    if (!b) return;
    var id = b.dataset.subToggle.replace(/^sub-/, '');
    var open = b.getAttribute('aria-expanded') !== 'true';
    setSub(b, open);
    subOpen = subOpen.filter(function (x) { return x !== id; });
    if (open) subOpen.push(id);
    try { localStorage.setItem(subKey, JSON.stringify(subOpen)); } catch (e) { /* ohne Speicher */ }
  });

  /* ---------- Checkliste ohne Seitenreload: Formulare mit data-cl-form ---------- */
  function setLead(text) {
    var el = $('[data-cl-lead]');
    if (el && text) el.textContent = text;
  }
  function clAlert(msg) {
    $$('[data-cl-alert]').forEach(function (a) { a.remove(); });
    if (!msg) return;
    var box = document.createElement('div');
    box.className = 'alert alert-success alert-dismissible fade show';
    box.setAttribute('role', 'status');
    box.setAttribute('data-cl-alert', '');
    box.appendChild(document.createTextNode(msg));
    var x = document.createElement('button');
    x.type = 'button'; x.className = 'btn-close';
    x.setAttribute('data-bs-dismiss', 'alert'); x.setAttribute('aria-label', tr('close'));
    box.appendChild(x);
    var main = $('main');
    main.insertBefore(box, main.firstChild);
  }
  /** Kategorieblöcke ersetzen/einfügen/entfernen, Reihenfolge, Auswahlliste und Zähler angleichen. */
  function applyChecklist(j) {
    var root = $('#clRoot');
    if (!root) return;
    (j.cats || []).forEach(function (c) {
      var old = $('[data-cat-id="' + c.id + '"]', root);
      if (!c.html.trim()) { if (old) old.remove(); return; }
      var tpl = document.createElement('template');
      tpl.innerHTML = c.html.trim();
      var fresh = tpl.content.firstElementChild;
      if (old) old.replaceWith(fresh); else root.appendChild(fresh);
      restoreSubs(fresh);
    });
    (j.order || []).forEach(function (id) {
      var el = $('[data-cat-id="' + id + '"]', root);
      if (el) root.appendChild(el);
    });
    var sel = $('#t-cat');
    if (sel && j.options) {
      var cur = sel.value;
      sel.textContent = '';
      j.options.forEach(function (o) {
        var opt = document.createElement('option');
        opt.value = o.id; opt.textContent = o.name;
        sel.appendChild(opt);
      });
      sel.value = cur;
    }
    var empty = $('[data-cl-empty]');
    if (empty) empty.hidden = !j.empty;
    setLead(j.lead);
    clAlert(j.msg);
  }
  document.addEventListener('submit', function (ev) {
    var f = ev.target.closest('form[data-cl-form]');
    if (!f || ev.defaultPrevented) return;
    ev.preventDefault();
    var root = $('#clRoot');
    var fd = new FormData(f);
    if (root) fd.set('show', root.dataset.show || 'all');
    var btns = $$('button', f);
    btns.forEach(function (b) { b.disabled = true; });
    send((ev.submitter && ev.submitter.getAttribute('formaction')) || f.action, fd).then(function (j) {
      var modal = f.closest('.modal');
      if (modal && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).hide();
      applyChecklist(j);
    }).catch(fail).finally(function () { btns.forEach(function (b) { b.disabled = false; }); });
  });

  /* ---------- Checkliste: Reihenfolge und Zuordnung per Ziehen oder Pfeile ---------- */
  var clRoot = $('#clRoot');
  if (clRoot) {
    var clDrag = null;
    /** IDs der Aufgabenzeilen einer Liste (Hauptaufgaben einer Kategorie bzw. Unteraufgaben einer Hauptaufgabe). */
    var clIds = function (cat, parent) {
      var box = parent ? document.getElementById('sub-' + parent) : $('.cat-block[data-cat-id="' + cat + '"]', clRoot);
      if (!box) return [];
      return $$(':scope > .task', box).map(function (r) { return r.dataset.taskId; });
    };
    var clPost = function (cat, parent, ids, id, then) {
      var fd = new FormData();
      fd.append('id', id); fd.append('cat', cat); fd.append('parent', parent);
      fd.append('show', clRoot.dataset.show || 'all');
      ids.forEach(function (x) { fd.append('ids[]', x); });
      send(u('checklist', 'move'), fd).then(function (j) { applyChecklist(j); if (then) then(); }).catch(fail);
    };
    var clMark = function (el, cls) {
      $$('.drop-before, .drop-after, .drop-into, .drop-end', clRoot).forEach(function (x) {
        x.classList.remove('drop-before', 'drop-after', 'drop-into', 'drop-end');
      });
      if (el) el.classList.add(cls);
    };
    /** Ziel unter dem Zeiger: { cat, parent, ids, el, cls } oder null, wenn dort nicht abgelegt werden darf. */
    var clTarget = function (ev) {
      var row = ev.target.closest('.task');
      var block = ev.target.closest('.cat-block');
      if (!block) return null;
      var cat = block.dataset.catId;
      if (!row) {
        return { cat: cat, parent: '', ids: clIds(cat, '').filter(function (x) { return x !== clDrag.id; }).concat(clDrag.id), el: block, cls: 'drop-end' };
      }
      var rid = row.dataset.taskId;
      var box = row.closest('.subtasks');
      if (rid === clDrag.id || (box && box.dataset.subId === clDrag.id)) return null;
      var r = row.getBoundingClientRect();
      var y = (ev.clientY - r.top) / r.height;
      var parent = box ? box.dataset.subId : '';
      var mode = y < 0.5 ? 'before' : 'after';
      if (!box && !clDrag.hasSubs && y >= 0.3 && y <= 0.7) mode = 'into';
      if (mode === 'into') {
        return { cat: cat, parent: rid, ids: clIds(cat, rid).filter(function (x) { return x !== clDrag.id; }).concat(clDrag.id), el: row, cls: 'drop-into', into: rid };
      }
      var ids = clIds(cat, parent).filter(function (x) { return x !== clDrag.id; });
      ids.splice(ids.indexOf(rid) + (mode === 'after' ? 1 : 0), 0, clDrag.id);
      return { cat: cat, parent: parent, ids: ids, el: row, cls: mode === 'after' ? 'drop-after' : 'drop-before' };
    };
    clRoot.addEventListener('dragstart', function (ev) {
      var h = ev.target.closest && ev.target.closest('[data-drag-task]');
      if (!h) return;
      var row = h.closest('.task');
      var edit = $('[data-subs]', row);
      clDrag = { id: row.dataset.taskId, row: row, isSub: row.classList.contains('subtask'), hasSubs: !!edit && +edit.dataset.subs > 0, t: null };
      ev.dataTransfer.effectAllowed = 'move';
      ev.dataTransfer.setData('text/plain', clDrag.id);
      ev.dataTransfer.setDragImage(row, 20, 20);
      setTimeout(function () { row.classList.add('dragging'); }, 0);
    });
    clRoot.addEventListener('dragover', function (ev) {
      if (!clDrag) return;
      clDrag.t = clTarget(ev);
      if (!clDrag.t) { clMark(null); return; }
      ev.preventDefault();
      ev.dataTransfer.dropEffect = 'move';
      clMark(clDrag.t.el, clDrag.t.cls);
    });
    clRoot.addEventListener('drop', function (ev) {
      if (!clDrag || !clDrag.t) return;
      ev.preventDefault();
      var t = clDrag.t;
      clMark(null);
      clPost(t.cat, t.parent, t.ids, clDrag.id, function () {
        if (t.into && subOpen.indexOf(t.into) < 0) { // Ziel aufklappen, damit die Aufgabe sichtbar bleibt
          subOpen.push(t.into);
          try { localStorage.setItem(subKey, JSON.stringify(subOpen)); } catch (e) { /* ohne Speicher */ }
          var b = $('[data-sub-toggle="sub-' + t.into + '"]', clRoot);
          if (b) setSub(b, true);
        }
      });
    });
    clRoot.addEventListener('dragend', function () {
      if (!clDrag) return;
      clDrag.row.classList.remove('dragging');
      clMark(null);
      clDrag = null;
    });
    // Pfeile: eine Position innerhalb der eigenen Liste
    clRoot.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-task-move]');
      if (!b) return;
      var row = b.closest('.task');
      var id = row.dataset.taskId;
      var box = row.closest('.subtasks');
      var parent = box ? box.dataset.subId : '';
      var cat = row.closest('.cat-block').dataset.catId;
      var ids = clIds(cat, parent);
      var i = ids.indexOf(id);
      var j = i + (+b.dataset.taskMove);
      if (i < 0 || j < 0 || j >= ids.length) return;
      ids.splice(i, 1);
      ids.splice(j, 0, id);
      var dir = b.dataset.taskMove;
      clPost(cat, parent, ids, id, function () {
        var again = $('.task[data-task-id="' + id + '"] [data-task-move="' + dir + '"]', clRoot);
        if (again) again.focus();
      });
    });
  }

  /* ---------- Ja/Nein-Umschalter (Kontakte, Kondolenzen) ---------- */
  var condFields = ['card', 'flowers', 'wreath', 'donation', 'thanks'];
  $$('[data-flag]').forEach(function (b) {
    b.addEventListener('click', function () {
      var field = b.dataset.flag;
      var route = condFields.indexOf(field) >= 0 ? 'condolences' : 'contacts';
      b.disabled = true;
      send(u(route, 'toggle'), { id: b.dataset.id, field: field }).then(function (j) {
        b.classList.toggle('on', j.value);
        b.setAttribute('aria-pressed', j.value ? 'true' : 'false');
        var i = $('i', b);
        if (i) i.className = 'bi ' + (j.value ? 'bi-check-circle-fill' : 'bi-circle');
        b.dispatchEvent(new CustomEvent('flagtoggled', { bubbles: true, detail: { id: b.dataset.id, field: field } }));
      }).catch(fail).finally(function () { b.disabled = false; });
    });
  });

  /* ---------- Zu- und Absagen ---------- */
  $$('form[data-rsvp]').forEach(function (form) {
    var timer;
    function save() {
      send(form.action, new FormData(form)).then(function (j) {
        form.elements.adults.value = j.adults;
        form.elements.children.value = j.children;
        var s = j.sums;
        if (s) {
          var set = function (k, v) { var el = document.querySelector('[data-sum="' + k + '"]'); if (el) el.textContent = v; };
          set('yes', s.yes); set('no', s.no); set('open', s.open); set('adults', s.adults);
          set('children', s.children); set('persons', s.adults + s.children);
        }
      }).catch(fail);
    }
    $$('.rsvp-seg button', form).forEach(function (btn) {
      btn.addEventListener('click', function () {
        form.elements.rsvp.value = btn.dataset.v;
        $$('.rsvp-seg button', form).forEach(function (x) { x.classList.toggle('on', x === btn); });
        if (btn.dataset.v === 'no') { form.elements.adults.value = 0; form.elements.children.value = 0; }
        save();
      });
    });
    ['adults', 'children'].forEach(function (n) {
      form.elements[n].addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(save, 600); });
    });
    form.addEventListener('submit', function (ev) { ev.preventDefault(); save(); });
  });

  /* ---------- Modals aus data-Attributen befüllen ---------- */
  function fillModal(modalId, attr, fn) {
    var m = document.getElementById(modalId);
    if (!m) return;
    m.addEventListener('show.bs.modal', function (ev) {
      var t = ev.relatedTarget;
      if (!t || !t.dataset[attr]) return;
      var d = {};
      try { d = JSON.parse(t.dataset[attr]); } catch (e) { d = {}; }
      var f = $('form', m);
      f.reset();
      Array.prototype.forEach.call(f.elements, function (el) {
        if (!el.name || el.name.charAt(0) === '_') return;
        if (el.type === 'checkbox') el.checked = !!d[el.name];
        else if (d[el.name] !== undefined && d[el.name] !== null) el.value = d[el.name];
        else if (el.tagName !== 'SELECT') el.value = '';
      });
      if (fn) fn(m, d);
    });
    m.addEventListener('shown.bs.modal', function () {
      var first = $('input:not([type=hidden]):not([type=checkbox]), select', m);
      if (first) first.focus();
    });
  }
  fillModal('taskModal', 'task', function (m, d) {
    var del = $('[data-delete-task]', m);
    if (del) del.hidden = !d.id;
    var sub = !!d.parent;
    var cat = $('[data-task-cat]', m);
    if (cat) cat.hidden = sub;
    $('.modal-title', m).textContent = d.id ? tr(sub ? 'task_edit_sub' : 'task_edit') : tr(sub ? 'task_new_sub' : 'task_new');
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-bs-target="#taskModal"][data-subs]');
    var del = $('#taskModal [data-delete-task]');
    if (b && del) {
      if (+b.dataset.subs) del.dataset.confirmClick = tr('task_delete_subs', {}, +b.dataset.subs);
      else del.dataset.confirmClick = tr('task_delete');
    }
  });
  fillModal('catModal', 'cat', function (m, d) {
    $('.modal-title', m).textContent = tr(d.id ? 'cat_rename' : 'cat_new');
  });
  fillModal('userModal', 'user', function (m, d) {
    $('[name=password]', m).required = !d.id;
    $('.modal-title', m).textContent = tr(d.id ? 'user_edit' : 'user_new');
    $('[name=active]', m).checked = d.active !== false;
    if (!d.id) $('[name=role]', m).value = 'user';
  });
  fillModal('albumModal', 'album', function (m, d) {
    var del = $('[data-album-delete]', m);
    if (del) del.hidden = !d.id;
    var ord = $('[data-album-order]', m);
    if (ord) ord.hidden = !d.id;
    $('.modal-title', m).textContent = d.id ? tr('album_edit') : tr(d.parent ? 'album_new_sub' : 'album_new');
    // eigene Nachfahren (und das Album selbst) sind als Eltern/Verweis gesperrt – Schleifenschutz
    var ex = d.exclude || [];
    $$('#al-parent option', m).forEach(function (o) { o.disabled = ex.indexOf(o.value) >= 0; });
    $$('#al-links input[type=checkbox]', m).forEach(function (c) {
      c.checked = (d.links || []).indexOf(c.value) >= 0;
      c.disabled = ex.indexOf(c.value) >= 0;
    });
  });

  /* ---------- Galerie: Reihenfolge ändern (Ziehen, Touch: langer Druck) ---------- */
  // root = Container, sel = Elemente mit data-id, btn = Umschalter, save(ids) = Speichern der neuen Reihenfolge
  function makeSortable(root, sel, btn, save, touchAfter) {
    var dragEl = null, before = '';
    var order = function () { return $$(sel, root).map(function (i) { return i.dataset.id; }); };
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      if (root.classList.contains('sorting')) { window.location.reload(); return; } // Lightbox-Indizes bzw. Links neu aufbauen
      root.classList.add('sorting');
      btn.classList.add('active');
      $('[data-sort-label]', btn).textContent = btn.dataset.done;
      $$(sel, root).forEach(function (it) { it.draggable = true; });
    });
    // Touch/Stift: langer Druck greift das Element, danach folgt es dem Finger (HTML5-Drag gilt nur für die Maus)
    var touchEl = null, touchId = null, pressTimer = 0, startX = 0, startY = 0, touchType = false;
    var touchEnd = function (doSave) {
      clearTimeout(pressTimer);
      if (touchEl) {
        touchEl.classList.remove('dragging');
        if (doSave && order().join() !== before) save(order());
      }
      touchEl = null; touchId = null;
    };
    root.addEventListener('pointerdown', function (ev) {
      touchType = ev.pointerType !== 'mouse';
      if (!touchType || !root.classList.contains('sorting') || ev.target.closest('[data-move]')) return;
      var it = ev.target.closest(sel);
      if (!it) return;
      startX = ev.clientX; startY = ev.clientY; touchId = ev.pointerId;
      clearTimeout(pressTimer);
      pressTimer = setTimeout(function () {
        touchEl = it; before = order().join();
        it.classList.add('dragging');
        if (navigator.vibrate) navigator.vibrate(15);
      }, 350);
    });
    root.addEventListener('pointermove', function (ev) {
      if (ev.pointerId !== touchId) return;
      if (!touchEl) {
        // vor dem langen Druck bewegt: Scrollen, kein Greifen
        if (Math.abs(ev.clientX - startX) > 8 || Math.abs(ev.clientY - startY) > 8) { clearTimeout(pressTimer); touchId = null; }
        return;
      }
      var t = document.elementFromPoint(ev.clientX, ev.clientY);
      t = t && t.closest(sel);
      if (!t || t === touchEl || t.parentNode !== root) return;
      root.insertBefore(touchEl, touchAfter(ev, t.getBoundingClientRect()) ? t.nextSibling : t);
    });
    ['pointerup', 'pointercancel'].forEach(function (n) {
      root.addEventListener(n, function (ev) { if (ev.pointerId === touchId) touchEnd(n === 'pointerup'); });
    });
    // gegriffen: Seite nicht mehr scrollen
    root.addEventListener('touchmove', function (ev) { if (touchEl) ev.preventDefault(); }, { passive: false });
    root.addEventListener('contextmenu', function (ev) { if (touchType && root.classList.contains('sorting')) ev.preventDefault(); });
    root.addEventListener('dragstart', function (ev) {
      var it = ev.target.closest && ev.target.closest(sel);
      if (!it || !root.classList.contains('sorting')) return;
      if (touchType) { ev.preventDefault(); return; }
      dragEl = it; before = order().join();
      ev.dataTransfer.effectAllowed = 'move';
      ev.dataTransfer.setData('text/plain', it.dataset.id);
      setTimeout(function () { it.classList.add('dragging'); }, 0);
    });
    root.addEventListener('dragover', function (ev) {
      if (!dragEl) return;
      ev.preventDefault();
      var t = ev.target.closest(sel);
      if (!t || t === dragEl || t.parentNode !== root) return;
      var r = t.getBoundingClientRect();
      root.insertBefore(dragEl, ev.clientX > r.left + r.width / 2 ? t.nextSibling : t);
    });
    root.addEventListener('drop', function (ev) { if (dragEl) ev.preventDefault(); });
    root.addEventListener('dragend', function () {
      if (!dragEl) return;
      dragEl.classList.remove('dragging');
      dragEl = null;
      if (order().join() !== before) save(order());
    });
    return order;
  }

  var sortGrid = document.getElementById('galleryGrid');
  var sortBtn = $('[data-sort-toggle]');
  if (sortGrid && sortBtn) {
    var gridSave = function (ids) {
      var fd = new FormData();
      fd.append('album', sortGrid.dataset.orderAlbum);
      ids.forEach(function (id) { fd.append('ids[]', id); });
      send(u('gallery', 'imgorder'), fd).catch(fail);
    };
    makeSortable(sortGrid, '.g-item', sortBtn, gridSave, function (ev, r) {
      return ev.clientY > r.bottom - r.height / 2 && ev.clientX > r.left + r.width / 2;
    });
    // im Sortiermodus weder Leuchtkasten öffnen noch Bild auswählen; Pfeile verschieben um eine Stelle
    sortGrid.addEventListener('click', function (ev) {
      if (!sortGrid.classList.contains('sorting')) return;
      var mv = ev.target.closest('[data-move]');
      if (mv) {
        var it = mv.closest('.g-item');
        var dir = +mv.dataset.move;
        var other = dir < 0 ? it.previousElementSibling : it.nextElementSibling;
        if (other) {
          sortGrid.insertBefore(dir < 0 ? it : other, dir < 0 ? other : it);
          gridSave($$('.g-item', sortGrid).map(function (i) { return i.dataset.id; }));
          mv.focus();
        }
      }
      if (ev.target.closest('.open, .pick') || mv) { ev.stopPropagation(); ev.preventDefault(); }
    }, true);
  }

  // Album-Leiste (Admin): Reihenfolge der Alben unter dem aktuellen Elternalbum
  var albBar = $('[data-album-bar]');
  var albBtn = $('[data-albsort-toggle]');
  if (albBar && albBtn) {
    var albSel = 'a[data-id]';
    var albSave = function (ids) {
      var fd = new FormData();
      fd.append('parent', albBar.dataset.orderParent);
      ids.forEach(function (id) { fd.append('ids[]', id); });
      send(u('gallery', 'albumorder'), fd).catch(fail);
    };
    makeSortable(albBar, albSel, albBtn, albSave, function (ev, r) { return ev.clientX > r.left + r.width / 2; });
    // im Sortiermodus nicht navigieren
    albBar.addEventListener('click', function (ev) {
      if (albBar.classList.contains('sorting') && ev.target.closest(albSel)) { ev.preventDefault(); ev.stopPropagation(); }
    }, true);
    // Tastatur: fokussiertes Album mit Pfeil links/rechts verschieben
    albBar.addEventListener('keydown', function (ev) {
      if (!albBar.classList.contains('sorting') || (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight')) return;
      var it = ev.target.closest && ev.target.closest(albSel);
      if (!it) return;
      var left = ev.key === 'ArrowLeft';
      var other = left ? it.previousElementSibling : it.nextElementSibling;
      while (other && !other.matches(albSel)) other = left ? other.previousElementSibling : other.nextElementSibling;
      if (!other) return;
      ev.preventDefault();
      albBar.insertBefore(left ? it : other, left ? other : it);
      it.focus();
      albSave($$(albSel, albBar).map(function (i) { return i.dataset.id; }));
    });
  }

  /* ---------- Galerie: Auswahl ---------- */
  var cartCount = document.getElementById('cartCount');
  var cartBar = document.getElementById('cartBar');
  function setCount(n) {
    if (cartCount) cartCount.textContent = n;
    if (cartBar) cartBar.hidden = n === 0;
  }
  function pick(id, mode) {
    return send(u('gallery', 'select'), { id: id, mode: mode || 'toggle' }).then(function (j) {
      setCount(j.count);
      return j;
    });
  }
  function markPicked(id, on) {
    $$('.g-item[data-id="' + id + '"]').forEach(function (it) {
      it.classList.toggle('picked', on);
      var b = $('[data-pick]', it);
      if (b) b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }
  $$('[data-pick]').forEach(function (b) {
    b.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var id = b.dataset.pick;
      pick(id).then(function (j) {
        markPicked(id, j.selected);
        if (!j.selected && b.hasAttribute('data-remove-on-unpick')) b.closest('.g-item').remove();
      }).catch(fail);
    });
  });
  var selAll = $('[data-select-all]');
  if (selAll) selAll.addEventListener('click', function () {
    var items = $$('#galleryGrid .g-item:not(.picked)');
    var chain = Promise.resolve();
    items.forEach(function (it) {
      chain = chain.then(function () { return pick(it.dataset.id, 'add').then(function () { markPicked(it.dataset.id, true); }); });
    });
    chain.catch(fail);
  });

  /* ---------- Galerie: Leuchtkasten & Diashow ---------- */
  var dataEl = document.getElementById('galleryData');
  var lb = document.getElementById('lightbox');
  if (dataEl && lb) {
    var G = JSON.parse(dataEl.textContent || '{}');
    var items = G.items || [];
    var imgs = $$('#lbStage img');
    var stage = document.getElementById('lbStage');
    var frontAlpha = false;
    var front = 0, idx = 0, playTimer = null, lastFocus = null, seen = {};
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function isPicked(id) { var it = $('.g-item[data-id="' + id + '"]'); return it && it.classList.contains('picked'); }
    function preload(i) { if (items[i]) { var im = new Image(); im.src = items[i].src; } }

    function show(i) {
      if (!items.length) return;
      idx = (i + items.length) % items.length;
      var it = items[idx];
      var next = imgs[1 - front];
      var cut = !!it.alpha || frontAlpha;
      next.onload = function () {
        // Transparente Bilder ohne Überblendung wechseln, sonst scheint das alte Bild durch
        stage.classList.toggle('cut', cut);
        frontAlpha = !!it.alpha;
        next.classList.add('on');
        imgs[front].classList.remove('on');
        front = 1 - front;
      };
      next.alt = it.caption || '';
      next.src = it.src;
      $('#lbCaption').textContent = it.caption || '';
      $('#lbCounter').textContent = (idx + 1) + ' / ' + items.length;
      var dl = $('#lbDl'); if (dl) dl.href = it.dl;
      var pb = $('#lbPick'); if (pb) pb.classList.toggle('on', !!isPicked(it.id));
      var eb = $('#lbEdit'); if (eb) eb.hidden = !it.edit;
      preload(idx + 1);
      if (!seen[it.id]) { seen[it.id] = 1; send(u('gallery', 'seen'), { id: it.id }).catch(function () {}); }
    }
    function open(i, play) {
      lastFocus = document.activeElement;
      lb.classList.add('open');
      document.body.style.overflow = 'hidden';
      lb.focus();
      show(i);
      if (play) startPlay();
    }
    function close() {
      stopPlay();
      lb.classList.remove('open');
      document.body.style.overflow = '';
      if (document.fullscreenElement) document.exitFullscreen().catch(function () {});
      if (lastFocus) lastFocus.focus();
    }
    function startPlay() {
      stopPlay();
      lb.classList.add('playing');
      $('#lbPlay').innerHTML = '<i class="bi bi-pause-fill"></i> ' + tr('pause');
      playTimer = setInterval(function () { show(idx + 1); }, reduce ? 8000 : 6000);
    }
    function stopPlay() {
      clearInterval(playTimer); playTimer = null;
      lb.classList.remove('playing');
      $('#lbPlay').innerHTML = '<i class="bi bi-play-fill"></i> ' + tr('slideshow');
    }

    $$('[data-open]').forEach(function (b) { b.addEventListener('click', function () { open(+b.dataset.open, false); }); });
    $$('[data-slideshow]').forEach(function (b) { b.addEventListener('click', function () { open(0, true); }); });
    $('#lbClose').addEventListener('click', close);
    $('#lbPrev').addEventListener('click', function () { stopPlay(); show(idx - 1); });
    $('#lbNext').addEventListener('click', function () { stopPlay(); show(idx + 1); });
    $('#lbPlay').addEventListener('click', function () { playTimer ? stopPlay() : startPlay(); });
    $('#lbFull').addEventListener('click', function () {
      if (document.fullscreenElement) document.exitFullscreen();
      else if (lb.requestFullscreen) lb.requestFullscreen().catch(function () {});
    });
    var pb = $('#lbPick');
    if (pb) pb.addEventListener('click', function () {
      var id = items[idx].id;
      pick(id).then(function (j) { markPicked(id, j.selected); pb.classList.toggle('on', j.selected); }).catch(fail);
    });
    var eb = $('#lbEdit');
    var imgModalEl = document.getElementById('imgModal');
    if (eb && imgModalEl) eb.addEventListener('click', function () {
      var it = items[idx];
      var f = $('form', imgModalEl);
      f.elements.id.value = it.id;
      f.elements.caption.value = it.caption || '';
      f.elements.album.value = it.album || '';
      $$('#im-links input[type=checkbox]', imgModalEl).forEach(function (c) {
        c.checked = (it.links || []).indexOf(c.value) >= 0;
      });
      if (f.elements.login) f.elements.login.checked = !!it.login;
      $('#im-meta').textContent = it.by ? (it.at ? tr('uploaded_by_at', { by: it.by, at: it.at }) : tr('uploaded_by', { by: it.by })) : '';
      stopPlay();
      if (document.fullscreenElement) document.exitFullscreen().catch(function () {});
      bootstrap.Modal.getOrCreateInstance(imgModalEl).show();
    });

    lb.addEventListener('keydown', function (ev) {
      if (document.querySelector('.modal.show')) return;
      if (ev.key === 'Escape') close();
      else if (ev.key === 'ArrowRight') { stopPlay(); show(idx + 1); }
      else if (ev.key === 'ArrowLeft') { stopPlay(); show(idx - 1); }
      else if (ev.key === ' ') { ev.preventDefault(); playTimer ? stopPlay() : startPlay(); }
    });

    // Wischgesten
    var sx = null, sy = null;
    var stage = document.getElementById('lbStage');
    stage.addEventListener('touchstart', function (ev) { sx = ev.touches[0].clientX; sy = ev.touches[0].clientY; }, { passive: true });
    stage.addEventListener('touchend', function (ev) {
      if (sx === null) return;
      var dx = ev.changedTouches[0].clientX - sx, dy = ev.changedTouches[0].clientY - sy;
      if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) { stopPlay(); show(idx + (dx < 0 ? 1 : -1)); }
      else if (dy > 90 && Math.abs(dy) > Math.abs(dx)) close();
      sx = sy = null;
    });
    stage.addEventListener('click', function (ev) { if (ev.target === stage) close(); });

    // Direkt öffnen (?open=ID)
    if (G.open) {
      var oi = items.findIndex(function (x) { return x.id === G.open; });
      if (oi >= 0) open(oi, false);
    } else if (G.play && items.length) {
      open(0, true); // ?play=1 (aus „Stationen“): Diashow des Albums starten
    }
  }

  /* ---------- Galerie: Upload mit Fortschritt ---------- */
  var upForm = document.getElementById('uploadForm');
  if (upForm) {
    var fileInput = document.getElementById('uploadFiles');
    var bar = $('.progress', upForm), barIn = $('.progress-bar', upForm), msg = document.getElementById('uploadMsg');
    ['dragenter', 'dragover'].forEach(function (t) {
      upForm.addEventListener(t, function (ev) { ev.preventDefault(); upForm.classList.add('drag'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
      upForm.addEventListener(t, function (ev) { ev.preventDefault(); upForm.classList.remove('drag'); });
    });
    upForm.addEventListener('drop', function (ev) {
      if (ev.dataTransfer && ev.dataTransfer.files.length) { fileInput.files = ev.dataTransfer.files; upForm.requestSubmit(); }
    });
    upForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var files = Array.prototype.slice.call(fileInput.files);
      if (!files.length) { msg.textContent = tr('pick_files'); return; }
      // In Paketen hochladen, damit post_max_size nicht überschritten wird
      var batches = [], cur = [], size = 0, LIMIT = 60 * 1024 * 1024;
      files.forEach(function (f) {
        if (cur.length && (size + f.size > LIMIT || cur.length >= 10)) { batches.push(cur); cur = []; size = 0; }
        cur.push(f); size += f.size;
      });
      if (cur.length) batches.push(cur);
      var total = files.reduce(function (s, f) { return s + f.size; }, 0), sent = 0, ok = 0, errors = [];
      bar.classList.remove('d-none');
      msg.textContent = tr('uploading');
      var album = upForm.elements.album.value;

      function next(i) {
        if (i >= batches.length) {
          barIn.style.width = '100%';
          msg.textContent = tr('uploaded', {}, ok) + (errors.length ? tr('upload_errors', { list: errors.join('; ') }) : '');
          if (ok) setTimeout(function () { location.reload(); }, errors.length ? 4000 : 800);
          return;
        }
        var fd = new FormData();
        fd.append('_csrf', csrf); fd.append('album', album);
        batches[i].forEach(function (f) { fd.append('files[]', f, f.name); });
        var batchSize = batches[i].reduce(function (s, f) { return s + f.size; }, 0);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', upForm.action);
        xhr.setRequestHeader('X-Requested-With', 'fetch');
        xhr.upload.onprogress = function (e) {
          if (e.lengthComputable) barIn.style.width = Math.round((sent + e.loaded / e.total * batchSize) / total * 100) + '%';
        };
        xhr.onload = function () {
          var j = {};
          try { j = JSON.parse(xhr.responseText); } catch (e) { j = { errors: [tr('server_error', { status: xhr.status })] }; }
          ok += j.uploaded || 0;
          errors = errors.concat(j.errors || (j.error ? [j.error] : []));
          sent += batchSize;
          next(i + 1);
        };
        xhr.onerror = function () { errors.push(tr('conn_lost')); sent += batchSize; next(i + 1); };
        xhr.send(fd);
      }
      next(0);
    });
  }

  /* ---------- Kontakte: Ansicht merken ---------- */
  $$('[data-view]').forEach(function (a) {
    a.addEventListener('click', function () {
      var base = location.pathname.replace(/[^\/]*$/, '');
      document.cookie = 'gdk_cview=' + a.dataset.view + '; path=' + base + '; max-age=31536000; samesite=lax';
    });
  });

  /* ---------- Auswahl (IDs) an Formulare anhängen ---------- */
  var ctSelectedIds = function () { return []; };
  $$('form[data-ct-ids]').forEach(function (f) {
    f.addEventListener('formdata', function (ev) {
      ctSelectedIds().forEach(function (id) { ev.formData.append('ids[]', id); });
    });
  });
  var labelModal = document.getElementById('labelModal');
  if (labelModal) labelModal.addEventListener('show.bs.modal', function () {
    var p = $('[data-label-scope]', labelModal);
    if (!p) return;
    var n = ctSelectedIds().length;
    p.textContent = n ? tr('label_scope_sel', {}, n) : p.dataset.all;
  });

  var accessModal = document.getElementById('accessModal');
  if (accessModal) accessModal.addEventListener('show.bs.modal', function () {
    var p = $('[data-access-scope]', accessModal), n = ctSelectedIds().length;
    if (p) p.textContent = tr('access_scope', {}, n);
  });

  /* ---------- Kontakt-Tabelle ---------- */
  var ct = document.getElementById('ctable');
  if (ct) {
    var COLS = JSON.parse(document.getElementById('ctCols').textContent || '[]');
    var colByKey = {};
    COLS.forEach(function (c) { colByKey[c.key] = c; });
    var table = $('table', ct), tbody = $('tbody', table), headRow = $('.ct-head', table), filterRow = $('.ct-filters', table);
    var scroller = $('.ct-scroll', ct), bar = document.getElementById('ctActions');
    var collator = new Intl.Collator('de', { numeric: true, sensitivity: 'base' });
    var RSVP_LABEL = { '': '', open: tr('rsvp_open'), yes: tr('rsvp_yes'), no: tr('rsvp_no') };

    var store = {
      get: function (k, d) { try { var v = localStorage.getItem('gdk.ct.' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
      set: function (k, v) { try { localStorage.setItem('gdk.ct.' + k, JSON.stringify(v)); } catch (e) { /* privater Modus */ } }
    };
    var visible = {};
    var storedCols = store.get('cols', null);
    COLS.forEach(function (c) { visible[c.key] = Array.isArray(storedCols) ? storedCols.indexOf(c.key) >= 0 : c.on; });
    if (!COLS.some(function (c) { return visible[c.key]; })) COLS.forEach(function (c) { visible[c.key] = c.on; });
    var widths = store.get('w', {}) || {};
    var sort = { key: null, dir: 1 };
    var filters = {}, query = '';
    var undoStack = [], undoing = false;
    var rows = function () { return $$('tr', tbody); };
    var cell = function (tr, key) { return $('td[data-col="' + key + '"]', tr); };

    /* --- Spalten sichtbar / Breiten --- */
    function width(key) { return Math.max(50, +widths[key] || colByKey[key].w); }
    function applyCols() {
      var total = 40 + 48 + 40;
      COLS.forEach(function (c) {
        $$('[data-col="' + c.key + '"]', table).forEach(function (el) { el.hidden = !visible[c.key]; });
        if (visible[c.key]) {
          total += width(c.key);
          var th = $('th[data-col="' + c.key + '"]', headRow);
          if (th) th.style.width = width(c.key) + 'px';
          var fth = $('th[data-col="' + c.key + '"]', filterRow);
          if (fth) fth.style.width = width(c.key) + 'px';
        }
      });
      table.style.width = total + 'px';
      scroller.style.setProperty('--ct-head-h', headRow.offsetHeight + 'px');
    }

    /* --- Filtern, Sortieren, Nummerieren --- */
    function visibleRows() { return rows().filter(function (r) { return !r.hidden; }); }
    function applyFilter() {
      rows().forEach(function (tr) {
        var ok = query === '' || (tr.dataset.text || '').indexOf(query) >= 0;
        if (ok) {
          Object.keys(filters).forEach(function (k) {
            if (!ok || !visible[k] || filters[k] === '') return;
            var td = cell(tr, k), v = td ? td.dataset.v : '';
            if (colByKey[k].type === 'flag' || colByKey[k].type === 'rsvp') ok = filters[k] === '*' || v === filters[k];
            else ok = v.toLowerCase().indexOf(filters[k].toLowerCase()) >= 0;
          });
        }
        tr.hidden = !ok;
      });
      refresh();
    }
    function renumber() { visibleRows().forEach(function (tr, i) { $('.ct-num', tr).textContent = i + 1; }); }
    function applySort() {
      var key = sort.key;
      $$('th[data-col]', headRow).forEach(function (th) {
        var on = th.dataset.col === key;
        th.setAttribute('aria-sort', on ? (sort.dir === 1 ? 'ascending' : 'descending') : 'none');
        $('.ct-arrow', th).className = 'bi ct-arrow' + (on ? (sort.dir === 1 ? ' bi-caret-up-fill' : ' bi-caret-down-fill') : '');
      });
      if (!key) return;
      var num = colByKey[key].type === 'num';
      var list = rows().map(function (tr, i) {
        var td = cell(tr, key);
        return { tr: tr, i: i, s: td ? (td.dataset.s !== undefined ? td.dataset.s : td.dataset.v) : '' };
      });
      list.sort(function (a, b) {
        if (a.s === '' && b.s !== '') return 1;       // Leere immer ans Ende
        if (b.s === '' && a.s !== '') return -1;
        var c = num ? (parseFloat(a.s) - parseFloat(b.s)) : collator.compare(a.s, b.s);
        return (c * sort.dir) || (a.i - b.i);
      });
      list.forEach(function (x) { tbody.appendChild(x.tr); });
      renumber();
    }
    function refresh() {
      renumber();
      var all = rows(), vis = visibleRows();
      var yes = vis.filter(function (tr) { var c = cell(tr, 'rsvp'); return c ? c.dataset.v === 'yes' : tr.dataset.rsvp === 'yes'; });
      var persons = vis.reduce(function (s, tr) { return s + (tr.dataset.persons ? +tr.dataset.persons : 0); }, 0);
      var sel = selectedRows().length;
      var txt = tr('ct_summary', { shown: vis.length }, all.length);
      if (colByKey.rsvp) txt += tr('ct_rsvp_sum', { yes: yes.length }, persons);
      $('#ctSummary').textContent = txt;
      $('#ctCount').textContent = sel;
      bar.hidden = sel === 0;
      var del = $('#ctDelete');
      if (del) del.dataset.confirmClick = tr('ct_delete_confirm', {}, sel);
      var all2 = $('#ctAll');
      all2.checked = vis.length > 0 && sel === vis.length;
      all2.indeterminate = sel > 0 && sel < vis.length;
    }
    // Personen je Zeile für die Summenzeile vorbereiten
    rows().forEach(function (tr) { setPersons(tr, cell(tr, 'rsvp') ? cell(tr, 'rsvp').dataset.v : ''); });
    function setPersons(tr, rsvp) {
      var n = rsvp === 'yes' ? (+tr.dataset.adults || 0) + (+tr.dataset.children || 0) : '';
      tr.dataset.persons = n === '' ? '' : String(n);
      var td = cell(tr, 'persons');
      if (td) { td.textContent = n === '' ? '' : n; td.dataset.v = td.dataset.s = n === '' ? '' : String(n); }
    }
    function rebuildText(tr) {
      var t = [];
      $$('td[data-col]', tr).forEach(function (td) {
        var type = (colByKey[td.dataset.col] || {}).type;
        if (type === 'text') t.push(td.dataset.v);
        else if (type === 'rsvp') t.push(RSVP_LABEL[td.dataset.v] || '');
      });
      tr.dataset.text = t.join(' ').toLowerCase();
    }

    /* --- Auswahl --- */
    function selectedRows() { return rows().filter(function (tr) { return !tr.hidden && $('.ct-pick', tr).checked; }); }
    ctSelectedIds = function () { return selectedRows().map(function (tr) { return tr.dataset.id; }); };
    var lastPick = null;
    tbody.addEventListener('click', function (ev) {
      var cb = ev.target.closest('.ct-pick');
      if (!cb) return;
      var tr = cb.closest('tr'), vis = visibleRows();
      if (ev.shiftKey && lastPick && !lastPick.hidden) {
        var a = vis.indexOf(lastPick), b = vis.indexOf(tr);
        vis.slice(Math.min(a, b), Math.max(a, b) + 1).forEach(function (r) { $('.ct-pick', r).checked = cb.checked; r.classList.toggle('sel', cb.checked); });
      }
      tr.classList.toggle('sel', cb.checked);
      lastPick = tr;
      refresh();
    });
    $('#ctAll').addEventListener('change', function (ev) {
      visibleRows().forEach(function (tr) { $('.ct-pick', tr).checked = ev.target.checked; tr.classList.toggle('sel', ev.target.checked); });
      refresh();
    });
    $('#ctClear').addEventListener('click', function () {
      rows().forEach(function (tr) { $('.ct-pick', tr).checked = false; tr.classList.remove('sel'); });
      refresh();
    });

    /* --- Suche, Spaltenfilter, Sortieren --- */
    var search = $('[data-ct-search]');
    if (search) search.addEventListener('input', function () { query = search.value.trim().toLowerCase(); applyFilter(); });
    $$('[data-filter]', filterRow).forEach(function (el) {
      el.addEventListener('input', function () { filters[el.dataset.filter] = el.value; applyFilter(); });
    });
    var filterBtn = document.getElementById('ctFilterBtn');
    function showFilters(on) {
      filterRow.hidden = !on;
      filterBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
      filterBtn.classList.toggle('active', on);
      store.set('filters', on);
      scroller.style.setProperty('--ct-head-h', headRow.offsetHeight + 'px');
    }
    filterBtn.addEventListener('click', function () { showFilters(filterRow.hidden); });
    headRow.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-sort]');
      if (!b) return;
      sort = { key: b.dataset.sort, dir: sort.key === b.dataset.sort ? -sort.dir : 1 };
      applySort();
    });

    /* --- Spaltenauswahl (Rechtsklick auf Kopf / Knopf „Spalten“) --- */
    var menu = document.createElement('div');
    menu.className = 'ct-menu surface';
    menu.hidden = true;
    menu.setAttribute('role', 'dialog');
    menu.setAttribute('aria-label', tr('ct_cols_aria'));
    document.body.appendChild(menu);
    function buildMenu() {
      menu.innerHTML = '';
      var h = document.createElement('div'); h.className = 'ct-menu-title'; h.textContent = tr('ct_cols_title'); menu.appendChild(h);
      COLS.forEach(function (c) {
        var l = document.createElement('label'); l.className = 'ct-menu-item';
        var cb = document.createElement('input'); cb.type = 'checkbox'; cb.className = 'form-check-input'; cb.checked = !!visible[c.key];
        cb.addEventListener('change', function () {
          visible[c.key] = cb.checked;
          if (!COLS.some(function (x) { return visible[x.key]; })) { visible[c.key] = true; cb.checked = true; return; }
          store.set('cols', COLS.filter(function (x) { return visible[x.key]; }).map(function (x) { return x.key; }));
          applyCols();
        });
        l.appendChild(cb); l.appendChild(document.createTextNode(' ' + c.label)); menu.appendChild(l);
      });
      var reset = document.createElement('button'); reset.type = 'button'; reset.className = 'btn btn-sm btn-link px-0 mt-1'; reset.textContent = tr('ct_cols_reset');
      reset.addEventListener('click', function () {
        COLS.forEach(function (c) { visible[c.key] = c.on; });
        widths = {}; store.set('w', widths); store.set('cols', COLS.filter(function (x) { return x.on; }).map(function (x) { return x.key; }));
        applyCols(); buildMenu();
      });
      menu.appendChild(reset);
    }
    function openMenu(x, y) {
      buildMenu();
      menu.hidden = false;
      var w = menu.offsetWidth, hgt = menu.offsetHeight;
      menu.style.left = Math.max(8, Math.min(x, window.innerWidth - w - 8)) + 'px';
      menu.style.top = Math.max(8, Math.min(y, window.innerHeight - hgt - 8)) + 'px';
      var first = $('input', menu); if (first) first.focus();
    }
    headRow.addEventListener('contextmenu', function (ev) { ev.preventDefault(); openMenu(ev.clientX, ev.clientY); });
    $('#ctColsBtn').addEventListener('click', function (ev) {
      ev.stopPropagation();
      var r = ev.currentTarget.getBoundingClientRect();
      openMenu(r.left, r.bottom + 4);
    });
    document.addEventListener('click', function (ev) { if (!menu.hidden && !menu.contains(ev.target)) menu.hidden = true; });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') menu.hidden = true; });

    /* --- Spaltenbreite ziehen --- */
    headRow.addEventListener('pointerdown', function (ev) {
      var g = ev.target.closest('[data-grip]');
      if (!g) return;
      ev.preventDefault(); ev.stopPropagation();
      var key = g.dataset.grip, startX = ev.clientX, startW = width(key);
      g.setPointerCapture(ev.pointerId);
      function move(e) { widths[key] = Math.max(50, startW + e.clientX - startX); applyCols(); }
      function up() { g.removeEventListener('pointermove', move); g.removeEventListener('pointerup', up); store.set('w', widths); }
      g.addEventListener('pointermove', move); g.addEventListener('pointerup', up);
    });

    /* --- Inline-Bearbeitung (Doppelklick / Doppeltippen) --- */
    var editing = null;
    function pushUndo(entry) {
      if (undoing) return;
      undoStack.push(entry); if (undoStack.length > 30) undoStack.shift();
      $('#ctUndo').disabled = false;
    }
    function updateRsvpRow(tr, j) {
      tr.dataset.adults = j.adults; tr.dataset.children = j.children;
      var td = cell(tr, 'rsvp');
      if (td) { td.dataset.v = j.rsvp; td.dataset.s = RSVP_LABEL[j.rsvp] || ''; td.textContent = RSVP_LABEL[j.rsvp] || ''; }
      setPersons(tr, j.rsvp); rebuildText(tr); refresh();
    }
    function saveRsvp(tr, status, adults, children) {
      return send(u('rsvp', 'rsvpset'), { id: tr.dataset.id, rsvp: status, adults: adults, children: children });
    }
    function saveCell(tr, field, value) {
      return send(ct.dataset.editUrl, { id: tr.dataset.id, field: field, value: value });
    }
    function startEdit(td, focusNext) {
      if (editing) { if (editing.td === td) return; finishEdit(true); }
      var kind = td.dataset.edit, key = td.dataset.col, old = td.dataset.v, input;
      if (kind === 'rsvp') {
        input = document.createElement('select'); input.className = 'form-select form-select-sm';
        Object.keys(RSVP_LABEL).forEach(function (k) {
          var o = document.createElement('option'); o.value = k; o.textContent = k === '' ? tr('rsvp_none') : RSVP_LABEL[k]; input.appendChild(o);
        });
      } else {
        input = document.createElement('input'); input.className = 'form-control form-control-sm'; input.type = 'text';
        if (key === 'relation') input.setAttribute('list', 'ctRelations');
        if (key === 'state') input.setAttribute('list', 'ctStates');
        if (key === 'email') input.type = 'email';
        if (key === 'phone' || key === 'mobile') input.type = 'tel';
        input.autocomplete = 'off';
      }
      input.value = old;
      input.setAttribute('aria-label', colByKey[key].label);
      td.dataset.html = td.innerHTML; td.textContent = ''; td.classList.add('editing'); td.appendChild(input);
      editing = { td: td, input: input, old: old, cancelled: false };
      input.focus(); if (input.select && kind !== 'rsvp') input.select();
      input.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); finishEdit(true); }
        else if (ev.key === 'Escape') { ev.preventDefault(); finishEdit(false); }
        else if (ev.key === 'Tab') {
          ev.preventDefault();
          var cells = $$('td[data-edit]', td.parentNode).filter(function (c) { return !c.hidden; });
          var next = cells[cells.indexOf(td) + (ev.shiftKey ? -1 : 1)];
          finishEdit(true);
          if (next) startEdit(next);
        }
      });
      input.addEventListener('blur', function () { if (editing && editing.input === input) finishEdit(true); });
      if (kind === 'rsvp') input.addEventListener('change', function () { finishEdit(true); });
    }
    function finishEdit(commit) {
      if (!editing) return;
      var e = editing, td = e.td, tr = td.parentNode, key = td.dataset.col, kind = td.dataset.edit;
      editing = null;
      var val = kind === 'rsvp' ? e.input.value : e.input.value.trim();
      td.classList.remove('editing');
      var show = function (v) {
        td.textContent = kind === 'rsvp' ? (RSVP_LABEL[v] || '') : v;
        td.dataset.v = v; td.dataset.s = kind === 'rsvp' ? (RSVP_LABEL[v] || '') : v;
      };
      if (!commit || val === e.old) { show(e.old); return; }
      show(val); td.classList.add('saving');
      var oldAdults = +tr.dataset.adults || 0, oldChildren = +tr.dataset.children || 0;
      var p = kind === 'rsvp'
        ? saveRsvp(tr, val, val === 'no' || val === '' ? 0 : oldAdults, val === 'no' || val === '' ? 0 : oldChildren)
        : saveCell(tr, key, val);
      p.then(function (j) {
        if (kind === 'rsvp') {
          updateRsvpRow(tr, j);
          pushUndo({ kind: 'rsvp', id: tr.dataset.id, old: { rsvp: e.old, adults: oldAdults, children: oldChildren } });
        } else {
          show(j.value); rebuildText(tr);
          pushUndo({ kind: 'cell', id: tr.dataset.id, field: key, old: e.old });
        }
      }).catch(function (err) { show(e.old); fail(err); }).finally(function () { td.classList.remove('saving'); });
    }
    tbody.addEventListener('dblclick', function (ev) {
      var td = ev.target.closest('td[data-edit]');
      if (td) { ev.preventDefault(); startEdit(td); }
    });
    var lastTap = { td: null, t: 0 };
    tbody.addEventListener('touchend', function (ev) {
      var td = ev.target.closest && ev.target.closest('td[data-edit]');
      if (!td) return;
      var now = Date.now();
      if (lastTap.td === td && now - lastTap.t < 350) { ev.preventDefault(); startEdit(td); lastTap = { td: null, t: 0 }; }
      else lastTap = { td: td, t: now };
    });

    /* --- Ja/Nein-Spalten und Rückgängig --- */
    tbody.addEventListener('flagtoggled', function (ev) {
      var b = ev.target, tr = b.closest('tr'), td = b.closest('td');
      td.dataset.v = td.dataset.s = b.classList.contains('on') ? '1' : '0';
      pushUndo({ kind: 'flag', id: tr.dataset.id, field: ev.detail.field });
    });
    function undo() {
      var a = undoStack.pop();
      $('#ctUndo').disabled = undoStack.length === 0;
      if (!a) return;
      var tr = $('tr[data-id="' + a.id + '"]', tbody);
      if (!tr) return;
      undoing = true;
      var done = function () { undoing = false; };
      if (a.kind === 'flag') {
        var b = $('[data-flag="' + a.field + '"]', tr);
        if (b) { b.click(); setTimeout(done, 800); } else done();
      } else if (a.kind === 'cell') {
        saveCell(tr, a.field, a.old).then(function (j) {
          var td = cell(tr, a.field); td.textContent = j.value; td.dataset.v = td.dataset.s = j.value; rebuildText(tr);
        }).catch(fail).finally(done);
      } else if (a.kind === 'rsvp') {
        saveRsvp(tr, a.old.rsvp, a.old.adults, a.old.children).then(function (j) { updateRsvpRow(tr, j); }).catch(fail).finally(done);
      }
    }
    $('#ctUndo').addEventListener('click', undo);
    document.addEventListener('keydown', function (ev) {
      if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'z' && !editing && !/^(INPUT|SELECT|TEXTAREA)$/.test((document.activeElement || {}).tagName || '')) {
        ev.preventDefault(); undo();
      }
    });

    /* --- Drucken (Auswahl oder alle sichtbaren) --- */
    function printTable() {
      ct.classList.toggle('print-sel', selectedRows().length > 0);
      var clean = function () { ct.classList.remove('print-sel'); window.removeEventListener('afterprint', clean); };
      window.addEventListener('afterprint', clean);
      window.print();
    }
    $('#ctPrint').addEventListener('click', printTable);
    $('#ctPrintSel').addEventListener('click', printTable);

    /* --- Sammelaktionen: Enter im Beziehungsfeld darf nichts anderes auslösen --- */
    var relInput = document.getElementById('ctRelValue');
    if (relInput) relInput.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); document.getElementById('ctRelBtn').click(); }
    });

    applyCols();
    showFilters(!!store.get('filters', false));
    refresh();
  }

  /* ---------- Admin: Diagramm ---------- */
  var chartData = document.getElementById('chartData');
  if (chartData && window.Chart) {
    var c = JSON.parse(chartData.textContent);
    var css = getComputedStyle(document.documentElement);
    var col = function (v) { return css.getPropertyValue(v).trim(); };
    Chart.defaults.font.family = col('--sans');
    Chart.defaults.color = col('--grau');
    new Chart(document.getElementById('activityChart'), {
      type: 'bar',
      data: {
        labels: c.labels,
        datasets: [
          { label: (c.names || {}).logins || 'Logins', data: c.logins, backgroundColor: col('--efeu'), stack: 'a' },
          { label: (c.names || {}).views || 'Views', data: c.views, backgroundColor: '#9fb3a3', stack: 'b' },
          { label: (c.names || {}).downloads || 'Downloads', data: c.downloads, backgroundColor: col('--messing'), stack: 'c' },
          { label: (c.names || {}).uploads || 'Uploads', data: c.uploads, backgroundColor: '#7d8a91', stack: 'd' },
          { label: (c.names || {}).fails || 'Failed', data: c.fails, type: 'line', borderColor: '#9a3b2e', backgroundColor: '#9a3b2e', pointRadius: 2, tension: .3 }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        interaction: { mode: 'index', intersect: false },
        scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                  y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: col('--nebel') } } },
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } }
      }
    });
  }
})();
