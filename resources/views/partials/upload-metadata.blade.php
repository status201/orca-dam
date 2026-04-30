{{-- Collapsible batch metadata section --}}
<div class="mb-6 border border-gray-200 rounded-lg overflow-hidden">
    <button @click="showMetadata = !showMetadata" type="button"
        class="w-full px-4 py-3 flex items-center justify-between text-left bg-gray-50 hover:bg-gray-100 transition-colors">
        <span class="text-sm font-medium text-gray-700">
            <i class="fas fa-tags mr-2 text-gray-400"></i>{{ __('Add Metadata') }}
        </span>
        <span class="flex items-center gap-2">
            <template x-if="metadataTags.length > 0 || metadataReferenceTags.length > 0 || metadataLicenseType || metadataCopyright || metadataCopyrightSource">
                <span class="text-xs text-blue-600 font-medium">
                    <i class="fas fa-check-circle mr-1"></i>{{ __('Set') }}
                </span>
            </template>
            <i class="fas fa-chevron-right text-gray-400 text-xs transition-transform duration-200"
               :class="showMetadata && 'rotate-90'"></i>
        </span>
    </button>
    <div x-show="showMetadata" x-collapse x-cloak class="border-t border-gray-200 px-4 py-4 space-y-4 bg-white">

        {{-- Tags --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Tags') }}</label>
            <div class="flex space-x-2 relative">
                <div class="flex-1 relative">
                    <input type="text"
                           x-model="metadataNewTag"
                           @input="metadataSearchTags"
                           @keydown.enter.prevent="metadataAddTagOrSelectSuggestion"
                           @keydown.down.prevent="metadataNavigateDown"
                           @keydown.up.prevent="metadataNavigateUp"
                           @keydown.escape="metadataHideSuggestions"
                           @blur="metadataHideSuggestions"
                           placeholder="{{ __('Add a tag...') }}"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent text-sm">

                    {{-- Autocomplete dropdown --}}
                    <div x-show="metadataShowSuggestions && metadataTagSuggestions.length > 0"
                         x-cloak
                         class="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                        <template x-for="(suggestion, index) in metadataTagSuggestions" :key="suggestion.type + '-' + suggestion.id">
                            <div @mousedown.prevent="metadataSelectSuggestion(suggestion)"
                                 :class="{'bg-blue-50': index === metadataSelectedIndex}"
                                 class="px-4 py-2 cursor-pointer hover:bg-blue-50 flex items-center justify-between text-sm">
                                <span x-text="suggestion.name"></span>
                                <span class="text-xs px-2 py-0.5 rounded-full font-semibold"
                                      :class="suggestion.type === 'reference' ? 'bg-orange-100 text-orange-700' : 'bg-blue-100 text-blue-700'">
                                    <span x-text="suggestion.type === 'reference' ? 'ref' : 'user'"></span>
                                    <template x-if="suggestion.type === 'reference'"><i class="fas fa-link ml-1"></i></template>
                                </span>
                            </div>
                        </template>
                    </div>
                </div>
                <button type="button" @click="metadataAddTag"
                        class="px-4 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover text-sm">
                    <i class="fas fa-plus mr-1"></i> {{ __('Add') }}
                </button>
            </div>

            {{-- Tag badges --}}
            <div class="flex flex-wrap gap-2 mt-2" x-show="metadataTags.length > 0 || metadataReferenceTags.length > 0" x-cloak>
                <template x-for="(tag, index) in metadataTags" :key="'u-' + index">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-blue-100 text-blue-700">
                        <span x-text="tag"></span>
                        <button type="button" @click="metadataRemoveTag(index)" class="ml-2 hover:text-blue-900">
                            <i class="fas fa-times"></i>
                        </button>
                    </span>
                </template>
                <template x-for="(tag, index) in metadataReferenceTags" :key="'r-' + tag.id">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-orange-100 text-orange-700">
                        <span x-text="tag.name"></span>
                        <span class="ml-2 px-1.5 py-0.5 text-[10px] font-semibold rounded-full bg-orange-200 text-orange-800">ref<i class="fas fa-link ml-1"></i></span>
                        <button type="button" @click="metadataRemoveReferenceTag(index)" class="ml-2 hover:text-orange-900">
                            <i class="fas fa-times"></i>
                        </button>
                    </span>
                </template>
            </div>
        </div>

        {{-- License Type --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('License Type') }}</label>
            <select x-model="metadataLicenseType"
                    class="w-full px-4 py-2 pr-dropdown border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent text-sm">
                <option value="">{{ __('Select a license...') }}</option>
                @foreach(\App\Models\Asset::licenseTypes() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        {{-- Copyright Information --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Copyright Information') }}</label>
            <input type="text" x-model="metadataCopyright" maxlength="500"
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent text-sm"
                   placeholder="{{ __('e.g., © 2024 Company Name, or copyright holder information') }}">
        </div>

        {{-- Copyright Source --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                {{ __('Copyright Source') }}
                <span class="text-gray-500 font-normal">{{ __('(URL or reference)') }}</span>
            </label>
            <input type="text" x-model="metadataCopyrightSource" maxlength="500"
                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent text-sm"
                   placeholder="{{ __('e.g., https://example.com/license or original source reference') }}">
        </div>

        <p class="text-xs text-gray-500">
            <i class="fas fa-info-circle mr-1"></i>
            {{ __('Metadata will be applied to all uploaded assets in this batch.') }}
        </p>
    </div>
</div>
