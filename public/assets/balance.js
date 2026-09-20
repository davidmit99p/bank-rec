// -----------------------------------------------------------------------------
// "What would balance this?"
//
// You tick a few items, they do not come to the same on both sides, and the
// question is always the same: what else is in here that would close the gap?
// Doing that by eye is where the time goes, especially when the answer is three
// small items rather than one obvious one.
//
// This looks through the items on the page for a set that closes it exactly -
// on either side, because the answer is sometimes a posting and its reversal on
// your own side rather than anything opposite.
//
// It works in whole pence, so nothing is lost to rounding, and it looks for the
// smallest, closest-dated answer first.
// -----------------------------------------------------------------------------
(function () {
  var form = document.getElementById('txnForm');
  if (!form) return;

  var MAX_SIZE  = 4;      // most items in one suggestion
  var MAX_POOL  = 250;    // most candidates considered per side
  var MAX_SHOWN = 5;      // most suggestions offered

  var btn   = document.getElementById('balanceBtn');
  var panel = document.getElementById('balancePanel');
  if (!btn || !panel) return;

  function pence(v) { return Math.round(parseFloat(v || 0) * 100); }
  function money(p) {
    return (p / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  // Everything ticked, and everything still available, on one side.
  function readSide(tag) {
    var ticked = [], free = [];
    form.querySelectorAll('.tick[data-side="' + tag + '"]').forEach(function (c) {
      if (c.dataset.matched === '1') return;          // matched rows are a different job
      var row  = c.closest('tr');
      var item = {
        id:    c.value,
        box:   c,
        p:     pence(c.dataset.value),
        date:  (row.querySelector('td.small') || {}).textContent || '',
        desc:  (row.querySelector('td.desc') || {}).textContent || ''
      };
      (c.checked ? ticked : free).push(item);
    });
    return { ticked: ticked, free: free };
  }

  function sum(rows) {
    var t = 0;
    rows.forEach(function (r) { t += r.p; });
    return t;
  }

  // Days between two yyyy-mm-dd dates, or 0 when either is missing.
  function daysApart(a, b) {
    if (!a || !b) return 0;
    var x = Date.parse(a), y = Date.parse(b);
    if (isNaN(x) || isNaN(y)) return 0;
    return Math.abs(x - y) / 86400000;
  }

  // Sets of up to four of these rows that come to exactly this much.
  //
  // Done with lookup tables rather than by trying every combination: every
  // single value, then every pair, and then each of those against the rest.
  // A few hundred rows is nothing this way, where trying every combination of
  // four would be billions.
  function findSets(rows, target, want) {
    var out = [], seen = {};

    function keep(set) {
      var ids = set.map(function (r) { return r.id; }).sort().join(',');
      if (seen[ids]) return;
      seen[ids] = true;
      out.push(set);
    }

    // one row
    rows.forEach(function (r) { if (r.p === target) keep([r]); });

    // two rows: for each row, is the rest of the gap sitting in the list?
    var byValue = {};
    rows.forEach(function (r, i) { (byValue[r.p] = byValue[r.p] || []).push(i); });
    for (var i = 0; i < rows.length && out.length < want * 4; i++) {
      var need = target - rows[i].p;
      (byValue[need] || []).forEach(function (j) {
        if (j > i) keep([rows[i], rows[j]]);
      });
    }

    // three and four rows, through the sums of every pair
    if (rows.length <= MAX_POOL) {
      var pairs = {};
      for (var a = 0; a < rows.length; a++) {
        for (var b = a + 1; b < rows.length; b++) {
          var s = rows[a].p + rows[b].p;
          (pairs[s] = pairs[s] || []).push([a, b]);
        }
      }
      // three: one row plus a pair
      for (var k = 0; k < rows.length && out.length < want * 4; k++) {
        var want3 = target - rows[k].p;
        (pairs[want3] || []).forEach(function (pr) {
          if (pr[0] !== k && pr[1] !== k) keep([rows[k], rows[pr[0]], rows[pr[1]]]);
        });
      }
      // four: a pair plus a pair
      if (MAX_SIZE >= 4) {
        var sums = Object.keys(pairs);
        for (var m = 0; m < sums.length && out.length < want * 4; m++) {
          var s1 = parseInt(sums[m], 10);
          var other = pairs[target - s1];
          if (!other) continue;
          pairs[s1].forEach(function (p1) {
            other.forEach(function (p2) {
              if (p1[0] === p2[0] || p1[0] === p2[1] || p1[1] === p2[0] || p1[1] === p2[1]) return;
              keep([rows[p1[0]], rows[p1[1]], rows[p2[0]], rows[p2[1]]]);
            });
          });
        }
      }
    }
    return out;
  }

  // The answer you would rather be given: fewest items, then closest in date to
  // what you ticked, then smallest amounts.
  function rank(sets, anchorDate) {
    return sets.sort(function (x, y) {
      if (x.length !== y.length) return x.length - y.length;
      var dx = 0, dy = 0;
      x.forEach(function (r) { dx += daysApart(r.date, anchorDate); });
      y.forEach(function (r) { dy += daysApart(r.date, anchorDate); });
      if (dx !== dy) return dx - dy;
      return Math.abs(sum(x)) - Math.abs(sum(y));
    });
  }

  function draw(html) { panel.innerHTML = html; panel.hidden = !html; }

  function look() {
    var L = readSide('L'), B = readSide('B');
    var nTicked = L.ticked.length + B.ticked.length;
    if (!nTicked) {
      draw('<p class="muted" style="margin:0">Tick the items you are working on first, then ask again.</p>');
      return;
    }

    var gap = sum(L.ticked) - sum(B.ticked);
    if (gap === 0) {
      draw('<p style="margin:0">Those already balance &mdash; press <b>Match ticked items</b>.</p>');
      return;
    }

    // adding to the left raises the gap, adding to the right lowers it
    var anchor = (L.ticked[0] || B.ticked[0] || {}).date || '';
    var found = [];
    [['L', L.free, -gap, document.getElementById('balanceBtn').dataset.left],
     ['B', B.free,  gap, document.getElementById('balanceBtn').dataset.right]].forEach(function (s) {
      var pool = s[1];
      var capped = pool.length > MAX_POOL;
      if (capped) pool = pool.slice(0, MAX_POOL);
      rank(findSets(pool, s[2], MAX_SHOWN), anchor).slice(0, MAX_SHOWN).forEach(function (set) {
        found.push({ side: s[0], label: s[3], rows: set, capped: capped });
      });
    });

    if (!found.length) {
      draw('<p style="margin:0"><b>Nothing on this page closes that gap</b> of ' + money(Math.abs(gap))
         + ' with ' + MAX_SIZE + ' items or fewer. Try a wider filter, more rows per page, '
         + 'or split an item that covers more than one.</p>');
      return;
    }

    found.sort(function (a, b) { return a.rows.length - b.rows.length; });
    found = found.slice(0, MAX_SHOWN);

    var html = '<p style="margin:0 0 .4rem">The two sides are <b>' + money(Math.abs(gap))
             + '</b> apart. Any of these would close that exactly:</p>';
    found.forEach(function (f, i) {
      html += '<div class="bal-option"><div class="bal-rows">';
      html += '<b>' + f.label + '</b>: ';
      html += f.rows.map(function (r) {
        return '<span class="bal-row">' + r.date + ' &middot; '
             + (r.desc || '').replace(/[<>&]/g, '') + ' <b>' + money(r.p) + '</b></span>';
      }).join(' + ');
      html += '</div><button type="button" class="btn small bal-tick" data-which="' + i + '">Tick these</button></div>';
    });
    html += '<p class="small muted" style="margin:.4rem 0 0">Only items on this page are considered, '
          + 'and only sets of ' + MAX_SIZE + ' or fewer. Ticking them does not match anything &mdash; '
          + 'you still press Match.</p>';
    draw(html);

    panel.querySelectorAll('.bal-tick').forEach(function (b) {
      b.addEventListener('click', function () {
        found[parseInt(b.dataset.which, 10)].rows.forEach(function (r) {
          r.box.checked = true;
          r.box.dispatchEvent(new Event('change', { bubbles: true }));
        });
        draw('');
      });
    });
  }

  btn.addEventListener('click', look);

  // The suggestions go stale the moment the ticks change.
  form.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('tick') && !panel.hidden) draw('');
  });
})();
