<?php
require_once '../includes/config.php';

$current_page = 'dataset';
$page_title = 'Dataset Details - Sentiment AI';

// Get dataset ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: train.php');
    exit;
}

$dataset_id = (int)$_GET['id'];
$dataset = null;
$stats = [
    'total' => 0,
    'positive' => 0,
    'negative' => 0,
    'neutral' => 0
];
require_once '../models/SentimentModel.php';
$model = new SentimentModel();
$confusion_matrix = [
    'positive' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
    'neutral' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
    'negative' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
];
$dataset_data = [];
$word_counts = [];
$word_cloud_data = [];

// Get dataset info
if ($conn) {
    $stmt = $conn->prepare("SELECT * FROM datasets WHERE id = ?");
    $stmt->bind_param("i", $dataset_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $dataset = $result->fetch_assoc();

        // Read CSV and calculate stats with auto-labeling
        $filepath = __DIR__ . '/data/uploads/' . $dataset['filename'];
        if (file_exists($filepath)) {
            // Load lexicon
            $lexicon_path = __DIR__ . '/../data/lexicon/lexicon.txt';
            $lexicon_scores = [];

            if (file_exists($lexicon_path)) {
                $lexicon = file($lexicon_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lexicon as $line) {
                    $parts = explode(",", $line);
                    if (count($parts) === 2) {
                        $lexicon_scores[trim($parts[0])] = (int)trim($parts[1]);
                    }
                }
            }

            $handle = fopen($filepath, 'r');
            $header = fgetcsv($handle);

            // Find text column (case insensitive)
            $textIndex = -1;
            foreach ($header as $index => $col) {
                if (strtolower(trim($col)) === 'teks' || strtolower(trim($col)) === 'text') {
                    $textIndex = $index;
                    break;
                }
            }

            // Prepare Temp CSV for Python Training
            $temp_dir = __DIR__ . '/data/temp/';
            if (!is_dir($temp_dir)) mkdir($temp_dir, 0777, true);
            $temp_csv = $temp_dir . 'processed_' . $dataset_id . '.csv';
            $temp_handle = fopen($temp_csv, 'w');
            fputcsv($temp_handle, ['text', 'sentiment']);

            if ($textIndex !== -1) {
                require_once '../lib/Preprocessing.php';
                $preprocessor = new Preprocessing();

                while (($row = fgetcsv($handle)) !== false) {
                    if (!isset($row[$textIndex]) || empty(trim($row[$textIndex]))) {
                        continue;
                    }

                    $text = $row[$textIndex];

                    // Preprocessing
                    $text = $preprocessor->convertEmoji($text);
                    $text = $preprocessor->convertEmoticons($text);
                    $cleaned_text = $preprocessor->cleanText($text);

                    if (empty(trim($cleaned_text))) {
                        continue;
                    }

                    $tokens = $preprocessor->tokenize($cleaned_text);
                    $tokens = $preprocessor->removeStopwords($tokens);
                    $stemmed_tokens = $preprocessor->stemWords($tokens);

                    // Calculate sentiment score
                    $total_score = 0;
                    foreach ($stemmed_tokens as $token) {
                        if (isset($lexicon_scores[$token])) {
                            $total_score += $lexicon_scores[$token];
                        }
                    }

                    // Determine sentiment (Lexicon / Actual)
                    $sentiment = 'neutral';
                    if ($total_score > 0) {
                        $sentiment = 'positive';
                    } elseif ($total_score < 0) {
                        $sentiment = 'negative';
                    }

                    // Write to temp CSV for Python
                    fputcsv($temp_handle, [$cleaned_text, $sentiment]);

                    // Model Prediction (Predicted) - If model exists
                    // We assume the global model is what we want to test against.
                    // Ideally, we'd load a specific model version, but for now use the active one.
                    // This re-analyzes using the full pipeline including preprocessing for consistency.
                    $model_result = $model->analyze($text);
                    $predicted_sentiment = $model_result['sentiment'] ?? 'neutral';

                    // Update Confusion Matrix
                    // Ensure keys exist to avoid warnings if model returns something unexpected (though it shouldn't)
                    if (isset($confusion_matrix[$sentiment][$predicted_sentiment])) {
                        $confusion_matrix[$sentiment][$predicted_sentiment]++;
                    }

                    // Store row data for table
                    $dataset_data[] = [
                        'text' => $text,
                        'actual' => $sentiment,
                        'predicted' => $predicted_sentiment,
                        'score' => $total_score
                    ];

                    // Count words for WordCloud
                    foreach ($stemmed_tokens as $token) {
                        if (strlen($token) > 2) { // Filter very short words
                            if (!isset($word_counts[$token])) {
                                $word_counts[$token] = 0;
                            }
                            $word_counts[$token]++;
                        }
                    }

                    $stats['total']++;
                    if (isset($stats[$sentiment])) {
                        $stats[$sentiment]++;
                    }
                }
            }
            fclose($handle);
            fclose($temp_handle);

            // Execute Python Script for Evaluation
            $python_script = __DIR__ . '/../scripts/train.py';
            $escaped_csv = escapeshellarg($temp_csv);
            $escaped_script = escapeshellarg($python_script);
            $cmd = "python $escaped_script --csv $escaped_csv --dataset_id " . (int)$dataset_id . " 2>&1";
            $output = shell_exec($cmd);

            // Read Python Result JSON
            $json_path = __DIR__ . "/data/testing/test_data_{$dataset_id}.json";
            if (file_exists($json_path)) {
                $py_data = json_decode(file_get_contents($json_path), true);
                if ($py_data) {
                    // Reset Confusion Matrix with Python Data
                    $confusion_matrix = [
                        'positive' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
                        'neutral' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
                        'negative' => ['positive' => 0, 'neutral' => 0, 'negative' => 0],
                    ];

                    $y_test = $py_data['y_test'];
                    $y_pred = $py_data['y_pred'];

                    for ($i = 0; $i < count($y_test); $i++) {
                        $actual = $y_test[$i];
                        $pred = $y_pred[$i];
                        // Normalize casing just in case
                        $actual = strtolower($actual);
                        $pred = strtolower($pred);

                        if (isset($confusion_matrix[$actual][$pred])) {
                            $confusion_matrix[$actual][$pred]++;
                        }
                    }
                }
            }

            // Process word counts for visualization
            arsort($word_counts);
            $top_words = array_slice($word_counts, 0, 100);
            $word_cloud_data = [];
            foreach ($top_words as $word => $count) {
                $word_cloud_data[] = [$word, $count];
            }
        }
    }
    $stmt->close();
}

