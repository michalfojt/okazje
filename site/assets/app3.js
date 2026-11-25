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
/* wybór sklepu i sortowania pamiętany w przeglądarce (bez pamięci też działa) */
function load(k, d) { try { return localStorage.getItem('okz-' + k) || d; } catch (e) { return d; } }
function save(k, v) { try { localStorage.setItem('okz-' + k, v); } catch (e) { /* brak storage */ } }

const SORTS = [['best', 'Atrakcyjność: od najlepszych'], ['worst', 'Atrakcyjność: od najsłabszych'], ['disc', 'Rabat: od największego'], ['price', 'Cena: od najniższej'], ['name', 'Nazwa: A-Z']];
/* serwer zwraca listę w kolejności "best"; sortowanie jest stabilne, więc przy remisie ta kolejność zostaje */
function sortPromos(rows, mode) {
  if (mode === 'best') return rows;
  const num = (v, d) => (v == null ? d : v);
  const cmp = {
    worst: (a, b) => (a.score.unsure - b.score.unsure) || (a.score.v - b.score.v) || (num(a.score.pct, 0) - num(b.score.pct, 0)),
    disc: (a, b) => num(b.score.pct, -1) - num(a.score.pct, -1),
    price: (a, b) => num(a.score.eff, Infinity) - num(b.score.eff, Infinity) || 0,
    name: (a, b) => (a.name || '').localeCompare(b.name || '', 'pl'),
  }[mode];
  return cmp ? rows.slice().sort(cmp) : rows;
}

const P = { list: [], q: '', cat: '', min: Number(load('pmin', 0)) || 0, open: new Set() };
const SCORE_MINS = [5, 6, 7, 8];
if (!SCORE_MINS.includes(P.min)) P.min = 0;
/* najwyższa ocena wśród aktualnych trafień produktu w gazetkach; 0 = brak ocenionego trafienia */
const bestScore = p => Math.max(0, ...(p.hits || []).map(h => (h.score ? h.score.v : 0)));

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

/* ---------- ocena atrakcyjności 1-10 ---------- */

/**
 * Plakietka z oceną i rozwijane uzasadnienie. `refresh` wywoływane po rozłączeniu lub przywróceniu porównania.
 * @return {{badge: Node, box: Node}}
 */
function scoreView(s, refresh, isOpen, onToggle) {
  if (!s) return { badge: el('span', { class: 'sc sc-none', text: '–' }), box: null };
  const key = s.v >= 9 ? 'sc-top' : s.v >= 7 ? 'sc-good' : s.v >= 5 ? 'sc-mid' : 'sc-low';
  const box = el('div', { class: 'sc-box', hidden: true });
  const badge = el('button', { class: 'sc ' + (s.unsure ? 'sc-none' : key), type: 'button', 'aria-expanded': 'false',
    'aria-label': 'Atrakcyjność ' + s.v + ' na 10' + (s.unsure ? ', niepewna' : '') + '. Pokaż uzasadnienie', title: s.why.join('\n') },
  el('b', { text: s.v + (s.unsure ? '?' : '') }), el('small', { text: '/10' }));
  const fill = () => {
    const parts = [el('ul', {}, ...s.why.map(w => el('li', { text: w })))];
    if (s.cmp && s.cmp.length) {
      parts.push(el('div', { class: 'sc-cmp-h', text: 'Porównane z' }));
      s.cmp.forEach(c => {
        const b = el('button', { class: 'btn sm ghost', type: 'button', text: c.excluded ? 'Przywróć' : 'To inny produkt' });
        b.addEventListener('click', async () => {
          b.disabled = true;
          try { await api('hist_split', { method: 'POST', body: { pair: c.pair, split: !c.excluded } }); await refresh(); }
          catch (e) { b.disabled = false; toast(e.message, true); }
        });
        parts.push(el('div', { class: 'sc-cmp' + (c.excluded ? ' sc-excl' : '') },
          el('span', { class: 'sc-cmp-n', text: c.name }),
          el('span', { class: 'sc-cmp-m', text: [c.shop || 'sklep', period(c.from, c.to), c.value, c.same_size ? '' : 'inna gramatura'].filter(Boolean).join(' · ') }),
          b));
      });
    }
    box.replaceChildren(...parts);
  };
  const set = open => {
    if (open) fill();
    box.hidden = !open;
    badge.setAttribute('aria-expanded', String(open));
  };
  badge.addEventListener('click', () => { set(box.hidden); if (onToggle) onToggle(!box.hidden); });
  if (isOpen) set(true);
  return { badge, box };
}
const turboTag = s => (s && s.turbo ? el('span', { class: 'sc-tag turbo', text: 'turbo okazja' })
  : s && s.fake ? el('span', { class: 'sc-tag fake', text: 'pozorna okazja' }) : null);

