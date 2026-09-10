<?php
/**
 * pages/admin_analytics.php
 * Premium Interactive Tracer Study Analytics Dashboard
 */

// Data untuk kontrol penyaringan kohort. Indikator akreditasi selalu
// dilaporkan per angkatan dan per program studi, sehingga dasbor global
// saja tidak memadai.
$filter_years  = $pdo->query(
    "SELECT DISTINCT graduation_year FROM users
     WHERE role='alumni' AND graduation_year IS NOT NULL
     ORDER BY graduation_year DESC"
)->fetchAll();
$filter_majors = $pdo->query("SELECT major_code, major_name FROM majors ORDER BY major_name ASC")->fetchAll();

// Auth check
$current_role = $_SESSION['user_role'] ?? 'alumni';
if (!isset($_SESSION['user_id']) || $current_role === 'alumni') {
    ?>
    <div class="max-w-2xl mx-auto px-4 py-16 text-center">
        <div class="glass p-12 rounded-[3rem] border border-white shadow-xl relative overflow-hidden">
            <div class="absolute -right-10 -top-10 w-40 h-40 bg-red-50 rounded-full blur-2xl pointer-events-none"></div>
            <div class="w-24 h-24 bg-red-100 text-red-600 rounded-3xl flex items-center justify-center mx-auto mb-8 shadow-inner border border-red-200">
                <i data-lucide="shield-alert" class="w-12 h-12"></i>
            </div>
            <h2 class="text-3xl font-black outfit text-slate-800 mb-4 tracking-tight">Akses Ditolak</h2>
            <p class="text-slate-500 mb-8 max-w-md mx-auto leading-relaxed text-sm">Maaf, halaman ini hanya dapat diakses oleh Administrator Tracer Study.</p>
            <a href="index.php?page=dashboard" class="inline-flex items-center gap-3 px-8 py-4 bg-slate-800 text-white rounded-2xl font-bold text-sm shadow-lg hover:bg-slate-900 transition-all active:scale-95">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                Kembali ke Dashboard
            </a>
        </div>
    </div>
    <script>lucide.createIcons();</script>
    <?php
    exit();
}
?>

<!-- ApexCharts Library -->
<script src="assets/js/apexcharts.min.js"></script>

