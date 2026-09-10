import 'dart:convert';
import 'package:http/http.dart' as http;

/// Service khusus untuk menangani koneksi API ke Backend AlumniLink (PHP Native)
class ApiService {
  // CATATAN PENTING KONFIGURASI URL:
  // - Untuk Android Emulator: Gunakan 'http://10.0.2.2/alumnilink/api'
  // - Untuk iOS Simulator / Chrome Web: Gunakan 'http://localhost/alumnilink/api'
  // - Untuk Device Fisik: Gunakan IPv4 dari komputer Anda (misal: 'http://192.168.1.5/alumnilink/api')
  static const String baseUrl = 'http://10.0.2.2/alumnilink/api';

  /// Fungsi [testBridgeConnection] melakukan handshake ke bridge.php
  /// dan mencetak hasilnya ke console untuk memverifikasi jalur komunikasi.
  static Future<void> testBridgeConnection() async {
    final url = Uri.parse('$baseUrl/bridge.php');

    try {
      print('\n🔄 [API_SERVICE] Mencoba terhubung ke: $url...');
      
      // Melakukan HTTP GET Request
      final response = await http.get(
        url,
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
      ).timeout(const Duration(seconds: 10)); // Timeout guard

      // Cek Status Code (200 OK)
      if (response.statusCode == 200) {
        // Parsing data JSON dari response body
        final Map<String, dynamic> data = json.decode(response.body);
        
        print('✅ [API_SERVICE] Handshake Berhasil!');
        print('=============================================');
        print('Status      : ${data['status']}');
        print('Pesan       : ${data['message']}');
        print('Versi API   : ${data['api_version']}');
        print('Waktu Server: ${data['server_time']}');
        print('Environment : ${data['environment']}');
        print('=============================================\n');
        
      } else {
        // Menangkap error jika status bukan 200 OK
        print('❌ [API_SERVICE] Handshake Gagal!');
        print('Status Code : ${response.statusCode}');
        print('Body Response: ${response.body}\n');
      }
    } catch (e) {
      // Menangkap error koneksi (misal server mati, salah IP, atau CORS error)
      print('🚨 [API_SERVICE] Terjadi Exception Koneksi:');
      print(e.toString());
      print('💡 TIPS TROUBLESHOOTING:');
      print('1. Pastikan server Apache (XAMPP) dalam keadaan RUNNING.');
      print('2. Jika Anda menggunakan Real Device, ganti "10.0.2.2" dengan IP Address WiFi komputer Anda.\n');
    }
  }
}

/* 
 * CONTOH PENGGUNAAN (Bisa dipanggil di fungsi main atau initState Widget):
 *
 * void main() async {
 *   // Inisialisasi binding Flutter
 *   WidgetsFlutterBinding.ensureInitialized();
 *   
 *   // Lakukan test bridge
 *   await ApiService.testBridgeConnection();
 *   
 *   runApp(const MyApp());
 * }
 */
