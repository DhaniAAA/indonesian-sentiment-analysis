-- Buat database
CREATE DATABASE IF NOT EXISTS sentiment_analysis;
USE sentiment_analysis;

-- Tabel untuk menyimpan hasil analisis
CREATE TABLE IF NOT EXISTS analysis_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    text TEXT NOT NULL,
    sentiment VARCHAR(10) NOT NULL,
    score FLOAT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabel untuk menyimpan kata-kata dalam lexicon
CREATE TABLE IF NOT EXISTS lexicon (
    id INT AUTO_INCREMENT PRIMARY KEY,
    word VARCHAR(100) NOT NULL,
    sentiment_value FLOAT NOT NULL,
    UNIQUE KEY (word)
);

-- Tabel untuk menyimpan stopwords
CREATE TABLE IF NOT EXISTS stopwords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    word VARCHAR(100) NOT NULL,
    UNIQUE KEY (word)
);

-- Tabel untuk menyimpan emoticons
CREATE TABLE IF NOT EXISTS emoticons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emoji VARCHAR(50) NOT NULL,
    meaning VARCHAR(100) NOT NULL,
    UNIQUE KEY (emoji)
);