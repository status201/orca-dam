@extends('layouts.app')

@section('title', __('Users'))

@section('content')
<div class="mb-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">{{ __('Users') }}</h1>
            <p class="text-gray-600 mt-2">{{ __('Manage system users and their roles') }}</p>
        </div>
        <a href="{{ route('users.create') }}" class="px-4 py-2 text-sm bg-orca-black text-white rounded-lg hover:bg-orca-black-hover flex items-center">
            <i class="fas fa-plus mr-2"></i> {{ __('Add User') }}
        </a>
    </div>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto invert-scrollbar-colors">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {{ __('Name') }}
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {{ __('Email') }}
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {{ __('Role') }}
                </th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {{ __('Assets') }}
                </th>
                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {{ __('2FA') }}
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {{ __('Actions') }}
                </th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @foreach($users as $user)
            <tr>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                        <div>
                            <div class="text-sm font-medium text-gray-900">
                                {{ $user->name }}
                                @if($user->id === auth()->id())
                                    <span class="ml-2 text-xs text-gray-500">({{ __('You') }})</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">{{ $user->email }}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full @if($user->isAdmin()) bg-purple-100 text-purple-700 @elseif($user->isApiUser()) bg-red-100 text-red-700 @else bg-blue-100 text-blue-700 @endif">
                        {{ ucfirst($user->role) }}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-center">
                    <a href="{{ route('assets.index', ['user' => $user->id]) }}" class="text-blue-600 hover:underline">
                        {{ $user->assets_count }}
                    </a>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-center">
                    @if($user->hasTwoFactorEnabled())
                        <i class="attention fas fa-check-circle text-green-600" title="{{ $user->two_factor_confirmed_at->format('M j, Y') }}"></i>
                    @else
                        <i class="attention fas fa-times-circle text-red-600"></i>
                    @endif
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <a href="{{ route('users.edit', $user) }}" class="text-orca-black hover:text-orca-black-hover mr-3">
                        <i class="fas fa-edit"></i> {{ __('Edit') }}
                    </a>
                    @if($user->id !== auth()->id())
                        <form action="{{ route('users.destroy', $user) }}" method="POST" class="inline"
                              onsubmit="return confirm('{{ __('Are you sure you want to delete this user?') }}');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="warning text-red-600 hover:text-red-900">
                                <i class="fas fa-trash"></i> {{ __('Delete') }}
                            </button>
                        </form>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
