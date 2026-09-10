<?php
/**
 * includes/tracer_lib.php
 * ─────────────────────────────────────────────────────────
 * Logika bersama Tracer Study: normalisasi jawaban, penyaringan kohort,
 * deduplikasi responden, dan agregasi pertanyaan.
 *
 * MENGAPA BERKAS INI ADA
 *
 * 1. Normalisasi jawaban sebelumnya ditulis DUA KALI di tempat berbeda —
 *    di sisi tulis (handlers/tracer_handler.php) dan di sisi baca
 *    (api/admin/tracer_analytics.php). Keduanya menyimpang: sisi tulis
 *    menyimpan 'high|medium|low', sisi baca membandingkan dengan
 *    'sangat relevan'. Akibatnya KPI "Keselarasan Kerja" selalu 0%.
 *    Menaruh normalisasi di satu tempat membuat penyimpangan itu mustahil
 *    terulang.
 *
 * 2. Seluruh metrik sebelumnya menghitung SUBMISSION, bukan alumni. Karena
 *    seorang alumnus dapat mengisi berulang kali, response rate bisa
 *    melampaui 100%. Riwayat pengisian tetap disimpan (berguna untuk
 *    analisis longitudinal), tetapi metrik keadaan-kini dihitung atas
 *    submission TERBARU per alumnus.
 *
 * 3. Hanya 5 pertanyaan ber-mapping_key yang dapat dilaporkan, padahal 16
 *    dari 26 pertanyaan bertipe tertutup — termasuk matriks kompetensi 8
 *    aspek yang justru diminta LAM-PTKes. Agregasi generik di sini membaca
 *    langsung kolom JSON `responses`, sehingga berlaku surut ke seluruh
 *    data lama tanpa perlu migrasi.
 */

// ─────────────────────────────────────────────────────────
// NORMALISASI JAWABAN
// ─────────────────────────────────────────────────────────

/**
 * Samakan jawaban relevansi bidang menjadi high|medium|low.
 *
 * Menerima label lama yang sudah terlanjur tersimpan di basis data
 * ("Sangat Relevan", "Relevan", "Cukup Relevan", "Tidak Relevan") maupun
 * slug baru, sehingga data lama dan baru terhitung bersama.
 *
 * @return string|null null bila tidak ada jawaban sama sekali.
 */
function tracer_normalize_relevance($raw)
{
    if ($raw === null || $raw === '') {
        return null;
    }

    $v = strtolower(trim((string)$raw));

    // Slug yang sudah ternormalisasi — kembalikan apa adanya.
    if ($v === 'high' || $v === 'medium' || $v === 'low') {
        return $v;
    }

    // "tidak relevan" harus diperiksa SEBELUM "relevan", karena keduanya
    // sama-sama memuat kata "relevan".
    if (strpos($v, 'tidak') !== false || strpos($v, 'kurang') !== false || strpos($v, 'rendah') !== false) {
        return 'low';
    }
    if (strpos($v, 'sangat') !== false || strpos($v, 'tinggi') !== false) {
        return 'high';
    }
    if (strpos($v, 'cukup') !== false || strpos($v, 'sedang') !== false) {
        return 'medium';
    }
    if (strpos($v, 'relevan') !== false) {
        return 'high';
    }

    return 'medium';
}

/** Samakan jawaban status pekerjaan menjadi slug baku. */
function tracer_normalize_work_status($raw)
{
    if ($raw === null || $raw === '') {
        return null;
    }

    $v = strtolower(trim((string)$raw));

    if (in_array($v, ['bekerja', 'wiraswasta', 'studi_lanjut', 'mencari_kerja'], true)) {
        return $v;
    }

    if (strpos($v, 'wira') !== false || strpos($v, 'usaha') !== false || strpos($v, 'bisnis') !== false) {
        return 'wiraswasta';
    }
    if (strpos($v, 'studi') !== false || strpos($v, 'lanjut') !== false || strpos($v, 'sekolah') !== false || strpos($v, 'kuliah') !== false) {
        return 'studi_lanjut';
    }
    if (strpos($v, 'cari') !== false || strpos($v, 'belum') !== false || strpos($v, 'tidak bekerja') !== false || strpos($v, 'menganggur') !== false) {
        return 'mencari_kerja';
    }
    if (strpos($v, 'kerja') !== false || strpos($v, 'employ') !== false || strpos($v, 'pns') !== false || strpos($v, 'aktif') !== false) {
        return 'bekerja';
    }

    return $v;
}

/** Label siap tampil untuk slug relevansi. */
function tracer_relevance_label($slug)
{
    $peta = ['high' => 'Relevan', 'medium' => 'Cukup Relevan', 'low' => 'Kurang Relevan'];
    return $peta[$slug] ?? 'Tidak Terjawab';
}

