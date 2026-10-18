// Muscu.log — interactions (enregistrement auto, minuteur de repos, sélecteur d'exercice, graphiques).
(() => {
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];

  async function api(action, data = {}) {
    const res = await fetch('api.php?action=' + action, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': document.querySelector('meta[name=csrf]')?.content || '',
      },
      body: JSON.stringify(data),
    });
    if (res.status === 401) { location.href = '?p=login'; throw new Error('Session expirée'); }
    const json = await res.json().catch(() => ({}));
    if (!res.ok || json.error) throw new Error(json.error || res.statusText);
    return json;
  }

  const fmtClock = (s) => {
    s = Math.max(0, Math.floor(s));
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(sec).padStart(2, '0');
  };

  // --- Chronos de séance ---------------------------------------------------
  const sinceEls = $$('[data-since]');
  if (sinceEls.length) {
    const tick = () => sinceEls.forEach((el) => {
      el.textContent = fmtClock((Date.now() - new Date(el.dataset.since).getTime()) / 1000);
    });
    tick();
    setInterval(tick, 1000);
  }

  // --- Minuteur de repos -----------------------------------------------------
  const rest = $('#rest');
  let restEnd = 0, restTotal = 0, restTimer = null;
  function beep() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.25].forEach((t) => {
        const o = ctx.createOscillator(), g = ctx.createGain();
        o.frequency.value = 880; o.connect(g); g.connect(ctx.destination);
        g.gain.setValueAtTime(0.25, ctx.currentTime + t);
        g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + t + 0.2);
        o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + 0.2);
      });
    } catch (e) { /* pas de son, tant pis */ }
    navigator.vibrate?.([200, 100, 200]);
  }
  function renderRest() {
    const left = (restEnd - Date.now()) / 1000;
    $('.rest-time', rest).textContent = left > 0 ? fmtClock(Math.ceil(left)) : '+' + fmtClock(-left);
    $('.rest-bar span', rest).style.width = Math.max(0, Math.min(100, (left / restTotal) * 100)) + '%';
    if (left <= 0 && !rest.classList.contains('is-over')) {
      rest.classList.add('is-over');
      $('.rest-label', rest).textContent = 'Go !';
      beep();
    }
  }
  function startRest(seconds) {
    if (!rest) return;
    restTotal = seconds; restEnd = Date.now() + seconds * 1000;
    rest.hidden = false; rest.classList.remove('is-over');
    $('.rest-label', rest).textContent = 'Repos';
    clearInterval(restTimer);
    renderRest();
    restTimer = setInterval(renderRest, 250);
  }
  function stopRest() { clearInterval(restTimer); if (rest) rest.hidden = true; }
  rest?.addEventListener('click', (e) => {
    const b = e.target.closest('[data-rest]');
    if (!b) return;
    if (b.dataset.rest === 'skip') return stopRest();
    const d = parseInt(b.dataset.rest, 10);
    restEnd += d * 1000; restTotal = Math.max(restTotal + d, 1);
    rest.classList.remove('is-over'); renderRest();
  });

  // --- Séries : enregistrement automatique ---------------------------------
  const exlist = $('.exlist');
  const saveTimers = new Map();
  function saveField(input) {
    const row = input.closest('.set-row');
    const key = row.dataset.set + input.name;
    clearTimeout(saveTimers.get(key));
    saveTimers.set(key, setTimeout(async () => {
      try {
        await api('set_update', { id: row.dataset.set, field: input.name, value: input.value });
        input.classList.remove('err');
      } catch (e) { input.classList.add('err'); console.error(e); }
    }, 350));
  }

  // Compteurs "séries / tonnes" en haut de la séance
  function refreshTotals() {
    let sets = 0, vol = 0;
    $$('.set-row[data-set]').forEach((row) => {
      const w = parseFloat($('input[name=weight]', row).value.replace(',', '.')) || 0;
      const r = parseInt($('input[name=reps]', row).value, 10) || 0;
      if (r > 0) { sets++; vol += w * r; }
    });
    const s = $('#tot-sets'), v = $('#tot-vol');
    if (s) s.textContent = sets;
    if (v) v.textContent = (vol / 1000).toLocaleString('fr-FR', { maximumFractionDigits: 2 });
  }

  if (exlist) {
    exlist.addEventListener('input', (e) => {
      if (e.target.matches('.set-in')) { saveField(e.target); refreshTotals(); }
    });

    exlist.addEventListener('click', async (e) => {
      const card = e.target.closest('.excard');

      // ✓ série faite : complète avec les valeurs proposées si vides, lance le repos
      const chk = e.target.closest('.set-check');
      if (chk) {
        const row = chk.closest('.set-row');
        const done = !row.classList.contains('is-done');
        if (done) {
          for (const name of ['weight', 'reps']) {
            const inp = $(`input[name=${name}]`, row);
            if (!inp.value && inp.placeholder && /^[\d.,]+$/.test(inp.placeholder)) {
              inp.value = inp.placeholder;
              await api('set_update', { id: row.dataset.set, field: name, value: inp.value });
            }
          }
        }
        row.classList.toggle('is-done', done);
        refreshTotals();
        chk.setAttribute('aria-pressed', String(done));
        api('set_update', { id: row.dataset.set, field: 'done', value: done ? 1 : 0 });
        if (done) startRest(parseInt(card.dataset.rest, 10) || 90);
        return;
      }

      if (e.target.closest('.set-del')) {
        const row = e.target.closest('.set-row');
        await api('set_delete', { id: row.dataset.set });
        row.remove();
        refreshTotals();
        $$('.set-row:not(.set-head) .set-n', card).forEach((n, i) => (n.textContent = i + 1));
        return;
      }

      if (e.target.closest('.add-set')) {
        const { html } = await api('set_add', { we: card.dataset.we });
        $('.sets', card).insertAdjacentHTML('beforeend', html);
        return;
      }

      if (e.target.closest('[data-remove]')) {
        if (!confirm('Retirer cet exercice de la séance ?')) return;
        await api('workout_remove_exercise', { we: card.dataset.we });
        location.reload();
        return;
      }

      const mv = e.target.closest('[data-move]');
      if (mv) {
        await api('workout_move_exercise', { we: card.dataset.we, dir: mv.dataset.move });
        location.reload();
      }
    });
  }

  // Notes de séance
  const notes = $('[data-notes]');
  if (notes) {
    let t;
    notes.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(() => api('workout_notes', { id: notes.dataset.notes, notes: notes.value }), 600);
    });
  }

  // --- Sélecteur d'exercice --------------------------------------------------
  const picker = $('#picker');
  let onPick = null;
  async function searchPicker() {
    const q = $('#picker-q').value, muscle = $('#picker-muscle').value;
    const res = await fetch(`api.php?action=search&q=${encodeURIComponent(q)}&muscle=${encodeURIComponent(muscle)}`);
    const list = await res.json();
    const box = $('#picker-list');
    box.innerHTML = list.length ? '' : '<p class="empty" style="padding:1rem">Aucun résultat.</p>';
    for (const ex of list) {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'pick';
      b.innerHTML = `${ex.img ? `<img src="${ex.img}" alt="" loading="lazy">` : ''}<span><strong></strong><small class="muted"></small></span>`;
      $('strong', b).textContent = ex.name;
      $('small', b).textContent = ex.sub + (ex.used ? ` · ${ex.used}× fait` : '');
      b.addEventListener('click', () => onPick?.(ex.id));
      box.appendChild(b);
    }
  }
  if (picker) {
    let t;
    $('#picker-q').addEventListener('input', () => { clearTimeout(t); t = setTimeout(searchPicker, 200); });
    $('#picker-muscle').addEventListener('change', searchPicker);
  }
  $$('[data-pick]').forEach((btn) => btn.addEventListener('click', () => {
    const kind = btn.dataset.pick, id = btn.dataset.id;
    onPick = async (exId) => {
      picker.close();
      if (kind === 'workout') await api('workout_add_exercise', { workout: id, exercise: exId });
      else await api('template_add_exercise', { template: id, exercise: exId });
      location.reload();
    };
    picker.showModal();
    $('#picker-q').value = '';
    $('#picker-q').focus();
    searchPicker();
  }));

  // --- Graphiques (Chart.js) -------------------------------------------------
  // Les couleurs viennent des tokens CSS : les graphiques suivent le thème clair/sombre.
  let charts = [];
  function renderCharts() {
    if (!window.Chart) return;
    charts.forEach((ch) => ch.destroy());
    charts = [];
    const css = getComputedStyle(document.documentElement);
    const c = (v) => css.getPropertyValue(v).trim();
    Chart.defaults.color = c('--muted');
    Chart.defaults.borderColor = c('--line');
    Chart.defaults.font.family = c('--mono');
    Chart.defaults.font.size = 11;

    const styles = {
      main: { borderColor: c('--accent-text'), backgroundColor: c('--accent-text'), borderWidth: 2.5, pointRadius: 2.5, pointHoverRadius: 5, tension: 0.3 },
      second: { borderColor: c('--muted'), backgroundColor: c('--muted'), borderWidth: 1.5, borderDash: [5, 4], pointRadius: 0, tension: 0.3 },
      ghost: { borderColor: c('--chart-ghost'), backgroundColor: c('--chart-ghost'), borderWidth: 0 },
      dots: { borderColor: 'transparent', backgroundColor: c('--faint'), pointRadius: 3, showLine: false },
      goal: { borderColor: c('--ok'), borderWidth: 1.5, borderDash: [6, 6], pointRadius: 0 },
    };

    $$('canvas[data-chart]').forEach((cv) => {
      const cfg = JSON.parse(cv.dataset.chart);
      const isBar = cfg.type === 'bar';
      charts.push(new Chart(cv, {
        type: cfg.type,
        data: {
          labels: cfg.labels,
          datasets: cfg.datasets.map((d) => ({
            label: d.label, data: d.data, ...styles[d.role || 'main'], ...(d.type ? { type: d.type, order: -1 } : {}),
            ...(isBar ? { borderRadius: 4, barPercentage: 0.8, categoryPercentage: 0.75 } : {}),
          })),
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          indexAxis: cfg.horizontal ? 'y' : 'x',
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: !cfg.compact && cfg.datasets.length > 1, align: 'start', labels: { boxWidth: 10, boxHeight: 10, padding: 14 } },
            tooltip: {
              backgroundColor: c('--surface'), titleColor: c('--text'), bodyColor: c('--text'),
              borderColor: c('--line-strong'), borderWidth: 1, padding: 10, cornerRadius: 6, boxPadding: 4,
            },
          },
          scales: {
            x: { grid: { display: !!cfg.horizontal }, border: { display: false }, ticks: { maxTicksLimit: 8 } },
            y: { grid: { display: !cfg.horizontal }, border: { display: false }, beginAtZero: isBar, ticks: { maxTicksLimit: cfg.horizontal ? 30 : 6 } },
          },
        },
      }));
    });
  }
  window.addEventListener('load', renderCharts);

  // --- Carte du corps : survoler un muscle le met en évidence sur le schéma ET dans le classement ---
  $$('.body-layout').forEach((box) => {
    const hot = (key, on) => $$(`[data-m="${key}"]`, box).forEach((el) => el.classList.toggle('is-hot', on));
    box.addEventListener('pointerover', (e) => { const el = e.target.closest('[data-m]'); if (el) hot(el.dataset.m, true); });
    box.addEventListener('pointerout', (e) => { const el = e.target.closest('[data-m]'); if (el) hot(el.dataset.m, false); });
  });

  // --- Thème clair / sombre --------------------------------------------------
  $('#theme-toggle')?.addEventListener('click', () => {
    const root = document.documentElement;
    const current = root.dataset.theme || (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
    const next = current === 'dark' ? 'light' : 'dark';
    root.dataset.theme = next;
    try { localStorage.setItem('theme', next); } catch (e) { /* stockage indisponible : thème pour cette page seulement */ }
    renderCharts();
  });
})();

