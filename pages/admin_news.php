<?php
// Fetch all posts
$stmt = $pdo->query("SELECT * FROM news_posts ORDER BY created_at DESC");
$posts = $stmt->fetchAll();
?>

<div class="max-w-6xl mx-auto">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-black outfit text-slate-800 tracking-tight">Kelola Berita & Event</h1>
            <p class="text-slate-400 text-sm font-medium mt-1">Publikasikan kegiatan, berita, atau event terbaru untuk alumni.</p>
        </div>
        <button onclick="openNewsModal()" class="bg-blue-600 text-white px-6 py-3 rounded-2xl font-bold flex items-center gap-2 hover:bg-blue-700 transition-all shadow-lg shadow-blue-100">
            <i data-lucide="plus" class="w-5 h-5"></i> Tambah Postingan
        </button>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="bg-emerald-50 text-emerald-600 p-4 rounded-2xl border border-emerald-100 mb-6 flex items-center gap-3 animate-in fade-in slide-in-from-top-2">
            <i data-lucide="check-circle" class="w-5 h-5"></i>
            <span class="text-sm font-bold">Berhasil! Postingan telah diperbarui.</span>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($posts as $post): ?>
        <div class="glass rounded-[2.5rem] overflow-hidden border-white shadow-sm flex flex-col group">
            <div class="h-48 overflow-hidden relative">
                <?php if ($post->image): ?>
                    <img src="<?php echo e($post->image); ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110" alt="News Image">
                <?php else: ?>
                    <div class="w-full h-full bg-slate-100 flex items-center justify-center text-slate-300">
                        <i data-lucide="image" class="w-12 h-12"></i>
                    </div>
                <?php endif; ?>
                <div class="absolute top-4 left-4">
                    <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest glass text-slate-700 border-white">
                        <?php echo e($post->type); ?>
                    </span>
                </div>
            </div>
            <div class="p-8 flex-1 flex flex-col">
                <h3 class="text-lg font-bold outfit text-slate-800 mb-2 line-clamp-2"><?php echo htmlspecialchars($post->title); ?></h3>
                <p class="text-slate-500 text-xs line-clamp-3 mb-6 leading-relaxed flex-1">
                    <?php echo e(strip_tags($post->content)); ?>
                </p>
                <div class="flex items-center justify-between pt-6 border-t border-slate-100 mt-auto">
                    <span class="text-[10px] font-bold text-slate-400"><?php echo date('d M Y', strtotime($post->created_at)); ?></span>
                    <div class="flex gap-2">
                        <button onclick="editNews(<?php echo htmlspecialchars(json_encode($post)); ?>)" class="p-2 bg-slate-100 text-slate-600 rounded-xl hover:bg-blue-50 hover:text-blue-600 transition-all">
                            <i data-lucide="edit-3" class="w-4 h-4"></i>
                        </button>
                        <form action="handlers/admin_news_handler.php" method="POST" onsubmit="confirmAction(event, this, 'Hapus postingan ini?')" class="inline">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo e($post->id); ?>">
                            <button type="submit" class="p-2 bg-slate-100 text-slate-600 rounded-xl hover:bg-red-50 hover:text-red-600 transition-all">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($posts)): ?>
        <div class="col-span-full py-20 text-center glass rounded-[3rem]">
            <i data-lucide="newspaper" class="w-16 h-16 text-slate-200 mx-auto mb-4"></i>
            <h3 class="text-xl font-bold outfit text-slate-400">Belum Ada Postingan</h3>
            <p class="text-slate-300 text-sm mt-2">Mulai buat berita atau event pertama Anda.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- News Modal -->
