/* Grove Park Collective — the booking form (new reservation and "change my reservation"). */
(function ($, GPC) {
  'use strict';

  var B = function () { return GPC.boot; };

  /**
   * GPC.BookingForm.open({
   *   mode: 'create' | 'edit', spaceId or spaceIds, date, start, end,
   *   booking (owner view, edit mode), token (edit mode),
   *   loadDay(date) → Promise<publicBookings[]>, onDone(ownerBooking), onConflict()
   * })
   * A reservation can use several spaces at the same time, and (when creating) repeat weekly.
   */
  function open(opts) {
    var boot = B(), edit = opts.mode === 'edit';
    var spaces = boot.spaces;
    var repeatWeeks = edit ? 0 : +(boot.rules.repeat_max_weeks || 0);
    var firstIds = (opts.spaceIds || [opts.spaceId || spaces[0].id]).map(Number).filter(function (id) { return find(id); });
    var state = {
      spaceIds: firstIds.length ? firstIds : [spaces[0].id],
      date: opts.date || boot.today,
      start: opts.start != null ? opts.start : null,
      end: opts.end != null ? opts.end : null,
      dayBookings: [],
      busy: false,
      repeat: false,
      until: null,
      skipConflicts: false,
      requestId: GPC.uuid()
    };
    var me = GPC.store.get('me', {});
    var inc = +boot.rules.time_increment;
    var multiDate = edit && (opts.booking.occasions || []).length > 1;

    var $form = $('<form novalidate autocomplete="on"></form>');
    $form.html(
      '<div class="when-card"><div class="wc-icon">' + GPC.icon('clock') + '</div><div><div class="wc-main"></div><div class="wc-sub"></div></div></div>' +
      '<div class="field bf-rooms"><span class="field-label" id="bf-rooms-label">' + (spaces.length > 1 ? 'Spaces' : 'Space') + '</span>' +
        '<div class="room-picks" role="group" aria-labelledby="bf-rooms-label"></div>' +
        (spaces.length > 1 ? '<div class="field-hint">Choose more than one to reserve them together.</div>' : '') +
      '</div>' +
      '<div class="field"><label for="bf-date">Date</label><input class="input" type="date" id="bf-date" name="date" required></div>' +
      '<div class="row">' +
        '<div class="field"><label for="bf-start">Start</label><select class="select" id="bf-start" name="start"></select></div>' +
        '<div class="field"><label for="bf-end">End</label><select class="select" id="bf-end" name="end"></select></div>' +
      '</div>' +
      '<div class="chips bf-durations" style="margin:-4px 0 10px" aria-label="Quick durations"></div>' +
      (repeatWeeks ?
        '<div class="bf-repeat">' +
          '<label class="check"><input type="checkbox" name="repeat"> <span>Repeat <span class="bf-repeat-day"></span> <span class="muted">(up to ' + repeatWeeks + ' weeks ahead)</span></span></label>' +
          '<div class="field bf-until" hidden><label for="bf-until">Until</label><input class="input" type="date" id="bf-until" name="repeat_until"><div class="field-hint bf-repeat-sum"></div></div>' +
        '</div>' : '') +
      '<div class="status-line bf-status" aria-live="polite" style="margin-bottom:16px"></div>' +
      '<div class="row">' +
        '<div class="field"><label for="bf-name">Your name</label><input class="input" id="bf-name" name="name" autocomplete="name" maxlength="120" required></div>' +
        '<div class="field bf-email-field"><label for="bf-email">Email</label><input class="input" type="email" id="bf-email" name="email" autocomplete="email" inputmode="email" maxlength="190" required></div>' +
      '</div>' +
      '<div class="field"><label for="bf-title">Meeting or event title <span class="opt">(optional)</span></label><input class="input" id="bf-title" name="title" maxlength="150" placeholder="e.g. Client meeting"></div>' +
      '<div class="field"><label for="bf-company">Company <span class="opt">(optional)</span></label><input class="input" id="bf-company" name="company" autocomplete="organization" maxlength="150"></div>' +
      '<button type="button" class="more-toggle bf-notes-toggle">+ Add a note</button>' +
      '<div class="field bf-notes" hidden><label for="bf-notes">Notes <span class="opt">(optional — only management sees these)</span></label><textarea class="textarea" id="bf-notes" name="notes" maxlength="2000"></textarea></div>' +
      '<label class="check"><input type="checkbox" name="is_private"> <span>Keep my name off the shared calendar <span class="muted">(others will just see “Reserved”)</span></span></label>' +
      '<div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
    );

    var $submit = $('<button type="submit" class="btn"></button>').text(edit ? 'Save changes' : 'Reserve Space');
    var $foot = $('<div style="display:contents"></div>').append(
      $('<div class="fine"></div>').text(edit ? 'We’ll email you the updated details.' : 'We’ll email a confirmation with a link to change or cancel. No account needed.'),
      $submit
    );

    var $picks = $form.find('.room-picks');
    spaces.forEach(function (s) {
      var $p = $('<label class="room-pick"></label>').append(
        $('<input type="checkbox" name="space_ids">').val(s.id).prop('checked', state.spaceIds.indexOf(s.id) >= 0),
        $('<span class="rp-dot" aria-hidden="true"></span>'),
        $('<span class="rp-name"></span>').text(s.name));
      $p[0].style.setProperty('--c', s.color);
      $picks.append($p);
    });
    $form.find('[name=date]').attr({ min: boot.today, max: GPC.date.addDays(boot.today, maxAhead()) });

    if (!edit && boot.rules.require_approval) {
      $form.find('.bf-email-field').closest('.row').after('<div class="field-hint" style="margin:-6px 0 14px">Use your work email. New addresses are approved once by building management — until then your time is held for you.</div>');
    }
    if (edit) {
      var b = opts.booking;
      $form.find('[name=name]').val(b.name);
      $form.find('.bf-email-field').html('<span class="field-label">Email</span><div style="padding:12px 0 0;color:var(--ink-2)"></div>')
        .find('div').text(b.email);
      $form.find('[name=title]').val(b.title || '');
      $form.find('[name=company]').val(b.company || '');
      $form.find('[name=notes]').val(b.notes || '');
      $form.find('[name=is_private]').prop('checked', !!b.is_private);
      if (b.notes) { $form.find('.bf-notes').prop('hidden', false); $form.find('.bf-notes-toggle').hide(); }
      if (multiDate) {
        $form.find('.bf-status').after($('<div class="field-hint" style="margin:-8px 0 16px"></div>').text(
          'Time and space changes apply to this date only. Name, title, company and notes apply to all of its dates.'));
      }
    } else {
      $form.find('[name=name]').val(me.name || '');
      $form.find('[name=email]').val(me.email || '');
      $form.find('[name=company]').val(me.company || '');
    }

    var dlg = GPC.dialog({
      eyebrow: edit ? 'Change reservation · ' + opts.booking.ref : 'New reservation',
      title: title(),
      body: $form,
      foot: $foot
    });
    $submit.on('click', function (e) { e.preventDefault(); $form.trigger('submit'); });

    // ------------------------------------------------------------- helpers
    function find(id) { return spaces.filter(function (s) { return s.id === +id; })[0] || null; }
    function chosen() { return state.spaceIds.map(find).filter(Boolean); }
    function title() { return chosen().map(function (s) { return chosen().length > 1 ? (s.short_name || s.name) : s.name; }).join(' + '); }
    function maxAhead() { return Math.max.apply(null, spaces.map(function (s) { return s.max_days_ahead; })); }
    /** The hours every chosen space is open on the date, or null. */
    function hours() {
      var lo = 0, hi = 1440, open = true;
      chosen().forEach(function (s) {
        var h = GPC.hoursOn(s, state.date);
        if (!h) { open = false; return; }
        lo = Math.max(lo, h[0]); hi = Math.min(hi, h[1]);
      });
      return open && hi > lo ? [lo, hi] : null;
    }
    function earliest() {
      // Can't start more than 15 minutes in the past.
      if (state.date !== B().today) return 0;
      return Math.ceil((B().nowMin - 15) / inc) * inc;
    }
    function maxDur() { return Math.min.apply(null, chosen().map(function (s) { return s.max_duration_minutes; })); }
    function others() {
      var mine = edit ? (opts.booking.refs || [opts.booking.ref]) : [];
      return state.dayBookings.filter(function (x) {
        return state.spaceIds.indexOf(x.space_id) >= 0 && mine.indexOf(x.ref) < 0;
      });
    }
    function untilMax() {
      var ahead = Math.min.apply(null, chosen().map(function (s) { return s.max_days_ahead; }));
      return GPC.date.addDays(B().today, Math.min(repeatWeeks * 7, ahead));
    }
    function repeatDates() {
      var out = [state.date];
      if (state.repeat && state.until) {
        for (var d = GPC.date.addDays(state.date, 7); d <= state.until; d = GPC.date.addDays(d, 7)) out.push(d);
      }
      return out;
    }
    function resetConflicts() {
      state.skipConflicts = false;
      $form.find('.bf-conflicts').remove();
    }

    function renderTimes() {
      var hrs = hours(), $s = $form.find('[name=start]'), $e = $form.find('[name=end]');
      $s.empty(); $e.empty();
      if (!hrs) {
        $s.append('<option value="">Closed</option>'); $e.append('<option value="">—</option>');
        return;
      }
      var first = Math.max(hrs[0], earliest());
      for (var m = Math.ceil(hrs[0] / inc) * inc; m < hrs[1]; m += inc) {
        if (m < first) continue;
        $s.append($('<option></option>').val(m).text(GPC.time.fmt(m)));
      }
      if (!$s.children().length) { $s.append('<option value="">No times left today</option>'); state.start = null; return; }
      if (state.start == null || state.start < first || state.start >= hrs[1]) {
        state.start = +$s.children().first().val();
      }
      $s.val(String(state.start));
      if (String($s.val()) !== String(state.start)) { state.start = +$s.children().first().val(); $s.val(String(state.start)); }

      var minD = +B().rules.min_duration_minutes;
      var lastEnd = Math.min(hrs[1], state.start + maxDur());
      for (var e2 = state.start + Math.max(inc, Math.ceil(minD / inc) * inc); e2 <= lastEnd; e2 += inc) {
        $e.append($('<option></option>').val(e2).text(GPC.time.fmt(e2) + '  ·  ' + GPC.time.duration(e2 - state.start)));
      }
      if (state.end == null || state.end <= state.start || state.end > lastEnd) {
        state.end = Math.min(state.start + 60, lastEnd);
        state.end = Math.max(state.end, +$e.children().first().val());
      }
      $e.val(String(state.end));
      if (String($e.val()) !== String(state.end)) { state.end = +$e.children().first().val(); $e.val(String(state.end)); }

      var $chips = $form.find('.bf-durations').empty();
      [30, 60, 90, 120, 180, 240].forEach(function (d) {
        if (d < minD) return;
        $('<button type="button" class="chip"></button>').text(d < 60 ? d + ' min' : (d / 60) + (d === 60 ? ' hr' : ' hrs'))
          .attr('aria-label', GPC.time.duration(d))
          .toggleClass('is-on', state.end - state.start === d)
          .prop('disabled', state.start + d > lastEnd)
          .data('d', d).appendTo($chips);
      });
    }

    function renderRepeat() {
      if (!repeatWeeks) return;
      var $r = $form.find('.bf-repeat');
      var min = GPC.date.addDays(state.date, 7), max = untilMax(), can = min <= max;
      $r.find('.bf-repeat-day').text('every ' + GPC.date.dayName(state.date));
      $r.find('[name=repeat]').prop('disabled', !can);
      $r.toggleClass('is-off', !can);
      if (!can) { state.repeat = false; $r.find('[name=repeat]').prop('checked', false); }
      $r.find('.bf-until').prop('hidden', !state.repeat);
      var $u = $r.find('[name=repeat_until]').attr({ min: min, max: max });
      if (!state.repeat) return;
      if (!state.until || state.until < min) state.until = GPC.date.addDays(state.date, 21); // four dates to start with
      if (state.until > max) state.until = max;
      // Keep the end date on the same weekday, so the last date isn't a surprise.
      state.until = GPC.date.addDays(state.date, Math.floor(GPC.date.diffDays(state.date, state.until) / 7) * 7);
      $u.val(state.until);
      var dates = repeatDates();
      $r.find('.bf-repeat-sum').text(dates.length + ' dates: ' + GPC.date.monthDay(dates[0]) + ' – ' + GPC.date.monthDay(dates[dates.length - 1]) +
        '. We check every date when you reserve.');
    }

    function renderStatus() {
      var list = chosen(), hrs = hours(), $st = $form.find('.bf-status').removeClass('ok bad');
      dlg.setTitle(title());
      var $wc = $form.find('.when-card');
      $wc[0].style.setProperty('--c', list[0].color);
      $wc.find('.wc-main').text(GPC.date.long(state.date) + (state.date === B().today ? ' (today)' : ''));
      var n = repeatDates().length;
      $submit.text(edit ? 'Save changes' : (n > 1 ? 'Reserve ' + n + ' dates' : (list.length > 1 ? 'Reserve Spaces' : 'Reserve Space')));
      if (!hrs) {
        var closed = list.filter(function (s) { return !GPC.hoursOn(s, state.date); });
        $wc.find('.wc-sub').text('Closed this day');
        $st.addClass('bad').html('<span class="dot"></span>').append(document.createTextNode(closed.length
          ? GPC.andList(closed.map(function (s) { return s.name; })) + (closed.length > 1 ? ' aren’t' : ' isn’t') + ' available on ' + GPC.date.dayName(state.date) + 's.'
          : 'These spaces aren’t open at the same time on ' + GPC.date.dayName(state.date) + 's. Try fewer spaces.'));
        $submit.prop('disabled', true);
        return;
      }
      if (state.start == null || state.end == null || isNaN(state.start)) {
        $wc.find('.wc-sub').text('No times available');
        $submit.prop('disabled', true);
        return;
      }
      $wc.find('.wc-sub').text(GPC.time.range(state.start, state.end) + ' · ' + GPC.time.duration(state.end - state.start));
      var clash = others().filter(function (x) { return x.start_min < state.end && x.end_min > state.start; })[0];
      if (clash) {
        $st.addClass('bad').html('<span class="dot"></span>').append(document.createTextNode(
          (list.length > 1 ? find(clash.space_id).name + ' is ' : '') +
          (clash.kind === 'block' ? (list.length > 1 ? 'unavailable ' : 'Unavailable ') : (list.length > 1 ? 'already reserved ' : 'Already reserved ')) +
          GPC.time.range(clash.start_min, clash.end_min) + ' — please pick another time.'));
        $submit.prop('disabled', true);
      } else {
        $st.addClass('ok').html('<span class="dot"></span>').append(document.createTextNode(
          n > 1 ? 'Available on ' + GPC.date.short(state.date) : (list.length > 1 ? 'All available' : 'Available')));
        $submit.prop('disabled', false);
      }
    }

    function loadDay() {
      var token = {};
      loadDay.last = token;
      $form.find('.bf-status').removeClass('ok bad').html('<span class="spinner"></span> Checking availability…');
      return opts.loadDay(state.date).then(function (list) {
        if (loadDay.last !== token) return;
        state.dayBookings = list;
        renderAll();
      }, function () {
        state.dayBookings = [];
        renderAll();
      });
    }
    function renderAll() { renderTimes(); renderRepeat(); renderStatus(); }

    /** Some dates of a repeat are taken: list them and offer to reserve the rest. */
    function showConflicts(err) {
      var d = err.data;
      var $c = $('<div class="alert alert-warn bf-conflicts" role="alert"><div style="flex:1"><strong></strong><ul class="conflict-list"></ul></div></div>');
      $c.find('strong').text(err.message);
      d.conflicts.forEach(function (c) {
        $c.find('ul').append($('<li></li>').text(GPC.date.short(c.date) + ' · ' + c.space + (c.kind === 'block' ? ' is unavailable ' : ' is reserved ') +
          GPC.time.range(GPC.time.toMin(c.start), GPC.time.toMin(c.end))));
      });
      if (d.creatable > 0) {
        var label = 'Reserve the other ' + d.creatable + (d.creatable === 1 ? ' date' : ' dates');
        $c.find('div').append(
          $('<p style="margin:8px 0 10px"></p>').text('You can reserve the dates that are free, or change the time or end date and try again.'),
          $('<button type="button" class="btn btn-sm"></button>').text(label).on('click', function () { state.skipConflicts = true; $form.trigger('submit'); }));
      }
      $form.find('.bf-conflicts').remove();
      $form.prepend($c);
      dlg.$body.scrollTop(0);
    }

    // ------------------------------------------------------------- events
    $form.on('change', '[name=space_ids]', function () {
      var ids = $form.find('[name=space_ids]:checked').map(function () { return +this.value; }).get();
      if (!ids.length) { this.checked = true; return; } // at least one space
      state.spaceIds = ids; resetConflicts(); renderAll();
    });
    $form.find('[name=date]').val(state.date).on('change', function () {
      if (!GPC.date.isValid(this.value)) return;
      state.date = this.value; resetConflicts(); loadDay();
    });
    $form.on('change', '[name=start]', function () {
      var dur = state.end - state.start;
      state.start = +this.value; state.end = state.start + dur; resetConflicts(); renderAll();
    });
    $form.on('change', '[name=end]', function () { state.end = +this.value; resetConflicts(); renderAll(); });
    $form.on('click', '.bf-durations .chip', function () { state.end = state.start + $(this).data('d'); resetConflicts(); renderAll(); });
    $form.on('change', '[name=repeat]', function () { state.repeat = this.checked; resetConflicts(); renderRepeat(); renderStatus(); });
    $form.on('change', '[name=repeat_until]', function () {
      if (GPC.date.isValid(this.value)) state.until = this.value;
      resetConflicts(); renderRepeat(); renderStatus();
    });
    $form.find('.bf-notes-toggle').on('click', function () {
      $(this).hide(); $form.find('.bf-notes').prop('hidden', false).find('textarea').trigger('focus');
    });

    $form.on('submit', function (e) {
      e.preventDefault();
      if (state.busy) return;
      GPC.formErrors($form, null);
      var v = function (n) { return $.trim($form.find('[name=' + n + ']').val() || ''); };
      if (!v('name')) return GPC.formErrors($form, { field: 'name', message: 'Please enter your name.' });
      if (!edit && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v('email'))) return GPC.formErrors($form, { field: 'email', message: 'Please enter a valid email address, like name@company.com.' });

      var payload = {
        space_ids: state.spaceIds, date: state.date,
        start: GPC.time.toHHMM(state.start), end: GPC.time.toHHMM(state.end),
        name: v('name'), title: v('title'), company: v('company'), notes: v('notes'),
        is_private: $form.find('[name=is_private]').is(':checked')
      };
      var url;
      if (edit) {
        url = 'api.php?action=update';
        payload.t = opts.token;
      } else {
        url = 'api.php?action=book';
        payload.email = v('email');
        payload.website = $form.find('[name=website]').val();
        payload.form_token = B().form_token;
        payload.request_id = state.requestId;
        if (state.repeat) { payload.repeat = 'weekly'; payload.repeat_until = state.until; }
        if (state.skipConflicts) payload.skip_conflicts = true;
        GPC.store.set('me', { name: payload.name, email: payload.email, company: payload.company });
        GPC.store.set('pending', { request_id: state.requestId, at: Date.now() });
      }

      state.busy = true;
      var label = $submit.text();
      $submit.addClass('is-busy').html('<span class="spinner"></span> ' + (edit ? 'Saving…' : 'Reserving…'));
      // The server ignores forms submitted within ~2 seconds of the page loading (bot check).
      var wait = Math.max(0, 2600 - (Date.now() - B().loadedAt));
      setTimeout(function () {
        GPC.api(url, 'POST', payload).then(function (res) {
          GPC.store.del('pending');
          GPC.rememberMine(res.booking);
          showConfirmation(res.booking);
          if (opts.onDone) opts.onDone(res.booking);
        }, function (err) {
          if (err.status !== 0) GPC.store.del('pending');
          state.busy = false;
          state.skipConflicts = false;
          $submit.removeClass('is-busy').text(label);
          if (err.code === 'conflict' && err.data && err.data.conflicts) {
            showConflicts(err);
            if (opts.onConflict) opts.onConflict();
          } else if (err.code === 'conflict') {
            GPC.formErrors($form, err);
            loadDay();
            if (opts.onConflict) opts.onConflict();
          } else if (err.code === 'stale_form') {
            GPC.formErrors($form, { message: err.message + ' (Your details are saved.)' });
          } else {
            GPC.formErrors($form, err);
          }
        });
      }, wait);
    });

    function showConfirmation(bk) {
      var dates = (bk.occasions || []).filter(function (o) { return o.status !== 'cancelled'; });
      var many = !edit && dates.length > 1;
      var rows = [[(bk.spaces || []).length > 1 ? 'Spaces' : 'Space', bk.space_name]];
      if (many) {
        var sameDay = dates.every(function (o) { return GPC.date.weekday(o.date) === GPC.date.weekday(dates[0].date); });
        rows.push(['Dates', dates.length + (sameDay ? ' ' + GPC.date.dayName(dates[0].date) + 's' : ' dates') + ', ' +
          GPC.date.monthDay(dates[0].date) + ' – ' + GPC.date.monthDay(dates[dates.length - 1].date)]);
      } else {
        rows.push(['Date', GPC.date.long(bk.date, true)]);
      }
      rows.push(['Time', GPC.time.range(bk.start_min, bk.end_min) + ' (' + GPC.time.duration(bk.end_min - bk.start_min) + ')']);
      if (bk.title) rows.push(['Title', bk.title]);
      rows.push(['Reference', bk.ref]);
      var pending = bk.status === 'pending';
      if (pending) rows.push(['Status', 'Awaiting approval']);
      var $c = $('<div class="confirm"></div>');
      $c.append('<div class="confirm-icon' + (pending ? ' is-pending' : '') + '">' + GPC.icon(pending ? 'clock' : 'check') + '</div>');
      if (pending) {
        $c.append($('<h3></h3>').text(edit ? 'Your request is updated.' : 'Request received.'));
        $c.append($('<p></p>').text(B().org_name + ' approves each new email address once. We’ve asked building management to approve ' + bk.email +
          ' — you’ll get a confirmation email as soon as they do. Your time is held for you until then.'));
      } else {
        $c.append($('<h3></h3>').text(edit ? 'Your reservation is updated.' : 'Your reservation is confirmed.'));
        $c.append($('<p></p>').text('A confirmation is on its way to ' + bk.email + '. It includes a link to change or cancel' + (many ? ' any date.' : '.')));
      }
      var $t = $('<div class="ticket"></div>');
      rows.forEach(function (r) { $t.append($('<div class="ticket-row"></div>').append($('<span></span>').text(r[0]), $('<strong></strong>').text(r[1]))); });
      $c.append($t);
      var $acts = $('<div class="confirm-actions"></div>');
      if (!pending) {
        $acts.append($('<a class="btn btn-ghost btn-sm" download></a>').attr('href', 'ics.php?t=' + encodeURIComponent(bk.manage_token)).html(GPC.icon('cal') + (many ? ' Add all dates to calendar' : ' Add to calendar')));
        if (!many) $acts.append($('<a class="btn btn-ghost btn-sm" target="_blank" rel="noopener"></a>').attr('href', GPC.googleCalUrl(bk, { name: bk.space_name })).text('Google Calendar'));
      }
      $acts.append($('<a class="btn btn-ghost btn-sm"></a>').attr('href', 'manage.php?t=' + encodeURIComponent(bk.manage_token)).text(pending ? 'View or cancel request' : 'Manage reservation'));
      $c.append($acts);
      dlg.setTitle('', '');
      dlg.$body.empty().append($c);
      dlg.$foot.empty().append($('<button type="button" class="btn btn-block">Done</button>').on('click', dlg.close));
      dlg.$body.scrollTop(0);
    }

    loadDay();
    return dlg;
  }

  GPC.googleCalUrl = function (bk, space) {
    var d = bk.date.replace(/-/g, '');
    var t = function (m) { return m >= 1440 ? '235900' : GPC.time.toHHMM(m).replace(':', '') + '00'; };
    return 'https://calendar.google.com/calendar/render?action=TEMPLATE' +
      '&text=' + encodeURIComponent((bk.title ? bk.title + ' — ' : '') + space.name) +
      '&dates=' + d + 'T' + t(bk.start_min) + '/' + d + 'T' + t(bk.end_min) +
      '&ctz=' + encodeURIComponent(B().timezone) +
      '&location=' + encodeURIComponent(space.name + ', ' + B().org_name) +
      '&details=' + encodeURIComponent('Reference ' + bk.ref + '\nManage: ' + B().base_url + '/manage.php?t=' + bk.manage_token);
  };

  /** ['A'] → "A", ['A', 'B'] → "A and B", ['A', 'B', 'C'] → "A, B and C" */
  GPC.andList = function (items) {
    return items.length < 2 ? (items[0] || '') : items.slice(0, -1).join(', ') + ' and ' + items[items.length - 1];
  };

  /**
   * Remember reservations made in this browser so the calendar can highlight "yours".
   * An owner view lists every room and date still booked with it in `mine`.
   */
  GPC.rememberMine = function (bk) {
    if (!bk || !bk.ref) return;
    var mine = GPC.store.get('mine', {});
    (bk.refs || [bk.ref]).forEach(function (ref) { delete mine[ref]; });
    if (bk.mine) {
      Object.keys(bk.mine).forEach(function (ref) { mine[ref] = bk.mine[ref]; });
    } else if (bk.status !== 'cancelled') {
      mine[bk.ref] = { t: bk.manage_token, d: bk.date };
    }
    var keys = Object.keys(mine);
    if (keys.length > 60) {
      keys.sort(function (a, b) { return (mine[a].d || '').localeCompare(mine[b].d || ''); });
      keys.slice(0, keys.length - 60).forEach(function (k) { delete mine[k]; });
    }
    GPC.store.set('mine', mine);
  };

  GPC.BookingForm = { open: open };
})(jQuery, window.GPC);