// Formulaire d'activité : la distance n'a de sens que pour certains sports (on l'atténue sinon).
document.querySelectorAll('.act-form').forEach((form) => {
  const sync = () => {
    const t = form.querySelector('input[name=type]:checked');
    form.classList.toggle('no-distance', !!t && t.dataset.distance === '0');
  };
  form.addEventListener('change', sync);
  sync();
});

// --- Visionneuse d'animation : clic sur une vignette → GIF en grand, zoom (suit le doigt / la souris), pause, consignes ---
(() => {
  const dlg = document.getElementById('viewer');
  if (!dlg) return;
  const $ = (id) => document.getElementById(id);
  const stage = $('viewer-stage'), img = $('viewer-img'), freeze = $('viewer-freeze');
  const zoomBtn = $('viewer-zoom'), pauseBtn = $('viewer-pause');
  const ZOOM = 2.2;
  let zoomed = false, paused = false, reqId = 0;

  const setOrigin = (e) => {
    const r = stage.getBoundingClientRect();
    const x = Math.min(100, Math.max(0, ((e.clientX - r.left) / r.width) * 100));
    const y = Math.min(100, Math.max(0, ((e.clientY - r.top) / r.height) * 100));
    stage.style.setProperty('--ox', x + '%');
    stage.style.setProperty('--oy', y + '%');
  };
  const setZoom = (on, e) => {
    zoomed = on;
    if (e) setOrigin(e); else { stage.style.setProperty('--ox', '50%'); stage.style.setProperty('--oy', '50%'); }
    stage.classList.toggle('is-zoomed', on);
    stage.style.setProperty('--z', on ? ZOOM : 1);
    zoomBtn.querySelector('span').textContent = on ? 'Dézoomer' : 'Zoomer';
    zoomBtn.setAttribute('aria-pressed', String(on));
  };
  const setPause = (on) => {
    paused = on;
    if (on && img.complete && img.naturalWidth) {
      // fige l'image affichée à cet instant (le navigateur dessine l'image courante du GIF)
      freeze.width = img.naturalWidth;
      freeze.height = img.naturalHeight;
      freeze.getContext('2d').drawImage(img, 0, 0);
    }
    freeze.hidden = !on;
    img.style.visibility = on ? 'hidden' : '';
    pauseBtn.querySelector('span').textContent = on ? 'Lecture' : 'Pause';
    pauseBtn.setAttribute('aria-pressed', String(on));
  };

  async function open(id) {
    const my = ++reqId;
    setZoom(false); setPause(false);
    $('viewer-title').textContent = 'Chargement…';
    $('viewer-sub').textContent = '';
    $('viewer-muscles').innerHTML = '';
    $('viewer-steps').innerHTML = '';
    $('viewer-loading').textContent = 'Chargement…';
    img.removeAttribute('src');
    stage.classList.add('is-loading');
    if (!dlg.open) dlg.showModal();
    try {
      const res = await fetch('api.php?action=exercise_info&id=' + encodeURIComponent(id));
      if (!res.ok) throw new Error(String(res.status));
      const ex = await res.json();
      if (my !== reqId) return;
      $('viewer-title').textContent = ex.name;
      $('viewer-sub').textContent = [ex.original, ex.equipment].filter(Boolean).join(' · ');
      img.alt = 'Animation : ' + ex.name;
      img.onload = () => stage.classList.remove('is-loading');
      img.onerror = () => { $('viewer-loading').textContent = 'Animation indisponible (connexion ?)'; };
      img.src = ex.gif || ex.image;
      $('viewer-link').href = '?p=exercise&id=' + encodeURIComponent(ex.id);
      const chips = $('viewer-muscles');
      ex.primary.forEach((m) => { const s = document.createElement('span'); s.className = 'chip strong'; s.textContent = m; chips.appendChild(s); });
      ex.secondary.forEach((m) => { const s = document.createElement('span'); s.className = 'chip'; s.textContent = m + ' ½'; chips.appendChild(s); });
      const ol = $('viewer-steps');
      ex.steps.forEach((t) => { const li = document.createElement('li'); li.textContent = t; ol.appendChild(li); });
    } catch (err) {
      $('viewer-title').textContent = 'Exercice introuvable';
      $('viewer-loading').textContent = '';
    }
  }

  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-viewer]');
    if (!t) return;
    e.preventDefault();
    open(t.dataset.viewer);
  });

  // Zoom : clic / tap sur l'image ; ensuite l'image suit le pointeur (ou le doigt qui glisse)
  stage.addEventListener('click', (e) => { if (!stage.classList.contains('is-loading')) setZoom(!zoomed, e); });
  stage.addEventListener('pointermove', (e) => { if (zoomed) setOrigin(e); });
  zoomBtn.addEventListener('click', () => setZoom(!zoomed));
  pauseBtn.addEventListener('click', () => setPause(!paused));
  dlg.querySelector('[data-viewer-close]').addEventListener('click', () => dlg.close());
  dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); }); // clic sur le fond
  dlg.addEventListener('keydown', (e) => {
    if (e.key === 'z' || e.key === '+') { e.preventDefault(); setZoom(!zoomed); }
    if (e.key === ' ' || e.key === 'k') { e.preventDefault(); setPause(!paused); }
  });
  dlg.addEventListener('close', () => { reqId++; setZoom(false); setPause(false); img.removeAttribute('src'); });
})();

// --- Comportements déclaratifs (pas de JS inline : compatible avec une CSP stricte) ---
document.addEventListener('submit', (e) => {
  const msg = e.target.dataset && e.target.dataset.confirm;
  if (msg && !confirm(msg)) e.preventDefault();
});
document.addEventListener('click', (e) => {
  const openD = e.target.closest('[data-open-dialog]');
  if (openD) { document.getElementById(openD.dataset.openDialog)?.showModal(); return; }
  const closeD = e.target.closest('[data-close-dialog]');
  if (closeD) { closeD.closest('dialog')?.close(); return; }
  const det = e.target.closest('[data-open-details]');
  if (det) { const d = document.getElementById(det.dataset.openDetails); if (d) d.open = true; }
});
document.addEventListener('change', (e) => {
  if (e.target.matches('[data-autosubmit]')) e.target.form.submit();
});
