<?php

/**
 * Kelas Preprocessing
 * Berisi fungsi-fungsi untuk melakukan preprocessing teks
 */
class Preprocessing
{
    private $emojiMap;
    private $stopwords;
    private $stemmer;
    private $emoticons;
    private $englishIdDictionary; // Kamus Bahasa Inggris-Indonesia
    private $negationWords;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Load emoji mapping
        $emojiJson = file_get_contents(__DIR__ . '/../data/emoji_convert.json');
        $this->emojiMap = json_decode($emojiJson, true) ?: [];

        // Load stopwords (kata-kata yang akan dihilangkan)
        $stopwordsFile = file_get_contents(__DIR__ . '/../data/stopwords_id.txt');
        $this->stopwords = explode("\n", $stopwordsFile);
        $this->stopwords = array_filter($this->stopwords);

        // Initiate stemmer (Sastrawi untuk Bahasa Indonesia)
        require_once __DIR__ . '/../vendor/autoload.php';
        $stemmerFactory = new \Sastrawi\Stemmer\StemmerFactory();
        $this->stemmer = $stemmerFactory->createStemmer();

        // Load emoticons dari file
        $emoticonsJson = file_get_contents(__DIR__ . '/../data/emoticons.json');
        $this->emoticons = json_decode($emoticonsJson, true) ?: [];

        // Load kamus Bahasa Inggris-Indonesia
        $englishIdJson = file_get_contents(__DIR__ . '/../data/english_id.json');
        $this->englishIdDictionary = json_decode($englishIdJson, true) ?: [];

        // Kata negasi umum Bahasa Indonesia
        $this->negationWords = ['tidak', 'bukan', 'nggak', 'ga', 'gak', 'tak', 'tiada'];
    }
    /**
     * Menjalankan semua proses preprocessing pada teks input
     *
     * @param string $text Teks yang akan diproses
     * @return string Teks hasil preprocessing
     */
    public function processText($text)
    {
        $text = $this->convertEmoji($text);
        $text = $this->convertEmoticons($text);
        $text = $this->cleanText($text);
        $text = $this->translateEnglishToIndonesian($text); // Terjemahkan setelah cleaning
        $tokens = $this->tokenize($text);
        $tokens = $this->applyNegation($tokens);
        $tokens = $this->removeStopwords($tokens);
        $tokens = $this->stemWords($tokens);

        return implode(' ', $tokens);
    }

    /**
     * Mengkonversi emoji ke bentuk teks
     *
     * @param string $text Teks yang berisi emoji
     * @return string Teks dengan emoji yang sudah dikonversi
     */
    public function convertEmoji($text)
    {
        foreach ($this->emojiMap as $emoji => $meaning) {
            $text = str_replace($emoji, ' ' . $meaning . ' ', $text);
        }

        return $text;
    }

    /**
     * Membersihkan teks dari karakter khusus, link, dll
     *
     * @param string $text Teks yang akan dibersihkan
     * @return string Teks yang sudah dibersihkan
     */
    public function cleanText($text)
    {
        // Lowercase
        $text = strtolower($text);

        // Hapus URL (termasuk short URLs seperti t.co, bit.ly, dll)
        $text = preg_replace('/https?:\/\/[^\s]+/i', '', $text);
        $text = preg_replace('/http?:\/\/[^\s]+/i', '', $text);
        $text = preg_replace('/www\.[^\s]+/i', '', $text);
        // Hapus pola t.co dan short URL lainnya yang mungkin tidak ada http
        $text = preg_replace('/\b[a-z]+\.[a-z]{2,3}\/[a-zA-Z0-9]+\b/i', '', $text);
        // Hapus URL sisa yang mungkin hanya domain
        $text = preg_replace('/\b(t\.co|bit\.ly|goo\.gl|tinyurl\.com|ow\.ly|is\.gd|buff\.ly)\/[^\s]+/i', '', $text);

        // Hapus HTML tags
        $text = strip_tags($text);

        // Hapus mention (@username)
        $text = preg_replace('/@\w+/', '', $text);

        // Hapus hashtag (#topic)
        $text = preg_replace('/#\w+/', '', $text);

        // Hapus tanda baca dan karakter khusus tapi biarkan huruf lokal
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        // Hapus angka yang berdiri sendiri
        $text = preg_replace('/\b\d+\b/', '', $text);

        // Hapus multiple spaces
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Memecah teks menjadi token (kata-kata)
     *
     * @param string|array $input Teks yang akan di-tokenize atau array token
     * @return array Array berisi token
     */
    public function tokenize($input)
    {
        if (is_array($input)) {
            return $input;
        }
        return explode(' ', $input);
    }

    public function updateStopwords(array $extra)
    {
        $this->stopwords = array_values(array_unique(array_merge($this->stopwords, $extra)));
    }

    public function setStopwords(array $list)
    {
        $this->stopwords = array_values(array_unique($list));
    }

    /**
     * Menghapus stopwords dari daftar token
     *
     * @param string|array $input Teks atau array token
     * @return array Array berisi token tanpa stopwords
     */
    public function removeStopwords($input)
    {
        $tokens = $this->tokenize($input);
        return array_values(array_filter(
            $tokens,
            function ($token) {
                return !in_array($token, $this->stopwords) && strlen($token) > 1;
            }
        ));
    }

    /**
     * Melakukan stemming pada daftar token
     *
     * @param string|array $input Teks atau array token
     * @return array Array berisi token yang sudah di-stem
     */
    public function stemWords($input)
    {
        $tokens = $this->tokenize($input);
        $result = [];
        foreach ($tokens as $token) {
            $result[] = $this->stemmer->stem($token);
        }
        return $result;
    }

    public function applyNegation(array $tokens): array
    {
        $result = [];
        $i = 0;
        $n = count($tokens);
        while ($i < $n) {
            $t = $tokens[$i];
            if (in_array($t, $this->negationWords, true) && ($i + 1) < $n) {
                $next = $tokens[$i + 1];
                $result[] = $t . '_' . $next;
                $i += 2;
                continue;
            }
            $result[] = $t;
            $i++;
        }
        return $result;
    }

    /**
     * Mengkonversi emoticon ke teks
     *
     * @param string $text Teks yang berisi emoticon
     * @return string Teks dengan emoticon yang sudah dikonversi
     */
    public function convertEmoticons($text)
    {
        foreach ($this->emoticons as $emoji => $meaning) {
            $text = str_replace($emoji, ' ' . $meaning . ' ', $text);
        }
        return $text;
    }

    /**
     * Mendeteksi apakah teks mengandung kata-kata bahasa Inggris
     *
     * @param string $text Teks yang akan dideteksi
     * @return bool True jika teks mengandung kata bahasa Inggris
     */
    public function containsEnglish($text)
    {
        $tokens = $this->tokenize(strtolower($text));
        $englishWords = array_keys($this->englishIdDictionary);

        foreach ($tokens as $token) {
            if (in_array($token, $englishWords)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Menerjemahkan kata-kata bahasa Inggris ke bahasa Indonesia
     *
     * @param string $text Teks yang akan diterjemahkan
     * @return string Teks yang sudah diterjemahkan
     */
    public function translateEnglishToIndonesian($text)
    {
        $tokens = $this->tokenize(strtolower($text));
        $translatedTokens = [];

        foreach ($tokens as $token) {
            if (isset($this->englishIdDictionary[$token])) {
                $translatedTokens[] = $this->englishIdDictionary[$token];
            } else {
                $translatedTokens[] = $token;
            }
        }

        return implode(' ', $translatedTokens);
    }
}