/** Znacznik typu trafienia: cała marka albo kategoria (zwykła pozycja bez znacznika). */
function matchTag(m) {
  if (m === 'brand') return el('span', { class: 'pr-tag', text: 'cała marka', title: 'Promocja na wszystkie produkty tej marki: sprawdź, czy obejmuje Twój wariant' });
  if (m === 'keyword') return el('span', { class: 'pr-tag', text: 'kategoria', title: 'Promocja na kategorię pasującą do słów kluczowych produktu' });
  return null;
}

/* logotypy sklepów (data: URI, bo deploy.php przenosi tylko php, js i css) */
const SHOP_LOGOS = {
  biedronka: { w: 89, src: 'data:image/png;base64,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' },
  lidl: { w: 40, src: 'data:image/png;base64,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' },
  carrefour: { w: 50, src: 'data:image/png;base64,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' },
  kaufland: { w: 40, src: 'data:image/png;base64,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' },
};

/** Logo sklepu zamiast nazwy; dla sklepu bez logo zostaje nazwa. */
function shopLogo(shop) {
  const key = String(shop || '').trim().toLowerCase().split(/[^a-z]+/)[0];
  if (!Object.prototype.hasOwnProperty.call(SHOP_LOGOS, key)) return el('span', { class: 'pr-hit-shop', text: shop || 'gazetka' });
  const l = SHOP_LOGOS[key];
  return el('img', { class: 'pr-hit-logo', src: l.src, alt: shop, title: shop, width: String(l.w), height: '40' });
}

