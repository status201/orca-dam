<section x-data="preferencesForm()">
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Preferences') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Customize your default settings. These override global settings but can still be changed per-session.') }}
        </p>
    </header>

    <form @submit.prevent="save" class="mt-6 space-y-6">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <x-input-label for="home_folder" :value="__('Home Folder')" />
                <button type="button"
                        @click="refreshFolders"
                        :disabled="refreshing"
                        class="text-gray-400 hover:text-gray-600 disabled:opacity-50"
                        title="Refresh folder list">
                    <i :class="refreshing ? 'fa-spin' : ''" class="fas fa-sync text-xs"></i>
                </button>
            </div>
            <select id="home_folder"
                    x-model="homeFolder"
                    name="home_folder"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-2 focus:ring-orca-black focus:border-transparent font-mono text-sm">
                <option value="">{{ __('Use default (root)') }}</option>
                @foreach($folders as $folder)
                    @php
                        $rootPrefix = $rootFolder !== '' ? $rootFolder . '/' : '';
                        $relativePath = ($folder === '' || $folder === $rootFolder) ? '' : str_replace($rootPrefix, '', $folder);
                        $depth = $relativePath ? substr_count($relativePath, '/') + 1 : 0;
                        $label = ($folder === '' || $folder === $rootFolder)
                            ? '/ (root)'
                            : str_repeat('╎  ', max(0, $depth - 1)) . '├─ ' . basename($folder);
                    @endphp
                    <option value="{{ $folder }}">{{ $label }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500">{{ __('Your default starting folder when browsing assets.') }}</p>
            <p x-show="errors.home_folder" x-text="errors.home_folder" class="mt-2 text-sm text-red-600"></p>
        </div>

        <div>
            <x-input-label for="items_per_page" :value="__('Items Per Page')" />
            <select id="items_per_page"
                    x-model="itemsPerPage"
                    name="items_per_page"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-2 focus:ring-orca-black focus:border-transparent">
                <option value="0">{{ __('Use default') }} ({{ $globalItemsPerPage }})</option>
                @foreach([12, 24, 36, 48, 60, 72, 96] as $count)
                    <option value="{{ $count }}">{{ $count }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-gray-500">{{ __('Default number of assets shown per page. The "Results per page" dropdown on the Assets page still overrides this.') }}</p>
            <p x-show="errors.items_per_page" x-text="errors.items_per_page" class="mt-2 text-sm text-red-600"></p>
        </div>

        <div>
            <x-input-label for="dark_mode" :value="__('Dark Mode (Experimental)')" />
            <select id="dark_mode"
                    x-model="darkMode"
                    name="dark_mode"
                    @change="updateDarkModePreview"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-2 focus:ring-orca-black focus:border-transparent">
                <option value="disabled">{{ __('Disabled') }}</option>
                <option value="force_dark">{{ __('Force Dark Mode') }}</option>
                <option value="force_light">{{ __('Force Light Mode') }}</option>
            </select>
            <p class="mt-1 text-xs text-gray-500">{{ __('Experimental dark/light mode override. CSS styling not yet complete.') }}</p>
            <p x-show="errors.dark_mode" x-text="errors.dark_mode" class="mt-2 text-sm text-red-600"></p>
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button :disabled="false" x-bind:disabled="saving">
                <span x-show="!saving">{{ __('Save') }}</span>
                <span x-show="saving"><i class="fas fa-spinner fa-spin mr-2"></i>{{ __('Saving...') }}</span>
            </x-primary-button>
        </div>
    </form>
</section>

@push('scripts')
<script>
function preferencesForm() {
    return {
        homeFolder: @json($user->getPreference('home_folder') ?? ''),
        itemsPerPage: @json($user->getPreference('items_per_page') ?? 0),
        darkMode: @json($user->getPreference('dark_mode') ?? 'disabled'),
        saving: false,
        refreshing: false,
        errors: {},

        async save() {
            this.saving = true;
            this.errors = {};

            try {
                const response = await fetch('{{ route('profile.preferences.update') }}', {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        home_folder: this.homeFolder,
                        items_per_page: this.itemsPerPage,
                        dark_mode: this.darkMode,
                    }),
                });

                const data = await response.json();

                if (response.ok) {
                    window.showToast(data.message || 'Preferences saved successfully');
                } else if (response.status === 422) {
                    // Validation errors
                    this.errors = data.errors || {};
                    const firstError = Object.values(this.errors)[0];
                    window.showToast(Array.isArray(firstError) ? firstError[0] : firstError, 'error');
                } else {
                    window.showToast(data.message || 'Failed to save preferences', 'error');
                }
            } catch (error) {
                console.error('Save error:', error);
                window.showToast('Failed to save preferences', 'error');
            } finally {
                this.saving = false;
            }
        },

        refreshFolders() {
            this.refreshing = true;
            window.showToast('Refreshing folder list...');
            setTimeout(() => {
                window.location.reload();
            }, 500);
        },

        updateDarkModePreview() {
            const html = document.documentElement;
            html.classList.remove('dark-mode', 'light-mode');

            if (this.darkMode === 'force_dark') {
                html.classList.add('dark-mode');
            } else if (this.darkMode === 'force_light') {
                html.classList.add('light-mode');
            }
        }
    };
}
</script>
@endpush
