<?php
// Filters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$relevance = $_GET['relevance'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Build Query for Latest Data
$base_query = " FROM tracer_submissions ts JOIN users u ON ts.user_id = u.id WHERE 1=1";
$params = [];

if ($search) {
    $base_query .= " AND (u.name LIKE ? OR u.nim LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status) {
    $base_query .= " AND ts.work_status = ?";
    $params[] = $status;
}
if ($relevance) {
    $base_query .= " AND ts.field_relevance = ?";
    $params[] = $relevance;
}
if ($start_date) {
    $base_query .= " AND DATE(ts.created_at) >= ?";
    $params[] = $start_date;
}
if ($end_date) {
    $base_query .= " AND DATE(ts.created_at) <= ?";
    $params[] = $end_date;
}

// Helper to generate pagination URLs
if (!function_exists('getPaginationUrl')) {
    function getPaginationUrl($page_num, $limit, $search, $status, $relevance, $start_date, $end_date) {
        return 'index.php?' . http_build_query([
            'page' => 'admin_tracer',
            'search' => $search,
            'status' => $status,
            'relevance' => $relevance,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'limit' => $limit,
            'p' => $page_num
        ]);
    }
}

// 1. Get total records for pagination
$count_stmt = $pdo->prepare("SELECT COUNT(*) " . $base_query);
$count_stmt->execute($params);
$total_records = (int)$count_stmt->fetchColumn();

// 2. Pagination parameters
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit < 5) $limit = 10;
$total_pages = ceil($total_records / $limit);
if ($total_pages < 1) $total_pages = 1;

$p = isset($_GET['p']) ? (int)$_GET['p'] : 1;
if ($p < 1) $p = 1;
if ($p > $total_pages) $p = $total_pages;

$offset = ($p - 1) * $limit;;

// Fetch all questions for detail mapping and dynamic options
$all_questions_stmt = $pdo->query("SELECT id, question_text, question_type, options, is_active, mapping_key FROM tracer_questions ORDER BY order_no ASC");
$all_questions_list = $all_questions_stmt->fetchAll(PDO::FETCH_ASSOC);
$questions_map_json = json_encode($all_questions_list);

// Build map of mapping_key => question_id
$mappings = [];
foreach ($all_questions_list as $q) {
    if (!empty($q['mapping_key'])) {
        $mappings[$q['mapping_key']] = $q['id'];
    }
}

// Fetch active mapped questions for table columns
$active_mapped_questions = [];
foreach ($all_questions_list as $q) {
    if ($q['is_active'] && !empty($q['mapping_key'])) {
        $active_mapped_questions[] = $q;
    }
}

// Helper functions for matching and normalizing responses
if (!function_exists('normalize_and_match_status')) {
    function normalize_and_match_status($val, $options) {
        if (empty($val)) return null;
        $val_clean = trim(strtolower($val));
        
        // Exact match
        foreach ($options as $opt) {
            if (trim(strtolower($opt)) === $val_clean) return $opt;
        }
        
        // Alphanumeric match
        $val_alpha = preg_replace('/[^a-z0-9]/', '', $val_clean);
        foreach ($options as $opt) {
            $opt_alpha = preg_replace('/[^a-z0-9]/', '', trim(strtolower($opt)));
            if ($opt_alpha === $val_alpha) return $opt;
        }
        
        // Substring match
        foreach ($options as $opt) {
            $opt_alpha = preg_replace('/[^a-z0-9]/', '', trim(strtolower($opt)));
            if (strpos($opt_alpha, $val_alpha) !== false || strpos($val_alpha, $opt_alpha) !== false) return $opt;
        }
        
        // Keyword fallback
        foreach ($options as $opt) {
            $opt_clean = trim(strtolower($opt));
            if (strpos($val_clean, 'bekerja') !== false && strpos($opt_clean, 'bekerja') !== false) return $opt;
            if (strpos($val_clean, 'wira') !== false && strpos($opt_clean, 'wira') !== false) return $opt;
            if ((strpos($val_clean, 'studi') !== false || strpos($val_clean, 'sekolah') !== false) && strpos($opt_clean, 'studi') !== false) return $opt;
            if (strpos($val_clean, 'mencari') !== false && strpos($opt_clean, 'mencari') !== false) return $opt;
        }
        return null;
    }
}

