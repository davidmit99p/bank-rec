<?php
// The quick route: a one-off reconciliation from two files, on one screen.
//
// 1. Name it and choose the two files.
// 2. One screen: the fields each file is read into at the top (which column of
//    each is the date, the amount, the description, and any extra fields,
//    in the order you want them shown), the files themselves underneath.
// 3. Create: two files, both imported, and a reconciliation pairing them -
//    marked as a one-off, so it can be removed in one step when you are done.
//
// Everything on step 2 happens in the browser; the only trips to the server
// are for a different sheet of a workbook and for the running check of what
// would come in, which reads the whole file with the ordinary importer.
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/quick.php';

$error  = null;
$screen = null;     // set when showing step 2

// --- the browser asking questions while you set things up -------------------
$action = $_GET['action'] ?? '';
if ($action === 'sheet' || $action === 'check') {
    header('Content-Type: application/json');
    try {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        if ($action === 'sheet') {
            $out = quick_describe($in['token'] ?? '', (string)($in['name'] ?? ''), (string)($in['sheet'] ?? ''));
        } else {
            $map = quick_side_map($in['plan'] ?? [], ($in['side'] ?? '') === 'right' ? 'right' : 'left');
            $out = quick_check($in['token'] ?? '', (string)($in['name'] ?? ''), (string)($in['sheet'] ?? ''),
                               (int)($in['data_start'] ?? 1), $map, !empty($in['reverse']));
        }
        echo json_encode(['ok' => true] + $out, JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

try {
    $stage = $_POST['stage'] ?? '';

    // Two files too big for the server arrive as an empty post, with nothing
    // to say why. Say why.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES) {
        throw new RuntimeException('Those files are too big to upload in one go (the limit is '
            . ini_get('post_max_size') . ' together). Try CSV rather than Excel, or a shorter date range.');
    }

    // --- step 1 done: both files in ---------------------------------------------
    if ($stage === 'upload') {
        quick_tidy_uploads();
        $name = trim($_POST['name'] ?? '');
        if ($name === '') throw new RuntimeException('Give the reconciliation a name.');
        $sides = [];
        foreach (['left' => 'file1', 'right' => 'file2'] as $side => $field) {
            $up = $_FILES[$field] ?? [];
            $token = quick_store_upload($up);
            $sides[$side] = quick_describe($token, $up['name']);
            $sides[$side]['label'] = mb_substr(pathinfo($up['name'], PATHINFO_FILENAME), 0, 60);
            $sides[$side]['reverse'] = false;
        }
        $screen = ['name' => $name, 'sides' => $sides,
                   'spares' => quick_suggest_pairs($sides['left'], $sides['right'], spare_count())];
    }

    // --- step 2 done: make it -----------------------------------------------------
    if ($stage === 'create') {
        $plan = json_decode($_POST['plan'] ?? '', true);
        if (!is_array($plan) || empty($plan['sides']['left']) || empty($plan['sides']['right'])) {
            throw new RuntimeException('Something went wrong with the form. Please start again.');
        }
        try {
            foreach (['left', 'right'] as $side) {
                if (!quick_token_path($plan['sides'][$side]['token'] ?? '')) {
                    throw new RuntimeException('The uploaded files have expired. Please start again.');
                }
            }
            if ($problems = quick_plan_problems($plan)) throw new RuntimeException(implode(' ', $problems));
            $done = quick_create($plan, $plan['sides']);

            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['rec_id'] = $done['rec_id'];
            $c = $done['counts'];
            $rules = (int)db()->query("SELECT COUNT(*) FROM rec_rules WHERE active = 1 AND rec_id IS NULL")->fetchColumn();
            flash('Created ' . $plan['name'] . ': ' . number_format($c['left']['n']) . ' transactions from '
                . $c['left']['label'] . ' and ' . number_format($c['right']['n']) . ' from ' . $c['right']['label']
                . (($c['left']['skipped'] + $c['right']['skipped'])
                    ? ' (' . ($c['left']['skipped'] + $c['right']['skipped']) . ' rows skipped for having no usable date or amount)'
                    : '')
                . '. ' . ($rules
                    ? "Your {$rules} shared rule" . ($rules === 1 ? '' : 's') . ' will apply - press Process rules, or match by hand.'
                    : 'There are no shared rules yet, so match by hand, or add some on the Rules screen.')
                . (oneoff_ready() ? ' It is marked as a one-off, so it can be removed in one step from Reconciliations.' : ''));
            header('Location: transactions.php');
            exit;
        } catch (Throwable $e) {
            // Back to step 2 as it was, with the reason.
            $error = $e->getMessage();
            $sides = [];
            foreach (['left', 'right'] as $side) {
                $s = $plan['sides'][$side];
                if (!quick_token_path($s['token'] ?? '')) { $sides = null; break; }
                $sides[$side] = quick_describe($s['token'], $s['name'], ($s['sheet'] ?? '') ?: null,
                                               $s['header_row'] ?? null, $s['data_start'] ?? null);
                $sides[$side]['label'] = $plan[$side . '_label'] ?? $s['name'];
                $sides[$side]['reverse'] = !empty($s['reverse']);
                foreach (['date', 'value', 'description'] as $k) {
                    $v = $plan[$k][$side] ?? '';
                    $sides[$side]['map'][$k] = $v === '' ? null : (int)$v;
                }
            }
            if ($sides) $screen = ['name' => $plan['name'] ?? '', 'sides' => $sides, 'spares' => $plan['spares'] ?? []];
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

render_header('Quick reconciliation');
?>
<h1>Quick reconciliation</h1>

<?php if ($error): ?><p class="flash" style="background:#fbeeee;border-color:#eccfcf;color:#a12f2f"><?= h($error) ?></p><?php endif; ?>

<?php if (!$screen): ?>
<p class="muted">For a one-off check: give it a name, choose the two files, and on the next screen say
  which column of each is which. It makes the two files and the reconciliation in one go, then takes
  you straight to the transactions. When you are finished, it can be removed in one step from the
  <a href="recs.php">Reconciliations</a> screen.</p>
<?php if (!oneoff_ready()): ?>
  <div class="panel" style="background:#fdf6e6;border-color:#e8d9a8">
    <p style="margin:0"><b>One small database change is still to run.</b> In phpMyAdmin, run
      <code>sql/migration_013_one_off.sql</code> against <code>entigy_recon</code>. Until then this works,
      but what it makes is not marked as a one-off, so it cannot be removed in one step.</p>
  </div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" class="panel">
  <input type="hidden" name="stage" value="upload">
  <label>Name</label>
  <input type="text" name="name" required placeholder="e.g. Stripe payouts v bank, March" value="<?= h($_POST['name'] ?? '') ?>">
  <div class="row">
    <div><label>First file <span class="muted small">(shown on the left)</span></label>
      <input type="file" name="file1" accept=".csv,.txt,.tsv,.xlsx,.xlsm" required></div>
    <div><label>Second file <span class="muted small">(shown on the right)</span></label>
      <input type="file" name="file2" accept=".csv,.txt,.tsv,.xlsx,.xlsm" required></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Read the two files</button></div>
</form>

<?php else: ?>
<form method="post" id="quickForm">
  <input type="hidden" name="stage" value="create">
  <input type="hidden" name="plan" id="planField">

  <div class="panel">
    <div class="row" style="align-items:end">
      <div style="flex:3"><label>Name</label><input type="text" id="qName" value="<?= h($screen['name']) ?>"></div>
    </div>

    <h2 style="margin:1rem 0 .3rem">Fields</h2>
    <p class="small muted" style="margin-top:0">Use the drop-downs to pick which column of each file goes into each
      field. For example, Date might be column 2 in the first file and column 1 in the second. Date and Amount are
      needed from both files; the others can come from just one. Use <b>+ Add a field</b> for anything else you want
      to see, such as a reference.</p>
    <p class="small muted" style="margin-top:0">To change the order the extra fields are shown on the Transactions
      screen, drag them by the ⠿ handle or use the arrows. That only changes the order, not which columns are linked.
      Date, Amount and Description always stay at the top.</p>
    <div class="scroll">
      <table class="qgrid" id="qGrid"></table>
    </div>
    <p style="margin:.4rem 0 0"><button type="button" class="btn ghost small" id="qAdd">+ Add a field</button>
      <span class="small muted" id="qAddNote"></span></p>
  </div>

  <div class="qsides">
    <?php foreach (['left' => 'First file', 'right' => 'Second file'] as $side => $title): ?>
    <div class="panel qside" data-side="<?= $side ?>">
      <h2 style="margin-top:0"><?= $title ?> &mdash; <span class="qfname"></span></h2>
      <div class="row" style="align-items:end">
        <div style="flex:2"><label>This side is called</label><input type="text" class="qlabel"></div>
        <div class="qsheetbox" hidden><label>Sheet</label><select class="qsheet"></select></div>
      </div>
      <div class="row" style="align-items:end">
        <div><label>Headings are on row <span class="muted small">(0 if none)</span></label>
          <input type="number" min="0" class="qhead"></div>
        <div><label>Data starts on row</label><input type="number" min="1" class="qstart"></div>
        <div><label>&nbsp;</label>
          <label style="margin:0;color:var(--ink)"><input type="checkbox" class="qrev" style="width:auto">
            Reverse the signs</label></div>
      </div>
      <p class="qcheck small"></p>
      <div class="scroll"><table class="qraw"></table></div>
      <p class="small" style="margin:.3rem 0"><a href="#" class="qtoggle">Show the top of the file</a></p>
      <h3 style="margin:.8rem 0 .3rem">As it will come in</h3>
      <div class="scroll"><table class="qmapped"></table></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="panel actions" style="position:sticky;bottom:0;z-index:5">
    <button class="btn" type="submit" id="qCreate">Create the reconciliation</button>
    <a class="btn ghost" href="quick.php">Start again</a>
    <span class="small muted" id="qProblems"></span>
  </div>
</form>

<style>
  .qsides { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  @media (max-width: 1100px) { .qsides { grid-template-columns: 1fr; } }
  .qgrid td, .qgrid th { vertical-align: middle; }
  .qgrid select, .qgrid input { width: 100%; min-width: 11rem; }
  .qgrid tr.spare td.qh { color: var(--muted); white-space: nowrap; }
  .qgrip { cursor: grab; font-size: 1.2rem; padding: 0 .3rem; touch-action: none; user-select: none; }
  .qgrip:active { cursor: grabbing; }
  .qgrid tr.dragging { opacity: .4; }
  .qgrid tr.over td { border-top: 2px solid var(--accent); }
  .qgrid tr.under td { border-bottom: 2px solid var(--accent); }
  .qarrow { border: 0; background: none; cursor: pointer; color: var(--muted); padding: 0 .15rem; }
  .qraw td, .qraw th, .qmapped td, .qmapped th { white-space: nowrap; font-size: .8rem; }
  .qraw tr.head td { background: #eef3fb; font-weight: 600; }
  .qraw tr.before td { opacity: .45; }
  .qraw th .qfield { display: block; font-size: .7rem; color: #fff; background: var(--accent); border-radius: 3px; padding: 0 .3rem; }
  .qcheck.bad { color: var(--bad); }
</style>

<script>
(function () {
  var S = <?= json_encode($screen, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
  var MAX_SPARES = <?= (int)spare_count() ?>;
  var SIDES = ['left', 'right'];

  // The grid: three fixed fields, then the extra ones in the order they will be shown.
  var fields = [
    {key: 'date',        label: 'Date',        fixed: true, left: S.sides.left.map.date,        right: S.sides.right.map.date},
    {key: 'value',       label: 'Amount',      fixed: true, left: S.sides.left.map.value,       right: S.sides.right.map.value},
    {key: 'description', label: 'Description', fixed: true, left: S.sides.left.map.description, right: S.sides.right.map.description}
  ];
  (S.spares || []).forEach(function (s) { fields.push({label: s.label, left: s.left, right: s.right}); });
  SIDES.forEach(function (sd) { S.sides[sd].showAll = false; });

  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    for (var k in (attrs || {})) {
      if (k === 'class') e.className = attrs[k]; else e.setAttribute(k, attrs[k]);
    }
    if (text !== undefined && text !== null) e.textContent = text;
    return e;
  }
  function blank(v) { return v === null || v === undefined || v === ''; }
  function fmt(n) { return Number(n).toLocaleString('en-GB', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

  // Headings for one file: from the heading row if there is one, else "Column n".
  function headings(sd) {
    var f = S.sides[sd], h = f.header_row > 0 ? (f.rows[f.header_row - 1] || []) : [];
    var sample = f.rows[f.data_start - 1] || [];
    var out = [];
    for (var i = 0; i < f.width; i++) {
      var name = (h[i] || '').trim() || 'Column ' + (i + 1);
      var eg = (sample[i] || '').trim();
      out.push({name: name, text: (i + 1) + ' · ' + name + (eg && eg !== name ? '  (e.g. ' + eg.slice(0, 18) + ')' : '')});
    }
    return out;
  }

  function columnSelect(field, sd) {
    var sel = el('select', {'data-side': sd});
    var none = field.key === 'date' || field.key === 'value' ? '— choose —' : '— not in this file —';
    sel.appendChild(el('option', {value: ''}, none));
    headings(sd).forEach(function (h, i) {
      var o = el('option', {value: String(i)}, h.text);
      if (!blank(field[sd]) && Number(field[sd]) === i) o.selected = true;
      sel.appendChild(o);
    });
    sel.addEventListener('change', function () {
      field[sd] = sel.value === '' ? null : Number(sel.value);
      drawSide(sd); checkSoon(sd); problems();
    });
    return sel;
  }

  // --- the grid ------------------------------------------------------------

  function drawGrid() {
    var t = document.getElementById('qGrid');
    t.innerHTML = '';
    var hr = el('tr');
    hr.appendChild(el('th', {}, ''));
    hr.appendChild(el('th', {}, 'Field'));
    hr.appendChild(el('th', {}, S.sides.left.label || 'First file'));
    hr.appendChild(el('th', {}, S.sides.right.label || 'Second file'));
    hr.appendChild(el('th', {}, ''));
    var thead = el('thead'); thead.appendChild(hr); t.appendChild(thead);
    var tb = el('tbody');
    fields.forEach(function (f, idx) {
      var tr = el('tr', {'class': f.fixed ? 'fixed' : 'spare'});
      var h = el('td', {'class': 'qh'});
      if (!f.fixed) {
        var grip = el('span', {'class': 'qgrip', title: 'Drag up or down to change the order'}, '⠿');
        var up = el('button', {type: 'button', 'class': 'qarrow', title: 'Move up'}, '▲');
        var dn = el('button', {type: 'button', 'class': 'qarrow', title: 'Move down'}, '▼');
        up.disabled = fields[idx - 1] && fields[idx - 1].fixed;
        dn.disabled = idx === fields.length - 1;
        up.onclick = function () { move(idx, idx - 1); };
        dn.onclick = function () { move(idx, idx + 1); };
        h.appendChild(grip); h.appendChild(up); h.appendChild(dn);
        grip.addEventListener('pointerdown', function (e) { startDrag(e, grip, tr, idx); });
      }
      tr.appendChild(h);
      var lab = el('td');
      if (f.fixed) {
        lab.appendChild(el('b', {}, f.label));
      } else {
        var inp = el('input', {type: 'text', maxlength: '60', placeholder: 'Name, e.g. Reference'});
        inp.value = f.label || '';
        inp.addEventListener('input', function () { f.label = inp.value; drawSides(); problems(); });
        lab.appendChild(inp);
      }
      tr.appendChild(lab);
      SIDES.forEach(function (sd) { var td = el('td'); td.appendChild(columnSelect(f, sd)); tr.appendChild(td); });
      var rm = el('td');
      if (!f.fixed) {
        var x = el('button', {type: 'button', 'class': 'btn ghost small', title: 'Remove this field'}, '✕');
        x.onclick = function () { fields.splice(idx, 1); drawAll(); SIDES.forEach(checkSoon); };
        rm.appendChild(x);
      }
      tr.appendChild(rm);
      tb.appendChild(tr);
    });
    t.appendChild(tb);
    var spares = fields.length - 3;
    document.getElementById('qAdd').disabled = spares >= MAX_SPARES;
    document.getElementById('qAddNote').textContent = spares >= MAX_SPARES
      ? 'That is the most there is room for (' + MAX_SPARES + ').'
      : 'Room for ' + (MAX_SPARES - spares) + ' more.';
  }
  // Dragging by the grip. Done with pointer events rather than the browser's
  // own drag-and-drop, which is unreliable on table rows.
  function startDrag(e, grip, tr, from) {
    e.preventDefault();
    grip.setPointerCapture(e.pointerId);
    tr.classList.add('dragging');
    var target = null;
    function rowAt(y) {
      var rows = document.querySelectorAll('#qGrid tbody tr'), hit = null;
      rows.forEach(function (r, i) {
        var b = r.getBoundingClientRect();
        if (i >= 3 && y >= b.top && y < b.bottom) hit = i;
      });
      if (hit === null && rows.length > 3) {
        if (y < rows[3].getBoundingClientRect().top) hit = 3;
        else if (y >= rows[rows.length - 1].getBoundingClientRect().bottom) hit = rows.length - 1;
      }
      return hit;
    }
    function onMove(ev) {
      target = rowAt(ev.clientY);
      document.querySelectorAll('#qGrid tbody tr').forEach(function (r, i) {
        r.classList.toggle('over', i === target && target < from);
        r.classList.toggle('under', i === target && target > from);
      });
    }
    function onUp() {
      grip.removeEventListener('pointermove', onMove);
      grip.removeEventListener('pointerup', onUp);
      grip.removeEventListener('pointercancel', onUp);
      tr.classList.remove('dragging');
      document.querySelectorAll('#qGrid tr.over, #qGrid tr.under').forEach(function (r) { r.classList.remove('over', 'under'); });
      if (target !== null && target !== from) move(from, target);
    }
    grip.addEventListener('pointermove', onMove);
    grip.addEventListener('pointerup', onUp);
    grip.addEventListener('pointercancel', onUp);
  }
  function move(from, to) {
    if (to < 3 || to >= fields.length || from === to) return;     // the three fixed fields stay on top
    var f = fields.splice(from, 1)[0];
    fields.splice(to, 0, f);
    drawAll(); SIDES.forEach(checkSoon);
  }
  document.getElementById('qAdd').onclick = function () {
    if (fields.length - 3 >= MAX_SPARES) return;
    fields.push({label: '', left: null, right: null});
    drawGrid(); problems();
    var inputs = document.querySelectorAll('#qGrid input');
    if (inputs.length) inputs[inputs.length - 1].focus();
  };

  // --- each file -----------------------------------------------------------
  function box(sd) { return document.querySelector('.qside[data-side="' + sd + '"]'); }
  function fieldsUsing(sd) {
    var by = {};
    fields.forEach(function (f) { if (!blank(f[sd])) (by[f[sd]] = by[f[sd]] || []).push(f.fixed ? f.label : (f.label || 'extra')); });
    return by;
  }
  function drawSide(sd) {
    var f = S.sides[sd], b = box(sd);
    b.querySelector('.qfname').textContent = f.name;
    b.querySelector('.qlabel').value = f.label;
    b.querySelector('.qhead').value = f.header_row;
    b.querySelector('.qstart').value = f.data_start;
    b.querySelector('.qrev').checked = !!f.reverse;
    var sb = b.querySelector('.qsheetbox'), ss = b.querySelector('.qsheet');
    sb.hidden = !(f.sheets && f.sheets.length > 1);
    if (!sb.hidden) {
      ss.innerHTML = '';
      f.sheets.forEach(function (s) {
        var o = el('option', {value: s.name}, s.name + ' (' + s.rows + ' rows)');
        if (s.name === f.sheet) o.selected = true;
        ss.appendChild(o);
      });
    }
    // The file as it is: the heading row and the first few rows of data, or
    // the whole top of the file when asked. Numbered, with the fields marked.
    var t = b.querySelector('.qraw'); t.innerHTML = '';
    var used = fieldsUsing(sd), hr = el('tr');
    hr.appendChild(el('th', {}, 'Row'));
    for (var i = 0; i < f.width; i++) {
      var th = el('th', {}, String(i + 1));
      if (used[i]) th.insertBefore(el('span', {'class': 'qfield'}, used[i].join(', ')), th.firstChild);
      hr.appendChild(th);
    }
    var thead = el('thead'); thead.appendChild(hr); t.appendChild(thead);
    var tb = el('tbody'), last = Math.min(f.rows.length, f.data_start + 4);
    f.rows.forEach(function (r, ri) {
      var n = ri + 1;
      var show = f.showAll ? n <= last || n <= 15 : (n === f.header_row || (n >= f.data_start && n <= last));
      if (!show) return;
      var tr = el('tr', {'class': n === f.header_row ? 'head' : (n < f.data_start ? 'before' : '')});
      tr.appendChild(el('td', {'class': 'muted'}, n + (n === f.header_row ? ' headings' : (n === f.data_start ? ' first' : ''))));
      for (var i = 0; i < f.width; i++) tr.appendChild(el('td', {}, r[i] || ''));
      tb.appendChild(tr);
    });
    t.appendChild(tb);
    b.querySelector('.qtoggle').textContent = f.showAll ? 'Show just the headings and first rows' : 'Show the top of the file';
    drawMapped(sd);
  }
  function drawSides() { SIDES.forEach(drawSide); }

  // What will actually come in, as read by the importer on the server.
  function drawMapped(sd) {
    var f = S.sides[sd], t = box(sd).querySelector('.qmapped'), c = f.check;
    t.innerHTML = '';
    var spares = fields.slice(3);
    var hr = el('tr');
    ['Date', 'Description'].forEach(function (x) { hr.appendChild(el('th', {}, x)); });
    spares.forEach(function (s) { hr.appendChild(el('th', {}, s.label || '(no name)')); });
    hr.appendChild(el('th', {'class': 'num'}, 'Amount'));
    var thead = el('thead'); thead.appendChild(hr); t.appendChild(thead);
    var tb = el('tbody');
    ((c && c.first) || []).forEach(function (r) {
      var tr = el('tr');
      tr.appendChild(el('td', {}, r[0]));
      tr.appendChild(el('td', {}, r[1]));
      spares.forEach(function (s, n) { tr.appendChild(el('td', {}, blank(s[sd]) ? '' : (r[3 + n] || ''))); });
      tr.appendChild(el('td', {'class': 'num' + (r[2] < 0 ? ' neg' : '')}, fmt(r[2])));
      tb.appendChild(tr);
    });
    t.appendChild(tb);
  }

  // --- the running check -----------------------------------------------------
  var timers = {}, seq = {};
  function plan() {
    var p = {name: document.getElementById('qName').value, left_label: S.sides.left.label, right_label: S.sides.right.label,
             date: {}, value: {}, description: {}, spares: [], sides: {}};
    fields.forEach(function (f, i) {
      var pair = {left: blank(f.left) ? '' : f.left, right: blank(f.right) ? '' : f.right};
      if (i < 3) p[f.key] = pair; else p.spares.push({label: f.label || '', left: pair.left, right: pair.right});
    });
    SIDES.forEach(function (sd) {
      var f = S.sides[sd];
      p.sides[sd] = {token: f.token, name: f.name, sheet: f.sheet || '', header_row: f.header_row,
                     data_start: f.data_start, reverse: !!f.reverse};
    });
    return p;
  }
  function checkSoon(sd) { clearTimeout(timers[sd]); timers[sd] = setTimeout(function () { check(sd); }, 350); }
  function check(sd) {
    var f = S.sides[sd], line = box(sd).querySelector('.qcheck'), my = seq[sd] = (seq[sd] || 0) + 1;
    line.className = 'qcheck small muted'; line.textContent = 'Reading the whole file…';
    fetch('quick.php?action=check', {method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({side: sd, token: f.token, name: f.name, sheet: f.sheet || '', data_start: f.data_start,
                            reverse: !!f.reverse, plan: plan()})})
      .then(function (r) { return r.json(); })
      .then(function (c) {
        if (my !== seq[sd]) return;                       // a newer check has started
        if (!c.ok) { line.className = 'qcheck small bad'; line.textContent = c.error; f.check = null; drawMapped(sd); problems(); return; }
        f.check = c;
        line.className = 'qcheck small' + (c.usable ? '' : ' bad');
        line.textContent = c.usable
          ? c.usable.toLocaleString() + ' rows will come in' + (c.skipped ? ', ' + c.skipped + ' skipped (no usable date or amount)' : '')
            + ' · total ' + fmt(c.total) + ' · ' + c.from + ' to ' + c.to
          : 'Nothing usable yet — check the date and amount columns and the row the data starts on.';
        drawMapped(sd); problems();
      })
      .catch(function () { if (my === seq[sd]) { line.className = 'qcheck small bad'; line.textContent = 'Could not check just now.'; } });
  }

  // --- what still needs doing before it can be created ------------------------
  function problems() {
    var p = [], names = {};
    if (!document.getElementById('qName').value.trim()) p.push('it needs a name');
    SIDES.forEach(function (sd, n) {
      var which = n ? 'the second file' : 'the first file';
      if (blank(fields[0][sd])) p.push('the date column of ' + which);
      if (blank(fields[1][sd])) p.push('the amount column of ' + which);
      if (S.sides[sd].check && !S.sides[sd].check.usable) p.push('nothing usable in ' + which);
    });
    fields.slice(3).forEach(function (f) {
      var k = (f.label || '').trim().toLowerCase();
      if (!k) p.push('a name for every extra field');
      else if (names[k]) p.push('two extra fields called "' + f.label + '"');
      names[k] = 1;
      if (blank(f.left) && blank(f.right)) p.push('a column for "' + (f.label || 'the new field') + '"');
    });
    document.getElementById('qProblems').textContent = p.length ? 'Still needed: ' + p.join('; ') + '.' : '';
    document.getElementById('qCreate').disabled = p.length > 0;
    return p;
  }

  // --- wiring ------------------------------------------------------------------
  SIDES.forEach(function (sd) {
    var b = box(sd), f = S.sides[sd];
    b.querySelector('.qlabel').addEventListener('input', function (e) { f.label = e.target.value; drawGrid(); });
    b.querySelector('.qhead').addEventListener('change', function (e) {
      f.header_row = Math.max(0, parseInt(e.target.value, 10) || 0); drawGrid(); drawSide(sd);
    });
    b.querySelector('.qstart').addEventListener('change', function (e) {
      f.data_start = Math.max(1, parseInt(e.target.value, 10) || 1); drawSide(sd); checkSoon(sd);
    });
    b.querySelector('.qrev').addEventListener('change', function (e) { f.reverse = e.target.checked; checkSoon(sd); });
    b.querySelector('.qtoggle').addEventListener('click', function (e) { e.preventDefault(); f.showAll = !f.showAll; drawSide(sd); });
    b.querySelector('.qsheet').addEventListener('change', function (e) {
      // A different sheet has its own layout, so it is read and guessed afresh.
      fetch('quick.php?action=sheet', {method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({token: f.token, name: f.name, sheet: e.target.value})})
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { alert(d.error); return; }
          ['rows', 'width', 'sheets', 'sheet', 'header_row', 'data_start', 'row_count'].forEach(function (k) { f[k] = d[k]; });
          fields[0][sd] = d.map.date; fields[1][sd] = d.map.value; fields[2][sd] = d.map.description;
          fields.slice(3).forEach(function (x) { x[sd] = null; });
          drawAll(); checkSoon(sd);
        });
    });
  });
  document.getElementById('qName').addEventListener('input', problems);
  document.getElementById('quickForm').addEventListener('submit', function (e) {
    if (problems().length) { e.preventDefault(); return; }
    document.getElementById('planField').value = JSON.stringify(plan());
    document.getElementById('qCreate').disabled = true;
    document.getElementById('qCreate').textContent = 'Creating…';
  });

  function drawAll() { drawGrid(); drawSides(); problems(); }
  drawAll();
  SIDES.forEach(check);
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
