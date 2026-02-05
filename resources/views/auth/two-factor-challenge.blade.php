<x-guest-layout title="Two-Factor Authentication">
    <div class="mb-4 text-sm text-gray-600">
        {{ __('Please enter your authentication code to continue.') }}
    </div>

    <form method="POST" action="{{ route('two-factor.challenge') }}">
        @csrf

        <!-- Authentication Code -->
        <div>
            <x-input-label for="code" :value="__('Authentication Code')" />
            <x-text-input id="code" class="block mt-1 w-full" type="text" name="code" required autofocus autocomplete="one-time-code" inputmode="numeric" placeholder="123456" />
            <x-input-error :messages="$errors->get('code')" class="mt-2" />
        </div>

        <p class="mt-3 text-sm text-gray-500">
            {{ __('Enter the 6-digit code from your authenticator app, or use one of your recovery codes.') }}
        </p>

        <div class="flex items-center justify-end mt-4">
            <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('login') }}">
                {{ __('Cancel') }}
            </a>

            <x-primary-button class="ms-3">
                {{ __('Verify') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
