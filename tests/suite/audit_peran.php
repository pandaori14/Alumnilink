<?php
require_once __DIR__ . '/_bootstrap.php';
require_once AKAR . '/includes/menu.php';
require_once AKAR . '/includes/auth_guard.php';

$perms  = sidebar_permissions_effective();
$peta   = page_capability_map();
$roles  = ['alumni','admin_tracer','admin_legalisir','keuangan','super_admin'];

$bocor = 0; $buntu = 0;
printf("%-24s %-16s %-8s %-8s %s\n", 'HALAMAN', 'PERAN', 'MENU', 'BOLEH', 'CATATAN');
echo str_repeat('-', 78), "\n";

foreach (all_menu_keys() as $page) {
    foreach ($roles as $role) {
        $menu  = $role === 'super_admin' ? true : in_array($page, $perms[$role] ?? [], true);
        $boleh = ($role === 'alumni' && strpos($page, 'admin_') === 0)
                 ? false                       // index.php:211 memblokir keras
                 : role_can_open_page($page, $role);

        // Halaman yang menjaga dirinya sendiri di baris pertama berkasnya,
        // jadi tidak bergantung pada page_capability_map().
        //   admin_settings -> super_admin saja (pages/admin_settings.php:3)
        //   admin_logs     -> seluruh staf, memang disengaja (baca-saja)
        $penjaga_sendiri = ['admin_settings' => ['super_admin'],
                            'admin_logs'     => ['admin_tracer','admin_legalisir','keuangan','super_admin']];
        if (isset($penjaga_sendiri[$page])) {
            $boleh = in_array($role, $penjaga_sendiri[$page], true);
        }

        $catatan = '';
        if (!$menu && $boleh && strpos($page, 'admin_') === 0) {
            // Halaman admin yang boleh dibuka padahal menunya disembunyikan
            $catatan = '*** IZIN BOCOR ***'; $bocor++;
        } elseif ($menu && !$boleh) {
            $catatan = 'jalan buntu (menu tampil, akses ditolak)'; $buntu++;
        }
        if ($catatan !== '') {
            printf("%-24s %-16s %-8s %-8s %s\n", $page, $role,
                $menu ? 'ya' : 'tidak', $boleh ? 'ya' : 'tidak', $catatan);
        }
    }
}

echo str_repeat('-', 78), "\n";
printf("Izin bocor: %d    Jalan buntu: %d\n", $bocor, $buntu);