function hitRow(h) {
  const per = period(h.valid_from, h.valid_to);
  const sc = scoreView(h.score, async () => { O.route(); });
  return el('div', { class: 'pr-hit' },
    el('div', { class: 'pr-hit-main' },
      sc.badge,
      turboTag(h.score),
      shopLogo(h.shop),
      el('span', { class: 'pr-hit-name', text: h.item_name }),
      matchTag(h.match)),
    el('div', { class: 'pr-hit-meta' },
      h.deal ? el('span', { class: 'pr-hit-deal', text: h.deal }) : null,
      h.price || !h.deal ? el('span', { class: 'price', text: h.price || 'brak ceny' }) : null,
      h.old_price ? el('span', { class: 'orig', text: h.old_price }) : null,
      per ? el('span', { class: 'pr-hit-per', text: per }) : null,
      h.page ? el('span', { class: 'pr-hit-per', text: 'str. ' + h.page }) : null,
      h.leaflet_url ? extLink(h.leaflet_url, 'Gazetka', 'open-link') : null),
    h.notes ? el('div', { class: 'pr-hit-notes', text: h.notes }) : null,
    sc.box);
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
    if (p.receipt) parts.splice(1, 0, el('div', { class: 'pr-receipt', text: receiptInfo(p.receipt) }));

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

function receiptInfo(r) {
  const n = (r.bought || []).length;
  const price = r.price ? (r.price / 100).toFixed(2).replace('.', ',') + ' zł' + (r.unit === 'kg' ? '/kg' : '') : '';
  const d = /^(\d{4})-(\d\d)-(\d\d)$/.exec(r.last || '');
  return ['Z paragonu: ' + r.raw, n + ' ' + plural(n, 'zakup', 'zakupy', 'zakupów'),
    d ? 'ostatnio ' + d[3] + '.' + d[2] + '.' + d[1] : '', price ? 'cena ' + price : ''].filter(Boolean).join(' · ');
}

/* odczyt zdjęcia paragonu (OCR w przeglądarce); pliki silnika podaje ocr.php z tego samego serwera */
let ocrLib = null;
function loadOcr() {
  if (!ocrLib) ocrLib = new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'ocr.php?f=tesseract.min.js';
    s.onload = () => resolve(window.Tesseract);
    s.onerror = () => { ocrLib = null; s.remove(); reject(new Error('Nie udało się pobrać modułu do odczytu zdjęć. Spróbuj ponownie.')); };
    document.head.append(s);
  });
  return ocrLib;
}
async function ocrWorker(onProgress) {
  const T = await loadOcr();
  const abs = f => new URL('ocr.php?f=' + f, location.href).href;
  /* moduł WebAssembly z jedną instrukcją SIMD: starsze przeglądarki dostają wolniejszy wariant */
  const simd = WebAssembly.validate(new Uint8Array([0, 97, 115, 109, 1, 0, 0, 0, 1, 5, 1, 96, 0, 1, 123, 3, 2, 1, 0, 10, 10, 1, 8, 0, 65, 0, 253, 15, 253, 98, 11]));
  let fail;
  const failed = new Promise((_, reject) => { fail = () => reject(new Error('Nie udało się uruchomić odczytu zdjęć. Spróbuj ponownie.')); });
  const w = await Promise.race([failed, T.createWorker('pol', 1, {
    workerPath: abs('worker.min.js'), workerBlobURL: false, corePath: abs(simd ? 'core-simd.wasm.js' : 'core-basic.wasm.js'),
    langPath: abs('lang'), logger: m => { if (m.status === 'recognizing text') onProgress(m.progress); }, errorHandler: fail,
  })]);
  await w.setParameters({ tessedit_pageseg_mode: '6' });
  return w;
}
/* czarno-biała kopia o szerokości ok. 1200 px: małe zrzuty ekranu bez powiększenia czytają się źle */
async function ocrImage(file) {
  /* createImageBitmap, bo zasady CSP strony nie pozwalają na <img> z adresem blob: */
  const img = await createImageBitmap(file);
  try {
    const w = img.width, h = img.height;
    /* limit powierzchni płótna w przeglądarkach mobilnych to ok. 16 mln pikseli */
    const k = Math.min(w < 1000 ? 1250 / w : 1, Math.sqrt(16e6 / (w * h)));
    const c = document.createElement('canvas');
    c.width = Math.floor(w * k); c.height = Math.floor(h * k);
    const g = c.getContext('2d');
    g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
    g.imageSmoothingQuality = 'high';
    g.filter = 'grayscale(1)';
    g.drawImage(img, 0, 0, c.width, c.height);
    return { canvas: c, low: w < 600 };
  } finally { img.close(); }
}
const isImage = f => /^image\//.test(f.type) || /\.(png|jpe?g|webp)$/i.test(f.name);

/* import paragonów: z JSON wysyłamy tylko pozycje i stopkę fiskalną, ze zdjęcia tylko tekst od daty
   do numeru paragonu oraz początek z nazwą sklepu (bez płatności i kodu kreskowego) */
