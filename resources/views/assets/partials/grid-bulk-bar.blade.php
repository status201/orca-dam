    <!-- Floating Bulk Action Bar -->
    <div x-show="$store.bulkSelection.hasSelection"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-y-full opacity-0"
         x-transition:enter-end="translate-y-0 opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="translate-y-0 opacity-100"
         x-transition:leave-end="translate-y-full opacity-0"
         class="fixed bottom-0 left-0 right-0 z-40 bg-gray-900 text-white shadow-2xl border-t border-gray-700">
        <div class="mx-auto px-6 py-3">
            <div class="flex flex-wrap items-center gap-3">
                <!-- Selected count -->
                <span class="text-sm font-medium whitespace-nowrap">
                    <i class="fas fa-check-circle mr-1"></i>
                    <span x-text="$store.bulkSelection.selected.length"></span> {{ __('selected') }}
                </span>

                <div class="w-px h-6 bg-gray-600 hidden sm:block"></div>

                <!-- Add tag input with autocomplete -->
                <div class="relative flex items-center gap-2">
                    <div class="relative">
                        <input type="text"
                               x-model="bulkTagInput"
                               @input="bulkFilterTagSuggestions()"
                               @keydown.enter="if(bulkTagInput.trim()) { bulkAddTag(); }"
                               @keydown.escape="bulkShowSuggestions = false"
                               @keydown.arrow-down.prevent="bulkSelectNextSuggestion()"
                               @keydown.arrow-up.prevent="bulkSelectPrevSuggestion()"
                               @blur="setTimeout(() => bulkShowSuggestions = false, 200)"
                               @focus="bulkFilterTagSuggestions()"
                               :disabled="bulkLoading"
                               placeholder="{{ __('Add tag') }}..."
                               class="px-3 py-1.5 text-sm text-gray-900 border border-gray-300 rounded-lg focus:ring-2 focus:ring-white focus:border-transparent w-40 disabled:opacity-50">

                        <!-- Autocomplete dropdown (opens upward) -->
                        <div x-show="bulkShowSuggestions && bulkFilteredSuggestions.length > 0"
                             x-cloak
                             class="absolute bottom-full mb-1 w-full bg-white border border-gray-300 rounded-lg shadow-lg max-h-40 overflow-y-auto">
                            <template x-for="(suggestion, index) in bulkFilteredSuggestions" :key="suggestion.type + '-' + suggestion.id">
                                <button type="button"
                                        @mousedown.prevent="bulkSelectSuggestion(suggestion)"
                                        :class="bulkSelectedSuggestionIndex === index ? 'bg-blue-100' : 'hover:bg-gray-100'"
                                        class="w-full text-left px-3 py-1.5 text-xs text-gray-700 border-b border-gray-100 last:border-b-0 flex items-center justify-between gap-2">
                                    <span x-text="suggestion.name"></span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded-full font-semibold"
                                          :class="suggestion.type === 'reference' ? 'bg-orange-100 text-orange-700' : 'bg-blue-100 text-blue-700'">
                                        <span x-text="suggestion.type === 'reference' ? 'ref' : 'user'"></span>
                                        <template x-if="suggestion.type === 'reference'"><i class="fas fa-link ml-1"></i></template>
                                    </span>
                                </button>
                            </template>
                        </div>
                    </div>
                    <button @click="bulkAddTag()"
                            :disabled="!bulkTagInput.trim() || bulkLoading"
                            class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 disabled:opacity-50 whitespace-nowrap">
                        <i class="fas fa-plus mr-1"></i> {{ __('Add tag') }}
                    </button>
                </div>

                <div class="w-px h-6 bg-gray-600 hidden sm:block"></div>

                <!-- Remove tags button -->
                <div class="relative">
                    <button @click="bulkShowRemovePanel ? (bulkShowRemovePanel = false) : bulkLoadRemoveTags()"
                            :disabled="bulkLoading"
                            class="attention px-3 py-1.5 bg-red-500 text-white text-sm rounded-lg hover:bg-red-600 disabled:opacity-50 whitespace-nowrap">
                        <i class="fas fa-tags mr-1"></i> {{ __('Remove tags') }}
                        <i :class="bulkLoading ? 'fas fa-spinner fa-spin ml-1' : ''"></i>
                    </button>

                    <!-- Remove tags panel (opens upward) -->
                    <div x-show="bulkShowRemovePanel"
                         x-cloak
                         @click.away="bulkShowRemovePanel = false"
                         class="absolute bottom-full mb-2 left-0 w-72 bg-white border border-gray-300 rounded-lg shadow-xl p-3 invert-scrollbar-colors">
                        <p class="text-xs text-gray-500 mb-2">{{ __('Click a tag to remove it from all selected assets') }}</p>
                        <div class="flex flex-wrap gap-1.5 max-h-60 overflow-y-auto">
                            <template x-if="bulkRemoveTags.length === 0">
                                <p class="text-xs text-gray-400">{{ __('No tags found on selected assets') }}</p>
                            </template>
                            <template x-for="tag in bulkRemoveTags" :key="tag.id">
                                <button @click="bulkRemoveTag(tag.id)"
                                        :disabled="bulkLoading"
                                        :class="tag.type === 'ai' ? 'bg-purple-100 text-purple-700 hover:bg-purple-200' : (tag.type === 'reference' ? 'bg-orange-100 text-orange-700 hover:bg-orange-200' : 'bg-blue-100 text-blue-700 hover:bg-blue-200')"
                                        class="attention inline-flex items-center px-2 py-1 rounded text-xs font-medium disabled:opacity-50 transition-colors">
                                    <span x-text="tag.name"></span>
                                    <span class="ml-1 text-[0.65rem] opacity-70" x-text="'(' + tag.count + ')'"></span>
                                    <i class="fas fa-times ml-1.5 text-xs"></i>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="w-px h-6 bg-gray-600 hidden sm:block"></div>

                <!-- Bulk download -->
                <button @click="bulkDownload()"
                        :disabled="bulkDownloading"
                        class="attention px-3 py-1.5 bg-emerald-600 text-white text-sm rounded-lg hover:bg-emerald-700 disabled:opacity-50 whitespace-nowrap">
                    <i :class="bulkDownloading ? 'fas fa-spinner fa-spin mr-1' : 'fas fa-download mr-1'"></i> {{ __('Download') }}
                </button>

                @if(!auth()->user()->isApiUser())
                <div class="w-px h-6 bg-gray-600 hidden sm:block"></div>

                <!-- Bulk move to trash -->
                <button @click="bulkTrash()"
                        :disabled="bulkTrashing"
                        class="attention px-3 py-1.5 bg-red-500 text-white text-sm rounded-lg hover:bg-red-600 disabled:opacity-50 whitespace-nowrap">
                    <i :class="bulkTrashing ? 'fas fa-spinner fa-spin mr-1' : 'fas fa-trash mr-1'"></i> {{ __('Move to Trash') }}
                </button>
                @endif

                @if(auth()->user()->isAdmin() && \App\Models\Setting::get('maintenance_mode', false))
                <div class="w-px h-6 bg-gray-600 hidden sm:block"></div>

                <!-- Bulk move -->
                <div class="relative">
                    <button @click="bulkMoveOpen = !bulkMoveOpen"
                            :disabled="bulkMoving"
                            class="attention px-3 py-1.5 bg-amber-600 text-white text-sm rounded-lg hover:bg-amber-700 disabled:opacity-50 whitespace-nowrap">
                        <i class="fas fa-folder-open mr-1"></i> {{ __('Move asset(s)') }}
                        <i :class="bulkMoving ? 'fas fa-spinner fa-spin ml-1' : ''"></i>
                    </button>

                    <!-- Folder picker panel (opens upward) -->
                    <div x-show="bulkMoveOpen"
                         x-cloak
                         @click.away="bulkMoveOpen = false"
                         class="absolute bottom-full mb-2 left-0 w-72 bg-white border border-gray-300 rounded-lg shadow-xl p-3">
                        <p class="text-xs text-gray-500 mb-2">{{ __('Select destination folder') }}</p>
                        <select x-model="bulkMoveFolder" class="w-full text-black px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-transparent mb-2">
                            <option value="">{{ __('Select folder') }}...</option>
                            <x-folder-tree-options :folders="$folders" :root-folder="$rootFolder" />
                        </select>
                        <button @click="bulkMoveApply()"
                                :disabled="!bulkMoveFolder || bulkMoving"
                                class="attention w-full px-3 py-1.5 bg-amber-600 text-white text-sm rounded-lg hover:bg-amber-700 disabled:opacity-50">
                            <i class="fas fa-arrows-alt mr-1"></i> {{ __('Apply') }}
                        </button>
                    </div>
                </div>

                <div class="w-px h-6 bg-gray-600 hidden sm:block"></div>

                <!-- Bulk permanent delete -->
                <button @click="bulkForceDelete()"
                        :disabled="bulkDeleting"
                        class="attention px-3 py-1.5 bg-red-700 text-white text-sm rounded-lg hover:bg-red-800 disabled:opacity-50 whitespace-nowrap">
                    <i class="fas fa-skull-crossbones mr-1"></i> {{ __('Permanent delete') }}
                    <i :class="bulkDeleting ? 'fas fa-spinner fa-spin ml-1' : ''"></i>
                </button>
                @endif

                <!-- Spacer -->
                <div class="flex-1"></div>

                <!-- Clear selection -->
                <button @click="$store.bulkSelection.clear(); bulkShowRemovePanel = false; bulkMoveOpen = false"
                        class="px-3 py-1.5 bg-gray-700 text-white text-sm rounded-lg hover:bg-gray-600 whitespace-nowrap">
                    <i class="fas fa-times mr-1"></i> {{ __('Clear selection') }}
                </button>
            </div>
        </div>
    </div>
