    <!-- Header with search and filters -->
    <div class="mb-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="page-header">
                <h1 class="text-3xl font-bold text-gray-900">
                    {{ __('Assets') }}
                    <span class="ml-2 relative -top-1 inline-flex items-center justify-center px-3 py-0.5 text-base font-semibold rounded-full bg-gray-200 text-gray-700">
                        {{ number_format($assets->total()) }}
                    </span>
                </h1>
                <p class="text-gray-600 mt-2">{{ __('Browse and manage your digital assets') }}</p>
            </div>

            <div class="flex flex-col gap-3">
                <!-- Row 1: Search (full width on sm-lg, auto on lg+) -->
                <div class="relative lg:hidden">
                    <input type="text"
                           x-model="search"
                           @keyup.enter="applyFilters"
                           placeholder="{{ __('Search... (+require -exclude)') }}"
                           :class="appliedSearch ? 'ring-2 ring-orca-black border-orca-black' : ''"
                           class="w-full pl-10 pr-10 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    <button @click="applyFilters"
                            class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-500 hover:text-gray-700">
                        <i class="fas fa-arrow-right text-sm"></i>
                    </button>
                </div>

                <!-- Row 2: Filters and Upload -->
                <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center sm:justify-end gap-3">
                    <!-- Search (hidden on mobile, visible inline on lg+) -->
                    <div class="relative hidden lg:block">
                        <input type="text"
                               x-model="search"
                               @keyup.enter="applyFilters"
                               placeholder="{{ __('Search... (+require -exclude)') }}"
                               :class="appliedSearch ? 'ring-2 ring-orca-black border-orca-black' : ''"
                               class="w-64 pl-10 pr-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    </div>

                    <!-- Folder filter -->
                    <select x-model="folder"
                            @change="applyFilters"
                            :class="folder && folder !== rootFolder && folderCount > 1 ? 'ring-2 ring-orca-black border-orca-black' : ''"
                            class="pr-dropdown px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent font-mono">
                        <x-folder-tree-options :folders="$folders" :root-folder="$rootFolder" />
                    </select>

                    <!-- Type filter -->
                    <select x-model="type"
                            @change="applyFilters"
                            :class="type ? 'ring-2 ring-orca-black border-orca-black' : ''"
                            class="pr-dropdown px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <option value="">{{ __('All Types') }}</option>
                        <option value="image">{{ __('Images') }}</option>
                        <option value="video">{{ __('Videos') }}</option>
                        <option value="document">{{ __('Documents') }}</option>
                    </select>

                    <!-- Tag filter -->
                    <button @click="showTagFilter = !showTagFilter"
                            :class="selectedTags.length > 0 ? 'ring-2 ring-orca-black border-orca-black' : (showTagFilter ? 'ring-1 ring-orca-black border-orca-black' : '')"
                            class="px-4 py-2 bg-white text-sm border border-gray-300 rounded-lg hover:bg-gray-50 flex items-center justify-center">
                        <i class="fas fa-filter mr-2"></i>
                        <span x-text="selectedTags.length > 0 ? @js(__('Tags')) + ` (${selectedTags.length})` : @js(__('Filter Tags'))"></span>
                    </button>

                    <!-- Sort -->
                    <select x-model="sort"
                            @change="applyFilters"
                            :class="sort !== 'date_desc' ? 'ring-2 ring-orca-black border-orca-black' : ''"
                            class="pr-dropdown px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <option value="date_desc">{{ __('Newest First') }}</option>
                        <option value="date_asc">{{ __('Oldest First') }}</option>
                        <option value="upload_desc">{{ __('Newest Uploads') }}</option>
                        <option value="upload_asc">{{ __('Oldest Uploads') }}</option>
                        <option value="size_desc">{{ __('Largest First') }}</option>
                        <option value="size_asc">{{ __('Smallest First') }}</option>
                        <option value="name_asc">{{ __('Name A-Z') }}</option>
                        <option value="name_desc">{{ __('Name Z-A') }}</option>
                        <option value="s3key_asc">{{ __('S3 Key A-Z') }}</option>
                        <option value="s3key_desc">{{ __('S3 Key Z-A') }}</option>
                    </select>

                    <!-- Upload button -->
                    <a :href="`{{ route('assets.create') }}${folder ? '?folder=' + encodeURIComponent(folder) : ''}`"
                       class="px-4 py-2 text-sm bg-orca-black text-white rounded-lg hover:bg-orca-black-hover flex items-center justify-center whitespace-nowrap">
                        <i class="fas fa-upload mr-2"></i> {{ __('Upload') }}
                    </a>
                </div>
            </div>
        </div>

        <!-- Tag filter dropdown -->
        <div x-show="showTagFilter"
             x-cloak
             @click.away="if (selectedTags.length === 0) showTagFilter = false"
             class="mt-4 bg-white border text-sm border-gray-200 rounded-lg shadow-lg p-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3">
                <h3 class="font-semibold">{{ __('Filter by Tags') }}</h3>
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Tag search input -->
                    <div class="relative">
                        <input type="text"
                               x-model="tagSearch"
                               @input="onFilterTagSearch()"
                               placeholder="{{ __('Search tags...') }}"
                               class="text-sm pl-8 pr-3 py-1 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent w-40">
                        <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                    </div>
                    <!-- Tag type filter -->
                    <select x-model="tagType" @change="onFilterTagTypeChange()"
                            class="pr-dropdown text-sm px-2 py-1 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <option value="">{{ __('All Tags') }}</option>
                        <option value="user">{{ __('User Tags') }}</option>
                        <option value="ai">{{ __('AI Tags') }}</option>
                        <option value="reference">{{ __('Reference Tags') }}</option>
                    </select>
                    <!-- Tag sort dropdown -->
                    <select x-model="tagSort" @change="onFilterTagSortChange()"
                            class="pr-dropdown text-sm px-2 py-1 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <option value="name_asc">{{ __('Name (A-Z)') }}</option>
                        <option value="name_desc">{{ __('Name (Z-A)') }}</option>
                        <option value="most_used">{{ __('Most used') }}</option>
                        <option value="least_used">{{ __('Least used') }}</option>
                        <option value="newest">{{ __('Newest') }}</option>
                        <option value="oldest">{{ __('Oldest') }}</option>
                    </select>
                    <div class="flex gap-2">
                        <button @click="applyFilters()"
                                x-show="tagsChanged()"
                                class="text-sm px-4 py-1 bg-orca-black text-white hover:bg-orca-black-hover rounded-lg transition">
                            <i class="fas fa-check mr-1"></i> {{ __('Apply') }}
                        </button>
                        <button @click="selectedTags = []; tagSearch = ''"
                                x-show="selectedTags.length > 0"
                                class="text-sm px-3 py-1 text-red-600 hover:bg-red-50 rounded-lg transition">
                            <i class="fas fa-times mr-1"></i> {{ __('Clear All') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Pinned selected tags -->
            <template x-if="pinnedTags.length > 0">
                <div class="mb-2">
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-2">
                        <template x-for="tag in pinnedTags" :key="'pinned-' + tag.id">
                            <label class="flex items-start space-x-2 p-2 bg-blue-50 hover:bg-blue-100 rounded cursor-pointer border border-blue-200">
                                <input type="checkbox"
                                       :value="tag.id"
                                       x-model="selectedTags"
                                       class="rounded text-blue-600 focus:ring-orca-black flex-shrink-0 mt-0.5">
                                <div class="flex flex-col gap-1 min-w-0 flex-1">
                                    <span class="text-sm font-medium truncate" x-text="tag.name"></span>
                                    <div class="flex items-center gap-1.5">
                                        <span :class="tag.type === 'ai' ? 'bg-purple-100 text-purple-700' : (tag.type === 'reference' ? 'bg-orange-100 text-orange-700' : 'bg-blue-100 text-blue-700')"
                                              class="tag attention text-xs px-2 py-0.5 rounded-full inline-block w-fit"
                                              x-text="tag.type === 'reference' ? 'ref' : tag.type"></span>
                                        <span class="text-xs text-gray-400" x-text="tag.assets_count"></span>
                                    </div>
                                </div>
                            </label>
                        </template>
                    </div>
                </div>
            </template>

            <div class="max-h-96 overflow-y-auto invert-scrollbar-colors" @scroll="onFilterScroll($event)">
                <!-- Loading state -->
                <div x-show="filterTagsLoading" class="text-center py-8">
                    <i class="fas fa-spinner fa-spin text-gray-400 mr-2"></i>
                    <span class="text-gray-500 text-sm">{{ __('Loading tags...') }}</span>
                </div>

                <div x-show="!filterTagsLoading">
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-2">
                        <template x-for="tag in displayTags" :key="tag.id">
                            <label class="flex items-start space-x-2 p-2 hover:bg-gray-50 rounded cursor-pointer border border-gray-200">
                                <input type="checkbox"
                                       :value="tag.id"
                                       x-model="selectedTags"
                                       class="rounded text-blue-600 focus:ring-orca-black flex-shrink-0 mt-0.5">
                                <div class="flex flex-col gap-1 min-w-0 flex-1">
                                    <span class="text-sm font-medium truncate" x-text="tag.name"></span>
                                    <div class="flex items-center gap-1.5">
                                        <span :class="tag.type === 'ai' ? 'bg-purple-100 text-purple-700' : (tag.type === 'reference' ? 'bg-orange-100 text-orange-700' : 'bg-blue-100 text-blue-700')"
                                              class="tag attention text-xs px-2 py-0.5 rounded-full inline-block w-fit"
                                              x-text="tag.type === 'reference' ? 'ref' : tag.type"></span>
                                        <span class="text-xs text-gray-400" x-text="tag.assets_count"></span>
                                    </div>
                                </div>
                            </label>
                        </template>
                    </div>

                    <!-- Loading more spinner -->
                    <div x-show="filterTagsLoadingMore" class="text-center py-4">
                        <i class="fas fa-spinner fa-spin text-gray-400 mr-2"></i>
                        <span class="text-gray-500 text-sm">{{ __('Loading more tags...') }}</span>
                    </div>

                    <!-- No tags message -->
                    <p x-show="!filterTagsLoading && filterTags.length === 0" class="text-gray-500 text-sm py-4 text-center">{{ __('No tags available yet.') }}</p>
                </div>
            </div>
        </div>
    </div>
