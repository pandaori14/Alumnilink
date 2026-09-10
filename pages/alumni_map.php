<?php
/**
 * Halaman ini dulu menyertakan header DAN footer-nya sendiri, satu-satunya
 * halaman di pages/ yang melakukannya. Karena index.php juga menyertakan
 * keduanya, footer-nya dirender DUA KALI pada setiap pemuatan: `require_once`
 * di sini menandai berkasnya sudah dimuat, lalu `include` polos di index.php
 * tidak memeriksa penanda itu dan menjalankannya lagi.
 *
 * Pemeriksaan sesi buatan sendiri juga dihapus: ia berjalan setelah header
 * mengirim keluaran, jadi yang tersisa hanyalah pengalihan JavaScript --
 * bukan penjagaan. Penjagaan yang sebenarnya ada di index.php (sesi) dan di
 * api/alumni/geodistribution.php (kedetailan data per peran).
 */
$page_title = "Peta Persebaran Alumni";
require_once __DIR__ . '/../includes/auth_guard.php';

// Fetch filter options from DB
$majors = $pdo->query("SELECT major_name FROM majors ORDER BY major_name ASC")->fetchAll(PDO::FETCH_COLUMN);
$batches = $pdo->query("SELECT DISTINCT graduation_year FROM users WHERE graduation_year IS NOT NULL ORDER BY graduation_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$statuses = ['Bekerja (Full-time)', 'Bekerja (Part-time)', 'Wiraswasta', 'Melanjutkan Pendidikan', 'Mencari Kerja', 'Lainnya'];
?>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="assets/css/leaflet.css">
<!-- MarkerCluster CSS -->
<link rel="stylesheet" href="assets/css/MarkerCluster.css">
<link rel="stylesheet" href="assets/css/MarkerCluster.Default.css">

<style>
    /* Premium UI Customizations */
    #map {
        height: 70vh;
        border-radius: 1rem;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        z-index: 10;
    }
    
    /* Glassmorphism Popup */
    .leaflet-popup-content-wrapper {
        background: rgba(255, 255, 255, 0.85) !important;
        backdrop-filter: blur(12px) !important;
        -webkit-backdrop-filter: blur(12px) !important;
        border: 1px solid rgba(255, 255, 255, 0.3) !important;
        border-radius: 12px !important;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important;
        padding: 4px;
    }
    .leaflet-popup-tip {
        background: rgba(255, 255, 255, 0.85) !important;
        backdrop-filter: blur(12px) !important;
    }
    
    .popup-header {
        font-weight: bold;
        font-size: 1.1rem;
        color: #1f2937;
        margin-bottom: 0.25rem;
        border-bottom: 2px solid #e5e7eb;
        padding-bottom: 0.25rem;
    }
    .popup-content p {
        margin: 0.25rem 0;
        font-size: 0.9rem;
        color: #4b5563;
    }
    .popup-badge {
        display: inline-block;
        padding: 0.1rem 0.5rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        background-color: #dbeafe;
        color: #1e40af;
        margin-top: 0.5rem;
    }

    /* Custom Marker Cluster Styling */
    .marker-cluster-small { background-color: rgba(96, 165, 250, 0.6); }
    .marker-cluster-small div { background-color: rgba(37, 99, 235, 0.8); color: white; }
    .marker-cluster-medium { background-color: rgba(52, 211, 153, 0.6); }
    .marker-cluster-medium div { background-color: rgba(5, 150, 105, 0.8); color: white; }
    .marker-cluster-large { background-color: rgba(248, 113, 113, 0.6); }
    .marker-cluster-large div { background-color: rgba(220, 38, 38, 0.8); color: white; }
</style>

<div class="flex flex-col lg:flex-row gap-6">
    <!-- Sidebar Filter -->
    <div class="w-full lg:w-1/4">
        <div class="bg-white rounded-xl shadow-md p-6 sticky top-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i data-lucide="filter" class="w-4 h-4 inline text-blue-600"></i> Filter Peta
            </h2>
            
            <form id="map-filter-form" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Program Studi</label>
                    <select aria-label="Filter Program Studi" name="major" id="filter-major" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 transition-colors">
                        <option value="">Semua Program Studi</option>
                        <?php foreach($majors as $major): ?>
                            <option value="<?= htmlspecialchars($major) ?>"><?= htmlspecialchars($major) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Angkatan</label>
                    <select aria-label="Filter Angkatan" name="batch" id="filter-batch" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 transition-colors">
                        <option value="">Semua Angkatan</option>
                        <?php foreach($batches as $batch): ?>
                            <option value="<?= htmlspecialchars($batch) ?>"><?= htmlspecialchars($batch) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status Pekerjaan</label>
                    <select aria-label="Filter Status" name="work_status" id="filter-status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 transition-colors">
                        <option value="">Semua Status</option>
                        <?php foreach($statuses as $status): ?>
                            <option value="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="pt-4 flex gap-2">
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-all duration-300 shadow-md hover:shadow-lg flex justify-center items-center gap-2">
                        <i data-lucide="search" class="w-4 h-4 inline"></i> Terapkan
                    </button>
                    <button type="button" id="btn-reset" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 px-4 rounded-lg transition-all duration-300">
                        Reset
                    </button>
                </div>
            </form>
            
            <div class="mt-8 p-4 bg-blue-50 rounded-lg border border-blue-100">
                <div class="flex items-start gap-3">
                    <i data-lucide="info" class="w-4 h-4 inline text-blue-500 mt-1"></i>
                    <div>
                        <h4 class="text-sm font-semibold text-blue-800">Informasi</h4>
                        <p class="text-xs text-blue-600 mt-1 leading-relaxed">
                            Peta ini menampilkan estimasi lokasi domisili atau tempat kerja alumni berdasarkan data yang diberikan. Beberapa titik yang berdekatan akan dikelompokkan ke dalam satu klaster berwarna.
                        </p>
                        <?php if (!is_admin()): ?>
                        <p class="text-xs text-blue-600 mt-2 leading-relaxed">
                            Demi privasi sesama alumni, titik ditampilkan pada tingkat perkiraan (radius sekitar 10&nbsp;km) dan alamat lengkap tidak ditampilkan. Anda dapat menyembunyikan diri dari peta ini lewat halaman <a href="index.php?page=profile" class="font-semibold underline">Profil</a>.
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Map Container -->
    <div class="w-full lg:w-3/4">
        <div class="bg-white rounded-xl shadow-md p-2">
            <div id="map"></div>
        </div>
    </div>