/** Label siap tampil untuk slug status pekerjaan. */
function tracer_work_status_label($slug)
{
    $peta = [
        'bekerja'       => 'Bekerja',
        'wiraswasta'    => 'Wiraswasta',
        'studi_lanjut'  => 'Studi Lanjut',
        'mencari_kerja' => 'Mencari Kerja',
    ];
    return $peta[$slug] ?? ($slug ?: 'Tidak Terjawab');
}

// ─────────────────────────────────────────────────────────
// PENYARINGAN & DEDUPLIKASI
// ─────────────────────────────────────────────────────────

/**
 * Bentuk potongan WHERE untuk penyaringan kohort.
 *
 * Mengasumsikan kueri pemanggil memakai alias `ts` untuk tracer_submissions
 * dan `u` untuk users.
 *
 * @param array $f graduation_year, major, start_date, end_date
 * @return array{0:string,1:array} [potongan SQL, parameter]
 */
function tracer_filter_sql(array $f)
{
    $where  = [];
    $params = [];

    if (!empty($f['graduation_year'])) {
        $where[]  = 'u.graduation_year = ?';
        $params[] = (int)$f['graduation_year'];
    }
    if (!empty($f['major'])) {
        $where[]  = 'u.major = ?';
        $params[] = $f['major'];
    }
    if (!empty($f['start_date'])) {
        $where[]  = 'DATE(ts.created_at) >= ?';
        $params[] = $f['start_date'];
    }
    if (!empty($f['end_date'])) {
        $where[]  = 'DATE(ts.created_at) <= ?';
        $params[] = $f['end_date'];
    }

    return [$where ? ' AND ' . implode(' AND ', $where) : '', $params];
}

/**
 * Subkueri berisi id submission TERBARU milik tiap alumnus.
 *
 * Dipakai agar metrik keadaan-kini tidak menghitung alumnus yang mengisi
 * berulang kali lebih dari sekali. MAX(id) dipilih ketimbang MAX(created_at)
 * karena stempel waktu bisa sama persis bila pengisian berdekatan.
 */
function tracer_latest_submission_subquery()
{
    return '(SELECT MAX(id) FROM tracer_submissions GROUP BY user_id)';
}

/**
 * Hitung jumlah responden UNIK dan total submission sekaligus.
 *
 * @return array{responden:int,submission:int}
 */
function tracer_response_counts($pdo, array $filters = [])
{
    [$filterSql, $params] = tracer_filter_sql($filters);

    $sql = "SELECT COUNT(DISTINCT ts.user_id) AS responden, COUNT(*) AS submission
            FROM tracer_submissions ts
            JOIN users u ON ts.user_id = u.id
            WHERE 1=1 $filterSql";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return [
        'responden'  => (int)($row->responden ?? 0),
        'submission' => (int)($row->submission ?? 0),
    ];
}

/**
 * Jumlah alumni yang SEHARUSNYA mengisi — penyebut response rate.
 * Mengikuti penyaringan kohort yang sama agar rasionya masuk akal.
 */
