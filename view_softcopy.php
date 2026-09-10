<?php
require_once 'config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/includes/session_boot.php';
}

// --- DATA MAPPING UTILITY ---
// Fetch formal document types from settings using PDO
$stmt_types = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'legalisir_document_types'");
$stmt_types->execute();
$types_res = $stmt_types->fetch();
$doc_mappings = json_decode($types_res->setting_value ?? '[]', true);

// Utility function for formal names - ENHANCED SMART MATCHING
$getFormalName = function($raw_name) use ($doc_mappings) {
    $raw_lower = strtolower($raw_name);
    
    // 1. Try Direct Slug Match (e.g. "ijazah profesi")
    foreach ($doc_mappings as $mapping) {
        if (!isset($mapping['id'])) continue;
        $slug = strtolower($mapping['id']);
        $slug_underscored = str_replace(' ', '_', $slug);
        $slug_first_word = explode(' ', $slug)[0];

        // Match if slug, slug with underscores, or first word of slug exists in filename
        if (strpos($raw_lower, $slug) !== false || 
            strpos($raw_lower, $slug_underscored) !== false ||
            (strlen($slug_first_word) > 3 && strpos($raw_lower, $slug_first_word) !== false)) {
            return $mapping['name'];
        }
    }

    // 2. Keyword Fallback (Generic mapping if slug match fails)
    if (strpos($raw_lower, 'ijazah') !== false) return "Ijazah";
    if (strpos($raw_lower, 'transkrip') !== false) return "Transkrip Nilai";

    // 3. Last Resort: Clean technical artifacts
    $clean = preg_replace('/(_[0-9]+)+$/i', '', $raw_name);
    $clean = str_replace('_', ' ', $clean);
    return ucwords(strtolower($clean));
};
// -----------------------------

$id = $_GET['id'] ?? '';
$token = $_GET['token'] ?? '';

// Check access
if (empty($id) || empty($token)) {
    die("Akses ditolak.");
}

