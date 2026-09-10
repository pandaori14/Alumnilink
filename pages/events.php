<?php
$user_id = $_SESSION['user_id'];

// Fetch user data for Tracer Study check
$stmt = $pdo->prepare("SELECT is_verified, last_tracer_update FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch();

// Tracer Alumni check (6 months rule)
$tracer_date = $user_data->last_tracer_update ?? null;
$six_months_ago = tracer_validity_threshold(); // dari settings.tracer_validity_months
$needs_tracer = !$tracer_date || $tracer_date < $six_months_ago;

// Fetch events & kegiatan
// Sebelumnya tanpa LIMIT: seluruh arsip event sejak awal ikut terambil pada
// setiap pemuatan, padahal yang dilihat orang hanya yang terbaru.
$hal_event = paginate($pdo, " FROM news_posts WHERE type IN ('event', 'kegiatan')",
                      '*', 'event_date DESC, created_at DESC', [], 12);
$events    = $hal_event['rows'];
$event_url = pager_url_builder(['page' => 'events']);
?>

<div class="max-w-6xl mx-auto">
    <div class="mb-8 px-1 text-center md:text-left">
        <h1 class="text-3xl md:text-4xl font-black outfit text-slate-800 tracking-tight mb-2">Event & Kegiatan</h1>
        <p class="text-slate-500 text-sm md:text-base font-medium max-w-2xl">Daftar kegiatan, seminar, dan acara reuni yang diselenggarakan untuk alumni. <br class="hidden md:block">Ikuti terus perkembangan dan mari berpartisipasi aktif!</p>
    </div>

    <?php if ($needs_tracer): ?>
    <div class="bg-red-50 border border-red-100 p-6 rounded-[2rem] mb-10 flex flex-col md:flex-row md:items-center justify-between gap-6 shadow-sm">
        <div class="flex items-start md:items-center gap-4">
            <div class="w-12 h-12 bg-red-500 text-white rounded-2xl flex items-center justify-center shrink-0 shadow-lg shadow-red-200">
                <i data-lucide="lock" class="w-6 h-6"></i>
            </div>
            <div>
                <h3 class="text-lg font-black outfit text-red-700 tracking-tight">Akses Pendaftaran Terkunci</h3>
                <p class="text-red-600/80 text-sm font-medium mt-1 leading-relaxed">Anda belum mengisi atau memperbarui data Tracer Study dalam 6 bulan terakhir. Mohon isi terlebih dahulu agar dapat mendaftar event.</p>
            </div>
        </div>
        <a href="index.php?page=tracer" class="bg-red-600 text-white px-6 py-3 rounded-xl font-bold hover:bg-red-700 transition-all text-center text-sm shadow-md shadow-red-200 shrink-0">
            Isi Tracer Sekarang
        </a>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
        <?php foreach ($events as $event): ?>
        <div class="bg-white rounded-[2.5rem] overflow-hidden border border-slate-100 shadow-sm group hover:shadow-xl hover:shadow-blue-500/10 transition-all duration-500 flex flex-col">
            <div class="h-48 overflow-hidden relative">
                <?php if($event->image): ?>
                    <img src="<?php echo e($event->image); ?>" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110" alt="Event Poster">
                <?php else: ?>
                    <div class="w-full h-full bg-slate-50 flex items-center justify-center text-slate-200">
                        <i data-lucide="calendar" class="w-12 h-12"></i>
                    </div>
                <?php endif; ?>
                
                <div class="absolute top-4 left-4 flex gap-2">
                    <span class="px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest glass text-slate-800 border-white shadow-sm">
                        <?php echo e($event->type); ?>
                    </span>
                </div>
            </div>
            
            <div class="p-8 flex flex-col flex-1">
                <?php if($event->event_date): ?>
                <div class="flex items-center gap-2 text-[11px] font-bold text-blue-600 uppercase tracking-widest mb-3 bg-blue-50 w-fit px-3 py-1.5 rounded-xl">
                    <i data-lucide="calendar-clock" class="w-3.5 h-3.5"></i>
                    <?php echo date('d M Y - H:i', strtotime($event->event_date)); ?> WIB
                </div>
                <?php endif; ?>

                <h3 class="text-xl font-black outfit text-slate-800 mb-3 group-hover:text-blue-600 transition-colors line-clamp-2"><?php echo htmlspecialchars($event->title); ?></h3>
                
                <p class="text-slate-500 text-sm leading-relaxed mb-8 line-clamp-3">
                    <?php echo e(strip_tags($event->content)); ?>
                </p>
                
                <div class="mt-auto pt-6 border-t border-slate-50 flex flex-col gap-3">
                    <a href="index.php?page=news_detail&id=<?php echo e($event->id); ?>" class="text-center text-sm font-bold text-slate-500 hover:text-blue-600 transition-colors">
                        Lihat Detail Acara
                    </a>

                    <?php if($event->registration_link): ?>
                        <?php if($needs_tracer): ?>
                            <button onclick="alertTracer()" class="w-full py-4 bg-slate-100 text-slate-400 rounded-2xl font-bold flex items-center justify-center gap-2 cursor-not-allowed">
                                <i data-lucide="lock" class="w-4 h-4"></i> Daftar Sekarang
                            </button>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars($event->registration_link); ?>" target="_blank" rel="noopener noreferrer" class="w-full py-4 bg-blue-600 text-white rounded-2xl font-bold flex items-center justify-center gap-2 hover:bg-blue-700 transition-all shadow-lg shadow-blue-200 active:scale-[0.98]">
                                Daftar Sekarang <i data-lucide="external-link" class="w-4 h-4"></i>
                            </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="w-full py-4 bg-slate-50 text-slate-400 rounded-2xl font-bold text-center text-sm border border-slate-100">
                            Pendaftaran Tidak Tersedia
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if(empty($events)): ?>
        <div class="col-span-full py-24 text-center glass rounded-[3rem] border border-white">
            <div class="w-20 h-20 bg-slate-100 text-slate-300 rounded-[2rem] flex items-center justify-center mx-auto mb-6">
                <i data-lucide="calendar-x" class="w-10 h-10"></i>
            </div>
            <h3 class="text-2xl font-black outfit text-slate-400 mb-2">Belum Ada Event</h3>
            <p class="text-slate-400 text-sm">Saat ini belum ada event atau kegiatan yang dipublikasikan.</p>
        </div>
        <?php endif; ?>
    </div>

    <?php
    render_pager_summary(count($events), $hal_event['total'], $hal_event['hal'], $hal_event['total_hal'], 'kegiatan');
    render_pager($event_url, $hal_event['hal'], $hal_event['total_hal']);
    ?>
</div>

<script src="assets/js/sweetalert2.min.js"></script>
<script>
function alertTracer() {
    Swal.fire({
        icon: 'warning',
        title: 'Akses Terkunci',
        text: 'Anda harus mengisi atau memperbarui Tracer Study Anda terlebih dahulu sebelum dapat mendaftar event.',
        confirmButtonText: 'Isi Tracer Sekarang',
        confirmButtonColor: '#2563eb',
        showCancelButton: true,
        cancelButtonText: 'Nanti',
        borderRadius: '1.5rem'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'index.php?page=tracer';
        }
    });
}
</script>
