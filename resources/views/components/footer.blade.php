<!-- Footer with waves -->
<footer id="orca-footer" class="wave-footer mt-auto pt-[8rem] pb-6">
    <div id="footer-content" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <div class="flex items-center justify-center mb-4">
            <div id="orca-logo-container" class="footer-orca-bg rounded-full p-[0.4rem] shadow-lg footer-logo-container"
                 onclick="this.querySelector('svg').classList.add('orca-jump'); setTimeout(() => this.querySelector('svg').classList.remove('orca-jump'), 1100);">
                <x-application-logo class="h-12 w-12 fill-current text-gray-800" />
            </div>
        </div>
        <h3 class="text-white font-semibold text-lg mb-1">ORCA DAM</h3>
        <p class="text-gray-400 text-sm mb-2">{{ __('ORCA Retrieves Cloud Assets') }}</p>
        <p class="text-gray-500 text-xs">
            &copy; {{ date('Y') }} - Studyflow &amp; Status201
        </p>
    </div>

    <!-- Game loader (hidden by default) -->
    <div id="orca-game-loader" style="display:none;">
        <div class="game-loader-text">LOADING...</div>
        <div class="game-loader-track">
            <div class="game-loader-bar"></div>
        </div>
    </div>

    <!-- Game area (hidden by default) -->
    <div id="orca-game-area" style="display:none;"></div>
</footer>