<div class="max-w-6xl mx-auto px-4 md:px-0 space-y-8 pb-12">
    <!-- Header -->
    <header class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-6">
        <div>
            <h1 class="text-3xl font-black outfit text-slate-800 flex items-center gap-3">
                <span class="p-3 bg-gradient-to-br from-indigo-500 to-violet-600 text-white rounded-2xl shadow-lg shadow-indigo-200">
                    <i data-lucide="line-chart" class="w-6 h-6"></i>
                </span>
                Analitik Tracer Alumni
            </h1>
            <p class="text-slate-500 mt-2 font-medium">Dashboard visualisasi hasil kuesioner tracer study dan sebaran kerja alumni</p>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
            <!-- Link to AI Insights -->
            <?php if ($current_role === 'super_admin'): ?>
                <a href="index.php?page=admin_ai_insights" class="px-5 py-3.5 bg-gradient-to-r from-indigo-600 to-violet-600 text-white rounded-2xl font-black text-xs uppercase tracking-wider shadow-lg shadow-indigo-200 hover:opacity-95 transition-opacity flex items-center gap-2">
                    <i data-lucide="sparkles" class="w-4 h-4"></i>
                    AI Insights Laporan
                </a>
            <?php endif; ?>
            <a href="index.php?page=admin_tracer" class="px-5 py-3.5 bg-white text-slate-600 rounded-2xl font-black text-xs uppercase tracking-wider border border-slate-200 hover:bg-slate-50 transition-all flex items-center gap-2">
                <i data-lucide="table" class="w-4 h-4"></i>
                Daftar Responden
            </a>
        </div>
    </header>

    <!-- Loading Skeleton Wrapper -->
    <!-- Penyaring kohort -->
    <div class="glass p-5 rounded-3xl border border-white shadow-sm mb-8 flex flex-col md:flex-row md:items-end gap-4">
        <div class="flex-1 min-w-[160px]">
            <label for="f-year" class="block text-[11px] font-black uppercase tracking-widest text-slate-400 mb-2">Angkatan</label>
            <select id="f-year" class="w-full px-4 py-3 rounded-2xl bg-white border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold">
                <option value="">Semua Angkatan</option>
                <?php foreach ($filter_years as $y): ?>
                    <option value="<?php echo (int)$y->graduation_year; ?>"><?php echo (int)$y->graduation_year; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[200px]">
            <label for="f-major" class="block text-[11px] font-black uppercase tracking-widest text-slate-400 mb-2">Program Studi</label>
            <select id="f-major" class="w-full px-4 py-3 rounded-2xl bg-white border border-slate-200 focus:border-blue-500 outline-none text-sm font-semibold">
                <option value="">Semua Prodi</option>
                <?php foreach ($filter_majors as $m): ?>
                    <option value="<?php echo htmlspecialchars($m->major_code); ?>"><?php echo htmlspecialchars($m->major_name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="button" id="f-reset" class="px-5 py-3 rounded-2xl bg-white border border-slate-200 text-slate-600 font-bold text-sm hover:bg-slate-50 transition-all active:scale-95">
            Reset
        </button>
    </div>

    <div id="analytics-loading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <?php for($i = 0; $i < 4; $i++): ?>
            <div class="glass p-6 rounded-[2.5rem] border border-white/60 animate-pulse space-y-4">
                <div class="w-10 h-10 bg-slate-200 rounded-xl"></div>
                <div class="h-4 bg-slate-200 rounded w-1/2"></div>
                <div class="h-8 bg-slate-200 rounded w-3/4"></div>
            </div>
        <?php endfor; ?>
    </div>

    <!-- Real Dashboard Content (Hidden by default, shown via JS) -->
    <div id="analytics-content" class="hidden space-y-8">
        <!-- KPI Cards Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <!-- KPI 1 -->
            <div class="glass p-6 md:p-8 rounded-[2.5rem] border border-white shadow-sm relative overflow-hidden group">
                <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-blue-500/5 rounded-full group-hover:scale-150 transition-all duration-700"></div>
                <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mb-5 shadow-inner">
                    <i data-lucide="users" class="w-6 h-6"></i>
                </div>
                <h3 class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-1">Total Responden</h3>
                <p id="kpi-respondents" class="text-3xl font-black text-slate-800 outfit mb-1">-</p>
                <p id="kpi-respondents-note" class="text-[11px] font-semibold text-slate-400 mb-1"></p>
                <p class="text-[10px] font-bold text-blue-600/70 uppercase tracking-wider">Hasil Tracer Terhimpun</p>
            </div>

            <!-- KPI 2 -->
            <div class="glass p-6 md:p-8 rounded-[2.5rem] border border-white shadow-sm relative overflow-hidden group">
                <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-emerald-500/5 rounded-full group-hover:scale-150 transition-all duration-700"></div>
                <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center mb-5 shadow-inner">
                    <i data-lucide="check-circle" class="w-6 h-6"></i>
                </div>
                <h3 class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-1">Keselarasan Kerja</h3>
                <p id="kpi-alignment" class="text-3xl font-black text-slate-800 outfit mb-2">-</p>
                <p class="text-[10px] font-bold text-emerald-600/70 uppercase tracking-wider">Sangat Relevan & Relevan</p>
            </div>

            <!-- KPI 3 -->
            <div class="glass p-6 md:p-8 rounded-[2.5rem] border border-white shadow-sm relative overflow-hidden group">
                <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-amber-500/5 rounded-full group-hover:scale-150 transition-all duration-700"></div>
                <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-2xl flex items-center justify-center mb-5 shadow-inner">
                    <i data-lucide="banknote" class="w-6 h-6"></i>
                </div>
                <h3 class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-1">Modus Pendapatan</h3>
                <p id="kpi-salary" class="text-lg font-black text-slate-800 outfit mb-4 truncate">-</p>
                <p class="text-[10px] font-bold text-amber-600/70 uppercase tracking-wider">Modus Terbanyak Responden</p>
            </div>

            <!-- KPI 4 -->
            <div class="glass p-6 md:p-8 rounded-[2.5rem] border border-white shadow-sm relative overflow-hidden group">
                <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-violet-500/5 rounded-full group-hover:scale-150 transition-all duration-700"></div>
                <div class="w-12 h-12 bg-violet-50 text-violet-600 rounded-2xl flex items-center justify-center mb-5 shadow-inner">
                    <i data-lucide="percent" class="w-6 h-6"></i>
                </div>
                <h3 class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] mb-1">Respons Rate</h3>
                <p id="kpi-rate" class="text-3xl font-black text-slate-800 outfit mb-1">-</p>
                <p id="kpi-rate-note" class="text-[11px] font-semibold text-slate-400 mb-1"></p>
                <p class="text-[10px] font-bold text-violet-600/70 uppercase tracking-wider">Dari Total Alumni Terverifikasi</p>
            </div>
        </div>

        <!-- Charts Grid 1: Status Karir (Donut) & Relevansi Bidang (Pie) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Career Status Card -->
            <div class="glass p-6 md:p-8 rounded-[2.5rem] border border-white shadow-sm flex flex-col bg-white">
                <div class="flex items-center gap-3 mb-6 shrink-0">
                    <div class="w-1.5 h-6 bg-indigo-500 rounded-full"></div>
                    <h2 class="text-lg font-black outfit text-slate-800">Status Pekerjaan Alumni</h2>
                </div>
                <div class="flex-1 flex items-center justify-center min-h-[300px]">
                    <div id="chart-career" class="w-full"></div>
                </div>
            </div>

            <!-- Field Relevance Card -->
            <div class="glass p-6 md:p-8 rounded-[2.5rem] border border-white shadow-sm flex flex-col bg-white">
                <div class="flex items-center gap-3 mb-6 shrink-0">
                    <div class="w-1.5 h-6 bg-indigo-500 rounded-full"></div>
                    <h2 class="text-lg font-black outfit text-slate-800">Kesesuaian Bidang Ilmu</h2>
                </div>
                <div class="flex-1 flex items-center justify-center min-h-[300px]">
                    <div id="chart-relevance" class="w-full"></div>
                </div>
            </div>
        </div>

        <!-- Charts Grid 2: Rentang Gaji (Bar) & Tren Pengisian (Area) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Salary Distribution Card -->
            <div class="glass p-6 md:p-8 rounded-[2.5rem] border border-white shadow-sm flex flex-col bg-white">
                <div class="flex items-center gap-3 mb-6 shrink-0">
                    <div class="w-1.5 h-6 bg-indigo-500 rounded-full"></div>
                    <h2 class="text-lg font-black outfit text-slate-800">Distribusi Pendapatan Bulanan</h2>
                </div>
                <div class="flex-1 min-h-[300px]">
                    <div id="chart-salary" class="w-full"></div>
                </div>
            </div>

            <!-- Monthly Submission Trend Card -->
            <div class="glass p-6 md:p-8 rounded-[2.5rem] border border-white shadow-sm flex flex-col bg-white">
                <div class="flex items-center gap-3 mb-6 shrink-0">
                    <div class="w-1.5 h-6 bg-indigo-500 rounded-full"></div>
                    <h2 class="text-lg font-black outfit text-slate-800">Tren Respons Kuesioner (6 Bulan Terakhir)</h2>
                </div>
                <div class="flex-1 min-h-[300px]">
                    <div id="chart-trend" class="w-full"></div>
                </div>
            </div>
        </div>

        <!-- Full Width Grid: Major distribution (Column) -->
        <div class="glass p-6 md:p-8 rounded-[2.5rem] border border-white shadow-sm bg-white">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-1.5 h-6 bg-indigo-500 rounded-full"></div>
                <h2 class="text-lg font-black outfit text-slate-800">Sebaran Responden per Program Studi</h2>
            </div>
            <div class="w-full min-h-[320px]">
                <div id="chart-majors" class="w-full"></div>
            </div>
        </div>
    </div>
