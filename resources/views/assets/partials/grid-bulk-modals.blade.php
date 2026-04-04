    <!-- Bulk move loading modal -->
    <div x-show="bulkMoving"
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="bg-white rounded-lg shadow-xl max-w-sm w-full mx-4 p-8 text-center">
            <!-- Animated orca logo -->
            <div class="mb-6 flex justify-center">
                <div class="relative w-24 h-24">
                    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" class="w-24 h-24 animate-orca-swim">
                        <ellipse cx="50" cy="55" rx="35" ry="25" fill="#1a1a1a"/>
                        <path d="M 15 60 Q 5 50, 8 42 Q 16 48, 16 50 Z" fill="#1a1a1a"/>
                        <path d="M 15 50 Q 5 60, 8 68 Q 16 62, 16 60 Z" fill="#1a1a1a"/>
                        <path d="M 44 40 L 42 15 L 48 30 Z" fill="#1a1a1a"/>
                        <ellipse cx="60" cy="58" rx="15" ry="10" fill="white"/>
                        <ellipse cx="68" cy="48" rx="8" ry="10" fill="white" transform="rotate(-20 68 48)"/>
                        <circle cx="68" cy="48" r="3" fill="#1a1a1a"/>
                        <circle cx="69" cy="47" r="1" fill="white"/>
                        <path d="M 72 55 Q 78 58, 82 55" stroke="#1a1a1a" stroke-width="2" fill="none" stroke-linecap="round"/>
                        <ellipse cx="48" cy="70" rx="7" ry="15" fill="#1a1a1a" transform="rotate(30 48 70)"/>
                    </svg>
                </div>
            </div>

            <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ __('Moving assets') }}...</h3>
            <p class="text-sm text-gray-500 mb-5">{{ __('This may take a while depending on the number of selected assets.') }}</p>

            <!-- Animated progress bar -->
            <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                <div class="h-full bg-amber-500 rounded-full animate-orca-progress"></div>
            </div>
        </div>
    </div>

    <!-- Bulk move summary modal -->
    <div x-show="bulkMoveShowSummary"
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
         @keydown.escape.window="bulkMoveDismissSummary()">
        <div class="bg-white rounded-lg shadow-xl max-w-xl w-full mx-4" @click.away="bulkMoveDismissSummary()">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="attention fas fa-check-circle text-green-500 mr-2"></i>{{ __('Assets moved') }}
                </h3>
                <button @click="bulkMoveDismissSummary()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6">
                <p class="text-sm text-gray-600 mb-3">
                    <span x-text="bulkMoveResults?.moved || 0"></span> {{ __('asset(s) moved. Old → new S3 keys:') }}
                </p>
                <textarea readonly
                          x-ref="moveSummaryText"
                          :value="bulkMoveSummaryText"
                          class="w-full h-48 px-3 py-2 text-xs font-mono text-gray-700 bg-gray-50 border border-gray-300 rounded-lg resize-none focus:outline-none"
                          @focus="$event.target.select()"></textarea>
                <div class="mt-4 flex justify-end gap-3">
                    <button @click="bulkMoveCopySummary()"
                            class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200">
                        <i class="fas fa-copy mr-1"></i> {{ __('Copy') }}
                    </button>
                    <button @click="bulkMoveDismissSummary()"
                            class="px-4 py-2 bg-orca-black text-white text-sm rounded-lg hover:bg-orca-black-hover">
                        {{ __('Done') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk delete loading modal -->
    <div x-show="bulkDeleting"
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="bg-white rounded-lg shadow-xl max-w-sm w-full mx-4 p-8 text-center">
            <!-- Animated orca logo -->
            <div class="mb-6 flex justify-center">
                <div class="relative w-24 h-24">
                    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" class="w-24 h-24 animate-orca-swim">
                        <ellipse cx="50" cy="55" rx="35" ry="25" fill="#1a1a1a"/>
                        <path d="M 15 60 Q 5 50, 8 42 Q 16 48, 16 50 Z" fill="#1a1a1a"/>
                        <path d="M 15 50 Q 5 60, 8 68 Q 16 62, 16 60 Z" fill="#1a1a1a"/>
                        <path d="M 44 40 L 42 15 L 48 30 Z" fill="#1a1a1a"/>
                        <ellipse cx="60" cy="58" rx="15" ry="10" fill="white"/>
                        <ellipse cx="68" cy="48" rx="8" ry="10" fill="white" transform="rotate(-20 68 48)"/>
                        <circle cx="68" cy="48" r="3" fill="#1a1a1a"/>
                        <circle cx="69" cy="47" r="1" fill="white"/>
                        <path d="M 72 55 Q 78 58, 82 55" stroke="#1a1a1a" stroke-width="2" fill="none" stroke-linecap="round"/>
                        <ellipse cx="48" cy="70" rx="7" ry="15" fill="#1a1a1a" transform="rotate(30 48 70)"/>
                    </svg>
                </div>
            </div>

            <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ __('Permanently deleting assets') }}...</h3>
            <p class="text-sm text-gray-500 mb-5">{{ __('This may take a while depending on the number of selected assets.') }}</p>

            <!-- Animated progress bar -->
            <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                <div class="h-full bg-red-500 rounded-full animate-orca-progress"></div>
            </div>
        </div>
    </div>

    <!-- Bulk delete summary modal -->
    <div x-show="bulkDeleteShowSummary"
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
         @keydown.escape.window="bulkDeleteDismissSummary()">
        <div class="bg-white rounded-lg shadow-xl max-w-xl w-full mx-4" @click.away="bulkDeleteDismissSummary()">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="attention fas fa-check-circle text-green-500 mr-2"></i>{{ __('Assets permanently deleted') }}
                </h3>
                <button @click="bulkDeleteDismissSummary()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6">
                <p class="text-sm text-gray-600 mb-3">
                    <span x-text="bulkDeleteResults?.deleted || 0"></span> {{ __('asset(s) permanently deleted. Deleted S3 keys:') }}
                </p>
                <textarea readonly
                          :value="bulkDeleteSummaryText"
                          class="w-full h-48 px-3 py-2 text-xs font-mono text-gray-700 bg-gray-50 border border-gray-300 rounded-lg resize-none focus:outline-none"
                          @focus="$event.target.select()"></textarea>
                <div class="mt-4 flex justify-end gap-3">
                    <button @click="bulkDeleteCopySummary()"
                            class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200">
                        <i class="fas fa-copy mr-1"></i> {{ __('Copy') }}
                    </button>
                    <button @click="bulkDeleteDismissSummary()"
                            class="px-4 py-2 bg-orca-black text-white text-sm rounded-lg hover:bg-orca-black-hover">
                        {{ __('Done') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