function trimReceiptText(t) {
  const lines = t.split('\n');
  const end = lines.findIndex(l => /^\s*(p[łl]atno|aby zwr)/i.test(l));
  return (end > 0 ? lines.slice(0, end) : lines).join('\n').slice(0, 30000);
}
async function readReceipts(files, status) {
  const out = [], bad = [];
  const images = files.filter(isImage);
  if (images.length) {
    status('Przygotowuję odczyt zdjęć (za pierwszym razem pobiera ok. 7 MB)…');
    let i = 0;
    const note = p => status('Czytam zdjęcie ' + (i + 1) + ' z ' + images.length + '… ' + Math.round(p * 100) + '%');
    const worker = await ocrWorker(note);
    try {
      for (; i < images.length; i++) {
        note(0);
        try {
          const { canvas, low } = await ocrImage(images[i]);
          const { data } = await worker.recognize(canvas);
          out.push({ text: trimReceiptText(data.text), low });
        } catch (e) { bad.push(images[i].name); }
      }
    } finally { await worker.terminate(); }
  }
  for (const f of files.filter(f => !isImage(f))) {
    try {
      if (f.size > 2 * 1024 * 1024) throw new Error('za duży');
      const j = JSON.parse(await f.text());
      if (!j || !Array.isArray(j.body)) throw new Error('brak pozycji');
      const body = j.body.filter(r => r && (r.sellLine || r.fiscalFooter)).map(r => (r.sellLine ? { sellLine: r.sellLine }
        : { fiscalFooter: { uniqueNumber: r.fiscalFooter.uniqueNumber, billNumber: r.fiscalFooter.billNumber, date: r.fiscalFooter.date } }));
      out.push({ body });
    } catch (e) { bad.push(f.name); }
  }
  return { out, bad };
}

function receiptImport(box, reload) {
  const input = el('input', { type: 'file', accept: '.json,application/json,image/png,image/jpeg,image/webp', multiple: true, hidden: true, 'aria-label': 'Pliki paragonów' });
  const btn = el('button', { class: 'btn', type: 'button', onclick: () => input.click() }, icon('plus'), 'Wgraj paragony');
  const send = async (receipts, bad, restore) => {
    const d = await api('receipt_import', { method: 'POST', body: { receipts, restore } });
    const r = d.result;
    const unread = bad.length + r.bad;
    const lines = ['Paragony: ' + r.receipts + '. Dodane produkty: ' + r.added + ', zaktualizowane: ' + r.updated + '.' +
      (!r.added && !r.updated && !r.ignored ? ' Nic nowego.' : '')];
    if (r.oneoff && r.oneoff.length) lines.push('Pominięte jako zakupy jednorazowe: ' + r.oneoff.join(', ') + '.');
    if (r.over_limit) lines.push('Pominięte z powodu limitu produktów: ' + r.over_limit + '.');
    if (unread) lines.push('Nieodczytane pliki: ' + unread + (bad.length ? ' (' + bad.join(', ') + ')' : '') + '.');
    const info = el('div', { class: 'pr-import', role: 'status' }, ...lines.map(t => el('p', { text: t })));
    if (r.ignored) {
      const again = el('button', { class: 'btn sm', type: 'button' }, 'Przywróć usunięte');
      again.addEventListener('click', async () => {
        again.disabled = true;
        try { await send(receipts, bad, true); } catch (e) { again.disabled = false; toast(e.message, true); }
      });
      info.append(el('p', { text: 'Pominięte, bo były wcześniej usunięte: ' + r.ignored + '.' }), again);
    }
    info.append(el('button', { class: 'btn sm ghost', type: 'button', onclick: () => box.replaceChildren() }, 'Zamknij'));
    box.replaceChildren(info);
    await reload();
  };
  input.addEventListener('change', async () => {
    const files = Array.from(input.files || []);
    input.value = '';
    if (!files.length) return;
    btn.disabled = true;
    try {
      if (files.length > 30) throw new Error('Maksymalnie 30 plików naraz.');
      const progress = el('p');
      const status = t => { progress.textContent = t; if (!progress.isConnected) box.replaceChildren(el('div', { class: 'pr-import', role: 'status' }, progress)); };
      const { out, bad } = await readReceipts(files, status);
      if (!out.length) throw new Error('Nie udało się odczytać paragonu: ' + bad.join(', '));
      await send(out, bad, false);
    } catch (e) { box.replaceChildren(); toast(e.message, true); }
    btn.disabled = false;
  });
  return [btn, input];
}

