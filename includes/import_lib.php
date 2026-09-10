<?php
/**
 * Pustaka impor massal alumni.
 *
 * Dipakai bersama oleh handlers/admin_alumni_import.php (impor CSV) dan
 * handlers/admin_alumni_handler.php (entri manual satu per satu), supaya
 * aturan yang menentukan "data alumni yang sah itu seperti apa" hanya ada
 * di SATU tempat. Bila dibiarkan terpisah, formulir manual dan impor akan
 * menyimpang — dan yang longgar akan menjadi pintu masuk data rusak.
 *
 * Format CSV sengaja dibuat SAMA PERSIS dengan keluaran
 * handlers/export_handler.php?type=alumni, sehingga ekspor → sunting di
 * Excel → impor kembali menjadi putaran yang utuh, dan berkas contohnya
 * cukup berupa hasil ekspor tanpa baris data.
 */

require_once __DIR__ . '/settings.php';

/** Kolom CSV yang dikenali, berikut nama-nama alias yang diterima. */
function import_column_aliases()
{
    return [
        'nim'             => ['nim'],
        'name'            => ['nama', 'name', 'namalengkap'],
        'email'           => ['email', 'surel', 'alamatemail'],
        'graduation_year' => ['tahunlulus', 'graduationyear', 'tahun', 'angkatan'],
        'major'           => ['prodi', 'major', 'programstudi', 'jurusan'],
        'ipk'             => ['ipk', 'gpa'],
        'phone'           => ['telepon', 'phone', 'nohp', 'hp', 'nomorhp', 'notelepon'],
        'address'         => ['alamat', 'address', 'alamatdomisili'],
    ];
}

/** Urutan kolom pada berkas contoh — sama dengan hasil Ekspor Excel. */
function import_template_header()
{
    return ['NIM', 'Nama', 'Email', 'Tahun Lulus', 'Prodi', 'IPK', 'Telepon', 'Alamat'];
}

/** Samakan bentuk nama kolom: huruf kecil, tanpa spasi dan tanda baca. */
function import_normalize_key($s)
{
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$s)));
}

/**
 * Ubah nomor telepon ke bentuk +62.
 *
 * Dipindahkan ke sini dari handlers/update_profile.php dan dipanggil oleh
 * keduanya. Kalau tidak, nomor yang masuk lewat impor dan lewat formulir
 * profil akan tersimpan dalam dua bentuk berbeda, dan pencarian nomor
 * tidak akan pernah cocok.
 */
function import_normalize_phone($raw)
{
    $phone = trim((string)$raw);
    if ($phone === '') {
        return null;
    }
    $phone = preg_replace('/[\s\-\(\)\.]+/', '', $phone);
    if ($phone === '' || !preg_match('/^\+?[0-9]+$/', $phone)) {
        return null;
    }
    if (substr($phone, 0, 1) === '0') {
        $phone = '+62' . substr($phone, 1);
    } elseif (substr($phone, 0, 2) === '62') {
        $phone = '+' . $phone;
    } elseif (substr($phone, 0, 1) !== '+') {
        $phone = '+' . $phone;
    }
    return $phone;
}

/**
 * Peta pencarian prodi: kode maupun nama (dinormalkan) -> major_code.
 * Diambil dari tabel majors yang hidup, bukan daftar yang ditulis di kode —
 * kode prodi di basis data produksi sudah berbeda dari nilai semaian awal.
 */
function import_major_index($pdo)
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    foreach ($pdo->query("SELECT major_code, major_name FROM majors") as $m) {
        $cache[import_normalize_key($m->major_code)] = $m->major_code;
        $cache[import_normalize_key($m->major_name)] = $m->major_code;
    }
    return $cache;
}

/** Daftar kode prodi yang sah, untuk ditampilkan pada pesan galat. */
function import_major_labels($pdo)
{
    $out = [];
    foreach ($pdo->query("SELECT major_code, major_name FROM majors ORDER BY major_code") as $m) {
        $out[] = $m->major_code . ' (' . $m->major_name . ')';
    }
    return $out;
}

/** Id baris baru, mengikuti pola pendaftaran mandiri. */
function import_generate_id()
{
    return 'usr_' . bin2hex(random_bytes(8)) . '_' . time();
}

