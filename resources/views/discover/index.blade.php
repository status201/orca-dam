@extends('layouts.app')

@section('title', __('Discover Unmapped Objects'))

@section('content')
<div x-data="discoverObjects()">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">{{ __('Discover Unmapped Objects') }}</h1>
        <p class="text-gray-600 mt-2">{{ __('Find and import objects in your S3 bucket that aren\'t yet tracked in ORCA') }}</p>
    </div>

    <!-- Folder filter and Scan button -->
    <div class="mb-6">
        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Scan Folder') }}</label>
        <div class="flex flex-col md:flex-row md:items-center gap-4">
            <select x-model="selectedFolder"
                    class="flex-1 max-w-md rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 font-mono text-sm">
                <x-folder-tree-options :folders="$folders" :root-folder="$rootFolder" />
            </select>

            <!-- Refresh folders button -->
            <button @click="scanFolders"
                    :disabled="scanningFolders"
                    type="button"
                    class="px-3 py-2 text-sm font-medium text-gray-600 bg-gray-50 border border-gray-200 rounded-lg hover:bg-gray-100 disabled:opacity-50"
                    title="{{ __('Refresh folder list from S3') }}">
                <i :class="scanningFolders ? 'fa-spinner fa-spin' : 'fa-sync'" class="fas"></i>
            </button>

            <!-- Scan bucket button -->
            <button @click="scanBucket"
                    :disabled="scanning"
                    class="px-6 py-3 text-sm bg-orca-black text-white rounded-lg hover:bg-orca-black-hover disabled:opacity-50 disabled:cursor-not-allowed flex items-center">
                <template x-if="!scanning">
                    <span><i class="fas fa-search mr-2"></i> {{ __('Scan Bucket') }}</span>
                </template>
                <template x-if="scanning">
                    <span><i class="fas fa-spinner fa-spin mr-2"></i> {{ __('Scanning...') }}</span>
                </template>
            </button>
        </div>
    </div>

    <!-- Results -->
    <div x-show="scanned" x-cloak>
        <!-- Summary -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
            <div class="flex items-center justify-between">
                <div class="w-full sm:w-auto">
                    <h2 class="text-xl font-semibold pr-2">
                        <div x-data="{
                            messageTemplate: @js(__('Found :count unmapped object(s)')),
                            get unmappedMessage() {
                                return this.messageTemplate.replace(':count', unmappedObjects.length);
                            }
                        }">
                            <span x-text="unmappedMessage"  class="text-blue-600"></span>
                        </div>
                    </h2>
                    <p class="text-gray-600 text-sm mt-1">{{ __('Select objects to import into ORCA') }}</p>
                </div>

                <div class="flex space-x-3" x-show="unmappedObjects.length > 0">
                    <button @click="selectAll"
                            class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        <i class="fas fa-check-double mr-2"></i><span class="hidden sm:inline"> {{ __('Select All') }}</span>
                    </button>

                    <button @click="deselectAll"
                            class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        <i class="fas fa-times mr-2"></i><span class="hidden sm:inline"> {{ __('Deselect All') }}</span>
                    </button>

                    <button @click="importSelected"
                            :disabled="selectedObjects.length === 0 || importing"
                            class="import px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        <template x-if="!importing">

                            <div x-data="{
                                messageTemplate: @js(__('Import :count Selected')),
                                get selectedMessage() {
                                    return this.messageTemplate.replace(':count', selectedObjects.length);
                                }}">
                                <i class="fas fa-file-import mr-2"></i>
                                <span x-text="selectedMessage"></span>
                            </div>

                        </template>
                        <template x-if="importing">
                            <span><i class="fas fa-spinner fa-spin mr-2"></i> {{ __('Importing...') }}</span>
                        </template>
                    </button>
                </div>
            </div>
        </div>

        <!-- Object list -->
        <div x-show="unmappedObjects.length > 0" class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="overflow-x-auto invert-scrollbar-colors">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left">
                                <input type="checkbox"
                                       @change="$event.target.checked ? selectAll() : deselectAll()"
                                       :checked="selectedObjects.length === unmappedObjects.length && unmappedObjects.length > 0"
                                       class="rounded text-blue-600 focus:ring-blue-500">
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                {{ __('Preview') }}
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                {{ __('Filename') }}
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                {{ __('Type') }}
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                {{ __('Size') }}
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                {{ __('Last Modified') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <template x-for="object in unmappedObjects" :key="object.key">
                            <tr :class="selectedObjects.includes(object.key) ? 'bg-blue-50' : ''">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <input type="checkbox"
                                           :value="object.key"
                                           x-model="selectedObjects"
                                           class="rounded text-blue-600 focus:ring-blue-500">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <template x-if="object.mime_type.startsWith('image/')">
                                        <img :src="object.url"
                                             :alt="object.filename"
                                             class="w-16 h-16 object-cover rounded">
                                    </template>
                                    <template x-if="!object.mime_type.startsWith('image/')">
                                        <div class="w-16 h-16 bg-gray-100 rounded flex items-center justify-center">
                                            <i class="fas text-5xl opacity-60"
                                               :class="[getFileIcon(object.mime_type, object.filename), getFileIconColor(getFileIcon(object.mime_type, object.filename))]"></i>
                                        </div>
                                    </template>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <div class="text-sm font-medium text-gray-900" x-text="object.filename"></div>
                                        <template x-if="object.is_deleted">
                                            <span class="text-xs px-2 py-0.5 bg-red-100 text-red-700 rounded-full whitespace-nowrap">
                                                <i class="attention fas fa-trash-alt mr-1"></i>{{ __('Deleted') }}
                                            </span>
                                        </template>
                                    </div>
                                    <div class="text-xs text-gray-500 font-mono truncate max-w-xs" x-text="object.key"></div>
                                    <template x-if="object.is_deleted">
                                        <div class="text-xs text-red-600 mt-1">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            {{ __('Soft-deleted') }} <span x-text="formatDate(object.deleted_at)"></span>
                                        </div>
                                    </template>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="text-xs px-2 py-1 bg-gray-100 text-gray-700 rounded" x-text="object.mime_type"></span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600" x-text="formatFileSize(object.size)">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600" x-text="formatDate(object.last_modified)">
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- No results -->
        <div x-show="unmappedObjects.length === 0" class="bg-white rounded-lg shadow-lg p-12 text-center">
            <i class="attention fas fa-check-circle text-6xl text-green-500 mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">{{ __('All Clear!') }}</h3>
            <p class="text-gray-600">{{ __('All objects in your S3 bucket are already tracked in ORCA.') }}</p>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.__pageData = window.__pageData || {};
window.__pageData.discover = {
    rootFolder: @js($rootFolder),
    routes: {
        foldersScan: @js(route('folders.scan')),
        discoverScan: @js(route('discover.scan')),
        discoverImport: @js(route('discover.import')),
    },
    translations: {
        failedToScanFolders: @js(__('Failed to scan folders')),
        foldersRefreshed: @js(__('Folders refreshed from S3')),
        foundUnmapped: @js(__('Found :count unmapped object(s)')),
        failedToScanBucket: @js(__('Failed to scan bucket')),
        import: @js(__('Import')),
        objectsProcessing: @js(__('object(s)? Processing will continue in the background.')),
        thumbnailsBackground: @js(__('Thumbnails and AI tags will be processed in background.')),
        importFailed: @js(__('Import failed:')),
        unknownError: @js(__('Unknown error')),
        failedToImportObjects: @js(__('Failed to import objects:')),
    },
};
</script>
@endpush
@endsection
