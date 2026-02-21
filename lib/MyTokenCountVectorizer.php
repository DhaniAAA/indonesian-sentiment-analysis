<?php

use Phpml\FeatureExtraction\TokenCountVectorizer;
use Phpml\Tokenization\Tokenizer;

/**
 * MyTokenCountVectorizer — extends TokenCountVectorizer dengan kemampuan
 * meng-set vocabulary dari luar (untuk inference menggunakan model tersimpan)
 * dan dukungan n-gram dasar.
 *
 * CATATAN: PHP-ML getVocabulary() mengembalikan [index => word].
 * SentimentModel menyimpan vocabulary sebagai [word => index] di vectorizer.json.
 * Class ini menggunakan $customVocabulary dengan format [word => index].
 */
class MyTokenCountVectorizer extends TokenCountVectorizer
{
    /** @var array [word => index] */
    private array $customVocabulary = [];

    private int $maxNgram = 1;

    /**
     * Set vocabulary dari luar (format: word => index).
     */
    public function setVocabulary(array $vocabulary): void
    {
        $this->customVocabulary = $vocabulary;
    }

    public function setMaxNgram(int $n): void
    {
        $this->maxNgram = max(1, min(3, $n));
    }

    /**
     * Override transform agar menggunakan customVocabulary.
     * Dipanggil dengan $samples by reference (kontrak PHP-ML).
     */
    public function transform(array &$samples, ?array &$targets = null): void
    {
        // Jika tidak ada customVocabulary, gunakan parent
        if (empty($this->customVocabulary)) {
            parent::transform($samples, $targets);
            return;
        }

        $result = [];

        foreach ($samples as $sample) {
            $tokens = $this->getTokenizerSafe()->tokenize((string) $sample);

            // Generate n-gram jika diperlukan
            if ($this->maxNgram > 1) {
                $tokens = $this->generateNgrams($tokens, $this->maxNgram);
            }

            // Inisialisasi semua posisi vocabulary dengan 0
            $counts = array_fill(0, count($this->customVocabulary), 0);

            // Hitung kemunculan token yang ada di vocabulary
            foreach ($tokens as $token) {
                if (isset($this->customVocabulary[$token])) {
                    $idx = $this->customVocabulary[$token];
                    if (isset($counts[$idx])) {
                        $counts[$idx]++;
                    }
                }
            }

            ksort($counts);
            $result[] = $counts;
        }

        $samples = $result;
    }

    /**
     * Akses tokenizer parent yang bersifat private via Reflection.
     */
    private function getTokenizerSafe(): Tokenizer
    {
        try {
            $ref      = new ReflectionClass(TokenCountVectorizer::class);
            $prop     = $ref->getProperty('tokenizer');
            $prop->setAccessible(true);
            return $prop->getValue($this);
        } catch (ReflectionException $e) {
            // Fallback: gunakan WhitespaceTokenizer
            return new \Phpml\Tokenization\WhitespaceTokenizer();
        }
    }

    /**
     * Generate n-gram dari array token.
     * Contoh: ['saya', 'suka'] → ['saya', 'suka', 'saya_suka']
     */
    private function generateNgrams(array $tokens, int $n): array
    {
        if ($n <= 1) {
            return $tokens;
        }

        $result = $tokens;
        $count  = count($tokens);

        for ($k = 2; $k <= $n; $k++) {
            for ($i = 0; $i <= $count - $k; $i++) {
                $result[] = implode('_', array_slice($tokens, $i, $k));
            }
        }

        return $result;
    }
}
