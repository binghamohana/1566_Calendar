/* Grove Park Collective — public booking app: spaces, calendar, manage reservation. */
(function ($, GPC) {
  'use strict';

  var boot = GPC.boot = JSON.parse(document.getElementById('boot').textContent);
  boot.loadedAt = Date.now();
  var clockBase = { min: boot.now_min, at: Date.now(), today: boot.today };
  boot.nowMin = boot.now_min;

  var spaces = boot.spaces;
  var $app = $('#app');
  var state = { view: null, space: null, date: boot.today, mode: null };
  var cal = null;
  var cache = {};

  // ------------------------------------------------------------------ clock & data

  function syncClock(res) {
    if (res && res.today) { clockBase = { min: res.now_min, at: Date.now(), today: res.today }; }
    var m = clockBase.min + Math.floor((Date.now() - clockBase.at) / 60000);
    boot.today = clockBase.today;
    boot.nowMin = Math.min(m, 1439);
  }
  setInterval(syncClock, 30000);

  function loadRange(from, to, force) {
    var key = from + '|' + to, hit = cache[key];
    if (hit && !force && Date.now() - hit.at < 45000) return Promise.resolve(hit.list);
    return GPC.api('api.php', 'GET', { action: 'bookings', from: from, to: to }).then(function (res) {
      syncClock(res);
      cache[key] = { at: Date.now(), list: res.bookings };
      return res.bookings;
    });
  }
  function loadDay(date) { return loadRange(date, date); }
  function invalidate() { cache = {}; }

  function spaceBySlug(slug) { return spaces.filter(function (s) { return s.slug === slug; })[0] || null; }
  function spaceById(id) { return spaces.filter(function (s) { return s.id === +id; })[0] || null; }
  function mine() { return GPC.store.get('mine', {}); }
  function maxDate() { return GPC.date.addDays(boot.today, Math.max.apply(null, spaces.map(function (s) { return s.max_days_ahead; }))); }

  // ------------------------------------------------------------------ routing

  function route() {
    GPC.closeDialogs();
    if (boot.manage_token) return renderManage();
    var parts = (location.hash.replace(/^#\/?/, '') || '').split('/');
    var date = parts.filter(GPC.date.isValid)[0];
    if (date && date < boot.today) date = boot.today;
    if (parts[0] === 'space' && spaceBySlug(parts[1])) {
      renderCalendar(spaceBySlug(parts[1]), date || state.date || boot.today, parts[3] === 'week' ? 'week' : (parts[3] === 'day' ? 'day' : null));
    } else if (parts[0] === 'calendar') {
      renderCalendar(null, date || state.date || boot.today, 'day');
    } else {
      renderHome();
    }
    $('.topnav a').removeClass('active').filter('[data-nav="' + (parts[0] === 'space' || parts[0] === 'calendar' ? 'calendar' : 'home') + '"]').addClass('active');
  }

  function go(hash) {
    if (location.hash === hash) { route(); } else { location.hash = hash; }
  }

  function calHash(space, date, mode) {
    return space ? '#/space/' + space.slug + '/' + date + (mode ? '/' + mode : '') : '#/calendar/' + date;
  }

  // ------------------------------------------------------------------ home

  function renderHome() {
    state.view = 'home';
    cal = null;
    document.title = boot.tagline + ' · ' + boot.org_name;
    var h = '<div class="wrap">' +
      '<section class="hero">' +
        '<div class="eyebrow">' + GPC.esc(boot.org_name) + '</div>' +
        '<h1>' + GPC.esc(boot.tagline).replace(/(\S+)$/, '<em>$1</em>') + '</h1>' +
        '<p>' + GPC.esc(boot.intro) + '</p>' +
        '<div class="hero-meta">' +
          '<span>' + GPC.icon('door') + spaces.length + ' shared ' + (spaces.length === 1 ? 'space' : 'spaces') + '</span>' +
          '<span>' + GPC.icon('clock') + 'Book in under a minute</span>' +
          '<span>' + GPC.icon('key') + 'No account or password</span>' +
        '</div>' +
      '</section>' +
      '<div class="space-grid"></div>' +
      '<div class="home-strip">' +
        '<div><h2>See everything at a glance</h2><p>Every space side by side for any day.</p></div>' +
        '<div style="display:flex;gap:10px;flex-wrap:wrap">' +
          '<a class="btn btn-ghost" href="#/calendar/' + boot.today + '">' + GPC.icon('cal') + ' All spaces calendar</a>' +
          '<button type="button" class="btn btn-quiet js-find">Find my reservations</button>' +
        '</div>' +
      '</div>' +
    '</div>';
    $app.html(h);
    var $grid = $app.find('.space-grid');
    if (!spaces.length) {
      $grid.replaceWith('<div class="empty"><h2>No spaces yet</h2><p>Check back soon.</p></div>');
      return;
    }
    spaces.forEach(function (s) { $grid.append(spaceCard(s)); });
    loadDay(boot.today).then(function (list) { updateCards(list); }, function () { /* cards still work */ });
  }

  function spaceCard(s) {
    var $c = $('<a class="space-card"></a>').attr('href', '#/space/' + s.slug + '/' + boot.today).attr('data-space', s.id);
    var art = s.photo
      ? '<img src="' + GPC.esc(s.photo) + '" alt="" loading="lazy">'
      : '<div class="ph"><div class="ph-mark">' + GPC.esc(s.name.charAt(0)) + '</div></div>';
    var meta = [s.location, s.capacity ? 'Up to ' + s.capacity + ' people' : null].filter(Boolean).join(' · ');
    $c.html(
      '<div class="space-art" style="--c:' + s.color + '">' + art + '<span class="pill js-status"><span class="dot"></span><span>Checking…</span></span></div>' +
      '<div class="space-card-body">' +
        (meta ? '<div class="eyebrow">' + GPC.esc(meta) + '</div>' : '') +
        '<h3>' + GPC.esc(s.name) + '</h3>' +
        (s.description ? '<p>' + GPC.esc(s.description) + '</p>' : '') +
        (s.amenities.length ? '<div class="amenities">' + s.amenities.map(function (a) { return '<span class="amenity">' + GPC.esc(a) + '</span>'; }).join('') + '</div>' : '') +
        '<div class="today-bar" style="--c:' + s.color + '"><div class="today-bar-head"><strong>Today</strong><span class="js-next"></span></div><div class="tbar"></div><div class="tbar-scale"></div></div>' +
        '<div class="card-cta"><span class="btn">View availability <span class="arrow">→</span></span></div>' +
      '</div>'
    );
    return $c;
  }

  function updateCards(list) {
    var now = boot.nowMin;
    spaces.forEach(function (s) {
      var $c = $app.find('.space-card[data-space="' + s.id + '"]');
      var hrs = GPC.hoursOn(s, boot.today);
      var evs = list.filter(function (b) { return b.space_id === s.id; }).sort(function (a, b) { return a.start_min - b.start_min; });
      var $pill = $c.find('.js-status').removeClass('free busy closed');
      var $next = $c.find('.js-next');
      var status, cls;

      if (!hrs) { status = 'Closed today'; cls = 'closed'; }
      else if (now < hrs[0]) { status = 'Opens ' + GPC.time.fmt(hrs[0]); cls = 'closed'; }
      else if (now >= hrs[1]) { status = 'Closed for the day'; cls = 'closed'; }
      else {
        var cur = evs.filter(function (e) { return e.start_min <= now && e.end_min > now; })[0];
        if (cur) {
          var until = cur.end_min;
          evs.forEach(function (e) { if (e.start_min === until) until = e.end_min; });
          status = until >= hrs[1] ? 'Booked for the rest of the day' : 'In use until ' + GPC.time.fmt(until);
          cls = 'busy';
        } else {
          var nxt = evs.filter(function (e) { return e.start_min > now; })[0];
          status = nxt ? 'Free until ' + GPC.time.fmt(nxt.start_min) : 'Available now';
          cls = 'free';
        }
      }
      $pill.addClass(cls).find('span:last').text(status);
      var upcoming = evs.filter(function (e) { return e.end_min > now; }).length;
      $next.text(upcoming ? upcoming + ' upcoming booking' + (upcoming === 1 ? '' : 's') : (hrs && now < hrs[1] ? 'Wide open' : ''));

      // Mini timeline
      var lo = s.hours && hrs ? hrs[0] : 420, hi = s.hours && hrs ? hrs[1] : 1320;
      if (!s.hours) {
        evs.forEach(function (e) { lo = Math.min(lo, Math.floor(e.start_min / 60) * 60); hi = Math.max(hi, Math.ceil(e.end_min / 60) * 60); });
      }
      var span = Math.max(60, hi - lo), pct = function (m) { return ((Math.min(Math.max(m, lo), hi) - lo) / span * 100).toFixed(2) + '%'; };
      var $bar = $c.find('.tbar').empty();
      if (!hrs) { $bar.append('<div class="seg closed" style="left:0;right:0"></div>'); }
      evs.forEach(function (e) {
        if (e.end_min <= lo || e.start_min >= hi) return;
        $('<div class="seg"></div>').toggleClass('block', e.kind === 'block')
          .css({ left: pct(e.start_min), width: 'calc(' + pct(e.end_min) + ' - ' + pct(e.start_min) + ')' })
          .attr('title', e.label + ' · ' + GPC.time.range(e.start_min, e.end_min)).appendTo($bar);
      });
      if (now > lo) $('<div class="past"></div>').css('width', pct(now)).appendTo($bar);
      if (now >= lo && now <= hi) $('<div class="now"></div>').css('left', pct(now)).appendTo($bar);
      var mid = Math.round((lo + hi) / 2 / 60) * 60;
      $c.find('.tbar-scale').html('<span>' + GPC.time.fmt(lo, true) + '</span><span>' + GPC.time.fmt(mid, true) + '</span><span>' + GPC.time.fmt(hi, true) + '</span>');
    });
  }

  // ------------------------------------------------------------------ calendar

  function renderCalendar(space, date, mode) {
    var wide = GPC.isWide();
    mode = space ? (mode || (wide ? 'week' : 'day')) : 'day';
    if (!wide) mode = 'day';
    var sameView = state.view === 'calendar' && state.space === space && state.mode === mode;
    // Moving between days in the same view keeps your place in the day instead of jumping to midnight.
    var keepTop = sameView && cal ? cal.$scroll.scrollTop() : null;
    state.view = 'calendar'; state.space = space; state.date = date; state.mode = mode;
    document.title = (space ? space.name : 'All spaces') + ' · ' + boot.org_name;

    var $page = $('<div class="wrap cal-page"></div>');
    var $tb = $('<div class="cal-toolbar"></div>').appendTo($page);

    // Space tabs
    var $tabs = $('<div class="space-tabs" role="tablist"></div>');
    $tabs.append($('<button type="button" class="space-tab" role="tab">All spaces</button>').toggleClass('is-on', !space).attr('aria-selected', !space)
      .on('click', function () { go(calHash(null, date)); }));
    spaces.forEach(function (s) {
      $('<button type="button" class="space-tab" role="tab"></button>').toggleClass('is-on', space === s).attr('aria-selected', space === s)
        .html('<i class="sw" style="--c:' + s.color + '"></i>').append(document.createTextNode(s.short_name))
        .on('click', function () { go(calHash(s, date, state.mode === 'week' ? 'week' : null)); }).appendTo($tabs);
    });
    $('<div class="cal-toolbar-row"></div>').append($tabs).appendTo($tb);

    // Date navigation
    var weekStart = GPC.date.weekStart(date);
    var step = mode === 'week' ? 7 : 1;
    var title = mode === 'week' ? GPC.date.weekLabel(weekStart) : GPC.date.long(date) + (date === boot.today ? '' : '');
    var $nav = $('<div class="cal-toolbar-row"></div>');
    var $dn = $('<div class="date-nav"></div>').appendTo($nav);
    var prevDate = GPC.date.addDays(date, -step);
    var canPrev = mode === 'week' ? weekStart > boot.today : date > boot.today;
    $dn.append($('<button type="button" class="btn btn-ghost btn-sm today-btn">Today</button>').prop('disabled', date === boot.today)
      .on('click', function () { go(calHash(space, boot.today, mode === 'week' ? 'week' : null)); }));
    $dn.append($('<span class="nav-arrows"></span>').append(
      $('<button type="button" class="icon-btn" aria-label="Previous">' + GPC.icon('left') + '</button>').prop('disabled', !canPrev).css('opacity', canPrev ? 1 : .35)
        .on('click', function () { go(calHash(space, prevDate < boot.today ? boot.today : prevDate, mode === 'week' ? 'week' : null)); }),
      $('<button type="button" class="icon-btn" aria-label="Next">' + GPC.icon('right') + '</button>')
        .on('click', function () { var n = GPC.date.addDays(date, step); if (n <= maxDate()) go(calHash(space, n, mode === 'week' ? 'week' : null)); })
    ));
    var $dateInput = $('<input type="date" class="date-input-hidden" aria-label="Pick a date">').attr({ min: boot.today, max: maxDate() }).val(date)
      .on('change', function () { if (GPC.date.isValid(this.value)) go(calHash(space, this.value, mode === 'week' ? 'week' : null)); });
    $dn.append($('<div class="date-title"></div>').append(
      $('<button type="button"></button>').text(title).append(GPC.icon('cal')).on('click', function () {
        try { $dateInput[0].showPicker(); } catch (e) { $dateInput.trigger('focus').trigger('click'); }
      }), $dateInput));
    if (space && wide) {
      $('<div class="seg-toggle" role="group" aria-label="View"></div>').append(
        $('<button type="button">Day</button>').toggleClass('is-on', mode === 'day').on('click', function () { go(calHash(space, date, 'day')); }),
        $('<button type="button">Week</button>').toggleClass('is-on', mode === 'week').on('click', function () { go(calHash(space, date, 'week')); })
      ).appendTo($nav);
    }
    $tb.append($nav);

    // Date strip (phones)
    var $strip = $('<div class="date-strip" aria-label="Choose a day"></div>');
    var stripStart = GPC.date.diffDays(boot.today, date) > 20 ? GPC.date.addDays(date, -3) : boot.today;
    for (var i = 0; i < 28; i++) {
      var d = GPC.date.addDays(stripStart, i);
      if (d > maxDate()) break;
      $('<button type="button" class="date-chip"></button>').toggleClass('is-on', d === date).toggleClass('is-today', d === boot.today)
        .attr('aria-label', GPC.date.long(d)).attr('data-date', d)
        .html('<span class="dw">' + (d === boot.today ? 'Today' : GPC.date.dayName(d, 3)) + '</span><span class="dn">' + GPC.date.dayNum(d) + '</span>')
        .on('click', function () { go(calHash(space, $(this).data('date'))); }).appendTo($strip);
    }
    $tb.append($strip);

    // Space info + legend
    var $info = $('<div class="space-info"></div>');
    if (space) {
      $info.append($('<strong></strong>').text(space.name), $('<span class="si-meta"></span>').text(
        [space.location, space.capacity ? 'Up to ' + space.capacity + ' people' : null, GPC.hoursSummary(space)].filter(Boolean).join(' · ')));
    }
    var $legend = $('<div class="legend"><span><i class="l-free"></i>Open</span><span><i class="l-res"></i>Reserved</span>' +
      (boot.rules.require_approval ? '<span><i class="l-pending"></i>Pending</span>' : '') +
      '<span><i class="l-mine"></i>Yours</span><span><i class="l-na"></i>Unavailable</span></div>');
    $legend.append($('<span class="cal-hint"></span>').text('Click an open time — or drag — to reserve'));
    $info.append($('<span style="flex:1"></span>'), $legend);
    $page.append($info);

    var $cal = $('<div></div>').appendTo($page);
    $page.append($('<button type="button" class="btn fab">' + GPC.icon('plus') + ' Reserve</button>').on('click', function () { quickBook(); }));
    $app.empty().append($page);
    $strip.find('.is-on')[0] && $strip.find('.is-on')[0].scrollIntoView({ inline: 'center', block: 'nearest' });

    cal = new GPC.Calendar($cal, {
      increment: +boot.rules.time_increment,
      defaultDuration: 60,
      maxDuration: +boot.rules.max_duration_minutes,
      onSelect: function (col, s, e) {
        GPC.BookingForm.open({ mode: 'create', spaceId: col.spaceId, date: col.date, start: s, end: e, loadDay: loadDay, onDone: afterChange, onConflict: refresh });
      },
      onEvent: function (ev) {
        if (ev.mine) location.href = 'manage.php?t=' + encodeURIComponent(ev.mine);
      }
    });
    var from = mode === 'week' ? weekStart : date, to = mode === 'week' ? GPC.date.addDays(weekStart, 6) : date;
    state.range = [from, to];
    drawCalendar(true, keepTop === null ? true : keepTop);
  }

  function columnsFor() {
    var space = state.space, cols = [];
    if (!space) {
      spaces.forEach(function (s) {
        cols.push({
          key: 's' + s.id, spaceId: s.id, date: state.date, title: s.short_name, color: s.color,
          sub: s.capacity ? 'Up to ' + s.capacity : (s.location || ''), hours: GPC.hoursOn(s, state.date), maxDuration: s.max_duration_minutes,
          onHead: function () { go(calHash(s, state.date)); }
        });
      });
    } else {
      var days = state.mode === 'week' ? 7 : 1, start = state.mode === 'week' ? state.range[0] : state.date;
      for (var i = 0; i < days; i++) {
        var d = GPC.date.addDays(start, i);
        cols.push({
          key: d, spaceId: space.id, date: d, isDay: true, title: String(GPC.date.dayNum(d)), sub: GPC.date.dayName(d, 3),
          hours: GPC.hoursOn(space, d), maxDuration: space.max_duration_minutes,
          onHead: state.mode === 'week' ? (function (dd) { return function () { go(calHash(space, dd, 'day')); }; })(d) : null
        });
      }
    }
    return cols;
  }

  /** scroll: true = jump to a sensible time, false = keep position, a number = restore that scrollTop. */
  function drawCalendar(fetch, scroll) {
    if (!cal) return;
    var cols = columnsFor(), myRefs = mine();
    var paint = function (list) {
      var events = [];
      list.forEach(function (b) {
        var col = state.space
          ? (b.space_id === state.space.id ? cols.filter(function (c) { return c.date === b.date; })[0] : null)
          : (b.date === state.date ? cols.filter(function (c) { return c.spaceId === b.space_id; })[0] : null);
        if (!col) return;
        var m = myRefs[b.ref];
        var sp = spaceById(b.space_id);
        events.push({
          col: col.key, start: b.start_min, end: b.end_min, kind: b.kind,
          title: m ? (b.pending ? 'Your request · awaiting approval' : 'Your reservation') : (b.pending ? 'Pending · ' + b.label : b.label),
          mine: m ? m.t : null, pending: b.pending, color: sp ? sp.color : null
        });
      });
      // Visible hours: the union of opening hours (always-open spaces show the full day).
      var lo = 1440, hi = 0;
      cols.forEach(function (c) { if (c.hours) { lo = Math.min(lo, c.hours[0]); hi = Math.max(hi, c.hours[1]); } });
      events.forEach(function (e) { lo = Math.min(lo, e.start); hi = Math.max(hi, e.end); });
      if (hi <= lo) { lo = 420; hi = 1320; }
      lo = Math.floor(lo / 60) * 60; hi = Math.ceil(hi / 60) * 60;
      cal.render({
        columns: cols, events: events, today: boot.today, nowMin: boot.nowMin, range: [lo, hi],
        colMin: state.space ? (state.mode === 'week' ? 0 : 0) : (cols.length > 3 && GPC.isMobile() ? 110 : 0)
      }, scroll === false);
      if (typeof scroll === 'number') {
        cal.$scroll.scrollTop(scroll);
      } else if (scroll) {
        var showsToday = cols.some(function (c) { return c.date === boot.today; });
        cal.scrollToMin(Math.max(lo, showsToday ? Math.min(boot.nowMin - 60, 17 * 60) : 7 * 60));
      }
    };
    if (!fetch) return;
    cal.loading(true);
    loadRange(state.range[0], state.range[1], true).then(function (list) {
      cal.loading(false); paint(list);
    }, function (err) {
      cal.loading(false); paint([]);
      GPC.toast(err.message, 'error');
    });
  }

  function refresh() {
    if (state.view === 'calendar') drawCalendar(true, false);
    else if (state.view === 'home') { invalidate(); loadDay(boot.today).then(updateCards); }
    else if (state.view === 'manage') loadManage(false);
  }

  function afterChange() { invalidate(); refresh(); }

  /** The floating "Reserve" button: next open slot in this space (or the first space). */
  function quickBook() {
    var space = state.space || spaces[0];
    var date = state.date;
    loadDay(date).then(function (list) {
      var hrs = GPC.hoursOn(space, date) || [0, 1440];
      var inc = +boot.rules.time_increment;
      var start = Math.max(hrs[0], date === boot.today ? Math.ceil(boot.nowMin / 30) * 30 : Math.max(hrs[0], 9 * 60));
      var evs = list.filter(function (b) { return b.space_id === space.id; }).sort(function (a, b) { return a.start_min - b.start_min; });
      evs.forEach(function (e) { if (e.start_min < start + 60 && e.end_min > start) start = Math.ceil(e.end_min / inc) * inc; });
      var end = Math.min(start + 60, hrs[1]);
      GPC.BookingForm.open({ mode: 'create', spaceId: space.id, date: date, start: start < hrs[1] ? start : null, end: start < hrs[1] ? end : null, loadDay: loadDay, onDone: afterChange, onConflict: refresh });
    });
  }

  // ------------------------------------------------------------------ manage

  var managed = null;

  function renderManage() {
    state.view = 'manage';
    document.title = 'Your reservation · ' + boot.org_name;
    $app.html('<div class="wrap manage"><div class="manage-card"><div class="muted"><span class="spinner"></span> Loading your reservation…</div></div></div>');
    loadManage(true);
  }

  function loadManage(first) {
    GPC.api('api.php', 'GET', { action: 'manage', t: boot.manage_token }).then(function (res) {
      managed = res.booking;
      GPC.rememberMine(managed);
      drawManage(first);
    }, function (err) {
      $app.html('<div class="wrap manage"><div class="empty"><h2>Reservation not found</h2><p></p><p><a class="btn" href="./">Go to the booking page</a></p></div></div>');
      $app.find('.empty p:first').text(err.message);
    });
  }

  function drawManage(first) {
    var b = managed, space = spaceById(b.space_id) || { name: b.space_name, color: '#3E5C4A', hours: null };
    var badge = b.status === 'cancelled' ? '<span class="badge cancelled">Cancelled</span>'
      : (b.status === 'pending' ? '<span class="badge pending">Awaiting approval</span>'
      : (b.is_past ? '<span class="badge past">Completed</span>' : '<span class="badge ok">' + GPC.icon('check').replace('<svg', '<svg width="12" height="12"') + ' Confirmed</span>'));
    var $card = $('<div class="manage-card"></div>');
    $card.append($('<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"></div>').append(
      $('<span class="eyebrow"></span>').text('Your reservation · Ref ' + b.ref), badge));
    $card.append($('<h1></h1>').text(b.space_name));
    $card.append($('<div class="manage-when"></div>').text(GPC.date.long(b.date, true) + ' · ' + GPC.time.range(b.start_min, b.end_min) + ' (' + GPC.time.duration(b.end_min - b.start_min) + ')'));
    var $grid = $('<div class="manage-grid"></div>');
    [['Reserved by', b.name], ['Email', b.email], ['Title', b.title], ['Company', b.company], ['Notes', b.notes],
      ['Others see', '“' + b.public_label + '”']].forEach(function (r) {
      if (r[1]) $grid.append($('<div></div>').append($('<span></span>').text(r[0]), $('<strong></strong>').text(r[1])));
    });
    $card.append($grid);

    var $acts = $('<div class="manage-actions"></div>');
    if (b.status === 'pending') {
      $card.append($('<div class="alert alert-warn"></div>').text('Waiting for building management to approve ' + b.email +
        '. Your time is held for you, and you’ll get a confirmation email as soon as it’s approved. New addresses only need approving once.'));
    }
    if (b.status === 'cancelled') {
      $card.append('<div class="alert alert-info">This reservation was cancelled, and the time is open for others.</div>');
      $acts.append('<a class="btn" href="./">Make a new reservation</a>');
    } else if (b.is_past) {
      $acts.append('<a class="btn" href="./">Book again</a>');
    } else {
      if (b.can_modify) {
        $acts.append($('<button type="button" class="btn">Change time or room</button>').on('click', function () { editManaged(); }));
      } else {
        $card.append('<div class="alert alert-info">This reservation is in progress. To make changes now, please contact management.</div>');
      }
      if (b.status !== 'pending') {
        $acts.append($('<a class="btn btn-ghost" download></a>').attr('href', 'ics.php?t=' + encodeURIComponent(b.manage_token)).html(GPC.icon('cal') + ' Add to calendar'));
      }
      if (b.can_cancel) {
        $acts.append($('<button type="button" class="btn btn-danger-ghost"></button>').text(b.status === 'pending' ? 'Cancel request' : 'Cancel reservation').on('click', cancelManaged));
      }
    }
    $card.append($acts);

    var $wrap = $('<div class="wrap manage"></div>').append($card);
    if (b.status === 'confirmed' && b.can_modify) {
      $wrap.append($('<h2 class="section-title"></h2>').text('Availability on ' + GPC.date.long(b.date)));
      $wrap.append($('<p class="muted small" style="margin:-6px 0 12px">Pick an open time to move your reservation.</p>'));
      var $cal = $('<div></div>').appendTo($wrap);
      $app.empty().append($wrap);
      cal = new GPC.Calendar($cal, {
        increment: +boot.rules.time_increment,
        maxDuration: space.max_duration_minutes || +boot.rules.max_duration_minutes,
        defaultDuration: b.end_min - b.start_min,
        onSelect: function (col, s, e) { editManaged(s, e); }
      });
      loadDay(b.date).then(function (list) {
        var events = list.filter(function (x) { return x.space_id === b.space_id; }).map(function (x) {
          var isThis = x.ref === b.ref;
          return { col: 'c', start: x.start_min, end: x.end_min, kind: x.kind, title: isThis ? 'This reservation' : x.label, mine: isThis ? null : null, current: isThis, color: space.color };
        });
        var hrs = GPC.hoursOn(space, b.date);
        var lo = hrs ? Math.floor(hrs[0] / 60) * 60 : 0, hi = hrs ? Math.ceil(hrs[1] / 60) * 60 : 1440;
        cal.render({ columns: [{ key: 'c', spaceId: b.space_id, date: b.date, title: space.name, color: space.color, hours: hrs }], events: events, today: boot.today, nowMin: boot.nowMin, range: [lo, hi] });
        cal.scrollToMin(Math.max(lo, b.start_min - 90));
      });
    } else {
      $app.empty().append($wrap);
      cal = null;
    }
    if (first && b.status === 'confirmed') history.replaceState(null, '', location.pathname + location.search);
  }

  function editManaged(start, end) {
    GPC.BookingForm.open({
      mode: 'edit', booking: managed, token: boot.manage_token,
      spaceId: managed.space_id, date: managed.date,
      start: start != null ? start : managed.start_min, end: end != null ? end : managed.end_min,
      loadDay: loadDay,
      onDone: function (bk) { managed = bk; invalidate(); drawManage(false); },
      onConflict: function () { invalidate(); }
    });
  }

  function cancelManaged() {
    GPC.confirm({
      title: 'Cancel this reservation?',
      message: managed.space_name + ' · ' + GPC.date.long(managed.date) + ', ' + GPC.time.range(managed.start_min, managed.end_min) + '. The time will open up for others right away.',
      ok: 'Yes, cancel it', cancel: 'Keep it', danger: true
    }).then(function (yes) {
      if (!yes) return;
      GPC.api('api.php?action=cancel', 'POST', { t: boot.manage_token }).then(function (res) {
        managed = res.booking;
        GPC.rememberMine(managed);
        invalidate();
        drawManage(false);
        GPC.toast('Reservation cancelled. We’ve emailed you a confirmation.');
      }, function (err) { GPC.toast(err.message, 'error'); });
    });
  }

  // ------------------------------------------------------------------ find my reservations

  function findDialog() {
    var $f = $('<form novalidate>' +
      '<p style="margin:0 0 16px;color:var(--ink-2)">Enter the email you booked with and we’ll send private links to change or cancel each upcoming reservation.</p>' +
      '<div class="field"><label for="fd-email">Email</label><input class="input" type="email" id="fd-email" name="email" autocomplete="email" inputmode="email" required></div>' +
      '</form>');
    $f.find('[name=email]').val((GPC.store.get('me', {}) || {}).email || '');
    var $btn = $('<button type="submit" class="btn btn-block">Email my reservations</button>');
    var d = GPC.dialog({ title: 'Find my reservations', body: $f, foot: $btn });
    $btn.on('click', function (e) { e.preventDefault(); $f.trigger('submit'); });
    $f.on('submit', function (e) {
      e.preventDefault();
      var email = $.trim($f.find('[name=email]').val());
      GPC.formErrors($f, null);
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return GPC.formErrors($f, { field: 'email', message: 'Please enter a valid email address.' });
      $btn.addClass('is-busy').html('<span class="spinner"></span> Sending…');
      GPC.api('api.php?action=send_links', 'POST', { email: email }).then(function () {
        d.$body.html('<div class="confirm"><div class="confirm-icon">' + GPC.icon('mail') + '</div><h3>Check your inbox.</h3><p></p></div>');
        d.$body.find('p').text('If ' + email + ' has upcoming reservations, links to manage them are on the way. It can take a minute — check spam too.');
        d.$foot.empty().append($('<button type="button" class="btn btn-block">Done</button>').on('click', d.close));
      }, function (err) {
        $btn.removeClass('is-busy').text('Email my reservations');
        GPC.formErrors($f, err);
      });
    });
  }

  // ------------------------------------------------------------------ refresh-during-booking recovery

  function recoverPending() {
    var p = GPC.store.get('pending');
    if (!p || !p.request_id) return;
    if (Date.now() - p.at > 15 * 60000) { GPC.store.del('pending'); return; }
    GPC.api('api.php', 'GET', { action: 'recover', request_id: p.request_id }).then(function (res) {
      GPC.store.del('pending');
      if (!res.booking) return;
      GPC.rememberMine(res.booking);
      var b = res.booking;
      GPC.toast('Good news — your reservation for ' + b.space_name + ' on ' + GPC.date.short(b.date) + ' went through.');
      refresh();
    }, function () { /* try again on next load */ });
  }

  // ------------------------------------------------------------------ init

  $(document).on('click', '.js-find', function (e) { e.preventDefault(); findDialog(); });
  $(window).on('hashchange', route);
  var lastWide = GPC.isWide();
  $(window).on('resize', function () {
    var w = GPC.isWide();
    if (w !== lastWide && state.view === 'calendar') { lastWide = w; route(); }
    lastWide = w;
  });
  setInterval(function () { if (document.visibilityState === 'visible' && !$('.overlay').length) refresh(); }, 60000);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && !$('.overlay').length) refresh(); });

  route();
  recoverPending();
})(jQuery, window.GPC);
