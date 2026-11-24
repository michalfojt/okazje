(() => {
'use strict';

const CSRF = document.querySelector('meta[name=csrf]').content;
const $ = (s, r = document) => r.querySelector(s);
const view = $('#view');

/* ---------- ikony (statyczne ścieżki, lucide) ---------- */
const ICON = {
  heart: '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
  moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
  search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  ext: '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
  trash: '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
  edit: '<path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
};
function icon(n, cls = '') {
  const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('class', ('i ' + cls).trim());
  s.setAttribute('aria-hidden', 'true');
  s.innerHTML = ICON[n];
  return s;
}

/* ---------- DOM ---------- */
function el(tag, props = {}, ...kids) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (v == null || v === false) continue;
    if (k === 'class') n.className = v;
    else if (k === 'text') n.textContent = v;
    else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
    else n.setAttribute(k, v === true ? '' : v);
  }
  for (const c of kids.flat()) if (c != null && c !== false) n.append(c.nodeType ? c : document.createTextNode(String(c)));
  return n;
}

function toast(msg, err = false) {
  const t = el('div', { class: 'toast' + (err ? ' err' : ''), text: msg });
  $('#toasts').append(t);
  setTimeout(() => t.remove(), err ? 5000 : 2600);
}

async function api(a, { method = 'GET', body, params } = {}) {
  const q = new URLSearchParams({ a, ...(params || {}) });
  const r = await fetch('api.php?' + q, {
    method, credentials: 'same-origin',
    headers: { 'X-CSRF': CSRF, ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  let j = {};
  try { j = await r.json(); } catch (e) { /* pusta odpowiedź */ }
  if (r.status === 401) { location.reload(); throw new Error('Sesja wygasła'); }
  if (!r.ok) throw new Error(j.error || 'Błąd serwera');
  return j;
}

/* ---------- stan ---------- */
const S = {
  runs: [], cats: [], fav: new Set(), today: '', retention: 14,
  off: { run: 'latest', cat: '', q: '', sort: 'new', list: [], meta: null },
  fv: { cat: '', q: '', sort: 'added', list: [] },
};
const catName = k => (S.cats.find(c => c.key === k) || {}).name || 'Inne';

async function loadState() {
  const st = await api('state');
  S.runs = st.runs; S.cats = st.categories; S.fav = new Set(st.fav_ids);
  S.today = st.today; S.retention = st.retention;
  updateFavCount();
}
function updateFavCount() {
  const n = $('#nFav');
  n.textContent = S.fav.size;
  n.hidden = S.fav.size === 0;
}

/* ---------- daty ---------- */
const WD = ['niedz.', 'pon.', 'wt.', 'śr.', 'czw.', 'pt.', 'sob.'];
function dayParts(day) {
  const [y, m, d] = day.split('-').map(Number);
  return { y, m, d, wd: WD[new Date(Date.UTC(y, m - 1, d)).getUTCDay()] };
}
function dayDiff(a, b) {
  const t = s => { const p = dayParts(s); return Date.UTC(p.y, p.m - 1, p.d); };
  return Math.round((t(a) - t(b)) / 86400000);
}
function dayLabel(day) {
  const diff = dayDiff(S.today, day);
  const p = dayParts(day);
  const dm = String(p.d).padStart(2, '0') + '.' + String(p.m).padStart(2, '0');
  if (diff === 0) return { top: 'Dziś', sub: dm };
  if (diff === 1) return { top: 'Wczoraj', sub: dm };
  return { top: p.wd + ' ' + dm, sub: '' };
}
function fmtIso(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleString('pl-PL', { timeZone: 'Europe/Warsaw', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
function fmtShort(iso) {
  return iso ? new Date(iso).toLocaleDateString('pl-PL', { timeZone: 'Europe/Warsaw', day: '2-digit', month: '2-digit' }) : '';
}
const safeHref = u => (/^https?:\/\//i.test(u) ? u : '#');

/* ---------- karta oferty ---------- */
function discount(o) {
  if (o.price_num && o.original_num && o.original_num > o.price_num) return Math.round(100 * (1 - o.price_num / o.original_num));
  return 0;
}

/* zdjęcie albo miejsce na zdjęcie z przyciskiem „Znajdź zdjęcie" */
function mountImage(media, o) {
  media.querySelectorAll('img, .ph, .find-img, .via').forEach(n => n.remove());
  media.classList.remove('noimg');
  if (o.image) {
    const img = el('img', { src: o.image, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' });
    img.addEventListener('error', () => { img.remove(); o.image = null; mountImage(media, o); });
    media.prepend(img);
    if (o.image_via === 'search') media.append(el('span', { class: 'via', text: 'zdjęcie poglądowe', title: 'Znalezione w wyszukiwarce obrazów, może przedstawiać inny model' }));
    return;
  }
  media.classList.add('noimg');
  const btn = el('button', { class: 'btn sm find-img', type: 'button', text: 'Znajdź zdjęcie' });
  btn.addEventListener('click', async () => {
    btn.disabled = true; btn.textContent = 'Szukam…';
    try {
      const r = await api('img_find', { method: 'POST', body: { id: o.id } });
      o.image = r.image + '?t=' + Date.now(); o.image_via = r.via;
      mountImage(media, o);
    } catch (e) {
      btn.disabled = false; btn.textContent = 'Znajdź zdjęcie';
      toast(e.message, true);
    }
  });
  media.prepend(el('span', { class: 'ph', text: 'brak zdjęcia' }), btn);
}

function card(o, onUnfav) {
  const media = el('div', { class: 'media' });
  mountImage(media, o);

  const flags = el('div', { class: 'flags' });
  if (o.new) flags.append(el('span', { class: 'flag', text: 'nowa' }));
  if (o.confidence === 'low') flags.append(el('span', { class: 'flag warn', text: 'niepewna cena' }));
  if (flags.children.length) media.append(flags);

  const heart = el('button', { class: 'heart', type: 'button', 'aria-pressed': String(S.fav.has(o.id)), 'aria-label': 'Dodaj do wybranych', title: 'Wybrane' }, icon('heart'));
  heart.addEventListener('click', async () => {
    const on = heart.getAttribute('aria-pressed') !== 'true';
    heart.setAttribute('aria-pressed', String(on));
    heart.classList.remove('pop'); void heart.offsetWidth; if (on) heart.classList.add('pop');
    on ? S.fav.add(o.id) : S.fav.delete(o.id);
    updateFavCount();
    try {
      await api('fav', { method: 'POST', body: { id: o.id, on } });
      if (!on && onUnfav) onUnfav(o, art);
    } catch (e) {
      heart.setAttribute('aria-pressed', String(!on));
      on ? S.fav.delete(o.id) : S.fav.add(o.id);
      updateFavCount();
      toast(e.message, true);
    }
  });
  media.append(heart);

  const d = discount(o);
  const priceRow = el('div', { class: 'price-row' },
    el('span', { class: 'price', text: o.price || 'brak ceny' }),
    o.original_price ? el('span', { class: 'orig', text: o.original_price }) : null,
    d ? el('span', { class: 'off', text: '−' + d + '%' }) : null,
    o.promo_end ? el('span', { class: 'until', text: 'do ' + o.promo_end }) : null,
  );

  const notes = o.notes ? el('p', { class: 'notes', text: o.notes }) : null;
  const more = notes && o.notes.length > 130 ? el('button', { class: 'more', type: 'button', text: 'Więcej', onclick: e => {
    const open = notes.classList.toggle('open');
    e.target.textContent = open ? 'Mniej' : 'Więcej';
  } }) : null;

  const when = o.added ? 'dodano ' + fmtShort(o.added) : (o.last_seen ? 'widziana ' + fmtShort(o.last_seen) : '');
  const art = el('article', { class: 'card' + (o.confidence === 'low' ? ' low' : '') },
    media,
    el('div', { class: 'body' },
      el('div', { class: 'meta' }, el('span', { class: 'tag', text: catName(o.category) }), el('span', { class: 'shop', text: o.shop || 'sklep nieznany' })),
      el('h3', { class: 'title' }, el('a', { href: safeHref(o.url), target: '_blank', rel: 'noopener noreferrer', text: o.title })),
      priceRow, notes, more,
      el('div', { class: 'foot' }, el('span', { text: when }),
        el('a', { class: 'open-link', href: safeHref(o.url), target: '_blank', rel: 'noopener noreferrer' }, 'Otwórz', icon('ext'))),
    ),
  );
  return art;
}

/* ---------- wspólne: filtry i siatka ---------- */
function applyFilters(list, f) {
  const q = f.q.trim().toLowerCase();
  let out = list.filter(o => (!f.cat || o.category === f.cat) &&
    (!q || (o.title + ' ' + (o.shop || '') + ' ' + (o.notes || '')).toLowerCase().includes(q)));
  const num = (o, k) => (o[k] == null ? Infinity : o[k]);
  const sorters = {
    new: (a, b) => (b.last_seen || '').localeCompare(a.last_seen || ''),
    added: (a, b) => (b.added || '').localeCompare(a.added || ''),
    asc: (a, b) => num(a, 'price_num') - num(b, 'price_num'),
    desc: (a, b) => (b.price_num || -1) - (a.price_num || -1),
    off: (a, b) => discount(b) - discount(a),
  };
  return out.sort(sorters[f.sort] || sorters.new);
}

function filterBar(f, list, repaint, sortOpts) {
  const counts = {};
  list.forEach(o => { counts[o.category] = (counts[o.category] || 0) + 1; });
  const catRow = el('div', { class: 'cats' });
  const mk = (key, name, n) => el('button', { class: 'chip' + (f.cat === key ? ' on' : ''), type: 'button', onclick: () => { f.cat = key; renderChips(); repaint(); } }, name, el('small', { text: String(n) }));
  const renderChips = () => {
    catRow.replaceChildren(mk('', 'Wszystkie', list.length), ...S.cats.filter(c => counts[c.key]).map(c => mk(c.key, c.name, counts[c.key])));
  };
  renderChips();
  const search = el('div', { class: 'search' }, icon('search'),
    el('input', { type: 'text', placeholder: 'Szukaj w ofertach', 'aria-label': 'Szukaj w ofertach', value: f.q, oninput: e => { f.q = e.target.value; repaint(); } }));
  const sort = el('select', { class: 'sort', 'aria-label': 'Sortowanie', onchange: e => { f.sort = e.target.value; repaint(); } },
    ...sortOpts.map(([v, t]) => el('option', { value: v, text: t, selected: f.sort === v })));
  return el('div', { class: 'filters' }, search, catRow, sort);
}

function grid(list, emptyMsg, onUnfav) {
  if (!list.length) return el('div', { class: 'empty' }, el('h2', { text: emptyMsg[0] }), el('p', { text: emptyMsg[1] }));
  return el('div', { class: 'grid' }, ...list.map(o => card(o, onUnfav)));
}

/* ---------- Oferty ---------- */
function runDay(runId) {
  const r = S.runs.find(x => x.id === runId);
  return r ? r.day : null;
}

async function viewOffers() {
  view.replaceChildren(el('div', { class: 'grid' }, ...Array.from({ length: 6 }, () => el('div', { class: 'skel' }))));
  const o = S.off;
  const data = await api('offers', { params: { run: o.run } });
  o.list = data.offers; o.meta = data.meta;

  const days = [...new Set(S.runs.map(r => r.day))];
  const curDay = o.run.startsWith('day:') ? o.run.slice(4) : runDay(o.run);

  const go = run => { o.run = run; o.cat = ''; viewOffers(); };
  const seg = el('div', { class: 'seg', role: 'group', 'aria-label': 'Zakres' },
    el('button', { type: 'button', class: o.run === 'latest' ? 'on' : '', text: 'Najnowsze', onclick: () => go('latest') }),
    el('button', { type: 'button', class: o.run === 'all' ? 'on' : '', text: S.retention + ' dni', onclick: () => go('all') }));

  const dayEls = [];
  const win = [];
  for (let i = 0; i < S.retention; i++) {
    const p = dayParts(S.today);
    const t = new Date(Date.UTC(p.y, p.m - 1, p.d - i));
    win.push(t.toISOString().slice(0, 10));
  }
  for (const day of win) {
    const runs = S.runs.filter(r => r.day === day);
    const lb = dayLabel(day);
    const b = el('button', { type: 'button', class: 'day' + (curDay === day ? ' on' : '') + (runs.length ? '' : ' none'), disabled: runs.length ? null : true,
      'aria-label': lb.top + ' ' + lb.sub + ', wyszukiwań: ' + runs.length, onclick: () => go('day:' + day) },
      el('span', { class: 'd', text: lb.top }),
      el('span', { class: 'n', text: runs.length ? runs.length + (runs.length === 1 ? ' wyszukiwanie' : runs.length < 5 ? ' wyszukiwania' : ' wyszukiwań') : 'brak' }),
      el('span', { class: 'pips' }, ...runs.map(() => el('i'))));
    dayEls.push(b);
  }

  const bar = el('div', { class: 'timeline' }, seg, el('div', { class: 'days' }, ...dayEls));

  let runRow = null;
  if (curDay) {
    const runs = S.runs.filter(r => r.day === curDay).slice().reverse();
    runRow = el('div', { class: 'runs' },
      el('button', { type: 'button', class: 'chip' + (o.run === 'day:' + curDay ? ' on' : ''), onclick: () => go('day:' + curDay) }, 'Cały dzień'),
      ...runs.map(r => el('button', { type: 'button', class: 'chip' + (o.run === r.id ? ' on' : ''), onclick: () => go(r.id) }, r.time, el('small', { text: r.count + ' ofert' }))));
  }

  const head = el('div', { class: 'page-head' }, el('h1', { text: 'Oferty' }), el('span', { class: 'sub', id: 'sub' }));
  const holder = el('div');
  const paint = () => {
    const rows = applyFilters(o.list, o);
    $('#sub').textContent = rows.length + (rows.length === o.list.length ? '' : ' z ' + o.list.length) + ' ofert' + subRange(o.meta);
    holder.replaceChildren(grid(rows, o.list.length
      ? ['Nic nie pasuje do filtrów', 'Zmień kategorię albo wyczyść wyszukiwanie.']
      : S.runs.length ? ['Brak ofert w tym zakresie', 'Wybierz inny dzień albo wyszukiwanie.']
        : ['Brak danych', 'Pierwsze oferty pojawią się po uruchomieniu importu (ingest.php).']));
  };
  const filters = o.list.length ? filterBar(o, o.list, paint, [['new', 'Najnowsze'], ['asc', 'Cena rosnąco'], ['desc', 'Cena malejąco'], ['off', 'Największy rabat']]) : null;
  view.replaceChildren(...[head, bar, runRow, filters, holder].filter(Boolean));
  paint();
}
function subRange(m) {
  if (!m || !m.to) return '';
  if (m.batches === 1) return ', wyszukiwanie ' + fmtIso(m.to);
  const a = fmtShort(m.from), b = fmtShort(m.to);
  return ', z ' + m.batches + ' wyszukiwań (' + (a === b ? a : a + ' do ' + b) + ')';
}

/* ---------- Wybrane ---------- */
async function viewFav() {
  view.replaceChildren(el('div', { class: 'grid' }, ...Array.from({ length: 3 }, () => el('div', { class: 'skel' }))));
  const f = S.fv;
  f.list = (await api('favorites')).offers;
  const head = el('div', { class: 'page-head' }, el('h1', { text: 'Wybrane' }), el('span', { class: 'sub', id: 'sub' }));
  const holder = el('div');
  const paint = () => {
    const rows = applyFilters(f.list, f);
    $('#sub').textContent = rows.length + (rows.length === f.list.length ? '' : ' z ' + f.list.length) + ' ofert';
    holder.replaceChildren(grid(rows, f.list.length
      ? ['Nic nie pasuje do filtrów', 'Zmień kategorię albo wyczyść wyszukiwanie.']
      : ['Nie masz jeszcze wybranych ofert', 'Kliknij serduszko przy ofercie, a trafi tutaj i zostanie nawet po 14 dniach.'],
      (o, art) => {
        f.list = f.list.filter(x => x.id !== o.id);
        art.classList.add('gone');
        setTimeout(() => { art.remove(); paint(); }, 220);
      }));
  };
  const filters = f.list.length ? filterBar(f, f.list, paint, [['added', 'Ostatnio dodane'], ['asc', 'Cena rosnąco'], ['desc', 'Cena malejąco'], ['off', 'Największy rabat']]) : null;
  view.replaceChildren(...[head, filters, holder].filter(Boolean));
  paint();
}

/* ---------- Kategorie ---------- */
function plural(n, a, b, c) { return n === 1 ? a : (n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 > 20)) ? b : c; }

async function viewCats() {
  view.replaceChildren(el('div', { class: 'skel', style: 'height:240px' }));
  const [, src] = await Promise.all([loadState(), api('sources')]);
  let sources = src.sources;

  const head = el('div', { class: 'page-head' }, el('h1', { text: 'Kategorie' }),
    el('span', { class: 'sub', text: S.cats.filter(c => !c.virtual).length + ' ' + plural(S.cats.length, 'kategoria', 'kategorie', 'kategorii') }));

  /* --- lista kategorii --- */
  const list = el('div', { class: 'panel' });
  const drawCats = () => list.replaceChildren(...S.cats.filter(c => !c.virtual).map(catRow), addCatRow());

  function catRow(c) {
    const row = el('section', { class: 'cat' });
    const show = () => {
      row.replaceChildren(
        el('div', { class: 'cat-h' },
          el('h3', { text: c.name }), el('code', { text: c.key }),
          el('div', { class: 'stats' },
            el('span', {}, el('b', { text: String(c.count) }), ' ' + plural(c.count, 'oferta', 'oferty', 'ofert') + ' z ' + S.retention + ' dni'),
            el('span', {}, el('b', { text: String(c.fav) }), ' ' + plural(c.fav, 'wybrana', 'wybrane', 'wybranych')))),
        el('div', { class: 'cat-b' },
          el('div', {}, el('h4', { text: 'Co to jest' }), el('p', { text: c.what || 'Brak opisu.' })),
          el('div', {}, el('h4', { text: 'Jak działa' }), el('p', { text: c.how || 'Brak opisu.' }))),
        el('div', { class: 'cat-actions' },
          el('button', { class: 'btn sm', type: 'button', onclick: edit }, icon('edit'), 'Edytuj opis'),
          c.custom ? delBtn() : null));
    };
    const delBtn = () => {
      const b = el('button', { class: 'btn sm ghost', type: 'button' }, icon('trash'), 'Usuń kategorię');
      let armed = null;
      b.addEventListener('click', async () => {
        if (!armed) { b.classList.add('danger-confirm'); b.lastChild.textContent = 'Na pewno usunąć?'; armed = setTimeout(() => { armed = null; b.classList.remove('danger-confirm'); b.lastChild.textContent = 'Usuń kategorię'; }, 3500); return; }
        try { await api('cat_delete', { method: 'POST', body: { key: c.key } }); await loadState(); drawCats(); toast('Kategoria usunięta'); } catch (e) { toast(e.message, true); }
      });
      return b;
    };
    const edit = () => {
      const name = c.custom ? el('input', { type: 'text', value: c.name, maxlength: '60', 'aria-label': 'Nazwa kategorii' }) : null;
      const what = el('textarea', { maxlength: '1500', 'aria-label': 'Co to jest' }); what.value = c.what || '';
      const how = el('textarea', { maxlength: '1500', 'aria-label': 'Jak działa' }); how.value = c.how || '';
      row.replaceChildren(
        el('div', { class: 'cat-h' }, el('h3', { text: c.name }), el('code', { text: c.key })),
        el('div', { class: 'cat-form' },
          name ? el('div', { class: 'full' }, el('label', { text: 'Nazwa' }), name) : null,
          el('div', {}, el('label', { text: 'Co to jest' }), what),
          el('div', {}, el('label', { text: 'Jak działa' }), how)),
        el('div', { class: 'cat-actions' },
          el('button', { class: 'btn sm primary', type: 'button', onclick: async () => {
            try {
              await api('cat_save', { method: 'POST', body: { key: c.key, name: name ? name.value : '', what: what.value, how: how.value } });
              c.what = what.value.trim(); c.how = how.value.trim(); if (name && name.value.trim()) c.name = name.value.trim();
              show(); toast('Zapisano opis');
            } catch (e) { toast(e.message, true); }
          } }, 'Zapisz'),
          el('button', { class: 'btn sm ghost', type: 'button', onclick: show }, 'Anuluj')));
      what.focus();
    };
    show();
    return row;
  }

  function addCatRow() {
    const row = el('div', { class: 'cat' });
    const closed = () => row.replaceChildren(el('div', { class: 'cat-actions' }, el('button', { class: 'btn sm', type: 'button', onclick: open }, icon('plus'), 'Dodaj kategorię')));
    const open = () => {
      const name = el('input', { type: 'text', maxlength: '60', placeholder: 'np. Rowery', 'aria-label': 'Nazwa kategorii' });
      const what = el('textarea', { maxlength: '1500', placeholder: 'Jakie produkty obejmuje kategoria', 'aria-label': 'Co to jest' });
      const how = el('textarea', { maxlength: '1500', placeholder: 'Skąd i jak są wyszukiwane oferty', 'aria-label': 'Jak działa' });
      row.replaceChildren(
        el('div', { class: 'cat-form' },
          el('div', { class: 'full' }, el('label', { text: 'Nazwa' }), name),
          el('div', {}, el('label', { text: 'Co to jest' }), what),
          el('div', {}, el('label', { text: 'Jak działa' }), how)),
        el('div', { class: 'cat-actions' },
          el('button', { class: 'btn sm primary', type: 'button', onclick: async () => {
            try { await api('cat_add', { method: 'POST', body: { name: name.value, what: what.value, how: how.value } }); await loadState(); drawCats(); toast('Dodano kategorię'); }
            catch (e) { toast(e.message, true); }
          } }, 'Dodaj'),
          el('button', { class: 'btn sm ghost', type: 'button', onclick: closed }, 'Anuluj')));
      name.focus();
    };
    closed();
    return row;
  }
  drawCats();

  /* --- strony do przeszukiwania --- */
  const srcPanel = el('div', { class: 'panel' });
  const url = el('input', { type: 'url', placeholder: 'https://sklep.pl/promocje', 'aria-label': 'Adres strony', required: true, spellcheck: 'false', autocapitalize: 'none' });
  const cat = el('select', { 'aria-label': 'Kategoria' }, el('option', { value: '', text: 'Wszystkie kategorie' }),
    ...S.cats.filter(c => !c.virtual).map(c => el('option', { value: c.key, text: c.name })));
  const note = el('input', { type: 'text', placeholder: 'Notatka (opcjonalnie)', maxlength: '200', 'aria-label': 'Notatka' });
  const err = el('p', { class: 'form-err', hidden: true, role: 'alert' });
  const form = el('form', { class: 'src-form', novalidate: true }, url, cat, note, el('button', { class: 'btn primary', type: 'submit' }, icon('plus'), 'Dodaj'));
  form.addEventListener('submit', async e => {
    e.preventDefault();
    err.hidden = true;
    if (!url.value.trim()) { err.textContent = 'Wpisz adres strony.'; err.hidden = false; return; }
    try {
      const r = await api('src_add', { method: 'POST', body: { url: url.value, category: cat.value, note: note.value } });
      sources.unshift(r.item); url.value = ''; note.value = ''; drawSources(); toast('Dodano stronę');
    } catch (ex) { err.textContent = ex.message; err.hidden = false; }
  });
  const srcList = el('div');
  const host = u => { try { return new URL(u).hostname.replace(/^www\./, ''); } catch (e) { return u; } };
  function drawSources() {
    sub.textContent = sources.length ? sources.filter(s => s.active).length + ' aktywnych z ' + sources.length : 'brak';
    if (!sources.length) { srcList.replaceChildren(el('div', { class: 'empty', style: 'border:0' }, el('h2', { text: 'Lista jest pusta' }), el('p', { text: 'Wklej adres sklepu, bloga albo strony z promocjami. Pojawi się tutaj i będzie przeszukiwany jako dodatkowe źródło.' }))); return; }
    srcList.replaceChildren(...sources.map(s => {
      const sw = el('input', { type: 'checkbox', 'aria-label': 'Aktywna' }); sw.checked = !!s.active;
      const row = el('div', { class: 'src' + (s.active ? '' : ' off') });
      sw.addEventListener('change', async () => {
        try { await api('src_toggle', { method: 'POST', body: { id: s.id, active: sw.checked } }); s.active = sw.checked; row.classList.toggle('off', !s.active); sub.textContent = sources.filter(x => x.active).length + ' aktywnych z ' + sources.length; }
        catch (ex) { sw.checked = !sw.checked; toast(ex.message, true); }
      });
      const del = el('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Usuń stronę', title: 'Usuń' }, icon('trash'));
      let armed = null;
      del.addEventListener('click', async () => {
        if (!armed) { del.style.color = 'var(--heart)'; del.title = 'Kliknij ponownie, aby usunąć'; armed = setTimeout(() => { armed = null; del.style.color = ''; del.title = 'Usuń'; }, 3000); return; }
        try { await api('src_delete', { method: 'POST', body: { id: s.id } }); sources = sources.filter(x => x.id !== s.id); drawSources(); } catch (ex) { toast(ex.message, true); }
      });
      row.append(el('label', { class: 'switch' }, sw, el('span')),
        el('div', { class: 'src-main' }, el('div', { class: 'host', text: host(s.url) }), el('code', { text: s.url }), s.note ? el('div', { class: 'note', text: s.note }) : null),
        s.category ? el('span', { class: 'tag', text: catName(s.category) }) : el('span'),
        del);
      return row;
    }));
  }
  const sub = el('span', { class: 'sub' });
  srcPanel.append(form, err, srcList);
  drawSources();

  view.replaceChildren(head, list,
    el('div', { class: 'section-title' }, el('h2', { text: 'Strony do przeszukiwania' }), sub),
    el('p', { class: 'hint', text: 'Adresy z tej listy trafiają do zadania wyszukującego jako dodatkowe źródła obok standardowych sklepów i serwisów. Wyłączona strona zostaje na liście, ale nie jest przeszukiwana.' }),
    srcPanel);
}

/* ---------- router i start ---------- */
const routes = { oferty: viewOffers, wybrane: viewFav, kategorie: viewCats };
let navId = 0;
async function route() {
  const name = (location.hash.replace(/^#\/?/, '') || 'oferty').split('?')[0];
  const fn = routes[name] || viewOffers;
  document.querySelectorAll('.tabs a').forEach(a => a.classList.toggle('on', a.dataset.tab === (routes[name] ? name : 'oferty')));
  const my = ++navId;
  try {
    if (name !== 'kategorie') await loadState();
    if (my === navId) await fn();
  } catch (e) {
    if (my === navId) view.replaceChildren(el('div', { class: 'empty' }, el('h2', { text: 'Nie udało się wczytać danych' }), el('p', { text: e.message })));
  }
  window.scrollTo(0, 0);
}

/* motyw */
function setTheme(t) {
  document.documentElement.setAttribute('data-theme', t);
  try { localStorage.setItem('okz-theme', t); } catch (e) { /* brak dostępu do storage */ }
  const b = $('#theme');
  b.replaceChildren(icon(t === 'dark' ? 'sun' : 'moon'));
  b.title = t === 'dark' ? 'Jasny motyw' : 'Ciemny motyw';
}
$('#theme').addEventListener('click', () => setTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'));
setTheme(document.documentElement.getAttribute('data-theme') || 'dark');

window.addEventListener('hashchange', route);
route();
})();