/**
 * Aturan NIM.
 *
 * Sengaja TIDAK diwujudkan sebagai UNIQUE di basis data pada rilis ini:
 * sudah ada satu NIM ganda dan beberapa NIM ngawur ('a', '1',
 * 'Admin Tracer'), sehingga ALTER TABLE akan gagal — dan blok migrasi di
 * config/db.php hanya punya satu catch di paling luar yang menutup seluruh
 * situs dengan die(). Penegakannya dilakukan di lapisan aplikasi dulu.
 */
function import_nim_is_valid($nim)
{
    return (bool)preg_match('/^[A-Za-z0-9]{6,20}$/', (string)$nim);
}

/**
 * Baca berkas CSV menjadi larik asosiatif.
 *
 * @return array{ok:bool, error:string, rows:array, header:array, missing:array}
 */
function import_read_csv($path, $maxRows)
{
    $hasil = ['ok' => false, 'error' => '', 'rows' => [], 'header' => [], 'missing' => []];

    $fh = @fopen($path, 'r');
    if (!$fh) {
        $hasil['error'] = 'Berkas tidak dapat dibuka.';
        return $hasil;
    }

    // Excel berbahasa Indonesia menulis pemisah titik-koma, dan penulisan
    // CSV oleh exporter kita diawali BOM UTF-8. Keduanya lazim, jadi
    // ditangani di sini alih-alih menyuruh operator memperbaiki berkasnya.
    $baris_pertama = fgets($fh);
    if ($baris_pertama === false) {
        fclose($fh);
        $hasil['error'] = 'Berkas kosong.';
        return $hasil;
    }
    $baris_pertama = preg_replace('/^\xEF\xBB\xBF/', '', $baris_pertama);
    $pemisah = substr_count($baris_pertama, ';') > substr_count($baris_pertama, ',') ? ';' : ',';

    rewind($fh);
    // Buang BOM sekali lagi pada aliran yang sudah di-rewind.
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }

    $judul = fgetcsv($fh, 0, $pemisah);
    if (!$judul) {
        fclose($fh);
        $hasil['error'] = 'Baris judul kolom tidak terbaca.';
        return $hasil;
    }

    // Petakan posisi kolom -> nama kanonik.
    $alias = import_column_aliases();
    $posisi = [];
    foreach ($judul as $i => $j) {
        $k = import_normalize_key($j);
        foreach ($alias as $kanonik => $daftar) {
            if (in_array($k, $daftar, true)) {
                $posisi[$kanonik] = $i;
                break;
            }
        }
    }
    $hasil['header'] = array_keys($posisi);

    // Nama dan e-mail adalah identitas minimum; tanpa keduanya baris tidak
    // dapat dinilai sama sekali.
    foreach (['name', 'email'] as $wajib) {
        if (!isset($posisi[$wajib])) {
            $hasil['missing'][] = $wajib;
        }
    }
    if ($hasil['missing']) {
        fclose($fh);
        $hasil['error'] = 'Kolom wajib tidak ditemukan: ' . implode(', ', $hasil['missing'])
            . '. Kolom yang terbaca: ' . (implode(', ', array_map('strval', $judul)) ?: '(kosong)');
        return $hasil;
    }

    $rows = [];
    $no = 1; // baris judul sudah lewat
    while (($data = fgetcsv($fh, 0, $pemisah)) !== false) {
        $no++;
        // Lewati baris yang seluruhnya kosong (lazim di akhir berkas Excel).
        if (count($data) === 1 && trim((string)$data[0]) === '') {
            continue;
        }
        if (count($rows) >= $maxRows) {
            fclose($fh);
            $hasil['error'] = "Berkas melebihi batas $maxRows baris. "
                . 'Pecah menjadi beberapa berkas agar setiap impor tetap dapat ditinjau.';
            return $hasil;
        }
        $baris = ['_line' => $no];
        foreach ($posisi as $kanonik => $i) {
            $baris[$kanonik] = isset($data[$i]) ? trim((string)$data[$i]) : '';
        }
        $rows[] = $baris;
    }
    fclose($fh);

    if (!$rows) {
        $hasil['error'] = 'Tidak ada baris data setelah judul kolom.';
        return $hasil;
    }

    $hasil['ok']   = true;
    $hasil['rows'] = $rows;
    return $hasil;
}

/**
 * Nilai seluruh baris: bentuk datanya, duplikat di dalam berkas, dan
 * bentrokan dengan data yang sudah ada.
 *
 * Vonis per baris:
 *   create   - akan dibuat
 *   update   - e-mail sudah ada; hanya mengisi kolom yang masih kosong
 *   conflict - NIM sudah dipakai orang lain; butuh keputusan manusia
 *   error    - datanya tidak sah, tidak akan disentuh
 *
 * @return array{rows:array, ringkasan:array}
 */