if (!function_exists('normalize_and_match_relevance')) {
    function normalize_and_match_relevance($val, $options) {
        if (empty($val)) return null;
        $val_clean = trim(strtolower($val));
        
        // Exact match
        foreach ($options as $opt) {
            if (trim(strtolower($opt)) === $val_clean) return $opt;
        }
        
        // Alphanumeric match
        $val_alpha = preg_replace('/[^a-z0-9]/', '', $val_clean);
        foreach ($options as $opt) {
            $opt_alpha = preg_replace('/[^a-z0-9]/', '', trim(strtolower($opt)));
            if ($opt_alpha === $val_alpha) return $opt;
        }
        
        // Substring match
        foreach ($options as $opt) {
            $opt_alpha = preg_replace('/[^a-z0-9]/', '', trim(strtolower($opt)));
            if (strpos($opt_alpha, $val_alpha) !== false || strpos($val_alpha, $opt_alpha) !== false) return $opt;
        }
        
        // Legacy DB mapping
        if ($val_clean === 'high') {
            foreach ($options as $opt) {
                if (stripos($opt, 'sangat') !== false) return $opt;
            }
            return $options[0] ?? null;
        }
        if ($val_clean === 'medium') {
            foreach ($options as $opt) {
                if (stripos($opt, 'relevan') !== false && stripos($opt, 'sangat') === false && stripos($opt, 'tidak') === false) return $opt;
            }
            return $options[1] ?? $options[0] ?? null;
        }
        if ($val_clean === 'low') {
            foreach ($options as $opt) {
                if (stripos($opt, 'tidak') !== false || stripos($opt, 'kurang') !== false) return $opt;
            }
            return end($options) ?: null;
        }
        return null;
    }
}

// Find questions for stats
$work_status_q = null;
foreach ($all_questions_list as $q) {
    if ($q['is_active'] && $q['mapping_key'] === 'work_status') {
        $work_status_q = $q;
        break;
    }
}
$work_status_opts = [];
if ($work_status_q && !empty($work_status_q['options'])) {
    $work_status_opts = json_decode($work_status_q['options'], true);
}
if (empty($work_status_opts)) {
    $work_status_opts = ['Bekerja Full-time', 'Wirausaha', 'Studi Lanjut (Spesialis/S2)', 'Mencari Kerja'];
}

$field_relevance_q = null;
foreach ($all_questions_list as $q) {
    if ($q['is_active'] && $q['mapping_key'] === 'field_relevance') {
        $field_relevance_q = $q;
        break;
    }
}
$field_relevance_opts = [];
if ($field_relevance_q && !empty($field_relevance_q['options'])) {
    $field_relevance_opts = json_decode($field_relevance_q['options'], true);
}
if (empty($field_relevance_opts)) {
    $field_relevance_opts = ['Sangat Relevan', 'Relevan', 'Cukup Relevan', 'Tidak Relevan'];
}

// Fetch all filtered rows for statistics aggregation (unlimited)
$stats_stmt = $pdo->prepare("SELECT ts.* " . $base_query);
$stats_stmt->execute($params);
$all_filtered = $stats_stmt->fetchAll();

// Fetch Paginated Submissions for display
$latest_stmt = $pdo->prepare("SELECT ts.*, u.name as user_name, u.nim as user_nim " . $base_query . " ORDER BY ts.created_at DESC LIMIT " . $limit . " OFFSET " . $offset);
$latest_stmt->execute($params);
$latest = $latest_stmt->fetchAll();

// Dynamic Agregations
$dynamic_status_counts = [];
foreach ($work_status_opts as $opt) {
    $dynamic_status_counts[$opt] = 0;
}
$dynamic_relevance_counts = [];
foreach ($field_relevance_opts as $opt) {
    $dynamic_relevance_counts[$opt] = 0;
}

foreach ($all_filtered as $l) {
    $resp = json_decode($l->responses, true) ?? [];
    
    // Status
    $s_val = null;
    if ($work_status_q) {
        $s_val = $resp['q_' . $work_status_q['id']] ?? null;
    }
    if (empty($s_val)) $s_val = $l->work_status;
    if (empty($s_val) && isset($resp['q_1'])) $s_val = $resp['q_1'];
    
    $matched_status = normalize_and_match_status($s_val, $work_status_opts);
    if ($matched_status !== null) {
        $dynamic_status_counts[$matched_status]++;
    }
    
    // Relevance
    $r_val = null;
    if ($field_relevance_q) {
        $r_val = $resp['q_' . $field_relevance_q['id']] ?? null;
    }
    if (empty($r_val)) $r_val = $l->field_relevance;
    if (empty($r_val) && isset($resp['q_5'])) $r_val = $resp['q_5'];
    
    $matched_relevance = normalize_and_match_relevance($r_val, $field_relevance_opts);
    if ($matched_relevance !== null) {
        $dynamic_relevance_counts[$matched_relevance]++;
    }
}

// Prepare Export Link with current filters
$export_params = http_build_query([
    'type' => 'tracer',
    'search' => $search,
    'status' => $status,
    'relevance' => $relevance,
    'start_date' => $start_date,
    'end_date' => $end_date
]);
?>

