<!-- Footer with waves -->
<footer class="wave-footer mt-auto pt-[8rem] pb-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <div class="flex items-center justify-center mb-4">
            <div class="footer-orca-bg rounded-full p-[0.4rem] shadow-lg footer-logo-container"
                 onclick="this.querySelector('svg').classList.add('orca-jump'); setTimeout(() => this.querySelector('svg').classList.remove('orca-jump'), 1100);">
                <x-application-logo class="h-12 w-12 fill-current text-gray-800" />
            </div>
        </div>
        <h3 class="text-white font-semibold text-lg mb-1">ORCA DAM</h3>
        <p class="text-gray-400 text-sm italic mb-2">ORCA Retrieves Cloud Assets</p>
        <p class="text-gray-500 text-xs">
            &copy; {{ date('Y') }} - Digital Asset Management
        </p>
    </div>
</footer>