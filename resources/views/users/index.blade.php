@extends('layouts.app')

@section('title', __('Users'))

@section('content')
<div x-data="{
    deleteUserId: null,
    deleteUserName: '',
    deleteUserAssetCount: 0,
    transferToUserId: '',
    confirmDelete(id, name, assetCount) {
        this.deleteUserId = id;
        this.deleteUserName = name;
        this.deleteUserAssetCount = assetCount;
        this.transferToUserId = '';
        $dispatch('open-modal', 'confirm-user-delete');
    }
}">
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
                    {{ __('Last login') }}
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
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    @if($user->last_login_at)
                        <span title="{{ $user->last_login_at->format('Y-m-d H:i') }}">
                            {{ $user->last_login_at->diffForHumans() }}
                        </span>
                    @else
                        <span class="text-gray-400">{{ __('Never') }}</span>
                    @endif
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <a href="{{ route('users.edit', $user) }}" class="text-orca-black hover:text-orca-black-hover mr-3">
                        <i class="fas fa-edit"></i> {{ __('Edit') }}
                    </a>
                    @if($user->id !== auth()->id())
                        <button type="button"
                            class="warning text-red-600 hover:text-red-900"
                            @click="confirmDelete({{ $user->id }}, '{{ addslashes($user->name) }}', {{ $user->assets_count }})">
                            <i class="fas fa-trash"></i> {{ __('Delete') }}
                        </button>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

<x-modal name="confirm-user-delete" maxWidth="md" focusable>
    <form :action="'{{ url('users') }}/' + deleteUserId" method="POST" class="p-6">
        @csrf
        @method('DELETE')

        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Delete User') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600" x-text="deleteUserName"></p>

        <template x-if="deleteUserAssetCount > 0">
            <div>
                <p class="mt-3 text-sm text-gray-600">
                    {{ __('This user owns') }}
                    <span class="font-semibold" x-text="deleteUserAssetCount"></span>
                    {{ __('asset(s). Select a user to transfer them to before deletion.') }}
                </p>

                <div class="mt-4">
                    <x-input-label :value="__('Transfer assets to')" for="transfer_to_user_id" />
                    <select name="transfer_to_user_id"
                            id="transfer_to_user_id"
                            x-model="transferToUserId"
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-2 focus:ring-orca-black focus:border-transparent"
                            required>
                        <option value="">{{ __('Select a user...') }}</option>
                        @foreach($users as $targetUser)
                            <option value="{{ $targetUser->id }}"
                                    x-show="deleteUserId !== {{ $targetUser->id }}">
                                {{ $targetUser->name }} ({{ $targetUser->email }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </template>

        <template x-if="deleteUserAssetCount === 0">
            <p class="mt-3 text-sm text-gray-600">
                {{ __('Are you sure you want to delete this user? This action cannot be undone.') }}
            </p>
        </template>

        <div class="mt-6 flex justify-end">
            <x-secondary-button x-on:click="$dispatch('close')">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button class="warning ms-3"
                :disabled="false"
                x-bind:disabled="deleteUserAssetCount > 0 && !transferToUserId">
                {{ __('Delete User') }}
            </x-danger-button>
        </div>
    </form>
</x-modal>

</div>
@endsection