/* formularz dodawania / edycji produktu */
function productForm(p, onSaved, onCancel) {
  const isNew = !p;
  const name = el('input', { type: 'text', maxlength: '160', placeholder: 'np. Sonoff ZBDongle-E', 'aria-label': 'Nazwa produktu', value: p ? p.name : '' });
  const ean = el('input', { type: 'text', maxlength: '20', inputmode: 'numeric', placeholder: 'opcjonalnie, 8-14 cyfr', 'aria-label': 'EAN', spellcheck: 'false', value: p ? p.ean || '' : '' });
  const cat = el('select', { 'aria-label': 'Kategoria' }, el('option', { value: '', text: 'Bez kategorii' }),
    ...S.cats.filter(c => !c.virtual).map(c => el('option', { value: c.key, text: c.name })));
  cat.value = p ? p.category || '' : '';
  const brand = el('input', { type: 'text', maxlength: '60', placeholder: p && p.receipt ? 'puste = bez marki' : 'puste = pierwsze słowo nazwy', 'aria-label': 'Marka', value: p ? p.brand || '' : '' });
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
  const importBox = el('div', { class: 'pr-addwrap' });
  const head = el('div', { class: 'page-head' }, el('div', { class: 'pr-title' }, el('h1', { text: 'Moje produkty' }), sub),
    el('div', { class: 'pr-tools' }, ...receiptImport(importBox, () => reload()), addBtn));

  const bar = el('div');
  const scoreBar = el('div', { class: 'cats pr-scores', role: 'group', 'aria-label': 'Filtr po ocenie atrakcyjności' });
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
    const fromReceipts = P.list.filter(p => p.receipt).length;
    if (P.cat && (P.cat === '__receipt' ? !fromReceipts : !counts[P.cat === '__none' ? '' : P.cat])) P.cat = '';
    const catRow = el('div', { class: 'cats' });
    const mk = (key, label, c) => el('button', { class: 'chip' + (P.cat === key ? ' on' : ''), type: 'button', onclick: () => { P.cat = key; drawBar(); drawList(); } }, label, el('small', { text: String(c) }));
    const keys = Object.keys(counts).filter(k => k !== '').sort((a, b) => catName(a).localeCompare(catName(b), 'pl'));
    catRow.append(mk('', 'Wszystkie', P.list.length), ...keys.map(k => mk(k, catName(k), counts[k])));
    if (counts['']) catRow.append(mk('__none', 'Bez kategorii', counts['']));
    if (fromReceipts) catRow.append(mk('__receipt', 'Z paragonów', fromReceipts));
    const input = el('input', { type: 'text', placeholder: 'Szukaj w produktach', 'aria-label': 'Szukaj w produktach', value: P.q });
    input.addEventListener('input', e => { P.q = e.target.value; drawList(); });
    bar.replaceChildren(el('div', { class: 'filters' }, el('div', { class: 'search' }, icon('search'), input), catRow, scoreBar));
  };
  const drawList = () => {
    if (!P.list.length) {
      holder.replaceChildren(el('div', { class: 'empty' },
        el('h2', { text: 'Nie masz jeszcze produktów' }),
        el('p', { text: 'Dodaj produkt, który chcesz śledzić (nazwę, opcjonalnie EAN i opis), albo wgraj cyfrowe paragony: z Biedronki (JSON) lub z Lidla (zdjęcia). Zadanie wyszukujące sprawdzi go w sklepach i porówna z gazetkami, a wynik pojawi się tutaj.' })));
      return;
    }
    const q = P.q.trim().toLowerCase();
    const inCat = p => !P.cat || (P.cat === '__receipt' ? !!p.receipt : P.cat === '__none' ? !p.category : p.category === P.cat);
    const found = P.list.filter(p => inCat(p) && (!q || (p.name + ' ' + (p.ean || '') + ' ' + (p.desc || '')).toLowerCase().includes(q)));
    drawScores(found);
    const rows = P.min ? found.filter(p => bestScore(p) >= P.min) : found;
    if (!rows.length) {
      holder.replaceChildren(el('div', { class: 'empty' }, el('h2', { text: 'Nic nie pasuje do filtrów' }),
        el('p', { text: P.min ? 'Żaden produkt nie ma teraz w gazetkach oceny ' + P.min + ' lub wyższej. Wybierz niższy próg albo „Każda”.' : 'Zmień kategorię albo wyczyść wyszukiwanie.' })));
      return;
    }
    holder.replaceChildren(...rows.map(p => productItem(p, reload)));
  };
  /* liczby przy progach uwzględniają wybraną kategorię i wyszukiwanie */
  function drawScores(found) {
    const mk = (min, label) => el('button', { class: 'chip' + (P.min === min ? ' on' : ''), type: 'button', 'data-min': String(min), 'aria-pressed': String(P.min === min),
      onclick: () => { P.min = min; save('pmin', min); drawList(); scoreBar.querySelector('[data-min="' + min + '"]').focus(); } },
    label, el('small', { text: String(min ? found.filter(p => bestScore(p) >= min).length : found.length) }));
    scoreBar.replaceChildren(el('span', { class: 'pr-scores-h', text: 'Ocena:' }), mk(0, 'Każda'), ...SCORE_MINS.map(n => mk(n, n + ' i więcej')));
  }
  async function reload() {
    const d = await api('products');
    P.list = d.products || [];
    paint();
  }

  view.replaceChildren(head, importBox, addWrap, bar, holder);
  paint();
}