$stmt = $pdo->prepare("
    SELECT lr.*, u.name as alumni_name, u.nim as alumni_nim, u.major as alumni_major, u.graduation_year as alumni_year 
    FROM legalisir_requests lr
    JOIN users u ON lr.user_id = u.id
    WHERE lr.id = ? AND lr.verification_token = ? AND lr.status = 'completed'
");
$stmt->execute([$id, $token]);
$request = $stmt->fetch();

if (!$request) {
    die("Dokumen tidak ditemukan atau belum diverifikasi.");
}

// Map alumni_major to major_code
$stmt_majors = $pdo->query("SELECT major_code, major_name FROM majors");
$majors = $stmt_majors->fetchAll();

$target_major_code = '';
foreach ($majors as $m) {
    if (strcasecmp($request->alumni_major, $m->major_code) === 0 || strcasecmp($request->alumni_major, $m->major_name) === 0) {
        $target_major_code = $m->major_code;
        break;
    }
}
if (empty($target_major_code)) {
    $target_major_code = default_major_code(); // settings.default_major_code
}

// Helper to get master accreditation file
$getMasterAccreditation = function($major_code, $year) use ($pdo) {
    $stmt = $pdo->prepare("SELECT file_path, certificate_name FROM accreditation_certificates WHERE major_code = ? AND start_year <= ? AND end_year >= ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$major_code, $year, $year]);
    return $stmt->fetch();
};

$docs = json_decode($request->documents);
// Default to the first document (usually Ijazah)
$target_doc = (is_array($docs) && count($docs) > 0) ? $docs[0] : null;
$file_path = is_object($target_doc) ? $target_doc->file : $target_doc;

if (!$file_path || $file_path == '#') {
    die("File dokumen tidak tersedia.");
}

// Fetch settings
$stmt_settings = $pdo->query("SELECT * FROM settings");
$raw_settings = $stmt_settings->fetchAll();
$sys_settings = [];
foreach ($raw_settings as $s) { $sys_settings[$s->setting_key] = $s->setting_value; }

$watermark_text = $sys_settings['digital_stamp_watermark'] ?? 'ALUMNILINK VERIFIED';
$stamp_text = $sys_settings['digital_stamp_text'] ?? 'Dokumen ini telah dilegalisir secara sah melalui sistem AlumniLink FK UMS.';
$system_logo = $sys_settings['system_logo'] ?? 'https://upload.wikimedia.org/wikipedia/id/thumb/a/af/Logo_Universitas_Muhammadiyah_Surakarta.png/600px-Logo_Universitas_Muhammadiyah_Surakarta.png';

$verify_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . str_replace('view_softcopy.php', 'verify.php', $_SERVER['REQUEST_URI']);
// Use api.qrserver.com with higher resolution for HD print
$qr_api = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&format=png&margin=1&data=" . urlencode($verify_url);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Softcopy Legalisir - <?php echo htmlspecialchars($request->alumni_name); ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <style>
        :root {
            --doc-scale: 1;
        }
        body { font-family: 'Outfit', sans-serif; background: #cbd5e1; color: #1e293b; padding: 20px 0; overflow-x: hidden; }
        
        /* Strict A4 Container with dynamic scale properties */
        .document-page {
            width: 210mm;
            height: 297mm;
            margin: 0 auto calc(30px - (1122px * (1 - var(--doc-scale)))) auto;
            background: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
            transform: scale(var(--doc-scale));
            transform-origin: top center;
            transition: transform 0.15s ease-out, margin-bottom 0.15s ease-out;
        }

        @media print {
            body { background: none; padding: 0; }
            .document-page { 
                margin: 0 !important; 
                box-shadow: none !important; 
                border: none !important; 
                transform: none !important;
            }
            .no-print { display: none !important; }
        }

        /* Official Letterhead Style */
        .letterhead {
            padding: 40px 50px 20px 50px;
            border-bottom: 3px double #2563eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .university-info h1 {
            font-size: 24px;
            font-weight: 900;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: -0.5px;
            line-height: 1;
        }

        .university-info p {
            font-size: 14px;
            font-weight: 700;
            color: #2563eb;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-top: 5px;
        }

        /* Verification Badge */
        .verify-badge {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .id-label {
            font-size: 8px;
            font-weight: 800;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .id-value {
            font-size: 11px;
            font-weight: 900;
            color: #1e293b;
            font-family: monospace;
        }

        /* Main Content Fitting */
        .content-frame {
            flex: 1;
            padding: 20px 50px;
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 5;
        }

        .alumni-meta {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding-bottom: 10px;
            border-bottom: 1px solid #f1f5f9;
        }

        .document-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fdfdfd;
            border: 1px solid #f1f5f9;
            position: relative;
        }

        .document-wrapper img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .official-footer {
            margin-top: auto;
            padding: 15px 50px 25px 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            position: relative;
            z-index: 20;
        }

        .security-pattern {
            position: absolute;
            inset: 0;
            background-image: radial-gradient(#2563eb 0.5px, transparent 0.5px);
            background-size: 30px 30px;
            opacity: 0.03;
            pointer-events: none;
        }

        .rotation-container {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
    </style>
</head>
<body>

    <!-- Professional Control Bar -->
    <div class="fixed top-6 left-1/2 -translate-x-1/2 z-[100] no-print w-[90%] sm:w-auto">
        <div class="bg-white/90 backdrop-blur-xl border border-white shadow-2xl rounded-full px-4 py-2 sm:px-8 sm:py-3 flex items-center justify-between sm:justify-start gap-3 sm:gap-6">
            <a href="index.php?page=legalisir" class="text-slate-400 hover:text-blue-600 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
            </a>
            <div class="h-5 w-px bg-slate-200"></div>
            <div class="flex items-center gap-2">
                <button onclick="rotateDoc(-90)" class="p-2 hover:bg-slate-100 rounded-full text-slate-600 transition-all"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg></button>
                <button onclick="rotateDoc(90)" class="p-2 hover:bg-slate-100 rounded-full text-slate-600 transition-all rotate-180"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg></button>
            </div>
            <div class="h-5 w-px bg-slate-200"></div>
            <button id="downloadBtn" onclick="downloadPDF()" class="bg-blue-600 text-white px-4 py-2 sm:px-8 sm:py-2.5 rounded-full font-bold shadow-lg shadow-blue-500/30 hover:bg-blue-700 transition-all active:scale-95 text-xs sm:text-sm flex items-center gap-2 whitespace-nowrap">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Download PDF
            </button>
        </div>
    </div>

    <div class="h-20 no-print"></div>

    <div id="main-container">
        <!-- PAGE TEMPLATE HELPERS -->
        <?php 
        if (!function_exists('renderHeader')) {
            function renderHeader($request, $system_logo, $qr_api, $is_master = false) {
                ?>
                <header class="letterhead px-10 py-8 bg-white flex justify-between items-center relative">
                    <div class="flex-1 pr-8">
                        <!-- Formal Institutional Text -->
                        <div class="university-info border-l-[3px] border-blue-600 pl-8 py-1 max-w-[500px]">
                            <h1 class="text-3xl font-black text-slate-900 outfit uppercase tracking-tight leading-tight">
                                Fakultas Kedokteran
                            </h1>
                            <p class="text-[14px] font-bold text-blue-600 uppercase tracking-[0.2em] mt-1 outfit">Universitas Muhammadiyah Surakarta</p>
                        </div>
                    </div>

                    <!-- Final Polished Security Seal (Spacious & Clean) -->
                    <div class="flex items-center gap-8 bg-white p-5 rounded-[32px] border border-slate-100 shadow-2xl shadow-blue-900/5 min-w-[300px] flex-shrink-0">
                        <!-- Left Side: Grouped Security Info -->
                        <div class="flex-1">
                            <div class="flex items-center gap-4 mb-3">
                                <div class="w-10 h-10 bg-blue-600 rounded-2xl flex items-center justify-center shadow-lg shadow-blue-500/20">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                </div>
                                <div class="flex flex-col">
                                    <span class="text-[8px] font-black text-blue-600 uppercase tracking-[0.4em] mb-0.5">Security</span>
                                    <span class="text-[11px] font-black text-slate-800 uppercase tracking-widest outfit">Verified</span>
                                </div>
                            </div>
                            <div class="pl-0.5">
                                <span class="text-[10px] font-black text-slate-300 uppercase tracking-[0.4em] outfit">
                                    <?php echo strtoupper(substr($request->id, -12)); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Right Side: Clean QR Frame -->
                        <div class="bg-white p-1 rounded-2xl border border-slate-50 shadow-sm">
                            <img src="<?php echo e($qr_api); ?>" class="w-16 h-16 mix-blend-multiply" alt="QR Seal" style="image-rendering: -webkit-optimize-contrast;">
                        </div>
                    </div>
                </header>

                <!-- Alumni Info Bar -->
                <div class="alumni-meta-bar px-[50px] py-4 bg-slate-50 border-y border-slate-200 flex justify-between items-center mb-8">
                    <div>
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-[0.2em] block mb-0.5">Alumni Name</span>
                        <span class="text-sm font-black text-slate-800 uppercase outfit"><?php echo htmlspecialchars($request->alumni_name); ?></span>
                    </div>
                    <div class="text-right">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-[0.2em] block mb-0.5">Document Status</span>
                        <span class="text-[10px] font-black text-blue-700 bg-blue-100/50 px-4 py-1 rounded-full border border-blue-200 uppercase tracking-wider">Verified</span>
                    </div>
                </div>
                <?php
            }
        }

        if (!function_exists('renderFooter')) {
            function renderFooter($current_page, $total_pages) {
                ?>
                <footer class="official-footer absolute bottom-0 left-0 right-0 border-t border-slate-100 bg-white">
                    <div class="text-[10px] text-slate-400 font-medium">
                        &copy; <?php echo date('Y'); ?> Universitas Muhammadiyah Surakarta. Diterbitkan secara resmi.
                    </div>
                    <div class="flex items-center gap-6">
                        <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                            Page <span class="text-slate-800"><?php echo e($current_page); ?></span> of <?php echo e($total_pages); ?>
                        </div>
                        <div class="w-px h-4 bg-slate-200"></div>
                        <div class="text-blue-600 font-black outfit text-[10px] uppercase tracking-[0.2em] flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full bg-blue-600"></div>
                            Official Certification
                        </div>
                    </div>
                </footer>
                <?php
            }
        }

        $total_pages = count($docs) + 1;
        ?>

        <!-- PAGE 1: COVER LETTER -->
        <div class="document-page relative">
            <div class="security-pattern"></div>
            <?php renderHeader($request, $system_logo, $qr_api, true); ?>

            <main class="content-frame pt-10 pb-24">
                <div class="text-center mb-10">
                    <h2 class="text-2xl font-black text-slate-900 outfit uppercase tracking-tight border-b-8 border-blue-600/10 inline-block px-12 pb-2">Surat Keterangan Legalisasi</h2>
                    <p class="text-[11px] font-bold text-slate-400 mt-4 tracking-[0.4em] uppercase">Ref No: <?php echo e($request->id); ?></p>
                </div>

                <div class="space-y-8 px-10">
                    <div class="bg-white p-10 rounded-[40px] border-2 border-slate-50 shadow-sm relative overflow-hidden">
                        <!-- Institutional Logo Watermark (Page 1 Only) -->
                        <div class="absolute inset-0 flex items-center justify-center opacity-[0.05] pointer-events-none z-0">
                            <img src="<?php echo e($system_logo); ?>" class="w-1/3 max-h-[70%] object-contain grayscale brightness-50" alt="Watermark Logo">
                        </div>  
                        <div class="absolute top-0 left-0 w-2 h-full bg-blue-600"></div>
                        <p class="text-sm text-slate-500 leading-relaxed mb-8">Fakultas Kedokteran UMS menyatakan bahwa seluruh dokumen terlampir adalah sah milik alumni berikut:</p>
                        
                        <div class="grid grid-cols-3 gap-y-8">
                            <div class="col-span-1 text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">Nama Alumni</div>
                            <div class="col-span-2 text-base font-black text-slate-900 outfit uppercase"><?php echo htmlspecialchars($request->alumni_name); ?></div>
                            
                            <div class="col-span-1 text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">NIM</div>
                            <div class="col-span-2 text-base font-black text-slate-900 outfit tracking-widest"><?php echo e($request->alumni_nim); ?></div>
                            
                            <div class="col-span-1 text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">Waktu Legalisasi</div>
                            <div class="col-span-2 text-base font-black text-slate-900 outfit"><?php echo date('d F Y, H:i', strtotime($request->verified_at)); ?> WIB</div>
                        </div>
                    </div>

                    <div class="pt-4">
                        <h3 class="text-[12px] font-black text-slate-900 uppercase tracking-widest mb-6 flex items-center gap-3">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="text-blue-600"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            Berkas Terlampir
                        </h3>
                        <div class="overflow-hidden rounded-3xl border border-slate-100 shadow-sm">
                            <table class="w-full text-left">
                                <thead class="bg-slate-50 border-b border-slate-100">
                                    <tr>
                                        <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest w-20">Item</th>
                                        <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Dokumen</th>
                                        
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($docs as $idx => $d): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="p-5 text-sm font-bold text-slate-400">#0<?php echo $idx + 1; ?></td>
                                        <td class="p-5">
                                            <div class="text-sm font-black text-slate-800 outfit uppercase tracking-tight">
                                                <?php 
                                                    $raw_file = is_object($d) ? $d->file : $d;
                                                    if (stripos($raw_file, 'Otomatis terlampir') !== false) {
                                                        $accred = $getMasterAccreditation($target_major_code, $request->alumni_year);
                                                        if ($accred && !empty($accred->certificate_name)) {
                                                            echo htmlspecialchars($accred->certificate_name);
                                                        } else {
                                                            echo "Sertifikat Akreditasi (" . htmlspecialchars($request->alumni_year) . ")";
                                                        }
                                                    } else {
                                                        $raw_name = "Dokumen";
                                                        if (is_object($d)) {
                                                            $raw_name = $d->name ?? pathinfo($d->file, PATHINFO_FILENAME);
                                                        } elseif (is_string($d)) {
                                                            $raw_name = pathinfo($d, PATHINFO_FILENAME);
                                                        }
                                                        echo htmlspecialchars($getFormalName($raw_name));
                                                    }
                                                ?>
                                            </div>
                                            <div class="text-[9px] text-slate-400 font-bold mt-1 uppercase tracking-widest flex items-center gap-1.5">
                                                <?php if(is_object($d) && isset($d->source) && $d->source === 'repository'): ?>
                                                    <span class="text-emerald-600 font-black flex items-center gap-1">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                                                        Official Source
                                                    </span>
                                                <?php else: ?>
                                                    Digital Verified Version
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
            <?php renderFooter(1, $total_pages); ?>
        </div>

        <!-- ATTACHMENT PAGES -->
        <?php 
        foreach ($docs as $index => $doc): 
            $file_path = is_object($doc) ? $doc->file : $doc;
            if (!$file_path || $file_path == '#') continue;
            
            $is_akreditasi = false;
            $cert_name = '';
            if (stripos($file_path, 'Otomatis terlampir') !== false) {
                $is_akreditasi = true;
                $accred = $getMasterAccreditation($target_major_code, $request->alumni_year);
                if ($accred && !empty($accred->file_path)) {
                    $file_path = $accred->file_path;
                    $cert_name = $accred->certificate_name;
                }
            }
            
            $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            
            // Re-map the name for this specific document entry
            if ($is_akreditasi) {
                $formal_display_name = !empty($cert_name) ? $cert_name : "Sertifikat Akreditasi (" . htmlspecialchars($request->alumni_year) . ")";
            } else {
                if (is_object($doc)) {
                    $current_doc_name = $doc->name ?? pathinfo($doc->file, PATHINFO_FILENAME);
                } else {
                    $current_doc_name = pathinfo($doc, PATHINFO_FILENAME);
                }
                $formal_display_name = $getFormalName($current_doc_name);
            }
        ?>
            <div class="document-page relative">
                <div class="security-pattern"></div>
                <?php renderHeader($request, $system_logo, $qr_api); ?>

                <main class="content-frame pb-24 flex-1 flex flex-col">
                    <div class="document-wrapper rounded-[40px] border-2 border-slate-50 shadow-inner overflow-hidden bg-white p-12 relative">
                        <!-- Content Label -->
                        <div class="absolute top-8 left-10 z-20 flex items-center gap-3">
                            <div class="bg-blue-600 text-white text-[10px] font-black px-4 py-1.5 rounded-full uppercase tracking-widest shadow-lg shadow-blue-200">
                                <?php echo e($formal_display_name); ?>
                            </div>
                            <?php if(is_object($doc) && isset($doc->source) && $doc->source === 'repository'): ?>
                                <div class="bg-emerald-600 text-white text-[10px] font-black px-4 py-1.5 rounded-full uppercase tracking-widest shadow-lg shadow-emerald-200 flex items-center gap-1.5">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                                    Official Source
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Layer 1: Horizontal Repeating Stamp Text (High-Density Staggered Pattern) -->
                        <div class="absolute inset-0 opacity-[0.08] pointer-events-none z-20 overflow-hidden flex flex-col gap-4 p-4">
                            <?php for($i=0; $i<60; $i++): ?>
                                <div class="whitespace-nowrap text-[8px] font-black uppercase tracking-[0.5em] text-blue-900/50" style="margin-left: <?php echo ($i % 3 == 0) ? '-50px' : (($i % 3 == 1) ? '-150px' : '-300px'); ?>;">
                                    <?php echo str_repeat(htmlspecialchars($stamp_text) . " &nbsp;&nbsp;&nbsp; • &nbsp;&nbsp;&nbsp; ", 15); ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                                
                        <!-- Layer 2: Centered Watermark (Macro-overlay) -->
                        <div class="absolute inset-0 flex items-center justify-center opacity-[0.05] pointer-events-none z-30">
                            <span class="text-8xl font-black rotate-[-30deg] uppercase border-[15px] border-blue-600 p-20 rounded-[100px] text-blue-600 outfit whitespace-nowrap">
                                <?php echo htmlspecialchars($watermark_text); ?>
                            </span>
                        </div>

                        <div id="doc-wrapper-<?php echo e($index); ?>" class="rotation-container relative z-10 w-full h-full p-12 flex items-center justify-center">
                            <?php
                                // Berkas dilayani lewat serve_document.php, bukan lewat
                                // tautan langsung ke uploads/. Folder tersebut kini
                                // ditutup .htaccess karena berisi data pribadi alumni.
                                // Token verifikasi diteruskan sebagai kredensial.
                                if (filter_var($file_path, FILTER_VALIDATE_URL)) {
                                    $full_url = $file_path;
                                } else {
                                    $full_url = rtrim(BASE_URL, '/') . '/serve_document.php?ctx=softcopy'
                                        . '&req='   . urlencode($id)
                                        . '&token=' . urlencode($token)
                                        . '&i='     . (int)$index;
                                }
                            ?>
                            <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])): ?>
                                <img src="<?php echo e($full_url); ?>" crossorigin="anonymous" class="max-w-full max-h-full object-contain shadow-2xl rounded-sm" alt="Document">
                            <?php elseif ($ext === 'pdf'): ?>
                                <div class="w-full h-full flex flex-col items-center justify-center bg-slate-50 text-slate-300 no-print">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" class="mb-4"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14.5 2 14.5 7.5 20 7.5"/></svg>
                                    <p class="text-[10px] font-black uppercase tracking-[0.5em]">Processing PDF...</p>
                                    <iframe src="<?php echo e($full_url); ?>#toolbar=0" class="w-full h-full border-none mt-8 rounded-xl shadow-lg"></iframe>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </main>

                <?php renderFooter($index + 2, $total_pages); ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="max-w-[900px] mx-auto mt-8 mb-20 text-center no-print">
        <p class="text-xs text-slate-400 font-bold uppercase tracking-widest bg-white/50 inline-block px-6 py-2 rounded-full border border-white">Digital Document Viewer v2.0</p>
    </div>

    <script src="assets/js/pdf.min.js"></script>
    <script src="assets/js/html2pdf.bundle.min.js"></script>
    <script src="assets/js/sweetalert2.min.js"></script>
    <script>
        function showSwalAlert(title, text, icon = 'info') {
            Swal.fire({
                title: title,
                text: text,
                icon: icon,
                confirmButtonColor: '#2563eb',
                confirmButtonText: 'Mengerti',
                borderRadius: '1.5rem',
                customClass: {
                    popup: 'rounded-[2rem] border border-slate-100 shadow-2xl outfit',
                    title: 'text-xl font-black text-slate-800',
                    confirmButton: 'px-6 py-3 bg-blue-600 text-white font-bold rounded-2xl shadow-lg shadow-blue-200 hover:bg-blue-700 transition-all'
                }
            });
        }
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/js/pdf.worker.min.js';

        let currentRotation = 0;
        
        function rotateDoc(degrees) {
            currentRotation = (currentRotation + degrees) % 360;
            const containers = document.querySelectorAll('.rotation-container');
            containers.forEach(el => {
                const transformValue = `rotate(${currentRotation}deg)`;
                el.style.transform = transformValue;
                document.documentElement.style.setProperty('--print-rotate', transformValue);
                if (Math.abs(currentRotation % 180) === 90) {
                    el.classList.add('rotated-90');
                    el.style.scale = '0.7'; 
                } else {
                    el.classList.remove('rotated-90');
                    el.style.scale = '1';
                }
            });
        }

        // Automatic PDF Rendering for Viewer
        async function renderPDFToImage(url, containerId) {
            try {
                const loadingTask = pdfjsLib.getDocument(url);
                const pdf = await loadingTask.promise;
                const page = await pdf.getPage(1); // Get first page
                
                const viewport = page.getViewport({ scale: 2 });
                const canvas = document.createElement('canvas');
                const context = canvas.getContext('2d');
                canvas.height = viewport.height;
                canvas.width = viewport.width;

                await page.render({ canvasContext: context, viewport: viewport }).promise;
                
                const container = document.getElementById(containerId);
                const img = document.createElement('img');
                img.src = canvas.toDataURL('image/jpeg', 0.95);
                img.className = "max-w-full max-h-full object-contain shadow-sm border border-slate-200";
                img.style.zIndex = "10";
                
                container.innerHTML = '';
                container.appendChild(img);
            } catch (e) {
                console.error("PDF render error:", e);
            }
        }

        // Dynamic Scaling for Mobile Responsiveness (Ensures strict A4 layout fits screen width)
        function updateDocScale() {
            const containerWidth = window.innerWidth;
            const targetWidth = 794; // approx 210mm in pixels (A4 width)
            let scale = 1;
            
            if (containerWidth < targetWidth + 40) {
                scale = (containerWidth - 30) / targetWidth;
            }
            
            document.documentElement.style.setProperty('--doc-scale', scale);
        }
        
        window.addEventListener('resize', updateDocScale);
        window.addEventListener('load', updateDocScale);
        updateDocScale(); // Execute immediately

        document.addEventListener('DOMContentLoaded', () => {
            <?php foreach ($docs as $index => $doc): 
                $file_path = is_object($doc) ? $doc->file : $doc;
                if (stripos($file_path, 'Otomatis terlampir') !== false) {
                    $accred = $getMasterAccreditation($target_major_code, $request->alumni_year);
                    if ($accred && !empty($accred->file_path)) {
                        $file_path = $accred->file_path;
                    }
                }
                $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                if ($ext === 'pdf'):
                    // Sama seperti di atas: dilayani lewat serve_document.php agar
                    // folder uploads/ dapat tetap tertutup bagi akses langsung.
                    $pdf_url = filter_var($file_path, FILTER_VALIDATE_URL)
                        ? $file_path
                        : rtrim(BASE_URL, '/') . '/serve_document.php?ctx=softcopy'
                            . '&req='   . urlencode($id)
                            . '&token=' . urlencode($token)
                            . '&i='     . (int)$index;
            ?>
                renderPDFToImage('<?php echo e($pdf_url); ?>', 'doc-wrapper-<?php echo e($index); ?>');
            <?php endif; endforeach; ?>
        });

        async function downloadPDF() {
            const element = document.getElementById('main-container');
            const alumniName = <?php echo e(json_encode($request->alumni_name)); ?>;
            const btn = document.getElementById('downloadBtn');
            const originalHTML = btn.innerHTML;
            
            btn.innerHTML = `<span class="animate-pulse">Generating...</span>`;
            btn.disabled = true;

            const opt = {
                margin: 0,
                filename: `Legalisir_${alumniName.replace(/\s+/g, '_')}.pdf`,
                image: { type: 'jpeg', quality: 1.0 },
                html2canvas: { scale: 3, useCORS: true, scrollY: 0 },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };

            try {
                await html2pdf().set(opt).from(element).save();
            } catch (error) {
                showSwalAlert('Gagal Mengunduh', 'Gagal mengunduh file secara otomatis. Silakan gunakan pintasan keyboard Ctrl + P untuk mencetak/menyimpan PDF.', 'warning');
            } finally {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        }
    </script>
</body>
</html>
