@extends('layouts.app')

@section('title', __('Create User'))

@section('content')

<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">{{ __('Create User') }}</h1>
        <p class="text-gray-600 mt-2">{{ __('Add a new user to the system') }}</p>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form action="{{ route('users.store') }}" method="POST">
            @csrf

            <div class="mb-4">
                <label for="name" class="block text-sm font-medium text-gray-700 mb-2">{{ __('Name') }}</label>
                <input type="text" name="name" id="name" value="{{ old('name') }}" required autocomplete="username"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent @error('name') border-red-500 @enderror">
                @error('name')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label for="email" class="block text-sm font-medium text-gray-700 mb-2">{{ __('Email') }}</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}" required autocomplete="email"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent @error('email') border-red-500 @enderror">
                @error('email')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label for="role" class="block text-sm font-medium text-gray-700 mb-2">{{ __('Role') }}</label>
                <select name="role" id="role" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent @error('role') border-red-500 @enderror">
                    <option value="editor" {{ old('role') === 'editor' ? 'selected' : '' }}>{{ __('Editor') }}</option>
                    <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>{{ __('Admin') }}</option>
                    <option value="api" {{ old('role') === 'api' ? 'selected' : '' }}>{{ __('Api') }}</option>
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
                <label for="password" class="block text-sm font-medium text-gray-700 mb-2">{{ __('Password') }}</label>
                <input type="password" name="password" id="password" required autocomplete="new-password"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent @error('password') border-red-500 @enderror">
                @error('password')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-6">
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-2">{{ __('Confirm Password') }}</label>
                <input type="password" name="password_confirmation" id="password_confirmation" required autocomplete="new-password"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
            </div>

            <div class="actions flex justify-end space-x-3">
                <a href="{{ route('users.index') }}" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                    {{ __('Cancel') }}
                </a>
                <button type="submit" data-testid="user-form-submit" class="px-4 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover">
                    <i class="fas fa-save mr-2"></i> {{ __('Create User') }}
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
