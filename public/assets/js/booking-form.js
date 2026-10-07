/* Grove Park Collective — the booking form (new reservation and "change my reservation"). */
(function ($, GPC) {
  'use strict';

  var B = function () { return GPC.boot; };

  /**
   * GPC.BookingForm.open({
   *   mode: 'create' | 'edit', spaceId, date, start, end,
   *   booking (owner view, edit mode), token (edit mode),
   *   loadDay(date) → Promise<publicBookings[]>, onDone(ownerBooking)
   * })
   */
  function open(opts) {
    var boot = B(), edit = opts.mode === 'edit';
    var spaces = boot.spaces;
    var state = {
      spaceId: +opts.spaceId || spaces[0].id,
      date: opts.date || boot.today,
      start: opts.start != null ? opts.start : null,
      end: opts.end != null ? opts.end : null,
      dayBookings: [],
      busy: false,
      requestId: GPC.uuid()
    };
    var me = GPC.store.get('me', {});
    var inc = +boot.rules.time_increment;

    var $form = $('<form novalidate autocomplete="on"></form>');
    $form.html(
      '<div class="when-card"><div class="wc-icon">' + GPC.icon('clock') + '</div><div><div class="wc-main"></div><div class="wc-sub"></div></div></div>' +
      '<div class="field"><label for="bf-space">Space</label><select class="select" id="bf-space" name="space_id"></select></div>' +
      '<div class="field"><label for="bf-date">Date</label><input class="input" type="date" id="bf-date" name="date" required></div>' +
      '<div class="row">' +
        '<div class="field"><label for="bf-start">Start</label><select class="select" id="bf-start" name="start"></select></div>' +
        '<div class="field"><label for="bf-end">End</label><select class="select" id="bf-end" name="end"></select></div>' +
      '</div>' +
      '<div class="chips bf-durations" style="margin:-4px 0 10px" aria-label="Quick durations"></div>' +
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

    spaces.forEach(function (s) {
      $form.find('[name=space_id]').append($('<option></option>').val(s.id).text(s.name));
    });
    $form.find('[name=date]').attr({ min: boot.today, max: GPC.date.addDays(boot.today, maxAhead()) });

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
    } else {
      $form.find('[name=name]').val(me.name || '');
      $form.find('[name=email]').val(me.email || '');
      $form.find('[name=company]').val(me.company || '');
    }

    var dlg = GPC.dialog({
      eyebrow: edit ? 'Change reservation · ' + opts.booking.ref : 'New reservation',
      title: spaceById(state.spaceId).name,
      body: $form,
      foot: $foot
    });
    $submit.on('click', function (e) { e.preventDefault(); $form.trigger('submit'); });

    // ------------------------------------------------------------- helpers
    function spaceById(id) { return spaces.filter(function (s) { return s.id === +id; })[0] || spaces[0]; }
    function maxAhead() { return Math.max.apply(null, spaces.map(function (s) { return s.max_days_ahead; })); }
    function hours() { return GPC.hoursOn(spaceById(state.spaceId), state.date); }
    function earliest() {
      // Can't start more than 15 minutes in the past.
      if (state.date !== B().today) return 0;
      return Math.ceil((B().nowMin - 15) / inc) * inc;
    }
    function maxDur() { return spaceById(state.spaceId).max_duration_minutes; }
    function others() {
      return state.dayBookings.filter(function (x) {
        return x.space_id === state.spaceId && !(edit && x.ref === opts.booking.ref);
      });
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
      if (!$s.children().length) { $s.append('<option value="">No times left today</option>'); return; }
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

    function renderStatus() {
      var space = spaceById(state.spaceId), hrs = hours(), $st = $form.find('.bf-status').removeClass('ok bad');
      dlg.setTitle(space.name);
      var $wc = $form.find('.when-card');
      $wc[0].style.setProperty('--c', space.color);
      $wc.find('.wc-main').text(GPC.date.long(state.date) + (state.date === B().today ? ' (today)' : ''));
      if (!hrs) {
        $wc.find('.wc-sub').text('Closed this day');
        $st.addClass('bad').html('<span class="dot"></span>').append(document.createTextNode(space.name + ' isn’t available on ' + GPC.date.dayName(state.date) + 's.'));
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
          (clash.kind === 'block' ? 'Unavailable ' : 'Already reserved ') + GPC.time.range(clash.start_min, clash.end_min) + ' — please pick another time.'));
        $submit.prop('disabled', true);
      } else {
        $st.addClass('ok').html('<span class="dot"></span>').append(document.createTextNode('Available'));
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
        renderTimes();
        renderStatus();
      }, function () {
        state.dayBookings = [];
        renderTimes();
        renderStatus();
      });
    }

    // ------------------------------------------------------------- events
    $form.find('[name=space_id]').val(String(state.spaceId)).on('change', function () {
      state.spaceId = +this.value; renderTimes(); renderStatus();
    });
    $form.find('[name=date]').val(state.date).on('change', function () {
      if (!GPC.date.isValid(this.value)) return;
      state.date = this.value; loadDay();
    });
    $form.on('change', '[name=start]', function () {
      var dur = state.end - state.start;
      state.start = +this.value; state.end = state.start + dur; renderTimes(); renderStatus();
    });
    $form.on('change', '[name=end]', function () { state.end = +this.value; renderTimes(); renderStatus(); });
    $form.on('click', '.bf-durations .chip', function () { state.end = state.start + $(this).data('d'); renderTimes(); renderStatus(); });
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
        space_id: state.spaceId, date: state.date,
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
        GPC.store.set('me', { name: payload.name, email: payload.email, company: payload.company });
        GPC.store.set('pending', { request_id: state.requestId, at: Date.now() });
      }

      state.busy = true;
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
          $submit.removeClass('is-busy').text(edit ? 'Save changes' : 'Reserve Space');
          if (err.code === 'conflict') {
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
      var space = spaceById(bk.space_id);
      var rows = [
        ['Space', space.name],
        ['Date', GPC.date.long(bk.date, true)],
        ['Time', GPC.time.range(bk.start_min, bk.end_min) + ' (' + GPC.time.duration(bk.end_min - bk.start_min) + ')']
      ];
      if (bk.title) rows.push(['Title', bk.title]);
      rows.push(['Reference', bk.ref]);
      var $c = $('<div class="confirm"></div>');
      $c.append('<div class="confirm-icon">' + GPC.icon('check') + '</div>');
      $c.append($('<h3></h3>').text(edit ? 'Your reservation is updated.' : 'Your reservation is confirmed.'));
      $c.append($('<p></p>').text('A confirmation is on its way to ' + bk.email + '. It includes a link to change or cancel.'));
      var $t = $('<div class="ticket"></div>');
      rows.forEach(function (r) { $t.append($('<div class="ticket-row"></div>').append($('<span></span>').text(r[0]), $('<strong></strong>').text(r[1]))); });
      $c.append($t);
      var $acts = $('<div class="confirm-actions"></div>');
      $acts.append($('<a class="btn btn-ghost btn-sm" download></a>').attr('href', 'ics.php?t=' + encodeURIComponent(bk.manage_token)).html(GPC.icon('cal') + ' Add to calendar'));
      $acts.append($('<a class="btn btn-ghost btn-sm" target="_blank" rel="noopener"></a>').attr('href', GPC.googleCalUrl(bk, space)).text('Google Calendar'));
      $acts.append($('<a class="btn btn-ghost btn-sm"></a>').attr('href', 'manage.php?t=' + encodeURIComponent(bk.manage_token)).text('Manage reservation'));
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

  /** Remember reservations made in this browser so the calendar can highlight "yours". */
  GPC.rememberMine = function (bk) {
    if (!bk || !bk.ref) return;
    var mine = GPC.store.get('mine', {});
    if (bk.status === 'cancelled') { delete mine[bk.ref]; } else { mine[bk.ref] = { t: bk.manage_token, d: bk.date }; }
    var keys = Object.keys(mine);
    if (keys.length > 60) {
      keys.sort(function (a, b) { return (mine[a].d || '').localeCompare(mine[b].d || ''); });
      keys.slice(0, keys.length - 60).forEach(function (k) { delete mine[k]; });
    }
    GPC.store.set('mine', mine);
  };

  GPC.BookingForm = { open: open };
})(jQuery, window.GPC);
