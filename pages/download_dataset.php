<?php
// Load konfigurasi
require_once '../includes/config.php';
require_once '../includes/memory_helper.php';

// Cek apakah ID dataset diberikan
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.1 400 Bad Request');
    echo "ID Dataset diperlukan";
    exit;
}

$dataset_id = (int)$_GET['id'];

// Validasi format dengan whitelist agar tidak bisa dimanipulasi
$allowed_formats = ['csv', 'json'];
$format = isset($_GET['format']) && in_array($_GET['format'], $allowed_formats)
    ? $_GET['format']
    : 'csv';

// Periksa apakah dataset ada
try {
    if (!$conn) {
        throw new Exception('Koneksi database gagal');
    }

    // Ambil informasi dataset
    $stmt = $conn->prepare("SELECT id, original_filename FROM datasets WHERE id = ?");
    $stmt->bind_param("i", $dataset_id);
    if (!$stmt->execute()) {
        throw new Exception('Gagal mengambil informasi dataset');
    }

    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        throw new Exception('Dataset tidak ditemukan');
    }

    $dataset = $result->fetch_assoc();
    $filename = preg_replace('/[^a-zA-Z0-9]/', '_', pathinfo($dataset['original_filename'], PATHINFO_FILENAME));
    $stmt->close();

    // Ambil data dataset
    $stmt = $conn->prepare("SELECT id, text, processed_text, sentiment, score FROM dataset_items WHERE dataset_id = ? ORDER BY id");
    $stmt->bind_param("i", $dataset_id);
    if (!$stmt->execute()) {
        throw new Exception('Gagal mengambil data dataset');
    }

    $result = $stmt->get_result();

    // Set header untuk download
    if ($format == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dataset_' . $filename . '.csv"');

        // Output CSV header
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Teks', 'Teks Preprocessing', 'Sentimen', 'Skor']);

        // Output data CSV
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, [
                $row['id'],
                $row['text'],
                $row['processed_text'],
                $row['sentiment'],
                $row['score']
            ]);
        }

        fclose($output);
    } elseif ($format == 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="dataset_' . $filename . '.json"');

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }

        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    $stmt->close();

} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo $e->getMessage();
}
?>