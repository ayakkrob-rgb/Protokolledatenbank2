/* Protokolldatenbank – kleine Komfortfunktionen (ohne Abhängigkeiten).
   Die Seiten funktionieren auch ohne JavaScript. */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* ---------- Navigation (mobil) ---------- */
  $$('[data-nav-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var nav = $('[data-nav]');
      if (!nav) return;
      var open = nav.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  document.addEventListener('click', function (e) {
    var nav = $('.admin-sidebar.open');
    if (nav && !nav.contains(e.target) && !e.target.closest('[data-nav-toggle]')) nav.classList.remove('open');
  });

  /* ---------- Meldungen schließen ---------- */
  $$('[data-dismiss]').forEach(function (btn) {
    btn.addEventListener('click', function () { btn.closest('.alert').remove(); });
  });

  /* ---------- Sicherheitsabfragen ---------- */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var submitter = e.submitter;
    var msg = (submitter && submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });
  $$('a[data-confirm]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      if (!window.confirm(a.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  /* ---------- Automatisch absenden (Filter) ---------- */
  $$('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form && el.form.submit(); });
  });

  /* ---------- Listenfilter ---------- */
  $$('[data-filter]').forEach(function (input) {
    var target = $(input.getAttribute('data-filter'));
    if (!target) return;
    var run = function () {
      var q = input.value.trim().toLowerCase();
      var visibleTotal = 0;
      $$('[data-filter-item]', target).forEach(function (item) {
        var text = (item.getAttribute('data-filter-text') || item.textContent).toLowerCase();
        var show = q === '' || text.indexOf(q) !== -1;
        item.classList.toggle('hidden', !show);
        if (show) visibleTotal++;
      });
      $$('[data-filter-group]', target).forEach(function (group) {
        group.classList.toggle('hidden', $$('[data-filter-item]:not(.hidden)', group).length === 0);
      });
      var empty = $('[data-filter-empty]', target);
      if (empty) empty.classList.toggle('hidden', visibleTotal !== 0);
    };
    input.addEventListener('input', run);
  });

  /* ---------- Prüferauswahl beim Kauf ---------- */
  $$('[data-picker]').forEach(function (picker) {
    var max = parseInt(picker.getAttribute('data-max'), 10) || 4;
    var inputs = $$('input[type=checkbox][data-pick]', picker);
    var counter = $$('[data-pick-count]');
    var summary = $('[data-pick-summary]');
    var submit = $('[data-pick-submit]');
    var update = function () {
      var chosen = inputs.filter(function (i) { return i.checked; });
      var full = chosen.length >= max;
      inputs.forEach(function (i) {
        if (!i.checked) {
          i.disabled = full;
          i.closest('.pick').classList.toggle('is-disabled', full);
        }
      });
      counter.forEach(function (c) { c.textContent = chosen.length + ' / ' + max; });
      if (summary) {
        summary.innerHTML = '';
        if (!chosen.length) {
          var li = document.createElement('li');
          li.className = 'muted';
          li.textContent = 'Noch keine Prüfer ausgewählt';
          summary.appendChild(li);
        }
        chosen.forEach(function (i) {
          var li = document.createElement('li');
          li.innerHTML = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
          li.appendChild(document.createTextNode(' ' + i.getAttribute('data-name')));
          summary.appendChild(li);
        });
      }
      if (submit) submit.disabled = chosen.length === 0;
    };
    inputs.forEach(function (i) { i.addEventListener('change', update); });
    update();
  });

  /* ---------- Dateiauswahl: Dateinamen anzeigen, Drag & Drop ---------- */
  $$('.dropzone').forEach(function (zone) {
    var input = $('input[type=file]', zone);
    var list = $('.file-names', zone);
    if (!input) return;
    var show = function () {
      if (!list) return;
      list.innerHTML = '';
      Array.prototype.forEach.call(input.files || [], function (f) {
        var li = document.createElement('li');
        var size = f.size > 1048576 ? (f.size / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(f.size / 1024)) + ' KB';
        li.textContent = '📄 ' + f.name + ' (' + size + ')';
        list.appendChild(li);
      });
    };
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(function (ev) {
      zone.addEventListener(ev, function () { zone.classList.add('dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zone.addEventListener(ev, function () { zone.classList.remove('dragover'); });
    });
    zone.addEventListener('drop', function () { setTimeout(show, 0); });
  });

  /* ---------- Datei-Vorschau (PDF/Bild) ---------- */
  $$('[data-viewer]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var item = btn.closest('.file-item');
      var viewer = $('.file-viewer', item);
      if (!viewer) return;
      if (!viewer.classList.contains('hidden')) {
        viewer.classList.add('hidden');
        btn.textContent = btn.getAttribute('data-label-open') || 'Ansehen';
        return;
      }
      if (!viewer.firstElementChild) {
        var src = btn.getAttribute('data-viewer');
        var type = btn.getAttribute('data-type');
        var el;
        if (type === 'image') {
          el = document.createElement('img');
          el.alt = 'Vorschau';
        } else {
          el = document.createElement('iframe');
          el.title = 'Vorschau';
        }
        el.src = src;
        viewer.appendChild(el);
      }
      viewer.classList.remove('hidden');
      btn.textContent = 'Vorschau schließen';
    });
  });

  /* ---------- In Zwischenablage kopieren ---------- */
  $$('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var done = function () {
        var old = btn.textContent;
        btn.textContent = 'Kopiert ✓';
        setTimeout(function () { btn.textContent = old; }, 1500);
      };
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(done);
      } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (e) { /* ignorieren */ }
        ta.remove();
      }
    });
  });

  /* ---------- Felder abhängig von anderen Feldern ein-/ausblenden ---------- */
  $$('[data-show-when]').forEach(function (el) {
    var parts = el.getAttribute('data-show-when').split('=');
    var name = parts[0];
    var values = (parts[1] || '').split('|');
    var inputs = $$('[name="' + name + '"]');
    var update = function () {
      var current = '';
      inputs.forEach(function (i) {
        if ((i.type === 'radio' || i.type === 'checkbox')) { if (i.checked) current = i.value; }
        else current = i.value;
      });
      el.classList.toggle('hidden', values.indexOf(current) === -1);
    };
    inputs.forEach(function (i) { i.addEventListener('change', update); });
    update();
  });

  /* ---------- Alle auswählen (Admin-Tabellen) ---------- */
  $$('[data-check-all]').forEach(function (master) {
    master.addEventListener('change', function () {
      var form = master.closest('form') || document;
      $$('input[type=checkbox][name="ids[]"]', form).forEach(function (c) { c.checked = master.checked; });
    });
  });

  /* ---------- Upload-Formular ---------- */
  var uploadForm = $('[data-upload-form]');
  if (uploadForm) {
    var dataEl = $('#upload-data');
    var data = dataEl ? JSON.parse(dataEl.textContent) : { examiners: {}, exams: {} };
    var examSelect = $('[name=exam_id]', uploadForm);
    var blocks = $$('[data-block]', uploadForm);
    var addBtn = $('[data-add-block]', uploadForm);
    var refundBox = $('[data-refund-info]');

    var titles = /\b(prof|dr|med|dent|rer|nat|phil|pd|priv|doz|habil|univ|apl|frau|herr)\b\.?/g;
    var norm = function (s) {
      return (s || '').toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
        .replace(titles, ' ').replace(/[^a-z0-9]+/g, ' ').trim();
    };

    var fillSelect = function (select, examId) {
      var current = select.value;
      var list = data.examiners[examId] || [];
      select.innerHTML = '';
      var ph = document.createElement('option');
      ph.value = '';
      ph.textContent = examId ? (list.length ? '– Prüfer auswählen –' : '– Noch keine Prüfer vorhanden –') : '– Bitte zuerst die Prüfung wählen –';
      select.appendChild(ph);
      var groups = {};
      list.forEach(function (ex) {
        var key = ex.subject || 'Weitere';
        (groups[key] = groups[key] || []).push(ex);
      });
      Object.keys(groups).forEach(function (key) {
        var og = document.createElement('optgroup');
        og.label = key;
        groups[key].forEach(function (ex) {
          var o = document.createElement('option');
          o.value = ex.id;
          o.textContent = ex.name;
          og.appendChild(o);
        });
        select.appendChild(og);
      });
      if (data.allowNew && examId) {
        var n = document.createElement('option');
        n.value = '__new';
        n.textContent = '＋ Prüfer ist nicht in der Liste – neu anlegen';
        select.appendChild(n);
      }
      var stillThere = $$('option', select).some(function (o) { return o.value === current; });
      select.value = stillThere ? current : '';
    };

    var toggleNew = function (block) {
      var select = $('[data-examiner-select]', block);
      var box = $('.new-examiner', block);
      if (!select || !box) return;
      var isNew = select.value === '__new';
      box.classList.toggle('hidden', !isNew);
      $$('input', box).forEach(function (i) { if (i.hasAttribute('data-required-new')) i.required = isNew; });
    };

    var suggest = function (block) {
      var input = $('[data-new-name]', block);
      var out = $('.suggestions', block);
      if (!input || !out) return;
      out.innerHTML = '';
      var q = norm(input.value);
      if (q.length < 3) return;
      var tokens = q.split(' ').filter(function (t) { return t.length >= 3; });
      var list = data.examiners[examSelect.value] || [];
      var hits = list.filter(function (ex) {
        var n = norm(ex.name);
        return tokens.some(function (t) { return n.indexOf(t) !== -1; });
      }).slice(0, 4);
      if (!hits.length) return;
      out.appendChild(document.createTextNode('Meintest du vielleicht: '));
      hits.forEach(function (ex) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-secondary btn-xs';
        b.textContent = ex.name;
        b.addEventListener('click', function () {
          var select = $('[data-examiner-select]', block);
          select.value = String(ex.id);
          input.value = '';
          out.innerHTML = '';
          toggleNew(block);
        });
        out.appendChild(b);
      });
    };

    var visibleBlocks = function () { return blocks.filter(function (b) { return !b.classList.contains('hidden'); }); };
    var maxBlocks = function () {
      var exam = data.exams[examSelect.value];
      return Math.min(blocks.length, exam ? exam.max : blocks.length);
    };
    var updateAddBtn = function () {
      if (addBtn) addBtn.classList.toggle('hidden', visibleBlocks().length >= maxBlocks());
      visibleBlocks().forEach(function (b, idx) {
        var num = $('.block-num', b);
        if (num) num.textContent = idx + 1;
        var rm = $('[data-remove-block]', b);
        if (rm) rm.classList.toggle('hidden', idx === 0);
      });
    };
    var updateRefund = function () {
      if (!refundBox) return;
      var exam = data.exams[examSelect.value];
      var html = exam && exam.refund ? exam.refund : (data.refundDefault || '');
      refundBox.innerHTML = html;
      refundBox.classList.toggle('hidden', html === '');
    };

    // Ohne JS sind alle Blöcke sichtbar; mit JS nur die ausgefüllten bzw. der erste.
    blocks.forEach(function (b, idx) {
      var used = $('[data-examiner-select]', b).value !== '' || ($('textarea', b) && $('textarea', b).value.trim() !== '');
      if (idx > 0 && !used) b.classList.add('hidden');
    });

    examSelect.addEventListener('change', function () {
      blocks.forEach(function (b) { fillSelect($('[data-examiner-select]', b), examSelect.value); toggleNew(b); });
      visibleBlocks().forEach(function (b, idx) { if (idx >= maxBlocks()) b.classList.add('hidden'); });
      updateAddBtn();
      updateRefund();
    });
    blocks.forEach(function (b) {
      var select = $('[data-examiner-select]', b);
      fillSelect(select, examSelect.value);
      var preset = select.getAttribute('data-value');
      if (preset) select.value = preset;
      select.addEventListener('change', function () { toggleNew(b); });
      toggleNew(b);
      var nameInput = $('[data-new-name]', b);
      if (nameInput) nameInput.addEventListener('input', function () { suggest(b); });
      var rm = $('[data-remove-block]', b);
      if (rm) rm.addEventListener('click', function () {
        b.classList.add('hidden');
        $$('input:not([type=hidden]), textarea', b).forEach(function (i) { if (i.type === 'file') i.value = ''; else i.value = ''; });
        select.value = '';
        toggleNew(b);
        var names = $('.file-names', b); if (names) names.innerHTML = '';
        updateAddBtn();
      });
    });
    if (addBtn) addBtn.addEventListener('click', function () {
      var next = blocks.filter(function (b) { return b.classList.contains('hidden'); })[0];
      if (next) { next.classList.remove('hidden'); next.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
      updateAddBtn();
    });
    updateAddBtn();
    updateRefund();
  }
})();
