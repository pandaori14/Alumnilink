<?php
/**
 * pages/notifications.php — Pusat Notifikasi
 * ─────────────────────────────────────────────────────────
 * Seluruh tindakan (tandai dibaca, hapus, buka detail) kini dilakukan lewat
 * api/notifications.php tanpa memuat ulang halaman.
 *
 * Perubahan penting dibanding versi sebelumnya:
 *  - Tujuan pengalihan tidak lagi diambil dari parameter URL (celah open
 *    redirect), melainkan dari kolom `link` di basis data.
 *  - Setiap tindakan membawa token CSRF.
 *  - Notifikasi yang sudah dibaca keluar dari daftar utama, tetapi tetap dapat
 *    ditelusuri pada tab Riwayat sehingga jejaknya tidak hilang.
 */
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <!-- Kepala halaman -->
    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-5 mb-8">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black outfit text-slate-800 tracking-tight flex items-center gap-3">
                <span class="relative">
                    <i data-lucide="bell" class="w-7 h-7 brand-text" aria-hidden="true"></i>
                    <span id="headerDot" class="hidden absolute -top-0.5 -right-0.5 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-white"></span>
                </span>
                Pusat Notifikasi
            </h1>
            <p class="text-slate-500 font-medium mt-1 text-sm">
                Pembaruan layanan, status pengajuan, dan kabar terbaru untuk Anda.
            </p>
        </div>

        <div class="flex items-center gap-2 shrink-0">
            <button type="button" id="btnReadAll"
                    class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 bg-white border border-slate-200 px-4 py-2.5 rounded-xl hover:bg-slate-50 transition-all active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed">
                <i data-lucide="check-check" class="w-4 h-4" aria-hidden="true"></i>
                Tandai Semua
            </button>
            <button type="button" id="btnClearRead"
                    class="hidden inline-flex items-center gap-2 text-xs font-bold text-slate-500 bg-white border border-slate-200 px-4 py-2.5 rounded-xl hover:bg-red-50 hover:text-red-600 hover:border-red-200 transition-all active:scale-95">
                <i data-lucide="trash-2" class="w-4 h-4" aria-hidden="true"></i>
                Bersihkan Riwayat
            </button>
        </div>
    </div>

    <!-- Tab -->
    <div class="flex items-center gap-2 mb-7 border-b border-slate-100" role="tablist">
        <button type="button" role="tab" aria-selected="true" data-filter="unread"
                class="notif-tab relative px-5 py-3 text-sm font-bold text-slate-800 transition-colors">
            Belum Dibaca
            <span id="tabCountUnread" class="ml-1.5 px-2 py-0.5 rounded-full bg-red-100 text-red-600 text-[10px] font-black align-middle">0</span>
            <span class="notif-tab-underline absolute bottom-0 left-0 right-0 h-0.5 brand-bg rounded-full"></span>
        </button>
        <button type="button" role="tab" aria-selected="false" data-filter="all"
                class="notif-tab relative px-5 py-3 text-sm font-bold text-slate-400 hover:text-slate-600 transition-colors">
            Riwayat
            <span id="tabCountAll" class="ml-1.5 px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 text-[10px] font-black align-middle">0</span>
            <span class="notif-tab-underline absolute bottom-0 left-0 right-0 h-0.5 brand-bg rounded-full hidden"></span>
        </button>
    </div>

    <!-- Wadah daftar -->
    <div id="notifList" class="space-y-3" aria-live="polite" aria-busy="true">
        <!-- Kerangka pemuatan -->
        <div class="animate-pulse space-y-3" id="notifSkeleton">
            <?php for ($i = 0; $i < 3; $i++): ?>
                <div class="bg-white border border-slate-100 rounded-2xl p-5 flex gap-4">
                    <div class="w-11 h-11 bg-slate-100 rounded-xl shrink-0"></div>
                    <div class="flex-1 space-y-2.5 py-1">
                        <div class="h-3.5 bg-slate-100 rounded w-1/3"></div>
                        <div class="h-3 bg-slate-100 rounded w-full"></div>
                        <div class="h-3 bg-slate-100 rounded w-2/3"></div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const API   = 'api/notifications.php';
    const CSRF  = <?php echo json_encode(get_csrf_token()); ?>;
    const list  = document.getElementById('notifList');

    let filter = 'unread';

    // ── Utilitas ────────────────────────────────────────────────────
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function post(action, extra) {
        const body = new URLSearchParams(Object.assign({ action: action, csrf_token: CSRF }, extra || {}));
        return fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
            body: body
        }).then(r => r.json());
    }

    // Warna per jenis notifikasi. Ditulis lengkap (bukan dirakit) agar
    // terdeteksi pemindai Tailwind saat build.
    const TONES = {
        emerald: 'bg-emerald-50 text-emerald-600 border-emerald-100',
        amber:   'bg-amber-50 text-amber-600 border-amber-100',
        red:     'bg-red-50 text-red-600 border-red-100',
        blue:    'bg-blue-50 text-blue-600 border-blue-100'
    };

    // ── Render ──────────────────────────────────────────────────────
    function emptyState() {
        const unread = filter === 'unread';
        return `
            <div class="bg-white border border-slate-100 rounded-3xl p-14 text-center">
                <div class="w-20 h-20 bg-slate-50 text-slate-300 rounded-3xl flex items-center justify-center mx-auto mb-5 border border-slate-100">
                    <i data-lucide="${unread ? 'bell-off' : 'inbox'}" class="w-9 h-9" aria-hidden="true"></i>
                </div>
                <h4 class="text-lg font-black outfit text-slate-700">
                    ${unread ? 'Semua Sudah Dibaca' : 'Riwayat Masih Kosong'}
                </h4>
                <p class="text-slate-400 mt-2 text-sm max-w-sm mx-auto leading-relaxed">
                    ${unread
                        ? 'Tidak ada notifikasi baru. Pembaruan status legalisir, donasi, dan kabar alumni akan muncul di sini.'
                        : 'Belum ada notifikasi yang tercatat untuk akun Anda.'}
                </p>
            </div>`;
    }

    function cardHTML(n) {
        const tone     = TONES[n.tone] || TONES.blue;
        const clickable = n.has_link;

        return `
        <article data-id="${n.id}"
                 class="notif-card group bg-white border ${n.is_read ? 'border-slate-100' : 'border-slate-200 shadow-sm'} rounded-2xl p-5 flex gap-4 transition-all hover:shadow-md hover:border-slate-300 ${clickable ? 'cursor-pointer' : ''}"
                 ${clickable ? 'tabindex="0" role="link" aria-label="Buka detail: ' + esc(n.title) + '"' : ''}>

            <div class="w-11 h-11 ${tone} border rounded-xl flex items-center justify-center shrink-0">
                <i data-lucide="${n.icon}" class="w-5 h-5" aria-hidden="true"></i>
            </div>

            <div class="flex-1 min-w-0">
                <div class="flex items-start justify-between gap-3">
                    <h4 class="font-bold text-slate-800 text-sm leading-snug flex items-center gap-2 flex-wrap">
                        ${esc(n.title)}
                        ${n.is_read ? '' : '<span class="w-2 h-2 brand-bg rounded-full shrink-0" title="Belum dibaca"></span>'}
                    </h4>
                    <time class="text-[11px] font-semibold text-slate-400 shrink-0 whitespace-nowrap" title="${esc(n.absolute)}">
                        ${esc(n.relative)}
                    </time>
                </div>

                <p class="text-slate-500 mt-1.5 text-sm leading-relaxed break-words">${esc(n.message)}</p>

                <div class="flex items-center gap-4 mt-3">
                    ${clickable ? `
                        <span class="inline-flex items-center gap-1.5 text-xs font-bold brand-text">
                            Lihat detail
                            <i data-lucide="arrow-right" class="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform" aria-hidden="true"></i>
                        </span>` : ''}

                    ${n.is_read ? '' : `
                        <button type="button" data-act="read" data-id="${n.id}"
                                class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-400 hover:text-slate-700 transition-colors">
                            <i data-lucide="check" class="w-3.5 h-3.5" aria-hidden="true"></i>
                            Tandai dibaca
                        </button>`}

                    <button type="button" data-act="delete" data-id="${n.id}"
                            class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-300 hover:text-red-500 transition-colors ml-auto"
                            aria-label="Hapus notifikasi">
                        <i data-lucide="trash-2" class="w-3.5 h-3.5" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </article>`;
    }

    function render(data) {
        list.setAttribute('aria-busy', 'false');

        document.getElementById('tabCountUnread').textContent = data.unread;
        document.getElementById('tabCountAll').textContent    = data.total;
        document.getElementById('btnReadAll').disabled        = data.unread === 0;
        document.getElementById('btnClearRead').classList.toggle('hidden', filter !== 'all' || data.total === 0);

        const dot = document.getElementById('headerDot');
        if (dot) dot.classList.toggle('hidden', data.unread === 0);

        // Perbarui lencana di header aplikasi
        if (window.updateNotifBadge) window.updateNotifBadge(data.unread);

        if (!data.items.length) {
            list.innerHTML = emptyState();
            lucide.createIcons();
            return;
        }

        // Kelompokkan per rentang tanggal agar daftar mudah dipindai
        let html = '', lastGroup = null;
        data.items.forEach(n => {
            if (n.group !== lastGroup) {
                lastGroup = n.group;
                html += `<p class="text-[10px] font-black uppercase tracking-widest text-slate-400 pt-4 pb-1 px-1">${esc(n.group)}</p>`;
            }
            html += cardHTML(n);
        });

        list.innerHTML = html;
        lucide.createIcons();
    }

    function load() {
        list.setAttribute('aria-busy', 'true');
        fetch(`${API}?action=list&filter=${filter}&limit=50`)
            .then(r => r.json())
            .then(d => { if (d.success) render(d); })
            .catch(() => {
                list.innerHTML = `<div class="bg-red-50 border border-red-200 rounded-2xl p-6 text-center text-sm text-red-600 font-semibold">
                    Gagal memuat notifikasi. Periksa koneksi Anda lalu muat ulang halaman.
                </div>`;
            });
    }

    // ── Interaksi ───────────────────────────────────────────────────

    // Buka detail: tandai dibaca lalu pindah ke tujuan DARI BASIS DATA
    function openDetail(id) {
        post('read', { id: id }).then(d => {
            if (d.success && d.link) {
                window.location.href = d.link;
            } else {
                load();
            }
        });
    }

    list.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-act]');
        if (btn) {
            e.stopPropagation();
            const id = btn.dataset.id;
            if (btn.dataset.act === 'read')   post('read',   { id: id }).then(load);
            if (btn.dataset.act === 'delete') post('delete', { id: id }).then(load);
            return;
        }

        const card = e.target.closest('.notif-card[role="link"]');
        if (card) openDetail(card.dataset.id);
    });

    // Kartu dapat dibuka dengan papan ketik
    list.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        const card = e.target.closest('.notif-card[role="link"]');
        if (card) { e.preventDefault(); openDetail(card.dataset.id); }
    });

    document.querySelectorAll('.notif-tab').forEach(tab => {
        tab.addEventListener('click', function () {
            filter = this.dataset.filter;
            document.querySelectorAll('.notif-tab').forEach(t => {
                const aktif = t === this;
                t.setAttribute('aria-selected', aktif ? 'true' : 'false');
                t.classList.toggle('text-slate-800', aktif);
                t.classList.toggle('text-slate-400', !aktif);
                t.querySelector('.notif-tab-underline').classList.toggle('hidden', !aktif);
            });
            load();
        });
    });

    document.getElementById('btnReadAll').addEventListener('click', function () {
        post('read_all').then(load);
    });

    document.getElementById('btnClearRead').addEventListener('click', function () {
        swalConfirm(
            'Bersihkan Riwayat?',
            'Seluruh notifikasi yang sudah dibaca akan dihapus permanen.',
            () => post('delete_read').then(load),
            'Ya, Bersihkan'
        );
    });

    load();
})();
</script>