<div class="max-w-6xl mx-auto px-4 md:px-0">
    <div class="mb-8 px-1">
        <h1 class="text-xl md:text-2xl font-bold outfit text-slate-800 tracking-tight">Laporan Tracer Alumni</h1>
        <p class="text-slate-400 text-xs md:text-sm mt-1 font-medium">Analisis sebaran karir dan relevansi kurikulum bagi alumni.</p>
    </div>
    
    <!-- Charts Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-12">
        <!-- Status Pekerjaan Card -->
        <div class="glass p-8 md:p-10 rounded-[3rem] border border-white shadow-sm relative overflow-hidden group">
            <div class="absolute -right-10 -top-10 w-32 h-32 bg-blue-500/5 rounded-full group-hover:scale-150 transition-all duration-1000"></div>
            <h2 class="text-xl font-black outfit mb-8 text-slate-800 uppercase tracking-tight flex items-center gap-3">
                <span class="w-2 h-8 bg-blue-600 rounded-full"></span>
                Status Pekerjaan
            </h2>
            <div class="space-y-6">
                <?php 
                $visible_status_counts = array_filter($dynamic_status_counts, function($count) { return $count > 0; });
                if (empty($visible_status_counts)) {
                    echo '<div class="text-center py-10"><p class="text-slate-400 text-sm font-medium">Belum ada data status pekerjaan.</p></div>';
                } else {
                    $total_responses = array_sum($visible_status_counts);
                    $colors = ['blue', 'indigo', 'purple', 'emerald'];
                    $i = 0;
                    foreach ($visible_status_counts as $status_label => $count): 
                        $color = $colors[$i % count($colors)];
                        $percent = ($total_responses > 0) ? ($count / $total_responses) * 100 : 0;
                        $i++;
                    ?>
                        <div class="group/bar">
                            <div class="flex justify-between text-xs font-black uppercase tracking-widest mb-2">
                                <span class="text-slate-500"><?php echo htmlspecialchars($status_label); ?></span>
                                <span class="text-slate-800"><?php echo e($count); ?> Alumni</span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-2xl h-4 overflow-hidden border border-slate-50">
                                <div class="bg-gradient-to-r from-<?php echo e($color); ?>-500 to-<?php echo e($color); ?>-400 h-full rounded-2xl shadow-[0_0_15px_rgba(59,130,246,0.3)] transition-all duration-1000 group-hover/bar:scale-x-105 origin-left" style="width: <?php echo e($percent); ?>%"></div>
                            </div>
                        </div>
                    <?php 
                    endforeach;
                }
                ?>
            </div>
        </div>

        <!-- Relevansi Bidang Ilmu Card -->
        <div class="glass p-8 md:p-10 rounded-[3rem] border border-white shadow-sm relative overflow-hidden group">
            <div class="absolute -left-10 -top-10 w-32 h-32 bg-purple-500/5 rounded-full group-hover:scale-150 transition-all duration-1000"></div>
            <h2 class="text-xl font-black outfit mb-10 text-slate-800 uppercase tracking-tight flex items-center gap-3">
                <span class="w-2 h-8 bg-purple-600 rounded-full"></span>
                Relevansi Bidang Ilmu
            </h2>
            <div class="flex items-end justify-around gap-4 h-48 pb-2">
                <?php 
                $visible_relevance_counts = array_filter($dynamic_relevance_counts, function($count) { return $count > 0; });
                if (empty($visible_relevance_counts)) {
                    echo '<div class="text-center py-12 w-full"><p class="text-slate-400 text-sm font-medium">Belum ada data relevansi pekerjaan.</p></div>';
                } else {
                    $max_count = max(array_values($visible_relevance_counts));
                    if ($max_count == 0) $max_count = 1;
                    foreach ($visible_relevance_counts as $label => $count): 
                        $h_percent = ($count / $max_count) * 100;
                        
                        // Simple short label for the bar chart
                        $short_label = $label;
                        if (strlen($short_label) > 12) {
                            $short_label = mb_substr($short_label, 0, 10) . '..';
                        }
                    ?>
                        <div class="flex-1 flex flex-col items-center gap-4 group/col h-full justify-end" title="<?php echo htmlspecialchars($label); ?>">
                            <div class="relative w-full max-w-[50px] bg-gradient-to-t from-purple-600/80 to-blue-500/80 rounded-2xl shadow-lg transition-all duration-700 hover:-translate-y-2 group-hover/col:shadow-purple-200" 
                                 style="height: <?php echo max($h_percent, 10); ?>%">
                                <div class="absolute -top-10 left-1/2 -translate-x-1/2 bg-slate-900 text-white text-[10px] font-black px-3 py-1.5 rounded-xl opacity-0 group-hover/col:opacity-100 transition-all pointer-events-none whitespace-nowrap">
                                    <?php echo e($count); ?>
                                </div>
                            </div>
                            <span class="text-[9px] font-black text-slate-400 uppercase tracking-[0.1em] text-center"><?php echo htmlspecialchars($short_label); ?></span>
                        </div>
                    <?php 
                    endforeach;
                }
                ?>
            </div>
        </div>
    </div>

    <!-- Filter Bar (Multilevel) -->
    <div class="glass p-6 rounded-[2.5rem] shadow-sm mb-10 border border-white/50">
        <form action="index.php" method="GET" class="space-y-4">
            <input type="hidden" name="page" value="admin_tracer">
            
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="md:col-span-2 relative">
                    <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                    <input aria-label="Cari Nama atau NIM" type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari Nama atau NIM..." class="w-full pl-11 pr-5 py-3.5 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-xs transition-all shadow-inner">
                </div>
                <select aria-label="Filter Status Kerja" name="status" class="px-5 py-3.5 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-xs appearance-none cursor-pointer shadow-inner text-slate-600 font-bold">
                    <option value="">Semua Status Kerja</option>
                    <option value="bekerja" <?php echo $status == 'bekerja' ? 'selected' : ''; ?>>Bekerja</option>
                    <option value="wiraswasta" <?php echo $status == 'wiraswasta' ? 'selected' : ''; ?>>Wiraswasta</option>
                    <option value="studi_lanjut" <?php echo $status == 'studi_lanjut' ? 'selected' : ''; ?>>Studi Lanjut</option>
                    <option value="mencari_kerja" <?php echo $status == 'mencari_kerja' ? 'selected' : ''; ?>>Mencari Kerja</option>
                </select>
                <select aria-label="Filter Relevansi" name="relevance" class="px-5 py-3.5 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-xs appearance-none cursor-pointer shadow-inner text-slate-600 font-bold">
                    <option value="">Semua Relevansi</option>
                    <option value="high" <?php echo $relevance == 'high' ? 'selected' : ''; ?>>High Relevance</option>
                    <option value="medium" <?php echo $relevance == 'medium' ? 'selected' : ''; ?>>Medium Relevance</option>
                    <option value="low" <?php echo $relevance == 'low' ? 'selected' : ''; ?>>Low Relevance</option>
                </select>
            </div>

            <div class="flex flex-col md:flex-row items-center justify-between gap-4 pt-4 border-t border-slate-50">
                <div class="flex items-center gap-3 w-full md:w-auto">
                    <div class="flex-1 md:flex-initial">
                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1.5 ml-1">Dari Tanggal</p>
                        <input aria-label="Tanggal mulai" type="date" name="start_date" value="<?php echo e($start_date); ?>" class="w-full px-4 py-2.5 rounded-xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-[11px] font-bold text-slate-600">
                    </div>
                    <div class="flex-1 md:flex-initial">
                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mb-1.5 ml-1">Sampai Tanggal</p>
                        <input aria-label="Tanggal akhir" type="date" name="end_date" value="<?php echo e($end_date); ?>" class="w-full px-4 py-2.5 rounded-xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-[11px] font-bold text-slate-600">
                    </div>
                    <button type="submit" class="mt-5 bg-slate-800 text-white p-3 rounded-xl hover:bg-slate-900 transition-all shadow-lg shadow-slate-200">
                        <i data-lucide="filter" class="w-4 h-4"></i>
                    </button>
                    <a href="index.php?page=admin_tracer" class="mt-5 bg-white border border-slate-200 text-slate-400 p-3 rounded-xl hover:text-slate-600 transition-all">
                        <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                    </a>
                </div>

                <div class="flex items-center gap-3 w-full md:w-auto">
                    <?php if (($_SESSION['user_role'] ?? '') === 'super_admin'): ?>
                    <a href="index.php?page=admin_ai_insights" class="flex-1 md:flex-initial flex items-center justify-center gap-3 bg-indigo-600 text-white px-6 py-3.5 rounded-2xl font-black uppercase tracking-widest text-[10px] shadow-xl shadow-indigo-200 hover:bg-indigo-700 transition-all group">
                        <i data-lucide="sparkles" class="w-4 h-4"></i>
                        AI Analytics
                    </a>
                    <?php endif; ?>
                    <a href="handlers/export_handler.php?<?php echo e($export_params); ?>" class="flex-1 md:flex-initial flex items-center justify-center gap-3 bg-emerald-600 text-white px-6 py-3.5 rounded-2xl font-black uppercase tracking-widest text-[10px] shadow-xl shadow-emerald-200 hover:bg-emerald-700 transition-all group">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        CSV
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Latest Data Table -->
    <div class="mb-6 flex items-center justify-between px-2">
        <h2 class="text-2xl font-black outfit text-slate-800 uppercase tracking-tight">Data Pengisian Terbaru</h2>
        <div class="w-10 h-10 bg-slate-50 rounded-xl flex items-center justify-center text-slate-300">
            <i data-lucide="list" class="w-5 h-5"></i>
        </div>
    </div>

    <!-- Mobile View (Cards) -->
    <div class="md:hidden space-y-6">
        <?php foreach ($latest as $l): 
            $resp = json_decode($l->responses, true) ?? [];
        ?>
        <div class="glass p-6 rounded-[2.5rem] border border-white shadow-sm relative overflow-hidden group">
            <div class="flex justify-between items-start mb-6">
                <div>
                    <p class="font-black text-slate-800 outfit text-lg"><?php echo e($l->user_name); ?></p>
                    <p class="text-[9px] font-bold text-slate-400 tracking-widest uppercase mt-1">NIM: <?php echo e($l->user_nim ?? '-'); ?></p>
                </div>
                <span class="text-[9px] font-black px-3 py-1.5 bg-blue-50 text-blue-600 rounded-xl uppercase tracking-widest border border-blue-100 shadow-sm">
                    <?php 
                    // Resolve status text
                    $s_val = null;
                    if ($work_status_q) {
                        $s_val = $resp['q_' . $work_status_q['id']] ?? null;
                    }
                    if (empty($s_val)) $s_val = $l->work_status;
                    if (empty($s_val) && isset($resp['q_1'])) $s_val = $resp['q_1'];
                    echo str_replace('_', ' ', htmlspecialchars($s_val));
                    ?>
                </span>
            </div>
            
            <div class="space-y-3 pt-6 border-t border-slate-50">
                <?php foreach ($active_mapped_questions as $amq): 
                    // Skip work_status as it is already shown in the top right badge
                    if ($amq['mapping_key'] === 'work_status') continue;
                    
                    $val = null;
                    $q_key = 'q_' . $amq['id'];
                    if (isset($resp[$q_key])) {
                        $val = $resp[$q_key];
                    }
                    if (empty($val)) {
                        $col_name = $amq['mapping_key'];
                        if (isset($l->$col_name)) {
                            $val = $l->$col_name;
                        }
                    }
                    if (empty($val)) {
                        if ($amq['mapping_key'] === 'company_name' && isset($resp['q_2'])) $val = $resp['q_2'];
                        elseif ($amq['mapping_key'] === 'job_title' && isset($resp['q_3'])) $val = $resp['q_3'];
                        elseif ($amq['mapping_key'] === 'salary_range' && isset($resp['q_4'])) $val = $resp['q_4'];
                        elseif ($amq['mapping_key'] === 'field_relevance' && isset($resp['q_5'])) $val = $resp['q_5'];
                    }
                    if (is_array($val)) $val = implode(', ', $val);
                    $display_val = !empty($val) ? htmlspecialchars($val) : '-';
                    
                    // Simple short title
                    $short_title = $amq['question_text'];
                    if (strlen($short_title) > 25) {
                        $short_title = mb_substr($short_title, 0, 22) . '...';
                    }
                ?>
                    <div class="flex justify-between items-center text-xs">
                        <span class="font-bold text-slate-400 uppercase tracking-wider text-[9px]" title="<?php echo htmlspecialchars($amq['question_text']); ?>"><?php echo htmlspecialchars($short_title); ?></span>
                        <span class="font-extrabold text-slate-700"><?php echo e($display_val); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="mt-6 pt-4 border-t border-slate-100 flex justify-between items-center text-[9px] font-bold text-slate-400 uppercase tracking-widest">
                <span><?php echo date('d M Y', strtotime($l->created_at)); ?></span>
                <button onclick='openDetailModal(<?php echo htmlspecialchars(json_encode($l), ENT_QUOTES, "UTF-8"); ?>)' class="text-[10px] font-black text-blue-600 bg-blue-50 px-4 py-2 rounded-xl hover:bg-blue-600 hover:text-white transition-all flex items-center gap-1.5">
                    <i data-lucide="eye" class="w-3.5 h-3.5"></i> Lihat Detail
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Desktop View (Table) -->
    <div class="hidden md:block glass rounded-[3rem] border border-white shadow-sm overflow-hidden">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-white/40 border-b border-slate-50">
                    <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Alumni</th>
                    <?php foreach ($active_mapped_questions as $amq): ?>
                        <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]" title="<?php echo htmlspecialchars($amq['question_text']); ?>">
                            <?php 
                            // Truncate the question text beautifully for header display
                            $q_text = $amq['question_text'];
                            if (strlen($q_text) > 30) {
                                echo htmlspecialchars(mb_substr($q_text, 0, 27)) . '...';
                            } else {
                                echo htmlspecialchars($q_text);
                            }
                            ?>
                        </th>
                    <?php endforeach; ?>
                    <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Tanggal</th>
                    <th class="px-8 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php foreach ($latest as $l): 
                    $resp = json_decode($l->responses, true) ?? [];
                ?>
                    <tr class="hover:bg-white/60 transition-all group">
                        <td class="px-8 py-6">
                            <p class="font-black text-slate-800 outfit text-base leading-tight group-hover:text-blue-600 transition-colors"><?php echo e($l->user_name); ?></p>
                            <p class="text-[9px] font-bold text-slate-400 tracking-widest uppercase mt-1 opacity-60"><?php echo e($l->user_nim ?? '-'); ?></p>
                        </td>
                        
                        <?php foreach ($active_mapped_questions as $amq): ?>
                            <td class="px-8 py-6">
                                <?php 
                                $val = null;
                                $q_key = 'q_' . $amq['id'];
                                
                                // 1. Check in JSON responses first
                                if (isset($resp[$q_key])) {
                                    $val = $resp[$q_key];
                                }
                                
                                // 2. Fallback to mapped DB column if JSON is empty or key is not set
                                if (empty($val)) {
                                    $col_name = $amq['mapping_key'];
                                    if (isset($l->$col_name)) {
                                        $val = $l->$col_name;
                                    }
                                }
                                
                                // 3. Fallback to legacy question IDs if applicable
                                if (empty($val)) {
                                    if ($amq['mapping_key'] === 'work_status' && isset($resp['q_1'])) {
                                        $val = $resp['q_1'];
                                    } elseif ($amq['mapping_key'] === 'company_name' && isset($resp['q_2'])) {
                                        $val = $resp['q_2'];
                                    } elseif ($amq['mapping_key'] === 'job_title' && isset($resp['q_3'])) {
                                        $val = $resp['q_3'];
                                    } elseif ($amq['mapping_key'] === 'salary_range' && isset($resp['q_4'])) {
                                        $val = $resp['q_4'];
                                    } elseif ($amq['mapping_key'] === 'field_relevance' && isset($resp['q_5'])) {
                                        $val = $resp['q_5'];
                                    }
                                }
                                
                                if (is_array($val)) {
                                    $val = implode(', ', $val);
                                }
                                
                                $display_val = !empty($val) ? htmlspecialchars($val) : '-';
                                
                                // Dynamic badges based on mapping key to match the premium styling!
                                if ($amq['mapping_key'] === 'work_status') {
                                    echo '<span class="text-xs font-black text-blue-600 bg-blue-50 px-3 py-1.5 rounded-xl border border-blue-100 shadow-sm uppercase">' . str_replace('_', ' ', $display_val) . '</span>';
                                } elseif ($amq['mapping_key'] === 'salary_range') {
                                    echo '<span class="text-xs font-black text-emerald-600 bg-emerald-50 px-3 py-1.5 rounded-xl border border-emerald-100 shadow-sm">' . $display_val . '</span>';
                                } elseif ($amq['mapping_key'] === 'field_relevance') {
                                    $rel_class = 'text-slate-500 bg-slate-50 border-slate-100';
                                    $lower_val = strtolower($display_val);
                                    if (strpos($lower_val, 'sangat') !== false || $lower_val === 'high') {
                                        $rel_class = 'text-purple-600 bg-purple-50 border-purple-100';
                                    } elseif ($lower_val === 'relevan' || $lower_val === 'cukup' || $lower_val === 'medium') {
                                        $rel_class = 'text-indigo-600 bg-indigo-50 border-indigo-100';
                                    }
                                    echo '<span class="text-xs font-bold ' . $rel_class . ' px-3 py-1.5 rounded-xl border shadow-sm">' . $display_val . '</span>';
                                } elseif ($amq['mapping_key'] === 'company_name') {
                                    echo '<span class="text-xs font-black text-slate-600 uppercase tracking-tighter bg-slate-50 px-3 py-1.5 rounded-xl border border-slate-100">' . $display_val . '</span>';
                                } else {
                                    echo '<span class="text-xs font-bold text-slate-500 outfit">' . $display_val . '</span>';
                                }
                                ?>
                            </td>
                        <?php endforeach; ?>
                        
                        <td class="px-8 py-6">
                            <span class="text-[10px] font-black text-slate-300 uppercase tracking-widest"><?php echo date('d M Y', strtotime($l->created_at)); ?></span>
                        </td>
                        <td class="px-8 py-6 text-center">
                            <button onclick='openDetailModal(<?php echo htmlspecialchars(json_encode($l), ENT_QUOTES, "UTF-8"); ?>)' class="p-2.5 bg-blue-50 text-blue-600 rounded-xl hover:bg-blue-600 hover:text-white transition-all shadow-sm transform hover:scale-105 duration-300" title="Lihat detail kuesioner">
                                <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls (Sleek Glassmorphic styling) -->
    <?php if ($total_records > 0): ?>
    <div class="mt-8 flex flex-col sm:flex-row items-center justify-between gap-4 p-6 bg-white/40 border border-white rounded-[2rem] shadow-sm">
        <!-- Limit & Total Entries Info -->
        <div class="flex items-center gap-3 text-xs text-slate-500 font-bold">
            <span>Tampilkan</span>
            <select onchange="window.location.href = this.value" class="px-3 py-1.5 rounded-xl bg-white border border-slate-100 text-xs font-black text-slate-600 outline-none cursor-pointer shadow-inner">
                <?php foreach ([10, 25, 50, 100] as $lim_opt): ?>
                    <option value="<?php echo htmlspecialchars(getPaginationUrl(1, $lim_opt, $search, $status, $relevance, $start_date, $end_date)); ?>" <?php echo $limit == $lim_opt ? 'selected' : ''; ?>>
                        <?php echo e($lim_opt); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span>baris</span>
            <span class="text-slate-300">|</span>
            <span>Menampilkan <?php echo min($offset + 1, $total_records); ?> - <?php echo min($offset + $limit, $total_records); ?> dari <?php echo e($total_records); ?> data</span>
        </div>

        <!-- Page Navigation Buttons -->
        <div class="flex items-center gap-2">
            <!-- Prev Button -->
            <?php if ($p > 1): ?>
                <a href="<?php echo htmlspecialchars(getPaginationUrl($p - 1, $limit, $search, $status, $relevance, $start_date, $end_date)); ?>" class="w-8 h-8 rounded-xl bg-white hover:bg-slate-50 text-slate-600 border border-slate-100 flex items-center justify-center hover:scale-105 transition-all shadow-sm" title="Halaman Sebelumnya">
                    <i data-lucide="chevron-left" class="w-4 h-4"></i>
                </a>
            <?php else: ?>
                <span class="w-8 h-8 rounded-xl bg-slate-50/50 text-slate-300 border border-slate-100/50 flex items-center justify-center cursor-not-allowed" title="Halaman Sebelumnya">
                    <i data-lucide="chevron-left" class="w-4 h-4"></i>
                </span>
            <?php endif; ?>

            <!-- Page Numbers Loop with Ellipses -->
            <?php
            $range = 1; // page numbers to show on either side of current page
            $pages_to_show = [];
            for ($i = 1; $i <= $total_pages; $i++) {
                if ($i == 1 || $i == $total_pages || ($i >= $p - $range && $i <= $p + $range)) {
                    $pages_to_show[] = $i;
                }
            }
            $prev_p = 0;
            foreach ($pages_to_show as $page_num):
                if ($prev_p > 0 && $page_num - $prev_p > 1):
                    echo '<span class="text-slate-400 text-xs px-1 font-bold">...</span>';
                endif;
                $active_class = ($page_num === $p) ? 'bg-blue-600 text-white shadow-md shadow-blue-200' : 'bg-white hover:bg-slate-50 text-slate-600 border border-slate-100 hover:scale-105';
                ?>
                <a href="<?php echo htmlspecialchars(getPaginationUrl($page_num, $limit, $search, $status, $relevance, $start_date, $end_date)); ?>" class="w-8 h-8 rounded-xl flex items-center justify-center text-xs font-black transition-all duration-300 <?php echo e($active_class); ?>">
                    <?php echo e($page_num); ?>
                </a>
                <?php
                $prev_p = $page_num;
            endforeach;
            ?>

            <!-- Next Button -->
            <?php if ($p < $total_pages): ?>
                <a href="<?php echo htmlspecialchars(getPaginationUrl($p + 1, $limit, $search, $status, $relevance, $start_date, $end_date)); ?>" class="w-8 h-8 rounded-xl bg-white hover:bg-slate-50 text-slate-600 border border-slate-100 flex items-center justify-center hover:scale-105 transition-all shadow-sm" title="Halaman Berikutnya">
                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                </a>
            <?php else: ?>
                <span class="w-8 h-8 rounded-xl bg-slate-50/50 text-slate-300 border border-slate-100/50 flex items-center justify-center cursor-not-allowed" title="Halaman Berikutnya">
                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Detail Modal (Glassmorphic) -->
