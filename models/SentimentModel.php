<?php

/**
 * Kelas SentimentModel
 * Mengelola model analisis sentimen dengan pendekatan hybrid
 * Menggunakan model Naive Bayes dan fallback ke lexicon untuk kata-kata yang tidak ada dalam vocabulary
 */
class SentimentModel
{
    private $vocabulary;
    private $classifier;
    private $lexicon;
    private $preprocessor;
    private $calibrationA = 1.0;
    private $calibrationB = 0.0;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Inisialisasi preprocessor untuk text cleaning
        require_once __DIR__ . '/../lib/Preprocessing.php';
        $this->preprocessor = new Preprocessing();

        gc_enable();
        register_shutdown_function([$this, 'handleShutdown']);

        // Load lexicon
        $this->loadLexicon();

        // Load vocabulary
        $this->loadVocabulary();
    }

    /**
     * Load lexicon sentimen
     */
    private function loadLexicon()
    {
        $this->lexicon = [];
        $lexiconPath = __DIR__ . '/../data/lexicon/lexicon.txt';

        if (file_exists($lexiconPath)) {
            $lines = file($lexiconPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $parts = explode(",", $line);
                if (count($parts) === 2) {
                    $this->lexicon[trim($parts[0])] = (int)trim($parts[1]);
                }
            }
        }
    }

    /**
     * Load vocabulary dari file JSON
     */
    private function loadVocabulary()
    {
        $this->vocabulary = [];
        $vocabularyPath = __DIR__ . '/../models/vectorizer.json';

        if (file_exists($vocabularyPath)) {
            $json = file_get_contents($vocabularyPath);
            $data = json_decode($json, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['vocabulary'])) {
                $this->vocabulary = $data['vocabulary'];
            }

            unset($json, $data);
            gc_collect_cycles();
        }
    }

    /**
     * Load model Naive Bayes
     */
    private function loadClassifier()
    {
        $this->classifier = null;
        $modelPath   = __DIR__ . '/../models/naive_bayes.dat';
        $modelPathGz = __DIR__ . '/../models/naive_bayes.dat.gz';

        // Cari file model yang tersedia
        $fileToLoad = null;
        $isGzip     = false;

        if (file_exists($modelPathGz)) {
            $fileToLoad = $modelPathGz;
            $isGzip     = true;
        } elseif (file_exists($modelPath)) {
            $fileToLoad = $modelPath;
        }

        if ($fileToLoad === null) {
            error_log("Model file not found: $modelPath");
            return;
        }

        // Periksa ukuran file sebelum dimuat
        $fileSize = filesize($fileToLoad);
        if ($fileSize > 500 * 1024 * 1024) {
            error_log("Model file size ($fileSize bytes) is too large to load safely");
            return;
        }

        try {
            $currentLimit = ini_get('memory_limit');
            if ($currentLimit !== '-1') {
                ini_set('memory_limit', '1024M');
            }

            if ($isGzip) {
                $gz     = gzopen($fileToLoad, 'rb');
                $buffer = '';
                while (!gzeof($gz)) {
                    $buffer .= gzread($gz, 1048576);
                }
                gzclose($gz);
                $data = $buffer;
                unset($buffer);
            } else {
                $data = file_get_contents($fileToLoad);
            }

            if ($data === false) {
                error_log("Failed to read model file: $fileToLoad");
                ini_set('memory_limit', $currentLimit);
                return;
            }

            $this->classifier = @unserialize($data);
            unset($data);
            gc_collect_cycles();

            if ($this->classifier === false) {
                error_log("Failed to unserialize model data from: $fileToLoad");
                $this->classifier = null;
            }

            ini_set('memory_limit', $currentLimit);
        } catch (\Exception $e) {
            error_log("Error loading classifier: " . $e->getMessage());
        }
    }

    /**
     * Preprocessing teks
     *
     * @param string $text Teks yang akan diproses
     * @return array Array berisi stemmed_text dan tokens
     */
    public function preprocessText($text)
    {
        // Konversi emoji dan emoticon
        $text = $this->preprocessor->convertEmoji($text);
        $text = $this->preprocessor->convertEmoticons($text);

        // Clean text
        $text = $this->preprocessor->cleanText($text);

        // Terjemahkan bahasa Inggris ke Indonesia
        $text = $this->preprocessor->translateEnglishToIndonesian($text);

        // Tokenize
        $tokens = $this->preprocessor->tokenize($text);

        // Apply negation handling
        $tokens = $this->preprocessor->applyNegation($tokens);

        // Remove stopwords
        $tokens = $this->preprocessor->removeStopwords($tokens);

        // Stemming
        $stemmed_tokens = $this->preprocessor->stemWords($tokens);
        $stemmed_text = implode(' ', $stemmed_tokens);

        return [
            'tokens' => $tokens,
            'stemmed_tokens' => $stemmed_tokens,
            'stemmed_text' => $stemmed_text
        ];
    }

    /**
     * Cek apakah ada kata dalam teks yang cocok dengan vocabulary model
     *
     * @param array $stemmed_tokens Array token yang sudah di-stem
     * @return bool True jika ada minimal satu kata yang cocok
     */
    public function hasVocabularyMatch($stemmed_tokens)
    {
        if (empty($this->vocabulary)) {
            return false;
        }

        foreach ($stemmed_tokens as $token) {
            if (isset($this->vocabulary[$token])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prediksi sentimen menggunakan lexicon
     *
     * @param array $tokens Array token
     * @return array Hasil prediksi dengan lexicon
     */
    public function predictWithLexicon($tokens)
    {
        $total_score = 0;
        $word_sentiment = [];

        // Hitung skor untuk setiap kata
        foreach ($tokens as $token) {
            $score = 0;
            if (isset($this->lexicon[$token])) {
                $score = $this->lexicon[$token];
                $total_score += $score;
            }

            $word_sentiment[$token] = [
                'count' => 1,
                'score' => $score,
                'sentiment' => $score > 0 ? 'positive' : ($score < 0 ? 'negative' : 'neutral')
            ];
        }

        // Tentukan sentimen berdasarkan skor total
        $sentiment = 'neutral';
        if ($total_score > 0) {
            $sentiment = 'positive';
        } elseif ($total_score < 0) {
            $sentiment = 'negative';
        }

        // Hitung probabilitas
        $probabilities = [
            'positive' => 0.33,
            'negative' => 0.33,
            'neutral' => 0.34
        ];

        // Ubah probabilitas berdasarkan skor lexicon
        if ($total_score != 0) {
            $magnitude = min(abs($total_score) / 5, 0.5); // Normalisasi magnitude, maksimal 0.5

            if ($sentiment === 'positive') {
                $probabilities['positive'] = 0.33 + $magnitude;
                $probabilities['negative'] = 0.33 - $magnitude / 2;
                $probabilities['neutral'] = 0.34 - $magnitude / 2;
            } elseif ($sentiment === 'negative') {
                $probabilities['negative'] = 0.33 + $magnitude;
                $probabilities['positive'] = 0.33 - $magnitude / 2;
                $probabilities['neutral'] = 0.34 - $magnitude / 2;
            }
        }

        return [
            'sentiment' => $sentiment,
            'probabilities' => $probabilities,
            'word_sentiment' => $word_sentiment,
            'method' => 'lexicon'
        ];
    }

    /**
     * Prediksi sentimen menggunakan model Naive Bayes
     *
     * @param string $stemmed_text Teks yang sudah di-stem
     * @return array Hasil prediksi dengan model
     */
    public function predictWithModel($stemmed_text)
    {
        require_once __DIR__ . '/../lib/MyTokenCountVectorizer.php';
        require_once __DIR__ . '/../vendor/autoload.php';

        // Gunakan vectorizer untuk transformasi teks
        $vectorizer = new \MyTokenCountVectorizer(new \Phpml\Tokenization\WhitespaceTokenizer());
        $vectorizer->setVocabulary($this->vocabulary);
        $vectorizer->setMaxNgram(2);

        $samples = [$stemmed_text];
        $vectorizer->transform($samples);
        $features = $samples;
        unset($samples);
        gc_collect_cycles();

        // Prediksi menggunakan model
        $sentiment = $this->classifier->predict($features[0]);

        // Simulasikan probabilitas (model asli tidak memberikan probabilities)
        $probabilities = [
            'positive' => 0.33,
            'negative' => 0.33,
            'neutral' => 0.34
        ];

        // Ubah probabilitas untuk kelas yang diprediksi
        $probabilities[$sentiment] = 0.7;

        // Normalisasi probabilitas
        $total = array_sum($probabilities);
        foreach ($probabilities as $key => $value) {
            $probabilities[$key] = $value / $total;
        }

        $probabilities = $this->calibrateProbabilities($probabilities);

        return [
            'sentiment' => $sentiment,
            'probabilities' => $probabilities,
            'method' => 'model'
        ];
    }

    /**
     * Analisis sentimen teks
     *
     * @param string $text Teks yang akan dianalisis
     * @return array Hasil analisis sentimen
     */
    public function analyze($text)
    {
        // Simpan teks asli sebelum preprocessing
        $original_text = $text;

        // Preprocessing teks
        $result = $this->preprocessText($text);
        $tokens = $result['tokens'];
        $stemmed_tokens = $result['stemmed_tokens'];
        $stemmed_text = $result['stemmed_text'];

        // Cek jika tidak ada token
        if (empty($tokens)) {
            return [
                'error' => true,
                'message' => 'Teks terlalu pendek atau hanya berisi kata umum yang dihapus saat preprocessing.'
            ];
        }

        // Cek apakah ada kata dalam vocabulary
        $has_vocab_match = $this->hasVocabularyMatch($stemmed_tokens);

        // Nilai default jika tidak ada path yang terpenuhi (defensive fallback)
        $prediction = [
            'sentiment'    => 'neutral',
            'probabilities' => ['positive' => 0.33, 'negative' => 0.33, 'neutral' => 0.34],
            'method'       => 'default_fallback',
        ];

        // Pilih metode analisis
        if ($has_vocab_match) {
            if ($this->classifier === null) {
                $this->loadClassifier();
            }
            if ($this->classifier !== null) {
                // Gunakan model machine learning
                $prediction = $this->predictWithModel($stemmed_text);
            } else {
                // Gunakan lexicon fallback karena classifier gagal dimuat
                $prediction = $this->predictWithLexicon($tokens);

                // Tambahkan pesan warning jika vocabulary ada tapi model tidak bisa dimuat
                if (!empty($this->vocabulary)) {
                    $prediction['warning'] = true;
                    $prediction['message'] = 'Model tidak dapat dimuat. Menggunakan analisis berbasis lexicon sebagai fallback.';
                    $prediction['sample_words'] = $this->getSampleWords(5);
                }
            }
        } else {
            // Fallback ke lexicon jika tidak ada kata yang cocok dengan vocabulary
            $prediction = $this->predictWithLexicon($tokens);
            $prediction['method'] = 'lexicon (no_vocab_match)';
        }

        // Hitung frekuensi kata untuk word cloud
        $word_frequencies = array_count_values($tokens);
        arsort($word_frequencies);
        $word_frequencies = array_slice($word_frequencies, 0, 50); // Ambil 50 kata teratas

        // Tambahkan informasi tambahan ke hasil
        $emotions = $this->detectEmotions($tokens);
        $word_scores = $this->computeWordContributions($tokens);
        $result = [
            'text' => $original_text,
            'processed_text' => $stemmed_text,
            'preprocessed_text' => $stemmed_text,
            'sentiment' => $prediction['sentiment'],
            'probabilities' => $prediction['probabilities'],
            'word_frequencies' => $word_frequencies,
            'method' => $prediction['method'],
            'emotions' => $emotions,
            'word_scores' => $word_scores
        ];

        // Tambahkan word_sentiment jika menggunakan lexicon
        if (isset($prediction['word_sentiment'])) {
            $result['word_sentiment'] = $prediction['word_sentiment'];
        }

        // Tambahkan warning jika ada
        if (isset($prediction['warning'])) {
            $result['warning'] = $prediction['warning'];
            $result['message'] = $prediction['message'];
            $result['sample_words'] = $prediction['sample_words'];
        }

        unset($tokens, $stemmed_tokens, $prediction, $word_frequencies);
        gc_collect_cycles();
        return $result;
    }

    /**
     * Dapatkan contoh kata dari vocabulary
     *
     * @param int $count Jumlah kata yang akan diambil
     * @return array Array berisi kata-kata contoh
     */
    public function getSampleWords($count = 5)
    {
        if (empty($this->vocabulary)) {
            return [];
        }

        // Ambil hanya kata-kata dari vocabulary, bukan indeks
        $words = array_keys($this->vocabulary);

        // Hanya ambil kata-kata yang valid (bukan angka atau terlalu pendek)
        $filtered_words = array_filter($words, function ($word) {
            return !is_numeric($word) && strlen($word) > 2 && !preg_match('/^\d+$/', $word);
        });

        // Jika tidak ada kata yang valid, kembalikan array kosong
        if (empty($filtered_words)) {
            return ['indonesia', 'sentimen', 'analisis', 'teks', 'positif'];
        }

        // Shuffle array untuk mengacak urutan kata
        shuffle($filtered_words);

        // Ambil $count kata pertama
        return array_slice($filtered_words, 0, $count);
    }

    private function handleShutdown()
    {
        $error = error_get_last();
        if ($error && strpos($error['message'], 'Allowed memory size') !== false) {
            error_log('Memory exhausted during sentiment analysis');
        }
    }

    private function calibrateProbabilities(array $probs): array
    {
        $calibrated = [];
        foreach ($probs as $k => $p) {
            $p = max(min($p, 1 - 1e-6), 1e-6);
            $logit = log($p / (1 - $p));
            $z = $this->calibrationA * $logit + $this->calibrationB;
            $p2 = 1 / (1 + exp(-$z));
            $calibrated[$k] = $p2;
        }
        $sum = array_sum($calibrated);
        foreach ($calibrated as $k => $v) {
            $calibrated[$k] = $v / ($sum ?: 1);
        }
        return $calibrated;
    }

    private function detectEmotions(array $tokens): array
    {
        $lexicons = [
            'senang' => ['senang', 'bahagia', 'gembira', 'puas', 'hepi', 'ceria', 'suka'],
            'marah' => ['marah', 'kesal', 'benci', 'emosi', 'muak', 'geram'],
            'sedih' => ['sedih', 'kecewa', 'terluka', 'galau', 'menyedihkan'],
            'takut' => ['takut', 'cemas', 'khawatir', 'ngeri', 'waswas']
        ];
        $scores = ['senang' => 0, 'marah' => 0, 'sedih' => 0, 'takut' => 0];
        foreach ($tokens as $t) {
            foreach ($lexicons as $emo => $words) {
                if (in_array($t, $words, true)) {
                    $scores[$emo] += 1;
                }
            }
        }
        return $scores;
    }

    private function computeWordContributions(array $tokens): array
    {
        $scores = [];
        foreach ($tokens as $t) {
            $scores[$t] = ($scores[$t] ?? 0) + (isset($this->lexicon[$t]) ? $this->lexicon[$t] : 0);
        }
        return $scores;
    }
}
