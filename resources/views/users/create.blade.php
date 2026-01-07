@extends('layouts.app')

@section('title', 'Create User')

@section('content')
<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900">Create User</h1>
    <p class="text-gray-600 mt-2">Add a new user to the system</p>
</div>

<div class="max-w-2xl bg-white rounded-lg shadow p-6">
    <form action="{{ route('users.store') }}" method="POST">
        @csrf

        <div class="mb-4">
            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Name</label>
            <input type="text" name="name" id="name" value="{{ old('name') }}" required
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent @error('name') border-red-500 @enderror">
            @error('name')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="mb-4">
            <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}" required
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent @error('email') border-red-500 @enderror">
            @error('email')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="mb-4">
            <label for="role" class="block text-sm font-medium text-gray-700 mb-2">Role</label>
            <select name="role" id="role" required
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent @error('role') border-red-500 @enderror">
                <option value="editor" {{ old('role') === 'editor' ? 'selected' : '' }}>Editor</option>
                <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Admin</option>
            </select>
            @error('role')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
            <p class="text-sm text-gray-500 mt-1">
                <strong>Editor:</strong> Can manage assets and tags<br>
                <strong>Admin:</strong> Can manage assets, tags, users, and discover new files
            </p>
        </div>

        <div class="mb-4">
            <label for="password" class="block text-sm font-medium text-gray-700 mb-2">Password</label>
            <input type="password" name="password" id="password" required
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent @error('password') border-red-500 @enderror">
            @error('password')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="mb-6">
            <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
            <input type="password" name="password_confirmation" id="password_confirmation" required
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
        </div>

        <div class="flex justify-end space-x-3">
            <a href="{{ route('users.index') }}" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit" class="px-4 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover">
                <i class="fas fa-save mr-2"></i> Create User
            </button>
        </div>
    </form>
</div>
@endsection
