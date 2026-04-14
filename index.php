<?php
// Gunakan header Tailwind untuk konsistensi gaya di seluruh halaman
$page_title = 'Analisis Sentimen - Beranda';
include 'includes/header.php';
?>

<main class="flex-1">
    <?php include 'includes/mobile_nav.php'; ?>
    <section class="relative overflow-hidden py-16 sm:py-24 bg-accent">
        <div class="absolute inset-0 -z-10 opacity-10" aria-hidden="true" style="background-image: linear-gradient(#000 2px, transparent 2px), linear-gradient(90deg, #000 2px, transparent 2px); background-size: 40px 40px;">
        </div>
        <div class="container-max px-4">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center">
                <div>
                    <h1 class="text-4xl sm:text-6xl font-black tracking-tight mb-4 bg-white border-2 border-black p-4 shadow-neo inline-block">Analisis Sentimen</h1>
                    <h2 class="text-xl sm:text-3xl font-bold mb-6 mt-2">Bahasa Indonesia</h2>
                    <p class="text-base sm:text-lg mb-8 font-medium bg-white border-2 border-black p-4 shadow-neo">Platform untuk memahami emosi dan pendapat dalam teks berbahasa Indonesia yang didukung machine learning.</p>
                    <div class="flex flex-wrap gap-4">
                        <a href="pages/train.php" class="btn btn-primary text-lg">
                            <span class="material-symbols-outlined">model_training</span>
                            Mulai Training
                        </a>
                        <a href="pages/about.php" class="btn btn-outline text-lg">
                            <span class="material-symbols-outlined">info</span>
                            Pelajari Selengkapnya
                        </a>
                    </div>
                </div>
                <div class="hidden lg:block">
                    <div class="card bg-white rotate-3 hover:rotate-0 transition-all duration-300">
                        <div class="flex items-center gap-3 mb-6 border-b-2 border-black pb-4">
                            <span class="material-symbols-outlined text-4xl text-black">sentiment_satisfied</span>
                            <strong class="text-2xl">Sentiment AI</strong>
                        </div>
                        <p class="text-lg font-bold">Analisis sentimen adalah proses menggunakan pemrosesan bahasa alami (NLP) untuk menentukan apakah suatu teks bersifat positif, negatif, atau netral.</p>
                        <div class="mt-4 flex gap-2">
                             <div class="w-4 h-4 bg-red-400 border border-black rounded-full"></div>
                             <div class="w-4 h-4 bg-yellow-400 border border-black rounded-full"></div>
                             <div class="w-4 h-4 bg-green-400 border border-black rounded-full"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-16 bg-white border-y-2 border-black">
        <div class="container-max px-4">
            <header class="text-center mb-12">
                <h2 class="text-3xl sm:text-5xl font-black mb-4 uppercase decoration-wavy underline decoration-accent decoration-4 underline-offset-8">Fitur Utama</h2>
                <p class="text-lg font-bold uppercase tracking-wider mt-4">Apa yang bisa Anda lakukan dengan aplikasi ini</p>
            </header>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <article class="card bg-purple-100 hover:bg-purple-200">
                    <div class="feature-icon mb-4 border-2 border-black p-2 inline-block bg-white shadow-[4px_4px_0_0_#000]">
                        <span class="material-symbols-outlined text-4xl">dashboard</span>
                    </div>
                    <h3 class="text-xl font-black mb-2 uppercase">Modern Dashboard</h3>
                    <p class="text-base font-medium mb-4">Dashboard dengan visualisasi interaktif dan analisis sentimen real-time.</p>
                    <a href="pages/dashboard.php" class="btn btn-outline w-full justify-center">Buka Dashboard</a>
                </article>
                <article class="card bg-teal-100 hover:bg-teal-200">
                    <div class="feature-icon mb-4 border-2 border-black p-2 inline-block bg-white shadow-[4px_4px_0_0_#000]">
                        <span class="material-symbols-outlined text-4xl">search</span>
                    </div>
                    <h3 class="text-xl font-black mb-2 uppercase">Analisis Teks</h3>
                    <p class="text-base font-medium mb-4">Masukkan teks untuk mengetahui sentimen yang terkandung.</p>
                    <a href="pages/predict.php" class="btn btn-outline w-full justify-center">Coba Sekarang</a>
                </article>
                <article class="card bg-red-100 hover:bg-red-200">
                    <div class="feature-icon mb-4 border-2 border-black p-2 inline-block bg-white shadow-[4px_4px_0_0_#000]">
                        <span class="material-symbols-outlined text-4xl">model_training</span>
                    </div>
                    <h3 class="text-xl font-black mb-2 uppercase">Training Model</h3>
                    <p class="text-base font-medium mb-4">Upload dataset untuk melatih model sentimen Anda sendiri.</p>
                    <a href="pages/train.php" class="btn btn-outline w-full justify-center">Latih Model</a>
                </article>
            </div>
        </div>
    </section>

    <footer class="mt-8 py-6 border-t-4 border-black bg-yellow-300">
        <div class="container-max px-4 text-center">
            <small class="font-bold text-black uppercase tracking-widest">© <?php echo date('Y'); ?> Sentiment AI — Analisis Sentimen Indonesia</small>
        </div>
    </footer>
</main>

<?php include 'includes/footer.php'; ?>