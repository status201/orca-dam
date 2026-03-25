@extends('layouts.app')

@section('title', __('TikZ to PNG'))

@section('content')
<div class="max-w-7xl mx-auto sm:px-6 lg:px-8" x-data="tikzPng()">

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
            <span class="text-gray-700 font-medium">{{ __('TikZ to PNG') }}</span>
        </div>
    </div>

    {{-- Input card --}}
    <div class="bg-white rounded-lg shadow p-6 mb-6 flex flex-col gap-4">
        <h2 class="text-base font-semibold text-gray-900">
            <i class="fas fa-image text-orca-teal mr-1"></i>
            {{ __('TikZ Input') }}
            <span class="inline-flex items-center ml-2 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">{{ __('Beta') }}</span>
        </h2>

        {{-- Examples --}}
        <div class="flex flex-wrap gap-2">
            <span class="text-xs text-gray-500 self-center">{{ __('Examples:') }}</span>
            <template x-for="ex in examples" :key="ex.label">
                <button @click="loadExample(ex.code)"
                    class="text-xs px-2.5 py-1 rounded-full border border-gray-200 text-gray-600 hover:bg-orca-teal hover:text-white hover:border-orca-teal transition-colors"
                    x-text="ex.label">
                </button>
            </template>
        </div>

        <p class="text-xs text-gray-500">
            {{ __('All') }} <code class="font-mono bg-gray-100 px-1 rounded">\begin{tikzpicture}...\end{tikzpicture}</code>
            {{ __('blocks will be extracted and rendered separately.') }}
        </p>

        {{-- Textarea --}}
        <textarea
            x-model="tikzCode"
            rows="12"
            class="w-full invert-scrollbar-colors font-mono text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent resize-y"
            placeholder="{{ __('Paste TikZ code or a full LaTeX document here…') }}">
        </textarea>

        {{-- Render button + options --}}
        <div class="flex items-center gap-4 flex-wrap">
            <button
                @click="render()"
                :disabled="rendering || !tikzCode.trim()"
                class="inline-flex items-center gap-2 px-4 py-2 bg-orca-teal text-white text-sm font-medium rounded-md hover:bg-orca-teal-hover disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                <template x-if="rendering">
                    <i class="fas fa-spinner fa-spin"></i>
                </template>
                <template x-if="!rendering">
                    <i class="fas fa-image"></i>
                </template>
                <span x-text="rendering ? '{{ __('Rendering…') }}' : '{{ __('Render PNG') }}'"></span>
            </button>

            <button @click="clearCode()" class="text-sm text-gray-500 hover:text-gray-700">
                <i class="fas fa-eraser mr-1"></i> {{ __('Clear') }}
            </button>

            <div class="flex items-center gap-2 ml-auto">
                <label class="text-xs text-gray-500">{{ __('Font') }}:</label>
                <select x-model="fontFamily"
                    class="text-xs border border-gray-200 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-orca-teal">
                    <option value="default">{{ __('Default (Serif)') }}</option>
                    <option value="sans">{{ __('Sans Serif') }}</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <label class="text-xs text-gray-500">{{ __('Edge padding (pt)') }}</label>
                <input type="number" x-model.number="viewBoxPadding" min="0" max="50" step="1"
                    class="w-16 text-xs border border-gray-200 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-orca-teal">
            </div>

            <div class="flex items-center gap-2">
                <label class="text-xs text-gray-500">{{ __('Width') }}:</label>
                <input type="number" x-model.number="pngWidth" min="200" max="4000" step="100"
                    class="w-24 text-xs border border-gray-200 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-orca-teal">
                <span class="text-xs text-gray-400">px</span>
            </div>

            <div class="flex items-center gap-1">
                <label class="text-xs text-gray-500 mr-1">{{ __('Density') }}:</label>
                <template x-for="d in [1, 2, 3]" :key="d">
                    <button
                        @click="pixelDensity = d"
                        :class="pixelDensity === d ? 'bg-orca-teal text-white border-orca-teal' : 'bg-white text-gray-600 border-gray-200 hover:border-gray-400'"
                        class="text-xs px-2 py-1 rounded border transition-colors"
                        x-text="d + 'x'">
                    </button>
                </template>
            </div>
        </div>

        {{-- Render status --}}
        <div x-show="rendering" class="text-sm text-gray-500">
            <i class="fas fa-spinner fa-spin mr-1"></i>
            {{ __('TikZJax is processing your diagrams. This may take a moment on first load.') }}
            <span class="text-xs text-gray-400" x-show="snippetCount > 0" x-text="'(' + snippetCount + ' snippet' + (snippetCount > 1 ? 's' : '') + ')'"></span>
        </div>

        {{-- Error display --}}
        <div x-show="renderError" class="text-sm text-red-600 bg-red-50 rounded-lg p-3">
            <i class="fas fa-circle-exclamation mr-1"></i>
            <span x-text="renderError"></span>
        </div>
    </div>

    {{-- Hidden iframe for TikZJax rendering (NO sandbox — fork needs Workers + IndexedDB) --}}
    <iframe id="tikz-png-iframe" style="display:none"></iframe>

    {{-- Results section --}}
    <div x-show="results.length > 0" class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-semibold text-gray-900">
                <i class="fas fa-images text-gray-400 mr-1"></i>
                {{ __('Results') }}
                <span class="text-sm font-normal text-gray-500" x-text="'(' + results.length + ' diagram' + (results.length > 1 ? 's' : '') + ')'"></span>
            </h3>
            <div class="flex gap-2">
                <button @click="selectAll()" class="text-xs text-orca-teal hover:underline">{{ __('Select all') }}</button>
                <span class="text-gray-300">|</span>
                <button @click="deselectAll()" class="text-xs text-gray-500 hover:underline">{{ __('Deselect all') }}</button>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            <template x-for="(result, idx) in results" :key="idx">
                <div class="border rounded-lg overflow-hidden"
                    :class="result.uploaded ? 'border-green-200 bg-green-50/30' : 'border-gray-200'">

                    {{-- Filename + checkbox --}}
                    <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 border-b border-gray-100">
                        <input type="checkbox" x-model="result.selected"
                            :disabled="!!result.uploaded"
                            class="rounded border-gray-300 text-orca-teal focus:ring-orca-teal">
                        <input type="text" x-model="result.name"
                            :disabled="!!result.uploaded"
                            class="flex-1 min-w-0 font-mono text-xs border border-gray-200 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-orca-teal focus:border-orca-teal disabled:bg-gray-50 disabled:text-gray-400"
                            placeholder="diagram-1.png">
                    </div>

                    {{-- PNG preview --}}
                    <div class="flex items-center justify-center min-h-[120px] bg-gray-50 rounded border border-gray-100 p-2 overflow-hidden">
                        <img :src="result.png" :alt="result.name" class="max-w-full h-auto" style="max-height: 300px;">
                    </div>

                    {{-- Dimensions --}}
                    <div class="px-3 py-1 text-xs text-gray-400 border-t border-gray-100">
                        <span x-text="result.width + ' × ' + result.height + ' px'"></span>
                        <span class="text-gray-300 mx-1">·</span>
                        <span x-text="result.logicalW + ' × ' + result.logicalH + ' logical'"></span>
                    </div>

                    {{-- Upload status --}}
                    <template x-if="result.uploaded">
                        <div class="px-3 py-2 text-xs text-green-700 bg-green-50 border-t border-green-100 flex items-center gap-1">
                            <i class="fas fa-check-circle"></i>
                            <a :href="result.uploaded.asset_url" class="underline hover:text-green-900" x-text="result.uploaded.filename"></a>
                        </div>
                    </template>
                    <template x-if="result.uploading">
                        <div class="px-3 py-2 text-xs text-gray-500 border-t border-gray-100">
                            <i class="fas fa-spinner fa-spin mr-1"></i> {{ __('Uploading…') }}
                        </div>
                    </template>
                </div>
            </template>
        </div>

        {{-- Upload to ORCA --}}
        <div class="border-t border-gray-100 pt-5">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">
                <i class="fas fa-cloud-arrow-up mr-2 text-gray-400"></i>{{ __('Upload to ORCA') }}
            </h3>
            <div class="flex flex-wrap gap-4 items-end">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Folder') }}</label>
                    <select
                        x-model="uploadFolder"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-orca-teal focus:ring-orca-teal font-mono text-sm">
                        <x-folder-tree-options :folders="$folders" :root-folder="$rootFolder" />
                    </select>
                </div>

                <button
                    @click="uploadSelected()"
                    :disabled="anyUploading || selectedCount === 0"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-orca-teal text-white text-sm font-medium rounded-md hover:bg-orca-teal-hover disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                    <template x-if="anyUploading">
                        <i class="fas fa-spinner fa-spin"></i>
                    </template>
                    <template x-if="!anyUploading">
                        <i class="fas fa-cloud-arrow-up"></i>
                    </template>
                    <span x-text="anyUploading ? '{{ __('Uploading…') }}' : ('{{ __('Upload selected') }} (' + selectedCount + ')')"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- TikZJax Technical Notes --}}
    <div class="mt-8 bg-amber-50 border border-amber-200 rounded-lg overflow-hidden">
        <button
            onclick="this.nextElementSibling.classList.toggle('hidden'); this.querySelector('.fa-chevron-right').classList.toggle('rotate-90')"
            class="w-full flex items-center gap-3 px-5 py-3.5 text-left hover:bg-amber-100/50 transition-colors">
            <i class="fas fa-flask text-amber-600"></i>
            <span class="font-semibold text-amber-900 text-sm">{{ __('TikZJax Technical Notes & Known Limitations') }}</span>
            <i class="fas fa-chevron-right text-amber-400 text-xs ml-auto transition-transform duration-200"></i>
        </button>
        <div class="hidden border-t border-amber-200 px-5 py-4 text-sm text-amber-900 space-y-4">
            <p>
                {{ __('This tool uses') }}
                <a href="https://github.com/drgrice1/tikzjax" target="_blank" rel="noopener" class="font-medium text-amber-700 underline decoration-amber-300 hover:text-amber-900">@drgrice1/tikzjax</a>,
                {{ __('a maintained fork of') }}
                <a href="https://github.com/kisonecat/tikzjax" target="_blank" rel="noopener" class="font-medium text-amber-700 underline decoration-amber-300 hover:text-amber-900">TikZJax</a>
                {{ __('that adds AMS font/symbol support, Web Worker compilation, and IndexedDB caching.') }}
            </p>

            <div>
                <h4 class="font-semibold text-amber-800 mb-1"><i class="attention fas fa-image mr-1.5 text-amber-500"></i>{{ __('Why PNG instead of SVG?') }}</h4>
                <p class="text-amber-800">
                    {{ __('The fork\'s') }}
                    <a href="https://github.com/drgrice1/dvi2html" target="_blank" rel="noopener" class="font-medium text-amber-700 underline decoration-amber-300 hover:text-amber-900">dvi2html</a>
                    {{ __('library outputs non-standard character codes in SVG text elements. These SVGs only render correctly with the exact matching font loaded — they show squares (□) when viewed as standalone files or embedded via') }}
                    <code class="bg-amber-100 px-1.5 py-0.5 rounded text-xs font-mono">&lt;img&gt;</code>
                    {{ __('tags. By rasterizing to PNG inside the browser (where the fonts are loaded), the output is a portable bitmap that displays correctly everywhere.') }}
                </p>
            </div>

            <div>
                <h4 class="font-semibold text-amber-800 mb-1"><i class="attention fas fa-triangle-exclamation mr-1.5 text-amber-500"></i>{{ __('Console warnings') }}</h4>
                <p class="text-amber-800">
                    {{ __('You may see a') }}
                    <code class="bg-amber-100 px-1.5 py-0.5 rounded text-xs font-mono">Permissions policy violation: unload</code>
                    {{ __('warning in Chromium browsers. This is cosmetic — it comes from the fork\'s cleanup code and does not affect rendering. The diagrams render correctly despite this warning.') }}
                </p>
            </div>

            <div>
                <h4 class="font-semibold text-amber-800 mb-1"><i class="attention fas fa-gears mr-1.5 text-amber-500"></i>{{ __('How it works') }}</h4>
                <p class="text-amber-800">
                    {{ __('TikZ code is compiled to SVG via a WebAssembly TeX engine running in a hidden iframe. The fork\'s web fonts are loaded in the iframe for correct rendering. Each SVG is then drawn onto an HTML canvas with embedded base64 font data, and exported as a PNG bitmap at the configured width and pixel density.') }}
                </p>
            </div>

            <div class="pt-2 border-t border-amber-200 text-xs text-amber-700 flex flex-wrap gap-x-4 gap-y-1">
                <a href="https://github.com/drgrice1/tikzjax" target="_blank" rel="noopener" class="inline-flex items-center gap-1 hover:text-amber-900"><i class="fab fa-github"></i> drgrice1/tikzjax <span class="text-amber-500">({{ __('fork — used here') }})</span></a>
                <a href="https://github.com/kisonecat/tikzjax" target="_blank" rel="noopener" class="inline-flex items-center gap-1 hover:text-amber-900"><i class="fab fa-github"></i> kisonecat/tikzjax <span class="text-amber-500">({{ __('original') }})</span></a>
                <a href="https://github.com/drgrice1/dvi2html" target="_blank" rel="noopener" class="inline-flex items-center gap-1 hover:text-amber-900"><i class="fab fa-github"></i> dvi2html</a>
                <a href="https://tikzjax.com" target="_blank" rel="noopener" class="inline-flex items-center gap-1 hover:text-amber-900"><i class="fas fa-globe"></i> tikzjax.com</a>
            </div>
        </div>
    </div>

</div>

<script>
window.__pageData = {
    folders: @json($folders),
    rootFolder: @json($rootFolder),
    uploadUrl: '{{ route('tools.tikz-png.upload') }}',
    csrfToken: '{{ csrf_token() }}',
};
</script>
@endsection