/* =====================================================================
   Gazetki
   ===================================================================== */
const G = { shops: [], leaflets: [], promos: [], hitCount: {}, pname: {}, endedOpen: false, q: '', pshop: load('pshop', ''), psort: load('psort', 'best'), pmine: false, pturbo: false, pmore: 1, open: new Set() };

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
    const sc = scoreView(it.score, async () => { O.route(); });
    const name = el('td', { class: 'n' }, el('span', { text: it.name }));
    const tag = turboTag(it.score);
    if (tag) name.append(tag);
    if (h) name.append(el('span', { class: 'lf-hit-tag', text: G.pname[h.product_id] ? 'Twój produkt: ' + G.pname[h.product_id] : 'Twój produkt' }));
    if (it.applies_to) name.append(el('span', { class: 'lf-scope', text: (it.scope === 'category' ? 'kategoria: ' : 'marka: ') + it.applies_to }));
    if (it.deal && it.price) name.append(el('span', { class: 'lf-scope', text: it.deal }));
    if (it.notes) name.append(el('span', { class: 'lf-note', text: it.notes }));
    if (sc.box) name.append(sc.box);
    return el('tr', { class: h ? 'hit' : '' },
      el('td', { class: 's' }, sc.badge),
      name,
      el('td', { class: 'p', text: it.price || it.deal || '' }),
      el('td', { class: 'o', text: it.old_price || '' }),
      el('td', { class: 'd', text: period(it.valid_from, it.valid_to) }),
      el('td', { class: 'g', text: it.page ? 'str. ' + it.page : '' }));
  });
  const val = i => (items[i].score ? items[i].score.v - (items[i].score.unsure ? 0.5 : 0) : 0);
  const order = rows.map((r, i) => i).sort((a, b) => (rows[b].classList.contains('hit') ? 1 : 0) - (rows[a].classList.contains('hit') ? 1 : 0) || val(b) - val(a));
  const table = el('table', { class: 'lf-table' },
    el('thead', {}, el('tr', {}, el('th', { text: 'Ocena' }), el('th', { text: 'Produkt' }), el('th', { text: 'Cena' }), el('th', { text: 'Stara cena' }), el('th', { text: 'Okres' }), el('th', { text: 'Strona' }))),
    el('tbody', {}, ...order.map(i => rows[i])));
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
  const refreshPromos = async () => {
    const pm = await api('promos');
    G.promos = pm.promos || [];
    drawPromos();
  };
  const promoRow = r => {
    const key = r.leaflet_id + '|' + r.name;
    const sc = scoreView(r.score, refreshPromos, G.open.has(key), open => (open ? G.open.add(key) : G.open.delete(key)));
    const main = el('div', { class: 'pl-main' }, el('b', { text: r.name }));
    const tag = turboTag(r.score);
    if (tag) main.append(tag);
    if (r.scope === 'brand' || r.scope === 'category') main.append(el('span', { class: 'pr-tag', text: (r.scope === 'category' ? 'kategoria: ' : 'cała marka: ') + (r.applies_to || '') }));
    (r.mine || []).forEach(m => main.append(el('span', { class: 'lf-hit-tag', text: 'Twój produkt: ' + (m.name || '') })));
    const per = period(r.valid_from, r.valid_to);
    main.append(el('small', { text: (r.shop || 'sklep') + (per ? ' · ' + per : '') + (r.notes ? ' · ' + r.notes : '') }));
    const price = el('div', { class: 'pl-price' });
    if (r.deal) price.append(el('b', { text: r.deal }));
    if (r.price) price.append(el('span', { text: r.price }));
    if (r.old_price) price.append(el('span', { class: 'orig', text: r.old_price }));
    return el('div', { class: 'pl-row' + ((r.mine || []).length ? ' mine' : '') }, sc.badge, main, price, sc.box);
  };
  const drawPromos = () => {
    const q = fold(input.value).trim();
    const rows = sortPromos(G.promos.filter(r => (!G.pshop || r.shop === G.pshop) && (!G.pmine || (r.mine || []).length) && (!G.pturbo || r.score.turbo)
      && (q.length < 2 || fold((r.name || '') + ' ' + (r.applies_to || '') + ' ' + (r.deal || '') + ' ' + (r.shop || '')).includes(q))), G.psort);
    promoSub.textContent = G.promos.length ? rows.length + ' z ' + G.promos.length : 'brak';
    if (!rows.length) {
      promoList.replaceChildren(el('div', { class: 'empty lf-empty' }, el('h2', { text: G.promos.length ? 'Nic nie pasuje' : 'Brak zapisanych promocji' }),
        el('p', { text: !G.promos.length ? 'Promocje pojawią się po przebiegu zadania, które odczyta aktualne gazetki.'
          : G.pturbo ? 'Nie ma teraz turbo okazji. Wymagają co najmniej 3 wcześniejszych promocji z minimum 4 tygodni.' : 'Zmień filtry albo szukaną frazę.' })));
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
  shopSel.addEventListener('change', () => { G.pshop = shopSel.value; save('pshop', G.pshop); G.pmore = 1; drawPromos(); });
  const sortSel = el('select', { 'aria-label': 'Sortowanie' }, ...SORTS.map(([v, t]) => el('option', { value: v, text: t })));
  sortSel.value = SORTS.some(x => x[0] === G.psort) ? G.psort : 'best';
  G.psort = sortSel.value;
  sortSel.addEventListener('change', () => { G.psort = sortSel.value; save('psort', G.psort); G.pmore = 1; drawPromos(); });
  const turboSw = switchEl(G.pturbo, 'Tylko turbo okazje');
  turboSw.cb.addEventListener('change', () => { G.pturbo = turboSw.cb.checked; G.pmore = 1; drawPromos(); });
  mineSw.cb.addEventListener('change', () => { G.pmine = mineSw.cb.checked; G.pmore = 1; drawPromos(); });
  const promoTools = el('div', { class: 'pl-tools' }, el('div', { class: 'search lf-search' }, icon('search'), input), shopSel, sortSel,
    el('label', { class: 'pl-mine' }, mineSw.node, el('span', { text: 'Tylko moje produkty' })),
    el('label', { class: 'pl-mine' }, turboSw.node, el('span', { text: 'Tylko turbo okazje' })));

  async function reload() {
    await fetchGazetki();
    fillShopSel(); drawPromos(); drawShops(); drawLeaflets();
  }

  const head = el('div', { class: 'page-head' }, el('h1', { text: 'Gazetki' }));
  view.replaceChildren(head,
    el('div', { class: 'section-title' }, el('h2', { text: 'Promocje w gazetkach' }), promoSub),
    el('p', { class: 'hint', text: 'Atrakcyjność 1-10 liczona z realnego rabatu po spełnieniu warunku i z porównania z wcześniejszymi promocjami w archiwum. „3?” oznacza brak podstaw do oceny. Dotknij oceny, żeby zobaczyć uzasadnienie.' }),
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
if (O.LIMITED || /^#\/?(produkty|gazetki)/.test(location.hash)) O.route();
})();
