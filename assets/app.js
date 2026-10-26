// Muscu.log — interactions (enregistrement auto, minuteur de repos, sélecteur d'exercice, graphiques).
(() => {
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];

  async function apiSend(action, data = {}) {
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

  // --- Hors ligne : les saisies partent dans une file d'attente et sont envoyées au retour du réseau ---
  const QUEUE_KEY = 'carnet-queue';
  const QUEUEABLE = {
    set_update: (d) => `set:${d.id}:${d.field}`,
    workout_notes: (d) => `notes:${d.id}`,
    exercise_note: (d) => `note:${d.exercise}`,
  };
  const readQueue = () => { try { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); } catch (e) { return []; } };
  function writeQueue(q) {
    try { q.length ? localStorage.setItem(QUEUE_KEY, JSON.stringify(q)) : localStorage.removeItem(QUEUE_KEY); } catch (e) { /* stockage indisponible */ }
    showSync(q.length);
  }
  function showSync(n) {
    let bar = $('#sync-bar');
    if (!n) { bar?.remove(); return; }
    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'sync-bar'; bar.className = 'sync-bar'; bar.setAttribute('role', 'status');
      document.body.append(bar);
    }
    bar.textContent = n === 1 ? '1 saisie hors ligne, envoyée dès le retour du réseau' : `${n} saisies hors ligne, envoyées dès le retour du réseau`;
  }
  async function api(action, data = {}) {
    const key = QUEUEABLE[action]?.(data);
    try {
      const json = await apiSend(action, data);
      if (key && readQueue().some((i) => i.key === key)) writeQueue(readQueue().filter((i) => i.key !== key)); // valeur plus récente envoyée
      return json;
    } catch (err) {
      if (!key || !(err instanceof TypeError)) throw err; // TypeError : réseau indisponible
      writeQueue([...readQueue().filter((i) => i.key !== key), { key, action, data }]);
      return { ok: true, queued: true };
    }
  }
  let flushing = false;
  async function flushQueue() {
    if (flushing || !readQueue().length) return;
    flushing = true;
    try {
      for (const item of readQueue()) {
        await apiSend(item.action, item.data);
        writeQueue(readQueue().filter((i) => i.key !== item.key));
      }
    } catch (e) { /* toujours hors ligne : on réessaiera */ }
    flushing = false;
  }
  showSync(readQueue().length);
  flushQueue();
  addEventListener('online', flushQueue);
  setInterval(flushQueue, 20000);

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
  // L'heure de fin est gardée dans le navigateur : le minuteur reprend après un rechargement.
  const rest = $('#rest');
  const REST_KEY = 'carnet-rest';
  const currentWorkout = $('.exlist')?.dataset.workout || null;
  let restEnd = 0, restTotal = 0, restTimer = null, restCard = null, restNext = '';
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
  function saveRest() {
    try { localStorage.setItem(REST_KEY, JSON.stringify({ end: restEnd, total: restTotal, next: restNext, workout: currentWorkout })); } catch (e) { /* stockage indisponible */ }
  }
  // seconds : durée ; next : exercice suivant (repos de transition) ; card : exercice dont on règle le repos entre séries
  function startRest(seconds, { next = '', card = null, end = 0 } = {}) {
    if (!rest) return;
    restTotal = seconds; restEnd = end || Date.now() + seconds * 1000;
    restCard = card; restNext = next;
    rest.hidden = false;
    rest.classList.toggle('is-over', end > 0 && end <= Date.now()); // reprise d'un repos déjà fini : pas de bip
    rest.classList.toggle('is-transition', !!next);
    $('.rest-label', rest).textContent = rest.classList.contains('is-over') ? 'Go !' : next ? 'Transition' : 'Repos';
    const nx = $('.rest-next', rest);
    nx.hidden = !next;
    nx.textContent = next ? 'Ensuite : ' + next : '';
    clearInterval(restTimer);
    renderRest();
    restTimer = setInterval(renderRest, 250);
    saveRest();
  }
  function stopRest() {
    clearInterval(restTimer);
    if (rest) rest.hidden = true;
    try { localStorage.removeItem(REST_KEY); } catch (e) { /* stockage indisponible */ }
  }
  // Reprise après rechargement (même séance, repos fini depuis moins de 5 min)
  try {
    const saved = JSON.parse(localStorage.getItem(REST_KEY) || 'null');
    if (saved && currentWorkout && saved.workout === currentWorkout && saved.end > Date.now() - 300000) {
      startRest(saved.total, { next: saved.next, end: saved.end });
    } else if (saved && (!saved.workout || saved.end < Date.now() - 300000)) localStorage.removeItem(REST_KEY);
  } catch (e) { /* stockage indisponible */ }
  rest?.addEventListener('click', (e) => {
    const b = e.target.closest('[data-rest]');
    if (!b) return;
    if (b.dataset.rest === 'skip') return stopRest();
    const d = parseInt(b.dataset.rest, 10);
    restEnd += d * 1000; restTotal = Math.max(restTotal + d, 1);
    // le repos ajusté reste valable pour les séries suivantes de l'exercice (pour cette séance)
    if (restCard && !restNext) restCard.dataset.rest = Math.max(15, Math.round(restTotal));
    rest.classList.remove('is-over'); renderRest(); saveRest();
  });

  // --- Glisser-déposer des exercices (séance et programme) -----------------
  // On maintient la poignée puis on glisse (doigt ou souris). Pendant le tri, les cartes se replient
  // sur leur titre pour que toute la séance tienne sur un écran de téléphone, et la page défile seule
  // près des bords. La poignée d'un superset déplace tout le groupe ; un exercice lâché entre deux
  // exercices d'un même superset rejoint ce superset.
  function sortable(list, { card, id, commit }) {
    const cards = () => $$(card, list);
    const groupOf = (c) => (c.dataset.ss ? parseInt(c.dataset.ss, 10) : null);
    let drag = null;

    // Cibles possibles : chaque exercice, ou chaque bloc (exercice seul / superset entier) si l'on déplace un groupe
    function units() {
      const others = cards().filter((c) => !drag.moving.includes(c));
      const out = [];
      others.forEach((c) => {
        const g = groupOf(c), last = out.at(-1);
        if (drag.group !== null && g !== null && last && last.g === g) last.els.push(c);
        else out.push({ g, els: [c] });
      });
      return out.map((u) => {
        const head = drag.group !== null && u.g !== null ? $(`[data-ss-head="${u.g}"]`, list) : null;
        return { ...u, top: (head || u.els[0]).getBoundingClientRect().top, bottom: u.els.at(-1).getBoundingClientRect().bottom };
      });
    }

    function update() {
      const delta = drag.y + scrollY - drag.startY;
      if (Math.abs(delta) > 4) drag.moved = true;
      const dy = drag.fix + delta;
      drag.moving.forEach((el) => (el.style.transform = `translateY(${dy}px)`));
      // la place visée est celle du doigt (la ligne de dépose s'affiche sous le pouce)
      const us = units();
      let i = us.findIndex((u) => (u.top + u.bottom) / 2 > drag.y);
      if (i < 0) i = us.length;
      drag.units = us;
      drag.index = i;
      if (!us.length) return;
      const y = i === 0 ? us[0].top - 5 : i === us.length ? us.at(-1).bottom + 5 : (us[i - 1].bottom + us[i].top) / 2;
      drag.line.style.top = (y - list.getBoundingClientRect().top) + 'px';
    }

    function autoScroll() {
      if (!drag) return;
      const top = 70, bottom = innerHeight - 110; // marge pour la barre d'onglets mobile
      const v = drag.y < top ? -(top - drag.y) / 5 : drag.y > bottom ? (drag.y - bottom) / 5 : 0;
      if (v) { scrollBy(0, Math.round(v)); update(); }
      drag.raf = requestAnimationFrame(autoScroll);
    }

    list.addEventListener('pointerdown', (e) => {
      const handle = e.target.closest('[data-drag]');
      if (!handle || drag || e.button > 0 || list.classList.contains('is-busy')) return;
      e.preventDefault();
      const head = handle.closest('[data-ss-head]');
      const group = head ? parseInt(head.dataset.ssHead, 10) : null;
      const origin = head || handle.closest(card);
      const moving = head ? [head, ...cards().filter((c) => groupOf(c) === group)] : [origin];
      // Repli des cartes : on garde la poignée sous le doigt
      const grab = e.clientY - origin.getBoundingClientRect().top;
      list.classList.add('is-sorting');
      const box = origin.getBoundingClientRect();
      const want = e.clientY - Math.min(grab, box.height / 2);
      scrollBy(0, Math.round(box.top - want));
      // si la page est trop courte pour défiler, on décale la carte pour qu'elle reste sous le doigt
      const fix = want - origin.getBoundingClientRect().top;
      handle.setPointerCapture?.(e.pointerId);
      const line = document.createElement('div');
      line.className = 'drop-line';
      list.append(line);
      moving.forEach((el) => el.classList.add('is-dragging'));
      navigator.vibrate?.(10);
      drag = { pid: e.pointerId, moving, group, line, fix, y: e.clientY, startY: e.clientY + scrollY, moved: false };
      update();
      autoScroll();
    });

    addEventListener('pointermove', (e) => {
      if (!drag || e.pointerId !== drag.pid) return;
      drag.y = e.clientY;
      update();
    });
    addEventListener('pointerup', (e) => { if (drag && e.pointerId === drag.pid) drop(false); });
    addEventListener('pointercancel', (e) => { if (drag && e.pointerId === drag.pid) drop(true); });
    addEventListener('keydown', (e) => { if (drag && e.key === 'Escape') drop(true); });

    async function drop(cancel) {
      const d = drag;
      drag = null;
      cancelAnimationFrame(d.raf);
      d.line.remove();
      d.moving.forEach((el) => { el.style.transform = ''; el.classList.remove('is-dragging'); });
      const before = cards().map((c) => id(c) + ':' + groupOf(c)).join();
      let order = null;
      if (!cancel && d.moved && d.units) {
        const flat = (us) => us.flatMap((u) => u.els);
        const groups = new Map(cards().map((c) => [c, groupOf(c)]));
        let seq;
        if (d.group !== null) {
          seq = [...flat(d.units.slice(0, d.index)), ...d.moving.slice(1), ...flat(d.units.slice(d.index))];
        } else {
          const x = d.moving[0], others = flat(d.units);
          seq = [...others.slice(0, d.index), x, ...others.slice(d.index)];
          const gx = groupOf(x), gp = groups.get(seq[d.index - 1]) ?? null, gn = groups.get(seq[d.index + 1]) ?? null;
          // garde son superset s'il reste collé à un membre, rejoint celui dans lequel on le lâche, sinon devient seul
          groups.set(x, gx !== null && (gp === gx || gn === gx) ? gx : gp !== null && gp === gn ? gp : null);
        }
        order = seq.map((c) => ({ id: id(c), g: groups.get(c) }));
        if (order.map((o) => o.id + ':' + o.g).join() === before) order = null;
      }
      if (!order) { list.classList.remove('is-sorting'); return; }
      list.classList.add('is-busy');
      try { await commit(order, id(d.moving[d.group !== null ? 1 : 0])); }
      catch (err) { alert(err.message); location.reload(); return; }
      list.classList.remove('is-sorting', 'is-busy');
    }
  }

  const tplRows = $('.tpl-rows');
  if (tplRows) {
    sortable(tplRows, {
      card: '.tpl-row',
      id: (c) => parseInt(c.dataset.id, 10),
      commit: (order, moved) => new Promise(() => {
        const form = tplRows.closest('form');
        form.elements.order.value = JSON.stringify(order);
        form.elements.moved.value = moved;
        form.requestSubmit();
      }),
    });
  }

  // --- Séries : enregistrement automatique ---------------------------------
  const exlist = $('.exlist');
  const saveTimers = new Map(); // clé → [minuteur, envoi]
  function saveField(input) {
    const row = input.closest('.set-row');
    const key = row.dataset.set + input.name;
    const send = async () => {
      try {
        await api('set_update', { id: row.dataset.set, field: input.name, value: input.value });
        input.classList.remove('err');
      } catch (e) { input.classList.add('err'); console.error(e); }
    };
    clearTimeout(saveTimers.get(key)?.[0]);
    saveTimers.set(key, [setTimeout(() => { saveTimers.delete(key); send(); }, 350), send]);
  }
  // Envoie tout de suite les saisies en attente (avant de recharger la liste)
  async function flushSaves() {
    const pending = [...saveTimers.values()];
    saveTimers.clear();
    pending.forEach(([t]) => clearTimeout(t));
    await Promise.all(pending.map(([, send]) => send()));
  }
  // Recharge la liste des exercices sans recharger la page (minuteurs et défilement conservés)
  async function refreshList() {
    await flushSaves();
    const html = await (await fetch(location.href)).text();
    const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('.exlist');
    if (fresh) exlist.innerHTML = fresh.innerHTML;
    refreshTotals();
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
    sortable(exlist, {
      card: '.excard',
      id: (c) => parseInt(c.dataset.we, 10),
      commit: async (order) => {
        await flushSaves();
        await api('workout_reorder', { workout: exlist.dataset.workout, order });
        await refreshList();
      },
    });

    exlist.addEventListener('input', (e) => {
      if (e.target.matches('.set-in')) { saveField(e.target); refreshTotals(); }
    });

    // Superset : après une série, on passe à l'exercice suivant du groupe (sans repos) ;
    // après le dernier du groupe, repos puis retour au premier exercice qui a encore des séries à faire.
    function supersetNext(card) {
      const members = $$(`.excard[data-ss="${card.dataset.ss}"]`, exlist);
      const i = members.indexOf(card);
      const pending = (c) => $('.set-row[data-set]:not(.is-done)', c);
      const next = members.slice(i + 1).find(pending) || (i === members.length - 1 ? members.find(pending) : null);
      if (!next) return false;
      const row = pending(next);
      row.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return i < members.length - 1;
    }

    exlist.addEventListener('click', async (e) => {
      const joint = e.target.closest('[data-ss-toggle]');
      if (joint) {
        joint.disabled = true;
        await api('workout_superset_toggle', { we: joint.dataset.ssToggle });
        await refreshList();
        return;
      }

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
        if (done) {
          // dans un superset, pas de repos avant d'avoir fait le tour du groupe
          const chained = card.dataset.ss && supersetNext(card);
          if (!chained) {
            const group = card.dataset.ss ? $$(`.excard[data-ss="${card.dataset.ss}"]`, exlist) : [card];
            const last = group.at(-1);
            if (group.every((c) => !$('.set-row[data-set]:not(.is-done)', c))) {
              // exercice (ou superset) terminé : repos de transition, en annonçant le suivant
              if (last.dataset.next) startRest(parseInt(last.dataset.restAfter, 10) || 90, { next: last.dataset.next });
              else stopRest();
            } else startRest(parseInt(card.dataset.rest, 10) || 90, { card });
          }
        }
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

      if (e.target.closest('[data-replace]')) {
        e.target.closest('details')?.removeAttribute('open');
        openPicker({ replace: card.dataset.we }, async (exId, name) => {
          if ($('.set-row.is-done', card) && !confirm('Des séries sont déjà cochées : elles compteront pour « ' + name + ' ». Continuer ?')) return;
          const template = card.dataset.tpl === '1' && confirm('Utiliser aussi « ' + name + ' » dans le programme à la place de celui-ci ?');
          await flushSaves();
          await api('workout_replace_exercise', { we: card.dataset.we, exercise: exId, template: template ? 1 : 0 });
          await refreshList();
        });
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
  let onPick = null, pickMode = null; // pickMode.replace = exercice de séance à remplacer
  async function searchPicker() {
    const q = $('#picker-q').value, muscle = $('#picker-muscle').value;
    const res = await fetch(pickMode?.replace
      ? `api.php?action=replace_suggestions&we=${encodeURIComponent(pickMode.replace)}&q=${encodeURIComponent(q)}`
      : `api.php?action=search&q=${encodeURIComponent(q)}&muscle=${encodeURIComponent(muscle)}`);
    const list = await res.json();
    const box = $('#picker-list');
    box.innerHTML = list.length ? '' : '<p class="empty" style="padding:1rem">Aucun résultat.</p>';
    for (const ex of list) {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'pick';
      b.innerHTML = `${ex.img ? `<img src="${ex.img}" alt="" loading="lazy">` : ''}<span><strong></strong><small class="muted"></small></span>`;
      $('strong', b).textContent = ex.name;
      $('small', b).textContent = ex.sub + (ex.used ? ` · ${ex.used}× fait` : '');
      b.addEventListener('click', () => onPick?.(ex.id, ex.name));
      box.appendChild(b);
    }
  }
  if (picker) {
    let t;
    $('#picker-q').addEventListener('input', () => { clearTimeout(t); t = setTimeout(searchPicker, 200); });
    $('#picker-muscle').addEventListener('change', searchPicker);
  }
  function openPicker(mode, pick) {
    pickMode = mode;
    onPick = async (exId, name) => { picker.close(); await pick(exId, name); };
    $('#picker-muscle').hidden = !!mode?.replace;
    $('#picker-q').placeholder = mode?.replace ? 'Alternatives (mêmes muscles) : filtrer par nom…' : 'Rechercher : leg press, curl, squat…';
    picker.showModal();
    $('#picker-q').value = '';
    if (matchMedia('(hover: hover)').matches) $('#picker-q').focus(); // sur mobile, pas de clavier d'office
    searchPicker();
  }
  $$('[data-pick]').forEach((btn) => btn.addEventListener('click', () => {
    const kind = btn.dataset.pick, id = btn.dataset.id;
    openPicker(null, async (exId) => {
      if (kind === 'workout') await api('workout_add_exercise', { workout: id, exercise: exId });
      else await api('template_add_exercise', { template: id, exercise: exId });
      location.reload();
    });
  }));

  // --- Réglages machine : enregistrés au fil de la frappe, propres à chaque compte ---
  const noteTimers = new Map();
  document.addEventListener('input', (e) => {
    const inp = e.target.closest('[data-ex-note]');
    if (!inp) return;
    const ex = inp.dataset.exNote;
    $$(`[data-ex-note="${CSS.escape(ex)}"]`).forEach((o) => { if (o !== inp) o.value = inp.value; }); // même exercice ailleurs dans la page
    clearTimeout(noteTimers.get(ex));
    noteTimers.set(ex, setTimeout(() => api('exercise_note', { exercise: ex, note: inp.value }).catch(console.error), 600));
  });

  // --- Écran toujours allumé pendant une séance en cours (désactivable) ---
  const wakeBtn = $('[data-wakelock]');
  if (wakeBtn && 'wakeLock' in navigator) {
    const KEY = 'carnet-wakelock';
    let lock = null;
    const wanted = () => { try { return localStorage.getItem(KEY) !== 'off'; } catch (e) { return true; } };
    const render = () => {
      wakeBtn.setAttribute('aria-pressed', String(wanted()));
      $('span', wakeBtn).textContent = wanted() ? 'Écran toujours allumé' : 'Écran en veille normale';
    };
    async function acquire() {
      if (!wanted() || document.visibilityState !== 'visible' || lock) return;
      try { lock = await navigator.wakeLock.request('screen'); lock.addEventListener('release', () => { lock = null; }); } catch (e) { /* refusé (batterie faible…) */ }
    }
    wakeBtn.hidden = false;
    wakeBtn.addEventListener('click', () => {
      try { localStorage.setItem(KEY, wanted() ? 'off' : 'on'); } catch (e) { /* stockage indisponible */ }
      if (wanted()) acquire(); else lock?.release();
      render();
    });
    document.addEventListener('visibilitychange', acquire);
    render();
    acquire();
  }

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
  // Déconnexion : on vide les pages gardées pour le hors ligne (elles appartiennent à ce compte)
  if (!e.defaultPrevented && /[?&]p=logout\b/.test(e.target.getAttribute('action') || '')) purgeOffline();
});

// --- Application installable + hors ligne (sw.js) ---------------------------
function purgeOffline() {
  try { ['carnet-rest', 'carnet-queue'].forEach((k) => localStorage.removeItem(k)); } catch (e) { /* stockage indisponible */ }
  if (window.caches) caches.keys().then((keys) => keys.forEach((k) => caches.delete(k)));
}
if (document.body.classList.contains('page-login') || document.body.classList.contains('page-register')) purgeOffline();
else if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
  navigator.serviceWorker.register('sw.js').catch(() => { /* navigateur sans support : l'app marche en ligne */ });
}
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

// --- Amis : kudos (AJAX) et copie du lien d'invitation ---
document.addEventListener('click', async (e) => {
  const k = e.target.closest('[data-kudos]');
  if (k) {
    if (k.disabled) return;
    k.disabled = true;
    try {
      const res = await fetch('api.php?action=kudos', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name=csrf]')?.content || '' },
        body: JSON.stringify({ workout: Number(k.dataset.kudos) }),
      });
      if (res.status === 401) { location.href = '?p=login'; return; }
      const json = await res.json().catch(() => ({}));
      if (!res.ok || json.error) throw new Error(json.error || res.statusText);
      // même séance affichée à plusieurs endroits : on met tous les boutons à jour
      document.querySelectorAll('[data-kudos="' + k.dataset.kudos + '"]').forEach((b) => {
        b.setAttribute('aria-pressed', json.mine ? 'true' : 'false');
        b.querySelector('.kudos-n').textContent = json.count;
      });
    } catch (err) {
      alert('Kudos impossible : ' + err.message);
    }
    k.disabled = false;
    return;
  }
  const c = e.target.closest('[data-copy]');
  if (c) {
    const input = document.getElementById(c.dataset.copy);
    if (!input) return;
    input.select();
    let ok = false;
    try { await navigator.clipboard.writeText(input.value); ok = true; } catch (err) { try { ok = document.execCommand('copy'); } catch (e2) { /* sélection manuelle */ } }
    const label = c.querySelector('span');
    if (label) {
      label.textContent = ok ? 'Copié' : 'Sélectionné';
      setTimeout(() => { label.textContent = 'Copier'; }, 2000);
    }
  }
});
