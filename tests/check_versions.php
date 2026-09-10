<?php
/**
 * Periksa pustaka pihak ketiga terhadap tests/dependencies.json.
 *
 *     php tests/check_versions.php              periksa
 *     php tests/check_versions.php --perbarui   tulis ulang hash setelah update
 *
 * ── Mengapa ini perlu ──────────────────────────────────────────────────
 * PHPMailer dan sembilan pustaka JavaScript disalin manual ke dalam proyek
 * ini. Tanpa Composer atau npm di jalur deploy, tidak ada satu pun mekanisme
 * yang akan memberi tahu bila:
 *
 *   - celah keamanan diumumkan untuk salah satunya;
 *   - seseorang menimpa berkasnya dengan versi lain;
 *   - unduhan rusak separuh dan diam-diam masuk ke repo.
 *
 * Berkas ini tidak menyelesaikan yang pertama — itu tugas manusia membaca
 * catatan rilis. Tetapi ia membuat dua yang terakhir mustahil lewat tanpa
 * ketahuan, dan yang terpenting: ia membuat _dev/DEPENDENSI.md tidak bisa
 * menyimpang dari kenyataan tanpa uji ini berubah merah.
 *
 * Hash dipakai, bukan nomor versi, karena berkas JavaScript minified tidak
 * mencantumkan versinya. Hash menangkap lebih banyak hal daripada nomor.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("CLI only.\n");
}

$akar     = dirname(__DIR__);
$manifest = __DIR__ . '/dependencies.json';
$perbarui = in_array('--perbarui', $argv, true);

if (!is_file($manifest)) {
    fwrite(STDERR, "  tests/dependencies.json tidak ditemukan.\n");
    exit(1);
}

$data = json_decode(file_get_contents($manifest), true);
if (!is_array($data)) {
    fwrite(STDERR, "  tests/dependencies.json tidak dapat dibaca sebagai JSON.\n");
    exit(1);
}

/** Hash pendek yang dipakai manifest. */
function hash_pendek($path)
{
    return substr(hash_file('sha256', $path), 0, 16);
}

$masalah  = 0;
$diperiksa = 0;
$berubah  = false;

// ── PHP: PHPMailer, termasuk konstanta VERSION-nya ───────────────────
foreach ($data['php'] ?? [] as &$lib) {
    foreach ($lib['berkas'] as &$b) {
        $path = $akar . '/' . $b['path'];
        $diperiksa++;

        if (!is_file($path)) {
            printf("  *** HILANG      %s\n", $b['path']);
            $masalah++;
            continue;
        }

        $nyata = hash_pendek($path);
        if ($nyata !== $b['sha256']) {
            if ($perbarui) {
                printf("  diperbarui      %-42s %s -> %s\n", $b['path'], $b['sha256'], $nyata);
                $b['sha256'] = $nyata;
                $berubah = true;
            } else {
                printf("  *** BERUBAH     %s\n", $b['path']);
                printf("      tercatat: %s   sebenarnya: %s\n", $b['sha256'], $nyata);
                $masalah++;
            }
        }
    }
    unset($b);

    // PHPMailer mendeklarasikan versinya sendiri; itu sumber yang lebih
    // kuat daripada apa pun yang ditulis manusia di manifest.
    if ($lib['nama'] === 'PHPMailer') {
        $isi = @file_get_contents($akar . '/includes/PHPMailer/PHPMailer.php');
        if ($isi && preg_match('/const VERSION\s*=\s*\'([^\']+)\'/', $isi, $m)) {
            $diperiksa++;
            if ($m[1] !== $lib['versi']) {
                if ($perbarui) {
                    printf("  diperbarui      PHPMailer versi %s -> %s\n", $lib['versi'], $m[1]);
                    $lib['versi'] = $m[1];
                    $berubah = true;
                } else {
                    printf("  *** VERSI TIDAK COCOK  PHPMailer\n");
                    printf("      manifest: %s   const VERSION: %s\n", $lib['versi'], $m[1]);
                    $masalah++;
                }
            }
        }
    }
}
unset($lib);

// ── JavaScript ───────────────────────────────────────────────────────
foreach ($data['js'] ?? [] as &$lib) {
    $path = $akar . '/' . $lib['path'];
    $diperiksa++;

    if (!is_file($path)) {
        printf("  *** HILANG      %-42s (%s %s)\n", $lib['path'], $lib['nama'], $lib['versi']);
        $masalah++;
        continue;
    }

    $nyata = hash_pendek($path);
    if ($nyata !== $lib['sha256']) {
        if ($perbarui) {
            printf("  diperbarui      %-42s %s -> %s\n", $lib['path'], $lib['sha256'], $nyata);
            $lib['sha256'] = $nyata;
            $berubah = true;
        } else {
            printf("  *** BERUBAH     %-42s (%s %s)\n", $lib['path'], $lib['nama'], $lib['versi']);
            printf("      tercatat: %s   sebenarnya: %s\n", $lib['sha256'], $nyata);
            $masalah++;
        }
    }
}
unset($lib);

// pdf.js dan worker-nya HARUS satu versi; versi berbeda gagal diam-diam.
$pdf = $worker = null;
foreach ($data['js'] ?? [] as $lib) {
    if ($lib['nama'] === 'pdf.js')        { $pdf = $lib['versi']; }
    if ($lib['nama'] === 'pdf.js worker') { $worker = $lib['versi']; }
}
if ($pdf !== null && $worker !== null) {
    $diperiksa++;
    if ($pdf !== $worker) {
        printf("  *** pdf.js (%s) dan worker-nya (%s) beda versi — pemuatan dokumen akan gagal diam-diam\n", $pdf, $worker);
        $masalah++;
    }
}

if ($berubah) {
    file_put_contents($manifest,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo "\n  tests/dependencies.json ditulis ulang. Periksa selisihnya sebelum di-commit.\n";
}

printf("\n  Diperiksa: %d butir.  Tidak cocok: %d.\n", $diperiksa, $masalah);
if ($masalah === 0) {
    echo "  Seluruh pustaka cocok dengan yang tercatat.\n";
} elseif (!$perbarui) {
    echo "  Bila perubahan ini disengaja: php tests/check_versions.php --perbarui\n";
}
exit($masalah > 0 && !$perbarui ? 1 : 0);
