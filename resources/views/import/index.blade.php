@extends('layouts.app')

@section('title', __('Import Metadata'))

@section('content')
<div x-data="importMetadata()">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">{{ __('Import Metadata') }}</h1>
        <p class="text-gray-600 mt-2">{{ __('Bulk update asset metadata by pasting CSV data') }}</p>
    </div>

    <!-- Step 1: Input -->
    <div x-show="step === 1">
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-paste mr-2"></i>{{ __('Paste CSV Data') }}
            </h2>

            <!-- Match Field -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-key mr-2"></i>{{ __('Match assets by') }}
                </label>
                <div class="flex gap-4">
                    <label class="inline-flex items-center">
                        <input type="radio" x-model="matchField" value="s3_key" class="text-gray-900 focus:ring-gray-900">
                        <span class="ml-2 text-sm">{{ __('s3_key') }}</span>
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" x-model="matchField" value="filename" class="text-gray-900 focus:ring-gray-900">
                        <span class="ml-2 text-sm">{{ __('filename') }}</span>
                    </label>
                </div>
                <p class="text-xs text-gray-500 mt-1">{{ __('Choose which field to use for matching CSV rows to existing assets') }}</p>
            </div>

            <!-- CSV Upload / Drop / Paste -->
            <div class="mb-4">
                <label for="csv_data" class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-table mr-2"></i>{{ __('CSV Data') }}
                </label>

                <!-- Drop zone (shown when textarea is empty) -->
                <div x-show="!csvData"
                     @drop.prevent="handleFileDrop($event)"
                     @dragover.prevent="dragActive = true"
                     @dragleave.prevent="dragActive = false"
                     :class="dragActive ? 'border-blue-500 bg-blue-50' : 'border-gray-300'"
                     class="border-2 border-dashed rounded-lg p-8 text-center transition-colors mb-2">
                    <i class="fas fa-file-csv text-4xl text-gray-400 mb-3"></i>
                    <p class="text-sm text-gray-600 mb-2">
                        {{ __('Drop a CSV file here or') }}
                        <label class="text-blue-600 hover:text-blue-800 cursor-pointer underline">
                            {{ __('browse') }}
                            <input type="file" accept=".csv,.txt,text/csv" class="hidden" @change="handleFileSelect($event)">
                        </label>
                    </p>
                    <p class="text-xs text-gray-400">{{ __('Or paste CSV data in the text area below') }}</p>
                </div>

                <!-- File name indicator -->
                <div x-show="csvFileName" x-cloak class="flex items-center gap-2 mb-2 text-sm text-gray-600 bg-gray-50 rounded-lg px-3 py-2">
                    <i class="fas fa-file-csv text-green-600"></i>
                    <span x-text="csvFileName"></span>
                    <button @click="csvData = ''; csvFileName = ''" class="ml-auto text-gray-400 hover:text-red-500" type="button">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <textarea id="csv_data"
                          x-model="csvData"
                          @drop.prevent="handleFileDrop($event)"
                          @dragover.prevent="dragActive = true"
                          @dragleave.prevent="dragActive = false"
                          rows="12"
                          :class="dragActive ? 'border-blue-500 bg-blue-50' : ''"
                          class="w-full rounded-lg border-gray-300 focus:border-transparent focus:ring-orca-black font-mono text-sm"
                          placeholder="s3_key,alt_text,caption,license_type,license_expiry_date,copyright,copyright_source,user_tags&#10;assets/photos/image1.jpg,A sunset over the ocean,Beautiful sunset,cc_by,2026-12-31,John Doe,https://example.com,nature, landscape, sunset&#10;assets/photos/image2.jpg,Mountain view,Mountain panorama,public_domain,,,,"
                ></textarea>
            </div>

            <!-- Preview Button -->
            <div class="flex items-center justify-between">
                <p class="text-sm text-gray-500">
                    <i class="fas fa-info-circle mr-1"></i>{{ __('First row must be the header row') }}
                </p>
                <button @click="previewImport"
                        :disabled="loading || !csvData.trim()"
                        class="px-6 py-3 text-sm bg-orca-black text-white rounded-lg hover:bg-orca-black-hover text-white transition-colors flex items-center disabled:opacity-50 disabled:cursor-not-allowed">
                    <template x-if="loading">
                        <i class="fas fa-spinner fa-spin mr-2"></i>
                    </template>
                    <template x-if="!loading">
                        <i class="fas fa-search mr-2"></i>
                    </template>
                    {{ __('Preview Import') }}
                </button>
            </div>
        </div>

        <!-- Field Reference -->
        <div class="bg-gray-50 rounded-lg border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-question-circle mr-2"></i>{{ __('Field Reference') }}
            </h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-300">
                            <th class="text-left py-2 pr-4 font-semibold">{{ __('Field') }}</th>
                            <th class="text-left py-2 pr-4 font-semibold">{{ __('Format') }}</th>
                            <th class="text-left py-2 font-semibold">{{ __('Notes') }}</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-700">
                        <tr class="border-b border-gray-200">
                            <td class="py-2 pr-4 font-mono text-xs">s3_key</td>
                            <td class="py-2 pr-4">assets/folder/file.jpg</td>
                            <td class="py-2">{{ __('Used for matching (exact match)') }}</td>
                        </tr>
                        <tr class="border-b border-gray-200">
                            <td class="py-2 pr-4 font-mono text-xs">filename</td>
                            <td class="py-2 pr-4">photo.jpg</td>
                            <td class="py-2">{{ __('Display name, also usable for matching') }}</td>
                        </tr>
                        <tr class="border-b border-gray-200">
                            <td class="py-2 pr-4 font-mono text-xs">alt_text</td>
                            <td class="py-2 pr-4">{{ __('Free text') }}</td>
                            <td class="py-2">{{ __('Image alt text') }}</td>
                        </tr>
                        <tr class="border-b border-gray-200">
                            <td class="py-2 pr-4 font-mono text-xs">caption</td>
                            <td class="py-2 pr-4">{{ __('Free text') }}</td>
                            <td class="py-2">{{ __('Image caption') }}</td>
                        </tr>
                        <tr class="border-b border-gray-200">
                            <td class="py-2 pr-4 font-mono text-xs">license_type</td>
                            <td class="py-2 pr-4 font-mono text-xs">public_domain, cc_by, cc_by_sa, cc_by_nd, cc_by_nc, cc_by_nc_sa, cc_by_nc_nd, fair_use, all_rights_reserved</td>
                            <td class="py-2">{{ __('Must be one of the listed values') }}</td>
                        </tr>
                        <tr class="border-b border-gray-200">
                            <td class="py-2 pr-4 font-mono text-xs">license_expiry_date</td>
                            <td class="py-2 pr-4">YYYY-MM-DD</td>
                            <td class="py-2">{{ __('Date format') }}</td>
                        </tr>
                        <tr class="border-b border-gray-200">
                            <td class="py-2 pr-4 font-mono text-xs">copyright</td>
                            <td class="py-2 pr-4">{{ __('Free text') }}</td>
                            <td class="py-2">{{ __('Copyright holder') }}</td>
                        </tr>
                        <tr class="border-b border-gray-200">
                            <td class="py-2 pr-4 font-mono text-xs">copyright_source</td>
                            <td class="py-2 pr-4">{{ __('Free text / URL') }}</td>
                            <td class="py-2">{{ __('Source attribution') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs">user_tags</td>
                            <td class="py-2 pr-4">{{ __('Comma-separated') }}</td>
                            <td class="py-2">{{ __('Added to existing tags, never removed') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded">
                <p class="text-xs text-yellow-800">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    <strong>{{ __('Note:') }}</strong> {{ __('Empty fields in the CSV are skipped (existing values are preserved). Only non-empty values will overwrite current data.') }}
                </p>
            </div>
        </div>
    </div>

    <!-- Step 2: Preview -->
    <div x-show="step === 2" x-cloak>
        <!-- Summary -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-clipboard-check mr-2"></i>{{ __('Preview Results') }}
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-blue-600" x-text="previewData.total"></div>
                    <div class="text-sm text-blue-800">{{ __('Total Rows') }}</div>
                </div>
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-green-600" x-text="previewData.matched"></div>
                    <div class="text-sm text-green-800">{{ __('Matched') }}</div>
                </div>
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-red-600" x-text="previewData.unmatched"></div>
                    <div class="text-sm text-red-800">{{ __('Not Found') }}</div>
                </div>
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center">
                    <div class="text-2xl font-bold text-gray-600" x-text="previewData.skipped"></div>
                    <div class="text-sm text-gray-800">{{ __('Skipped') }}</div>
                </div>
            </div>

            <!-- Matched Results -->
            <template x-if="matchedResults.length > 0">
                <div class="mb-6">
                    <h3 class="text-md font-semibold text-gray-900 mb-3">
                        <i class="attention fas fa-check-circle text-green-600 mr-2"></i>{{ __('Assets to Update') }}
                    </h3>
                    <div class="space-y-3">
                        <template x-for="result in matchedResults" :key="result.row">
                            <div class="import-result attention border border-gray-200 rounded-lg p-4">
                                <div class="flex items-start gap-4">
                                    <!-- Thumbnail -->
                                    <div class="attention flex-shrink-0">
                                        <img :src="result.asset.thumbnail_url || '/placeholder.png'"
                                             :alt="result.asset.filename"
                                             class="w-16 h-16 object-cover rounded border border-gray-200"
                                             onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22><rect fill=%22%23f3f4f6%22 width=%2264%22 height=%2264%22/><text x=%2232%22 y=%2236%22 text-anchor=%22middle%22 fill=%22%239ca3af%22 font-size=%2212%22>?</text></svg>'">
                                    </div>
                                    <!-- Info -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-sm font-medium text-gray-900" x-text="result.asset.filename"></span>
                                            <span class="text-xs text-gray-500" x-text="'({{ __('Row') }} ' + result.row + ')'"></span>
                                        </div>
                                        <div class="text-xs text-gray-500 font-mono truncate" x-text="result.asset.s3_key"></div>

                                        <!-- Changes -->
                                        <template x-if="Object.keys(result.changes).length > 0">
                                            <div class="mt-2 space-y-1">
                                                <template x-for="(change, field) in result.changes" :key="field">
                                                    <div class="text-xs">
                                                        <span class="font-medium text-gray-700" x-text="field + ':'"></span>
                                                        <template x-if="change.from !== undefined">
                                                            <span>
                                                                <span class="text-red-600 line-through" x-text="change.from || '({{ __('empty') }})'"></span>
                                                                <i class="fas fa-arrow-right mx-1 text-gray-400"></i>
                                                                <span class="text-green-600" x-text="change.to"></span>
                                                            </span>
                                                        </template>
                                                        <template x-if="change.add !== undefined">
                                                            <span class="text-green-600">
                                                                <i class="fas fa-plus mr-1"></i><span x-text="change.add"></span>
                                                            </span>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="Object.keys(result.changes).length === 0">
                                            <p class="mt-1 text-xs text-gray-400 italic">{{ __('No changes detected') }}</p>
                                        </template>

                                        <!-- Row Errors -->
                                        <template x-if="result.errors && result.errors.length > 0">
                                            <div class="mt-2">
                                                <template x-for="error in result.errors" :key="error">
                                                    <p class="text-xs text-red-600">
                                                        <i class="fas fa-exclamation-circle mr-1"></i><span x-text="error"></span>
                                                    </p>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Unmatched Results -->
            <template x-if="unmatchedResults.length > 0">
                <div class="mb-6">
                    <h3 class="text-md font-semibold text-gray-900 mb-3">
                        <i class="attention fas fa-times-circle text-red-600 mr-2"></i>{{ __('Not Found') }}
                    </h3>
                    <div class="attention bg-red-50 border border-red-200 rounded-lg p-4">
                        <ul class="space-y-1">
                            <template x-for="result in unmatchedResults" :key="result.row">
                                <li class="text-sm text-red-800">
                                    <span x-text="'{{ __('Row') }} ' + result.row + ': '"></span>
                                    <span class="font-mono" x-text="result.match_value"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </template>

            <!-- Action Buttons -->
            <div class="flex items-center justify-between">
                <button @click="step = 1"
                        class="px-4 py-2 text-sm border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                    <i class="fas fa-arrow-left mr-2"></i>{{ __('Back') }}
                </button>

                <button @click="runImport"
                        :disabled="loading || matchedResults.length === 0 || hasValidationErrors"
                        class="attention px-6 py-3 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center disabled:opacity-50 disabled:cursor-not-allowed">
                    <template x-if="loading">
                        <i class="fas fa-spinner fa-spin mr-2"></i>
                    </template>
                    <template x-if="!loading">
                        <i class="fas fa-file-import mr-2"></i>
                    </template>
                    {{ __('Import') }}
                </button>
            </div>
        </div>
    </div>

    <!-- Step 3: Results -->
    <div x-show="step === 3" x-cloak>
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-check-double mr-2"></i>{{ __('Import Complete') }}
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                    <div class="attention text-2xl font-bold text-green-600" x-text="importResult.updated"></div>
                    <div class="attention text-sm text-green-800">{{ __('Assets Updated') }}</div>
                </div>
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center">
                    <div class="attention text-2xl font-bold text-gray-600" x-text="importResult.skipped"></div>
                    <div class="attention text-sm text-gray-800">{{ __('Skipped') }}</div>
                </div>
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-center">
                    <div class="attention text-2xl font-bold text-red-600" x-text="importResult.errors.length"></div>
                    <div class="attention text-sm text-red-800">{{ __('Errors') }}</div>
                </div>
            </div>

            <!-- Import Errors -->
            <template x-if="importResult.errors && importResult.errors.length > 0">
                <div class="mb-6">
                    <h3 class="attention text-md font-semibold text-red-900 mb-3">
                        <i class="fas fa-exclamation-triangle mr-2"></i>{{ __('Errors') }}
                    </h3>
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                        <ul class="space-y-2">
                            <template x-for="err in importResult.errors" :key="err.row">
                                <li class="attention text-sm text-red-800">
                                    <strong x-text="'{{ __('Row') }} ' + err.row + ' (' + err.match_value + '):'"></strong>
                                    <ul class="ml-4 mt-1">
                                        <template x-for="e in err.errors" :key="e">
                                            <li class="text-xs" x-text="e"></li>
                                        </template>
                                    </ul>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </template>

            <button @click="startOver"
                    class="px-6 py-3 text-sm bg-orca-black text-white rounded-lg hover:bg-orca-black-hover transition-colors flex items-center">
                <i class="fas fa-redo mr-2"></i>{{ __('Start Over') }}
            </button>
        </div>
    </div>

    <!-- Error Alert -->
    <div x-show="errorMessage" x-cloak
         class="attention fixed bottom-4 right-4 bg-red-600 text-white px-6 py-3 rounded-lg shadow-lg z-50 flex items-center">
        <i class="fas fa-exclamation-circle mr-2"></i>
        <span x-text="errorMessage"></span>
        <button @click="errorMessage = ''" class="ml-4 text-white hover:text-red-200">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>

<script>
window.__pageData = window.__pageData || {};
window.__pageData.import = {
    csrfToken: @js(csrf_token()),
    routes: {
        importPreview: @js(route('import.preview')),
        importImport: @js(route('import.import')),
    },
    translations: {
        pleaseSelectCsv: @js(__('Please select a CSV file.')),
        failedToReadFile: @js(__('Failed to read file.')),
        unexpectedError: @js(__('An unexpected error occurred. Please try again.')),
        networkError: @js(__('Network error. Please check your connection and try again.')),
    },
};
</script>
@endsection
