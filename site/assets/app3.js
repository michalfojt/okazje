(() => {
'use strict';

const O = window.OKZ;
if (!O) return;
const { el, icon, toast, api, S, catName, safeHref, plural, fmtIso, fmtShort } = O;
const view = O.view;

/* ---------- ikony (statyczne ścieżki, lucide) ---------- */
Object.assign(O.ICON, {
  package: '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
  newspaper: '<path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8M15 18h-5M10 6h8v4h-8V6Z"/>',
  'chevron-down': '<path d="m6 9 6 6 6-6"/>',
  link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
});

/* ---------- pomocnicze ---------- */
const gone = name => !location.hash.replace(/^#\/?/, '').startsWith(name);
const skel = (n, h) => el('div', { class: 'pr-skels' }, ...Array.from({ length: n }, () => el('div', { class: 'skel', style: 'height:' + h + 'px' })));
const host = u => { try { return new URL(u).hostname.replace(/^www\./, ''); } catch (e) { return u; } };
const today = () => S.today || new Date().toISOString().slice(0, 10);

/* data RRRR-MM-DD -> DD.MM */
const dm = d => (d && /^\d{4}-\d{2}-\d{2}/.test(d) ? d.slice(8, 10) + '.' + d.slice(5, 7) : '');
function period(from, to) {
  const a = dm(from), b = dm(to);
  if (a && b) return a === b ? a : a + '-' + b;
  if (a) return 'od ' + a;
  if (b) return 'do ' + b;
  return '';
}
function dayDelta(a, b) {
  const t = s => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10));
  return Math.round((t(a) - t(b)) / 86400000);
}
/* stan gazetki: trwa / od jutra / od DD.MM / zakończona */
function leafStatus(l) {
  const t = today();
  if (l.valid_to && l.valid_to < t) return { key: 'end', text: 'zakończona' };
  if (l.valid_from && l.valid_from > t) {
    return dayDelta(l.valid_from, t) === 1 ? { key: 'soon', text: 'od jutra' } : { key: 'soon', text: 'od ' + dm(l.valid_from) };
  }
  return { key: 'on', text: 'trwa' };
}

/* przycisk usuwania z potwierdzeniem drugim kliknięciem */
function armBtn(label, onConfirm) {
  const b = el('button', { class: 'btn sm ghost', type: 'button' }, icon('trash'), el('span', { text: label }));
  const txt = b.lastChild;
  let armed = null;
  b.addEventListener('click', async () => {
    if (!armed) {
      b.classList.add('danger-confirm'); txt.textContent = 'Na pewno usunąć?';
      armed = setTimeout(() => { armed = null; b.classList.remove('danger-confirm'); txt.textContent = label; }, 3500);
      return;
    }
    clearTimeout(armed); armed = null;
    b.disabled = true;
    try { await onConfirm(); } catch (e) { b.disabled = false; b.classList.remove('danger-confirm'); txt.textContent = label; toast(e.message, true); }
  });
  return b;
}
function armIcon(title, onConfirm) {
  const b = el('button', { class: 'icon-btn', type: 'button', 'aria-label': title, title }, icon('trash'));
  let armed = null;
  b.addEventListener('click', async () => {
    if (!armed) {
      b.style.color = 'var(--heart)'; b.title = 'Kliknij ponownie, aby usunąć';
      armed = setTimeout(() => { armed = null; b.style.color = ''; b.title = title; }, 3000);
      return;
    }
    clearTimeout(armed); armed = null;
    try { await onConfirm(); } catch (e) { b.style.color = ''; b.title = title; toast(e.message, true); }
  });
  return b;
}

function switchEl(checked, label) {
  const cb = el('input', { type: 'checkbox', 'aria-label': label });
  cb.checked = !!checked;
  return { cb, node: el('label', { class: 'switch' }, cb, el('span')) };
}
function field(label, input, cls = '') {
  return el('div', { class: 'pr-f ' + cls }, el('label', { text: label }), input);
}
const extLink = (href, text, cls = '') =>
  el('a', { class: cls, href: safeHref(href), target: '_blank', rel: 'noopener noreferrer' }, text, icon('ext'));

/* =====================================================================
   Moje produkty
   ===================================================================== */
const P = { list: [], q: '', cat: '', open: new Set() };

function offerCard(o) {
  const media = el('div', { class: 'pr-oimg' });
  const noimg = () => { media.classList.add('noimg'); media.replaceChildren(el('span', { text: 'brak zdjęcia' })); };
  if (o.image) {
    const img = el('img', { src: o.image, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' });
    img.addEventListener('error', noimg);
    media.append(img);
  } else noimg();
  return el('article', { class: 'pr-offer' + (o.confidence === 'low' ? ' low' : '') },
    media,
    el('div', { class: 'pr-obody' },
      el('div', { class: 'price-row' },
        el('span', { class: 'pr-oprice', text: o.price || 'brak ceny' }),
        o.original_price ? el('span', { class: 'orig', text: o.original_price }) : null),
      el('div', { class: 'pr-oshop', text: o.shop || 'sklep nieznany' }),
      el('a', { class: 'pr-otitle', href: safeHref(o.url), target: '_blank', rel: 'noopener noreferrer', text: o.title || o.url, title: o.title || '' }),
      el('div', { class: 'pr-odate', text: o.last_seen ? 'widziana ' + fmtShort(o.last_seen) : '' })));
}

/** Znacznik typu trafienia: cała marka albo kategoria (zwykła pozycja bez znacznika). */
function matchTag(m) {
  if (m === 'brand') return el('span', { class: 'pr-tag', text: 'cała marka', title: 'Promocja na wszystkie produkty tej marki: sprawdź, czy obejmuje Twój wariant' });
  if (m === 'keyword') return el('span', { class: 'pr-tag', text: 'kategoria', title: 'Promocja na kategorię pasującą do słów kluczowych produktu' });
  return null;
}

function hitRow(h) {
  const per = period(h.valid_from, h.valid_to);
  return el('div', { class: 'pr-hit' },
    el('div', { class: 'pr-hit-main' },
      el('span', { class: 'pr-hit-shop', text: h.shop || 'gazetka' }),
      el('span', { class: 'pr-hit-name', text: h.item_name }),
      matchTag(h.match)),
    el('div', { class: 'pr-hit-meta' },
      h.deal ? el('span', { class: 'pr-hit-deal', text: h.deal }) : null,
      h.price || !h.deal ? el('span', { class: 'price', text: h.price || 'brak ceny' }) : null,
      h.old_price ? el('span', { class: 'orig', text: h.old_price }) : null,
      per ? el('span', { class: 'pr-hit-per', text: per }) : null,
      h.page ? el('span', { class: 'pr-hit-per', text: 'str. ' + h.page }) : null,
      h.leaflet_url ? extLink(h.leaflet_url, 'Gazetka', 'open-link') : null),
    h.notes ? el('div', { class: 'pr-hit-notes', text: h.notes }) : null);
}

function productItem(p, reload) {
  const row = el('article', { class: 'panel pr-item' });

  const show = () => {
    row.className = 'panel pr-item' + (p.active ? '' : ' is-off');
    const sw = switchEl(p.active, 'Aktywny');
    const state = el('span', { class: 'pr-activet', text: p.active ? 'aktywny' : 'wstrzymany' });
    sw.cb.addEventListener('change', async () => {
      try {
        await api('prod_toggle', { method: 'POST', body: { id: p.id, active: sw.cb.checked } });
        p.active = sw.cb.checked; row.classList.toggle('is-off', !p.active); state.textContent = p.active ? 'aktywny' : 'wstrzymany';
      } catch (e) { sw.cb.checked = !sw.cb.checked; toast(e.message, true); }
    });

    const top = el('div', { class: 'pr-top' },
      el('div', { class: 'pr-id' },
        el('h3', { class: 'pr-name', text: p.name }),
        el('div', { class: 'pr-ids' },
          p.ean ? el('code', { text: p.ean }) : null,
          p.category ? el('span', { class: 'tag', text: catName(p.category) }) : null)),
      el('div', { class: 'pr-active' }, state, sw.node));

    const best = p.best
      ? el('span', { class: 'pr-best' },
        el('b', { text: p.best.price }), ' ',
        p.best.url ? el('a', { href: safeHref(p.best.url), target: '_blank', rel: 'noopener noreferrer', text: p.best.shop || host(p.best.url) }) : (p.best.shop || ''))
      : el('span', { class: 'pr-nobest', text: 'brak ofert' });
    const n = p.offers_count != null ? p.offers_count : (p.offers || []).length;
    const stats = el('div', { class: 'pr-stats' },
      best,
      el('span', { text: n + ' ' + plural(n, 'oferta', 'oferty', 'ofert') }),
      el('span', { text: p.last_searched ? 'wyszukiwano ' + fmtIso(p.last_searched) : 'jeszcze nie wyszukiwano' }));

    const parts = [top, stats];

    if (p.hits && p.hits.length) {
      parts.push(el('div', { class: 'pr-hits' },
        el('div', { class: 'pr-hits-h' }, icon('newspaper'), 'W gazetkach'),
        ...p.hits.map(hitRow)));
    }

    const offers = p.offers || [];
    if (offers.length) {
      const grid = el('div', { class: 'pr-offers', hidden: !P.open.has(p.id) });
      grid.append(...offers.map(offerCard));
      if (n > offers.length) grid.append(el('p', { class: 'hint pr-more', text: 'Pokazano ' + offers.length + ' najtańszych z ' + n + ' ofert.' }));
      const t = el('button', { class: 'pr-toggle', type: 'button', 'aria-expanded': String(P.open.has(p.id)) },
        icon('chevron-down'), el('span', { text: 'Oferty (' + n + ')' }));
      t.addEventListener('click', () => {
        const o = grid.hidden; grid.hidden = !o;
        t.setAttribute('aria-expanded', String(o));
        o ? P.open.add(p.id) : P.open.delete(p.id);
      });
      parts.push(t, grid);
    }

    if (p.desc) parts.push(el('p', { class: 'pr-desc', text: p.desc }));
    if (p.sites && p.sites.length) {
      parts.push(el('div', { class: 'pr-sites' }, el('span', { class: 'pr-sites-l', text: 'Strony priorytetowe' }),
        ...p.sites.map(u => el('a', { class: 'pr-site', href: safeHref(u), target: '_blank', rel: 'noopener noreferrer', text: host(u), title: u }))));
    }

    parts.push(el('div', { class: 'pr-actions' },
      el('button', { class: 'btn sm', type: 'button', onclick: edit }, icon('edit'), 'Edytuj'),
      armBtn('Usuń', async () => {
        await api('prod_delete', { method: 'POST', body: { id: p.id } });
        P.open.delete(p.id); toast('Produkt usunięty'); await reload();
      })));
    row.replaceChildren(...parts);
  };

  const edit = () => {
    row.className = 'panel pr-item editing';
    row.replaceChildren(productForm(p, async () => { toast('Zapisano produkt'); await reload(); }, show));
  };
  show();
  return row;
}

/* formularz dodawania / edycji produktu */
function productForm(p, onSaved, onCancel) {
  const isNew = !p;
  const name = el('input', { type: 'text', maxlength: '160', placeholder: 'np. Sonoff ZBDongle-E', 'aria-label': 'Nazwa produktu', value: p ? p.name : '' });
  const ean = el('input', { type: 'text', maxlength: '20', inputmode: 'numeric', placeholder: 'opcjonalnie, 8-14 cyfr', 'aria-label': 'EAN', spellcheck: 'false', value: p ? p.ean || '' : '' });
  const cat = el('select', { 'aria-label': 'Kategoria' }, el('option', { value: '', text: 'Bez kategorii' }),
    ...S.cats.filter(c => !c.virtual).map(c => el('option', { value: c.key, text: c.name })));
  cat.value = p ? p.category || '' : '';
  const brand = el('input', { type: 'text', maxlength: '60', placeholder: 'puste = pierwsze słowo nazwy', 'aria-label': 'Marka', value: p ? p.brand || '' : '' });
  const keywords = el('input', { type: 'text', maxlength: '200', placeholder: 'np. żel pod prysznic, kosmetyki do mycia', 'aria-label': 'Słowa kluczowe', value: p ? p.keywords || '' : '' });
  const desc = el('textarea', { maxlength: '1500', placeholder: 'Wersja, model, czego nie mylić, ważne cechy', 'aria-label': 'Opis dla wyszukiwarki' });
  desc.value = p ? p.desc || '' : '';
  const sites = el('textarea', { placeholder: 'https://sklep.pl\nhttps://inny-sklep.pl', 'aria-label': 'Strony priorytetowe', spellcheck: 'false', autocapitalize: 'none' });
  sites.value = p ? (p.sites || []).join('\n') : '';
  const sw = switchEl(p ? p.active : true, 'Aktywny');
  const err = el('p', { class: 'form-err pr-err', hidden: true, role: 'alert' });
  const save = el('button', { class: 'btn sm primary', type: 'submit' }, isNew ? 'Dodaj produkt' : 'Zapisz');

  const form = el('form', { class: 'pr-form', novalidate: true },
    field('Nazwa', name, 'wide'),
    field('EAN', ean),
    field('Kategoria', cat),
    field('Marka (promocje na całą markę)', brand),
    field('Słowa kluczowe (promocje na kategorię, po przecinku)', keywords, 'wide'),
    field('Opis dla wyszukiwarki', desc, 'wide'),
    field('Strony priorytetowe (jeden adres w linii)', sites, 'wide'),
    el('div', { class: 'pr-f wide pr-formfoot' },
      el('div', { class: 'pr-active' }, sw.node, el('span', { class: 'pr-activet', text: 'Aktywny w wyszukiwaniu' })),
      el('div', { class: 'pr-actions' }, save, el('button', { class: 'btn sm ghost', type: 'button', onclick: onCancel }, 'Anuluj'))),
    err);
  form.addEventListener('submit', async e => {
    e.preventDefault();
    err.hidden = true;
    if (!name.value.trim()) { err.textContent = 'Wpisz nazwę produktu.'; err.hidden = false; name.focus(); return; }
    save.disabled = true;
    try {
      const body = { name: name.value, ean: ean.value, desc: desc.value, category: cat.value, brand: brand.value, keywords: keywords.value, sites: sites.value, active: sw.cb.checked };
      if (p) body.id = p.id;
      await api('prod_save', { method: 'POST', body });
      await onSaved();
    } catch (ex) { err.textContent = ex.message; err.hidden = false; save.disabled = false; }
  });
  setTimeout(() => name.focus(), 0);
  return form;
}

async function viewProducts() {
  view.replaceChildren(skel(3, 150));
  const data = await api('products');
  if (gone('produkty')) return;
  P.list = data.products || [];

  const sub = el('span', { class: 'sub' });
  const addWrap = el('div', { class: 'pr-addwrap' });
  const addBtn = el('button', { class: 'btn primary', type: 'button' }, icon('plus'), 'Dodaj produkt');
  const closeAdd = () => { addWrap.replaceChildren(); addBtn.disabled = false; };
  addBtn.addEventListener('click', () => {
    addBtn.disabled = true;
    addWrap.replaceChildren(el('div', { class: 'panel pr-item editing' },
      productForm(null, async () => { toast('Dodano produkt'); closeAdd(); await reload(); }, closeAdd)));
  });
  const head = el('div', { class: 'page-head' }, el('div', { class: 'pr-title' }, el('h1', { text: 'Moje produkty' }), sub), addBtn);

  const bar = el('div');
  const holder = el('div', { class: 'pr-list' });

  const paint = () => {
    const n = P.list.length;
    const act = P.list.filter(p => p.active).length;
    sub.textContent = n ? n + ' ' + plural(n, 'produkt', 'produkty', 'produktów') + ', aktywnych: ' + act : 'brak produktów';
    drawBar(); drawList();
  };
  const drawBar = () => {
    if (!P.list.length) { bar.replaceChildren(); return; }
    const counts = {};
    P.list.forEach(p => { counts[p.category || ''] = (counts[p.category || ''] || 0) + 1; });
    if (P.cat && !counts[P.cat]) P.cat = '';
    const catRow = el('div', { class: 'cats' });
    const mk = (key, label, c) => el('button', { class: 'chip' + (P.cat === key ? ' on' : ''), type: 'button', onclick: () => { P.cat = key; drawBar(); drawList(); } }, label, el('small', { text: String(c) }));
    const keys = Object.keys(counts).filter(k => k !== '').sort((a, b) => catName(a).localeCompare(catName(b), 'pl'));
    catRow.append(mk('', 'Wszystkie', P.list.length), ...keys.map(k => mk(k, catName(k), counts[k])));
    if (counts['']) catRow.append(mk('__none', 'Bez kategorii', counts['']));
    const input = el('input', { type: 'text', placeholder: 'Szukaj w produktach', 'aria-label': 'Szukaj w produktach', value: P.q });
    input.addEventListener('input', e => { P.q = e.target.value; drawList(); });
    bar.replaceChildren(el('div', { class: 'filters' }, el('div', { class: 'search' }, icon('search'), input), catRow));
  };
  const drawList = () => {
    if (!P.list.length) {
      holder.replaceChildren(el('div', { class: 'empty' },
        el('h2', { text: 'Nie masz jeszcze produktów' }),
        el('p', { text: 'Dodaj produkt, który chcesz śledzić: nazwę, opcjonalnie EAN i opis. Zadanie wyszukujące sprawdzi go w sklepach i porówna z gazetkami, a wynik pojawi się tutaj.' })));
      return;
    }
    const q = P.q.trim().toLowerCase();
    const rows = P.list.filter(p => (!P.cat || (P.cat === '__none' ? !p.category : p.category === P.cat)) &&
      (!q || (p.name + ' ' + (p.ean || '') + ' ' + (p.desc || '')).toLowerCase().includes(q)));
    if (!rows.length) {
      holder.replaceChildren(el('div', { class: 'empty' }, el('h2', { text: 'Nic nie pasuje do filtrów' }), el('p', { text: 'Zmień kategorię albo wyczyść wyszukiwanie.' })));
      return;
    }
    holder.replaceChildren(...rows.map(p => productItem(p, reload)));
  };
  async function reload() {
    const d = await api('products');
    P.list = d.products || [];
    paint();
  }

  view.replaceChildren(head, addWrap, bar, holder);
  paint();
}

/* =====================================================================
   Gazetki
   ===================================================================== */
const G = { shops: [], leaflets: [], promos: [], hitCount: {}, pname: {}, endedOpen: false, q: '', pshop: '', pmine: false, pmore: 1 };

function leafletCard(l, ctx) {
  const st = leafStatus(l);
  const cover = el('div', { class: 'lf-cover' });
  const ph = () => { cover.classList.add('noimg'); cover.replaceChildren(icon('newspaper')); };
  if (l.cover) {
    const img = el('img', { src: l.cover, alt: '', loading: 'lazy' });
    img.addEventListener('error', ph);
    cover.append(img);
  } else ph();

  const nItems = l.items_count || 0;
  const nHits = l.hits_count != null ? l.hits_count : (G.hitCount[l.id] || 0);
  const per = period(l.valid_from, l.valid_to);

  const head = el('button', { class: 'lf-head', type: 'button', 'aria-expanded': 'false' },
    cover,
    el('div', { class: 'lf-info' },
      el('div', { class: 'lf-meta' }, el('span', { class: 'lf-shop-n', text: l.shop || 'sklep nieznany' }), el('span', { class: 'lf-st ' + st.key, text: st.text })),
      el('div', { class: 'lf-title', text: l.title || 'Gazetka' }),
      el('div', { class: 'lf-per', text: per || 'bez daty ważności' }),
      el('div', { class: 'lf-counts' },
        el('span', { text: nItems + ' ' + plural(nItems, 'produkt', 'produkty', 'produktów') }),
        nHits ? el('span', { class: 'lf-hits-n', text: nHits + ' ' + plural(nHits, 'trafienie', 'trafienia', 'trafień') }) : null)),
    icon('chevron-down', 'lf-chev'));
  const body = el('div', { class: 'lf-body', hidden: true });
  const art = el('article', { class: 'panel lf-card' + (st.key === 'end' ? ' ended' : '') + (nHits ? ' hit' : '') }, head, body);

  let loaded = false;
  async function open(force) {
    const willOpen = force === true ? true : body.hidden;
    body.hidden = !willOpen;
    head.setAttribute('aria-expanded', String(willOpen));
    art.classList.toggle('open', willOpen);
    if (willOpen && !loaded) {
      body.replaceChildren(skel(1, 90));
      try {
        const d = await api('leaflet', { params: { id: l.id } });
        loaded = true;
        body.replaceChildren(leafletDetail(d));
      } catch (e) {
        body.replaceChildren(el('p', { class: 'form-err', text: e.message }));
      }
    }
  }
  head.addEventListener('click', () => open());
  ctx.cards[l.id] = { art, open: () => open(true) };
  return art;
}

function leafletDetail(d) {
  const l = d.leaflet;
  const hits = d.hits || [];
  const hitMap = new Map();
  hits.forEach(h => hitMap.set((h.item_name || '').trim().toLowerCase(), h));

  const top = el('div', { class: 'lf-dtop' },
    l.url ? extLink(l.url, 'Otwórz gazetkę', 'btn sm') : null,
    el('span', { class: 'sub', text: (l.items || []).length + ' ' + plural((l.items || []).length, 'pozycja', 'pozycje', 'pozycji') }));

  const items = l.items || [];
  if (!items.length) return el('div', { class: 'lf-dwrap' }, top, el('p', { class: 'hint', text: 'Nie odczytano jeszcze produktów z tej gazetki.' }));

  const rows = items.map(it => {
    const h = hitMap.get((it.name || '').trim().toLowerCase());
    const name = el('td', { class: 'n' }, el('span', { text: it.name }));
    if (h) name.append(el('span', { class: 'lf-hit-tag', text: G.pname[h.product_id] ? 'Twój produkt: ' + G.pname[h.product_id] : 'Twój produkt' }));
    if (it.applies_to) name.append(el('span', { class: 'lf-scope', text: (it.scope === 'category' ? 'kategoria: ' : 'marka: ') + it.applies_to }));
    if (it.deal && it.price) name.append(el('span', { class: 'lf-scope', text: it.deal }));
    if (it.notes) name.append(el('span', { class: 'lf-note', text: it.notes }));
    return el('tr', { class: h ? 'hit' : '' },
      name,
      el('td', { class: 'p', text: it.price || it.deal || '' }),
      el('td', { class: 'o', text: it.old_price || '' }),
      el('td', { class: 'd', text: period(it.valid_from, it.valid_to) }),
      el('td', { class: 'g', text: it.page ? 'str. ' + it.page : '' }));
  });
  rows.sort((a, b) => (b.classList.contains('hit') ? 1 : 0) - (a.classList.contains('hit') ? 1 : 0));
  const table = el('table', { class: 'lf-table' },
    el('thead', {}, el('tr', {}, el('th', { text: 'Produkt' }), el('th', { text: 'Cena' }), el('th', { text: 'Stara cena' }), el('th', { text: 'Okres' }), el('th', { text: 'Strona' }))),
    el('tbody', {}, ...rows));
  return el('div', { class: 'lf-dwrap' }, top, el('div', { class: 'lf-tablewrap' }, table));
}

function shopRow(s, reload) {
  const row = el('div', { class: 'lf-shoprow' });
  const show = () => {
    row.className = 'lf-shoprow' + (s.active ? '' : ' is-off');
    const sw = switchEl(s.active, 'Aktywny');
    sw.cb.addEventListener('change', async () => {
      try { await api('shop_toggle', { method: 'POST', body: { id: s.id, active: sw.cb.checked } }); s.active = sw.cb.checked; row.classList.toggle('is-off', !s.active); }
      catch (e) { sw.cb.checked = !sw.cb.checked; toast(e.message, true); }
    });
    row.replaceChildren(
      sw.node,
      el('div', { class: 'lf-shopmain' },
        el('div', { class: 'lf-shopname' }, el('b', { text: s.name }),
          el('a', { class: 'lf-shoplink', href: safeHref(s.url), target: '_blank', rel: 'noopener noreferrer', title: s.url }, icon('link'), host(s.url))),
        s.note ? el('div', { class: 'note', text: s.note }) : null),
      el('button', { class: 'icon-btn', type: 'button', 'aria-label': 'Edytuj sklep', title: 'Edytuj', onclick: edit }, icon('edit')),
      armIcon('Usuń sklep', async () => { await api('shop_delete', { method: 'POST', body: { id: s.id } }); toast('Sklep usunięty'); await reload(); }));
  };
  const edit = () => {
    row.className = 'lf-shoprow editing';
    row.replaceChildren(shopForm(s, async () => { toast('Zapisano sklep'); await reload(); }, show));
  };
  show();
  return row;
}

function shopForm(s, onSaved, onCancel) {
  const name = el('input', { type: 'text', maxlength: '60', placeholder: 'np. Biedronka', 'aria-label': 'Nazwa sklepu', value: s ? s.name : '' });
  const url = el('input', { type: 'url', placeholder: 'https://sklep.pl/gazetki', 'aria-label': 'Adres strony z gazetkami', spellcheck: 'false', autocapitalize: 'none', value: s ? s.url : '' });
  const note = el('input', { type: 'text', maxlength: '300', placeholder: 'Notatka, np. adres sklepu (opcjonalnie)', 'aria-label': 'Notatka', value: s ? s.note || '' : '' });
  const sw = switchEl(s ? s.active : true, 'Aktywny');
  const err = el('p', { class: 'form-err pr-err', hidden: true, role: 'alert' });
  const save = el('button', { class: 'btn sm primary', type: 'submit' }, s ? 'Zapisz' : 'Dodaj sklep');
  const form = el('form', { class: 'lf-form', novalidate: true },
    field('Nazwa', name), field('Adres strony z gazetkami', url, 'wide'), field('Notatka', note, 'wide'),
    el('div', { class: 'pr-f wide pr-formfoot' },
      el('div', { class: 'pr-active' }, sw.node, el('span', { class: 'pr-activet', text: 'Aktywny' })),
      el('div', { class: 'pr-actions' }, save, el('button', { class: 'btn sm ghost', type: 'button', onclick: onCancel }, 'Anuluj'))),
    err);
  form.addEventListener('submit', async e => {
    e.preventDefault();
    err.hidden = true;
    if (!name.value.trim()) { err.textContent = 'Wpisz nazwę sklepu.'; err.hidden = false; return; }
    if (!url.value.trim()) { err.textContent = 'Wpisz adres strony z gazetkami.'; err.hidden = false; return; }
    save.disabled = true;
    try {
      const body = { name: name.value, url: url.value, note: note.value, active: sw.cb.checked };
      if (s) body.id = s.id;
      await api('shop_save', { method: 'POST', body });
      await onSaved();
    } catch (ex) { err.textContent = ex.message; err.hidden = false; save.disabled = false; }
  });
  setTimeout(() => name.focus(), 0);
  return form;
}

async function fetchGazetki() {
  const [sh, pr, pm] = await Promise.all([api('shops'), api('products').catch(() => ({ products: [] })), api('promos').catch(() => ({ promos: [] }))]);
  G.promos = pm.promos || [];
  G.shops = sh.shops || [];
  G.leaflets = sh.leaflets || [];
  G.hitCount = {}; G.pname = {};
  (pr.products || []).forEach(p => {
    G.pname[p.id] = p.name;
    (p.hits || []).forEach(h => { if (h.leaflet_id) G.hitCount[h.leaflet_id] = (G.hitCount[h.leaflet_id] || 0) + 1; });
  });
}

async function viewLeaflets() {
  view.replaceChildren(skel(2, 150));
  await fetchGazetki();
  if (gone('gazetki')) return;

  const ctx = { cards: {} };

  /* --- sklepy --- */
  const shopsSub = el('span', { class: 'sub' });
  const shopsList = el('div', { class: 'lf-shops' });
  const shopAdd = el('div', { class: 'lf-shopadd' });
  const shopsPanel = el('div', { class: 'panel' }, shopAdd, shopsList);
  const addShopBtn = el('button', { class: 'btn sm', type: 'button' }, icon('plus'), 'Dodaj sklep');
  const closeShopAdd = () => { shopAdd.replaceChildren(); addShopBtn.disabled = false; };
  addShopBtn.addEventListener('click', () => {
    addShopBtn.disabled = true;
    shopAdd.replaceChildren(shopForm(null, async () => { toast('Dodano sklep'); closeShopAdd(); await reload(); }, closeShopAdd));
  });
  const drawShops = () => {
    const n = G.shops.length, act = G.shops.filter(s => s.active).length;
    shopsSub.textContent = n ? 'aktywnych ' + act + ' z ' + n : 'brak';
    shopsList.replaceChildren(...(n ? G.shops.map(s => shopRow(s, reload))
      : [el('div', { class: 'empty', style: 'border:0' }, el('h2', { text: 'Brak sklepów' }), el('p', { text: 'Dodaj sklep z gazetkami, a zadanie wyszukujące będzie czytać jego aktualne gazetki.' }))]));
  };

  /* --- gazetki --- */
  const leafSub = el('span', { class: 'sub' });
  const results = el('div', { class: 'lf-results', hidden: true });
  const grid = el('div', { class: 'lf-grid' });
  const endedWrap = el('div', { class: 'lf-endedwrap' });
  const drawLeaflets = () => {
    ctx.cards = {};
    const cur = G.leaflets.filter(l => l.current);
    const old = G.leaflets.filter(l => !l.current);
    leafSub.textContent = cur.length ? cur.length + ' ' + plural(cur.length, 'gazetka', 'gazetki', 'gazetek') : 'brak';
    const byDate = (a, b) => (a.valid_from || '').localeCompare(b.valid_from || '') || (a.shop || '').localeCompare(b.shop || '', 'pl');
    cur.sort(byDate); old.sort((a, b) => byDate(b, a));
    grid.replaceChildren(...(cur.length ? cur.map(l => leafletCard(l, ctx))
      : [el('div', { class: 'empty lf-empty' }, el('h2', { text: 'Brak aktualnych gazetek' }), el('p', { text: 'Gazetki pojawią się po najbliższym przebiegu zadania wyszukującego, o ile masz dodane aktywne sklepy.' }))]));
    if (!old.length) { endedWrap.replaceChildren(); return; }
    const gridOld = el('div', { class: 'lf-grid', hidden: !G.endedOpen }, ...old.map(l => leafletCard(l, ctx)));
    const t = el('button', { class: 'pr-toggle', type: 'button', 'aria-expanded': String(G.endedOpen) }, icon('chevron-down'), el('span', { text: 'Zakończone (' + old.length + ')' }));
    t.addEventListener('click', () => { G.endedOpen = gridOld.hidden; gridOld.hidden = !G.endedOpen; t.setAttribute('aria-expanded', String(G.endedOpen)); });
    endedWrap.replaceChildren(t, gridOld);
  };

  /* --- promocje ze wszystkich aktualnych gazetek --- */
  const PAGE = 60;
  const promoSub = el('span', { class: 'sub' });
  const promoList = el('div', { class: 'pl-list' });
  const promoMore = el('div', { class: 'pl-more' });
  const input = el('input', { type: 'text', placeholder: 'Szukaj w promocjach (np. Dove, żel, lakier)', 'aria-label': 'Szukaj w promocjach', value: G.q, autocomplete: 'off' });
  const shopSel = el('select', { 'aria-label': 'Sklep' });
  const mineSw = switchEl(G.pmine, 'Tylko moje produkty');
  const fold = t => String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ł/g, 'l');
  const discTag = d => {
    if (!d) return el('span', { class: 'pl-d none', text: '–', title: 'Brak danych do oceny rabatu' });
    const key = d.rating === 'bardzo dobra' ? 'top' : d.rating === 'dobra' ? 'good' : d.rating === 'średnia' ? 'mid' : 'low';
    return el('span', { class: 'pl-d ' + key, text: (d.upto ? 'do ' : '~') + d.pct + '%', title: 'Ocena: ' + d.rating + ' (' + d.basis + (d.upto ? ', maksymalnie' : '') + ')' });
  };
  const promoRow = r => {
    const main = el('div', { class: 'pl-main' }, el('b', { text: r.name }));
    if (r.scope === 'brand' || r.scope === 'category') main.append(el('span', { class: 'pr-tag', text: (r.scope === 'category' ? 'kategoria: ' : 'cała marka: ') + (r.applies_to || '') }));
    (r.mine || []).forEach(m => main.append(el('span', { class: 'lf-hit-tag', text: 'Twój produkt: ' + (m.name || '') })));
    const per = period(r.valid_from, r.valid_to);
    main.append(el('small', { text: (r.shop || 'sklep') + (per ? ' · ' + per : '') + (r.notes ? ' · ' + r.notes : '') }));
    const price = el('div', { class: 'pl-price' });
    if (r.deal) price.append(el('b', { text: r.deal }));
    if (r.price) price.append(el('span', { text: r.price }));
    if (r.old_price) price.append(el('span', { class: 'orig', text: r.old_price }));
    return el('div', { class: 'pl-row' + ((r.mine || []).length ? ' mine' : '') }, discTag(r.discount), main, price);
  };
  const drawPromos = () => {
    const q = fold(input.value).trim();
    const rows = G.promos.filter(r => (!G.pshop || r.shop === G.pshop) && (!G.pmine || (r.mine || []).length)
      && (q.length < 2 || fold((r.name || '') + ' ' + (r.applies_to || '') + ' ' + (r.deal || '') + ' ' + (r.shop || '')).includes(q)));
    promoSub.textContent = G.promos.length ? rows.length + ' z ' + G.promos.length : 'brak';
    if (!rows.length) {
      promoList.replaceChildren(el('div', { class: 'empty lf-empty' }, el('h2', { text: G.promos.length ? 'Nic nie pasuje' : 'Brak zapisanych promocji' }),
        el('p', { text: G.promos.length ? 'Zmień filtry albo szukaną frazę.' : 'Promocje pojawią się po przebiegu zadania, które odczyta aktualne gazetki.' })));
      promoMore.replaceChildren();
      return;
    }
    const n = Math.min(rows.length, PAGE * G.pmore);
    promoList.replaceChildren(...rows.slice(0, n).map(promoRow));
    if (n < rows.length) {
      const b = el('button', { class: 'btn sm', type: 'button', text: 'Pokaż więcej (' + (rows.length - n) + ')' });
      b.addEventListener('click', () => { G.pmore++; drawPromos(); });
      promoMore.replaceChildren(b);
    } else promoMore.replaceChildren();
  };
  const fillShopSel = () => {
    const names = [...new Set(G.promos.map(r => r.shop).filter(Boolean))].sort((x, y) => x.localeCompare(y, 'pl'));
    shopSel.replaceChildren(el('option', { value: '', text: 'Wszystkie sklepy' }), ...names.map(n => el('option', { value: n, text: n })));
    shopSel.value = names.includes(G.pshop) ? G.pshop : '';
    G.pshop = shopSel.value;
  };
  let timer = null;
  input.addEventListener('input', () => { G.q = input.value; G.pmore = 1; clearTimeout(timer); timer = setTimeout(drawPromos, 150); });
  shopSel.addEventListener('change', () => { G.pshop = shopSel.value; G.pmore = 1; drawPromos(); });
  mineSw.cb.addEventListener('change', () => { G.pmine = mineSw.cb.checked; G.pmore = 1; drawPromos(); });
  const promoTools = el('div', { class: 'pl-tools' }, el('div', { class: 'search lf-search' }, icon('search'), input), shopSel,
    el('label', { class: 'pl-mine' }, mineSw.node, el('span', { text: 'Tylko moje produkty' })));

  async function reload() {
    await fetchGazetki();
    fillShopSel(); drawPromos(); drawShops(); drawLeaflets();
  }

  const head = el('div', { class: 'page-head' }, el('h1', { text: 'Gazetki' }));
  view.replaceChildren(head,
    el('div', { class: 'section-title' }, el('h2', { text: 'Promocje w gazetkach' }), promoSub),
    el('p', { class: 'hint', text: 'Wszystko, co zadanie odczytało z aktualnych gazetek, od najlepszego rabatu. „Drugi za 1 zł” i „1+1 gratis” liczone są jak 50%, „2+1” jak 33%. Twoje produkty są na górze.' }),
    promoTools, promoList, promoMore,
    el('div', { class: 'section-title' }, el('h2', { text: 'Aktualne gazetki' }), leafSub),
    grid, endedWrap,
    el('div', { class: 'section-title' }, el('h2', { text: 'Sklepy' }), el('div', { class: 'pr-tools' }, shopsSub, addShopBtn)),
    el('p', { class: 'hint', text: 'Strony sklepów, z których zadanie wyszukujące czyta aktualne gazetki. Wyłączony sklep zostaje na liście, ale jego gazetki nie są sprawdzane.' }),
    shopsPanel);
  fillShopSel(); drawPromos(); drawShops(); drawLeaflets();
}

/* ---------- rejestracja tras ---------- */
O.routes.produkty = viewProducts;
O.routes.gazetki = viewLeaflets;
if (/^#\/?(produkty|gazetki)/.test(location.hash)) O.route();
})();