<div id="detailModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm hidden opacity-0 transition-all duration-300">
    <div class="glass w-full max-w-2xl p-8 rounded-[2.5rem] shadow-2xl relative transform scale-95 transition-all duration-300 flex flex-col max-h-[85vh] overflow-hidden border border-white/20">
        <!-- Close Button -->
        <button onclick="closeDetailModal()" class="absolute top-6 right-6 text-slate-400 hover:text-slate-600 p-2 hover:bg-slate-100/50 rounded-xl transition-all">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        
        <!-- Header -->
        <div class="mb-6 border-b border-slate-100 pb-4 pr-10">
            <div class="flex items-center gap-3 mb-2">
                <span class="w-2 h-6 bg-blue-600 rounded-full"></span>
                <h2 class="text-xl font-black outfit text-slate-800 uppercase tracking-tight">Detail Kuesioner Alumni</h2>
            </div>
            <p class="text-xs text-slate-400 font-medium">Respons lengkap kuesioner tracer study.</p>
        </div>
        
        <!-- Alumnus Info Header -->
        <div class="p-6 bg-blue-50/50 border border-blue-100/50 rounded-3xl mb-6 grid grid-cols-1 sm:grid-cols-2 gap-4 shrink-0 shadow-inner">
            <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Nama Alumni</p>
                <p id="det_alumni_name" class="font-black text-slate-800 text-base outfit mt-0.5">-</p>
                <p id="det_alumni_nim" class="text-[10px] font-bold text-slate-500 mt-0.5">NIM: -</p>
            </div>
            <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Tanggal Submit</p>
                <p id="det_submit_date" class="font-black text-slate-700 text-sm outfit mt-0.5">-</p>
                <span id="det_work_status" class="inline-block mt-2 text-[9px] font-black px-2.5 py-1 rounded-lg uppercase tracking-widest text-white shadow-sm">-</span>
            </div>
        </div>

        <!-- Answers Content Area -->
        <div id="det_answers_container" class="flex-1 overflow-y-auto pr-2 space-y-4 scrollbar-thin">
            <!-- Dynamic QA items will be injected here -->
        </div>
    </div>
