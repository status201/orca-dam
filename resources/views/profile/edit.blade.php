<x-app-layout title="Profile">
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Left Column -->
                <div class="space-y-6">
                    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                        @include('profile.partials.update-profile-information-form')
                    </div>

                    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                        @include('profile.partials.update-password-form')
                    </div>
                </div>

                <!-- Right Column -->
                <div class="space-y-6">
                    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                        @include('profile.partials.update-preferences-form')
                    </div>

                    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                        @include('profile.partials.delete-user-form')
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
