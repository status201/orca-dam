<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Recovery Codes') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <p class="text-sm text-green-700">{{ session('status') }}</p>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="mb-6">
                        <div class="flex items-center">
                            <svg class="w-6 h-6 text-yellow-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <h3 class="text-lg font-medium text-gray-900">
                                {{ __('Save Your Recovery Codes') }}
                            </h3>
                        </div>
                        <p class="mt-2 text-sm text-gray-600">
                            {{ __('Store these recovery codes in a safe place. Each code can only be used once to access your account if you lose access to your authenticator app.') }}
                        </p>
                    </div>

                    <!-- Recovery Codes -->
                    <div class="bg-gray-100 p-4 rounded-lg mb-6">
                        <div class="grid grid-cols-2 gap-2">
                            @foreach ($recoveryCodes as $code)
                                <code class="font-mono text-sm bg-white p-2 rounded border text-center select-all">{{ $code }}</code>
                            @endforeach
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-between">
                        <button type="button" id="copyButton" onclick="copyRecoveryCodes()" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            <svg id="copyIcon" class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                            <svg id="checkIcon" class="w-4 h-4 mr-2 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span id="copyText">{{ __('Copy Codes') }}</span>
                        </button>

                        <a href="{{ route('profile.edit') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:bg-indigo-700 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            {{ __('Done') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function copyRecoveryCodes() {
            const codes = @json($recoveryCodes);
            const text = codes.join('\n');
            const button = document.getElementById('copyButton');
            const copyIcon = document.getElementById('copyIcon');
            const checkIcon = document.getElementById('checkIcon');
            const copyText = document.getElementById('copyText');

            navigator.clipboard.writeText(text).then(() => {
                // Show success state
                copyIcon.classList.add('hidden');
                checkIcon.classList.remove('hidden');
                copyText.textContent = 'Copied!';
                button.classList.remove('bg-gray-800', 'hover:bg-gray-700');
                button.classList.add('bg-green-600', 'hover:bg-green-500');

                // Reset after 2 seconds
                setTimeout(() => {
                    copyIcon.classList.remove('hidden');
                    checkIcon.classList.add('hidden');
                    copyText.textContent = 'Copy Codes';
                    button.classList.remove('bg-green-600', 'hover:bg-green-500');
                    button.classList.add('bg-gray-800', 'hover:bg-gray-700');
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy codes:', err);
                copyText.textContent = 'Copy failed';
                setTimeout(() => {
                    copyText.textContent = 'Copy Codes';
                }, 2000);
            });
        }
    </script>
</x-app-layout>
