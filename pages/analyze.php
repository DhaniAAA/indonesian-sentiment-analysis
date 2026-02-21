<?php
// Aktifkan tampilan error (dimatikan di production agar tidak bocor ke JSON)
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Log semua error ke file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../php_error.log');

// Import kelas yang diperlukan (harus berada di level global)
use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\Tokenization\WhitespaceTokenizer;

// Logger sederhana untuk debugging
function debug_log($message, $data = null) {
    $log_file = __DIR__ . '/../debug_analyze.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message";

    if ($data !== null) {
        $log_message .= "\nData: " . print_r($data, true);
    }

    file_put_contents($log_file, $log_message . "\n\n", FILE_APPEND);
}

// Log awal eksekusi
debug_log("===== Mulai eksekusi analyze.php =====");

try {
    // Cek apakah memory_helper.php ada
    if (!file_exists('../includes/memory_helper.php')) {
        throw new Exception('File memory_helper.php tidak ditemukan!');
    }
    debug_log("File memory_helper.php ada");

    // Tambahkan memory helper untuk meningkatkan batas memori
    require_once '../includes/memory_helper.php';
    debug_log("memory_helper.php berhasil diinclude");

    // Cek file-file yang diperlukan
    if (!file_exists('../vendor/autoload.php')) {
        throw new Exception('File vendor/autoload.php tidak ditemukan!');
    }
    debug_log("File vendor/autoload.php ada");

    if (!file_exists('../includes/config.php')) {
        throw new Exception('File config.php tidak ditemukan!');
    }
    debug_log("File config.php ada");

    if (!file_exists('../models/SentimentModel.php')) {
        throw new Exception('File models/SentimentModel.php tidak ditemukan!');
    }
    debug_log("File models/SentimentModel.php ada");

    // Include file-file yang diperlukan
    require_once '../vendor/autoload.php';
    require_once '../includes/config.php';
    require_once '../models/SentimentModel.php';
    debug_log("Semua file berhasil diinclude");

    // Set header JSON
    header('Content-Type: application/json; charset=utf-8');
    mb_internal_encoding('UTF-8');
    debug_log("Header diatur ke application/json; charset=utf-8");

    // Tambahkan cek debug emoji untuk melihat apakah emoji terdeteksi
    $originalText = $_POST['text'] ?? '';
    debug_log("Emoji check - Raw input: " . bin2hex(substr($originalText, 0, 50))); // Lihat representasi hex dari input

    // Periksa method request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('HTTP/1.1 405 Method Not Allowed');
        echo json_encode(['error' => 'Method not allowed']);
        debug_log("Error: Request bukan POST");
        exit;
    }
    debug_log("Request method adalah POST");

    // Ambil teks dari POST
    $text = $_POST['text'] ?? '';
    debug_log("Teks input: " . substr($text, 0, 100) . (strlen($text) > 100 ? '...' : ''));

    if (empty($text)) {
        throw new Exception('Teks tidak boleh kosong');
    }

    // Inisialisasi model sentimen
    debug_log("Inisialisasi SentimentModel");
    $model = new SentimentModel();

    // Analisis sentimen
    debug_log("Menganalisis sentimen");
    $result = $model->analyze($text);

    // Cek jika ada error
    if (isset($result['error']) && $result['error']) {
        throw new Exception($result['message']);
    }

    debug_log("Hasil analisis: " . $result['sentiment'] . " (metode: " . $result['method'] . ")");

    // Bersihkan output buffer sebelum mengirim respons
    if (ob_get_length()) ob_clean();

    // Debug JSON sebelum mengirim
    $json = json_encode($result, JSON_UNESCAPED_UNICODE);
    if (json_last_error() !== JSON_ERROR_NONE) {
        debug_log("JSON Error: " . json_last_error_msg(), $result);
        http_response_code(500);
        echo json_encode(['error' => 'Error saat generate JSON response: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    debug_log("JSON valid, ukuran: " . strlen($json) . " bytes");

    // Kirim respons JSON
    echo $json;
    debug_log("Berhasil mengirim respons JSON");

} catch (Exception $e) {
    // Log error untuk debugging
    debug_log("ERROR: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

    // Bersihkan output buffer sebelum mengirim respons
    if (ob_get_length()) ob_clean();

    // Kirim kode status HTTP error dan pesan error dalam format JSON
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Error $e) {
    // Tangkap semua jenis error PHP
    debug_log("PHP ERROR: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

    // Bersihkan output buffer
    if (ob_get_length()) ob_clean();

    // Kirim respons error
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan internal: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
?>