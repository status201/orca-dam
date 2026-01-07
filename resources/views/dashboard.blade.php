<x-app-layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Welcome Section -->
            <div class="mb-16 text-center">
                <div class="flex items-center justify-center mb-6">
                    <div class="bg-white rounded-full p-2 shadow-lg cursor-pointer"
                         onclick="const footerOrca = document.querySelector('.footer-logo-container svg'); if(footerOrca) { footerOrca.classList.add('orca-jump'); setTimeout(() => footerOrca.classList.remove('orca-jump'), 1100); }">
                        <x-application-logo class="h-48 w-48 fill-current text-gray-800" style="width: 12rem; height: 12rem;" />
                    </div>
                </div>
                <h1 class="text-4xl font-bold text-gray-900 mb-3">ORCA DAM</h1>
                <p class="text-xl text-gray-600 italic pb-8">ORCA Retrieves Cloud Assets</p>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    You're logged in!
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
