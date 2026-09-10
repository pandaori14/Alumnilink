<?php
require_once __DIR__ . '/_bootstrap.php';
$BASE = uji_base_url();
$pass=0;$fail=0;
function cek($ok,$l,$d=''){global $pass,$fail;
 if($ok){$pass++;printf("  LULUS  %-46s %s\n",$l,$d);}else{$fail++;printf("  GAGAL  %-46s %s\n",$l,$d);}}
function sesi_palsu($uid,$role){
 $sid=bin2hex(random_bytes(16));
 $path=session_save_path()?:sys_get_temp_dir();
 file_put_contents($path.'/sess_'.$sid,
  "user_id|s:".strlen($uid).":\"$uid\";user_role|s:".strlen($role).":\"$role\";user_name|s:3:\"Uji\";last_activity|i:".time().";csrf_token|s:32:\"".str_repeat('a',32)."\";");
 return $sid;}
function muat($sid,$page){
 global $BASE;
 $ch=curl_init("$BASE/index.php?page=$page");
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_COOKIE=>'PHPSESSID='.$sid]);
 $b=curl_exec($ch);$c=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 return [$c,(string)$b];}
/** Hitung tag struktural HANYA di awal baris, supaya template string di
 *  dalam JavaScript (frame.srcdoc = `<!DOCTYPE ...`) tidak ikut terhitung. */
function n($b,$re){ return preg_match_all($re,$b); }

$pdo->exec("UPDATE users SET is_verified = 1 WHERE role='alumni' AND id='alumni_6a0d761f16121'");
$uid_sa = $pdo->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn();
$uid_al = 'alumni_6a0d761f16121';
$sa=sesi_palsu($uid_sa,'super_admin');
$al=sesi_palsu($uid_al,'alumni');
printf("super_admin=%s  alumni=%s\n", $uid_sa, $uid_al);

foreach ([['super_admin',$sa,['dashboard','profile','admin_settings','admin_users','admin_alumni','admin_alumni_import','alumni_map','admin_legalisir','admin_logs']],
          ['alumni',$al,['dashboard','profile','alumni_map','legalisir']]] as [$peran,$sid,$halaman]) {
  echo "\n[$peran]\n";
  foreach ($halaman as $p) {
    [$c,$b]=muat($sid,$p);
    $doctype=n($b,'/^<!DOCTYPE html>/mi');
    $html   =n($b,'/^<\/html>/mi');
    $body   =n($b,'/^<body[^>]*>/mi');
    $fatal  =strpos($b,'Fatal error')!==false || strpos($b,'Parse error')!==false;
    $warn   =preg_match('/\b(Warning|Notice|Deprecated):\s/',$b);
    $ua     =strpos($b,'ui-avatars')!==false;
    $ok = $c===200 && $doctype===1 && $html===1 && $body===1 && !$fatal && !$warn && !$ua;
    cek($ok,$p,"HTTP $c | doctype=$doctype body=$body /html=$html".
      ($fatal?' FATAL':'').($warn?' WARNING':'').($ua?' UI-AVATARS':''));
  }
}

echo "\n[perilaku]\n";
[,$b]=muat($al,'dashboard');
cek(preg_match('/href="(index.php?page=)?alumni_map"/',$b)===1,'menu Peta tampil bagi alumni');
cek(preg_match('/href="(index.php?page=)?admin_users"/',$b)===0,'menu Kelola User tersembunyi dari alumni');
[,$b]=muat($al,'profile');
cek(strpos($b,'name="map_participate"')!==false,'sakelar peta ada di halaman Profil');
cek(strpos($b,'avatar.php?name=')!==false,'avatar dilayani secara lokal');
[,$b]=muat($sa,'admin_settings');
foreach (['alumni_map','admin_tracer_report','admin_employer_survey','admin_email_layouts','admin_analytics','admin_majors','guide'] as $k) {
  cek(strpos($b,'menus[alumni]['.$k.']')!==false || strpos($b,'value="'.$k.'"')!==false,
      "Pengaturan menampilkan kotak centang '$k'");
}

echo "\n[akses langsung]\n";
foreach (['pages/alumni_map.php','pages/admin_users.php','includes/header.php','includes/menu.php'] as $u) {
  $ch=curl_init("$BASE/$u");
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
  curl_exec($ch);$c=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
  cek($c===403,"$u ditolak","HTTP $c");
}

foreach([$sa,$al] as $s){@unlink((session_save_path()?:sys_get_temp_dir()).'/sess_'.$s);}
echo "\n────────────────────────────────\n  LULUS: $pass   GAGAL: $fail\n";
exit($fail?1:0);
