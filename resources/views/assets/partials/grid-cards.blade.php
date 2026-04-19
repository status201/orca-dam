    @php
        // Append the current index query string so the show page can reconstruct
        // the result-set context (for cycle navigation and the back button).
        $showQuery = request()->getQueryString();
        $showSuffix = $showQuery ? '?'.$showQuery : '';
    @endphp
    <!-- Asset grid -->
    <!-- Grid View -->
    <div x-show="viewMode === 'grid'" x-cloak class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 xxl:grid-cols-12 gap-4">
        @foreach($assets as $asset)
        <div class="group relative bg-white rounded-lg shadow hover:shadow-lg transition-shadow overflow-hidden cursor-pointer"
             x-data="assetCard({{ $asset->id }})"
             @click="if ($store.bulkSelection.hasSelection) { $store.bulkSelection.shiftToggle({{ $asset->id }}, $event); } else { window.location.href = '{{ route('assets.show', $asset).$showSuffix }}'; }">
            <!-- Selection checkbox -->
            <div class="absolute top-2 left-2 z-20"
                 :class="$store.bulkSelection.hasSelection || $store.bulkSelection.isSelected({{ $asset->id }}) ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'"
                 @click.stop="$store.bulkSelection.shiftToggle({{ $asset->id }}, $event)">
                <div :class="$store.bulkSelection.isSelected({{ $asset->id }}) ? 'bg-orca-black border-orca-black' : 'bg-white/80 border-gray-400'"
                     class="w-6 h-6 rounded border-2 flex items-center justify-center cursor-pointer hover:border-orca-black transition-colors">
                    <i x-show="$store.bulkSelection.isSelected({{ $asset->id }})" class="fas fa-check text-white text-xs"></i>
                </div>
            </div>

            <!-- Thumbnail -->
            <div class="aspect-square bg-gray-100 relative">
                @if($asset->is_missing)
                <div class="absolute top-1 right-1 z-10">
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-red-600 text-white">
                        <i class="fas fa-triangle-exclamation mr-1"></i>{{ __('Missing') }}
                    </span>
                </div>
                @endif
                @if($asset->isImage() && $asset->thumbnail_url)
                    <img src="{{ $asset->thumbnail_url }}"
                         alt="{{ $asset->filename }}"
                         :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                         loading="lazy">
                @elseif($asset->isSvg())
                    <img src="{{ $asset->url }}"
                         alt="{{ $asset->filename }}"
                         :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                         loading="lazy">
                @elseif($asset->isVideo() && $asset->thumbnail_url)
                    <img src="{{ $asset->thumbnail_url }}"
                         alt="{{ $asset->filename }}"
                         :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                         loading="lazy">
                    <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                        <div class="w-10 h-10 bg-black/50 rounded-full flex items-center justify-center">
                            <i class="fas fa-play text-white text-sm ml-0.5"></i>
                        </div>
                    </div>
                @elseif($asset->isPdf() && $asset->thumbnail_url)
                    <img src="{{ $asset->thumbnail_url }}"
                         alt="{{ $asset->filename }}"
                         :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                         loading="lazy">
                    <div class="absolute attention top-0 right-0 w-6 h-6 bg-white/60 rounded-bl-lg flex items-center justify-center pointer-events-none">
                        <i class="fas fa-file-pdf text-red-600 text-sm"></i>
                    </div>
                @elseif($asset->isMathMl())
                    <x-mml-preview :asset="$asset" size="thumb" />
                @else
                    <div class="w-full h-full flex items-center justify-center">
                        <x-file-type-icon :asset="$asset" class="text-9xl opacity-60" />
                    </div>
                @endif

                <!-- Overlay with actions -->
                <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition-all flex items-center justify-center opacity-0 group-hover:opacity-100">
                    <button @click.stop="downloadAsset('{{ route('assets.download', $asset) }}')"
                            :disabled="downloading"
                            :class="downloading ? 'bg-green-600' : 'bg-white hover:bg-gray-100'"
                            :title="downloading ? @js(__('Downloading...')) : @js(__('Download'))"
                            class="text-gray-900 px-3 py-2 rounded-lg transition-all duration-300 mr-2">
                        <i :class="downloading ? 'fas fa-spinner fa-spin text-white' : 'fas fa-download'"></i>
                    </button>
                    <button @click.stop="copyAssetUrl('{{ $asset->url }}')"
                            :class="copied ? 'attention bg-green-600' : 'bg-white hover:bg-gray-100'"
                            :title="copied ? @js(__('Copied!')) : @js(__('Copy URL'))"
                            class="text-gray-900 px-3 py-2 rounded-lg transition-all duration-300 mr-2">
                        <i :class="copied ? 'fas fa-check text-white' : 'fas fa-copy'"></i>
                    </button>
                    <a href="{{ route('assets.edit', $asset) }}"
                       @click.stop
                       class="bg-white text-gray-900 px-3 py-2 rounded-lg hover:bg-gray-100 transition-colors"
                       title="{{ __('Edit') }}">
                        <i class="fas fa-edit"></i>
                    </a>
                </div>
            </div>

            <!-- Info -->
            <div class="p-3">
                <p class="text-sm font-medium text-gray-900 truncate" title="{{ $asset->filename }}">
                    {{ $asset->filename }}
                </p>
                <div class="text-xs text-gray-500 mt-1 space-y-0.5">
                    <p><i class="fas fa-hdd mr-1"></i>{{ $asset->formatted_size }}</p>
                    <p title="{{ __('Last modified') }} {{ $asset->updated_at }}"><i class="fas fa-clock mr-1"></i>{{ $asset->updated_at->diffForHumans() }}</p>
                    <p class="truncate" title="{{ __('Uploaded by') }} {{ $asset->user->name  }}"><i class="fas fa-user mr-1"></i>{{ $asset->user->name }}</p>
                </div>

                @if($asset->tags->count() > 0)
                <div class="flex flex-wrap gap-1 mt-2">
                    @foreach($asset->tags->take(2) as $tag)
                    <x-tag-badge :tag="$tag" size="xs" />
                    @endforeach

                    @if($asset->tags->count() > 2)
                    <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">
                        +{{ $asset->tags->count() - 2 }}
                    </span>
                    @endif
                </div>
                @endif
            </div>
        </div>
        @endforeach
    </div>

    <!-- List/Table View -->
    <div x-show="viewMode === 'list'" x-cloak class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto invert-scrollbar-colors">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-center w-10">
                            <div @click="$store.bulkSelection.toggleSelectAll()"
                                 :class="$store.bulkSelection.allOnPageSelected ? 'bg-orca-black border-orca-black' : 'bg-white border-gray-400'"
                                 class="w-5 h-5 rounded border-2 flex items-center justify-center cursor-pointer hover:border-orca-black transition-colors mx-auto">
                                <i x-show="$store.bulkSelection.allOnPageSelected" class="fas fa-check text-white text-xs"></i>
                            </div>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">
                            {{ __('Thumbnail') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[200px]">
                            {{ __('Filename') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-40">
                            {{ __('Actions') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[250px]">
                            {{ __('S3 Key') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">
                            {{ __('Size') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[300px]">
                            {{ __('Tags') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[180px]">
                            {{ __('License') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($assets as $asset)
                    <tr x-data="assetRow({{ $asset->id }}, @js($asset->tags->map(fn($t) => ['id' => $t->id, 'name' => $t->name, 'type' => $t->type, 'attached_by' => $t->pivot->attached_by ?? $t->type])->toArray()), '{{ $asset->license_type }}', '{{ $asset->url }}')"
                        class="hover:bg-gray-50 transition-colors">

                        <!-- Selection checkbox -->
                        <td class="px-4 py-3 text-center">
                            <div @click="$store.bulkSelection.shiftToggle({{ $asset->id }}, $event)"
                                 :class="$store.bulkSelection.isSelected({{ $asset->id }}) ? 'bg-orca-black border-orca-black' : 'bg-white border-gray-400'"
                                 class="w-5 h-5 rounded border-2 flex items-center justify-center cursor-pointer hover:border-orca-black transition-colors mx-auto">
                                <i x-show="$store.bulkSelection.isSelected({{ $asset->id }})" class="fas fa-check text-white text-xs"></i>
                            </div>
                        </td>

                        <!-- Thumbnail -->
                        <td class="px-4 py-3">
                            <a href="{{ route('assets.show', $asset).$showSuffix }}" class="block">
                                <div class="w-16 h-16 bg-gray-100 rounded flex items-center justify-center overflow-hidden hover:ring-2 hover:ring-orca-500 transition-all relative">
                                    @if($asset->is_missing)
                                    <div class="absolute top-0 right-0 z-10">
                                        <span class="inline-flex items-center px-1 py-0.5 rounded text-[0.6rem] font-medium bg-red-600 text-white">
                                            <i class="fas fa-triangle-exclamation"></i>
                                        </span>
                                    </div>
                                    @endif
                                    @if($asset->isImage() && $asset->thumbnail_url)
                                        <img src="{{ $asset->thumbnail_url }}"
                                             alt="{{ $asset->filename }}"
                                             :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                                             loading="lazy">
                                    @elseif($asset->isSvg())
                                        <img src="{{ $asset->url }}"
                                             alt="{{ $asset->filename }}"
                                             :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                                             loading="lazy">
                                    @elseif($asset->isVideo() && $asset->thumbnail_url)
                                        <img src="{{ $asset->thumbnail_url }}"
                                             alt="{{ $asset->filename }}"
                                             :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                                             loading="lazy">
                                        <div class="absolute inset-0 flex items-center justify-center">
                                            <div class="w-6 h-6 bg-black/50 rounded-full flex items-center justify-center">
                                                <i class="fas fa-play text-white text-[0.5rem] ml-px"></i>
                                            </div>
                                        </div>
                                    @elseif($asset->isPdf() && $asset->thumbnail_url)
                                        <img src="{{ $asset->thumbnail_url }}"
                                             alt="{{ $asset->filename }}"
                                             :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                                             loading="lazy">
                                        <div class="absolute attention top-0 right-0 w-6 h-6 bg-white/60 rounded-bl flex items-center justify-center pointer-events-none">
                                            <i class="fas fa-file-pdf text-red-600 text-xs"></i>
                                        </div>
                                    @elseif($asset->isMathMl())
                                        <x-mml-preview :asset="$asset" size="thumb" />
                                    @else
                                        <x-file-type-icon :asset="$asset" class="text-3xl opacity-60" />
                                    @endif
                                </div>
                            </a>
                        </td>

                        <!-- Filename -->
                        <td class="px-4 py-3">
                            <div class="text-sm font-medium text-gray-900">{{ $asset->filename }}</div>
                            <div class="text-xs text-gray-500 mt-1">
                                <span title="{{ __('Last modified') }} {{ $asset->updated_at }}">{{ $asset->updated_at->diffForHumans() }}</span>
                                <span class="mx-1">•</span>
                                <span title="{{ __('Uploaded by') }} {{ $asset->user->email }}">{{ $asset->user->name }}</span>
                            </div>
                        </td>

                        <!-- Actions -->
                        <td class="actions-icons px-4 py-3">
                            <div class="flex gap-3">
                                <a href="{{ route('assets.show', $asset).$showSuffix }}"
                                   class="text-blue-600 hover:text-blue-800"
                                   title="{{ __('View asset') }}">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button @click="copyUrl()"
                                        :class="copied ? 'attention text-green-600' : 'text-gray-600 hover:text-gray-800'"
                                        :title="copied ? @js(__('Copied!')) : @js(__('Copy URL'))">
                                    <i :class="copied ? 'fas fa-check' : 'fas fa-copy'"></i>
                                </button>
                                <a href="{{ route('assets.edit', $asset) }}"
                                   class="attention text-yellow-600 hover:text-yellow-800"
                                   title="{{ __('Edit asset') }}">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="{{ route('assets.replace', $asset) }}"
                                   class="attention text-amber-600 hover:text-amber-800"
                                   title="{{ __('Replace asset') }}">
                                    <i class="fas fa-shuffle"></i>
                                </a>
                                <button @click="deleteAsset()"
                                        :disabled="loading"
                                        class="text-red-800 hover:text-red-900 disabled:opacity-50"
                                        title="{{ __('Delete asset') }}">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>

                        <!-- S3 Key -->
                        <td class="px-4 py-3">
                            <div class="text-xs text-gray-600 font-mono break-all">{{ $asset->s3_key }}</div>
                        </td>

                        <!-- File Size -->
                        <td class="px-4 py-3">
                            <span class="text-sm text-gray-700">{{ $asset->formatted_size }}</span>
                            @if($asset->width && $asset->height)
                                <div class="text-xs text-gray-500">{{ $asset->width }} x {{ $asset->height }}</div>
                            @endif
                        </td>

                        <!-- Tags with Inline Editing -->
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                <!-- Existing Tags -->
                                <template x-for="(tag, index) in tags" :key="tag.id">
                                    <span x-data="{ expanded: false }"
                                          :class="[
                                            tag.type === 'ai' ? 'bg-purple-100 text-purple-700' : (tag.type === 'reference' ? 'bg-orange-100 text-orange-700' : 'bg-blue-100 text-blue-700'),
                                            tag.attached_by && tag.attached_by !== tag.type ? (tag.attached_by === 'ai' ? 'ring-2 ring-purple-400' : (tag.attached_by === 'reference' ? 'ring-2 ring-orange-400' : 'ring-2 ring-blue-400')) : ''
                                          ]"
                                          :title="(tag.type === 'reference' && tag.name.length > 12 ? tag.name : '') + (tag.attached_by && tag.attached_by !== tag.type ? (tag.type === 'reference' && tag.name.length > 12 ? ' — ' : '') + '{{ __('Created as') }}: ' + tag.type + ', {{ __('Attached by') }}: ' + tag.attached_by : '')"
                                          class="tag attention inline-flex items-center px-2 py-1 rounded text-xs font-medium">
                                        <span x-text="tag.type === 'reference' && tag.name.length > 12 && !expanded ? tag.name.substring(0, 12) + '…' : tag.name"
                                              :style="tag.type === 'reference' && tag.name.length > 12 ? 'cursor:pointer' : ''"
                                              @click.stop="if (tag.type === 'reference' && tag.name.length > 12) expanded = !expanded"></span>
                                        <button @click="removeTag(tag)"
                                                :disabled="loading"
                                                class="ml-1 hover:text-red-600 disabled:opacity-50"
                                                title="{{ __('Remove tag') }}">
                                            <i class="fas fa-times text-xs"></i>
                                        </button>
                                    </span>
                                </template>

                                <!-- Add Tag Button/Input -->
                                <div x-show="!addingTag">
                                    <button @click="showAddTagInput()"
                                            :disabled="loading"
                                            class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-600 hover:bg-gray-200 disabled:opacity-50">
                                        <i class="fas fa-plus mr-1"></i> {{ __('Add') }}
                                    </button>
                                </div>

                                <div x-show="addingTag" x-cloak class="relative inline-flex items-center gap-1">
                                    <div class="relative">
                                        <input type="text"
                                               x-ref="tagInput"
                                               x-model="newTagName"
                                               @input="filterTagSuggestions()"
                                               @keydown.enter="if(newTagName.trim()) { addTag(); }"
                                               @keydown.escape="cancelAddTag()"
                                               @keydown.arrow-down.prevent="selectNextSuggestion()"
                                               @keydown.arrow-up.prevent="selectPrevSuggestion()"
                                               @blur="setTimeout(() => showSuggestions = false, 200)"
                                               @focus="filterTagSuggestions()"
                                               class="px-2 py-1 text-xs border border-gray-300 rounded focus:ring-1 focus:ring-blue-500"
                                               placeholder="{{ __('Tag name') }}"
                                               style="width: 120px;">

                                        <!-- Autocomplete dropdown -->
                                        <div x-show="showSuggestions && filteredSuggestions.length > 0"
                                             x-cloak
                                             class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded shadow-lg max-h-40 overflow-y-auto">
                                            <template x-for="(suggestion, index) in filteredSuggestions" :key="suggestion">
                                                <button type="button"
                                                        @mousedown.prevent="selectSuggestion(suggestion)"
                                                        :class="selectedSuggestionIndex === index ? 'bg-blue-100' : 'hover:bg-gray-100'"
                                                        class="w-full text-left px-3 py-1.5 text-xs text-gray-700 border-b border-gray-100 last:border-b-0"
                                                        x-text="suggestion">
                                                </button>
                                            </template>
                                        </div>
                                    </div>

                                    <button @click="addTag()"
                                            :disabled="!newTagName.trim() || loading"
                                            class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 disabled:opacity-50">
                                        {{ __('Add') }}
                                    </button>
                                    <button @click="cancelAddTag()"
                                            class="px-2 py-1 bg-gray-200 text-gray-700 text-xs rounded hover:bg-gray-300">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </td>

                        <!-- License with Inline Editing -->
                        <td class="px-4 py-3">
                            <select x-model="license"
                                    @change="updateLicense()"
                                    :disabled="loading"
                                    class="text-sm border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 disabled:opacity-50">
                                <option value="">{{ __('Not specified') }}</option>
                                <option value="public_domain">{{ __('Public Domain') }}</option>
                                <option value="cc0">{{ __('CC0') }}</option>
                                <option value="cc_by">{{ __('CC BY') }}</option>
                                <option value="cc_by_sa">{{ __('CC BY-SA') }}</option>
                                <option value="cc_by_nd">{{ __('CC BY-ND') }}</option>
                                <option value="cc_by_nc">{{ __('CC BY-NC') }}</option>
                                <option value="cc_by_nc_sa">{{ __('CC BY-NC-SA') }}</option>
                                <option value="cc_by_nc_nd">{{ __('CC BY-NC-ND') }}</option>
                                <option value="fair_use">{{ __('Fair Use') }}</option>
                                <option value="all_rights_reserved">{{ __('All Rights Reserved') }}</option>
                                <option value="other">{{ __('Other') }}</option>
                            </select>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
