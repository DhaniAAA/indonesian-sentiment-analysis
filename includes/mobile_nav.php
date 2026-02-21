<!-- Mobile Topbar -->
<div class="lg:hidden mb-4 flex items-center justify-between" role="navigation" aria-label="Mobile Topbar">
    <div class="flex items-center gap-3">
        <button id="openNav" class="btn btn-outline p-2" aria-label="Open navigation">
            <span class="material-symbols-outlined">menu</span>
        </button>
        <h1 class="font-extrabold">Sentiment AI</h1>
    </div>

</div>

<!-- Mobile Drawer -->
<div id="navDrawer" class="hidden fixed inset-0 z-50 bg-black/30" role="dialog" aria-modal="true" aria-labelledby="mobileNavTitle">
    <div class="absolute left-0 top-0 h-full w-72 p-6 bg-white border-r-4 border-black shadow-[10px_0_0_0_rgba(0,0,0,0.5)]">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <div class="size-10 rounded-full flex items-center justify-center border-2 border-black bg-yellow-300">
                    <span class="material-symbols-outlined text-black">sentiment_satisfied</span>
                </div>
                <strong id="mobileNavTitle">Sentiment AI</strong>
            </div>
            <button id="closeNav" class="btn btn-outline p-2 bg-red-100 border-red-500 text-red-500" aria-label="Close">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <nav class="flex flex-col gap-2" aria-label="Mobile Navigation">
            <a class="flex items-center gap-4 p-3 border-2 border-transparent hover:border-black hover:bg-yellow-50 hover:shadow-[4px_4px_0_0_#000] transition-all no-underline" href="dashboard.php">
                <span class="material-symbols-outlined">dashboard</span>
                <span>Dashboard</span>
            </a>
            <a class="flex items-center gap-4 p-3 border-2 border-transparent hover:border-black hover:bg-yellow-50 hover:shadow-[4px_4px_0_0_#000] transition-all no-underline" href="predict.php">
                <span class="material-symbols-outlined">search</span>
                <span>Analisis Teks</span>
            </a>
            <a class="flex items-center gap-4 p-3 border-2 border-transparent hover:border-black hover:bg-yellow-50 hover:shadow-[4px_4px_0_0_#000] transition-all no-underline" href="train.php">
                <span class="material-symbols-outlined">model_training</span>
                <span>Training &amp; Datasets</span>
            </a>
            <a class="flex items-center gap-4 p-3 border-2 border-transparent hover:border-black hover:bg-yellow-50 hover:shadow-[4px_4px_0_0_#000] transition-all no-underline" href="about.php">
                <span class="material-symbols-outlined">info</span>
                <span>About</span>
            </a>
        </nav>
    </div>
</div>