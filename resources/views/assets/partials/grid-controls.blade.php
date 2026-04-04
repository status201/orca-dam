    <!-- View Toggle -->
    <div class="mb-4 flex justify-end gap-2">
        <!-- Select All (grid mode) -->
        <button x-show="viewMode === 'grid'"
                @click="$store.bulkSelection.toggleSelectAll()"
                :class="$store.bulkSelection.allOnPageSelected ? 'bg-orca-black text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                class="px-3 py-2 text-xs font-medium border border-gray-300 rounded-lg transition-colors"
                :title="$store.bulkSelection.allOnPageSelected ? @js(__('Deselect all')) : @js(__('Select all'))">
            <i class="fas fa-check-double mr-1"></i>
            <span x-text="$store.bulkSelection.allOnPageSelected ? @js(__('Deselect all')) : @js(__('Select all'))"></span>
        </button>

        <!-- Fit Mode Toggle -->
        <div class="inline-flex rounded-md shadow-sm" role="group">
            <button @click="fitMode = 'cover'; saveFitMode()"
                    :class="fitMode === 'cover' ? 'bg-orca-black text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                    class="px-3 py-2 text-xs font-medium border border-gray-300 rounded-l-lg transition-colors"
                    title="{{ __('Zoom and crop') }}">
                <i class="fas fa-crop-alt"></i>
            </button>
            <button @click="fitMode = 'contain'; saveFitMode()"
                    :class="fitMode === 'contain' ? 'bg-orca-black text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                    class="px-3 py-2 text-xs font-medium border border-gray-300 rounded-r-lg transition-colors"
                    title="{{ __('Fit to keep proportions') }}">
                <i class="fas fa-expand"></i>
            </button>
        </div>

        <div class="inline-flex rounded-md shadow-sm" role="group">
            <button @click="viewMode = 'grid'; saveViewMode()"
                    :class="viewMode === 'grid' ? 'bg-orca-black text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                    class="px-4 py-2 text-xs font-medium border border-gray-300 rounded-l-lg transition-colors">
                <i class="fas fa-th mr-2"></i> {{ __('Grid') }}
            </button>
            <button @click="viewMode = 'list'; saveViewMode()"
                    :class="viewMode === 'list' ? 'bg-orca-black text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                    class="px-4 py-2 text-xs font-medium border border-gray-300 rounded-r-lg transition-colors">
                <i class="fas fa-list mr-2"></i> {{ __('List') }}
            </button>
        </div>
    </div>

    <!-- Missing assets warning bar -->
    @if($missingCount > 0)
    <div class="attention mb-4 p-3 border border-red-800 rounded-lg flex items-center justify-between">
        <span class="text-sm text-red-800">
            <i class="fas fa-triangle-exclamation mr-2"></i>
            {{ trans_choice(':count asset has a missing S3 object|:count assets have missing S3 objects', $missingCount) }}
        </span>
        <a href="?missing=1" class="text-sm text-red-800 font-medium hover:text-red-700">
            {{ __('View') }} <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>
    @endif