function tracer_expected_respondents($pdo, array $filters = [])
{
    $where  = ["role = 'alumni'", 'is_verified = 1'];
    $params = [];

    if (!empty($filters['graduation_year'])) {
        $where[]  = 'graduation_year = ?';
        $params[] = (int)$filters['graduation_year'];
    }
    if (!empty($filters['major'])) {
        $where[]  = 'major = ?';
        $params[] = $filters['major'];
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

// ─────────────────────────────────────────────────────────
// AGREGASI PERTANYAAN
// ─────────────────────────────────────────────────────────

/** Tipe pertanyaan yang jawabannya tertutup sehingga layak diagregasi. */
function tracer_is_aggregatable($question_type)
{
    return in_array($question_type, ['radio', 'select', 'checkbox', 'rating'], true);
}

/**
 * Hitung distribusi jawaban satu pertanyaan.
 *
 * Membaca langsung kolom JSON `responses` (berkunci q_<id>), sehingga
 * berlaku untuk SEMUA pertanyaan, bukan hanya 5 yang ber-mapping_key.
 *
 * Opsi diambil dari definisi pertanyaan supaya pilihan yang tidak pernah
 * dipilih tetap muncul bernilai 0 — penting agar tabel laporan lengkap.
 *
 * @return array{question:object,options:array,total:int}|null
 */
function tracer_aggregate_question($pdo, $question_id, array $filters = [])
{
    $question_id = (int)$question_id;

    $qs = $pdo->prepare('SELECT id, question_text, question_type, options FROM tracer_questions WHERE id = ?');
    $qs->execute([$question_id]);
    $q = $qs->fetch();

    if (!$q || !tracer_is_aggregatable($q->question_type)) {
        return null;
    }

    // Kunci JSON dibentuk dari bilangan bulat, sehingga tidak dapat disusupi.
    $path = '$."q_' . $question_id . '"';

    [$filterSql, $params] = tracer_filter_sql($filters);
    $latest = tracer_latest_submission_subquery();

    $daftar_opsi = json_decode((string)$q->options, true);
    if (!is_array($daftar_opsi)) {
        $daftar_opsi = [];
    }

    $hasil = [];
    $total = 0;

    if ($q->question_type === 'checkbox') {
        // Jawaban tersimpan sebagai larik JSON — hitung per opsi.
        foreach ($daftar_opsi as $opsi) {
            $sql = "SELECT COUNT(*) FROM tracer_submissions ts
                    JOIN users u ON ts.user_id = u.id
                    WHERE ts.id IN $latest
                      AND JSON_CONTAINS(JSON_EXTRACT(ts.responses, ?), JSON_QUOTE(?))
                      $filterSql";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([$path, $opsi], $params));
            $n = (int)$stmt->fetchColumn();
            $hasil[$opsi] = $n;
            $total += $n;
        }
    } else {
        $sql = "SELECT JSON_UNQUOTE(JSON_EXTRACT(ts.responses, ?)) AS jawaban, COUNT(*) AS jumlah
                FROM tracer_submissions ts
                JOIN users u ON ts.user_id = u.id
                WHERE ts.id IN $latest
                  AND JSON_EXTRACT(ts.responses, ?) IS NOT NULL
                  $filterSql
                GROUP BY jawaban";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$path, $path], $params));

        $terhitung = [];
        foreach ($stmt->fetchAll() as $r) {
            if ($r->jawaban === null || $r->jawaban === '') {
                continue;
            }
            $terhitung[$r->jawaban] = (int)$r->jumlah;
            $total += (int)$r->jumlah;
        }

        // Susun mengikuti urutan opsi resmi lebih dulu, lalu jawaban di luar
        // daftar (bisa muncul bila opsi pernah diubah setelah ada jawaban).
        foreach ($daftar_opsi as $opsi) {
            $hasil[$opsi] = $terhitung[$opsi] ?? 0;
            unset($terhitung[$opsi]);
        }
        foreach ($terhitung as $jawaban => $jumlah) {
            $hasil[$jawaban] = $jumlah;
        }
    }

    return ['question' => $q, 'options' => $hasil, 'total' => $total];
}

/**
 * Daftar pertanyaan yang layak tampil di laporan.
 *
 * @param bool $hanya_aktif Batasi ke pertanyaan yang masih aktif.
 */
function tracer_reportable_questions($pdo, $hanya_aktif = true)
{
    $sql = "SELECT id, question_text, question_type, options, mapping_key, order_no
            FROM tracer_questions
            WHERE question_type IN ('radio','select','checkbox','rating')";

    // Hormati penanda "Tampilkan di laporan" bila kolomnya sudah ada.
    // Diperiksa secara defensif agar berkas ini tetap berfungsi pada basis
    // data yang belum sempat menjalankan migrasi.
    try {
        if ($pdo->query("SHOW COLUMNS FROM tracer_questions LIKE 'show_in_report'")->fetch()) {
            $sql .= ' AND show_in_report = 1';
        }
    } catch (PDOException $e) {
        // abaikan; tampilkan seluruh pertanyaan tertutup
    }

    if ($hanya_aktif) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY order_no ASC, id ASC';

    return $pdo->query($sql)->fetchAll();
}

// ─────────────────────────────────────────────────────────
// SURVEI KEPUASAN PENGGUNA LULUSAN (PENILAIAN ATASAN)
// ─────────────────────────────────────────────────────────

/**
 * Pertanyaan kompetensi pada tracer — dipakai sebagai aspek penilaian atasan.
 *
 * Aspek TIDAK ditulis ulang secara terpisah supaya penilaian atasan dan
 * penilaian diri alumni dijamin memakai aspek dan skala yang sama, sehingga
 * keduanya dapat disandingkan langsung dalam satu tabel.
 */
function tracer_competency_questions($pdo)
{
    $sql = "SELECT id, question_text, options
            FROM tracer_questions
            WHERE question_type IN ('radio','select')
              AND question_text LIKE '%kompetensi%'
            ORDER BY order_no ASC, id ASC";
    return $pdo->query($sql)->fetchAll();
}

/** Ringkas teks pertanyaan kompetensi menjadi label aspek yang pendek. */
function tracer_competency_label($teks)
{
    if (preg_match('/terkait\s+(.+?)\s*\?*\s*$/iu', $teks, $m)) {
        return ucfirst(trim($m[1]));
    }
    return $teks;
}

/**
 * Calon responden atasan, diturunkan dari jawaban tracer yang sudah ada.
 *
 * Kuesioner tracer sudah menanyakan nama, jabatan, dan e-mail atasan langsung
 * ("dengan seizin atasan"), sehingga daftar undangan tidak perlu diisi manual.
 *
 * Hanya submission TERBARU tiap alumnus yang dipakai, dan hanya baris dengan
 * alamat e-mail berformat sah — data lapangan terbukti memuat isian asal
 * seperti "ums" pada kolom e-mail.
 */
function employer_survey_candidates($pdo, array $filters = [])
{
    // Kenali kolom kontak atasan dari teks pertanyaannya, bukan dari id yang
    // dapat berbeda antar instalasi.
    $map = ['nama' => null, 'jabatan' => null, 'email' => null];
    foreach ($pdo->query("SELECT id, question_text FROM tracer_questions")->fetchAll() as $q) {
        $t = strtolower($q->question_text);
        if (strpos($t, 'atasan') === false) {
            continue;
        }
        if ($map['email'] === null && strpos($t, 'email') !== false) {
            $map['email'] = (int)$q->id;
        } elseif ($map['jabatan'] === null && strpos($t, 'jabatan') !== false) {
            $map['jabatan'] = (int)$q->id;
        } elseif ($map['nama'] === null && strpos($t, 'nama') !== false) {
            $map['nama'] = (int)$q->id;
        }
    }

    if ($map['email'] === null) {
        return [];
    }

    [$filterSql, $params] = tracer_filter_sql($filters);
    $latest = tracer_latest_submission_subquery();

    $pEmail   = '$."q_' . $map['email'] . '"';
    $pNama    = $map['nama']    ? '$."q_' . $map['nama'] . '"'    : null;
    $pJabatan = $map['jabatan'] ? '$."q_' . $map['jabatan'] . '"' : null;

    $selNama    = $pNama    ? "JSON_UNQUOTE(JSON_EXTRACT(ts.responses, ?))"    : "NULL";
    $selJabatan = $pJabatan ? "JSON_UNQUOTE(JSON_EXTRACT(ts.responses, ?))" : "NULL";

    $sql = "SELECT ts.id AS submission_id, ts.user_id, u.name AS alumni_name,
                   u.nim, u.graduation_year,
                   JSON_UNQUOTE(JSON_EXTRACT(ts.responses, ?)) AS employer_email,
                   $selNama AS employer_name,
                   $selJabatan AS employer_position,
                   (SELECT COUNT(*) FROM employer_surveys es
                     WHERE es.alumni_user_id = ts.user_id AND es.status <> 'expired') AS sudah_diundang
            FROM tracer_submissions ts
            JOIN users u ON ts.user_id = u.id
            WHERE ts.id IN $latest $filterSql
            ORDER BY u.name ASC";

    $bind = [$pEmail];
    if ($pNama)    { $bind[] = $pNama; }
    if ($pJabatan) { $bind[] = $pJabatan; }
    $bind = array_merge($bind, $params);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);

    $hasil = [];
    foreach ($stmt->fetchAll() as $r) {
        $email = trim((string)$r->employer_email);

        // Saring isian yang bukan alamat e-mail. Tanpa ini undangan akan
        // dikirim ke alamat tak sah dan mengotori reputasi pengiriman.
        $r->email_valid    = (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
        $r->employer_email = $email;
        $hasil[] = $r;
    }

    return $hasil;
}

/**
 * Rekap penilaian atasan per aspek, sejajar dengan penilaian diri alumni.
 *
 * @return array daftar aspek berisi distribusi jawaban dan totalnya
 */
function employer_survey_aggregate($pdo)
{
    $aspek = tracer_competency_questions($pdo);
    if (!$aspek) {
        return [];
    }

    $hasil = [];
    foreach ($aspek as $q) {
        $opsi = json_decode((string)$q->options, true);
        if (!is_array($opsi)) {
            $opsi = [];
        }

        $dist = array_fill_keys($opsi, 0);
        $tot  = 0;

        $stmt = $pdo->prepare(
            "SELECT a.answer_value, COUNT(*) AS n
             FROM employer_survey_answers a
             JOIN employer_surveys s ON a.survey_id = s.id
             WHERE a.question_id = ? AND s.status = 'completed'
             GROUP BY a.answer_value"
        );
        $stmt->execute([$q->id]);

        foreach ($stmt->fetchAll() as $r) {
            $dist[$r->answer_value] = ($dist[$r->answer_value] ?? 0) + (int)$r->n;
            $tot += (int)$r->n;
        }

        $hasil[] = [
            'question' => $q,
            'label'    => tracer_competency_label($q->question_text),
            'options'  => $dist,
            'total'    => $tot,
        ];
    }

    return $hasil;
}
