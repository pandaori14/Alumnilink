// Pembantu tests/suite/uji_interaksi.php — MENGKLIK elemen sungguhan di
// Chrome headless lewat DevTools Protocol, tanpa paket npm.
//
//   node tests/interaksi_cdp.mjs <port> <berkas-konfigurasi.json>
//
// Konfigurasi: { base, sesi: {peran: id}, langkah: [...] }
// Setiap langkah: { nama, peran, url, klik (selector), tunggu (ms), nilai (ekspresi) }
// Keluaran: satu baris JSON berisi array hasil.
//
// Mengapa ini ada: uji_js_render hanya MENGURAI skrip. Skrip yang sintaksnya
// benar tetapi tidak pernah terpasang ke elemennya — misalnya karena berjalan
// di <head> sebelum elemennya ada — lolos tanpa jejak, dan tombolnya diam
// saja tanpa galat di konsol. Hanya klik sungguhan yang membuktikannya.
import fs from 'fs';

const port = process.argv[2];
const cfg = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
const tunggu = (ms) => new Promise((r) => setTimeout(r, ms));

const r = await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' });
const target = await r.json();
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((ok, gagal) => { ws.onopen = ok; ws.onerror = gagal; });

let id = 0;
const janji = new Map();
let peristiwa = [];
ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && janji.has(d.id)) { janji.get(d.id)(d); janji.delete(d.id); }
    else if (d.method) peristiwa.push(d);
};
const kirim = (method, params = {}) => new Promise((ok) => {
    const n = ++id; janji.set(n, ok); ws.send(JSON.stringify({ id: n, method, params }));
});
const nilai = async (ekspresi) => {
    const e = await kirim('Runtime.evaluate', { expression: ekspresi, returnByValue: true, awaitPromise: true });
    if (e.result?.exceptionDetails) {
        return { galat: e.result.exceptionDetails.exception?.description || 'evaluasi gagal' };
    }
    return e.result?.result?.value;
};

await kirim('Network.enable');
await kirim('Runtime.enable');
await kirim('Page.enable');
await kirim('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });

const hasil = [];
for (const langkah of cfg.langkah) {
    await kirim('Network.clearBrowserCookies');
    const sesi = cfg.sesi[langkah.peran];
    if (sesi) {
        await kirim('Network.setCookie', { name: 'PHPSESSID', value: sesi, domain: 'localhost', path: '/' });
    }
    peristiwa = [];
    await kirim('Page.navigate', { url: cfg.base + '/' + langkah.url });
    for (let i = 0; i < 60 && !peristiwa.some((e) => e.method === 'Page.loadEventFired'); i++) { await tunggu(100); }
    await tunggu(500);

    // Klik lewat koordinat sungguhan, bukan el.click(): itu menguji juga
    // bahwa elemennya benar-benar dapat ditekan pengguna, tidak tertutup
    // elemen lain.
    let klik = null;
    if (langkah.klik) {
        klik = await nilai(`(() => {
            const el = document.querySelector(${JSON.stringify(langkah.klik)});
            if (!el) return { ada: false };
            el.scrollIntoView({ block: 'center' });
            const g = el.getBoundingClientRect();
            const x = Math.round(g.left + g.width / 2), y = Math.round(g.top + g.height / 2);
            const atas = document.elementFromPoint(x, y);
            return { ada: true, x: x, y: y, tertutup: !(el === atas || el.contains(atas)) };
        })()`);
        if (klik && klik.ada && !klik.tertutup) {
            for (const type of ['mousePressed', 'mouseReleased']) {
                await kirim('Input.dispatchMouseEvent', { type, x: klik.x, y: klik.y, button: 'left', clickCount: 1 });
            }
        }
    }
    await tunggu(langkah.tunggu || 1200);

    hasil.push({
        nama: langkah.nama,
        klik: klik,
        nilai: langkah.nilai ? await nilai(langkah.nilai) : null,
        galatJs: peristiwa.filter((e) => e.method === 'Runtime.exceptionThrown')
            .map((e) => (e.params.exceptionDetails.exception?.description
                || e.params.exceptionDetails.text || '').split('\n')[0]),
    });
}

console.log(JSON.stringify(hasil));

// Lihat catatan di tests/responsif_cdp.mjs: Chrome yang tertinggal hidup
// menahan pipa keluaran dan membuat tests/run_all.php menunggu selamanya.
await kirim('Browser.close');
await tunggu(300);
ws.close();