if (!$dataset) {
    header('Location: train.php');
    exit;
}

// Add WordCloud script to header
$extra_head = '<script src="https://cdnjs.cloudflare.com/ajax/libs/wordcloud2.js/1.2.2/wordcloud2.min.js"></script>';
include '../includes/header.php';
?>

<?php include '../includes/sidebar.php'; ?>

<!-- Main -->
<main class="flex-1 p-4 sm:p-6 lg:p-8">
    <?php include '../includes/mobile_nav.php'; ?>

    <div class="max-w-7xl mx-auto">
        <!-- Header -->
        <header class="mb-6 sm:mb-8">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                <div>
                    <nav class="flex items-center text-sm opacity-70 mb-2" aria-label="Breadcrumb">
                        <a class="hover:underline" href="dashboard.php">Dashboard</a>
                        <span class="mx-2">/</span>
                        <a class="hover:underline" href="train.php">Training</a>
                        <span class="mx-2">/</span>
                        <span aria-current="page">Dataset Details</span>
                    </nav>
                    <h1 class="text-2xl sm:text-4xl font-black tracking-tight"><?php echo htmlspecialchars($dataset['original_filename']); ?></h1>
                </div>
                <div class="flex items-center gap-2 sm:gap-3">
                    <a href="download_dataset.php?id=<?php echo $dataset_id; ?>" class="btn btn-primary">
                        <span class="material-symbols-outlined align-middle text-base sm:text-lg mr-1">download</span>
                        Export
                    </a>
                </div>
            </div>
        </header>

        <!-- Grid -->
        <section class="grid grid-cols-1 xl:grid-cols-3 gap-6 lg:gap-8 auto-rows-fr">
            <!-- Dataset Info -->
            <article class="card">
                <h2 class="text-xl font-bold mb-4">Dataset Info</h2>
                <dl class="space-y-3">
                    <div class="flex items-center justify-between">
                        <dt class="opacity-70">Nama File</dt>
                        <dd class="font-semibold text-xs"><?php echo htmlspecialchars($dataset['original_filename']); ?></dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="opacity-70">Total Data</dt>
                        <dd class="font-semibold"><?php echo number_format($stats['total']); ?></dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="opacity-70">Status</dt>
                        <dd class="font-semibold"><?php echo ucfirst($dataset['status']); ?></dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="opacity-70">Tanggal Upload</dt>
                        <dd class="font-semibold"><?php echo date('d M Y', strtotime($dataset['created_at'])); ?></dd>
                    </div>
                </dl>
            </article>

            <!-- Sentiment Distribution -->
            <article class="card xl:col-span-2">
                <div class="flex items-start justify-between gap-4 mb-4">
                    <h2 class="text-xl font-bold">Distribusi Sentimen</h2>
                    <div class="flex items-center gap-2 text-sm opacity-80">
                        <div class="size-3 rounded-full bg-green-500"></div> <span>Positive</span>
                        <div class="size-3 rounded-full ml-4 bg-red-500"></div> <span>Negative</span>
                        <div class="size-3 rounded-full ml-4 bg-yellow-500"></div> <span>Neutral</span>
                    </div>
                </div>
                <div class="w-full grid grid-cols-1 md:grid-cols-[auto_1fr] gap-6 items-center">
                    <?php
                    $posPercent = $stats['total'] > 0 ? round(($stats['positive'] / $stats['total']) * 100, 1) : 0;
                    $negPercent = $stats['total'] > 0 ? round(($stats['negative'] / $stats['total']) * 100, 1) : 0;
                    $neuPercent = $stats['total'] > 0 ? round(($stats['neutral'] / $stats['total']) * 100, 1) : 0;

                    $posDash = round($posPercent * 100 / 100, 1);
                    $negDash = round($negPercent * 100 / 100, 1);
                    $neuDash = round($neuPercent * 100 / 100, 1);
                    ?>
                    <div class="relative w-56 h-56 md:w-64 md:h-64 mx-auto">
                        <svg class="w-full h-full" viewBox="0 0 36 36" aria-label="Sentiment donut chart" role="img">
                            <title><?php echo "$posPercent% Positive, $negPercent% Negative, $neuPercent% Neutral"; ?></title>
                            <circle r="15.915" cx="18" cy="18" fill="none" stroke="#e5e7eb" stroke-width="3"></circle>
                            <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                                  fill="none" stroke="#ef4444" stroke-dasharray="<?php echo $negDash; ?> <?php echo 100 - $negDash; ?>" stroke-width="4"></path>
                            <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                                  fill="none" stroke="#eab308" stroke-dasharray="<?php echo $neuDash; ?> <?php echo 100 - $neuDash; ?>" stroke-dashoffset="-<?php echo $negDash; ?>" stroke-width="4"></path>
                            <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                                  fill="none" stroke="#22c55e" stroke-dasharray="<?php echo $posDash; ?> <?php echo 100 - $posDash; ?>" stroke-dashoffset="-<?php echo $negDash + $neuDash; ?>" stroke-width="4"></path>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-3xl md:text-4xl font-extrabold"><?php echo number_format($stats['total']); ?></span>
                            <span class="opacity-70 text-sm">Total</span>
                        </div>
                    </div>
                    <ul class="grid grid-cols-3 gap-2 md:gap-4 text-center">
                        <li class="p-3 card shadow-none bg-green-50 border-2 border-black">
                            <p class="text-xs opacity-70">Positive</p>
                            <p class="text-2xl font-bold"><?php echo $posPercent; ?>%</p>
                            <p class="text-xs opacity-50"><?php echo number_format($stats['positive']); ?> data</p>
                        </li>
                        <li class="p-3 card shadow-none bg-red-50 border-2 border-black">
                            <p class="text-xs opacity-70">Negative</p>
                            <p class="text-2xl font-bold"><?php echo $negPercent; ?>%</p>
                            <p class="text-xs opacity-50"><?php echo number_format($stats['negative']); ?> data</p>
                        </li>
                        <li class="p-3 card shadow-none bg-yellow-50 border-2 border-black">
                            <p class="text-xs opacity-70">Neutral</p>
                            <p class="text-2xl font-bold"><?php echo $neuPercent; ?>%</p>
                            <p class="text-xs opacity-50"><?php echo number_format($stats['neutral']); ?> data</p>
                        </li>
                    </ul>
                </div>
            </article>
        </section>

        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6 lg:gap-8 mt-6">
            <!-- Word Cloud -->
            <article class="card lg:col-span-2">
                <h2 class="text-xl font-bold mb-4">
                    <span class="material-symbols-outlined align-middle mr-2">cloud</span>
                    Word Cloud Dataset
                </h2>
                <div class="w-full h-[400px] border-2 border-black bg-gray-50 flex items-center justify-center relative" id="wordCloudContainer">
                    <canvas id="wordCloudCanvas" class="w-full h-full"></canvas>
                </div>
                <p class="text-sm opacity-70 mt-2 text-center">Kata-kata yang paling sering muncul dalam dataset (setelah preprocessing)</p>
            </article>
        </section>

        <!-- Model Evaluation -->
        <section class="mt-8">
            <h2 class="text-2xl font-black mb-6 border-b-4 border-black inline-block pr-8">Evaluasi Model</h2>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-8">
                <!-- Confusion Matrix -->
                <article class="card">
                    <h3 class="text-xl font-bold mb-4">Confusion Matrix</h3>
                    <!-- <p class="text-sm opacity-70 mb-4">Perbandingan Label Aktual (Lexicon) vs Prediksi Model Python (Naive Bayes)</p> -->

                    <div class="overflow-x-auto">
                        <table class="w-full text-center border-collapse">
                            <thead>
                                <tr>
                                    <th class="p-2 border-2 border-transparent"></th>
                                    <th colspan="3" class="p-2 border-2 border-black bg-gray-100 font-bold">Predicted Sentiment</th>
                                </tr>
                                <tr>
                                    <th class="p-2 border-2 border-black bg-gray-100 font-bold w-1/4">Actual Sentiment</th>
                                    <th class="p-2 border-2 border-black font-bold text-green-600 bg-green-50">Positive</th>
                                    <th class="p-2 border-2 border-black font-bold text-yellow-600 bg-yellow-50">Neutral</th>
                                    <th class="p-2 border-2 border-black font-bold text-red-600 bg-red-50">Negative</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <th class="p-2 border-2 border-black font-bold text-green-600 bg-green-50">Positive</th>
                                    <td class="p-4 border-2 border-black font-mono text-xl font-bold bg-green-100"><?php echo $confusion_matrix['positive']['positive']; ?></td>
                                    <td class="p-4 border-2 border-black font-mono text-xl"><?php echo $confusion_matrix['positive']['neutral']; ?></td>
                                    <td class="p-4 border-2 border-black font-mono text-xl"><?php echo $confusion_matrix['positive']['negative']; ?></td>
                                </tr>
                                <tr>
                                    <th class="p-2 border-2 border-black font-bold text-yellow-600 bg-yellow-50">Neutral</th>
                                    <td class="p-4 border-2 border-black font-mono text-xl"><?php echo $confusion_matrix['neutral']['positive']; ?></td>
                                    <td class="p-4 border-2 border-black font-mono text-xl font-bold bg-yellow-100"><?php echo $confusion_matrix['neutral']['neutral']; ?></td>
                                    <td class="p-4 border-2 border-black font-mono text-xl"><?php echo $confusion_matrix['neutral']['negative']; ?></td>
                                </tr>
                                <tr>
                                    <th class="p-2 border-2 border-black font-bold text-red-600 bg-red-50">Negative</th>
                                    <td class="p-4 border-2 border-black font-mono text-xl"><?php echo $confusion_matrix['negative']['positive']; ?></td>
                                    <td class="p-4 border-2 border-black font-mono text-xl"><?php echo $confusion_matrix['negative']['neutral']; ?></td>
                                    <td class="p-4 border-2 border-black font-mono text-xl font-bold bg-red-100"><?php echo $confusion_matrix['negative']['negative']; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>

                <!-- Model Metrics -->
                <article class="card">
                    <h3 class="text-xl font-bold mb-4">Performa Model</h3>
                    <?php
                        // Simple accuracy calculation
                        $correct = $confusion_matrix['positive']['positive'] + $confusion_matrix['neutral']['neutral'] + $confusion_matrix['negative']['negative'];
                        $total_samples = array_sum(array_map('array_sum', $confusion_matrix));
                        $accuracy = $total_samples > 0 ? ($correct / $total_samples) * 100 : 0;
                    ?>
                    <div class="grid grid-cols-1 gap-4">
                        <div class="bg-black text-white p-4 border-2 border-black shadow-[4px_4px_0_0_rgba(0,0,0,0.2)]">
                            <h4 class="text-sm uppercase tracking-widest opacity-80 mb-1">Akurasi Global</h4>
                            <p class="text-4xl font-black font-mono"><?php echo number_format($accuracy, 1); ?>%</p>
                        </div>
                        <div class="p-4 border-2 border-black bg-blue-50">
                            <h4 class="font-bold mb-2">Insight</h4>
                            <p class="text-sm opacity-80">
                                Dari <strong><?php echo number_format($total_samples); ?></strong> data, model berhasil memprediksi <strong><?php echo number_format($correct); ?></strong> data dengan tepat sesuai label lexicon.
                            </p>
                            <p class="text-xs mt-2 opacity-60">*Catatan: Ini adalah akurasi training (model diuji pada data yang sama dengan data training).</p>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <!-- Classification Table -->
        <section class="mt-8">
            <h2 class="text-2xl font-black mb-6 border-b-4 border-black inline-block pr-8">Data Klasifikasi</h2>
            <div class="card overflow-hidden p-0">
                <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-black text-white sticky top-0 z-10">
                            <tr>
                                <th class="p-4 font-bold border-b-2 border-black">Teks</th>
                                <th class="p-4 font-bold border-b-2 border-black w-32">Actual</th>
                                <th class="p-4 font-bold border-b-2 border-black w-32">Predicted</th>
                                <th class="p-4 font-bold border-b-2 border-black w-24 text-center">Match</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y-2 divide-gray-100">
                            <?php foreach ($dataset_data as $row):
                                $is_match = $row['actual'] === $row['predicted'];
                                $actual_color = $row['actual'] === 'positive' ? 'text-green-600 bg-green-50' : ($row['actual'] === 'negative' ? 'text-red-600 bg-red-50' : 'text-yellow-600 bg-yellow-50');
                                $pred_color = $row['predicted'] === 'positive' ? 'text-green-600 bg-green-50' : ($row['predicted'] === 'negative' ? 'text-red-600 bg-red-50' : 'text-yellow-600 bg-yellow-50');
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="p-4 text-sm font-mono border-r border-gray-100"><?php echo htmlspecialchars(mb_strimwidth($row['text'], 0, 100, "...")); ?></td>
                                <td class="p-4 text-xs font-bold uppercase border-r border-gray-100">
                                    <span class="px-2 py-1 rounded-sm border border-black/10 <?php echo $actual_color; ?>">
                                        <?php echo $row['actual']; ?>
                                    </span>
                                </td>
                                <td class="p-4 text-xs font-bold uppercase border-r border-gray-100">
                                    <span class="px-2 py-1 rounded-sm border border-black/10 <?php echo $pred_color; ?>">
                                        <?php echo $row['predicted']; ?>
                                    </span>
                                </td>
                                <td class="p-4 text-center">
                                    <?php if($is_match): ?>
                                        <span class="material-symbols-outlined text-green-500 font-bold">check</span>
                                    <?php else: ?>
                                        <span class="material-symbols-outlined text-red-500 font-bold">close</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Ambil data, lalu potong (slice) hanya 0 sampai 50 kata pertama
        const rawList = <?php echo json_encode($word_cloud_data); ?>;
        const wordList = rawList.slice(0, 100);

        if (wordList.length > 0) {
            const canvas = document.getElementById('wordCloudCanvas');
            const container = document.getElementById('wordCloudContainer');

            // Resize canvas to match container
            canvas.width = container.offsetWidth;
            canvas.height = container.offsetHeight;

            WordCloud(canvas, {
                list: wordList,
                gridSize: 8,
                weightFactor: function (size) {
                    return Math.max(16, (size / wordList[0][1]) * 60); // Scale based on max freq
                },
                fontFamily: 'Space Mono, monospace',
                color: function (word, weight) {
                    const colors = ['#8B5CF6', '#10B981', '#EF4444', '#F59E0B', '#111827'];
                    return colors[Math.floor(Math.random() * colors.length)];
                },
                rotateRatio: 0,
                rotationSteps: 0,
                backgroundColor: 'transparent',
                drawOutOfBound: false,
                shrinkToFit: true
            });

            // Handle resize
            window.addEventListener('resize', function() {
                canvas.width = container.offsetWidth;
                canvas.height = container.offsetHeight;
                // Re-render handled by library or page reload usually, simple debounce in real app
            });
        }
    });

    // Theme toggle logic removal - Cleanup if any traces left
</script>

<?php include '../includes/footer.php'; ?>