function import_evaluate($pdo, array $rows)
{
    $indexProdi  = import_major_index($pdo);
    $labelProdi  = import_major_labels($pdo);
    $tahun_kini  = (int)date('Y');

    // Ambil sekali untuk seluruh berkas, bukan satu kueri per baris.
    $existing_email = [];
    foreach ($pdo->query("SELECT id, name, LOWER(email) AS email FROM users WHERE email IS NOT NULL") as $u) {
        $existing_email[$u->email] = ['id' => $u->id, 'name' => $u->name];
    }
    $existing_nim = [];
    foreach ($pdo->query("SELECT id, name, nim, LOWER(email) AS email FROM users WHERE nim IS NOT NULL AND nim <> ''") as $u) {
        // NIM ganda memang sudah ada di data lama; simpan yang pertama saja
        // — yang penting adalah mengetahui bahwa NIM itu sudah terpakai.
        if (!isset($existing_nim[strtolower($u->nim)])) {
            $existing_nim[strtolower($u->nim)] = ['id' => $u->id, 'name' => $u->name, 'email' => $u->email];
        }
    }

    // Duplikat DI DALAM berkas juga harus ketahuan; kalau tidak, baris
    // kedua akan menimpa hasil baris pertama tanpa pernah dilaporkan.
    $lihat_email = [];
    $lihat_nim   = [];
    foreach ($rows as $i => $r) {
        $e = strtolower(trim($r['email'] ?? ''));
        $n = strtolower(trim($r['nim'] ?? ''));
        if ($e !== '') { $lihat_email[$e][] = $r['_line']; }
        if ($n !== '') { $lihat_nim[$n][]   = $r['_line']; }
    }

    $hasil = [];
    $ringkasan = ['create' => 0, 'update' => 0, 'conflict' => 0, 'error' => 0];

    foreach ($rows as $r) {
        $pesan  = [];
        $bersih = ['_line' => $r['_line']];

        // ── Nama ──────────────────────────────────────────────────
        $nama = trim($r['name'] ?? '');
        if ($nama === '' || mb_strlen($nama) < 2) {
            $pesan[] = 'Nama wajib diisi (minimal 2 huruf).';
        } elseif (mb_strlen($nama) > 255) {
            $pesan[] = 'Nama melebihi 255 karakter.';
        }
        $bersih['name'] = $nama;

        // ── E-mail ────────────────────────────────────────────────
        $email = strtolower(trim($r['email'] ?? ''));
        if ($email === '') {
            $pesan[] = 'E-mail wajib diisi.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $pesan[] = 'Format e-mail tidak sah.';
        } elseif (strlen($email) > 255) {
            $pesan[] = 'E-mail melebihi 255 karakter.';
        } elseif (count($lihat_email[$email] ?? []) > 1) {
            $pesan[] = 'E-mail muncul lebih dari sekali di berkas ini (baris '
                     . implode(', ', $lihat_email[$email]) . ').';
        }
        $bersih['email'] = $email;

        // ── NIM ───────────────────────────────────────────────────
        $nim = trim($r['nim'] ?? '');
        $bersih['nim'] = $nim !== '' ? $nim : null;
        if ($nim !== '') {
            if (!import_nim_is_valid($nim)) {
                $pesan[] = "NIM \"$nim\" tidak sah (harus 6-20 huruf/angka tanpa spasi).";
            } elseif (count($lihat_nim[strtolower($nim)] ?? []) > 1) {
                $pesan[] = 'NIM muncul lebih dari sekali di berkas ini (baris '
                         . implode(', ', $lihat_nim[strtolower($nim)]) . ').';
            }
        }

        // ── Tahun lulus ───────────────────────────────────────────
        $tahun = trim($r['graduation_year'] ?? '');
        $bersih['graduation_year'] = null;
        if ($tahun !== '') {
            if (!ctype_digit($tahun) || (int)$tahun < 1950 || (int)$tahun > $tahun_kini + 1) {
                $pesan[] = "Tahun lulus \"$tahun\" di luar rentang 1950-" . ($tahun_kini + 1) . '.';
            } else {
                $bersih['graduation_year'] = (int)$tahun;
            }
        }

        // ── Prodi ─────────────────────────────────────────────────
        $prodi = trim($r['major'] ?? '');
        $bersih['major'] = null;
        if ($prodi !== '') {
            $kunci = import_normalize_key($prodi);
            if (isset($indexProdi[$kunci])) {
                $bersih['major'] = $indexProdi[$kunci];
            } else {
                // Sengaja dijadikan galat, bukan diam-diam dijatuhkan ke
                // prodi bawaan: impor adalah tindakan sengaja dan layak
                // diberi tahu bila datanya tidak dikenali.
                $pesan[] = "Prodi \"$prodi\" tidak dikenali. Yang tersedia: "
                         . implode('; ', $labelProdi) . '.';
            }
        }

        // ── IPK ───────────────────────────────────────────────────
        $ipk = trim($r['ipk'] ?? '');
        $bersih['ipk'] = null;
        if ($ipk !== '') {
            $ipk_n = str_replace(',', '.', $ipk);   // Excel id-ID memakai koma
            if (!is_numeric($ipk_n) || (float)$ipk_n < 0 || (float)$ipk_n > 4) {
                $pesan[] = "IPK \"$ipk\" di luar rentang 0,00-4,00.";
            } else {
                $bersih['ipk'] = round((float)$ipk_n, 2);
            }
        }

        // ── Telepon ───────────────────────────────────────────────
        $tel = trim($r['phone'] ?? '');
        $bersih['phone'] = null;
        if ($tel !== '') {
            $norm = import_normalize_phone($tel);
            if ($norm === null) {
                $pesan[] = "Nomor telepon \"$tel\" tidak dikenali.";
            } else {
                $bersih['phone'] = $norm;
            }
        }

        // ── Alamat ────────────────────────────────────────────────
        $alamat = trim($r['address'] ?? '');
        if (mb_strlen($alamat) > 1000) {
            $pesan[] = 'Alamat melebihi 1000 karakter.';
        }
        $bersih['address'] = $alamat !== '' ? $alamat : null;

        // ── Tentukan vonis ────────────────────────────────────────
        if ($pesan) {
            $vonis = 'error';
        } elseif (isset($existing_email[$email])) {
            $vonis = 'update';
            $lama  = $existing_email[$email];
            $pesan[] = 'E-mail sudah terdaftar atas nama "' . $lama['name'] . '".';
            $bersih['target_id'] = $lama['id'];

            // NIM di baris ini milik orang lain -> jangan sentuh apa pun.
            if ($nim !== '' && isset($existing_nim[strtolower($nim)])
                && $existing_nim[strtolower($nim)]['id'] !== $lama['id']) {
                $vonis = 'error';
                $pesan[] = 'NIM tersebut sudah dipakai pengguna lain ("'
                         . $existing_nim[strtolower($nim)]['name'] . '").';
            }
        } elseif ($nim !== '' && isset($existing_nim[strtolower($nim)])) {
            // NIM cocok tetapi e-mailnya berbeda. Ini persis situasi yang
            // melahirkan NIM ganda yang sudah ada; keputusannya milik manusia.
            $vonis = 'conflict';
            $pesan[] = 'NIM sudah dipakai "' . $existing_nim[strtolower($nim)]['name']
                     . '" dengan e-mail berbeda (' . $existing_nim[strtolower($nim)]['email'] . ').';
        } else {
            $vonis = 'create';
        }

        $bersih['verdict']  = $vonis;
        $bersih['messages'] = $pesan;
        $ringkasan[$vonis]++;
        $hasil[] = $bersih;
    }

    return ['rows' => $hasil, 'ringkasan' => $ringkasan];
}

/** Label dan warna untuk setiap vonis, dipakai di tampilan pratinjau. */
function import_verdict_meta($v)
{
    $peta = [
        'create'   => ['label' => 'Akan dibuat',   'kelas' => 'bg-emerald-100 text-emerald-700', 'ikon' => 'user-plus'],
        'update'   => ['label' => 'Lengkapi data', 'kelas' => 'bg-blue-100 text-blue-700',       'ikon' => 'refresh-cw'],
        'conflict' => ['label' => 'Bentrok NIM',   'kelas' => 'bg-amber-100 text-amber-700',     'ikon' => 'alert-triangle'],
        'error'    => ['label' => 'Tidak sah',     'kelas' => 'bg-red-100 text-red-700',         'ikon' => 'x-circle'],
    ];
    return $peta[$v] ?? ['label' => $v, 'kelas' => 'bg-slate-100 text-slate-600', 'ikon' => 'help-circle'];
}