</div>

<script>
// Grafik disimpan agar dapat dimusnahkan sebelum digambar ulang.
// Tanpa ini, setiap pergantian filter akan menumpuk instans ApexCharts baru
// di atas yang lama.
let chartInstances = [];

function destroyCharts() {
    chartInstances.forEach(c => { try { c.destroy(); } catch (e) {} });
    chartInstances = [];
}

function currentFilterQuery() {
    const p = new URLSearchParams();
    const y = document.getElementById('f-year');
    const m = document.getElementById('f-major');
    if (y && y.value) p.set('graduation_year', y.value);
    if (m && m.value) p.set('major', m.value);
    const q = p.toString();
    return q ? '?' + q : '';
}

function loadAnalytics() {
    destroyCharts();
    document.getElementById('analytics-loading').classList.remove('hidden');
    document.getElementById('analytics-content').classList.add('hidden');

    fetch('api/admin/tracer_analytics.php' + currentFilterQuery())
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                showSwalAlert('Error', 'Gagal memuat data analitik: ' + (data.error || 'Terjadi kesalahan.'), 'error');
                return;
            }

            // 2. Hide loading skeleton, show real dashboard content
            document.getElementById('analytics-loading').classList.add('hidden');
            const content = document.getElementById('analytics-content');
            content.classList.remove('hidden');
            content.classList.add('animate-fade');

            // 3. Populate KPI cards
            //
            // total_respondents kini berisi responden UNIK (bukan jumlah
            // submission), sehingga alumnus yang mengisi berulang kali tidak
            // membuat response rate melampaui 100% seperti sebelumnya.
            document.getElementById('kpi-respondents').textContent = data.total_respondents.toLocaleString('id-ID');
            document.getElementById('kpi-alignment').textContent = data.alignment_rate + '%';
            document.getElementById('kpi-salary').textContent = data.max_salary_range;

            // Tampilkan selisih pengisian berulang bila ada, supaya angkanya
            // transparan dan tidak dikira data hilang.
            const noteEl = document.getElementById('kpi-respondents-note');
            if (noteEl) {
                const ulang = data.total_submissions - data.total_respondents;
                noteEl.textContent = ulang > 0
                    ? `${data.total_submissions} pengisian (${ulang} pengisian ulang)`
                    : `${data.total_submissions} pengisian`;
            }

            const responseRate = data.total_verified_alumni > 0
                ? Math.round((data.total_respondents / data.total_verified_alumni) * 100)
                : 0;
            document.getElementById('kpi-rate').textContent = responseRate + '%';

            const rateNote = document.getElementById('kpi-rate-note');
            if (rateNote) {
                rateNote.textContent = `${data.total_respondents} dari ${data.total_verified_alumni} alumni`;
            }

            // 4. Render Career Status Donut Chart
            chartInstances.push(new ApexCharts(document.querySelector("#chart-career"), {
                chart: { type: 'donut', height: 320, fontFamily: 'inherit' },
                labels: data.career_status.labels,
                series: data.career_status.series,
                colors: ['#6366f1', '#10b981', '#f59e0b', '#ef4444', '#64748b'],
                plotOptions: {
                    pie: {
                        donut: {
                            size: '70%',
                            labels: {
                                show: true,
                                total: {
                                    show: true,
                                    label: 'Responden',
                                    formatter: () => data.total_respondents
                                }
                            }
                        }
                    }
                },
                legend: { position: 'bottom' },
                dataLabels: { enabled: true, formatter: (val) => val.toFixed(1) + '%' }
            }));
            chartInstances[chartInstances.length - 1].render();

            // 5. Render Field Relevance Pie Chart
            chartInstances.push(new ApexCharts(document.querySelector("#chart-relevance"), {
                chart: { type: 'pie', height: 320, fontFamily: 'inherit' },
                labels: data.relevance.labels,
                series: data.relevance.series,
                colors: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#94a3b8'],
                legend: { position: 'bottom' },
                dataLabels: { enabled: true, formatter: (val) => val.toFixed(1) + '%' }
            }));
            chartInstances[chartInstances.length - 1].render();

            // 6. Render Salary Horizontal Bar Chart
            chartInstances.push(new ApexCharts(document.querySelector("#chart-salary"), {
                chart: { type: 'bar', height: 300, fontFamily: 'inherit', toolbar: { show: false } },
                plotOptions: {
                    bar: {
                        borderRadius: 8,
                        horizontal: true,
                        barHeight: '60%',
                        distributed: true
                    }
                },
                colors: ['#3b82f6', '#6366f1', '#8b5cf6', '#a855f7', '#ec4899'],
                series: [{
                    name: 'Jumlah Responden',
                    data: data.salary.series
                }],
                xaxis: {
                    categories: data.salary.labels,
                    labels: { style: { colors: '#64748b' } }
                },
                yaxis: {
                    labels: { style: { colors: '#64748b', fontWeight: 600 } }
                },
                dataLabels: { enabled: true, align: 'right' },
                legend: { show: false }
            }));
            chartInstances[chartInstances.length - 1].render();

            // 7. Render Monthly Trend Area Chart
            chartInstances.push(new ApexCharts(document.querySelector("#chart-trend"), {
                chart: { type: 'area', height: 300, fontFamily: 'inherit', toolbar: { show: false } },
                stroke: { curve: 'smooth', width: 3 },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.45,
                        opacityTo: 0.05,
                        stops: [0, 100]
                    }
                },
                colors: ['#6366f1'],
                series: [{
                    name: 'Respons',
                    data: data.monthly_trend.series
                }],
                xaxis: {
                    categories: data.monthly_trend.labels,
                    labels: { style: { colors: '#64748b' } }
                },
                yaxis: {
                    labels: { style: { colors: '#64748b' } }
                },
                dataLabels: { enabled: false }
            }));
            chartInstances[chartInstances.length - 1].render();

            // 8. Render Majors Column Chart
            chartInstances.push(new ApexCharts(document.querySelector("#chart-majors"), {
                chart: { type: 'bar', height: 320, fontFamily: 'inherit', toolbar: { show: false } },
                plotOptions: {
                    bar: {
                        borderRadius: 8,
                        columnWidth: '45%',
                        distributed: true
                    }
                },
                colors: ['#6366f1', '#0ea5e9', '#8b5cf6', '#10b981'],
                series: [{
                    name: 'Responden',
                    data: data.major_distribution.series
                }],
                xaxis: {
                    categories: data.major_distribution.labels,
                    labels: {
                        style: { colors: '#64748b', fontWeight: 600 }
                    }
                },
                yaxis: {
                    labels: { style: { colors: '#64748b' } }
                },
                dataLabels: { enabled: true },
                legend: { show: false }
            }));
            chartInstances[chartInstances.length - 1].render();

            // Recenter icons inside dynamic dashboard
            if (window.lucide) window.lucide.createIcons();
        })
        .catch(err => {
            showSwalAlert('Error Koneksi', 'Gagal menghubungi server analitik: ' + err.message, 'error');
        });
}

document.addEventListener('DOMContentLoaded', () => {
    ['f-year', 'f-major'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', loadAnalytics);
    });

    const reset = document.getElementById('f-reset');
    if (reset) {
        reset.addEventListener('click', () => {
            const y = document.getElementById('f-year');
            const m = document.getElementById('f-major');
            if (y) y.value = '';
            if (m) m.value = '';
            loadAnalytics();
        });
    }

    loadAnalytics();
});
</script>
