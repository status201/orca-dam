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
        @php $currentLocale = app()->getLocale(); @endphp
        <div class="flex items-center justify-center gap-2 mt-3 text-xs select-none">
            {{-- UK flag SVG --}}
            @php
                $ukFlag = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 30" width="20" height="13" preserveAspectRatio="none" style="display:inline-block;vertical-align:middle;border-radius:2px;overflow:hidden"><rect width="60" height="30" fill="#012169"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/><path d="M0,0 L60,30 M60,0 L0,30" stroke="#C8102E" stroke-width="4"/><path d="M30,0 V30 M0,15 H60" stroke="#fff" stroke-width="10"/><path d="M30,0 V30 M0,15 H60" stroke="#C8102E" stroke-width="6"/></svg>';
                $nlFlag = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 20" width="20" height="13" preserveAspectRatio="none" style="display:inline-block;vertical-align:middle;border-radius:2px;overflow:hidden"><rect width="30" height="7" fill="#AE1C28"/><rect width="30" height="6" y="7" fill="#fff"/><rect width="30" height="7" y="13" fill="#21468B"/></svg>';
            @endphp

            @if($currentLocale === 'en')
                <span class="opacity-60 cursor-default flex items-center gap-1 text-gray-400 grayscale hover:grayscale-0 transition-all">{!! $ukFlag !!} EN</span>
            @else
                <form method="POST" action="{{ route('locale.set') }}" class="inline m-0">
                    @csrf
                    <input type="hidden" name="locale" value="en">
                    <button type="submit"
                            title="Change the interface language to English"
                            class="grayscale hover:grayscale-0 opacity-50 hover:opacity-100 transition-all cursor-pointer bg-transparent border-0 p-0 text-xs text-gray-400 hover:text-gray-200 flex items-center gap-1">
                        {!! $ukFlag !!} EN
                    </button>
                </form>
            @endif

            <span class="text-gray-600">|</span>

            @if($currentLocale === 'nl')
                <span class="opacity-60 cursor-default flex items-center gap-1 text-gray-400 grayscale hover:grayscale-0 transition-all">{!! $nlFlag !!} NL</span>
            @else
                <form method="POST" action="{{ route('locale.set') }}" class="inline m-0">
                    @csrf
                    <input type="hidden" name="locale" value="nl">
                    <button type="submit"
                            title="Verander de interface taal naar het Nederlands"
                            class="grayscale hover:grayscale-0 opacity-50 hover:opacity-100 transition-all cursor-pointer bg-transparent border-0 p-0 text-xs text-gray-400 hover:text-gray-200 flex items-center gap-1">
                        {!! $nlFlag !!} NL
                    </button>
                </form>
            @endif
        </div>
    </div>

    <!-- Game loader (hidden by default) -->
    <div id="orca-game-loader" style="display:none;">
        <div class="game-loader-text">LOADING...</div>
        <div class="game-loader-track">
            <div class="game-loader-bar"></div>
        </div>
    </div>

    <!-- Game area (hidden by default) -->
    <div id="orca-game-area" style="display:none;" data-player="{{ Auth::user()?->name }}"></div>
</footer>
