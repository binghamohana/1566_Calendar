/* Grove Park Collective — admin app. */
(function ($, GPC) {
  'use strict';

  var boot = GPC.boot = JSON.parse(document.getElementById('boot').textContent);
  var S = { admin: null, csrf: '', spaces: [], settings: {}, today: null, nowMin: 0, approvalsPending: 0 };
  var $root = $('#admin-app');
  var $content = null;
  var esc = GPC.esc;

  // ------------------------------------------------------------------ API

  function authFail(err) {
    if (err.status === 401) { showLogin(); }
    else if (err.code === 'csrf') { GPC.toast('Your session expired — reloading…', 'error'); setTimeout(function () { location.reload(); }, 1200); }
    throw err;
  }
  function get(action, params) { return GPC.api('api.php', 'GET', $.extend({ action: action }, params || {})).catch(authFail); }
  function post(action, data) { return GPC.api('api.php?action=' + action, 'POST', data || {}, { 'X-CSRF-Token': S.csrf }).catch(authFail); }
  function fail(err) { if (err && err.status !== 401) GPC.toast(err.message, 'error'); }

  function spaceById(id) { return S.spaces.filter(function (s) { return s.id === +id; })[0] || null; }
  function activeSpaces() { return S.spaces.filter(function (s) { return s.is_active; }); }
  function localDateTime(utc) {
    if (!utc) return '';
    var d = new Date(utc.replace(' ', 'T') + 'Z');
    try { return d.toLocaleString('en-US', { timeZone: boot.timezone, month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }); }
    catch (e) { return utc + ' UTC'; }
  }
  function when(b) { return GPC.date.short(b.date) + ' · ' + GPC.time.range(b.start_min, b.end_min); }
  function who(b) {
    if (b.kind === 'block') return 'Blocked' + (b.title ? ': ' + b.title : '');
    return (b.name || 'Reservation') + (b.company ? ' · ' + b.company : '');
  }

  // ------------------------------------------------------------------ boot & sign in

  function init() {
    get('me').then(function (res) { applyMe(res); renderShell(); route(); }, function () { /* login shown */ });
  }

  function applyMe(res) {
    S.admin = res.admin; S.csrf = res.csrf; S.spaces = res.spaces; S.settings = res.settings;
    S.today = res.today; S.nowMin = res.now_min;
    S.approvalsPending = res.approvals_pending || 0;
    updateBadge();
  }

  function updateBadge() {
    $root.find('[data-nav="approvals"] .nav-badge').remove();
    if (S.approvalsPending > 0) $root.find('[data-nav="approvals"]').append($('<span class="nav-badge"></span>').text(S.approvalsPending));
  }

  function refreshMe() { return get('me').then(applyMe); }

  function showLogin() {
    $content = null;
    var $card = $('<div class="login"><div class="login-card">' +
      '<a class="brand" href="../"><img src="../assets/img/logo-mark.svg" alt=""><span class="brand-name">' + boot.brand_html + '</span></a>' +
      '<h1>Management</h1><p>Sign in to manage spaces and reservations.</p>' +
      '<form novalidate>' +
        '<div class="field"><label for="li-email">Email</label><input class="input" type="email" id="li-email" name="email" autocomplete="username" required></div>' +
        '<div class="field"><label for="li-pass">Password</label><input class="input" type="password" id="li-pass" name="password" autocomplete="current-password" required></div>' +
        '<button class="btn btn-block" type="submit">Sign in</button>' +
      '</form></div></div>');
    $root.empty().append($card);
    var $f = $card.find('form');
    $f.find('[name=email]').trigger('focus');
    $f.on('submit', function (e) {
      e.preventDefault();
      var $b = $f.find('button').addClass('is-busy').html('<span class="spinner"></span> Signing in…');
      GPC.formErrors($f, null);
      GPC.api('api.php?action=login', 'POST', { email: $f.find('[name=email]').val(), password: $f.find('[name=password]').val() }).then(function (res) {
        S.csrf = res.csrf;
        return refreshMe().then(function () { renderShell(); route(); });
      }).catch(function (err) {
        $b.removeClass('is-busy').text('Sign in');
        GPC.formErrors($f, err);
      });
    });
  }

  // ------------------------------------------------------------------ shell & routing

  var NAV = [
    ['dashboard', 'Dashboard', 'spark'], ['calendar', 'Calendar', 'cal'], ['reservations', 'Reservations', 'clock'],
    ['approvals', 'Approved emails', 'check'], ['spaces', 'Spaces', 'door'], ['settings', 'Settings', 'key'], ['emails', 'Email log', 'mail'], ['admins', 'Administrators', 'users']
  ];

  function renderShell() {
    var side = '<aside class="side"><a class="brand" href="#/dashboard"><img src="../assets/img/logo-mark.svg" alt=""><span class="brand-name">' + boot.brand_html + '</span></a>' +
      '<div class="side-label">Management</div>';
    var mob = '<nav class="mobile-nav"><img src="../assets/img/logo-mark.svg" alt="">';
    NAV.forEach(function (n) {
      side += '<a class="nav" href="#/' + n[0] + '" data-nav="' + n[0] + '">' + GPC.icon(n[2]) + n[1] + '</a>';
      mob += '<a href="#/' + n[0] + '" data-nav="' + n[0] + '">' + n[1] + '</a>';
    });
    mob += '<a href="../" target="_blank" rel="noopener">Public page ↗</a></nav>';
    side += '<div class="side-foot"><div><strong>' + esc(S.admin.name) + '</strong>' + esc(S.admin.email) + '</div>' +
      '<div class="links"><a href="../" target="_blank" rel="noopener">Public page ↗</a><button type="button" class="js-password">Password</button><button type="button" class="js-logout">Sign out</button></div></div></aside>';
    $root.html('<div class="shell">' + side + '<div>' + mob + '<main class="content"></main></div></div>');
    $content = $root.find('.content');
    updateBadge();
  }

  $(document).on('click', '.js-logout', function () {
    GPC.api('api.php?action=logout', 'POST', {}).finally(function () { location.hash = ''; showLogin(); });
  });
  $(document).on('click', '.js-password', function () { passwordDialog(); });

  function route() {
    if (!$content) return;
    GPC.closeDialogs();
    var parts = location.hash.replace(/^#\/?/, '').split('/');
    var view = parts[0] || 'dashboard';
    $root.find('[data-nav]').removeClass('active').filter('[data-nav="' + (view === 'booking' ? 'reservations' : view) + '"]').addClass('active');
    window.scrollTo(0, 0);
    switch (view) {
      case 'calendar': return viewCalendar(parts[1], parts[2], parts[3]);
      case 'reservations': return viewReservations();
      case 'booking': viewReservations(); return openBooking(+parts[1]);
      case 'approvals': return viewApprovals();
      case 'spaces': return viewSpaces();
      case 'settings': return viewSettings();
      case 'emails': return viewEmails();
      case 'admins': return viewAdmins();
      default: return viewDashboard();
    }
  }
  $(window).on('hashchange', route);

  function pageHead(title, $actions) {
    var $h = $('<div class="page-head"><h1></h1><div class="actions"></div></div>');
    $h.find('h1').text(title);
    if ($actions) $h.find('.actions').append($actions);
    return $h;
  }

  function newButtons() {
    return [
      $('<button type="button" class="btn btn-sm">' + GPC.icon('plus') + ' New reservation</button>').on('click', function () { bookingForm({ kind: 'reservation' }); }),
      $('<button type="button" class="btn btn-ghost btn-sm">Block time</button>').on('click', function () { bookingForm({ kind: 'block' }); })
    ];
  }

  // ------------------------------------------------------------------ dashboard

  function viewDashboard() {
    $content.empty().append(pageHead('Dashboard', newButtons()), '<div class="muted"><span class="spinner"></span></div>');
    get('dashboard').then(function (d) {
      var $k = $('<div class="kpis"></div>');
      var kpi = function (label, value, foot, small) {
        return $('<div class="kpi"><div class="k-label"></div><div class="k-value"></div><div class="k-foot"></div></div>')
          .find('.k-label').text(label).end().find('.k-value').text(value).toggleClass('small-text', !!small).end().find('.k-foot').text(foot || '').end();
      };
      $k.append(
        kpi('Today', d.today_count, d.today_count === 1 ? 'reservation' : 'reservations'),
        kpi('This week', d.week.count, d.week.hours + ' hours booked'),
        kpi(d.month.label, d.month.count, d.month.hours + ' hours · ' + d.month.cancelled + ' cancelled'),
        kpi('Most-used space', d.month.top_space || '—', 'this month', true)
      );

      var $up = $('<div class="panel"><h2>Upcoming <a href="#/reservations">See all</a></h2><div class="list"></div></div>');
      if (!d.upcoming.length) $up.find('.list').append('<div class="muted small">Nothing booked yet.</div>');
      d.upcoming.forEach(function (b) { $up.find('.list').append(listItem(b)); });

      var $can = $('<div class="panel"><h2>Recent cancellations</h2><div class="list"></div></div>');
      if (!d.cancellations.length) $can.find('.list').append('<div class="muted small">None.</div>');
      d.cancellations.forEach(function (b) { $can.find('.list').append(listItem(b, 'Cancelled ' + localDateTime(b.cancelled_at))); });

      var $use = $('<div class="panel"><h2>Hours booked · ' + esc(d.month.label) + '</h2><div class="bars"></div></div>');
      var max = Math.max.apply(null, d.month.by_space.map(function (s) { return s.hours; }).concat([1]));
      d.month.by_space.forEach(function (s) {
        $use.find('.bars').append($('<div class="bar-row"></div>').css('--c', s.color).append(
          $('<span></span>').text(s.name), $('<span class="v"></span>').text(s.hours + ' h · ' + s.count),
          $('<div class="bar"><i></i></div>').find('i').css('width', (s.hours / max * 100) + '%').end()));
      });

      var $health = $('<div class="panel"><h2>System health</h2></div>').append(healthList(d.health));
      var $approvals = $();
      if (d.approvals.length) {
        $approvals = $('<div class="panel" style="margin-bottom:18px"><h2>Waiting for your approval <a href="#/approvals">Approved emails</a></h2></div>');
        d.approvals.forEach(function (a) { $approvals.append(approvalCard(a, viewDashboard)); });
      }
      S.approvalsPending = d.approvals.length; updateBadge();
      $content.empty().append(pageHead('Dashboard', newButtons()), $approvals, $k,
        $('<div class="grid-3"></div>').append($('<div></div>').append($up, $can), $('<div></div>').append($use, $health)));
    }, fail);
  }

  function listItem(b, extra) {
    var sp = spaceById(b.space_id) || {};
    var $i = $('<div class="list-item" role="button" tabindex="0"></div>');
    $i.append($('<div class="li-date"></div>').append($('<div class="m"></div>').text(GPC.date.dayName(b.date, 3)), $('<div class="d"></div>').text(GPC.date.dayNum(b.date))));
    $i.append($('<div class="li-body"></div>').append(
      $('<div class="li-main"></div>').text(who(b) + (b.title && b.kind !== 'block' ? ' — ' + b.title : '')),
      $('<div class="li-sub"></div>').html('<i class="sw-dot" style="--c:' + (sp.color || '#999') + '"></i>').append(document.createTextNode(
        (sp.short_name || sp.name || '') + ' · ' + GPC.date.monthDay(b.date) + ', ' + GPC.time.range(b.start_min, b.end_min) + (extra ? ' · ' + extra : '')))));
    $i.on('click keydown', function (e) { if (e.type === 'click' || e.key === 'Enter') openBooking(b.id); });
    return $i;
  }

  function healthList(h) {
    var $l = $('<div class="health"></div>');
    var row = function (cls, title, text) {
      $l.append($('<div class="health-row"></div>').addClass(cls).append('<span class="dot"></span>', $('<div></div>').append($('<strong></strong>').text(title), text ? $('<span></span>').text(' — ' + text) : '')));
    };
    if (h.cron_ok) row('ok', 'Background tasks running', 'last run ' + h.cron_minutes_ago + ' min ago');
    else row('bad', 'Background tasks not running', h.cron_minutes_ago == null ? 'cron has never run, so reminders won’t send. See docs/DEPLOYMENT.md.' : 'last run ' + h.cron_minutes_ago + ' min ago. Check the cron job.');
    if (h.mail_transport === 'log') row('warn', 'Email is in test mode', 'messages are written to storage/logs/mail.log, not sent. Configure SMTP in config.php.');
    else row('ok', 'Email sending via ' + h.mail_transport.toUpperCase());
    if (h.emails_failed) row('bad', h.emails_failed + ' email' + (h.emails_failed === 1 ? '' : 's') + ' failed', 'see the Email log.');
    if (h.emails_pending > 5) row('warn', h.emails_pending + ' emails waiting to send');
    if (h.approvals_pending) row('warn', h.approvals_pending + ' email address' + (h.approvals_pending === 1 ? '' : 'es') + ' waiting for approval', 'see Approved emails.');
    return $l;
  }

  // ------------------------------------------------------------------ calendar

  var cal = null;

  function viewCalendar(date, slug, mode) {
    date = GPC.date.isValid(date) ? date : S.today;
    var space = slug && slug !== 'all' ? S.spaces.filter(function (s) { return s.slug === slug; })[0] : null;
    mode = space && mode === 'week' ? 'week' : 'day';
    var hash = function (d, sp, m) { return '#/calendar/' + d + '/' + (sp ? sp.slug : 'all') + (m === 'week' ? '/week' : ''); };
    var step = mode === 'week' ? 7 : 1;
    var weekStart = GPC.date.weekStart(date);

    var $tb = $('<div class="cal-toolbar"></div>');
    var $tabs = $('<div class="space-tabs"></div>');
    $tabs.append($('<button type="button" class="space-tab">All spaces</button>').toggleClass('is-on', !space).on('click', function () { location.hash = hash(date, null); }));
    S.spaces.forEach(function (s) {
      $('<button type="button" class="space-tab"></button>').toggleClass('is-on', space === s).html('<i class="sw" style="--c:' + s.color + '"></i>')
        .append(document.createTextNode((s.short_name || s.name) + (s.is_active ? '' : ' (off)')))
        .on('click', function () { location.hash = hash(date, s, mode); }).appendTo($tabs);
    });
    $tb.append($('<div class="cal-toolbar-row"></div>').append($tabs));
    var $di = $('<input type="date" class="date-input-hidden">').val(date).on('change', function () { if (GPC.date.isValid(this.value)) location.hash = hash(this.value, space, mode); });
    var $nav = $('<div class="cal-toolbar-row"></div>').append($('<div class="date-nav"></div>').append(
      $('<button type="button" class="btn btn-ghost btn-sm">Today</button>').on('click', function () { location.hash = hash(S.today, space, mode); }),
      $('<span class="nav-arrows"></span>').append(
        $('<button type="button" class="icon-btn" aria-label="Previous">' + GPC.icon('left') + '</button>').on('click', function () { location.hash = hash(GPC.date.addDays(date, -step), space, mode); }),
        $('<button type="button" class="icon-btn" aria-label="Next">' + GPC.icon('right') + '</button>').on('click', function () { location.hash = hash(GPC.date.addDays(date, step), space, mode); })),
      $('<div class="date-title"></div>').append($('<button type="button"></button>').text(mode === 'week' ? GPC.date.weekLabel(weekStart) : GPC.date.long(date, true)).append(GPC.icon('cal'))
        .on('click', function () { try { $di[0].showPicker(); } catch (e) { $di.trigger('focus'); } }), $di)
    ));
    if (space) {
      $nav.append($('<div class="seg-toggle"></div>').css('display', 'inline-flex').append(
        $('<button type="button">Day</button>').toggleClass('is-on', mode === 'day').on('click', function () { location.hash = hash(date, space, 'day'); }),
        $('<button type="button">Week</button>').toggleClass('is-on', mode === 'week').on('click', function () { location.hash = hash(date, space, 'week'); })));
    }
    $tb.append($nav);

    var $cal = $('<div></div>');
    $content.empty().append(pageHead('Calendar', newButtons()), $tb,
      $('<p class="muted small" style="margin:-4px 0 12px">Click an open time to create a reservation or block; click a booking to view, edit or cancel it. Admins can book outside the public rules.</p>'), $cal);

    var cols = [];
    if (space) {
      for (var i = 0; i < (mode === 'week' ? 7 : 1); i++) {
        var d = mode === 'week' ? GPC.date.addDays(weekStart, i) : date;
        cols.push({ key: d, spaceId: space.id, date: d, isDay: true, title: String(GPC.date.dayNum(d)), sub: GPC.date.dayName(d, 3), hours: [0, 1440] });
      }
    } else {
      S.spaces.forEach(function (s) {
        cols.push({ key: 's' + s.id, spaceId: s.id, date: date, title: s.short_name || s.name, color: s.color, sub: s.is_active ? '' : 'Not bookable', hours: [0, 1440],
          onHead: function () { location.hash = hash(date, s); } });
      });
    }
    cal = new GPC.Calendar($cal, {
      increment: 15, allowPast: true, defaultDuration: 60, maxDuration: 1440,
      onSelect: function (col, s, e) { bookingForm({ kind: 'reservation', space_id: col.spaceId, date: col.date, start: s, end: e }); },
      onEvent: function (ev) { openBooking(ev.data.id); }
    });
    var from = mode === 'week' ? weekStart : date, to = mode === 'week' ? GPC.date.addDays(weekStart, 6) : date;
    cal.loading(true);
    get('calendar', { from: from, to: to }).then(function (res) {
      cal.loading(false);
      var events = [];
      res.bookings.forEach(function (b) {
        var col = space ? (b.space_id === space.id ? cols.filter(function (c) { return c.date === b.date; })[0] : null)
          : (b.date === date ? cols.filter(function (c) { return c.spaceId === b.space_id; })[0] : null);
        if (!col) return;
        var sp = spaceById(b.space_id);
        events.push({ col: col.key, start: b.start_min, end: b.end_min, kind: b.kind, title: (b.status === 'pending' ? 'Pending: ' : '') + who(b),
          sub: b.kind === 'block' ? '' : (b.title || ''), pending: b.status === 'pending', color: sp ? sp.color : null, clickable: true, data: b });
      });
      cal.render({ columns: cols, events: events, today: res.today, nowMin: res.now_min, range: [0, 1440], colMin: cols.length > 4 ? 140 : 0 });
      cal.scrollToMin(date === res.today ? Math.max(0, Math.min(res.now_min - 60, 17 * 60)) : 7 * 60);
    }, function (err) { cal.loading(false); fail(err); });
  }

  // ------------------------------------------------------------------ reservations list

  var listState = { q: '', when: 'upcoming', space_id: '', status: '', kind: '', page: 1 };

  function viewReservations() {
    var $f = $('<div class="filters"></div>');
    var $q = $('<input class="input search" type="search" placeholder="Search name, email, company, title or reference">').val(listState.q);
    var sel = function (name, opts) {
      var $s = $('<select class="select"></select>').attr('name', name);
      opts.forEach(function (o) { $s.append($('<option></option>').val(o[0]).text(o[1])); });
      return $s.val(listState[name]);
    };
    var $when = sel('when', [['upcoming', 'Upcoming'], ['past', 'Past'], ['all', 'All dates']]);
    var $space = sel('space_id', [['', 'All spaces']].concat(S.spaces.map(function (s) { return [String(s.id), s.name]; })));
    var $status = sel('status', [['', 'Any status'], ['confirmed', 'Confirmed'], ['pending', 'Awaiting approval'], ['cancelled', 'Cancelled']]);
    var $kind = sel('kind', [['', 'Reservations & blocks'], ['reservation', 'Reservations'], ['block', 'Blocks']]);
    $f.append($q, $when, $space, $status, $kind);
    var $export = $('<a class="btn btn-ghost btn-sm">Export CSV</a>');
    var $table = $('<div class="table-wrap"></div>');
    var $pager = $('<div class="pager"></div>');
    $content.empty().append(pageHead('Reservations', [$export].concat(newButtons())), $f, $table, $pager);

    var timer;
    function load() {
      var params = $.extend({}, listState);
      $export.attr('href', 'api.php?' + $.param($.extend({ action: 'export' }, params)));
      $table.html('<div style="padding:24px" class="muted"><span class="spinner"></span></div>');
      get('bookings', params).then(function (res) {
        if (!res.bookings.length) { $table.html('<div class="empty"><h2>No matches</h2><p>Try different filters.</p></div>'); $pager.empty(); return; }
        var $t = $('<table class="table"><thead><tr><th>When</th><th>Space</th><th>Booked by</th><th class="hide-sm">Title</th><th>Status</th></tr></thead><tbody></tbody></table>');
        res.bookings.forEach(function (b) {
          var sp = spaceById(b.space_id) || {};
          var status = b.status === 'cancelled' ? '<span class="badge cancelled">Cancelled</span>' : (b.status === 'pending' ? '<span class="badge pending">Awaiting approval</span>'
            : (b.kind === 'block' ? '<span class="badge block">Block</span>' : '<span class="badge ok">Confirmed</span>'));
          var $tr = $('<tr class="clickable"></tr>').toggleClass('is-cancelled', b.status === 'cancelled').on('click', function () { openBooking(b.id); });
          $tr.append($('<td class="nowrap"></td>').append($('<div class="strike"></div>').text(GPC.date.short(b.date)), $('<div class="sub"></div>').text(GPC.time.range(b.start_min, b.end_min))));
          $tr.append($('<td></td>').html('<i class="sw-dot" style="--c:' + (sp.color || '#999') + '"></i>').append(document.createTextNode(sp.short_name || sp.name || '')));
          $tr.append($('<td></td>').append($('<div></div>').text(b.kind === 'block' ? 'Management' : (b.name || '')), $('<div class="sub"></div>').text(b.kind === 'block' ? '' : [b.company, b.email].filter(Boolean).join(' · '))));
          $tr.append($('<td class="hide-sm"></td>').text(b.title || ''));
          $tr.append($('<td></td>').html(status));
          $t.find('tbody').append($tr);
        });
        $table.empty().append($t);
        var pages = Math.ceil(res.total / 50);
        $pager.empty().append($('<span></span>').text(res.total + ' result' + (res.total === 1 ? '' : 's')));
        if (pages > 1) {
          $pager.append($('<span style="display:flex;gap:8px;align-items:center"></span>').append(
            $('<button type="button" class="btn btn-ghost btn-sm">Previous</button>').prop('disabled', listState.page <= 1).on('click', function () { listState.page--; load(); }),
            $('<span></span>').text('Page ' + listState.page + ' of ' + pages),
            $('<button type="button" class="btn btn-ghost btn-sm">Next</button>').prop('disabled', listState.page >= pages).on('click', function () { listState.page++; load(); })));
        }
      }, fail);
    }
    $q.on('input', function () { clearTimeout(timer); timer = setTimeout(function () { listState.q = $q.val(); listState.page = 1; load(); }, 250); });
    $f.on('change', 'select', function () { listState[this.name] = this.value; listState.page = 1; load(); });
    load();
    viewReservations.reload = load;
  }

  function reloadCurrent() {
    var v = (location.hash.replace(/^#\/?/, '').split('/')[0]) || 'dashboard';
    if (v === 'reservations' || v === 'booking') { if (viewReservations.reload) viewReservations.reload(); }
    else route();
  }

  // ------------------------------------------------------------------ booking detail

  function openBooking(id) {
    if (!id) return;
    var d = GPC.dialog({ title: 'Loading…', body: '<div class="muted"><span class="spinner"></span></div>', wide: true });
    get('booking', { id: id }).then(function (res) {
      var b = res.booking, sp = spaceById(b.space_id) || { name: 'Space' };
      var status = b.status === 'cancelled' ? 'Cancelled' : (b.status === 'pending' ? 'Awaiting approval' : (b.kind === 'block' ? 'Block' : 'Confirmed'));
      d.setTitle(sp.name, status + ' · Ref ' + b.ref);
      var $dl = $('<dl class="detail-grid"></dl>');
      var row = function (k, v, isHtml) { if (v === null || v === undefined || v === '') return; $dl.append($('<dt></dt>').text(k), isHtml ? $('<dd></dd>').append(v) : $('<dd></dd>').text(v)); };
      row('When', GPC.date.long(b.date, true) + ', ' + GPC.time.range(b.start_min, b.end_min) + ' (' + GPC.time.duration(b.end_min - b.start_min) + ')');
      if (b.kind === 'reservation') {
        row('Name', b.name);
        row('Email', b.email ? $('<a></a>').attr('href', 'mailto:' + b.email).text(b.email) : '', true);
        row('Company', b.company);
        row('Title', b.title);
      } else {
        row('Reason', b.title || '—');
      }
      row('Notes', b.notes);
      row('Public sees', '“' + b.public_label + '”' + (b.is_private ? ' (marked private)' : ''));
      row('Booked', (b.source === 'admin' ? 'By ' + (b.created_by_name || 'an administrator') : 'Online by the tenant') + ' · ' + localDateTime(b.created_at) + (b.created_ip ? ' · IP ' + b.created_ip : ''));
      if (b.status === 'cancelled') row('Cancelled', localDateTime(b.cancelled_at) + ' by ' + (b.cancelled_by || '') + (b.cancel_reason ? ' — ' + b.cancel_reason : ''));
      if (b.series_id) row('Repeats', b.series_count + ' active booking' + (b.series_count === 1 ? '' : 's') + ' in this series');
      if (b.kind === 'reservation') row('Emails', b.notify ? 'Automated emails on' : 'Automated emails off');
      if (b.manage_url) {
        row('Manage link', $('<div class="copy-row"></div>').append($('<input class="input" readonly>').val(b.manage_url),
          $('<button type="button" class="btn btn-ghost btn-sm">Copy</button>').on('click', function () { copy(b.manage_url); })), true);
      }
      var $body = $('<div></div>');
      if (res.access && res.access.status === 'pending') {
        $body.append($('<div class="alert alert-warn" style="display:block"></div>').append(
          $('<div style="margin-bottom:10px"></div>').text(b.email + ' isn’t on the approved list yet, so this reservation is held until you decide. Approving confirms every reservation waiting from this address.'),
          approvalButtons(res.access, function () { d.close(); reloadCurrent(); })));
      }
      $body.append($dl);
      if (res.emails.length) {
        var $em = $('<div class="history"><strong>Emails</strong></div>');
        res.emails.forEach(function (e) {
          $em.append($('<div></div>').append($('<time></time>').text(localDateTime(e.created_at)), document.createTextNode(e.type + ' → ' + e.to_email + ' · ' + e.status + (e.last_error ? ' (' + e.last_error + ')' : ''))));
        });
        $body.append($em);
      }
      if (res.history.length) {
        var $h = $('<div class="history"><strong>History</strong></div>');
        res.history.forEach(function (h) {
          $h.append($('<div></div>').append($('<time></time>').text(localDateTime(h.created_at)), document.createTextNode(h.actor.replace(/^admin:/, '') + ' ' + h.action + (h.details ? ' — ' + h.details : ''))));
        });
        $body.append($h);
      }
      d.$body.empty().append($body);
      var $acts = $('<div style="display:flex;gap:8px;flex-wrap:wrap;width:100%"></div>');
      $acts.append($('<button type="button" class="btn btn-sm">Edit</button>').on('click', function () { d.close(); bookingForm({ edit: b }); }));
      if (b.status !== 'cancelled') $acts.append($('<button type="button" class="btn btn-danger-ghost btn-sm">Cancel</button>').on('click', function () { cancelDialog(b, d); }));
      $acts.append($('<span style="flex:1"></span>'), $('<button type="button" class="btn btn-quiet btn-sm">Delete</button>').on('click', function () { deleteDialog(b, d); }));
      d.$foot.empty().append($acts);
      d.$el.append(d.$foot);
    }, function (err) { d.close(); fail(err); });
  }

  function copy(text) {
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(function () { GPC.toast('Copied'); });
  }

  function cancelDialog(b, parent) {
    var $f = $('<form></form>');
    if (b.series_id) {
      $f.append('<div class="field"><span class="field-label">Which bookings?</span>' +
        '<label class="check"><input type="radio" name="scope" value="one" checked> Only this one (' + esc(GPC.date.short(b.date)) + ')</label>' +
        '<label class="check"><input type="radio" name="scope" value="following"> This and all later ones in the series</label></div>');
    }
    $f.append('<div class="field"><label for="cx-reason">Reason <span class="opt">(optional — included in the email)</span></label><input class="input" id="cx-reason" name="reason" maxlength="255"></div>');
    if (b.kind === 'reservation' && b.email) $f.append($('<label class="check"><input type="checkbox" name="notify" checked> <span></span></label>').find('span').text('Email ' + b.name + ' that it was cancelled').end());
    var $ok = $('<button type="button" class="btn btn-danger">Cancel booking</button>');
    var d = GPC.dialog({ title: 'Cancel booking', body: $f, foot: $ok });
    $ok.on('click', function () {
      $ok.addClass('is-busy');
      post('booking_cancel', { id: b.id, scope: $f.find('[name=scope]:checked').val() || 'one', reason: $f.find('[name=reason]').val(), notify: $f.find('[name=notify]').is(':checked') }).then(function (res) {
        d.close(); if (parent) parent.close();
        GPC.toast(res.cancelled === 1 ? 'Booking cancelled' : res.cancelled + ' bookings cancelled');
        reloadCurrent();
      }, function (err) { $ok.removeClass('is-busy'); fail(err); });
    });
  }

  function deleteDialog(b, parent) {
    var $f = $('<div><p style="margin:0 0 12px;color:var(--ink-2)">Deleting removes the booking and its history permanently. To keep a record, cancel instead. No email is sent.</p></div>');
    if (b.series_id) {
      $f.append('<label class="check"><input type="radio" name="dscope" value="one" checked> Only this one</label><label class="check" style="margin-top:6px"><input type="radio" name="dscope" value="following"> This and all later ones in the series</label>');
    }
    var $ok = $('<button type="button" class="btn btn-danger">Delete permanently</button>');
    var d = GPC.dialog({ title: 'Delete booking?', body: $f, foot: $ok });
    $ok.on('click', function () {
      post('booking_delete', { id: b.id, scope: $f.find('[name=dscope]:checked').val() || 'one' }).then(function (res) {
        d.close(); if (parent) parent.close();
        GPC.toast(res.deleted + ' deleted');
        reloadCurrent();
      }, fail);
    });
  }

  // ------------------------------------------------------------------ booking form (create / edit)

  function timeOptions($sel, from, to, selected) {
    $sel.empty();
    for (var m = from; m <= to; m += 15) $sel.append($('<option></option>').val(m).text(m === 1440 ? '12:00 AM (midnight)' : GPC.time.fmt(m)));
    $sel.val(String(selected));
  }

  function bookingForm(o) {
    var edit = o.edit || null;
    var kind = edit ? edit.kind : (o.kind || 'reservation');
    var $f = $('<form novalidate></form>');
    var spaces = edit ? S.spaces : activeSpaces().length ? activeSpaces() : S.spaces;
    var start = edit ? edit.start_min : (o.start != null ? o.start : 9 * 60);
    var end = edit ? edit.end_min : (o.end != null ? o.end : start + 60);

    if (!edit) {
      $f.append($('<div class="seg-kind"></div>').append(
        $('<button type="button" data-k="reservation">Reservation</button>'), $('<button type="button" data-k="block">Block time</button>')));
    }
    $f.append('<div class="field k-res"><label for="af-space">Space</label><select class="select" id="af-space" name="space_id"></select></div>');
    $f.append('<div class="field k-block"><span class="field-label">Spaces to block</span><div class="space-checks"></div></div>');
    spaces.forEach(function (s) {
      $f.find('[name=space_id]').append($('<option></option>').val(s.id).text(s.name + (s.is_active ? '' : ' (not bookable)')));
      $f.find('.space-checks').append($('<label class="check"></label>').append($('<input type="checkbox" name="space_ids">').val(s.id), document.createTextNode(s.name)));
    });
    $f.append('<div class="row"><div class="field"><label for="af-date">Date</label><input class="input" type="date" id="af-date" name="date"></div>' +
      '<div class="field" style="justify-content:flex-end"><label class="check" style="min-height:46px;align-items:center"><input type="checkbox" name="all_day"> All day</label></div></div>');
    $f.append('<div class="row af-times"><div class="field"><label for="af-start">Start</label><select class="select" id="af-start" name="start"></select></div>' +
      '<div class="field"><label for="af-end">End</label><select class="select" id="af-end" name="end"></select></div></div>');
    if (!edit) {
      $f.append('<div class="row"><div class="field"><label for="af-repeat">Repeat</label><select class="select" id="af-repeat" name="repeat">' +
        '<option value="none">Does not repeat</option><option value="daily">Every day</option><option value="weekdays">Every weekday (Mon–Fri)</option>' +
        '<option value="weekly">Every week</option><option value="biweekly">Every 2 weeks</option><option value="monthly">Every month (same date)</option></select></div>' +
        '<div class="field af-until" hidden><label for="af-until">Until</label><input class="input" type="date" id="af-until" name="repeat_until"></div></div>');
    }
    $f.append('<div class="k-res"><div class="row"><div class="field"><label for="af-name">Name</label><input class="input" id="af-name" name="name" maxlength="120"></div>' +
      '<div class="field"><label for="af-email">Email <span class="opt">(optional)</span></label><input class="input" type="email" id="af-email" name="email" maxlength="190"></div></div>' +
      '<div class="row"><div class="field"><label for="af-company">Company <span class="opt">(optional)</span></label><input class="input" id="af-company" name="company" maxlength="150"></div>' +
      '<div class="field"><label for="af-title">Title <span class="opt">(optional)</span></label><input class="input" id="af-title" name="title" maxlength="150"></div></div></div>');
    $f.append('<div class="field k-block"><label for="af-reason">Reason <span class="opt">(shown on the public calendar, e.g. “Building closed”)</span></label><input class="input" id="af-reason" name="reason" maxlength="150"></div>');
    $f.append('<div class="field"><label for="af-notes">Internal notes <span class="opt">(optional)</span></label><textarea class="textarea" id="af-notes" name="notes" maxlength="2000"></textarea></div>');
    $f.append('<div class="k-res" style="display:flex;flex-direction:column;gap:10px">' +
      '<label class="check"><input type="checkbox" name="is_private"> Private (public calendar just shows “Reserved”)</label>' +
      '<label class="check"><input type="checkbox" name="notify" checked> Send confirmation, reminder and after-use emails</label>' +
      (edit ? '<label class="check af-send-update"><input type="checkbox" name="send_update" checked> If the time or room changes, email the updated details</label>' : '') +
      '</div>');

    // Prefill
    $f.find('[name=date]').val(edit ? edit.date : (o.date || S.today));
    $f.find('[name=space_id]').val(String(edit ? edit.space_id : (o.space_id || spaces[0].id)));
    $f.find('[name=space_ids]').filter(function () { return +this.value === +(o.space_id || 0); }).prop('checked', true);
    if (edit) {
      $f.find('.space-checks').closest('.field').remove();
      $f.find('[name=name]').val(edit.name || ''); $f.find('[name=email]').val(edit.email || '');
      $f.find('[name=company]').val(edit.company || ''); $f.find('[name=title]').val(edit.kind === 'reservation' ? (edit.title || '') : '');
      $f.find('[name=reason]').val(edit.kind === 'block' ? (edit.title || '') : '');
      $f.find('[name=notes]').val(edit.notes || ''); $f.find('[name=is_private]').prop('checked', edit.is_private); $f.find('[name=notify]').prop('checked', edit.notify);
      if (edit.kind === 'block') $f.find('.k-res').first().replaceWith('<div class="field"><label for="af-space">Space</label><select class="select" id="af-space" name="space_id"></select></div>');
      if (edit.kind === 'block') { spaces.forEach(function (s) { $f.find('[name=space_id]').append($('<option></option>').val(s.id).text(s.name)); }); $f.find('[name=space_id]').val(String(edit.space_id)); }
    }
    var allDay = start === 0 && end === 1440;
    $f.find('[name=all_day]').prop('checked', allDay);
    timeOptions($f.find('[name=start]'), 0, 1425, start);
    timeOptions($f.find('[name=end]'), 15, 1440, end);

    function setKind(k) {
      kind = k;
      $f.find('.seg-kind button').removeClass('is-on').filter('[data-k="' + k + '"]').addClass('is-on');
      $f.find('.k-res').toggle(k === 'reservation');
      $f.find('.k-block').toggle(k === 'block');
      if (k === 'block' && !$f.find('[name=space_ids]:checked').length) $f.find('[name=space_ids]').filter(function () { return this.value === $f.find('[name=space_id]').val(); }).prop('checked', true);
      if (!edit) d.setTitle(k === 'block' ? 'Block time' : 'New reservation');
    }
    $f.on('click', '.seg-kind button', function () { setKind($(this).data('k')); });
    $f.on('change', '[name=all_day]', function () { $f.find('.af-times').toggle(!this.checked); }).find('.af-times').toggle(!allDay);
    $f.on('change', '[name=repeat]', function () {
      $f.find('.af-until').prop('hidden', this.value === 'none');
      if (!$f.find('[name=repeat_until]').val()) $f.find('[name=repeat_until]').val(GPC.date.addDays($f.find('[name=date]').val(), 28));
    });
    $f.on('change', '[name=start]', function () {
      var s = +this.value, e = +$f.find('[name=end]').val();
      if (e <= s) $f.find('[name=end]').val(String(Math.min(1440, s + 60)));
    });

    var $submit = $('<button type="submit" class="btn"></button>').text(edit ? 'Save changes' : 'Create');
    var d = GPC.dialog({ title: edit ? 'Edit ' + (kind === 'block' ? 'block' : 'reservation') : 'New reservation', eyebrow: edit ? 'Ref ' + edit.ref : 'Admin', body: $f, foot: $submit, wide: true });
    setKind(kind);
    $submit.on('click', function (e) { e.preventDefault(); $f.trigger('submit'); });

    function payload(skip) {
      var v = function (n) { return $.trim($f.find('[name=' + n + ']').val() || ''); };
      var p = {
        kind: kind, date: v('date'), all_day: $f.find('[name=all_day]').is(':checked'),
        start: GPC.time.toHHMM(+v('start')), end: GPC.time.toHHMM(+v('end')),
        notes: v('notes'), skip_conflicts: !!skip
      };
      if (kind === 'block' && !edit) { p.space_ids = $f.find('[name=space_ids]:checked').map(function () { return +this.value; }).get(); }
      else { p.space_id = +v('space_id'); }
      if (kind === 'reservation') {
        $.extend(p, { name: v('name'), email: v('email'), company: v('company'), title: v('title'),
          is_private: $f.find('[name=is_private]').is(':checked'), notify: $f.find('[name=notify]').is(':checked'),
          send_update: $f.find('[name=send_update]').is(':checked') });
      } else { p.title = v('reason'); }
      if (!edit) { p.repeat = v('repeat'); p.repeat_until = v('repeat_until'); }
      if (edit) p.id = edit.id;
      return p;
    }

    function submit(skip) {
      GPC.formErrors($f, null);
      $f.find('.conflicts').remove();
      $submit.addClass('is-busy');
      post(edit ? 'booking_update' : 'booking_create', payload(skip)).then(function (res) {
        d.close();
        if (edit) GPC.toast('Saved');
        else GPC.toast('Created ' + res.created + (res.created === 1 ? ' booking' : ' bookings') + (res.skipped && res.skipped.length ? ' · skipped ' + res.skipped.length + ' conflicting' : ''));
        reloadCurrent();
      }, function (err) {
        $submit.removeClass('is-busy');
        if (err.code === 'conflict' && err.data.conflicts) {
          var $c = $('<div class="alert alert-error conflicts" role="alert"><div style="flex:1"><strong></strong><ul class="conflict-list"></ul></div></div>');
          $c.find('strong').text(err.message);
          err.data.conflicts.forEach(function (c) {
            $c.find('ul').append($('<li></li>').text(c.space + ' · ' + GPC.date.short(c.date) + ' · ' + GPC.time.range(GPC.time.toMin(c.start), GPC.time.toMin(c.end)) + (c.label ? ' — ' + c.label : '')));
          });
          if (err.data.creatable > 0) {
            $c.find('div').append($('<button type="button" class="btn btn-sm" style="margin-top:10px"></button>')
              .text('Skip those and create the other ' + err.data.creatable).on('click', function () { submit(true); }));
          }
          $f.prepend($c);
          d.$body.scrollTop(0);
        } else {
          GPC.formErrors($f, err);
        }
      });
    }
    $f.on('submit', function (e) { e.preventDefault(); submit(false); });
  }

  // ------------------------------------------------------------------ approved emails

  /** Approve / Approve whole domain / Decline buttons for one pending address. */
  function approvalButtons(a, done) {
    var $w = $('<div style="display:flex;gap:8px;flex-wrap:wrap"></div>');
    var act = function (action, data, $btn) {
      $w.find('button').prop('disabled', true); $btn.addClass('is-busy');
      post(action, $.extend({ id: a.id }, data)).then(function (r) {
        S.approvalsPending = Math.max(0, S.approvalsPending - 1); updateBadge();
        GPC.toast(action === 'access_approve'
          ? 'Approved' + (r.bookings ? ' — ' + r.bookings + ' reservation' + (r.bookings === 1 ? '' : 's') + ' confirmed and emailed' : '')
          : 'Declined' + (r.bookings ? ' — ' + r.bookings + ' held reservation' + (r.bookings === 1 ? '' : 's') + ' released' : ''));
        done();
      }, function (err) { $w.find('button').prop('disabled', false); $btn.removeClass('is-busy'); fail(err); });
    };
    var $ok = $('<button type="button" class="btn btn-sm"></button>').text('Approve ' + a.pattern);
    $ok.on('click', function () { act('access_approve', {}, $ok); });
    $w.append($ok);
    if (a.domain_ok && a.domain) {
      var $dom = $('<button type="button" class="btn btn-ghost btn-sm"></button>').text('Approve everyone @' + a.domain);
      $dom.on('click', function () { act('access_approve', { whole_domain: true }, $dom); });
      $w.append($dom);
    }
    var $no = $('<button type="button" class="btn btn-danger-ghost btn-sm">Decline</button>');
    $no.on('click', function () {
      var $f = $('<div><p style="margin:0 0 12px;color:var(--ink-2)"></p><div class="field"><label for="dc-reason">Reason <span class="opt">(optional — included in the email to them)</span></label><input class="input" id="dc-reason" maxlength="255"></div></div>');
      $f.find('p').text('Their held reservations are released and they’re told by email. If they book again, you’ll get a new request.');
      var $go = $('<button type="button" class="btn btn-danger">Decline</button>');
      var dd = GPC.dialog({ title: 'Decline ' + a.pattern + '?', body: $f, foot: $go });
      $go.on('click', function () { dd.close(); act('access_decline', { reason: $f.find('input').val() }, $no); });
    });
    return $w.append($no);
  }

  function approvalCard(a, done) {
    var $c = $('<div class="approval"></div>');
    $c.append($('<div class="ap-head"></div>').append(
      $('<strong></strong>').text(a.name || a.pattern),
      $('<span></span>').text([a.name ? a.pattern : null, a.company].filter(Boolean).join(' · ')),
      $('<span class="muted"></span>').text('asked ' + localDateTime(a.requested_at))));
    if (a.bookings.length) {
      var $ul = $('<ul class="ap-list"></ul>');
      a.bookings.forEach(function (b) {
        var sp = spaceById(b.space_id) || {};
        $ul.append($('<li></li>').html('<i class="sw-dot" style="--c:' + (sp.color || '#999') + '"></i>').append(document.createTextNode(
          (sp.short_name || sp.name || '') + ' · ' + GPC.date.short(b.date) + ', ' + GPC.time.range(b.start_min, b.end_min) + (b.title ? ' · ' + b.title : ''))));
      });
      $c.append($ul);
    } else {
      $c.append('<p class="muted small" style="margin:6px 0 10px">No reservations waiting (they may have cancelled).</p>');
    }
    return $c.append(approvalButtons(a, done));
  }

  function viewApprovals() {
    $content.empty().append(pageHead('Approved emails'), '<div class="muted"><span class="spinner"></span></div>');
    get('access').then(function (res) {
      var pending = res.entries.filter(function (e) { return e.status === 'pending'; });
      var approved = res.entries.filter(function (e) { return e.status === 'approved'; });
      var declined = res.entries.filter(function (e) { return e.status === 'declined'; });
      S.approvalsPending = pending.length; updateBadge();

      var $intro = $('<p class="muted" style="margin:-10px 0 18px;max-width:760px"></p>');
      $intro.text(res.require_approval
        ? 'People on this list book instantly. Anyone else’s booking is held as pending, and the request is emailed to ' + res.approvers.join(', ') + '. Approving someone adds them here.'
        : 'Approval is turned off (Settings → Booking rules), so anyone can book instantly. This list is kept for when it’s turned back on.');

      var $pend = $();
      if (pending.length) {
        $pend = $('<div class="panel" style="margin-bottom:18px"><h2>Waiting for approval</h2></div>');
        pending.forEach(function (a) { $pend.append(approvalCard(a, viewApprovals)); });
      }

      var $add = $('<div class="panel" style="margin-bottom:18px"><h2>Add to the approved list</h2></div>');
      var $ta = $('<textarea class="textarea" rows="4" placeholder="jane@acme.com&#10;@tenantcompany.com"></textarea>');
      var $addBtn = $('<button type="button" class="btn btn-sm">Add</button>');
      $add.append($('<div class="field"></div>').append($ta, $('<div class="field-hint"></div>').text(
        'One per line (commas work too). Use @company.com to approve everyone at a tenant company. Don’t add public domains like @gmail.com — approve those people individually.')), $addBtn);
      $addBtn.on('click', function () {
        if (!$.trim($ta.val())) return;
        $addBtn.addClass('is-busy');
        post('access_add', { entries: $ta.val() }).then(function (r) {
          $addBtn.removeClass('is-busy');
          var msg = 'Added ' + r.added.length + (r.confirmed ? ' · ' + r.confirmed + ' waiting reservation' + (r.confirmed === 1 ? '' : 's') + ' confirmed' : '');
          if (r.invalid.length) GPC.toast('Not valid, skipped: ' + r.invalid.join(', '), 'error');
          GPC.toast(msg);
          viewApprovals();
        }, function (err) { $addBtn.removeClass('is-busy'); fail(err); });
      });

      var table = function (title, rows, withApprove) {
        var $p = $('<div class="table-wrap" style="margin-bottom:18px"></div>');
        if (!rows.length) return $p.html('<div class="empty" style="padding:28px"><p>Nobody yet.</p></div>');
        var $t = $('<table class="table"><thead><tr><th></th><th class="hide-sm">Type</th><th class="hide-sm"></th><th></th></tr></thead><tbody></tbody></table>');
        $t.find('th').eq(0).text(title);
        $t.find('th').eq(2).text(withApprove ? 'Declined' : 'Approved');
        rows.forEach(function (e) {
          var $tr = $('<tr></tr>');
          $tr.append($('<td></td>').append($('<div></div>').text(e.pattern), (e.name || e.company) ? $('<div class="sub"></div>').text([e.name, e.company].filter(Boolean).join(' · ')) : ''));
          $tr.append($('<td class="hide-sm"></td>').text(e.is_domain ? 'Whole domain' : 'Address'));
          $tr.append($('<td class="hide-sm"></td>').append($('<div></div>').text(localDateTime(e.decided_at || e.created_at)),
            $('<div class="sub"></div>').text((e.decided_by || '').replace(/^admin:/, '') + (e.note ? ' — ' + e.note : ''))));
          var $acts = $('<td style="text-align:right;white-space:nowrap"></td>');
          if (withApprove) {
            $acts.append($('<button type="button" class="btn btn-ghost btn-sm">Approve</button>').on('click', function () {
              post('access_add', { entries: e.pattern }).then(function () { GPC.toast('Approved'); viewApprovals(); }, fail);
            }), ' ');
          }
          $acts.append($('<button type="button" class="btn btn-quiet btn-sm">Remove</button>').on('click', function () {
            GPC.confirm({ title: 'Remove ' + e.pattern + '?', message: withApprove ? 'Removes it from the declined list.' : 'Future bookings from ' + (e.is_domain ? 'this domain' : 'this address') + ' will need approval again. Existing reservations are not affected.', ok: 'Remove', danger: true })
              .then(function (yes) { if (yes) post('access_remove', { id: e.id }).then(viewApprovals, fail); });
          }));
          $t.find('tbody').append($tr.append($acts));
        });
        return $p.append($t);
      };

      $content.empty().append(pageHead('Approved emails'), $intro, $pend, $add, table('Approved (' + approved.length + ')', approved, false));
      if (declined.length) $content.append($('<h2 class="section-title" style="font-size:22px">Declined</h2>'), table('Declined (' + declined.length + ')', declined, true));
    }, fail);
  }

  // ------------------------------------------------------------------ spaces

  function viewSpaces() {
    var $add = $('<button type="button" class="btn btn-sm">' + GPC.icon('plus') + ' Add space</button>').on('click', function () { spaceForm(null); });
    var $panel = $('<div class="panel" style="padding:6px 8px"></div>');
    $content.empty().append(pageHead('Spaces', $add), $panel,
      '<p class="muted small" style="margin-top:14px">Turn off “Available for booking” to hide a space from tenants without losing its history. Use the arrows to change the order on the booking page.</p>');
    get('spaces').then(function (res) {
      S.spaces = res.spaces;
      if (!S.spaces.length) { $panel.html('<div class="empty"><h2>No spaces yet</h2><p>Add your first bookable space.</p></div>'); return; }
      S.spaces.forEach(function (s, i) {
        var $r = $('<div class="space-admin"></div>').toggleClass('is-off', !s.is_active);
        $r.append($('<div class="space-art"></div>').css('--c', s.color).html(s.photo_url ? '<img src="../' + esc(s.photo_url) + '" alt="">' : '<div class="ph"><div class="ph-mark">' + esc(s.name.charAt(0)) + '</div></div>'));
        $r.append($('<div></div>').append($('<h3></h3>').text(s.name), $('<div class="meta"></div>').text(
          [s.location, s.capacity ? 'Up to ' + s.capacity : null, GPC.hoursSummary(s), s.is_active ? s.upcoming_count + ' upcoming' : 'Not bookable'].filter(Boolean).join(' · '))));
        var $acts = $('<div class="acts"></div>');
        $acts.append($('<button type="button" class="icon-btn" aria-label="Move up">' + GPC.icon('left').replace('M15 5l-7 7 7 7', 'M5 15l7-7 7 7') + '</button>').prop('disabled', i === 0).css('opacity', i === 0 ? .35 : 1).on('click', function () { move(i, -1); }));
        $acts.append($('<button type="button" class="icon-btn" aria-label="Move down">' + GPC.icon('down') + '</button>').prop('disabled', i === S.spaces.length - 1).css('opacity', i === S.spaces.length - 1 ? .35 : 1).on('click', function () { move(i, 1); }));
        $acts.append($('<a class="btn btn-ghost btn-sm" target="_blank" rel="noopener">View</a>').attr('href', '../#/space/' + s.slug));
        $acts.append($('<button type="button" class="btn btn-sm">Edit</button>').on('click', function () { spaceForm(s); }));
        $r.append($acts);
        $panel.append($r);
      });
    }, fail);
    function move(i, dir) {
      var ids = S.spaces.map(function (s) { return s.id; });
      var t = ids[i]; ids[i] = ids[i + dir]; ids[i + dir] = t;
      post('space_reorder', { ids: ids }).then(viewSpaces, fail);
    }
  }

  function spaceForm(s) {
    var isNew = !s;
    s = s || { name: '', short_name: '', slug: '', location: '', capacity: '', description: '', amenities: '', color: '#3E5C4A', instructions: '', cleanup_message: '', hours: null, max_duration_minutes: null, max_days_ahead: null, is_active: true };
    var $f = $('<form novalidate></form>');
    var fld = function (name, label, html, hint) {
      return $('<div class="field"></div>').append($('<label></label>').attr('for', 'sf-' + name).html(label), $(html).attr({ id: 'sf-' + name, name: name }), hint ? $('<div class="field-hint"></div>').text(hint) : '');
    };
    $f.append($('<div class="row"></div>').append(
      fld('name', 'Name', '<input class="input" maxlength="120">'),
      fld('short_name', 'Short name <span class="opt">(calendar column)</span>', '<input class="input" maxlength="60">')));
    $f.append($('<div class="row"></div>').append(
      fld('location', 'Location <span class="opt">(e.g. Upstairs)</span>', '<input class="input" maxlength="120">'),
      fld('capacity', 'Capacity <span class="opt">(people)</span>', '<input class="input" type="number" min="1" max="5000">')));
    $f.append(fld('description', 'Description', '<textarea class="textarea" maxlength="2000"></textarea>'));
    $f.append($('<div class="row"></div>').append(
      fld('amenities', 'Amenities <span class="opt">(comma-separated)</span>', '<input class="input" maxlength="500" placeholder="Display screen, Whiteboard, Wi-Fi">'),
      $('<div class="field"><label for="sf-color">Calendar color</label><div class="copy-row"><input type="color" id="sf-color" name="color" style="width:52px;height:44px;border:1px solid var(--line);border-radius:8px;padding:2px;background:#fff"><input class="input" name="color_text" maxlength="7" style="font-family:inherit"></div></div>')));

    // Photo
    var $photo = $('<div class="field"><span class="field-label">Photo</span></div>');
    if (isNew) { $photo.append('<div class="field-hint">Save the space first, then add a photo.</div>'); }
    else {
      var $art = $('<div class="space-art"></div>').css('--c', s.color).html(s.photo_url ? '<img src="../' + esc(s.photo_url) + '" alt="">' : '<div class="ph"><div class="ph-mark">' + esc(s.name.charAt(0)) + '</div></div>');
      var $file = $('<input type="file" accept="image/jpeg,image/png,image/webp" hidden>');
      var $up = $('<button type="button" class="btn btn-ghost btn-sm">Upload photo</button>').on('click', function () { $file.trigger('click'); });
      var $rm = $('<button type="button" class="btn btn-quiet btn-sm">Remove</button>').toggle(!!s.photo_url).on('click', function () {
        post('space_photo_remove', { id: s.id }).then(function () { $art.html('<div class="ph"><div class="ph-mark">' + esc(s.name.charAt(0)) + '</div></div>'); $rm.hide(); refreshMe(); }, fail);
      });
      $file.on('change', function () {
        if (!this.files[0]) return;
        var fd = new FormData(); fd.append('id', s.id); fd.append('photo', this.files[0]);
        $up.addClass('is-busy').text('Uploading…');
        $.ajax({ url: 'api.php?action=space_photo', method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json', headers: { 'X-CSRF-Token': S.csrf } })
          .done(function (res) { $art.html('<img src="../' + esc(res.space.photo_url) + '?t=' + Date.now() + '" alt="">'); $rm.show(); refreshMe(); GPC.toast('Photo updated'); })
          .fail(function (xhr) { GPC.toast((xhr.responseJSON && xhr.responseJSON.error) || 'Upload failed', 'error'); })
          .always(function () { $up.removeClass('is-busy').text('Upload photo'); $file.val(''); });
      });
      $photo.append($('<div class="photo-row"></div>').append($art, $('<div style="display:flex;flex-direction:column;gap:6px;align-items:flex-start"></div>').append($up, $rm, '<span class="field-hint">JPG, PNG or WebP · landscape works best</span>')), $file);
    }
    $f.append($photo);

    // Availability
    var $av = $('<div class="field"><span class="field-label">When can tenants book it?</span>' +
      '<label class="check"><input type="radio" name="hours_mode" value="always"> Any time, every day</label>' +
      '<label class="check"><input type="radio" name="hours_mode" value="set"> Set weekly hours</label><div class="hours-grid" style="margin-top:8px"></div>' +
      '<div class="field-hint">Admins can always book outside these hours. Use “Block time” for holidays and one-off closures.</div></div>');
    var $hg = $av.find('.hours-grid');
    [1, 2, 3, 4, 5, 6, 0].forEach(function (d) {
      var cur = s.hours ? s.hours[String(d)] : ['07:00', '22:00'];
      var $open = $('<label class="check"></label>').append($('<input type="checkbox">').attr('data-day', d).prop('checked', !s.hours || !!cur), document.createTextNode(GPC.date.DAYS[d].slice(0, 3)));
      var $s1 = $('<select class="select"></select>').attr('data-from', d), $s2 = $('<select class="select"></select>').attr('data-to', d);
      timeOptions($s1, 0, 1425, cur ? GPC.time.toMin(cur[0]) : 420);
      timeOptions($s2, 15, 1440, cur ? GPC.time.toMin(cur[1]) : 1320);
      $hg.append($open, $s1, $s2);
    });
    $av.find('[name=hours_mode][value=' + (s.hours ? 'set' : 'always') + ']').prop('checked', true);
    var syncHours = function () {
      var set = $av.find('[name=hours_mode]:checked').val() === 'set';
      $hg.toggle(set);
      $hg.find('input[data-day]').each(function () { $hg.find('[data-from="' + $(this).data('day') + '"],[data-to="' + $(this).data('day') + '"]').prop('disabled', !this.checked); });
    };
    $av.on('change', 'input', syncHours);
    $f.append($av);

    $f.append($('<div class="row"></div>').append(
      fld('max_duration_minutes', 'Longest booking <span class="opt">(minutes)</span>', '<input class="input" type="number" min="15" max="1440">', 'Blank = building default (' + S.settings.max_duration_minutes + ')'),
      fld('max_days_ahead', 'Book up to <span class="opt">(days ahead)</span>', '<input class="input" type="number" min="1" max="730">', 'Blank = building default (' + S.settings.max_days_ahead + ')')));
    $f.append(fld('instructions', 'Room instructions <span class="opt">(added to the reminder email)</span>', '<textarea class="textarea" maxlength="3000" placeholder="e.g. The TV remote is in the drawer. Door code is…"></textarea>'));
    $f.append(fld('cleanup_message', 'After-use message <span class="opt">(leave blank to use the building default)</span>', '<textarea class="textarea" maxlength="3000"></textarea>'));
    if (!isNew) $f.append(fld('slug', 'Web address <span class="opt">(…/#/space/<b>slug</b>)</span>', '<input class="input" maxlength="80">'));
    $f.append('<label class="check"><input type="checkbox" name="is_active"> Available for booking (shown on the public page)</label>');

    ['name', 'short_name', 'location', 'capacity', 'description', 'amenities', 'instructions', 'cleanup_message', 'slug', 'max_duration_minutes', 'max_days_ahead'].forEach(function (k) {
      $f.find('[name=' + k + ']').val(s[k] == null ? '' : s[k]);
    });
    $f.find('[name=color],[name=color_text]').val(s.color);
    $f.on('input', '[name=color]', function () { $f.find('[name=color_text]').val(this.value); });
    $f.on('input', '[name=color_text]', function () { if (/^#[0-9a-f]{6}$/i.test(this.value)) $f.find('[name=color]').val(this.value); });
    $f.find('[name=is_active]').prop('checked', s.is_active);

    var $save = $('<button type="submit" class="btn">Save space</button>');
    var $foot = $('<div style="display:flex;gap:10px;width:100%;align-items:center"></div>');
    if (!isNew) {
      $foot.append($('<button type="button" class="btn btn-quiet btn-sm">Delete</button>').on('click', function () {
        GPC.confirm({ title: 'Delete ' + s.name + '?', message: 'Only spaces that have never been booked can be deleted. Otherwise, turn off “Available for booking”.', ok: 'Delete', danger: true }).then(function (yes) {
          if (yes) post('space_delete', { id: s.id }).then(function () { d.close(); GPC.toast('Deleted'); refreshMe().then(viewSpaces); }, fail);
        });
      }));
    }
    $foot.append('<span style="flex:1"></span>', $save);
    var d = GPC.dialog({ title: isNew ? 'Add space' : 'Edit space', eyebrow: isNew ? '' : s.name, body: $f, foot: $foot, wide: true });
    syncHours();
    $save.on('click', function (e) { e.preventDefault(); $f.trigger('submit'); });
    $f.on('submit', function (e) {
      e.preventDefault();
      GPC.formErrors($f, null);
      var data = { id: isNew ? '' : s.id, color: $f.find('[name=color_text]').val(), is_active: $f.find('[name=is_active]').is(':checked') };
      ['name', 'short_name', 'location', 'capacity', 'description', 'amenities', 'instructions', 'cleanup_message', 'slug', 'max_duration_minutes', 'max_days_ahead'].forEach(function (k) {
        var $el = $f.find('[name=' + k + ']'); if ($el.length) data[k] = $el.val();
      });
      if ($av.find('[name=hours_mode]:checked').val() === 'set') {
        data.hours = {};
        [0, 1, 2, 3, 4, 5, 6].forEach(function (dd) {
          var open = $hg.find('input[data-day="' + dd + '"]').is(':checked');
          data.hours[dd] = open ? [GPC.time.toHHMM(+$hg.find('[data-from="' + dd + '"]').val()), GPC.time.toHHMM(+$hg.find('[data-to="' + dd + '"]').val())] : null;
        });
      } else { data.hours = null; }
      $save.addClass('is-busy');
      post('space_save', data).then(function () {
        d.close(); GPC.toast(isNew ? 'Space added' : 'Saved');
        refreshMe().then(viewSpaces);
      }, function (err) { $save.removeClass('is-busy'); GPC.formErrors($f, err); });
    });
  }

  // ------------------------------------------------------------------ settings

  function viewSettings() {
    $content.empty().append(pageHead('Settings'), '<div class="muted"><span class="spinner"></span></div>');
    get('settings').then(function (res) {
      var st = res.settings;
      var $f = $('<form class="form-section" novalidate></form>');
      var panel = function (title, sub) { return $('<div class="panel"></div>').append($('<h2></h2>').text(title), sub ? $('<p class="panel-sub"></p>').text(sub) : ''); };
      var input = function (name, label, type, hint, attrs) {
        var $i = type === 'textarea' ? $('<textarea class="textarea"></textarea>') : $('<input class="input">').attr('type', type || 'text');
        $i.attr($.extend({ name: name, id: 'st-' + name }, attrs || {})).val(st[name]);
        return $('<div class="field"></div>').append($('<label></label>').attr('for', 'st-' + name).html(label), $i, hint ? $('<div class="field-hint"></div>').text(hint) : '');
      };
      var check = function (name, label) {
        return $('<label class="check" style="margin-bottom:10px"></label>').append($('<input type="checkbox">').attr('name', name).prop('checked', !!st[name]), $('<span></span>').text(label));
      };
      var select = function (name, label, options, hint) {
        var $s = $('<select class="select"></select>').attr({ name: name, id: 'st-' + name });
        options.forEach(function (o) { $s.append($('<option></option>').val(o[0]).text(o[1])); });
        $s.val(String(st[name]));
        return $('<div class="field"></div>').append($('<label></label>').attr('for', 'st-' + name).text(label), $s, hint ? $('<div class="field-hint"></div>').text(hint) : '');
      };

      // General
      $f.append(panel('General', 'How the booking page introduces itself.').append(
        $('<div class="row"></div>').append(input('org_name', 'Organization name'), input('tagline', 'Page headline')),
        input('intro', 'Intro text', 'textarea'),
        $('<div class="row"></div>').append(input('contact_email', 'Management email', 'email', 'Shown on the page; replies to automated emails go here.'), input('contact_phone', 'Management phone <span class="opt">(optional)</span>')),
        input('timezone', 'Time zone', 'text', 'IANA name, e.g. America/New_York')));

      // Privacy
      var $priv = panel('Public calendar privacy', 'What other tenants see on a reserved time. Email addresses and notes are never shown publicly. Tenants can also mark a booking private.');
      var $rc = $('<div class="radio-cards"></div>');
      [['none', 'Just “Reserved”', 'Reserved'], ['first', 'First name', 'Reserved — Sam'], ['full', 'Full name', 'Reserved — Sam Rivera']].forEach(function (o) {
        $rc.append($('<label class="radio-card"></label>').append($('<input type="radio" name="public_show_name">').val(o[0]).prop('checked', st.public_show_name === o[0]), $('<strong></strong>').text(o[1]), $('<span class="preview"></span>').text(o[2])));
      });
      $priv.append($rc, '<div style="height:14px"></div>', check('public_show_company', 'Also show the company name'), check('public_show_title', 'Show the meeting title instead of “Reserved”'));
      $f.append($priv);

      // Rules
      $f.append(panel('Booking rules', 'Applied to tenant bookings. Administrators can override them. Opening hours are set per space under Spaces.').append(
        $('<div class="row"></div>').append(
          select('time_increment', 'Time steps', [['15', '15 minutes'], ['30', '30 minutes'], ['60', '1 hour'], ['5', '5 minutes'], ['10', '10 minutes']]),
          input('min_duration_minutes', 'Shortest booking (minutes)', 'number', null, { min: 5, max: 1440 })),
        $('<div class="row"></div>').append(
          input('max_duration_minutes', 'Longest booking (minutes)', 'number', '720 = 12 hours. Spaces can override.', { min: 15, max: 1440 }),
          input('max_days_ahead', 'How far ahead (days)', 'number', null, { min: 1, max: 730 })),
        $('<div class="row"></div>').append(
          input('min_notice_minutes', 'Minimum notice (minutes)', 'number', '0 = can book right up to the start.', { min: 0 }),
          input('max_upcoming_per_email', 'Max upcoming bookings per person', 'number', '0 = no limit.', { min: 0 })),
        $('<div class="row"></div>').append(
          input('rate_limit_per_hour', 'New bookings per network per hour', 'number', 'Stops automated flooding. Everyone in the building may share one internet connection, so keep this generous.', { min: 1 }),
          $('<div></div>')),
        $('<div style="border-top:1px solid var(--line-soft);padding-top:16px;margin-top:4px"></div>').append(
          check('require_approval', 'Require approval for new email addresses'),
          $('<p class="field-hint" style="margin:-4px 0 14px 28px">Addresses and domains on the approved list book instantly. Anyone else’s booking is held as “pending” and the request is emailed for approval. Manage the list under Approved emails.</p>'),
          input('approval_emails', 'Send approval requests to <span class="opt">(comma-separated)</span>', 'text', 'Blank = the management email above, or every administrator if that’s blank too.'))));

      // Emails
      var $em = panel('Automated emails', 'Confirmations always go out right away. Reminders and after-use notes are sent by the background task.');
      $em.append(check('reminder_enabled', 'Send a reminder before each reservation'),
        input('reminder_minutes', 'Reminder lead time (minutes before start)', 'number', null, { min: 5, max: 1440 }),
        check('followup_enabled', 'Send a thank-you / tidy-up note after each reservation'),
        input('followup_offset_minutes', 'Send it (minutes after the end — use a negative number to send before)', 'number', null, { min: -120, max: 240 }),
        input('cleanup_message', 'After-use message', 'textarea', 'Blank lines start new paragraphs. Spaces can override this.'),
        input('building_instructions', 'Building information for reminders <span class="opt">(Wi-Fi, door codes, parking…)</span>', 'textarea'),
        input('admin_notify_emails', 'Copy management on activity <span class="opt">(comma-separated emails)</span>', 'text'),
        check('admin_notify_new', 'Email management about every new tenant reservation'),
        check('admin_notify_cancel', 'Email management when a tenant cancels'));
      var $test = $('<div class="copy-row" style="margin-top:8px"></div>').append(
        $('<input class="input" type="email" placeholder="you@example.com">').val(S.admin.email),
        $('<button type="button" class="btn btn-ghost btn-sm">Send test email</button>').on('click', function () {
          var $b = $(this).addClass('is-busy');
          post('email_test', { to: $test.find('input').val() }).then(function (r) {
            $b.removeClass('is-busy');
            if (r.email.status === 'sent') GPC.toast('Test email sent — check your inbox.');
            else GPC.toast('Not sent: ' + (r.email.last_error || r.email.status), 'error');
          }, function (err) { $b.removeClass('is-busy'); fail(err); });
        }));
      $em.append('<div class="field-label" style="margin-top:6px">Delivery: ' + esc(res.mail.transport.toUpperCase()) + (res.mail.transport === 'smtp' ? ' via ' + esc(res.mail.host) : '') + ' · from ' + esc(res.mail.from) +
        ' <span class="opt">(set in config/config.php)</span></div>', $test);
      $f.append($em);

      // Feeds
      var $feeds = panel('Calendar feeds for staff', 'Subscribe in Google Calendar (Other calendars → + → From URL) or Outlook to see every booking with names. Google refreshes subscribed calendars every few hours, so use this page for up-to-the-minute availability. Keep these links private.');
      res.feeds.forEach(function (fd) {
        $feeds.append($('<div class="field"></div>').append($('<span class="field-label"></span>').text(fd.name),
          $('<div class="copy-row"></div>').append($('<input class="input" readonly>').val(fd.url), $('<button type="button" class="btn btn-ghost btn-sm">Copy</button>').on('click', function () { copy(fd.url); }))));
      });
      $feeds.append($('<button type="button" class="btn btn-quiet btn-sm">Generate new links (old ones stop working)</button>').on('click', function () {
        GPC.confirm({ title: 'Replace feed links?', message: 'Anyone subscribed with the current links will stop receiving updates.', ok: 'Replace links', danger: true }).then(function (yes) {
          if (yes) post('feed_key_reset').then(function () { GPC.toast('New links created'); viewSettings(); }, fail);
        });
      }));
      $f.append($feeds);

      var $save = $('<button type="submit" class="btn">Save settings</button>');
      $f.append($('<div class="sticky-save"></div>').append($save));
      $content.empty().append(pageHead('Settings'), $('<div></div>').append(healthList(res.health)).addClass('panel').css('max-width', '760px').css('margin-bottom', '18px'), $f);
      $f.on('submit', function (e) {
        e.preventDefault();
        GPC.formErrors($f, null);
        var data = {};
        $f.find('input[name],select[name],textarea[name]').each(function () {
          if (this.type === 'checkbox') data[this.name] = this.checked;
          else if (this.type === 'radio') { if (this.checked) data[this.name] = this.value; }
          else data[this.name] = this.value;
        });
        $save.addClass('is-busy');
        post('settings_save', data).then(function () { $save.removeClass('is-busy'); GPC.toast('Settings saved'); refreshMe(); }, function (err) { $save.removeClass('is-busy'); GPC.formErrors($f, err); });
      });
    }, fail);
  }

  // ------------------------------------------------------------------ emails

  function viewEmails() {
    var $sel = $('<select class="select" style="width:auto"><option value="">All emails</option><option value="failed">Failed</option><option value="pending">Waiting</option><option value="sent">Sent</option></select>');
    var $run = $('<button type="button" class="btn btn-ghost btn-sm">Run background tasks now</button>').on('click', function () {
      $run.addClass('is-busy');
      post('run_jobs').then(function (r) {
        $run.removeClass('is-busy');
        GPC.toast('Done: ' + r.result.reminders + ' reminders, ' + r.result.followups + ' after-use notes, ' + r.result.sent + ' sent, ' + r.result.failed + ' failed');
        load();
      }, function (err) { $run.removeClass('is-busy'); fail(err); });
    });
    var $health = $('<div class="panel" style="margin-bottom:18px"></div>');
    var $table = $('<div class="table-wrap"></div>');
    $content.empty().append(pageHead('Email log', [$sel, $run]), $health, $table);
    function load() {
      get('emails', { status: $sel.val() }).then(function (res) {
        $health.empty().append(healthList(res.health));
        if (!res.emails.length) { $table.html('<div class="empty"><h2>No emails yet</h2></div>'); return; }
        var $t = $('<table class="table"><thead><tr><th>Created</th><th>Type</th><th>To</th><th class="hide-sm">Subject</th><th>Status</th><th></th></tr></thead><tbody></tbody></table>');
        res.emails.forEach(function (e) {
          var badge = e.status === 'sent' ? 'ok' : (e.status === 'failed' ? 'cancelled' : 'past');
          var $tr = $('<tr class="clickable"></tr>').on('click', function (ev) { if (!$(ev.target).closest('button').length) previewEmail(e.id); });
          $tr.append($('<td class="nowrap"></td>').text(localDateTime(e.created_at)), $('<td></td>').text(e.type), $('<td></td>').text(e.to_email),
            $('<td class="hide-sm"></td>').append($('<div></div>').text(e.subject), e.last_error ? $('<div class="sub" style="color:var(--danger)"></div>').text(e.last_error) : ''),
            $('<td></td>').append($('<span class="badge"></span>').addClass(badge).text(e.status + (e.attempts > 1 ? ' (' + e.attempts + ' tries)' : ''))),
            $('<td></td>').append(e.status !== 'sent' ? $('<button type="button" class="btn btn-ghost btn-sm">Retry</button>').on('click', function () {
              post('email_retry', { id: e.id }).then(function (r) { GPC.toast(r.email.status === 'sent' ? 'Sent' : 'Still failing: ' + (r.email.last_error || '')); load(); }, fail);
            }) : ''));
          $t.find('tbody').append($tr);
        });
        $table.empty().append($t);
      }, fail);
    }
    $sel.on('change', load);
    load();
  }

  function previewEmail(id) {
    get('email', { id: id }).then(function (res) {
      GPC.dialog({ title: res.email.subject, eyebrow: 'To ' + res.email.to_email, wide: true,
        body: $('<pre style="white-space:pre-wrap;font:13px/1.55 ui-monospace,Menlo,monospace;margin:0"></pre>').text(res.email.body_text) });
    }, fail);
  }

  // ------------------------------------------------------------------ administrators

  function viewAdmins() {
    var $add = $('<button type="button" class="btn btn-sm">' + GPC.icon('plus') + ' Add administrator</button>').on('click', function () { adminForm(null); });
    var $table = $('<div class="table-wrap"></div>');
    $content.empty().append(pageHead('Administrators', [$('<button type="button" class="btn btn-ghost btn-sm">Change my password</button>').on('click', passwordDialog), $add]), $table,
      '<p class="muted small" style="margin-top:14px">Locked out? On the server run <code>php bin/admin.php password you@example.com</code>.</p>');
    get('admins').then(function (res) {
      var $t = $('<table class="table"><thead><tr><th>Name</th><th>Email</th><th class="hide-sm">Last sign-in</th><th>Status</th><th></th></tr></thead><tbody></tbody></table>');
      res.admins.forEach(function (a) {
        $t.find('tbody').append($('<tr></tr>').append(
          $('<td></td>').text(a.name + (a.id === res.me ? ' (you)' : '')), $('<td></td>').text(a.email),
          $('<td class="hide-sm"></td>').text(a.last_login_at ? localDateTime(a.last_login_at) : 'Never'),
          $('<td></td>').html(a.is_active ? '<span class="badge ok">Active</span>' : '<span class="badge past">Disabled</span>'),
          $('<td style="text-align:right"></td>').append($('<button type="button" class="btn btn-ghost btn-sm">Edit</button>').on('click', function () { adminForm(a, a.id === res.me); }))));
      });
      $table.empty().append($t);
    }, fail);
  }

  function adminForm(a, isMe) {
    var isNew = !a;
    var $f = $('<form novalidate>' +
      '<div class="field"><label for="ad-name">Name</label><input class="input" id="ad-name" name="name" maxlength="120"></div>' +
      '<div class="field"><label for="ad-email">Email</label><input class="input" type="email" id="ad-email" name="email" maxlength="190"></div>' +
      '<div class="field"><label for="ad-pass"></label><input class="input" type="password" id="ad-pass" name="password" autocomplete="new-password" minlength="10"><div class="field-hint">At least 10 characters. Share it with them privately.</div></div>' +
      '<label class="check"><input type="checkbox" name="is_active" checked> Active (can sign in)</label></form>');
    $f.find('label[for=ad-pass]').text(isNew ? 'Password' : 'New password (leave blank to keep)');
    if (a) {
      $f.find('[name=name]').val(a.name); $f.find('[name=email]').val(a.email).prop('disabled', true); $f.find('[name=is_active]').prop('checked', a.is_active);
      if (isMe) $f.find('[name=is_active]').closest('label').hide();
    }
    var $save = $('<button type="submit" class="btn">Save</button>');
    var $foot = $('<div style="display:flex;gap:10px;width:100%"></div>');
    if (a && !isMe) {
      $foot.append($('<button type="button" class="btn btn-quiet btn-sm">Remove</button>').on('click', function () {
        GPC.confirm({ title: 'Remove ' + a.name + '?', message: 'They will no longer be able to sign in.', ok: 'Remove', danger: true }).then(function (yes) {
          if (yes) post('admin_delete', { id: a.id }).then(function () { d.close(); viewAdmins(); }, fail);
        });
      }));
    }
    $foot.append('<span style="flex:1"></span>', $save);
    var d = GPC.dialog({ title: isNew ? 'Add administrator' : 'Edit administrator', body: $f, foot: $foot });
    $save.on('click', function (e) { e.preventDefault(); $f.trigger('submit'); });
    $f.on('submit', function (e) {
      e.preventDefault();
      GPC.formErrors($f, null);
      post('admin_save', { id: a ? a.id : 0, name: $f.find('[name=name]').val(), email: $f.find('[name=email]').val(), password: $f.find('[name=password]').val(), is_active: $f.find('[name=is_active]').is(':checked') })
        .then(function () { d.close(); GPC.toast('Saved'); viewAdmins(); }, function (err) { GPC.formErrors($f, err); });
    });
  }

  function passwordDialog() {
    var $f = $('<form novalidate>' +
      '<div class="field"><label for="pw-cur">Current password</label><input class="input" type="password" id="pw-cur" name="current" autocomplete="current-password"></div>' +
      '<div class="field"><label for="pw-new">New password</label><input class="input" type="password" id="pw-new" name="new" autocomplete="new-password"><div class="field-hint">At least 10 characters. Other devices will be signed out.</div></div></form>');
    var $save = $('<button type="submit" class="btn">Change password</button>');
    var d = GPC.dialog({ title: 'Change password', body: $f, foot: $save });
    $save.on('click', function (e) { e.preventDefault(); $f.trigger('submit'); });
    $f.on('submit', function (e) {
      e.preventDefault();
      GPC.formErrors($f, null);
      post('password', { current: $f.find('[name=current]').val(), 'new': $f.find('[name=new]').val() }).then(function (res) {
        S.csrf = res.csrf; d.close(); GPC.toast('Password changed');
      }, function (err) { GPC.formErrors($f, err); });
    });
  }

  init();
})(jQuery, window.GPC);
