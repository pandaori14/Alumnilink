</main>
    </div>

    <!-- Premium Floating Mobile Navigation -->
    <div class="fixed bottom-8 left-6 right-6 z-50 md:hidden">
        <div class="bg-slate-900/95 backdrop-blur-3xl rounded-[2.5rem] border border-white/10 shadow-[0_20px_50px_rgba(0,0,0,0.3)] px-4 py-3">
            <nav class="flex items-center justify-around gap-1">
                <a href="dashboard" class="flex flex-col items-center gap-1.5 transition-all duration-300 <?php echo $page == 'dashboard' ? 'text-white scale-110' : 'text-white/30 hover:text-white/50'; ?>">
                    <div class="relative">
                        <i data-lucide="home" class="w-5 h-5"></i>
                        <?php if ($page == 'dashboard'): ?>
                            <span class="absolute -bottom-2 left-1/2 -translate-x-1/2 w-1.5 h-1.5 bg-blue-500 rounded-full shadow-[0_0_10px_rgba(59,130,246,0.8)]"></span>
                        <?php endif; ?>
                    </div>
                    <span class="text-[9px] font-black uppercase tracking-tighter <?php echo $page == 'dashboard' ? 'opacity-100' : 'opacity-60'; ?>">Beranda</span>
                </a>

                <?php 
                $menu_type = $_SESSION['user_role'] === 'alumni' ? 'main' : 'admin';
                $shown_count = 0;
                
                foreach ($all_menus[$menu_type] as $menu_data):
                    if ($menu_data['key'] === 'dashboard') continue;
                    if (!can_see_menu($menu_data['key'], $_SESSION['user_role'], $sidebar_permissions)) continue;
                    
                    $shown_count++;
                    if ($shown_count > 3) break; 
                    
                    $is_active = strpos($page, $menu_data['key']) !== false;
                    
                    // Generate short label for mobile bottom nav
                    $label_parts = explode(' ', $menu_data['label']);
                    $short_label = count($label_parts) > 1 && in_array(strtolower($label_parts[0]), ['kelola', 'laporan', 'database', 'konfigurasi', 'layanan', 'data']) ? $label_parts[1] : $label_parts[0];
                    if ($menu_data['key'] === 'admin_tracer_config') $short_label = 'Tracer';
                    if ($menu_data['key'] === 'admin_settings') $short_label = 'Sistem';
                    if ($menu_data['key'] === 'admin_news') $short_label = 'Berita';
                ?>
                <a href="<?php echo e($menu_data['key']); ?>" class="flex flex-col items-center gap-1.5 transition-all duration-300 <?php echo $is_active ? 'text-white scale-110' : 'text-white/30 hover:text-white/50'; ?>">
                    <div class="relative">
                        <i data-lucide="<?php echo e($menu_data['icon']); ?>" class="w-5 h-5"></i>
                        <?php if ($is_active): ?>
                            <span class="absolute -bottom-2 left-1/2 -translate-x-1/2 w-1.5 h-1.5 bg-blue-500 rounded-full shadow-[0_0_10px_rgba(59,130,246,0.8)]"></span>
                        <?php endif; ?>
                    </div>
                    <span class="text-[9px] font-black uppercase tracking-tighter <?php echo $is_active ? 'opacity-100' : 'opacity-60'; ?>"><?php echo e($short_label); ?></span>
                </a>
                <?php endforeach; ?>

                <a href="profile" class="flex flex-col items-center gap-1.5 transition-all duration-300 <?php echo $page == 'profile' ? 'text-white scale-110' : 'text-white/30 hover:text-white/50'; ?>">
                    <div class="relative">
                        <i data-lucide="user" class="w-5 h-5"></i>
                        <?php if ($page == 'profile'): ?>
                            <span class="absolute -bottom-2 left-1/2 -translate-x-1/2 w-1.5 h-1.5 bg-blue-500 rounded-full shadow-[0_0_10px_rgba(59,130,246,0.8)]"></span>
                        <?php endif; ?>
                    </div>
                    <span class="text-[9px] font-black uppercase tracking-tighter <?php echo $page == 'profile' ? 'opacity-100' : 'opacity-60'; ?>">Profil</span>
                </a>
            </nav>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // Premium Universal URL Masking for Status Bar & Copy Link: Convert all index.php?page= links to clean encrypted/RESTful paths
        function maskAllLinks() {
            document.querySelectorAll('a').forEach(a => {
                const origHref = a.getAttribute('href');
                if (origHref && origHref.includes('index.php?page=')) {
                    try {
                        const urlObj = new URL(a.href, window.location.origin);
                        const pageParam = urlObj.searchParams.get('page');
                        const idParam = urlObj.searchParams.get('id');
                        const actionParam = urlObj.searchParams.get('action');
                        
                        let cleanPath = pageParam;
                        if (idParam) cleanPath += '/' + idParam;
                        if (actionParam) cleanPath += '/' + actionParam;
                        
                        a.setAttribute('href', cleanPath);
                        a.removeAttribute('onclick');
                    } catch(err) {}
                }
            });
        }

        maskAllLinks();
        document.addEventListener('DOMContentLoaded', maskAllLinks);
    </script>
    <?php if (($sys_settings['dashboard_bg_animation'] ?? '1') === '1'): ?>
    <script>
        // Antigravity Particles Generator
        function createParticles() {
            const container = document.getElementById('particles');
            if (!container) return;
            const particleCount = 15; // Jumlah partikel
            
            for (let i = 0; i < particleCount; i++) {
                setTimeout(() => {
                    const particle = document.createElement('div');
                    particle.classList.add('particle');
                    
                    // Randomize size, position, and animation duration
                    const size = Math.random() * 60 + 20; // 20px - 80px
                    const left = Math.random() * 100; // 0% - 100%
                    const duration = Math.random() * 20 + 15; // 15s - 35s
                    
                    particle.style.width = `${size}px`;
                    particle.style.height = `${size}px`;
                    particle.style.left = `${left}%`;
                    particle.style.animationDuration = `${duration}s`;
                    
                    container.appendChild(particle);
                    
                    // Remove and recreate particle when animation ends to keep it looping infinitely
                    particle.addEventListener('animationend', () => {
                        particle.remove();
                        createSingleParticle(container);
                    });
                }, i * 1500); // Stagger particle creation
            }
        }

        function createSingleParticle(container) {
            const particle = document.createElement('div');
            particle.classList.add('particle');
            const size = Math.random() * 60 + 20;
            const left = Math.random() * 100;
            const duration = Math.random() * 20 + 15;
            
            particle.style.width = `${size}px`;
            particle.style.height = `${size}px`;
            particle.style.left = `${left}%`;
            particle.style.animationDuration = `${duration}s`;
            
            container.appendChild(particle);
            particle.addEventListener('animationend', () => {
                particle.remove();
                createSingleParticle(container);
            });
        }

        document.addEventListener('DOMContentLoaded', createParticles);
    </script>
    <?php endif; ?>

    <style>
    /* Global Scoped Modal Styles (Antigravity Design System) */
    .mo {
        position: fixed;
        inset: 0;
        background: rgba(15,23,42,0.45);
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
        backdrop-filter: blur(5px);
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.2s;
    }
    .mo.open { opacity: 1; pointer-events: all; }
    .mo-box {
        background: #fff;
        border-radius: 20px;
        width: 100%;
        box-shadow: 0 30px 70px rgba(0,0,0,0.22);
        transform: scale(0.94) translateY(12px);
        transition: transform 0.22s;
        overflow: hidden;
    }
    .mo.open .mo-box { transform: scale(1) translateY(0); }
    .lp-preview-label {
        padding: 12px 16px;
        font-size: 11px;
        font-weight: 700;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 1px;
        border-bottom: 1px solid #f1f5f9;
        background: white;
    }
    </style>

    <!-- ══════ MODAL: Detail Broadcast ════════════════════ -->
    <div class="mo" id="detail-modal" onclick="moOverlayClose(event,this)">
        <div class="mo-box" style="max-width:960px;">
            <!-- Header -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/50">
                <div>
                    <h2 class="font-bold text-lg text-slate-800 outfit" id="det-title">Detail Broadcast</h2>
                    <p class="text-xs text-slate-400 mt-0.5" id="det-meta">Loading...</p>
                </div>
                <button type="button" onclick="closeMo('detail-modal')" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-slate-100 text-slate-500 transition-colors" aria-label="Tutup">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <!-- Body -->
            <div class="grid grid-cols-1 lg:grid-cols-5 h-[500px]">
                <!-- Left: Email Content (3 cols) -->
                <div class="lg:col-span-3 border-r border-slate-100 flex flex-col overflow-hidden bg-white">
                    <div class="lp-preview-label border-b border-slate-50 px-4 py-2">Isi Pesan</div>
                    <div class="p-4 flex-1 overflow-auto bg-slate-50/50">
                        <iframe id="det-iframe" style="width:100%; height:100%; border:none; border-radius:12px; background:white; box-shadow:0 2px 12px rgba(0,0,0,0.02);"></iframe>
                    </div>
                    <div class="px-4 py-2 bg-slate-50/50 border-t border-slate-100 text-xs" id="det-attachments-wrap">
                        <span class="font-bold text-slate-500">Lampiran:</span>
                        <div id="det-attachments" class="flex flex-wrap gap-2 mt-1.5"></div>
                    </div>
                </div>
                <!-- Right: Delivery Logs & Stats (2 cols) -->
                <div class="lg:col-span-2 flex flex-col overflow-hidden bg-slate-50/30">
                    <div class="lp-preview-label border-b border-slate-150 px-4 py-2">Log Pengiriman</div>
                    <!-- Stats Summary -->
                    <div class="grid grid-cols-3 gap-2 p-3 bg-white border-b border-slate-100 shrink-0">
                        <div class="p-2 bg-indigo-50 border border-indigo-100/50 rounded-xl text-center">
                            <span class="block text-[10px] font-black text-indigo-400 uppercase tracking-widest leading-none mb-1">Total</span>
                            <span class="text-sm font-black text-indigo-700 outfit" id="det-stat-total">0</span>
                        </div>
                        <div class="p-2 bg-emerald-50 border border-emerald-100/50 rounded-xl text-center">
                            <span class="block text-[10px] font-black text-emerald-400 uppercase tracking-widest leading-none mb-1">Sukses</span>
                            <span class="text-sm font-black text-emerald-700 outfit" id="det-stat-success">0</span>
                        </div>
                        <div class="p-2 bg-rose-50 border border-rose-100/50 rounded-xl text-center">
                            <span class="block text-[10px] font-black text-rose-400 uppercase tracking-widest leading-none mb-1">Gagal</span>
                            <span class="text-sm font-black text-rose-700 outfit" id="det-stat-failed">0</span>
                        </div>
                    </div>
                    <!-- Recipient list filter / search -->
                    <div class="p-2 border-b border-slate-100 bg-white flex gap-2 shrink-0">
                        <div class="relative flex-1">
                            <input aria-label="Cari penerima" id="det-log-search" type="text" oninput="filterDetailLogs()" placeholder="Cari penerima..."
                                   class="w-full pl-7 pr-3 py-1.5 rounded-lg border border-slate-200 text-xs outline-none focus:ring-1 focus:ring-indigo-400">
                            <i data-lucide="search" class="w-3 h-3 text-slate-400 absolute left-2 top-1/2 -translate-y-1/2"></i>
                        </div>
                        <select aria-label="Filter Status" id="det-log-status" onchange="filterDetailLogs()" class="px-2 py-1 rounded-lg border border-slate-200 text-xs bg-white outline-none">
                            <option value="">Semua Status</option>
                            <option value="sent">Sukses</option>
                            <option value="failed">Gagal</option>
                            <option value="queued">Antrean</option>
                        </select>
                    </div>
                    <!-- Scrollable Table -->
                    <div class="flex-1 overflow-auto p-2" id="det-log-list-wrap">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="text-slate-400 font-bold border-b border-slate-100"><th class="pb-1.5 pl-1">Penerima</th><th class="pb-1.5">Channel</th><th class="pb-1.5 pr-1">Status</th></tr>
                            </thead>
                            <tbody id="det-log-tbody" class="divide-y divide-slate-100/70">
                                <!-- JS populated -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ── Global Modal Open / Close Helpers ──────────
        function openMo(id) {
            document.getElementById(id).classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function closeMo(id) {
            document.getElementById(id).classList.remove('open');
            document.body.style.overflow = '';
        }
        function moOverlayClose(e, el) {
            if (e.target === el) { el.classList.remove('open'); document.body.style.overflow = ''; }
        }

        // ── Detail Broadcast Modal ──────────────────────
        let currentDetailLogs = []; // Cache list of logs for filtering

        function viewBroadcastDetails(id) {
            // Open modal and show loading
            const frame = document.getElementById('det-iframe');
            frame.srcdoc = `<!DOCTYPE html><html lang="id"><body style="font-family:sans-serif;color:#94a3b8;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;">Loading...</body></html>`;
            
            document.getElementById('det-title').textContent = 'Loading...';
            document.getElementById('det-meta').textContent = '';
            document.getElementById('det-stat-total').textContent = '-';
            document.getElementById('det-stat-success').textContent = '-';
            document.getElementById('det-stat-failed').textContent = '-';
            document.getElementById('det-log-tbody').innerHTML = '<tr><td colspan="3" class="text-center py-4 text-slate-400">Loading logs...</td></tr>';
            document.getElementById('det-attachments-wrap').style.display = 'none';
            document.getElementById('det-attachments').innerHTML = '';
            
            openMo('detail-modal');

            fetch('api/broadcast_detail.php?id=' + id)
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                        closeMo('detail-modal');
                        return;
                    }
                    
                    // Populate basic info
                    document.getElementById('det-title').textContent = data.title;
                    const priorityLabels = { 'normal': 'Normal', 'info': 'Info', 'urgent': '🔴 Urgent' };
                    const priorityLabel = priorityLabels[data.priority] || 'Normal';
                    const dateStr = new Date(data.created_at).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                    document.getElementById('det-meta').textContent = `Oleh: ${data.sender_name} • ${dateStr} • Prioritas: ${priorityLabel}`;

                    // Populate iframe
                    const htmlContent = data.body_html || `<div style="padding:16px;font-family:sans-serif;color:#333;line-height:1.6;">${data.message.replace(/\n/g, '<br>')}</div>`;
                    frame.srcdoc = `<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><style>body{margin:0;padding:12px;background:#fff;}</style></head><body>${htmlContent}</body></html>`;

                    // Populate attachments
                    if (data.attachments && data.attachments.length > 0) {
                        document.getElementById('det-attachments-wrap').style.display = 'block';
                        document.getElementById('det-attachments').innerHTML = data.attachments.map(att => {
                            const sizeMB = (att.file_size / (1024 * 1024)).toFixed(2);
                            return `<a href="uploads/broadcast_attachments/${att.stored_name}" target="_blank" class="inline-flex items-center gap-1 bg-slate-100 hover:bg-slate-200 text-[10px] px-2.5 py-1 rounded-full border border-slate-200 text-slate-600 transition-colors">
                                📎 ${escH(att.original_name)} (${sizeMB} MB)
                            </a>`;
                        }).join('');
                    }

                    // Populate Logs (if admin)
                    const logCol = document.querySelector('#detail-modal .grid > div:last-child');
                    const contentCol = document.querySelector('#detail-modal .grid > div:first-child');
                    if (data.delivery_logs) {
                        logCol.style.display = 'flex';
                        contentCol.className = 'lg:col-span-3 border-r border-slate-100 flex flex-col overflow-hidden bg-white';
                        currentDetailLogs = data.delivery_logs;
                        
                        // Calculate stats
                        const total = currentDetailLogs.length;
                        const success = currentDetailLogs.filter(l => l.status === 'sent').length;
                        const failed = currentDetailLogs.filter(l => l.status === 'failed').length;
                        
                        document.getElementById('det-stat-total').textContent = total;
                        document.getElementById('det-stat-success').textContent = success;
                        document.getElementById('det-stat-failed').textContent = failed;
                        
                        document.getElementById('det-log-search').value = '';
                        document.getElementById('det-log-status').value = '';
                        
                        renderDetailLogs(currentDetailLogs);
                    } else {
                        logCol.style.display = 'none';
                        contentCol.className = 'lg:col-span-5 flex flex-col overflow-hidden bg-white';
                    }
                    
                    // Refresh lucide icons inside modal if needed
                    if (window.lucide) window.lucide.createIcons();
                })
                .catch(err => {
                    alert('Gagal mengambil data detail: ' + err.message);
                    closeMo('detail-modal');
                });
        }

        function renderDetailLogs(logs) {
            const tbody = document.getElementById('det-log-tbody');
            if (logs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-slate-400">Tidak ada data.</td></tr>';
                return;
            }
            
            tbody.innerHTML = logs.map(l => {
                const nameDisplay = l.to_name ? `${escH(l.to_name)}<br><span class="text-[10px] text-slate-400">${escH(l.to_email)}</span>` : escH(l.to_email);
                const extBadge = l.is_external == 1 ? '<span class="text-[8px] px-1 bg-amber-100 text-amber-700 rounded ml-1 font-bold">EXT</span>' : '';
                
                let statusBadge = '';
                if (l.status === 'sent') {
                    statusBadge = '<span class="px-2 py-0.5 bg-emerald-50 text-emerald-600 rounded-full font-bold border border-emerald-100">Sukses</span>';
                } else if (l.status === 'failed') {
                    statusBadge = `<span class="px-2 py-0.5 bg-rose-50 text-rose-600 rounded-full font-bold border border-rose-100 cursor-help" title="${escA(l.error_message || 'Gagal dikirim')}">Gagal ⚠️</span>`;
                } else {
                    statusBadge = `<span class="px-2 py-0.5 bg-blue-50 text-blue-600 rounded-full font-bold border border-blue-100">${escH(l.status)}</span>`;
                }
                
                const channelBadge = l.channel === 'app' 
                    ? '<span class="inline-flex items-center gap-1 font-semibold text-indigo-600">🔔 App</span>' 
                    : '<span class="inline-flex items-center gap-1 font-semibold text-sky-600">✉ Email</span>';
                    
                return `<tr class="border-b border-slate-100/50 hover:bg-slate-50/40">
                    <td class="py-2 pl-1 font-medium text-slate-700 leading-tight">${nameDisplay}${extBadge}</td>
                    <td class="py-2">${channelBadge}</td>
                    <td class="py-2 pr-1">${statusBadge}</td>
                </tr>`;
            }).join('');
        }

        function filterDetailLogs() {
            const q = document.getElementById('det-log-search').value.toLowerCase().trim();
            const status = document.getElementById('det-log-status').value;
            
            const filtered = currentDetailLogs.filter(l => {
                const matchesQuery = (l.to_email && l.to_email.toLowerCase().includes(q)) || 
                                     (l.to_name && l.to_name.toLowerCase().includes(q));
                const matchesStatus = !status || l.status === status;
                return matchesQuery && matchesStatus;
            });
            
            renderDetailLogs(filtered);
        }

        function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
        function escA(s) { return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }

        document.addEventListener('DOMContentLoaded', () => {
            // Auto open broadcast if view_broadcast parameter is present in URL
            const urlParams = new URLSearchParams(window.location.search);
            const viewBcId = urlParams.get('view_broadcast');
            if (viewBcId) {
                // Remove view_broadcast parameter from URL without reloading page
                const cleanSearch = window.location.search.replace(/[?&]view_broadcast=[^&]+/,'').replace(/^&/,'?').replace(/^\?/,'');
                const newUrl = window.location.pathname + (cleanSearch ? '?' + cleanSearch : '');
                window.history.replaceState({}, '', newUrl);
                
                viewBroadcastDetails(viewBcId);
            }

            // Move modals to body to escape transform container contexts that break position: fixed
            const moveModalsToBody = () => {
                document.querySelectorAll('.mo, .fixed.inset-0, .fixed[id*="Modal"], .fixed[id*="modal"]').forEach(modal => {
                    if (modal.classList.contains('swal2-container')) return;
                    if (modal.parentElement !== document.body) {
                        document.body.appendChild(modal);
                    }
                });
            };
            moveModalsToBody();
            setTimeout(moveModalsToBody, 100);
        });
    </script>
</body>
</html>
