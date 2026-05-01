@php
    $user = auth()->user();
    $passkeys = $user->webAuthnCredentials()->latest()->get();
    $maxPasskeys = \App\Services\WebAuthnService::MAX_CREDENTIALS_PER_USER;
@endphp

<section
    x-data="passkeyManager()"
    @passkey-add.window="addPasskey()"
>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Passkeys') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Sign in with your fingerprint, face, screen lock, or security key. Passkeys are phishing-resistant and skip the two-factor code on login.') }}
        </p>
    </header>

    @if ($errors->has('passkey'))
        <div class="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
            <p class="attention text-sm text-red-700">{{ $errors->first('passkey') }}</p>
        </div>
    @endif

    @if (session('status') && in_array(session('status'), [__('Passkey renamed.'), __('Passkey removed.')], true))
        <div class="mt-4 p-4 bg-green-50 border border-green-200 rounded-lg">
            <p class="attention text-sm text-green-700">{{ session('status') }}</p>
        </div>
    @endif

    <template x-if="!supported">
        <div class="mt-4 p-4 bg-amber-50 border border-amber-200 rounded-lg">
            <p class="attention text-sm text-amber-700">
                {{ __('Your browser does not support passkeys. Try a recent version of Chrome, Edge, Safari, or Firefox.') }}
            </p>
        </div>
    </template>

    <div class="mt-6 space-y-4">
        @if ($passkeys->isEmpty())
            <div class="flex items-center text-sm text-gray-600">
                <svg class="w-5 h-5 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a4 4 0 11-8 0 4 4 0 018 0zm6 6a2 2 0 11-4 0 2 2 0 014 0zM3 21h12v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2zm14 0h4v-1a3 3 0 00-3-3h-1" />
                </svg>
                <span>{{ __('No passkeys registered yet.') }}</span>
            </div>
        @else
            <ul class="divide-y divide-gray-200 border border-gray-200 rounded-lg">
                @foreach ($passkeys as $passkey)
                    <li class="flex items-center justify-between p-4 gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 {{ $passkey->isEnabled() ? 'text-green-500' : 'text-gray-400' }}" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M10 2a4 4 0 00-4 4v2H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-1V6a4 4 0 00-4-4zm-2 6V6a2 2 0 114 0v2H8z" />
                                </svg>
                                <span class="text-sm font-medium text-gray-900 truncate">
                                    {{ $passkey->alias ?: __('Unnamed passkey') }}
                                </span>
                                @if ($passkey->isDisabled())
                                    <span class="px-2 py-0.5 text-xs bg-gray-100 text-gray-600 rounded">{{ __('Disabled') }}</span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                {{ __('Added :date', ['date' => \Illuminate\Support\Carbon::parse($passkey->created_at)->format('M j, Y')]) }}
                                <span class="mx-1 text-gray-300">•</span>
                                @if ($passkey->last_used_at)
                                    {{ __('Last used :date', ['date' => \Illuminate\Support\Carbon::parse($passkey->last_used_at)->diffForHumans()]) }}
                                @else
                                    {{ __('Never used') }}
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <form method="POST" action="{{ route('profile.passkeys.update', $passkey->id) }}"
                                  class="flex items-center gap-2"
                                  x-data="{ editing: false, alias: @js($passkey->alias) }"
                                  @submit="if (!editing) $event.preventDefault()">
                                @csrf
                                @method('PATCH')
                                <template x-if="!editing">
                                    <x-secondary-button type="button" @click="editing = true; $nextTick(() => $refs.aliasInput.focus())">
                                        {{ __('Rename') }}
                                    </x-secondary-button>
                                </template>
                                <template x-if="editing">
                                    <span class="flex items-center gap-2">
                                        <input
                                            x-ref="aliasInput"
                                            type="text"
                                            name="alias"
                                            x-model="alias"
                                            maxlength="100"
                                            class="block w-40 px-2 py-1 text-sm border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            placeholder="{{ __('e.g. MacBook') }}"
                                        />
                                        <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                                        <x-secondary-button type="button" @click="editing = false; alias = @js($passkey->alias)">{{ __('Cancel') }}</x-secondary-button>
                                    </span>
                                </template>
                            </form>
                            <form method="POST" action="{{ route('profile.passkeys.destroy', $passkey->id) }}"
                                  onsubmit="return confirm('{{ __('Remove this passkey? You will not be able to use it to sign in anymore.') }}')">
                                @csrf
                                @method('DELETE')
                                <x-danger-button type="submit" class="warning">{{ __('Remove') }}</x-danger-button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="pt-2 border-t border-gray-100">
            @if ($passkeys->count() >= $maxPasskeys)
                <p class="text-sm text-gray-600">
                    {{ __('You have reached the maximum of :max passkeys. Remove an existing one to add a new one.', ['max' => $maxPasskeys]) }}
                </p>
            @else
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <input
                        type="text"
                        x-model="alias"
                        maxlength="100"
                        :disabled="!supported || adding"
                        placeholder="{{ __('Name this passkey (optional)') }}"
                        class="block w-full sm:w-64 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <button
                        type="button"
                        @click="addPasskey()"
                        :disabled="!supported || adding"
                        class="inline-flex items-center justify-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition disabled:opacity-50"
                    >
                        <span x-show="!adding">{{ __('Add Passkey') }}</span>
                        <span x-show="adding">{{ __('Waiting for device...') }}</span>
                    </button>
                </div>
            @endif
        </div>
    </div>
</section>

@push('scripts')
    <script>
        window.__pageData = window.__pageData || {};
        window.__pageData.routes = Object.assign(window.__pageData.routes || {}, {
            passkeyOptions: @json(route('profile.passkeys.options')),
            passkeyStore: @json(route('profile.passkeys.store')),
        });
        window.__pageData.translations = Object.assign(window.__pageData.translations || {}, {
            passkeyAdded: @json(__('Passkey added.')),
            passkeyAddFailed: @json(__('Failed to add passkey.')),
            passkeyCancelled: @json(__('Passkey registration was cancelled.')),
        });
    </script>
@endpush