<div id="newsModal" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>
    <div class="absolute inset-0 flex items-center justify-center p-6">
        <div class="bg-white rounded-[3rem] shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto animate-in zoom-in-95 duration-300">
            <div class="p-10">
                <div class="flex items-center justify-between mb-8">
                    <h2 id="modalTitle" class="text-2xl font-black outfit text-slate-800">Tambah Postingan</h2>
                    <button onclick="closeNewsModal()" class="w-10 h-10 bg-slate-100 text-slate-400 rounded-xl flex items-center justify-center hover:bg-red-50 hover:text-red-500 transition-all">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>

                <form action="handlers/admin_news_handler.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="id" id="post_id">
                    <input type="hidden" name="existing_image" id="existing_image">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Kategori</label>
                            <select aria-label="Jenis konten" name="type" id="post_type" onchange="toggleEventFields()" class="w-full px-5 py-4 rounded-2xl bg-slate-50 border border-slate-100 focus:border-blue-500 outline-none text-sm font-bold">
                                <option value="berita">Berita</option>
                                <option value="event">Event</option>
                                <option value="kegiatan">Kegiatan</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2 ml-1" for="f_image">Foto Sampul</label>
                            <input id="f_image" type="file" name="image" class="w-full px-5 py-3 rounded-2xl bg-slate-50 border border-slate-100 focus:border-blue-500 outline-none text-sm">
                        </div>
                    </div>

                    <div id="eventFields" class="hidden grid-cols-1 md:grid-cols-2 gap-6 bg-blue-50/50 p-6 rounded-[2rem] border border-blue-100">
                        <div>
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Tanggal Event (Opsional)</label>
                            <input aria-label="Tanggal kegiatan" type="datetime-local" name="event_date" id="post_event_date" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-bold">
                        </div>
                        <div>
                            <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Link Pendaftaran (GForms/dll)</label>
                            <input aria-label="https://forms.gle/" type="url" name="registration_link" id="post_registration_link" class="w-full px-5 py-4 rounded-2xl bg-white border border-slate-100 focus:border-blue-500 outline-none text-sm font-bold" placeholder="https://forms.gle/...">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Judul Postingan</label>
                        <input aria-label="Tulis judul yang menarik" type="text" name="title" id="post_title" required class="w-full px-5 py-4 rounded-2xl bg-slate-50 border border-slate-100 focus:border-blue-500 outline-none text-sm font-bold" placeholder="Tulis judul yang menarik...">
                    </div>

                    <div>
                        <label class="block text-xs font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Konten / Detail</label>
                        <textarea aria-label="Tulis isi postingan di sini" name="content" id="post_content" required class="w-full px-5 py-4 rounded-2xl bg-slate-50 border border-slate-100 focus:border-blue-500 outline-none text-sm h-64" placeholder="Tulis isi postingan di sini..."></textarea>
                    </div>

                    <div class="pt-4">
                        <button type="submit" class="w-full py-5 bg-blue-600 text-white rounded-[2rem] font-black outfit text-lg shadow-xl shadow-blue-100 hover:bg-blue-700 hover:scale-[1.02] transition-all active:scale-95">
                            Simpan Postingan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function toggleEventFields() {
        const type = document.getElementById('post_type').value;
        const fields = document.getElementById('eventFields');
        if (type === 'event' || type === 'kegiatan') {
            fields.classList.remove('hidden');
            fields.classList.add('grid');
        } else {
            fields.classList.add('hidden');
            fields.classList.remove('grid');
        }
    }

    function openNewsModal() {
        document.getElementById('newsModal').classList.remove('hidden');
        document.getElementById('modalTitle').innerText = 'Tambah Postingan';
        document.getElementById('post_id').value = '';
        document.getElementById('post_title').value = '';
        document.getElementById('post_content').value = '';
        document.getElementById('post_type').value = 'berita';
        document.getElementById('post_event_date').value = '';
        document.getElementById('post_registration_link').value = '';
        document.getElementById('existing_image').value = '';
        toggleEventFields();
    }

    function closeNewsModal() {
        document.getElementById('newsModal').classList.add('hidden');
    }

    function editNews(post) {
        document.getElementById('newsModal').classList.remove('hidden');
        document.getElementById('modalTitle').innerText = 'Edit Postingan';
        document.getElementById('post_id').value = post.id;
        document.getElementById('post_title').value = post.title;
        document.getElementById('post_content').value = post.content;
        document.getElementById('post_type').value = post.type;
        
        // Format datetime-local requires YYYY-MM-DDThh:mm
        if(post.event_date) {
            document.getElementById('post_event_date').value = post.event_date.replace(' ', 'T').slice(0, 16);
        } else {
            document.getElementById('post_event_date').value = '';
        }
        
        document.getElementById('post_registration_link').value = post.registration_link || '';
        document.getElementById('existing_image').value = post.image;
        toggleEventFields();
    }
</script>
