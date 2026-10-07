/* Grove Park Collective — time-grid calendar (used by the public page and the admin).
 *
 * Columns can be spaces (one day, every room side by side) or days (one room, a week).
 * Time runs vertically. Click/tap an open slot to start a booking; drag with a mouse to pick a range.
 * Because the server never allows overlaps, events in one column never overlap either.
 */
(function ($, GPC) {
  'use strict';

  function Calendar($root, opts) {
    this.$root = $root.addClass('cal');
    this.opts = $.extend({ increment: 15, tapMinutes: 30, defaultDuration: 60, maxDuration: 1440, allowPast: false, readOnly: false }, opts);
    this.$root.html('<div class="cal-scroll"><div class="cal-grid"></div></div><div class="cal-loading" hidden><span class="spinner"></span></div>');
    this.$scroll = this.$root.find('.cal-scroll');
    this.$grid = this.$root.find('.cal-grid');
    this.data = null;
    this._bind();
  }

  Calendar.prototype.loading = function (on) { this.$root.find('.cal-loading').prop('hidden', !on); };

  /**
   * data: {columns, events, today, nowMin, range: [startMin, endMin], colMin}
   * column: {key, title, sub, date, color, hours: [s, e] | null, isDay, onHead}
   * event:  {col, start, end, title, sub, kind, mine, current, color, clickable, data}
   */
  Calendar.prototype.render = function (data, keepScroll) {
    var self = this, o = this.opts;
    var prevTop = this.$scroll.scrollTop();
    this.data = data;
    var r = data.range || [0, 1440], s0 = r[0], s1 = r[1];
    var hours = (s1 - s0) / 60;
    var y = function (m) { return 'calc(var(--hour) * ' + ((m - s0) / 60).toFixed(4) + ')'; };
    var h = function (a, b) { return 'calc(var(--hour) * ' + ((b - a) / 60).toFixed(4) + ')'; };

    this.$root.toggleClass('read-only', !!o.readOnly);
    this.$root[0].style.setProperty('--cols', data.columns.length);
    this.$root[0].style.setProperty('--hours', hours);
    this.$root[0].style.setProperty('--colmin', (data.colMin || 0) + 'px');

    var html = '<div class="cal-corner"></div>';
    data.columns.forEach(function (c, i) {
      var tag = c.onHead ? 'button type="button"' : 'div';
      var cls = 'cal-colhead' + (c.isDay ? ' is-day' : '') + (c.date === data.today && c.isDay ? ' is-today' : '');
      var title = c.isDay ? '<span>' + GPC.esc(c.title) + '</span>' : (c.color ? '<i class="sw" style="--c:' + c.color + '"></i>' : '') + '<span>' + GPC.esc(c.title) + '</span>';
      html += '<' + tag + ' class="' + cls + '" data-i="' + i + '">' +
        (c.isDay ? '<div class="ch-sub">' + GPC.esc(c.sub || '') + '</div><div class="ch-title">' + title + '</div>'
                 : '<div class="ch-title">' + title + '</div>' + (c.sub ? '<div class="ch-sub">' + GPC.esc(c.sub) + '</div>' : '')) +
        '</' + (c.onHead ? 'button' : 'div') + '>';
    });

    html += '<div class="cal-times">';
    for (var hr = Math.ceil(s0 / 60); hr < s1 / 60; hr++) {
      if (hr * 60 === s0) continue;
      html += '<div class="cal-time" style="top:' + y(hr * 60) + '">' + GPC.time.hourLabel(hr) + '</div>';
    }
    html += '</div>';

    data.columns.forEach(function (c, i) {
      html += '<div class="cal-col" data-i="' + i + '">';
      // closed
      if (!c.hours) {
        html += '<div class="cal-shade closed" style="top:0;bottom:0"><span>Closed</span></div>';
      } else {
        if (c.hours[0] > s0) html += '<div class="cal-shade closed" style="top:0;height:' + h(s0, Math.min(c.hours[0], s1)) + '"></div>';
        if (c.hours[1] < s1) html += '<div class="cal-shade closed" style="top:' + y(Math.max(c.hours[1], s0)) + ';bottom:0"></div>';
      }
      // past
      if (c.date < data.today) {
        html += '<div class="cal-shade past" style="top:0;bottom:0"></div>';
      } else if (c.date === data.today && data.nowMin > s0) {
        html += '<div class="cal-shade past" style="top:0;height:' + h(s0, Math.min(data.nowMin, s1)) + '"></div>';
      }
      // events
      self._events(c.key).forEach(function (ev) {
        var a = Math.max(ev.start, s0), b = Math.min(ev.end, s1);
        if (b <= a) return;
        var dur = b - a;
        var cls = 'cal-ev' + (ev.kind === 'block' ? ' is-block' : '') + (ev.mine ? ' is-mine' : '') + (ev.pending ? ' is-pending' : '') +
          (ev.current ? ' is-current' : '') + (ev.clickable || ev.mine ? ' is-clickable' : '') +
          (dur < 45 ? ' is-short' : '') + (dur < 25 ? ' is-tiny' : '');
        var time = GPC.time.range(ev.start, ev.end);
        html += '<div class="' + cls + '" data-col="' + GPC.esc(c.key) + '" data-start="' + ev.start + '"' +
          ' style="top:' + y(a) + ';height:calc(' + h(a, b) + ' - 2px);' + (ev.color ? '--c:' + ev.color : '') + '"' +
          ' title="' + GPC.esc(ev.title + ' · ' + time + (ev.sub ? ' · ' + ev.sub : '')) + '"' +
          (ev.clickable || ev.mine ? ' role="button" tabindex="0"' : '') + '>' +
          '<div class="ev-inner"><div class="ev-title">' + GPC.esc(ev.title) + '</div>' +
          '<div class="ev-time">' + time + '</div>' +
          (ev.sub && dur >= 75 ? '<div class="ev-sub">' + GPC.esc(ev.sub) + '</div>' : '') +
          '</div></div>';
      });
      if (c.date === data.today && data.nowMin >= s0 && data.nowMin <= s1) {
        html += '<div class="cal-now" style="top:' + y(data.nowMin) + '"></div>';
      }
      html += '<div class="cal-hover" hidden></div><div class="cal-sel" hidden></div></div>';
    });

    this.$grid.html(html);
    if (keepScroll) this.$scroll.scrollTop(prevTop);
  };

  Calendar.prototype._events = function (colKey) {
    return (this.data.events || []).filter(function (e) { return e.col === colKey; }).sort(function (a, b) { return a.start - b.start; });
  };

  Calendar.prototype.scrollToMin = function (m) {
    var r = this.data.range || [0, 1440];
    var colH = this.$grid.find('.cal-col').first().outerHeight() || 1;
    var px = (m - r[0]) / (r[1] - r[0]) * colH;
    this.$scroll.scrollTop(Math.max(0, px - 12));
  };

  /** The open interval around minute m in a column: [lo, hi], or null when m is not bookable. */
  Calendar.prototype._freeAround = function (col, m) {
    var d = this.data, o = this.opts, r = d.range || [0, 1440];
    if (!col.hours) return null;
    var lo = Math.max(r[0], col.hours[0]), hi = Math.min(r[1], col.hours[1]);
    if (!o.allowPast) {
      if (col.date < d.today) return null;
      if (col.date === d.today) {
        // A reservation may start up to 15 minutes in the past (walk-ups).
        lo = Math.max(lo, Math.ceil((d.nowMin - 15) / o.increment) * o.increment);
      }
    }
    var evs = this._events(col.key);
    for (var i = 0; i < evs.length; i++) {
      var ev = evs[i];
      if (m >= ev.start && m < ev.end) return null;
      if (ev.end <= m) lo = Math.max(lo, ev.end);
      if (ev.start > m) { hi = Math.min(hi, ev.start); break; }
    }
    if (m < lo || m >= hi) return null;
    return [lo, hi];
  };

  Calendar.prototype._minAt = function ($col, pageY) {
    var r = this.data.range || [0, 1440];
    var rect = $col[0].getBoundingClientRect();
    var frac = (pageY - rect.top) / rect.height;
    return Math.max(r[0], Math.min(r[1] - 1, r[0] + frac * (r[1] - r[0])));
  };

  /** Turn a tap at minute m into a sensible default range (snapped, clipped to free time). */
  Calendar.prototype._tapRange = function (col, m) {
    var o = this.opts, step = Math.max(o.tapMinutes, o.increment);
    var free = this._freeAround(col, m);
    if (!free) return null;
    var start = Math.floor(m / step) * step;
    if (start < free[0]) start = Math.ceil(free[0] / o.increment) * o.increment;
    var end = Math.min(start + o.defaultDuration, free[1], start + (col.maxDuration || o.maxDuration));
    end = Math.floor(end / o.increment) * o.increment;
    if (end <= start) return null;
    return [start, end];
  };

  Calendar.prototype._bind = function () {
    var self = this;
    var drag = null, suppressClick = false;

    function colOf(el) { var i = +$(el).closest('.cal-col').data('i'); return { i: i, col: self.data.columns[i] }; }

    function showBox($box, s, e, label) {
      var r = self.data.range || [0, 1440];
      $box.prop('hidden', false).css({
        top: 'calc(var(--hour) * ' + ((s - r[0]) / 60) + ')',
        height: 'calc(var(--hour) * ' + ((e - s) / 60) + ' - 2px)'
      }).text(label);
    }

    // Header click (e.g. jump from "all spaces" to one space)
    this.$root.on('click', '.cal-colhead', function () {
      var c = self.data.columns[+$(this).data('i')];
      if (c && c.onHead) c.onHead(c);
    });

    // Existing booking click
    this.$root.on('click keydown', '.cal-ev', function (e) {
      if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
      e.stopPropagation();
      var $ev = $(this), key = String($ev.data('col')), start = +$ev.data('start');
      var ev = self._events(key).filter(function (x) { return x.start === start; })[0];
      if (ev && self.opts.onEvent && (ev.clickable || ev.mine)) self.opts.onEvent(ev);
    });
    this.$root.on('mousedown', '.cal-ev', function (e) { e.stopPropagation(); });

    // Hover preview (mouse only)
    this.$root.on('mousemove', '.cal-col', function (e) {
      if (drag || self.opts.readOnly || !self.data) return;
      var $col = $(this), c = colOf(this).col, m = self._minAt($col, e.clientY);
      self.$grid.find('.cal-hover').not($col.find('.cal-hover')).prop('hidden', true);
      var range = $(e.target).closest('.cal-ev').length ? null : self._tapRange(c, m);
      var $h = $col.find('.cal-hover');
      if (!range) { $h.prop('hidden', true); return; }
      showBox($h, range[0], Math.min(range[0] + Math.max(self.opts.tapMinutes, self.opts.increment), range[1]), '+ ' + GPC.time.fmt(range[0]));
    });
    this.$root.on('mouseleave', '.cal-col', function () { $(this).find('.cal-hover').prop('hidden', true); });

    // Drag to select (mouse)
    this.$root.on('mousedown', '.cal-col', function (e) {
      if (e.button !== 0 || self.opts.readOnly || !self.data) return;
      var $col = $(this), c = colOf(this).col, m = self._minAt($col, e.clientY);
      var free = self._freeAround(c, m);
      if (!free) return;
      e.preventDefault();
      var inc = self.opts.increment;
      var anchor = Math.max(free[0], Math.floor(m / inc) * inc);
      drag = { $col: $col, col: c, anchor: anchor, free: free, y0: e.clientY, moved: false, s: anchor, e: anchor + inc };
      $col.find('.cal-hover').prop('hidden', true);
    });
    $(document).on('mousemove.gpccal', function (e) {
      if (!drag) return;
      if (Math.abs(e.clientY - drag.y0) > 6) drag.moved = true;
      if (!drag.moved) return;
      var inc = self.opts.increment, m = self._minAt(drag.$col, e.clientY), maxD = drag.col.maxDuration || self.opts.maxDuration;
      if (m >= drag.anchor) {
        drag.s = drag.anchor;
        drag.e = Math.min(Math.max(Math.ceil(m / inc) * inc, drag.anchor + inc), drag.free[1], drag.anchor + maxD);
      } else {
        drag.e = drag.anchor + inc;
        drag.s = Math.max(Math.floor(m / inc) * inc, drag.free[0], drag.e - maxD);
      }
      showBox(drag.$col.find('.cal-sel'), drag.s, drag.e, GPC.time.range(drag.s, drag.e) + ' · ' + GPC.time.duration(drag.e - drag.s));
    });
    $(document).on('mouseup.gpccal', function (e) {
      if (!drag) return;
      var d = drag; drag = null;
      suppressClick = true;
      setTimeout(function () { suppressClick = false; }, 50);
      d.$col.find('.cal-sel').prop('hidden', true);
      var range = d.moved ? [d.s, d.e] : self._tapRange(d.col, self._minAt(d.$col, e.clientY));
      if (range && self.opts.onSelect) self.opts.onSelect(d.col, range[0], range[1]);
    });

    // Tap (touch / pen / keyboard-less click)
    this.$root.on('click', '.cal-col', function (e) {
      if (suppressClick || self.opts.readOnly || !self.data || $(e.target).closest('.cal-ev').length) return;
      var c = colOf(this).col, range = self._tapRange(c, self._minAt($(this), e.clientY));
      if (range && self.opts.onSelect) self.opts.onSelect(c, range[0], range[1]);
    });
  };

  GPC.Calendar = Calendar;
})(jQuery, window.GPC);
