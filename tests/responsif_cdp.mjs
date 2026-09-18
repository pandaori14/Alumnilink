// Pembantu tests/suite/uji_responsif.php — menjalankan pemeriksaan tata
// letak di Chrome headless lewat DevTools Protocol, tanpa paket npm.
//
//   node tests/responsif_cdp.mjs <port> <berkas-konfigurasi.json>
//
// Konfigurasi: { base, layar: [{nama,w,h,mobile}], halaman: [[peran,sesi,url]] }
// Keluaran: satu baris JSON berisi array hasil.
import fs from 'fs';

const port = process.argv[2];
const cfg = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
const tunggu = (ms) => new Promise((r) => setTimeout(r, ms));

// Dijalankan DI DALAM halaman. Yang dinilai adalah apa yang dirasakan
// pengguna: halaman tidak boleh dapat digeser ke samping. Daftar elemen
// yang melewati tepi hanya dikumpulkan sebagai petunjuk ketika itu terjadi.
const PEMERIKSA = `(() => {
    const lebar = document.documentElement.clientWidth;
    const geser = document.documentElement.scrollWidth - lebar;
    const luber = [];
    if (geser > 1) {
        for (const el of document.querySelectorAll('body *')) {
            const g = el.getBoundingClientRect();
            if (g.width === 0 || g.height === 0) continue;
            const gaya = getComputedStyle(el);
            if (gaya.visibility === 'hidden' || gaya.position === 'fixed') continue;
            if (g.left < -1000) continue;                 // sengaja di luar layar (skip-link)
            if (g.right <= lebar + 1) continue;
            let digulir = false;
            for (let p = el; p && p !== document.body; p = p.parentElement) {
                const pg = getComputedStyle(p);
                if (pg.overflowX === 'auto' || pg.overflowX === 'scroll') { digulir = true; break; }
            }
            if (digulir) continue;
            luber.push((el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') +
                (typeof el.className === 'string' && el.className.trim()
                    ? '.' + el.className.trim().split(/\\s+/).slice(0, 3).join('.') : '')).slice(0, 80));
        }
    }
    const utama = document.getElementById('main-content');
    return {
        geser: geser,
        luber: [...new Set(luber)].slice(0, 5),
        ruangBawah: utama ? Math.round(parseFloat(getComputedStyle(utama).paddingBottom)) : null,
    };
})()`;

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

await kirim('Network.enable');
await kirim('Runtime.enable');
await kirim('Page.enable');

const hasil = [];
for (const layar of cfg.layar) {
    await kirim('Emulation.setDeviceMetricsOverride', {
        width: layar.w, height: layar.h, deviceScaleFactor: 1, mobile: !!layar.mobile,
    });
    for (const [peran, sesi, url] of cfg.halaman) {
        await kirim('Network.clearBrowserCookies');
        if (sesi) {
            await kirim('Network.setCookie', { name: 'PHPSESSID', value: sesi, domain: 'localhost', path: '/' });
        }
        peristiwa = [];
        await kirim('Page.navigate', { url: cfg.base + '/' + url });
        for (let i = 0; i < 50 && !peristiwa.some((e) => e.method === 'Page.loadEventFired'); i++) {
            await tunggu(100);
        }
        await tunggu(400);
        const ev = await kirim('Runtime.evaluate', { expression: PEMERIKSA, returnByValue: true });
        const nilai = ev.result?.result?.value
            || { galat: ev.result?.exceptionDetails?.exception?.description || 'evaluasi gagal' };
        hasil.push({
            layar: layar.nama, lebar: layar.w, mobile: !!layar.mobile, peran,
            halaman: url.replace('index.php?page=', '').split('&')[0],
            ...nilai,
            galatJs: peristiwa.filter((e) => e.method === 'Runtime.exceptionThrown')
                .map((e) => (e.params.exceptionDetails.exception?.description || e.params.exceptionDetails.text || '').split('\n')[0]),
        });
    }
}
console.log(JSON.stringify(hasil));

// Tutup peramban dengan rapi. Tanpa ini, Chrome tetap hidup dan — bila
// suite dijalankan dari tests/run_all.php — ia menahan pipa keluaran
// sehingga proses induk menunggu selamanya.
await kirim('Browser.close');
await tunggu(300);
ws.close();
