/* Atrapa API do ręcznego testu UI bez PHP. Ładować przed app.js. */
(() => {
'use strict';
const qs = new URLSearchParams(location.search);
const theme = qs.get('theme');
if (theme === 'light' || theme === 'dark') {
  document.documentElement.setAttribute('data-theme', theme);
  try { localStorage.setItem('okz-theme', theme); } catch (e) { /* brak storage */ }
}
const DELAY = +(qs.get('delay') || 80);
const TODAY = '2026-11-24';

const svg = (bg, fg, t) => 'data:image/svg+xml,' + encodeURIComponent(
  '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="400"><rect width="300" height="400" fill="' + bg + '"/><text x="150" y="210" font-size="34" font-family="sans-serif" text-anchor="middle" fill="' + fg + '">' + t + '</text></svg>');
const photo = (bg, t) => 'data:image/svg+xml,' + encodeURIComponent(
  '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="200"><rect width="320" height="200" fill="#fff"/><rect x="100" y="40" width="120" height="120" rx="14" fill="' + bg + '"/><text x="160" y="108" font-size="20" font-family="sans-serif" text-anchor="middle" fill="#fff">' + t + '</text></svg>');

const cats = [
  { key: 'elektronika', name: 'Elektronika', count: 4, fav: 0, what: 'Sprzęt elektroniczny', how: 'Sklepy internetowe', custom: false },
  { key: 'dom', name: 'Dom i ogród', count: 2, fav: 0, what: 'Dom', how: 'Sklepy', custom: false },
  { key: 'spozywcze', name: 'Spożywcze', count: 1, fav: 0, what: 'Żywność', how: 'Gazetki', custom: false },
  { key: 'wybrane', name: 'Wybrane', virtual: true, count: 0, fav: 0 },
];

let products = [
  { id: 'p3f9a1c2e', name: 'Kawa ziarnista Lavazza Qualità Rossa 1 kg', ean: '8000070035607', desc: 'Kawa ziarnista, opakowanie 1 kg, wersja Qualità Rossa (czerwone opakowanie). Nie mylić z Oro ani z mieloną.', category: 'spozywcze', sites: ['https://www.kawa-sklep.pl', 'https://allegro.pl', 'https://www.ceneo.pl/Kawa_ziarnista'], active: true, last_searched: '2026-11-24T14:00:00+01:00' },
  { id: 'p1b2c3d4e', name: 'Sonoff ZBDongle-E', ean: '6920075740219', desc: 'Koordynator Zigbee 3.0 USB, wersja E (EFR32MG21), nie mylić z ZBDongle-P.', category: 'elektronika', sites: ['https://botland.com.pl'], active: true, last_searched: '2026-11-23T08:00:00+01:00' },
  { id: 'p9e8d7c6b', name: 'Zmywarka do zabudowy 60 cm, klasa energetyczna B, z szufladą na sztućce i oświetleniem wnętrza (długa nazwa do testu zawijania)', ean: '', desc: '', category: '', sites: [], active: false, last_searched: null },
];

const offersFor = {
  p3f9a1c2e: [
    { id: 'o1', title: 'Lavazza Qualità Rossa kawa ziarnista 1 kg', url: 'https://www.kawa-sklep.pl/lavazza-rossa', price: '42,99 zł', price_num: 42.99, original_price: '54,90 zł', shop: 'Kawa-Sklep', last_seen: '2026-11-24T14:00:00+01:00', image: photo('#c0392b', 'Rossa'), confidence: 'high' },
    { id: 'o2', title: 'Kawa ziarnista LAVAZZA Qualita Rossa 1000 g - bardzo długi tytuł oferty który nie powinien rozsadzić karty ani wyjść poza jej krawędzie nigdzie', url: 'https://allegro.pl/oferta/1', price: '45,50 zł', price_num: 45.5, original_price: null, shop: 'Allegro', last_seen: '2026-11-24T14:00:00+01:00', image: photo('#a93226', 'Allegro'), confidence: 'medium' },
    { id: 'o3', title: 'Lavazza Rossa 1 kg ziarno', url: 'https://www.ceneo.pl/123', price: '47,00 zł', price_num: 47, original_price: null, shop: 'Ceneo', last_seen: '2026-11-22T14:00:00+01:00', image: null, confidence: 'low' },
    { id: 'o4', title: 'Kawa Lavazza Qualità Rossa 1kg', url: 'https://www.auchan.pl/lavazza', price: '49,99 zł', price_num: 49.99, original_price: '59,99 zł', shop: 'Auchan', last_seen: '2026-11-21T14:00:00+01:00', image: photo('#922b21', 'Auchan'), confidence: 'high' },
  ],
  p1b2c3d4e: [
    { id: 'o5', title: 'Sonoff ZBDongle-E - USB Zigbee 3.0', url: 'https://botland.com.pl/sonoff-zbdongle-e', price: '89,90 zł', price_num: 89.9, original_price: null, shop: 'Botland', last_seen: '2026-11-23T08:00:00+01:00', image: photo('#2c3e50', 'Dongle'), confidence: 'high' },
    { id: 'o6', title: 'SONOFF Zigbee 3.0 USB Dongle Plus E', url: 'https://allegro.pl/oferta/2', price: '94,00 zł', price_num: 94, original_price: null, shop: 'Allegro', last_seen: '2026-11-23T08:00:00+01:00', image: null, confidence: 'high' },
  ],
  p9e8d7c6b: [],
};
const hitsFor = {
  p3f9a1c2e: [
    { id: 'h1', product_id: 'p3f9a1c2e', leaflet_id: 'lf-lidl', shop: 'Lidl', leaflet_url: 'https://www.lidl.pl/gazetka', leaflet_title: 'Oferty od 25.11', item_name: 'Kawa ziarnista Lavazza Qualità Rossa 1 kg', price: '36,99 zł', old_price: '49,99 zł', valid_from: '2026-11-27', valid_to: '2026-11-29', page: 12, notes: 'cena przy zakupie w aplikacji Lidl Plus', alerted: true },
  ],
};

let shops = [
  { id: 's1a2b3c4d', name: 'Biedronka', url: 'https://www.biedronka.pl/pl/gazetki', note: 'sklep: Poznań, ul. Dąbrowskiego 29', active: true },
  { id: 's5e6f7a8b', name: 'Lidl', url: 'https://www.lidl.pl/c/nasze-gazetki/s10008614', note: 'sklep najbliższy: Poznań, Jeżyce', active: true },
];

const L = (id, shop_id, shop, title, from, to, items) => ({ id, shop_id, shop, title, url: 'https://example.pl/gazetka/' + id, valid_from: from, valid_to: to, cover: svg(shop === 'Lidl' ? '#0050aa' : '#d32f2f', '#fff', shop), items });
const it = (name, price, old, from, to, page, notes) => ({ name, price, old_price: old || '', valid_from: from || null, valid_to: to || null, page: page || null, notes: notes || '' });
let leaflets = [
  L('lf-bied', 's1a2b3c4d', 'Biedronka', 'Gazetka od 24.11', '2026-11-24', '2026-11-29', [
    it('Masło Extra 200 g', '5,99 zł', '8,49 zł', '2026-11-24', '2026-11-26', 3, 'przy zakupie 2 szt.'),
    it('Mleko UHT 3,2% 1 l', '3,49 zł', '', null, null, 3),
    it('Pierś z kurczaka świeża, 1 kg', '16,99 zł', '22,99 zł', '2026-11-24', '2026-11-29', 5),
    it('Papier toaletowy 16 rolek', '14,99 zł', '21,99 zł', null, null, 7),
    it('Płyn do naczyń 900 ml', '4,99 zł', '', null, null, 8),
    it('Jabłka Gala 1 kg', '2,99 zł', '', '2026-11-24', '2026-11-25', 2),
    it('Kawa mielona Tchibo Family 250 g', '12,99 zł', '16,99 zł', null, null, 4, 'limit 4 szt. na paragon'),
  ]),
  L('lf-lidl', 's5e6f7a8b', 'Lidl', 'Oferty od 25.11', '2026-11-25', '2026-11-30', [
    it('Kawa ziarnista Lavazza Qualità Rossa 1 kg', '36,99 zł', '49,99 zł', '2026-11-27', '2026-11-29', 12, 'cena przy zakupie w aplikacji Lidl Plus'),
    it('Chleb pszenny 500 g', '3,29 zł', '', null, null, 2),
    it('Ser Gouda plastry 150 g', '4,49 zł', '5,99 zł', null, null, 3),
    it('Odkurzacz bezworkowy Silvercrest 800 W', '199,00 zł', '259,00 zł', '2026-11-27', '2026-11-30', 20, 'gwarancja 3 lata'),
    it('Bluza polarowa męska', '39,99 zł', '', '2026-11-27', '2026-11-30', 22),
    it('Ręczniki kąpielowe 2 szt.', '29,99 zł', '', null, null, 23),
  ]),
  L('lf-old', 's1a2b3c4d', 'Biedronka', 'Gazetka od 17.11', '2026-11-17', '2026-11-23', [
    it('Cukier 1 kg', '3,99 zł', '', null, null, 2),
    it('Ryż biały 1 kg', '4,29 zł', '', null, null, 2),
  ]),
];
const leafMeta = l => { const { items, ...r } = l; return { ...r, items_count: items.length, current: !l.valid_to || l.valid_to >= TODAY }; };
const uid = () => 'p' + Math.random().toString(16).slice(2, 10);

function productsOut() {
  return products.map(p => {
    const offs = (offersFor[p.id] || []).slice().sort((a, b) => a.price_num - b.price_num);
    const best = offs[0] ? { price: offs[0].price, price_num: offs[0].price_num, shop: offs[0].shop, url: offs[0].url } : null;
    return { ...p, offers: offs, best, hits: hitsFor[p.id] || [], offers_count: offs.length };
  });
}
const cleanSites = s => (Array.isArray(s) ? s : String(s || '').split(/\r?\n/)).map(x => x.trim()).filter(Boolean).map(x => (/^https?:\/\//.test(x) ? x : 'https://' + x));

function handle(a, method, body, q) {
  switch (a) {
    case 'state': return { runs: [], categories: cats, fav_ids: [], today: TODAY, retention: 14 };
    case 'offers': return { offers: [], meta: null };
    case 'favorites': return { offers: [] };
    case 'sources': return { sources: [] };
    case 'products': return { products: productsOut() };
    case 'prod_save': {
      if (!String(body.name || '').trim()) throw [422, 'Podaj nazwę produktu.'];
      if (body.ean && !/^(\d{8}|\d{12,14})$/.test(String(body.ean).replace(/[\s-]/g, ''))) throw [422, 'EAN musi mieć 8, 12, 13 lub 14 cyfr.'];
      const data = { name: body.name.trim(), ean: String(body.ean || '').replace(/[\s-]/g, ''), desc: body.desc || '', category: body.category || '', sites: cleanSites(body.sites), active: !!body.active };
      if (body.id) { const p = products.find(x => x.id === body.id); if (!p) throw [404, 'Nie ma takiego produktu.']; Object.assign(p, data); return { ok: true, product: p }; }
      const p = { id: uid(), last_searched: null, ...data }; products.push(p); offersFor[p.id] = []; return { ok: true, product: p };
    }
    case 'prod_toggle': { const p = products.find(x => x.id === body.id); if (p) p.active = !!body.active; return { ok: true }; }
    case 'prod_delete': products = products.filter(x => x.id !== body.id); return { ok: true };
    case 'shops': return { shops, leaflets: leaflets.map(leafMeta) };
    case 'shop_save': {
      if (!String(body.name || '').trim()) throw [422, 'Podaj nazwę sklepu.'];
      if (!/^https?:\/\/[^/]+\.[a-z]{2,}/i.test(body.url || '')) throw [422, 'Adres strony jest nieprawidłowy.'];
      const data = { name: body.name.trim(), url: body.url.trim(), note: body.note || '', active: !!body.active };
      if (body.id) { const s = shops.find(x => x.id === body.id); Object.assign(s, data); return { ok: true, shop: s }; }
      const s = { id: 's' + Math.random().toString(16).slice(2, 10), ...data }; shops.push(s); return { ok: true, shop: s };
    }
    case 'shop_toggle': { const s = shops.find(x => x.id === body.id); if (s) s.active = !!body.active; return { ok: true }; }
    case 'shop_delete': shops = shops.filter(x => x.id !== body.id); return { ok: true };
    case 'leaflet': {
      const l = leaflets.find(x => x.id === q.get('id')); if (!l) throw [404, 'Nie ma takiej gazetki.'];
      const hits = Object.values(hitsFor).flat().filter(h => h.leaflet_id === l.id);
      return { leaflet: l, hits };
    }
    case 'leaflet_search': {
      const s = (q.get('q') || '').toLowerCase();
      if (s.length < 2) throw [422, 'Wpisz co najmniej 2 znaki.'];
      const results = [];
      leaflets.filter(l => leafMeta(l).current).forEach(l => l.items.forEach(i => { if (i.name.toLowerCase().includes(s)) results.push({ leaflet_id: l.id, shop: l.shop, title: l.title, valid_from: l.valid_from, valid_to: l.valid_to, item: i }); }));
      return { results };
    }
    default: throw [404, 'Nieznana akcja: ' + a];
  }
}

const realFetch = window.fetch.bind(window);
window.fetch = (input, init = {}) => {
  const u = new URL(typeof input === 'string' ? input : input.url, location.href);
  if (!/api\.php$/.test(u.pathname)) return realFetch(input, init);
  const method = (init.method || 'GET').toUpperCase();
  let body = {}; try { body = init.body ? JSON.parse(init.body) : {}; } catch (e) { /* brak body */ }
  return new Promise(res => setTimeout(() => {
    let status = 200, out;
    try { out = handle(u.searchParams.get('a'), method, body, u.searchParams); }
    catch (e) { if (Array.isArray(e)) { status = e[0]; out = { error: e[1] }; } else { status = 500; out = { error: String(e) }; } }
    res(new Response(JSON.stringify(out), { status, headers: { 'Content-Type': 'application/json' } }));
  }, DELAY));
};
})();
