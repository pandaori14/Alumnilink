<?php
require_once __DIR__ . '/_bootstrap.php';
require_once AKAR . '/includes/completeness.php';
$BASE = uji_base_url();
$pass=0;$fail=0;
function cek($ok,$l,$d=''){global $pass,$fail;
 if($ok){$pass++;printf("  LULUS  %-50s %s\n",$l,$d);}else{$fail++;printf("  GAGAL  %-50s %s\n",$l,$d);}}
function sesi_palsu($uid,$role){
 $sid=bin2hex(random_bytes(16));$path=session_save_path()?:sys_get_temp_dir();
 file_put_contents($path.'/sess_'.$sid,"user_id|s:".strlen($uid).":\"$uid\";user_role|s:".strlen($role).":\"$role\";user_name|s:3:\"Uji\";last_activity|i:".time().";csrf_token|s:32:\"".str_repeat('a',32)."\";");
 return $sid;}
function muat($sid,$url){
 $ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>40,CURLOPT_COOKIE=>'PHPSESSID='.$sid]);
 $b=curl_exec($ch);$c=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return [$c,(string)$b];}

// Bandingkan ringkasan agregat dengan hitungan manual per baris
$ring = alumni_completeness_summary($pdo);
$rows = $pdo->query("SELECT * FROM users WHERE role='alumni'")->fetchAll();
$manual = ['nim'=>0,'phone'=>0,'address'=>0,'major'=>0,'year'=>0];
foreach ($rows as $u) {
  if (empty($u->nim) || !preg_match('/^[A-Za-z0-9]{6,20}$/',(string)$u->nim)) $manual['nim']++;
  if ($u->phone===null || $u->phone==='') $manual['phone']++;
  if ($u->address===null || $u->address==='') $manual['address']++;
  if ($u->major===null || $u->major==='') $manual['major']++;
  if ($u->graduation_year===null) $manual['year']++;
}
cek($ring['total']===count($rows),'total alumni cocok',$ring['total'].' vs '.count($rows));
foreach ($ring['rincian'] as $d) {
  cek($d['n']===$manual[$d['filter']], "hitungan \"{$d['label']}\" cocok SQL vs PHP", "{$d['n']} vs {$manual[$d['filter']]}");
}

// Skor per baris masuk akal
$skor_maks = 0; $skor_min = 100;
foreach ($rows as $u) { $s=alumni_completeness_score($u); $skor_maks=max($skor_maks,$s); $skor_min=min($skor_min,$s); }
cek($skor_min>=0 && $skor_maks<=100,'skor per baris dalam 0-100',"$skor_min..$skor_maks");

// Filter "missing" tidak bisa disuntik
cek(alumni_missing_filter_sql("nim' OR 1=1 -- ")==='','nilai filter ngawur menghasilkan string kosong');
cek(alumni_missing_filter_sql('phone')!=='','filter sah menghasilkan SQL');

// Halaman dan paginasi
$uid = $pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn();
$sid = sesi_palsu($uid,'super_admin');
[$c,$b] = muat($sid,"$BASE/index.php?page=admin_alumni");
cek($c===200,'halaman Database Alumni tampil',"HTTP $c");
cek(strpos($b,'Kelengkapan data rata-rata')!==false,'kartu kelengkapan tampil');
cek(strpos($b,'Impor Massal')!==false,'tombol Impor Massal tampil');
cek(strpos($b,'ui-avatars')===false,'nol ui-avatars');
cek(preg_match('/Menampilkan (\d+) dari (\d+) alumni/',$b,$m)===1,'baris jumlah tampil',$m[0]??'-');

// Paginasi sungguhan: kecilkan ukuran halaman lalu hitung kartu
$lama = $pdo->query("SELECT setting_value FROM settings WHERE setting_key='pagination_size'")->fetchColumn();

// Pengaturan dikembalikan lewat shutdown handler, bukan di baris terakhir:
// bila skrip mati di tengah (keluaran dipotong, exception, exit dini), nilai
// yang diubah untuk pengujian akan tertinggal dan MERACUNI uji berikutnya.
register_shutdown_function(function () use ($pdo, $lama) {
    if ($lama !== false) {
        $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='pagination_size'")->execute([$lama]);
    } else {
        $pdo->exec("DELETE FROM settings WHERE setting_key='pagination_size'");
    }
});

$pdo->exec("INSERT INTO settings (setting_key,setting_value) VALUES ('pagination_size','5')
            ON DUPLICATE KEY UPDATE setting_value='5'");   // 5 = batas minimum yang diterima setting_int()
[$c,$b] = muat($sid,"$BASE/index.php?page=admin_alumni");
preg_match('/Menampilkan (\d+) dari (\d+) alumni/',$b,$m);
cek((int)($m[1]??0)===5,'satu halaman berisi tepat 5 kartu',($m[1]??'?').' dari '.($m[2]??'?'));
cek(strpos($b,'halaman 1 dari')!==false,'penanda halaman tampil');
[$c,$b2] = muat($sid,"$BASE/index.php?page=admin_alumni&p=2");
cek($c===200 && $b2!==$b,'halaman 2 berisi baris berbeda');
[$c,$b3] = muat($sid,"$BASE/index.php?page=admin_alumni&p=9999");
cek($c===200,'nomor halaman di luar batas tidak error',"HTTP $c");

// Filter kelengkapan menyaring
[$c,$b4] = muat($sid,"$BASE/index.php?page=admin_alumni&missing=phone");
preg_match('/Menampilkan (\d+) dari (\d+) alumni/',$b4,$m4);
cek((int)($m4[2]??-1)===$manual['phone'],'filter "tanpa telepon" cocok hitungan',($m4[2]??'?').' vs '.$manual['phone']);

if ($lama!==false) { $pdo->prepare("UPDATE settings SET setting_value=? WHERE setting_key='pagination_size'")->execute([$lama]); }
else { $pdo->exec("DELETE FROM settings WHERE setting_key='pagination_size'"); }
@unlink((session_save_path()?:sys_get_temp_dir()).'/sess_'.$sid);
echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail?1:0);