</div>

<script>
    const questionsMap = <?php echo e($questions_map_json); ?>;

    function openDetailModal(submission) {
        document.getElementById('det_alumni_name').textContent = submission.user_name || '-';
        document.getElementById('det_alumni_nim').textContent = 'NIM: ' + (submission.user_nim || '-');
        
        // Formatting date
        let dateStr = '-';
        if (submission.created_at) {
            const d = new Date(submission.created_at);
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
            dateStr = d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
        }
        document.getElementById('det_submit_date').textContent = dateStr;
        
        // Status Badge
        const statusEl = document.getElementById('det_work_status');
        const statusVal = submission.work_status || 'unemployed';
        statusEl.textContent = statusVal.replace('_', ' ').toUpperCase();
        
        // Change badge color depending on status
        statusEl.className = "inline-block mt-2 text-[9px] font-black px-2.5 py-1 rounded-lg uppercase tracking-widest text-white shadow-sm " + 
            (statusVal === 'bekerja' ? 'bg-blue-600' : 
             statusVal === 'wiraswasta' ? 'bg-emerald-600' : 
             statusVal === 'studi_lanjut' ? 'bg-purple-600' : 'bg-slate-500');
        
        // QA injection
        const container = document.getElementById('det_answers_container');
        container.innerHTML = '';
        
        let responses = {};
        try {
            responses = typeof submission.responses === 'string' ? JSON.parse(submission.responses) : (submission.responses || {});
        } catch(e) {
            console.error('Failed to parse responses JSON:', e);
        }
        
        // Keep track of which response keys have been displayed
        const displayedKeys = new Set();
        
        // 1. Loop through database questions
        questionsMap.forEach(q => {
            const key = 'q_' + q.id;
            if (responses.hasOwnProperty(key)) {
                let answer = responses[key];
                if (Array.isArray(answer)) answer = answer.join(', ');
                
                injectQA(q.question_text, answer, false);
                displayedKeys.add(key);
            }
        });
        
        // 2. Loop through responses keys that were not mapped (e.g. deleted/historical questions)
        for (const key in responses) {
            if (responses.hasOwnProperty(key) && !displayedKeys.has(key)) {
                let answer = responses[key];
                if (Array.isArray(answer)) answer = answer.join(', ');
                
                const deletedQText = `[ID: ${key.replace('q_', '')}] Pertanyaan Historis (Tidak aktif / Terhapus)`;
                injectQA(deletedQText, answer, true);
            }
        }
        
        // Show modal with animation
        const modal = document.getElementById('detailModal');
        modal.classList.remove('hidden');
        // Force reflow
        modal.offsetHeight;
        modal.classList.remove('opacity-0');
        modal.querySelector('.glass').classList.remove('scale-95');
        
        // Trigger Lucide icons update
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    }

    function injectQA(question, answer, isDeleted = false) {
        const container = document.getElementById('det_answers_container');
        const qaCard = document.createElement('div');
        qaCard.className = `p-5 rounded-2xl border ${isDeleted ? 'bg-slate-50/50 border-slate-100 text-slate-500' : 'bg-white/40 border-slate-100 text-slate-800'} transition-all hover:bg-slate-50/70 shadow-sm`;
        
        qaCard.innerHTML = `
            <p class="text-xs font-bold ${isDeleted ? 'text-slate-400' : 'text-slate-500'} mb-1.5 leading-relaxed">${question}</p>
            <p class="font-extrabold text-sm ${isDeleted ? 'text-slate-600 font-mono' : 'text-slate-800'}">${answer || '-'}</p>
        `;
        container.appendChild(qaCard);
    }

    function closeDetailModal() {
        const modal = document.getElementById('detailModal');
        modal.classList.add('opacity-0');
        modal.querySelector('.glass').classList.add('scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
        }, 300); // Wait for transition duration
    }
</script>
