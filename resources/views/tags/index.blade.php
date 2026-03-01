@extends('layouts.app')

@section('title', __('Tags'))

@section('content')
<div x-data="tagManager()">
    <!-- Header with search -->
    <div class="mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">{{ __('Tags') }}</h1>
                <p class="text-gray-600 mt-2">{{ __('Browse all tags in your asset library') }}</p>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
                <!-- Sort -->
                <select x-model="sort" @change="changeSort($el.value)"
                        class="md:order-3 pr-dropdown px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                    <option value="name_asc">{{ __('Name (A-Z)') }}</option>
                    <option value="name_desc">{{ __('Name (Z-A)') }}</option>
                    <option value="most_used">{{ __('Most used') }}</option>
                    <option value="least_used">{{ __('Least used') }}</option>
                    <option value="newest">{{ __('Newest') }}</option>
                    <option value="oldest">{{ __('Oldest') }}</option>
                </select>
                <!-- Search -->
                <div class="relative md:order-2">
                    <input type="text"
                           x-model="searchQuery"
                           @input="onSearchInput()"
                           @keyup.enter="activeSearch = searchQuery; loadPage(1)"
                           placeholder="{{ __('Search tags...') }}"
                           class="w-full sm:w-64 pl-10 pr-10 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    <button x-show="searchQuery.length > 0"
                            x-cloak
                            @click="searchQuery = ''; activeSearch = ''; loadPage(1)"
                            class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <p x-show="total > 0" x-cloak class="md:order-1 text-sm text-gray-600 whitespace-nowrap">
                    <span x-text="tags.length"></span> {{ __('of') }} <span x-text="total"></span>
                </p>
            </div>
        </div>
    </div>

    <!-- Filter tabs -->
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex space-x-8">
            <button @click="changeType('')"
               :class="type === '' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
               class="py-4 px-1 border-b-2 font-medium text-sm">
                {{ __('All Tags') }} <span x-show="type === ''" x-cloak>(<span x-text="total"></span>)</span>
                <span x-show="type !== ''" x-cloak>(<span x-text="typeCounts.all"></span>)</span>
            </button>
            <button @click="changeType('user')"
               :class="type === 'user' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
               class="attention py-4 px-1 border-b-2 font-medium text-sm">
                {{ __('User Tags') }} <span x-show="type === 'user'" x-cloak>(<span x-text="total"></span>)</span>
                <span x-show="type !== 'user'" x-cloak>(<span x-text="typeCounts.user"></span>)</span>
            </button>
            <button @click="changeType('ai')"
               :class="type === 'ai' ? 'border-purple-500 text-purple-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
               class="attention py-4 px-1 border-b-2 font-medium text-sm">
                {{ __('AI Tags') }} <span x-show="type === 'ai'" x-cloak>(<span x-text="total"></span>)</span>
                <span x-show="type !== 'ai'" x-cloak>(<span x-text="typeCounts.ai"></span>)</span>
            </button>
            <button @click="changeType('reference')"
               :class="type === 'reference' ? 'border-orange-500 text-orange-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
               class="attention py-4 px-1 border-b-2 font-medium text-sm">
                {{ __('Reference Tags') }} <span x-show="type === 'reference'" x-cloak>(<span x-text="total"></span>)</span>
                <span x-show="type !== 'reference'" x-cloak>(<span x-text="typeCounts.reference"></span>)</span>
            </button>
        </nav>
    </div>

    <!-- Loading skeleton -->
    <template x-if="loading">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 xxl:grid-cols-6 gap-4">
            <template x-for="i in 12" :key="i">
                <div class="bg-white rounded-lg shadow p-4 animate-pulse">
                    <div class="flex items-start justify-between mb-2">
                        <div class="h-6 bg-gray-200 rounded w-2/3"></div>
                        <div class="h-6 bg-gray-200 rounded w-12"></div>
                    </div>
                    <div class="h-4 bg-gray-200 rounded w-1/3 mt-2"></div>
                </div>
            </template>
        </div>
    </template>

    <!-- Tags grid -->
    <template x-if="!loading && tags.length > 0">
        <div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 xxl:grid-cols-6 gap-4">
                <template x-for="tag in tags" :key="tag.id">
                    <div class="bg-white rounded-lg shadow hover:shadow-lg transition-shadow p-4">
                        <div class="flex items-start justify-between mb-2 gap-3">
                            <a :href="`{{ route('assets.index') }}?tags[]=${tag.id}`"
                               :title="tag.name"
                               class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-gray-900 hover:text-blue-600 truncate" x-text="tag.name"></h3>
                            </a>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <span class="tag attention px-2 py-1 text-xs font-semibold rounded-full whitespace-nowrap"
                                      :class="tag.type === 'ai' ? 'bg-purple-100 text-purple-700' : (tag.type === 'reference' ? 'bg-orange-100 text-orange-700' : 'bg-blue-100 text-blue-700')">
                                    <span x-text="tag.type === 'reference' ? 'ref' : tag.type"></span>
                                    <template x-if="tag.type === 'ai'"><i class="fas fa-robot ml-1"></i></template>
                                    <template x-if="tag.type === 'reference'"><i class="fas fa-link ml-1"></i></template>
                                </span>

                                <!-- Edit button (not for AI tags) -->
                                <template x-if="tag.type !== 'ai'">
                                    <button @click="editTag(tag.id, tag.name)"
                                            class="text-gray-500 hover:text-blue-600 p-1.5 hover:bg-blue-50 rounded transition"
                                            :title="'{{ __('Edit tag') }}'">
                                        <i class="fas fa-edit text-sm"></i>
                                    </button>
                                </template>

                                <!-- Delete button -->
                                <button @click="deleteTag(tag.id, tag.name, tag.type)"
                                        class="delete text-gray-500 hover:text-red-600 p-1.5 hover:bg-red-50 rounded transition"
                                        :title="'{{ __('Delete tag') }}'">
                                    <i class="fas fa-trash text-sm"></i>
                                </button>
                            </div>
                        </div>
                        <p class="text-sm text-gray-600">
                            <i class="fas fa-images mr-1"></i>
                            <span x-text="tag.assets_count"></span> <span x-text="tag.assets_count === 1 ? 'asset' : 'assets'"></span>
                        </p>
                    </div>
                </template>
            </div>

            <!-- Scroll sentinel for infinite scroll -->
            <div x-ref="scrollSentinel" class="h-4"></div>

            <!-- Loading more spinner -->
            <div x-show="loadingMore" class="text-center py-6">
                <i class="fas fa-spinner fa-spin text-gray-400 mr-2"></i>
                <span class="text-gray-500 text-sm">{{ __('Loading more tags...') }}</span>
            </div>
        </div>
    </template>

    <!-- No tags at all -->
    <template x-if="!loading && tags.length === 0 && !activeSearch">
        <div class="text-center py-12 bg-white rounded-lg shadow">
            <i class="fas fa-tags text-6xl text-gray-300 mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">{{ __('No tags found') }}</h3>
            <p class="text-gray-500">
                {{ __('Tags will appear here as you add them to your assets') }}
            </p>
        </div>
    </template>

    <!-- No matching search results -->
    <template x-if="!loading && tags.length === 0 && activeSearch">
        <div class="text-center py-12 bg-white rounded-lg shadow">
            <i class="fas fa-search text-6xl text-gray-300 mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">{{ __('No matching tags') }}</h3>
            <p class="text-gray-500">
                {{ __('No tags match') }} "<span x-text="activeSearch"></span>"
            </p>
            <button @click="searchQuery = ''; activeSearch = ''; loadPage(1)" class="mt-4 text-blue-600 hover:text-blue-800">
                {{ __('Clear search') }}
            </button>
        </div>
    </template>

    <!-- Edit Tag Modal -->
    <div x-show="showEditModal"
         x-cloak
         class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         @click.self="showEditModal = false">
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4">
            <h2 class="text-2xl font-bold mb-4">{{ __('Edit Tag') }}</h2>

            <form @submit.prevent="updateTag">
                <div class="mb-4">
                    <label for="editTagName" class="block text-sm font-medium text-gray-700 mb-2">
                        {{ __('Tag Name') }}
                    </label>
                    <input type="text"
                           id="editTagName"
                           x-model="editingTagName"
                           required
                           maxlength="50"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button"
                            @click="showEditModal = false"
                            class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        {{ __('Cancel') }}
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover">
                        <i class="fas fa-save mr-2"></i> {{ __('Save') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.__pageData = window.__pageData || {};
window.__pageData.tagConfig = {
    type: @json(request('type', '')),
    sort: @json(request('sort', 'name_asc')),
    typeCounts: @json($typeCounts)
};
window.__pageData.translations = {
    tagUpdated: @js(__('Tag updated successfully')),
    tagUpdateFailed: @js(__('Failed to update tag')),
    tagDeleted: @js(__('Tag deleted successfully')),
    tagDeleteFailed: @js(__('Failed to delete tag')),
    aiTag: @js(__('AI tag')),
    referenceTag: @js(__('Reference tag')),
    tag: @js(__('tag')),
    confirmDeleteThe: @js(__('Are you sure you want to delete the')),
    removeFromAllAssets: @js(__('This will remove it from all assets.'))
};
</script>
@endpush
@endsection
