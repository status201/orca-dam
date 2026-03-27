@extends('layouts.app')

@section('title', __('TikZ Server Render'))

@section('content')
<div class="max-w-7xl mx-auto sm:px-6 lg:px-8" x-data="tikzServer()">

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
            <span class="text-gray-700">{{ __('TikZ Server Render') }}</span>
        </div>
    </div>

    <div class="mb-6">
        <div class="flex items-center gap-3">
            <h1 class="text-3xl font-bold">{{ __('TikZ Server Render') }}</h1>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                {{ __('Server') }}
            </span>
        </div>
        <p class="text-gray-600 mt-1">{{ __('Compile TikZ diagrams on the server with full TeX Live support. Compare SVG and PNG output variants.') }}</p>
    </div>

    {{-- Availability warning --}}
    @unless($compilerAvailable)
    <div class="mb-6 flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800">
        <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5"></i>
        <div>
            <p class="font-semibold mb-1">{{ __('TeX Live is not installed') }}</p>
            <p>{{ __('This tool requires latex and dvisvgm to be available on the server. Install TeX Live to enable server-side rendering.') }}</p>
        </div>
    </div>
    @endunless

    {{-- Input card --}}
    <div class="bg-white rounded-lg shadow p-6 mb-6 flex flex-col gap-4">
        <h2 class="text-base font-semibold text-gray-900">
            <i class="fas fa-pen mr-2 text-gray-400"></i>{{ __('TikZ Input') }}
        </h2>

        {{-- Examples + Load template --}}
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ __('Examples') }}</p>
                <div class="flex flex-wrap gap-2">
                    <template x-for="ex in examples" :key="ex.label">
                        <button
                            @click="loadExample(ex.code)"
                            class="px-2 py-1 text-xs rounded border border-gray-200 text-gray-600 hover:border-orca-teal hover:text-orca-teal transition-colors"
                            x-text="ex.label">
                        </button>
                    </template>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0 relative">
                <input type="file" accept=".tex,.txt" class="hidden" x-ref="templateInput" @change="loadTemplateFile($event)">
                <button
                    @click="$refs.templateInput.click()"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded border border-gray-300 text-gray-600 hover:border-orca-teal hover:text-orca-teal transition-colors">
                    <i class="fas fa-file-import"></i>
                    {{ __('Load .tex file') }}
                </button>
                <button
                    @click="openTemplateBrowser()"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded border border-gray-300 text-gray-600 hover:border-orca-teal hover:text-orca-teal transition-colors">
                    <i class="fas fa-database"></i>
                    {{ __('Load from ORCA') }}
                </button>
                <template x-if="templateName">
                    <span class="text-xs text-gray-400 font-mono" x-text="templateName"></span>
                </template>

                {{-- Template browser panel --}}
                <div x-show="templateBrowserOpen" x-transition @click.outside="closeTemplateBrowser()"
                    class="absolute right-0 top-full mt-2 w-96 lg:w-[32rem] bg-white rounded-lg shadow-lg border border-gray-200 z-50">
                    <div class="p-3 border-b border-gray-100">
                        <div class="flex items-center gap-2">
                            <input
                                type="text"
                                x-model="templateSearchQuery"
                                @input.debounce.300ms="searchTemplates()"
                                placeholder="{{ __('Search templates…') }}"
                                class="flex-1 text-sm border border-gray-300 rounded-md px-2 py-1.5 focus:outline-none focus:ring-1 focus:ring-orca-teal focus:border-orca-teal"
                                x-ref="templateSearchInput"
                                @keydown.escape="closeTemplateBrowser()">
                            <button @click="closeTemplateBrowser()" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="max-h-64 overflow-y-auto invert-scrollbar-colors">
                        <template x-if="templateSearchLoading">
                            <div class="px-3 py-4 text-center text-sm text-gray-400">
                                <i class="fas fa-spinner fa-spin mr-1"></i> {{ __('Searching…') }}
                            </div>
                        </template>
                        <template x-if="!templateSearchLoading && templateSearchResults.length === 0">
                            <div class="px-3 py-4 text-center text-sm text-gray-400">
                                {{ __('No templates found') }}
                            </div>
                        </template>
                        <template x-for="tpl in templateSearchResults" :key="tpl.id">
                            <button
                                @click="loadFromOrca(tpl.id)"
                                :disabled="templateLoadingId === tpl.id"
                                class="w-full text-left px-3 py-2 hover:bg-gray-50 border-b border-gray-50 transition-colors disabled:opacity-50">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium text-gray-800 truncate" x-text="tpl.filename" :title="tpl.filename"></div>
                                        <div class="text-xs text-gray-400 truncate" x-text="tpl.folder" :title="tpl.folder"></div>
                                    </div>
                                    <div class="text-xs text-gray-400 whitespace-nowrap shrink-0">
                                        <span x-text="tpl.formatted_size"></span>
                                        <span class="mx-1">&middot;</span>
                                        <span x-text="tpl.updated_at"></span>
                                    </div>
                                </div>
                                <template x-if="templateLoadingId === tpl.id">
                                    <i class="fas fa-spinner fa-spin text-xs text-orca-teal mt-1"></i>
                                </template>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <p class="text-xs text-gray-500">
            <i class="fas fa-info-circle mr-1"></i>
            {{ __('All') }} <code class="font-mono bg-gray-100 px-1 rounded">\begin{tikzpicture}...\end{tikzpicture}</code>
            {{ __('blocks will be extracted and rendered separately.') }}
            {{ __('You can also paste a full LaTeX document — the preamble (colors, packages, commands) will be applied to each diagram.') }}
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
                :disabled="rendering || !tikzCode.trim() || !compilerAvailable"
                class="inline-flex items-center gap-2 px-5 py-2 bg-orca-teal text-white text-sm font-medium rounded-md hover:bg-orca-teal-hover disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                <template x-if="rendering">
                    <i class="fas fa-spinner fa-spin"></i>
                </template>
                <template x-if="!rendering">
                    <i class="fas fa-server"></i>
                </template>
                <span x-text="rendering ? '{{ __('Compiling…') }}' : '{{ __('Render') }}'"></span>
            </button>
            <button
                @click="clearCode()"
                :disabled="!tikzCode.trim() && results.length === 0"
                class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-600 text-sm font-medium rounded-md hover:border-gray-400 hover:text-gray-800 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                <i class="fas fa-times"></i>
                {{ __('Clear') }}
            </button>
            <button
                @click="saveToOrca()"
                :disabled="!tikzCode.trim() || savingTemplate"
                class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-600 text-sm font-medium rounded-md hover:border-orca-teal hover:text-orca-teal disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                <i class="fas fa-floppy-disk" :class="savingTemplate && 'fa-spin'"></i>
                {{ __('Save as .tex') }}
            </button>
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-600 whitespace-nowrap">{{ __('Edge padding (pt)') }}</label>
                <input
                    type="number"
                    x-model.number="borderPt"
                    min="0"
                    max="50"
                    step="1"
                    class="w-16 text-sm border border-gray-300 rounded-md px-2 py-2 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent">
            </div>
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-600 whitespace-nowrap">{{ __('Font') }}:</label>
                <select
                    x-model="fontPackage"
                    class="text-sm border border-gray-300 rounded-md px-2 py-2 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent">
                    @foreach($fontPackages as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-600 whitespace-nowrap">{{ __('PNG DPI') }}:</label>
                <input
                    type="number"
                    x-model.number="pngDpi"
                    min="72"
                    max="600"
                    step="1"
                    class="w-20 text-sm border border-gray-300 rounded-md px-2 py-2 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent">
            </div>
            <template x-if="rendering">
                <span class="text-sm text-gray-500">
                    <i class="fas fa-spinner fa-spin mr-1"></i>
                    {{ __('Compiling on server. This may take a few seconds…') }}
                </span>
            </template>
        </div>

        {{-- Render error --}}
        <template x-if="renderError">
            <div class="flex items-start gap-2 p-3 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">
                <i class="fas fa-exclamation-circle mt-0.5"></i>
                <div>
                    <span x-text="renderError"></span>
                    <template x-if="renderLog && !showLog">
                        <button @click="showLog = true" class="ml-2 underline text-red-600 hover:text-red-800">{{ __('Show compilation log') }}</button>
                    </template>
                </div>
            </div>
        </template>
    </div>

    {{-- Compilation log --}}
    <template x-if="renderLog">
        <div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
            <button
                @click="showLog = !showLog"
                class="w-full flex items-center gap-3 px-5 py-3 text-left hover:bg-gray-50 transition-colors">
                <i class="fas fa-terminal text-gray-400"></i>
                <span class="font-medium text-sm text-gray-700">{{ __('Compilation log') }}</span>
                <i class="fas fa-chevron-right text-gray-400 text-xs ml-auto transition-transform duration-200" :class="showLog && 'rotate-90'"></i>
            </button>
            <div x-show="showLog" x-collapse class="border-t border-gray-100">
                <pre class="px-5 py-4 text-xs font-mono text-gray-600 max-h-64 overflow-auto invert-scrollbar-colors whitespace-pre-wrap" x-text="renderLog"></pre>
            </div>
        </div>
    </template>

    {{-- Results section --}}
    <div x-show="results.length > 0" class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-gray-900">
                <i class="fas fa-images mr-2 text-gray-400"></i>
                {{ __('Results') }}
                (<span x-text="results.length"></span> <span x-text="results.length === 1 ? '{{ __('diagram') }}' : '{{ __('diagrams') }}'"></span>)
            </h2>
            <div class="flex items-center gap-3 text-sm">
                <button @click="selectAll()" class="text-orca-teal hover:text-orca-teal-hover transition-colors">{{ __('Select all') }}</button>
                <span class="text-gray-300">|</span>
                <button @click="deselectAll()" class="text-orca-teal hover:text-orca-teal-hover transition-colors">{{ __('Deselect all') }}</button>
            </div>
        </div>

        {{-- Result cards --}}
        <div class="space-y-6 mb-6">
            <template x-for="(result, rIdx) in results" :key="rIdx">
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    {{-- Snippet header --}}
                    <div class="bg-gray-50 px-4 py-2 border-b border-gray-100">
                        <span class="text-sm font-medium text-gray-700" x-text="'{{ __('Diagram') }} ' + (rIdx + 1)"></span>
                    </div>

                    {{-- Variant tabs --}}
                    <div class="flex border-b border-gray-200 overflow-x-auto">
                        <template x-for="type in variantTypes(result)" :key="type">
                            <button
                                @click="result.activeTab = type"
                                :class="result.activeTab === type
                                    ? 'border-b-2 border-orca-teal text-orca-teal bg-white'
                                    : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50'"
                                class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium whitespace-nowrap transition-colors">
                                <span x-text="variantLabel(type)"></span>
                                <span class="text-xs font-normal px-1.5 py-0.5 rounded-full"
                                    :class="result.activeTab === type ? 'bg-orca-teal/10 text-orca-teal' : 'bg-gray-100 text-gray-500'"
                                    x-text="formatSize(result.variants[type].size)"></span>
                            </button>
                        </template>
                    </div>

                    {{-- Active variant content --}}
                    <div class="p-4">
                        {{-- Preview --}}
                        <div class="flex items-center justify-center min-h-[150px] bg-gray-50 rounded border border-gray-100 p-3 mb-4 overflow-hidden">
                            <div x-html="previewHtml(result)" class="max-w-full [&>svg]:max-w-full [&>svg]:h-auto [&>img]:max-w-full [&>img]:h-auto"></div>
                        </div>

                        {{-- Per-variant upload controls --}}
                        <template x-for="type in variantTypes(result)" :key="'ctrl-' + type">
                            <div x-show="result.activeTab === type" class="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    x-model="result.variants[type].selected"
                                    :disabled="!!result.variants[type].uploaded"
                                    class="rounded border-gray-300 text-orca-teal focus:ring-orca-teal">
                                <span class="text-xs text-gray-500">{{ __('Upload') }}</span>
                                <input
                                    type="text"
                                    x-model="result.variants[type].name"
                                    :disabled="!!result.variants[type].uploaded"
                                    class="flex-1 min-w-0 font-mono text-xs border border-gray-200 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-orca-teal focus:border-orca-teal disabled:bg-gray-50 disabled:text-gray-400">

                                {{-- Dimensions for PNG --}}
                                <template x-if="type === 'png' && result.variants[type].width">
                                    <span class="text-xs text-gray-400" x-text="result.variants[type].width + ' × ' + result.variants[type].height + ' px'"></span>
                                </template>

                                {{-- Upload status --}}
                                <template x-if="result.variants[type].uploaded">
                                    <a :href="result.variants[type].uploaded.asset_url" target="_blank"
                                        class="inline-flex items-center gap-1 text-xs text-green-700 hover:text-green-900">
                                        <i class="fas fa-check-circle text-green-600"></i>
                                        <span x-text="result.variants[type].uploaded.filename" class="underline"></span>
                                    </a>
                                </template>
                                <template x-if="result.variants[type].uploading">
                                    <span class="text-xs text-gray-500"><i class="fas fa-spinner fa-spin mr-1"></i>{{ __('Uploading…') }}</span>
                                </template>
                            </div>
                        </template>
                    </div>
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

    {{-- Technical notes --}}
    <div class="mt-8 bg-green-50 border border-green-200 rounded-lg overflow-hidden">
        <button
            onclick="this.nextElementSibling.classList.toggle('hidden'); this.querySelector('.fa-chevron-right').classList.toggle('rotate-90')"
            class="w-full flex items-center gap-3 px-5 py-3.5 text-left hover:bg-green-100/50 transition-colors">
            <i class="fas fa-server text-green-600"></i>
            <span class="font-semibold text-green-900 text-sm">{{ __('Server-Side Rendering — Technical Notes') }}</span>
            <i class="fas fa-chevron-right text-green-400 text-xs ml-auto transition-transform duration-200"></i>
        </button>
        <div class="hidden border-t border-green-200 px-5 py-4 text-sm text-green-900 space-y-4">
            <p>
                {{ __('This tool compiles TikZ code on the server using a real TeX Live installation, producing four output variants for comparison.') }}
            </p>

            <div>
                <h4 class="font-semibold text-green-800 mb-1"><i class="fas fa-file-code mr-1.5 text-green-500"></i>{{ __('Output variants') }}</h4>
                <ul class="list-disc list-inside text-green-800 space-y-1 ml-1">
                    <li><strong>SVG</strong> — {{ __('Default dvisvgm output with font data in SVG defs. Smallest SVG, but requires fonts on the viewing system.') }}</li>
                    <li><strong>SVG ({{ __('embedded fonts') }})</strong> — {{ __('WOFF2 font data embedded as base64 data URIs. Self-contained but larger file size.') }}</li>
                    <li><strong>SVG ({{ __('text as paths') }})</strong> — {{ __('All text converted to vector path outlines. No font dependencies, ideal for portable use.') }}</li>
                    <li><strong>PNG</strong> — {{ __('Raster output converted from SVG at configurable DPI (via rsvg-convert or inkscape). Universal compatibility.') }}</li>
                </ul>
            </div>

            <div>
                <h4 class="font-semibold text-green-800 mb-1"><i class="fas fa-shield-halved mr-1.5 text-green-500"></i>{{ __('Security') }}</h4>
                <p class="text-green-800">
                    {{ __('LaTeX is compiled with --no-shell-escape and paranoid file I/O mode. Dangerous commands (\\write18, \\openin, etc.) are rejected before compilation. Each render runs in an isolated temporary directory with a configurable timeout.') }}
                </p>
            </div>

            <div>
                <h4 class="font-semibold text-green-800 mb-1"><i class="fas fa-download mr-1.5 text-green-500"></i>{{ __('Requirements') }}</h4>
                <p class="text-green-800">
                    {{ __('Requires TeX Live (or similar TeX distribution) with latex and dvisvgm installed on the server. For PNG output, rsvg-convert (librsvg2-bin) or inkscape is needed.') }}
                </p>
            </div>
        </div>
    </div>

</div>

<script>
window.__pageData = {
    folders: @json($folders),
    rootFolder: @json($rootFolder),
    renderUrl: '{{ route('tools.tikz-server.render') }}',
    svgUploadUrl: '{{ route('tools.tikz-svg.upload') }}',
    pngUploadUrl: '{{ route('tools.tikz-png.upload') }}',
    templateSearchUrl: '{{ route('tools.tikz-server.templates') }}',
    templateLoadUrl: '{{ url('tools/tikz-server/templates') }}',
    templateUploadUrl: '{{ route('tools.tikz-server.templates.upload') }}',
    saveTemplatePrompt: '{{ __('Template name') }}:',
    csrfToken: '{{ csrf_token() }}',
    compilerAvailable: @json($compilerAvailable),
};
</script>
@endsection
