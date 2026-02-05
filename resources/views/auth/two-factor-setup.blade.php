<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Set Up Two-Factor Authentication') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="mb-6">
                        <h3 class="text-lg font-medium text-gray-900">
                            {{ __('Scan QR Code') }}
                        </h3>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ __('Scan this QR code with your authenticator app (Google Authenticator, Authy, 1Password, etc.).') }}
                        </p>
                    </div>

                    <!-- QR Code -->
                    <div class="flex justify-center mb-6">
                        <div class="p-4 bg-white border rounded-lg">
                            {!! $qrCodeSvg !!}
                        </div>
                    </div>

                    <!-- Manual Entry -->
                    <div class="mb-6">
                        <p class="text-sm text-gray-600 mb-2">
                            {{ __('Or enter this code manually:') }}
                        </p>
                        <div class="bg-gray-100 p-3 rounded-lg text-center">
                            <code class="text-lg font-mono tracking-wider select-all">{{ $secret }}</code>
                        </div>
                    </div>

                    <!-- Verification Form -->
                    <form method="POST" action="{{ route('two-factor.confirm') }}">
                        @csrf

                        <div class="mb-4">
                            <x-input-label for="code" :value="__('Verify Code')" />
                            <p class="text-sm text-gray-500 mb-2">
                                {{ __('Enter the 6-digit code from your authenticator app to confirm setup.') }}
                            </p>
                            <x-text-input id="code" class="block mt-1 w-full" type="text" name="code" required autofocus autocomplete="one-time-code" inputmode="numeric" placeholder="123456" maxlength="6" />
                            <x-input-error :messages="$errors->get('code')" class="mt-2" />
                        </div>

                        <div class="flex items-center justify-end">
                            <a href="{{ route('profile.edit') }}" class="text-sm text-gray-600 hover:text-gray-900 underline mr-4">
                                {{ __('Cancel') }}
                            </a>
                            <x-primary-button>
                                {{ __('Enable Two-Factor Authentication') }}
                            </x-primary-button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
