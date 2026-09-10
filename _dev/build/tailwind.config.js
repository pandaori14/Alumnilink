/**
 * Konfigurasi build Tailwind untuk AlumniLink.
 *
 * Menggantikan cdn.tailwindcss.com yang meng-compile CSS di dalam browser
 * pada setiap pemuatan halaman dan memang tidak diperuntukkan bagi produksi.
 */
const colors = ['blue', 'indigo', 'emerald', 'red', 'green', 'orange', 'purple', 'amber', 'slate', 'yellow'];
const shades = ['50', '100', '200', '400', '500', '600', '700'];
const prefixes = ['bg', 'text', 'border', 'from', 'to'];

// Kelas yang dirakit lewat interpolasi PHP, mis. bg-<?php echo $color; ?>-500
// di pages/dashboard.php dan pages/admin_tracer.php. Pemindai Tailwind tidak
// dapat melihatnya, jadi harus didaftarkan manual di sini.
const safelist = [];
for (const p of prefixes) for (const c of colors) for (const s of shades) safelist.push(`${p}-${c}-${s}`);

module.exports = {
  content: [
    '../../*.php',
    '../../pages/**/*.php',
    '../../includes/**/*.php',
    '../../handlers/**/*.php',
    '../../api/**/*.php',
  ],
  safelist,
  theme: {
    extend: {
      fontFamily: {
        outfit: ['Outfit', 'sans-serif'],
        jakarta: ['"Plus Jakarta Sans"', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
