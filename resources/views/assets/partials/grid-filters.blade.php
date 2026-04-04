    <!-- Active Filters Bar -->
    <div x-show="!navigating && (appliedSearch || (folder && folder !== rootFolder && folderCount > 1) || type || user || initialTags.length > 0)" x-cloak class="active-filters mb-4 flex flex-wrap items-center gap-2">
        <span class="text-sm text-gray-500 font-medium">{{ __('Active filters') }}:</span>

        <!-- Search pill -->
        <template x-if="appliedSearch">
            <span class="inline-flex items-center gap-1 px-3 py-1 bg-gray-200 text-orca-black text-sm rounded-full">
                <i class="fas fa-search text-xs"></i>
                <span class="max-w-[200px] truncate" x-text="appliedSearch"></span>
                <button @click="search = ''; applyFilters()" class="ml-1 hover:text-gray-600">&times;</button>
            </span>
        </template>

        <!-- Folder pill -->
        <template x-if="folder && folder !== rootFolder && folderCount > 1">
            <span class="inline-flex items-center gap-1 px-3 py-1 bg-gray-200 text-orca-black text-sm rounded-full">
                <i class="fas fa-folder text-xs"></i>
                <span x-text="folder"></span>
                <button @click="folder = ''; applyFilters()" class="ml-1 hover:text-gray-600">&times;</button>
            </span>
        </template>

        <!-- Type pill -->
        <template x-if="type">
            <span class="inline-flex items-center gap-1 px-3 py-1 bg-gray-200 text-orca-black text-sm rounded-full">
                <i class="fas fa-file text-xs"></i>
                <span x-text="type.charAt(0).toUpperCase() + type.slice(1)"></span>
                <button @click="type = ''; applyFilters()" class="ml-1 hover:text-gray-600">&times;</button>
            </span>
        </template>

        <!-- User pill -->
        <template x-if="user">
            <span class="inline-flex items-center gap-1 px-3 py-1 bg-gray-200 text-orca-black text-sm rounded-full">
                <i class="fas fa-user text-xs"></i>
                <span x-text="userName"></span>
                <button @click="user = ''; userName = ''; applyFilters()" class="ml-1 hover:text-gray-600">&times;</button>
            </span>
        </template>

        <!-- Tag pills -->
        <template x-for="tagId in initialTags" :key="tagId">
            <span class="inline-flex items-center gap-1 px-3 py-1 bg-gray-200 text-orca-black text-sm rounded-full">
                <i class="fas fa-tag text-xs"></i>
                <span x-text="pinnedTags.find(t => t.id == tagId)?.name || tagId"></span>
                <button @click="selectedTags = selectedTags.filter(id => id != tagId); applyFilters()" class="ml-1 hover:text-gray-600">&times;</button>
            </span>
        </template>

        <!-- Clear all -->
        <button @click="search = ''; folder = ''; type = ''; user = ''; userName = ''; selectedTags = []; applyFilters()"
                class="text-sm text-gray-500 hover:text-gray-700 underline ml-2">
            {{ __('Clear all filters') }}
        </button>
    </div>
