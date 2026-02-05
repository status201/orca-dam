<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Two-Factor Authentication') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Add an extra layer of security to your account by enabling two-factor authentication.') }}
        </p>
    </header>

    @if (session('status') === 'two-factor-disabled')
        <div class="mt-4 p-4 bg-green-50 border border-green-200 rounded-lg">
            <p class="attention text-sm text-green-700">{{ __('Two-factor authentication has been disabled.') }}</p>
        </div>
    @endif

    @if ($errors->has('two_factor'))
        <div class="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
            <p class="attention text-sm text-red-700">{{ $errors->first('two_factor') }}</p>
        </div>
    @endif

    @if (auth()->user()->hasTwoFactorEnabled())
        <!-- 2FA is enabled -->
        <div class="mt-6">
            <div class="flex items-center mb-4">
                <svg class="w-5 h-5 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                <span class="attention text-sm font-medium text-green-700">{{ __('Two-factor authentication is enabled') }}</span>
            </div>

            <p class="text-sm text-gray-600 mb-4">
                {{ __('Enabled on') }} {{ auth()->user()->two_factor_confirmed_at->format('M j, Y \a\t g:i A') }}
            </p>

            <div class="flex flex-wrap gap-3">
                <form method="POST" action="{{ route('two-factor.recovery-codes') }}" class="inline">
                    @csrf
                    <x-secondary-button type="submit">
                        {{ __('Regenerate Recovery Codes') }}
                    </x-secondary-button>
                </form>

                <form method="POST" action="{{ route('two-factor.disable') }}" class="inline">
                    @csrf
                    @method('DELETE')
                    <x-danger-button type="submit" class="warning">
                        {{ __('Disable') }}
                    </x-danger-button>
                </form>
            </div>
        </div>
    @else
        <!-- 2FA is not enabled -->
        <div class="mt-6">
            <div class="flex items-center mb-4">
                <svg class="w-5 h-5 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                <span class="text-sm font-medium text-gray-700">{{ __('Two-factor authentication is not enabled') }}</span>
            </div>

            <p class="text-sm text-gray-600 mb-4">
                {{ __('When two-factor authentication is enabled, you will be prompted for a secure code during login. You can retrieve this code from your phone\'s authenticator app.') }}
            </p>

            <a href="{{ route('two-factor.setup') }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                {{ __('Enable Two-Factor Authentication') }}
            </a>
        </div>
    @endif
</section>
