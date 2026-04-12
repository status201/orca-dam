@extends('layouts.app')

@section('title', __('Animated GIF Maker'))

@section('content')
<div class="max-w-7xl mx-auto sm:px-6 lg:px-8" x-data="gifMaker()">

    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <a href="{{ route('tools.index') }}" class="inline-flex items-center text-orca-black hover:text-orca-black-hover">
            <i class="fas fa-arrow-left mr-2"></i> {{ __('Back to Tools') }}
        </a>
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <a href="{{ route('tools.index') }}" class="hover:text-orca-teal transition-colors">
                <i class="fas fa-wrench mr-1"></i>{{ __('Tools') }}
            </a>
            <i class="fas fa-chevron-right text-xs"></i>
            <span class="text-gray-700 font-medium">{{ __('Animated GIF Maker') }}</span>
        </div>
    </div>

    {{-- Frames card --}}
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-gray-900">
                <i class="fas fa-film text-orca-teal mr-1"></i>
                {{ __('Frames') }}
                <span class="inline-flex items-center ml-2 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">{{ __('Beta') }}</span>
                <span class="text-sm font-normal text-gray-500 ml-2" x-show="frames.length > 0" x-text="'(' + frames.length + ' frame' + (frames.length !== 1 ? 's' : '') + ')'"></span>
            </h2>
            <button x-show="frames.length > 0" @click="clearAll()" class="text-sm text-gray-500 hover:text-gray-700">
                <i class="fas fa-eraser mr-1"></i> {{ __('Clear all') }}
            </button>
        </div>

        {{-- Drop zone --}}
        <div
            class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center cursor-pointer hover:border-orca-teal hover:bg-gray-50 transition-colors mb-4"
            @click="$refs.fileInput.click()"
            @dragover.prevent="$el.classList.add('border-orca-teal', 'bg-gray-50')"
            @dragleave.prevent="$el.classList.remove('border-orca-teal', 'bg-gray-50')"
            @drop.prevent="$el.classList.remove('border-orca-teal', 'bg-gray-50'); addImages($event.dataTransfer.files)">
            <i class="fas fa-images fa-2x text-gray-400 mb-2"></i>
            <p class="text-sm text-gray-600">{{ __('Drop images here or click to browse') }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ __('PNG, JPG, WebP — multiple files supported') }}</p>
        </div>
        <input type="file" x-ref="fileInput" class="hidden" accept="image/*" multiple
            @change="addImages($event.target.files); $event.target.value = ''">

        {{-- Frame strip --}}
        <div x-show="frames.length > 0" x-ref="frameList" class="flex gap-3 overflow-x-auto pb-2" style="scrollbar-width: thin;">
            <template x-for="(frame, idx) in frames" :key="frame.id">
                <div class="flex-shrink-0 w-32 border border-gray-200 rounded-lg overflow-hidden bg-gray-50">
                    {{-- Drag handle + frame number --}}
                    <div class="flex items-center justify-between px-2 py-1 bg-gray-100 border-b border-gray-200">
                        <span class="drag-handle cursor-grab active:cursor-grabbing text-gray-400 hover:text-gray-600">
                            <i class="fas fa-grip-vertical text-xs"></i>
                        </span>
                        <span class="text-xs font-medium text-gray-500" x-text="'#' + (idx + 1)"></span>
                        <div class="flex gap-1">
                            <button @click="duplicateFrame(frame.id)" class="text-gray-400 hover:text-orca-teal" title="{{ __('Duplicate') }}">
                                <i class="fas fa-copy text-xs"></i>
                            </button>
                            <button @click="removeFrame(frame.id)" class="text-gray-400 hover:text-red-500" title="{{ __('Remove') }}">
                                <i class="fas fa-times text-xs"></i>
                            </button>
                        </div>
                    </div>

                    {{-- Thumbnail --}}
                    <div class="h-20 flex items-center justify-center bg-white p-1">
                        <img :src="frame.objectUrl" class="max-w-full max-h-full object-contain" :alt="'Frame ' + (idx + 1)">
                    </div>

                    {{-- Per-frame delay --}}
                    <div class="px-2 py-1.5 border-t border-gray-200">
                        <label class="text-[10px] text-gray-400 block">{{ __('Delay (ms)') }}</label>
                        <input type="number" x-model.number="frame.delay" min="50" max="10000" step="50"
                            :placeholder="globalDelay"
                            class="w-full text-xs border border-gray-200 rounded px-1.5 py-0.5 focus:outline-none focus:ring-1 focus:ring-orca-teal">
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- Settings card --}}
    <div x-show="frames.length > 0" class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-base font-semibold text-gray-900 mb-4">
            <i class="fas fa-sliders text-gray-400 mr-1"></i>
            {{ __('Settings') }}
        </h2>

        {{-- Row 1: Timing --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            {{-- Frame delay --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Frame delay') }}</label>
                <div class="flex items-center gap-2">
                    <input type="number" x-model.number="globalDelay" min="50" max="10000" step="50"
                        class="w-24 text-sm border border-gray-300 rounded-md px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent">
                    <span class="text-xs text-gray-400">ms</span>
                </div>
                <div class="flex gap-1.5 mt-2">
                    <button @click="globalDelay = 200" :class="globalDelay === 200 ? 'bg-orca-teal text-white border-orca-teal' : 'bg-white text-gray-600 border-gray-200 hover:border-gray-400'"
                        class="text-xs px-2 py-0.5 rounded border transition-colors">{{ __('Fast') }}</button>
                    <button @click="globalDelay = 500" :class="globalDelay === 500 ? 'bg-orca-teal text-white border-orca-teal' : 'bg-white text-gray-600 border-gray-200 hover:border-gray-400'"
                        class="text-xs px-2 py-0.5 rounded border transition-colors">{{ __('Normal') }}</button>
                    <button @click="globalDelay = 1000" :class="globalDelay === 1000 ? 'bg-orca-teal text-white border-orca-teal' : 'bg-white text-gray-600 border-gray-200 hover:border-gray-400'"
                        class="text-xs px-2 py-0.5 rounded border transition-colors">{{ __('Slow') }}</button>
                </div>
            </div>

            {{-- Pre-delay --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Pre-delay') }}</label>
                <div class="flex items-center gap-2">
                    <input type="number" x-model.number="preDelay" min="0" max="10000" step="100"
                        class="w-24 text-sm border border-gray-300 rounded-md px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent">
                    <span class="text-xs text-gray-400">ms</span>
                </div>
                <p class="text-xs text-gray-400 mt-1">{{ __('Extra hold time on first frame') }}</p>
            </div>

            {{-- Post-delay --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Post-delay') }}</label>
                <div class="flex items-center gap-2">
                    <input type="number" x-model.number="postDelay" min="0" max="10000" step="100"
                        class="w-24 text-sm border border-gray-300 rounded-md px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent">
                    <span class="text-xs text-gray-400">ms</span>
                </div>
                <p class="text-xs text-gray-400 mt-1">{{ __('Extra hold time on last frame') }}</p>
            </div>
        </div>

        {{-- Row 2: Transition, Loop, Output --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            {{-- Transition --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Transition') }}</label>
                <select x-model="transition"
                    class="w-full pr-dropdown text-sm border border-gray-300 rounded-md px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent">
                    <option value="none">{{ __('None (instant cut)') }}</option>
                    <option value="fade">{{ __('Fade') }}</option>
                    <option value="slide-left">{{ __('Slide left') }}</option>
                    <option value="slide-right">{{ __('Slide right') }}</option>
                    <option value="slide-up">{{ __('Slide up') }}</option>
                    <option value="slide-down">{{ __('Slide down') }}</option>
                </select>
                <div x-show="transition !== 'none'" class="flex items-center gap-2 mt-2">
                    <label class="text-xs text-gray-500">{{ __('Duration') }}:</label>
                    <input type="number" x-model.number="transitionDuration" min="100" max="2000" step="50"
                        class="w-20 text-xs border border-gray-200 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-orca-teal">
                    <span class="text-xs text-gray-400">ms</span>
                </div>
            </div>

            {{-- Loop --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Loop') }}</label>
                <select x-model="loopMode"
                    class="w-full pr-dropdown text-sm border border-gray-300 rounded-md px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent">
                    <option value="forever">{{ __('Loop forever') }}</option>
                    <option value="once">{{ __('Play once (no loop)') }}</option>
                    <option value="count">{{ __('Custom count') }}</option>
                </select>
                <div x-show="loopMode === 'count'" class="flex items-center gap-2 mt-2">
                    <input type="number" x-model.number="loopCount" min="1" max="100" step="1"
                        class="w-20 text-xs border border-gray-200 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-orca-teal">
                    <span class="text-xs text-gray-400">{{ __('iterations') }}</span>
                </div>
            </div>

            {{-- Output size --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Output size') }}</label>
                <div class="flex items-center gap-2">
                    <input type="number" x-model.number="outputWidth" @change="updateWidth()" min="50" max="2000" step="10"
                        class="w-20 text-sm border border-gray-300 rounded-md px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent">
                    <span class="text-xs text-gray-400">&times;</span>
                    <input type="number" x-model.number="outputHeight" @change="updateHeight()" min="50" max="2000" step="10"
                        class="w-20 text-sm border border-gray-300 rounded-md px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent">
                    <span class="text-xs text-gray-400">px</span>
                    <button @click="lockAspectRatio = !lockAspectRatio"
                        :class="lockAspectRatio ? 'text-orca-teal' : 'text-gray-400'"
                        class="hover:text-orca-teal-hover transition-colors" :title="lockAspectRatio ? '{{ __('Unlock aspect ratio') }}' : '{{ __('Lock aspect ratio') }}'">
                        <i :class="lockAspectRatio ? 'fas fa-lock' : 'fas fa-lock-open'" class="text-sm"></i>
                    </button>
                </div>
                <button @click="fitToFrames()" class="text-xs text-orca-teal hover:underline mt-2">
                    <i class="fas fa-expand mr-1"></i>{{ __('Fit to largest frame') }}
                </button>
            </div>
        </div>

        {{-- Row 3: Background --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            {{-- Background color --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Background') }}</label>
                <div class="flex items-center gap-2">
                    <input type="color" x-model="bgColor"
                        class="w-8 h-8 rounded border border-gray-300 cursor-pointer p-0.5">
                    <input type="text" x-model="bgColor" maxlength="7"
                        class="w-24 text-sm font-mono border border-gray-300 rounded-md px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent">
                </div>
                <p class="text-xs text-gray-400 mt-1">{{ __('Visible behind non-filling frames') }}</p>
            </div>
        </div>
    </div>

    {{-- Generate & Preview card --}}
    <div x-show="frames.length > 0" class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-base font-semibold text-gray-900 mb-4">
            <i class="fas fa-wand-magic-sparkles text-gray-400 mr-1"></i>
            {{ __('Generate') }}
        </h2>

        <div class="flex items-center gap-4 mb-4">
            <button @click="generateGif()"
                :disabled="generating || !allFramesLoaded"
                class="inline-flex items-center gap-2 px-4 py-2 bg-orca-teal text-white text-sm font-medium rounded-md hover:bg-orca-teal-hover disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                <template x-if="generating">
                    <i class="fas fa-spinner fa-spin"></i>
                </template>
                <template x-if="!generating">
                    <i class="fas fa-wand-magic-sparkles"></i>
                </template>
                <span x-text="generating ? '{{ __('Generating…') }}' : '{{ __('Generate GIF') }}'"></span>
            </button>

            <span x-show="frames.length < 2" class="text-xs text-gray-400">
                <i class="fas fa-info-circle mr-1"></i>{{ __('At least 2 frames required') }}
            </span>
        </div>

        {{-- Progress bar --}}
        <div x-show="generating" class="mb-4">
            <div class="w-full bg-gray-200 rounded-full h-2">
                <div class="bg-orca-teal h-2 rounded-full transition-all duration-300" :style="'width: ' + generateProgress + '%'"></div>
            </div>
            <p class="text-xs text-gray-500 mt-1" x-text="generateProgress + '%'"></p>
        </div>

        {{-- Preview --}}
        <div x-show="generatedGif" class="border border-gray-200 rounded-lg overflow-hidden">
            <div class="flex items-center justify-center bg-gray-50 p-4" style="min-height: 120px;">
                <img x-show="generatedGif" :src="generatedGif?.objectUrl" :alt="uploadFilename" class="max-w-full h-auto" style="max-height: 400px;">
            </div>
            <div class="flex items-center justify-between px-4 py-2 border-t border-gray-100 bg-gray-50">
                <span class="text-xs text-gray-500">
                    <span x-text="generatedGif?.width + ' × ' + generatedGif?.height + ' px'"></span>
                    <span class="text-gray-300 mx-1">&middot;</span>
                    <span x-text="formatFileSize(generatedGif?.size || 0)"></span>
                </span>
                <button @click="downloadGif()"
                    class="inline-flex items-center gap-1.5 text-sm text-orca-teal hover:text-orca-teal-hover font-medium">
                    <i class="fas fa-download"></i> {{ __('Download') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Upload to ORCA --}}
    <div x-show="generatedGif" class="bg-white rounded-lg shadow p-6">
        <h3 class="text-sm font-semibold text-gray-700 mb-3">
            <i class="fas fa-cloud-arrow-up mr-2 text-gray-400"></i>{{ __('Upload to ORCA') }}
        </h3>

        <div class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Folder') }}</label>
                <select x-model="uploadFolder"
                    class="w-full pr-dropdown rounded-lg border-gray-300 shadow-sm focus:border-orca-teal focus:ring-orca-teal font-mono text-sm">
                    <x-folder-tree-options :folders="$folders" :root-folder="$rootFolder" />
                </select>
            </div>

            <div class="min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Filename') }}</label>
                <input type="text" x-model="uploadFilename"
                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-orca-teal focus:ring-orca-teal text-sm"
                    placeholder="animation.gif">
            </div>

            <button @click="uploadToOrca()"
                :disabled="uploading || !generatedGif"
                class="inline-flex items-center gap-2 px-4 py-2 bg-orca-teal text-white text-sm font-medium rounded-md hover:bg-orca-teal-hover disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                <template x-if="uploading">
                    <i class="fas fa-spinner fa-spin"></i>
                </template>
                <template x-if="!uploading">
                    <i class="fas fa-cloud-arrow-up"></i>
                </template>
                <span x-text="uploading ? '{{ __('Uploading…') }}' : '{{ __('Upload to ORCA') }}'"></span>
            </button>
        </div>

        {{-- Upload success --}}
        <div x-show="uploadedAsset" class="mt-3 text-sm text-green-700 bg-green-50 rounded-lg px-4 py-2 flex items-center gap-2">
            <i class="fas fa-check-circle"></i>
            <span>{{ __('Uploaded!') }}</span>
            <a :href="uploadedAsset?.asset_url" class="underline hover:text-green-900 font-medium" x-text="uploadedAsset?.filename"></a>
        </div>
    </div>

</div>

<script>
window.__pageData = {
    folders: @json($folders),
    rootFolder: @json($rootFolder),
    uploadUrl: '{{ route('tools.gif-maker.upload') }}',
    csrfToken: '{{ csrf_token() }}',
};
</script>
@endsection
