<?php
// Prevent PHP warnings/errors from breaking the JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start(); // Buffer all output (including Sastrawi deprecated notices)

require_once '../includes/memory_helper.php';
require_once '../vendor/autoload.php';
require_once '../includes/config.php';
require_once '../lib/Preprocessing.php';

use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\Tokenization\WhitespaceTokenizer;
use Phpml\Classification\NaiveBayes;
use Phpml\FeatureExtraction\TfIdfTransformer;

/**
 * Helper: buang semua output di buffer (warning, deprecated notice, dsb),
 * set header JSON, lalu echo data dan exit.
 */
function sendJson(array $data, int $status = 200): void
{
    // Bersihkan buffer — ini yang mencegah "Unexpected end of JSON input"
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        // Fallback: paksa konversi UTF-8 jika ada karakter non-UTF8
        array_walk_recursive($data, function (&$v) {
            if (is_string($v)) {
                $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
            }
        });
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    echo $json;
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    if (!isset($_FILES['csv_file']) || !isset($_POST['dataset_name'])) {
        throw new Exception('Missing required fields');
    }

    $dataset_name = trim($_POST['dataset_name']);
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File melebihi batas upload_max_filesize di php.ini',
            UPLOAD_ERR_FORM_SIZE  => 'File melebihi batas MAX_FILE_SIZE di form',
            UPLOAD_ERR_PARTIAL    => 'File hanya terupload sebagian',
            UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang diunggah',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary tidak ditemukan',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk',
        ];
        throw new Exception($uploadErrors[$file['error']] ?? 'File upload error (code: ' . $file['error'] . ')');
    }

    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($fileExtension !== 'csv') {
        throw new Exception('Only CSV files are allowed');
    }

    // Create upload directory if not exists
    $upload_dir = __DIR__ . '/data/uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // Generate unique filename
    $filename = uniqid('dataset_') . '.csv';
    $filepath = $upload_dir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to save file');
    }

    // Read and validate CSV
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        throw new Exception('Cannot read CSV file');
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        unlink($filepath);
        throw new Exception('CSV kosong atau format tidak valid');
    }

    // Cari index kolom Teks (case insensitive)
    $textIndex = -1;
    foreach ($header as $index => $col) {
        if (strtolower(trim($col)) === 'teks' || strtolower(trim($col)) === 'text') {
            $textIndex = $index;
            break;
        }
    }

    if ($textIndex === -1) {
        fclose($handle);
        unlink($filepath);
        throw new Exception('CSV harus memiliki kolom "Teks" atau "text"');
    }

    // Load lexicon untuk labeling otomatis
    $lexicon_path = __DIR__ . '/../data/lexicon/lexicon.txt';
    if (!file_exists($lexicon_path)) {
        fclose($handle);
        unlink($filepath);
        throw new Exception('File lexicon tidak ditemukan');
    }

    $lexicon = file($lexicon_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lexicon_scores = [];
    foreach ($lexicon as $line) {
        $parts = explode(",", $line);
        if (count($parts) === 2) {
            $lexicon_scores[trim($parts[0])] = (int)trim($parts[1]);
        }
    }
    unset($lexicon);

    $samples         = [];
    $labels          = [];
    $preprocessor    = new Preprocessing();
    $processed_texts = []; // hash map O(1) untuk deteksi duplikat

    while (($row = fgetcsv($handle)) !== false) {
        if (!isset($row[$textIndex]) || empty(trim($row[$textIndex]))) {
            continue;
        }

        $text = $row[$textIndex];

        // Preprocessing
        $text         = $preprocessor->convertEmoji($text);
        $text         = $preprocessor->convertEmoticons($text);
        $cleaned_text = $preprocessor->cleanText($text);

        if (empty(trim($cleaned_text))) {
            continue;
        }

        $tokens         = $preprocessor->tokenize($cleaned_text);
        $tokens         = $preprocessor->removeStopwords($tokens);
        $stemmed_tokens = $preprocessor->stemWords($tokens);
        $stemmed_text   = implode(' ', $stemmed_tokens);

        if (empty(trim($stemmed_text))) {
            continue;
        }

        // Skip duplikat dengan hash map O(1)
        if (isset($processed_texts[$cleaned_text])) {
            continue;
        }
        $processed_texts[$cleaned_text] = true;

        // Labeling otomatis berdasarkan lexicon
        $total_score = 0;
        foreach ($stemmed_tokens as $token) {
            if (isset($lexicon_scores[$token])) {
                $total_score += $lexicon_scores[$token];
            }
        }

        $sentiment = 'neutral';
        if ($total_score > 0) {
            $sentiment = 'positive';
        } elseif ($total_score < 0) {
            $sentiment = 'negative';
        }

        $samples[] = $stemmed_text;
        $labels[]  = $sentiment;
    }
    fclose($handle);
    unset($processed_texts, $lexicon_scores);

    if (count($samples) < 10) {
        unlink($filepath);
        throw new Exception(
            'Dataset terlalu kecil. Butuh minimal 10 data valid (setelah preprocessing). ' .
            'Ditemukan: ' . count($samples) . ' data.'
        );
    }

    // Save to database
    $stmt = $conn->prepare(
        "INSERT INTO datasets (filename, original_filename, sample_count, status, created_at) VALUES (?, ?, ?, 'completed', NOW())"
    );
    $sample_count = count($samples);
    $stmt->bind_param("ssi", $filename, $dataset_name, $sample_count);
    $stmt->execute();
    $dataset_id = $conn->insert_id;
    $stmt->close();

    // Train model menggunakan PHP-ML
    $vectorizer = new TokenCountVectorizer(new WhitespaceTokenizer());
    $vectorizer->fit($samples);
    $vectorizer->transform($samples);

    $transformer = new TfIdfTransformer();
    $transformer->fit($samples);
    $transformer->transform($samples);

    $classifier = new NaiveBayes();
    $classifier->train($samples, $labels);

    // Save model ke root/models/ (konsisten dengan SentimentModel.php)
    $model_dir = __DIR__ . '/../models/';
    if (!is_dir($model_dir)) {
        mkdir($model_dir, 0777, true);
    }

    // Simpan vocabulary — PHP-ML getVocabulary() mengembalikan [index=>word],
    // kita butuh [word=>index] agar MyTokenCountVectorizer bisa lookup dengan isset()
    $rawVocab  = $vectorizer->getVocabulary();
    // rawVocab sudah [index=>word] dari array_flip internal, balik lagi ke [word=>index]
    $wordToIdx = array_flip($rawVocab);

    if (empty($wordToIdx)) {
        throw new Exception('Vocabulary kosong setelah training. Periksa isi dataset.');
    }

    $vocabJson = json_encode(
        ['vocabulary' => $wordToIdx],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($vocabJson === false) {
        throw new Exception('Gagal meng-encode vocabulary: ' . json_last_error_msg());
    }
    file_put_contents($model_dir . 'vectorizer.json', $vocabJson);
    file_put_contents($model_dir . 'naive_bayes.dat', serialize($classifier));

    // Save model info to database
    $stmt = $conn->prepare(
        "INSERT INTO models (dataset_id, filename, model_type, created_at) VALUES (?, ?, ?, NOW())"
    );

    $vf         = 'vectorizer.json';
    $model_type = 'vectorizer';
    $stmt->bind_param("iss", $dataset_id, $vf, $model_type);
    $stmt->execute();

    $nf         = 'naive_bayes.dat';
    $model_type = 'naive_bayes';
    $stmt->bind_param("iss", $dataset_id, $nf, $model_type);
    $stmt->execute();
    $stmt->close();

    sendJson([
        'success'       => true,
        'message'       => 'Dataset berhasil diupload dan model berhasil ditraining!',
        'dataset_id'    => $dataset_id,
        'samples_count' => $sample_count,
    ]);

} catch (Exception $e) {
    sendJson(['success' => false, 'error' => $e->getMessage()], 400);
} catch (Error $e) {
    // Tangkap fatal PHP errors (misal: memory exhausted, class not found)
    error_log('upload_dataset.php Fatal Error: ' . $e->getMessage());
    sendJson(['success' => false, 'error' => 'Terjadi kesalahan internal: ' . $e->getMessage()], 500);
}
