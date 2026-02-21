<?php
/**
 * Kelas Visualization
 * Menyediakan fungsi untuk memvisualisasikan hasil analisis sentimen
 */
class Visualization {
    /**
     * Menghasilkan data untuk diagram batang sentimen
     *
     * @param array $sentimentCounts Jumlah untuk setiap kategori sentimen
     * @return array Data untuk diagram batang
     */
    public function generateBarChartData($sentimentCounts) {
        $chartData = [
            'labels' => array_keys($sentimentCounts),
            'datasets' => [
                [
                    'label' => 'Jumlah Sentimen',
                    'data' => array_values($sentimentCounts),
                    'backgroundColor' => [
                        'rgba(75, 192, 192, 0.6)',  // positive - hijau
                        'rgba(255, 99, 132, 0.6)',  // negative - merah
                        'rgba(255, 206, 86, 0.6)',  // neutral - kuning
                    ],
                    'borderColor' => [
                        'rgba(75, 192, 192, 1)',
                        'rgba(255, 99, 132, 1)',
                        'rgba(255, 206, 86, 1)',
                    ],
                    'borderWidth' => 1
                ]
            ]
        ];

        return $chartData;
    }

    /**
     * Menghasilkan data untuk wordcloud
     *
     * @param array $wordData Array berisi data kata (frekuensi dan sentimen)
     * @return array Data untuk wordcloud
     */
    public function generateWordCloudData($wordData) {
        $cloudData = [];

        foreach ($wordData as $word => $data) {
            // Skip kata yang terlalu pendek
            if (strlen($word) < 3) {
                continue;
            }

            // Tentukan warna berdasarkan sentimen
            $color = '#888888'; // default untuk neutral
            if ($data['sentiment'] === 'positive') {
                $color = '#4bc0c0'; // hijau
            } elseif ($data['sentiment'] === 'negative') {
                $color = '#ff6384'; // merah
            }

            $cloudData[] = [
                'text' => $word,
                'weight' => $data['count'],
                'color' => $color
            ];
        }

        return $cloudData;
    }

    /**
     * Menghasilkan HTML untuk diagram batang
     *
     * @param array $chartData Data diagram batang
     * @return string HTML untuk diagram batang
     */
    public function renderBarChart($chartData) {
        // JSON_HEX_TAG mencegah XSS jika data mengandung </script> atau HTML tags
        $chartDataJson = json_encode($chartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        $html = <<<HTML
        <div class="chart-container">
            <canvas id="sentimentBarChart"></canvas>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var ctx = document.getElementById('sentimentBarChart').getContext('2d');
                var chartData = {$chartDataJson};
                var sentimentChart = new Chart(ctx, {
                    type: 'bar',
                    data: chartData,
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        },
                        plugins: {
                            title: {
                                display: true,
                                text: 'Distribusi Sentimen'
                            }
                        }
                    }
                });
            });
        </script>
HTML;

        return $html;
    }

    /**
     * Menghasilkan HTML untuk wordcloud
     *
     * @param array $cloudData Data wordcloud
     * @return string HTML untuk wordcloud
     */
    public function renderWordCloud($cloudData) {
        // JSON_HEX_TAG mencegah XSS
        $cloudDataJson = json_encode($cloudData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        $html = <<<HTML
        <div id="wordcloud" style="width: 100%; height: 400px;"></div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var cloudData = {$cloudDataJson};
                var container = document.getElementById('wordcloud');
                // Gunakan vanilla JS, tidak bergantung pada jQuery
                if (typeof WordCloud !== 'undefined') {
                    var canvas = document.createElement('canvas');
                    canvas.width = container.offsetWidth;
                    canvas.height = 400;
                    container.appendChild(canvas);
                    WordCloud(canvas, {
                        list: cloudData.map(function(d) { return [d.text, d.weight]; }),
                        gridSize: 8,
                        weightFactor: 4,
                        backgroundColor: 'transparent',
                        color: function(word, weight, fontSize, distance, theta) {
                            return ['#8B5CF6','#10B981','#EF4444','#F59E0B'][Math.floor(Math.random()*4)];
                        },
                        rotateRatio: 0
                    });
                }
            });
        </script>
HTML;

        return $html;
    }

    /**
     * Menghasilkan ringkasan teks dari hasil analisis
     *
     * @param array $stats Statistik hasil analisis
     * @return string HTML ringkasan
     */
    public function renderSummary($stats) {
        $total = $stats['total'];
        $positive = $stats['sentiment_counts']['positive'];
        $negative = $stats['sentiment_counts']['negative'];
        $neutral = $stats['sentiment_counts']['neutral'];

        $positivePercent = $total > 0 ? round(($positive / $total) * 100, 1) : 0;
        $negativePercent = $total > 0 ? round(($negative / $total) * 100, 1) : 0;
        $neutralPercent = $total > 0 ? round(($neutral / $total) * 100, 1) : 0;

        $html = <<<HTML
        <div class="summary-container">
            <h3>Ringkasan Analisis</h3>
            <div class="summary-stats">
                <div class="stat-item">
                    <span class="stat-label">Total Teks Dianalisis:</span>
                    <span class="stat-value">{$total}</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Sentimen Positif:</span>
                    <span class="stat-value">{$positive} ({$positivePercent}%)</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Sentimen Negatif:</span>
                    <span class="stat-value">{$negative} ({$negativePercent}%)</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Sentimen Netral:</span>
                    <span class="stat-value">{$neutral} ({$neutralPercent}%)</span>
                </div>
            </div>
        </div>
HTML;

        return $html;
    }
}