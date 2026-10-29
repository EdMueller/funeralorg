/* Stationen – Zeitstrahl und Weltkarte (Vanilla JS, Karte = lokales SVG, Winkel Tripel 84°N–58°S) */
(function () {
  'use strict';

  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var MAX_ZOOM = 40;
  // Winkel Tripel; Konstanten stammen aus bin/make-worldmap.php (Ausschnitt der world.svg)
  var WT_X0 = -2.570796, WT_Y0 = 1.514134, WT_XS = 5.141593, WT_YS = 2.686335, WT_COS1 = 2 / Math.PI;
  var RAD = Math.PI / 180;
  var touchOnly = window.matchMedia('(hover: none)').matches;

  var I18N = {};
  try { I18N = JSON.parse((document.getElementById('i18n') || {}).textContent || '{}'); } catch (e) { I18N = {}; }
  /** Text zum Schlüssel (Plural über n; {n} wird ersetzt). */
  function tr(key, params, n) {
    var v = I18N[key];
    if (v === undefined) return key;
    if (v && typeof v === 'object') v = (n === 1 ? v.one : v.other) || v.other;
    params = params || {};
    if (n !== undefined && params.n === undefined) params.n = n;
    return String(v).replace(/\{(\w+)\}/g, function (m, k) { return params[k] !== undefined ? params[k] : m; });
  }

  /** Winkel Tripel (Einheitskugel, y nach oben); Winkel in Bogenmaß. */
  function wt(phi, lam) {
    var a = Math.acos(Math.cos(phi) * Math.cos(lam / 2));
    var sinc = a < 1e-9 ? 1 : Math.sin(a) / a;
    return [0.5 * (lam * WT_COS1 + 2 * Math.cos(phi) * Math.sin(lam / 2) / sinc), 0.5 * (phi + Math.sin(phi) / sinc)];
  }

  function pct(lat, lon) {
    var p = wt(lat * RAD, lon * RAD);
    return { x: (p[0] - WT_X0) / WT_XS * 100, y: (WT_Y0 - p[1]) / WT_YS * 100 };
  }

  /** Umkehrung per Newton-Verfahren; null, wenn der Punkt außerhalb des Kartenumrisses liegt. */
  function unproject(px, py) {
    var X = WT_X0 + px * WT_XS, Y = WT_Y0 - py * WT_YS;
    var phi = Math.max(-1.5, Math.min(1.5, Y)), lam = 2 * X / (1 + WT_COS1), h = 1e-6;
    for (var i = 0; i < 60; i++) {
      var f = wt(phi, lam), ex = f[0] - X, ey = f[1] - Y;
      if (Math.abs(ex) + Math.abs(ey) < 1e-9) break;
      var fp = wt(phi + h, lam), fl = wt(phi, lam + h);
      var a = (fl[0] - f[0]) / h, b = (fp[0] - f[0]) / h, c = (fl[1] - f[1]) / h, d = (fp[1] - f[1]) / h;
      var det = a * d - b * c;
      if (Math.abs(det) < 1e-12) return null;
      lam -= (d * ex - b * ey) / det;
      phi -= (-c * ex + a * ey) / det;
      phi = Math.max(-Math.PI / 2, Math.min(Math.PI / 2, phi));
      lam = Math.max(-Math.PI, Math.min(Math.PI, lam));
    }
    var g = wt(phi, lam);
    if (Math.abs(g[0] - X) + Math.abs(g[1] - Y) > 1e-5) return null;
    return { lat: phi / RAD, lon: lam / RAD };
  }

  /** Zoom- und verschiebbare Karte. Positionen der Pins stehen in Prozent der Kartenfläche. */
  function WorldMap(el, opts) {
    opts = opts || {};
    var view = $('.wmap-view', el);
    var s = 1, tx = 0, ty = 0;
    var drag = null, dragged = false;
    var api = {};

    function size() { return { w: el.clientWidth, h: el.clientHeight }; }
    function clamp() {
      var z = size();
      tx = Math.min(0, Math.max(tx, z.w - z.w * s));
      ty = Math.min(0, Math.max(ty, z.h - z.h * s));
    }
    function apply(animate) {
      clamp();
      view.classList.toggle('anim', !!animate);
      view.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + s + ')';
      view.style.setProperty('--inv', (1 / s).toFixed(4));
      el.classList.toggle('zoomed', s > 1.01);
      if (opts.onChange) opts.onChange();
    }
    function zoomAt(f, cx, cy, animate) {
      var s2 = Math.min(MAX_ZOOM, Math.max(1, s * f));
      tx = cx - (cx - tx) * (s2 / s);
      ty = cy - (cy - ty) * (s2 / s);
      s = s2;
      apply(animate);
    }
    function centerOn(xp, yp, scale, animate) {
      var z = size();
      s = Math.min(MAX_ZOOM, Math.max(1, scale));
      tx = z.w / 2 - xp / 100 * z.w * s;
      ty = z.h / 2 - yp / 100 * z.h * s;
      apply(animate);
    }

    api.world = function (animate) { s = 1; tx = 0; ty = 0; apply(animate); };
    api.fit = function (points, animate) {
      if (!points.length) return api.world(animate);
      var x0 = 101, x1 = -1, y0 = 101, y1 = -1;
      points.forEach(function (p) { x0 = Math.min(x0, p.x); x1 = Math.max(x1, p.x); y0 = Math.min(y0, p.y); y1 = Math.max(y1, p.y); });
      var w = Math.max(x1 - x0, 1), h = Math.max(y1 - y0, 1);
      var scale = points.length === 1 || (x1 - x0 < 0.5 && y1 - y0 < 0.5) ? 4 : Math.min(8, 0.75 * Math.min(100 / w, 100 / h));
      centerOn((x0 + x1) / 2, (y0 + y1) / 2, scale, animate);
    };
    api.centerOn = centerOn;
    /** Holt einen Punkt in den sichtbaren Bereich, falls er außerhalb liegt. */
    api.reveal = function (p) {
      var z = size();
      var sx = tx + p.x / 100 * z.w * s, sy = ty + p.y / 100 * z.h * s;
      if (sx < z.w * 0.05 || sx > z.w * 0.95 || sy < z.h * 0.06 || sy > z.h * 0.94) centerOn(p.x, p.y, s, false);
    };
    api.toLatLon = function (clientX, clientY) {
      var r = el.getBoundingClientRect(), z = size();
      var xp = ((clientX - r.left - tx) / s) / z.w, yp = ((clientY - r.top - ty) / s) / z.h;
      return unproject(xp, yp);
    };
    api.refresh = function () { apply(false); };

    // Pins positionieren
    $$('.wpin[data-lat]', el).forEach(function (p) {
      var c = pct(parseFloat(p.dataset.lat), parseFloat(p.dataset.lon));
      p.style.left = c.x + '%';
      p.style.top = c.y + '%';
    });

    // Bedienung
    $$('[data-wz]', el).forEach(function (b) {
      b.addEventListener('click', function (ev) {
        ev.stopPropagation();
        var z = size(), w = b.dataset.wz;
        if (w === 'in') zoomAt(1.6, z.w / 2, z.h / 2, true);
        else if (w === 'out') zoomAt(1 / 1.6, z.w / 2, z.h / 2, true);
        else if (w === 'world') api.world(true);
        else if (w === 'fit' && opts.onFit) opts.onFit();
      });
    });
    el.addEventListener('wheel', function (ev) {
      if (!opts.wheel && !(ev.ctrlKey || ev.metaKey)) return;
      ev.preventDefault();
      var r = el.getBoundingClientRect();
      zoomAt(ev.deltaY < 0 ? 1.25 : 0.8, ev.clientX - r.left, ev.clientY - r.top, false);
    }, { passive: false });
    el.addEventListener('pointerdown', function (ev) {
      if (ev.button > 0 || ev.target.closest('.wmap-ctl') || ev.target.closest('.wpop')) return;
      drag = { x: ev.clientX, y: ev.clientY, id: ev.pointerId, on: false };
      dragged = false;
    });
    el.addEventListener('pointermove', function (ev) {
      if (!drag || ev.pointerId !== drag.id) return;
      var dx = ev.clientX - drag.x, dy = ev.clientY - drag.y;
      if (!drag.on) {
        if (Math.abs(dx) + Math.abs(dy) < 5 || s <= 1.01) return;
        drag.on = true;
        dragged = true;
        try { el.setPointerCapture(ev.pointerId); } catch (e) { /* ohne Capture */ }
      }
      tx += dx; ty += dy;
      drag.x = ev.clientX; drag.y = ev.clientY;
      apply(false);
    });
    function endDrag(ev) { if (drag && ev.pointerId === drag.id) drag = null; }
    el.addEventListener('pointerup', endDrag);
    el.addEventListener('pointercancel', endDrag);
    el.addEventListener('click', function (ev) {
      if (dragged) { dragged = false; ev.stopPropagation(); return; }
      if (opts.onPick && !ev.target.closest('.wmap-ctl')) {
        var ll = api.toLatLon(ev.clientX, ev.clientY);
        if (ll) opts.onPick(ll);
      }
    }, true);
    window.addEventListener('resize', function () { apply(false); });

    apply(false);
    return api;
  }

  /* ---------- Seite: Zeitstrahl + Karte + Popup ---------- */
  var dataEl = document.getElementById('stationsData');
  var mapEl = document.getElementById('worldMap');
  if (dataEl && mapEl) {
    var items = {};
    (JSON.parse(dataEl.textContent || '{}').items || []).forEach(function (it) { items[it.id] = it; });
    var pop = document.getElementById('wPop');
    var pins = {};
    $$('.wpin', mapEl).forEach(function (p) { pins[p.dataset.sid] = p; });
    var marks = $$('.tl-mark');
    var current = null, hideTimer = null, popHover = false;

    function allPoints() {
      return Object.keys(pins).map(function (id) { return pct(items[id].lat, items[id].lon); });
    }
    var map = WorldMap(mapEl, {
      onChange: function () { if (current && !pop.hidden) placePop(); },
      onFit: function () { map.fit(allPoints(), true); }
    });
    map.fit(allPoints(), false);

    function el(tag, cls, text) {
      var n = document.createElement(tag);
      if (cls) n.className = cls;
      if (text) n.textContent = text;
      return n;
    }
    function buildPop(it) {
      pop.textContent = '';
      var card = el(it.link ? 'a' : 'div', 'wpop-card');
      if (it.link) card.href = it.link;
      card.style.setProperty('--c', it.color);
      if (it.img) {
        var im = el('img', 'wpop-img');
        im.src = it.img; im.alt = ''; im.loading = 'lazy';
        card.appendChild(im);
      }
      var body = el('div', 'wpop-body');
      if (it.when) body.appendChild(el('div', 'wpop-when', it.when));
      body.appendChild(el('strong', 'wpop-title', it.title));
      if (it.place) body.appendChild(el('div', 'wpop-place', it.place));
      if (it.text) body.appendChild(el('p', 'wpop-text', it.text));
      if (it.link) {
        var cta = it.count > 0 ? tr('cta_slideshow', {}, it.count) : tr('cta_view');
        body.appendChild(el('div', 'wpop-cta', cta));
      }
      card.appendChild(body);
      pop.appendChild(card);
    }
    function placePop() {
      var pin = pins[current];
      if (!pin) return;
      var er = mapEl.getBoundingClientRect(), pr = pin.getBoundingClientRect();
      var ax = pr.left + pr.width / 2 - er.left, ay = pr.top - er.top;
      var pw = pop.offsetWidth, ph = pop.offsetHeight;
      var left = Math.min(Math.max(ax - pw / 2, 6), Math.max(6, er.width - pw - 6));
      var top = ay - ph - 10;
      var below = top < 6;
      if (below) top = ay + pr.height + 10;
      pop.style.left = left + 'px';
      pop.style.top = top + 'px';
      pop.classList.toggle('below', below);
    }
    function setActive(id, on) {
      $$('[data-sid="' + id + '"]').forEach(function (n) { n.classList.toggle('is-active', on); });
    }
    function show(id) {
      clearTimeout(hideTimer);
      if (current && current !== id) setActive(current, false);
      var it = items[id];
      if (!it) return;
      current = id;
      setActive(id, true);
      if (!pins[id]) { pop.hidden = true; return; }
      map.reveal(pct(it.lat, it.lon));
      buildPop(it);
      pop.hidden = false;
      placePop();
    }
    function hide() {
      if (current) setActive(current, false);
      current = null;
      pop.hidden = true;
    }
    function scheduleHide() {
      clearTimeout(hideTimer);
      hideTimer = setTimeout(function () { if (!popHover) hide(); }, 220);
    }
    pop.addEventListener('mouseenter', function () { popHover = true; clearTimeout(hideTimer); });
    pop.addEventListener('mouseleave', function () { popHover = false; scheduleHide(); });

    function bindTarget(n) {
      var id = n.dataset.sid;
      n.addEventListener('mouseenter', function () { if (!touchOnly) show(id); });
      n.addEventListener('mouseleave', function () { if (!touchOnly) scheduleHide(); });
      n.addEventListener('focus', function () { show(id); });
      n.addEventListener('blur', function () { scheduleHide(); });
      n.addEventListener('click', function (ev) {
        ev.stopPropagation();
        var it = items[id];
        if (touchOnly) {  // erster Tipp zeigt das Popup, zweiter öffnet die Diashow
          if (current === id && it.link) window.location.href = it.link; else show(id);
          return;
        }
        if (it.link) window.location.href = it.link; else show(id);
      });
    }
    marks.forEach(bindTarget);
    Object.keys(pins).forEach(function (id) { bindTarget(pins[id]); });
    document.addEventListener('click', function (ev) {
      if (!ev.target.closest('.wpop') && !ev.target.closest('.wpin') && !ev.target.closest('.tl-mark')) hide();
    });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') hide(); });

    // Legende filtert nach Farbe
    $$('[data-legend]').forEach(function (b) {
      b.addEventListener('click', function () {
        var on = b.getAttribute('aria-pressed') !== 'true';
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
        $$('[data-color]').forEach(function (n) { if (n.dataset.color === b.dataset.legend) n.hidden = !on; });
        hide();
        layoutTimeline();
      });
    });

    /* Beschriftungen des Zeitstrahls auf vier Bahnen verteilen, damit sie sich nicht überdecken */
    function layoutTimeline() {
      var last = [-1e9, -1e9, -1e9, -1e9];
      marks.forEach(function (m) {
        if (m.hidden) return;
        var label = $('.tl-label', m);
        var x = m.offsetLeft, w = label.offsetWidth;
        var lane = -1;
        for (var l = 0; l < 4; l++) if (x - w / 2 >= last[l] + 8) { lane = l; break; }
        if (lane < 0) lane = last.indexOf(Math.min.apply(null, last));
        m.dataset.lane = lane;
        last[lane] = x + w / 2;
      });
    }
    layoutTimeline();
    window.addEventListener('resize', layoutTimeline);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(layoutTimeline);
  }

  /* ---------- Ort wählen (Bearbeiten-Dialog) ---------- */
  var pickEl = document.getElementById('pickMap');
  var modal = document.getElementById('stationModal');
  if (pickEl && modal) {
    var form = $('form', modal) || modal;
    var lat = form.elements.lat, lon = form.elements.lon;
    var marker = $('.wpin-pick', pickEl);
    var picker = WorldMap(pickEl, {
      wheel: true,
      onPick: function (p) {
        lat.value = p.lat.toFixed(3);
        lon.value = p.lon.toFixed(3);
        syncMarker(false);
      }
    });
    function syncMarker(center) {
      var a = parseFloat(String(lat.value).replace(',', '.')), o = parseFloat(String(lon.value).replace(',', '.'));
      if (isNaN(a) || isNaN(o)) { marker.hidden = true; return; }
      var c = pct(a, o);
      marker.style.left = c.x + '%';
      marker.style.top = c.y + '%';
      marker.hidden = false;
      if (center) picker.centerOn(c.x, c.y, 4, false); else picker.reveal(c);
    }
    lat.addEventListener('change', function () { syncMarker(true); });
    lon.addEventListener('change', function () { syncMarker(true); });
    modal.addEventListener('shown.bs.modal', function () {
      picker.world(false);
      syncMarker(true);
      if (marker.hidden) picker.world(false);
    });
  }
  /* ---------- Dialog „Station“ füllen (Auslöser trägt data-station="<json>") ---------- */
  var stModal = document.getElementById('stationModal');
  if (stModal) {
    stModal.addEventListener('show.bs.modal', function (ev) {
      var t = ev.relatedTarget;
      if (!t || !t.dataset.station) return;
      var d = {};
      try { d = JSON.parse(t.dataset.station); } catch (e) { d = {}; }
      var f = $('form', stModal);
      f.reset();
      Array.prototype.forEach.call(f.elements, function (el) {
        if (!el.name || el.name.charAt(0) === '_') return;
        if (el.type === 'checkbox') el.checked = !!d[el.name];
        else if (d[el.name] !== undefined && d[el.name] !== null) el.value = d[el.name];
        else if (el.tagName !== 'SELECT') el.value = '';
      });
      var fixed = d.type === 'birth' || d.type === 'death';
      $$('[data-st-fixed]', stModal).forEach(function (c) { c.hidden = fixed; });
      $('[name=title]', stModal).required = !fixed;
      var del = $('[data-delete-station]', stModal);
      if (del) del.hidden = !d.id || fixed;
      $('.modal-title', stModal).textContent = fixed ? tr(d.type === 'birth' ? 'st_birth' : 'st_death') : tr(d.id ? 'st_edit' : 'st_new');
    });
    stModal.addEventListener('shown.bs.modal', function () {
      var first = $('input:not([type=hidden]):not([type=checkbox]), select', stModal);
      if (first) first.focus();
    });
  }
})();
