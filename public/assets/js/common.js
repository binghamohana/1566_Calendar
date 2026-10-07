/* Grove Park Collective — shared helpers (dates, times, API calls, dialogs, toasts). */
(function ($, window) {
  'use strict';

  var GPC = window.GPC = window.GPC || {};

  // ---------------------------------------------------------------- dates
  // Dates are "YYYY-MM-DD" strings in the building's time zone. Math is done in UTC
  // so a visitor's own device time zone can never shift a day.
  var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

  function parse(d) { var p = d.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }
  function iso(dt) { return dt.toISOString().slice(0, 10); }

  GPC.date = {
    addDays: function (d, n) { var dt = parse(d); dt.setUTCDate(dt.getUTCDate() + n); return iso(dt); },
    weekday: function (d) { return parse(d).getUTCDay(); },
    weekStart: function (d) { var w = parse(d).getUTCDay(); return GPC.date.addDays(d, w === 0 ? -6 : 1 - w); }, // Monday
    diffDays: function (a, b) { return Math.round((parse(b) - parse(a)) / 86400000); },
    isValid: function (d) { return /^\d{4}-\d{2}-\d{2}$/.test(d || '') && !isNaN(parse(d)); },
    long: function (d, withYear) {
      var dt = parse(d);
      return DAYS[dt.getUTCDay()] + ', ' + MONTHS[dt.getUTCMonth()] + ' ' + dt.getUTCDate() + (withYear ? ', ' + dt.getUTCFullYear() : '');
    },
    short: function (d) {
      var dt = parse(d);
      return DAYS[dt.getUTCDay()].slice(0, 3) + ', ' + MONTHS[dt.getUTCMonth()].slice(0, 3) + ' ' + dt.getUTCDate();
    },
    dayName: function (d, len) { var n = DAYS[parse(d).getUTCDay()]; return len ? n.slice(0, len) : n; },
    dayNum: function (d) { return parse(d).getUTCDate(); },
    monthDay: function (d) { var dt = parse(d); return MONTHS[dt.getUTCMonth()].slice(0, 3) + ' ' + dt.getUTCDate(); },
    weekLabel: function (start) {
      var a = parse(start), b = parse(GPC.date.addDays(start, 6));
      var sameMonth = a.getUTCMonth() === b.getUTCMonth();
      return MONTHS[a.getUTCMonth()].slice(0, 3) + ' ' + a.getUTCDate() + ' – ' +
        (sameMonth ? '' : MONTHS[b.getUTCMonth()].slice(0, 3) + ' ') + b.getUTCDate() + ', ' + b.getUTCFullYear();
    },
    relative: function (d, today) {
      var diff = GPC.date.diffDays(today, d);
      if (diff === 0) return 'Today';
      if (diff === 1) return 'Tomorrow';
      if (diff === -1) return 'Yesterday';
      return GPC.date.short(d);
    },
    DAYS: DAYS
  };

  // ---------------------------------------------------------------- times (minutes after midnight)
  GPC.time = {
    toMin: function (hhmm) { var p = String(hhmm).split(':'); return (+p[0]) * 60 + (+p[1]); },
    toHHMM: function (m) { return ('0' + Math.floor(m / 60)).slice(-2) + ':' + ('0' + (m % 60)).slice(-2); },
    fmt: function (m, compact) {
      m = m % 1440;
      var h = Math.floor(m / 60), mm = m % 60, ap = h >= 12 ? 'PM' : 'AM', h12 = h % 12 === 0 ? 12 : h % 12;
      if (compact) return h12 + (mm ? ':' + ('0' + mm).slice(-2) : '') + (ap === 'AM' ? 'a' : 'p');
      return h12 + ':' + ('0' + mm).slice(-2) + ' ' + ap;
    },
    range: function (s, e) {
      var sa = (s % 1440) >= 720, ea = (e % 1440) >= 720;
      if (sa === ea && e < 1440) return GPC.time.fmt(s).replace(/ (AM|PM)$/, '') + '–' + GPC.time.fmt(e);
      return GPC.time.fmt(s) + '–' + GPC.time.fmt(e);
    },
    duration: function (mins) {
      var h = Math.floor(mins / 60), m = mins % 60, out = [];
      if (h) out.push(h + (h === 1 ? ' hr' : ' hrs'));
      if (m) out.push(m + ' min');
      return out.join(' ') || '0 min';
    },
    hourLabel: function (h) { h = h % 24; return (h % 12 === 0 ? 12 : h % 12) + (h < 12 ? ' AM' : ' PM'); }
  };

  /** Opening hours for a space on a date → [startMin, endMin] or null if closed. */
  GPC.hoursOn = function (space, date) {
    if (!space || !space.hours) return [0, 1440];
    var day = space.hours[String(GPC.date.weekday(date))];
    if (!day) return null;
    return [GPC.time.toMin(day[0]), GPC.time.toMin(day[1])];
  };

  /** "Mon–Fri 7 AM–10 PM · Sat 8 AM–10 PM · Sun closed" */
  GPC.hoursSummary = function (space) {
    if (!space.hours) return 'Available any time';
    var order = [1, 2, 3, 4, 5, 6, 0], groups = [];
    order.forEach(function (d) {
      var v = space.hours[String(d)], key = v ? v.join('-') : 'closed', last = groups[groups.length - 1];
      if (last && last.key === key) { last.days.push(d); } else { groups.push({ key: key, days: [d], v: v }); }
    });
    return groups.map(function (g) {
      var a = GPC.date.DAYS[g.days[0]].slice(0, 3), b = GPC.date.DAYS[g.days[g.days.length - 1]].slice(0, 3);
      var label = g.days.length > 1 ? a + '–' + b : a;
      if (!g.v) return label + ' closed';
      var s = GPC.time.toMin(g.v[0]), e = GPC.time.toMin(g.v[1]);
      return label + ' ' + (s === 0 && e === 1440 ? 'all day' : GPC.time.range(s, e));
    }).join(' · ');
  };

  // ---------------------------------------------------------------- misc
  GPC.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  GPC.uuid = function () {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(b);
    return Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
  };

  GPC.store = {
    get: function (k, fallback) { try { var v = localStorage.getItem('gpc.' + k); return v == null ? fallback : JSON.parse(v); } catch (e) { return fallback; } },
    set: function (k, v) { try { localStorage.setItem('gpc.' + k, JSON.stringify(v)); } catch (e) { /* private mode */ } },
    del: function (k) { try { localStorage.removeItem('gpc.' + k); } catch (e) { /* ignore */ } }
  };

  GPC.isMobile = function () { return window.matchMedia('(max-width: 720px)').matches; };
  GPC.isWide = function () { return window.matchMedia('(min-width: 900px)').matches; };

  GPC.icon = function (name) {
    var p = {
      check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
      x: '<path d="M6 6l12 12M18 6L6 18"/>',
      left: '<path d="M15 5l-7 7 7 7"/>',
      right: '<path d="M9 5l7 7-7 7"/>',
      cal: '<rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
      clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
      users: '<circle cx="9" cy="9" r="3.2"/><path d="M3.5 19c.6-3 2.8-4.6 5.5-4.6s4.9 1.6 5.5 4.6"/><path d="M15.5 6.3a3 3 0 0 1 0 5.6M17.5 14.8c1.6.6 2.6 2 3 4.2"/>',
      pin: '<path d="M12 21s-6.5-6.2-6.5-11a6.5 6.5 0 0 1 13 0c0 4.8-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
      plus: '<path d="M12 5v14M5 12h14"/>',
      down: '<path d="M6 9l6 6 6-6"/>',
      mail: '<rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="M4 7l8 6 8-6"/>',
      door: '<path d="M6 20V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v14M4 20h16"/><circle cx="14.5" cy="12.5" r=".9" fill="currentColor"/>',
      key: '<circle cx="8" cy="15" r="3.5"/><path d="M10.5 12.5L19 4M16 7l2.5 2.5"/>',
      spark: '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M6 18l2.5-2.5M15.5 8.5L18 6"/>'
    }[name] || '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + p + '</svg>';
  };

  // ---------------------------------------------------------------- API
  /**
   * JSON request → Promise resolving to the response body.
   * Errors reject with {message, field, code, status, data}.
   */
  GPC.api = function (url, method, data, headers) {
    return new Promise(function (resolve, reject) {
      $.ajax({
        url: url,
        method: method || 'GET',
        data: method && method !== 'GET' ? JSON.stringify(data || {}) : data,
        contentType: method && method !== 'GET' ? 'application/json' : undefined,
        dataType: 'json',
        headers: headers || {},
        timeout: 30000
      }).done(resolve).fail(function (xhr, status) {
        var body = xhr.responseJSON || {};
        reject({
          message: body.error || (status === 'timeout'
            ? 'The connection timed out. Please check your internet and try again.'
            : (xhr.status === 0 ? 'Can’t reach the server. Please check your connection and try again.' : 'Something went wrong. Please try again.')),
          field: body.field || null,
          code: body.code || null,
          status: xhr.status,
          data: body
        });
      });
    });
  };

  // ---------------------------------------------------------------- toasts
  GPC.toast = function (msg, type) {
    var $wrap = $('.toasts');
    if (!$wrap.length) $wrap = $('<div class="toasts" role="status" aria-live="polite"></div>').appendTo('body');
    var $t = $('<div class="toast"></div>').toggleClass('error', type === 'error').text(msg).appendTo($wrap);
    setTimeout(function () { $t.fadeOut(200, function () { $t.remove(); }); }, type === 'error' ? 6000 : 3800);
  };

  // ---------------------------------------------------------------- dialogs
  var openDialogs = [];

  /**
   * Open a dialog (centered on desktop, bottom sheet on phones).
   * opts: {title, eyebrow, body (html|jQuery), foot (html|jQuery), wide, onClose}
   * Returns {$el, $body, $foot, close, setTitle}.
   */
  GPC.dialog = function (opts) {
    var lastFocus = document.activeElement;
    var $ov = $('<div class="overlay" role="presentation"></div>');
    var $dlg = $('<div class="dialog" role="dialog" aria-modal="true"></div>').toggleClass('wide', !!opts.wide);
    var $head = $('<div class="dialog-head"><div style="flex:1;min-width:0"><div class="eyebrow"></div><h2></h2></div></div>');
    var $close = $('<button type="button" class="icon-btn dialog-close" aria-label="Close">' + GPC.icon('x') + '</button>');
    var $body = $('<div class="dialog-body"></div>').append(opts.body || '');
    var $foot = $('<div class="dialog-foot"></div>').append(opts.foot || '');
    $head.find('.eyebrow').text(opts.eyebrow || '').toggle(!!opts.eyebrow);
    $head.find('h2').text(opts.title || '');
    $head.append($close);
    $dlg.append($head, $body);
    if (opts.foot) $dlg.append($foot);
    $ov.append($dlg).appendTo('body');
    var id = 'dlg-' + Math.random().toString(36).slice(2);
    $head.find('h2').attr('id', id);
    $dlg.attr('aria-labelledby', id);
    $('body').css('overflow', 'hidden');
    requestAnimationFrame(function () { $ov.addClass('is-open'); });

    var api = {
      $el: $dlg, $body: $body, $foot: $foot,
      setTitle: function (t, eyebrow) {
        $head.find('h2').text(t);
        if (eyebrow !== undefined) $head.find('.eyebrow').text(eyebrow).toggle(!!eyebrow);
      },
      close: function () {
        if (api.closed) return;
        api.closed = true;
        openDialogs = openDialogs.filter(function (d) { return d !== api; });
        $ov.removeClass('is-open');
        setTimeout(function () { $ov.remove(); }, 180);
        if (!openDialogs.length) $('body').css('overflow', '');
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) { /* ignore */ } }
        if (opts.onClose) opts.onClose();
      }
    };
    $close.on('click', api.close);
    $ov.on('mousedown', function (e) { if (e.target === $ov[0]) api.close(); });
    openDialogs.push(api);
    setTimeout(function () {
      if ($dlg[0].contains(document.activeElement)) return; // never steal focus from a field already in use
      var $f = $dlg.find('[autofocus]').first();
      if (!$f.length && !GPC.isMobile()) $f = $dlg.find('input:not([type=hidden]):not(.hp input),select,textarea').filter(':visible').first();
      ($f.length ? $f : $close).trigger('focus');
    }, 60);
    return api;
  };

  GPC.closeDialogs = function () { openDialogs.slice().forEach(function (d) { d.close(); }); };

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && openDialogs.length) openDialogs[openDialogs.length - 1].close();
  });

  GPC.confirm = function (opts) {
    return new Promise(function (resolve) {
      var done = false;
      var $ok = $('<button type="button" class="btn"></button>').text(opts.ok || 'Confirm').toggleClass('btn-danger', !!opts.danger);
      var $cancel = $('<button type="button" class="btn btn-ghost"></button>').text(opts.cancel || 'Go back');
      var d = GPC.dialog({
        title: opts.title,
        body: $('<p style="margin:0 0 8px;color:var(--ink-2)"></p>').text(opts.message || ''),
        foot: $('<div style="display:flex;gap:10px;justify-content:flex-end;width:100%;flex-wrap:wrap"></div>').append($cancel, $ok),
        onClose: function () { if (!done) resolve(false); }
      });
      $ok.on('click', function () { done = true; d.close(); resolve(true); });
      $cancel.on('click', function () { d.close(); });
    });
  };

  /** Show server validation errors next to the matching field. */
  GPC.formErrors = function ($form, err) {
    $form.find('.field').removeClass('has-error').find('.field-error').remove();
    $form.find('.form-alert').remove();
    if (!err) return;
    var $field = err.field ? $form.find('[name="' + err.field + '"]').closest('.field') : $();
    if ($field.length) {
      $field.addClass('has-error').append($('<div class="field-error"></div>').text(err.message));
      var el = $field.find('input,select,textarea')[0];
      if (el) { el.focus({ preventScroll: true }); el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    } else {
      var $a = $('<div class="alert alert-error form-alert" role="alert"></div>').text(err.message);
      $form.prepend($a);
      if ($a[0].scrollIntoView) $a[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  };
})(jQuery, window);