</div>

<!-- Leaflet JS -->
<script src="assets/js/leaflet.js"></script>
<!-- MarkerCluster JS -->
<script src="assets/js/leaflet.markercluster.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Map centered on Indonesia
    const map = L.map('map').setView([-2.548926, 118.0148634], 5);
    
    // Premium Basemap (CartoDB Positron - light and clean)
    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
        subdomains: 'abcd',
        maxZoom: 19
    }).addTo(map);

    // Initialize MarkerClusterGroup
    const markers = L.markerClusterGroup({
        chunkedLoading: true,
        spiderfyOnMaxZoom: true,
        showCoverageOnHover: false,
        zoomToBoundsOnClick: true
    });

    // Function to load and render data
    function loadMapData(filters = '') {
        // Show loading state
        map.spin && map.spin(true); // If spin.js is used, otherwise just optional visual cue
        
        fetch(`api/alumni/geodistribution.php?${filters}`)
            .then(response => response.json())
            .then(data => {
                markers.clearLayers();
                
                if(data.error) {
                    console.error(data.error);
                    return;
                }
                
                if(data.features && data.features.length > 0) {
                    const geoJsonLayer = L.geoJSON(data, {
                        pointToLayer: function (feature, latlng) {
                            // Custom marker icon
                            const icon = L.divIcon({
                                className: 'custom-marker',
                                html: `<div style="background-color:#2563eb; width:16px; height:16px; border-radius:50%; border:2px solid white; box-shadow:0 2px 4px rgba(0,0,0,0.3);"></div>`,
                                iconSize: [16, 16],
                                iconAnchor: [8, 8]
                            });
                            return L.marker(latlng, {icon: icon});
                        },
                        onEachFeature: function (feature, layer) {
                            const props = feature.properties;

                            // Nama, tempat kerja, dan alamat diisi sendiri oleh
                            // alumni. Menyisipkannya mentah ke innerHTML membuat
                            // profil siapa pun bisa menjalankan skrip di peramban
                            // staf yang membuka popupnya.
                            const esc = (v) => String(v ?? '')
                                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');

                            // Baris tempat kerja & alamat hanya dikirim server
                            // kepada staf; bagi alumni propertinya memang tidak
                            // ada, jadi barisnya dilewati.
                            const barisKerja = props.company !== undefined
                                ? `<p><i data-lucide="building-2" class="w-4 h-4 inline text-gray-400"></i> ${esc(props.company)}</p>`
                                : '';
                            const barisAlamat = props.address !== undefined
                                ? `<p><i data-lucide="map-pin" class="w-4 h-4 inline text-gray-400"></i> ${esc(props.address)}</p>`
                                : '';

                            const popupContent = `
                                <div class="popup-header">
                                    <i data-lucide="graduation-cap" class="w-4 h-4 inline text-blue-500 mr-1"></i> ${esc(props.name)}
                                </div>
                                <div class="popup-content">
                                    <p><i data-lucide="book" class="w-4 h-4 inline text-gray-400"></i> ${esc(props.major)} ('${esc(props.batch)})</p>
                                    ${barisKerja}
                                    ${barisAlamat}
                                    <span class="popup-badge">${esc(props.work_status)}</span>
                                </div>
                            `;
                            layer.bindPopup(popupContent, { minWidth: 220 });
                            layer.on("popupopen", function () {
                                if (window.lucide) lucide.createIcons();
                            });
                        }
                    });
                    
                    markers.addLayer(geoJsonLayer);
                    map.addLayer(markers);
                    
                    // Fit bounds if there's data
                    const bounds = markers.getBounds();
                    if(bounds.isValid()) {
                        map.flyToBounds(bounds, { padding: [50, 50], duration: 1.5 });
                    }
                }
            })
            .catch(err => console.error("Error loading map data:", err));
    }

    // Initial load
    loadMapData();

    // Handle filter form submission
    document.getElementById('map-filter-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const searchParams = new URLSearchParams(formData);
        loadMapData(searchParams.toString());
    });

    // Handle reset
    document.getElementById('btn-reset').addEventListener('click', function() {
        document.getElementById('map-filter-form').reset();
        loadMapData();
    });
});
</script>

