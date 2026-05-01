@extends('layouts.app')

@section('title', __('Edit User'))

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">{{ __('Edit User') }}</h1>
        <p class="text-gray-600 mt-2">{{ __('Update user information and role') }}</p>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form action="{{ route('users.update', $user) }}" method="POST">
            @csrf
            @method('PATCH')

            <div class="mb-4">
                <label for="name" class="block text-sm font-medium text-gray-700 mb-2">{{ __('Name') }}</label>
                <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" required autocomplete="username"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent @error('name') border-red-500 @enderror">
                @error('name')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label for="email" class="block text-sm font-medium text-gray-700 mb-2">{{ __('Email') }}</label>
                <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" required autocomplete="email"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent @error('email') border-red-500 @enderror">
                @error('email')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label for="role" class="block text-sm font-medium text-gray-700 mb-2">{{ __('Role') }}</label>
                <select name="role" id="role" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent @error('role') border-red-500 @enderror">
                    <option value="editor" {{ old('role', $user->role) === 'editor' ? 'selected' : '' }}>{{ __('Editor') }}</option>
                    <option value="admin" {{ old('role', $user->role) === 'admin' ? 'selected' : '' }}>{{ __('Admin') }}</option>
                    <option value="api" {{ old('role', $user->role) === 'api' ? 'selected' : '' }}>{{ __('Api') }}</option>
                </select>
                @error('role')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
                <p class="text-sm text-gray-500 mt-1">
                    <strong>{{ __('Editor:') }}</strong> {{ __('Can manage assets and tags') }}<br>
                    <strong>{{ __('Admin:') }}</strong> {{ __('Can manage assets, tags, users, and discover new files') }}<br>
                    <strong>{{ __('Api:') }}</strong> {{ __('Can view and upload assets') }}
                </p>
            </div>

            <div class="mb-4">
                <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
                    {{ __('Password') }} <span class="text-gray-500 font-normal">({{ __('leave blank to keep current') }})</span>
                </label>
                <input type="password" name="password" id="password" autocomplete="new-password"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent @error('password') border-red-500 @enderror">
                @error('password')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-6">
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-2">{{ __('Confirm Password') }}</label>
                <input type="password" name="password_confirmation" id="password_confirmation" autocomplete="new-password"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
            </div>

            <div class="actions flex justify-end space-x-3">
                <a href="{{ route('users.index') }}" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                    {{ __('Cancel') }}
                </a>
                <button type="submit" class="px-4 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover">
                    <i class="fas fa-save mr-2"></i> {{ __('Update User') }}
                </button>
            </div>
        </form>
    </div>

    @can('clearPasskeys', $user)
        @php
            $passkeyCount = $user->webAuthnCredentials()->count();
        @endphp
        @if ($passkeyCount > 0)
            <div class="mt-6 bg-white rounded-lg shadow p-6 border border-amber-200">
                <h2 class="text-lg font-semibold text-gray-900 mb-2">{{ __('Passkeys') }}</h2>
                <p class="text-sm text-gray-600 mb-1">
                    {{ __(':name has :count passkey(s) registered. Use this if they have lost access to all of their passkey-bearing devices.', ['name' => $user->name, 'count' => $passkeyCount]) }}
                </p>
                <p class="text-xs text-gray-500 mb-4">
                    @if ($user->last_passkey_used_at)
                        {{ __('Last passkey sign-in: :date', ['date' => $user->last_passkey_used_at->diffForHumans()]) }}
                    @else
                        {{ __('Last passkey sign-in: never') }}
                    @endif
                </p>
                <form method="POST" action="{{ route('users.passkeys.clear', $user) }}"
                      onsubmit="return confirm('{{ __('Remove all passkeys for this user? They will need to register new passkeys to use passkey sign-in.') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                        <i class="fas fa-key mr-2"></i> {{ __('Clear all passkeys') }}
                    </button>
                </form>
            </div>
        @endif
    @endcan
</div>
@endsection
