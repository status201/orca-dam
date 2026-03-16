@extends('layouts.app')

@section('title', __('TikZ to SVG'))

@section('content')
<div class="max-w-7xl mx-auto sm:px-6 lg:px-8" x-data="tikzSvg()">

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
            <span class="text-gray-700">{{ __('TikZ to SVG') }}</span>
        </div>
    </div>

    <div class="mb-6">
        <div class="flex items-center gap-3">
            <h1 class="text-3xl font-bold">{{ __('TikZ to SVG') }}</h1>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                {{ __('Beta') }}
            </span>
        </div>
        <p class="text-gray-600 mt-1">{{ __('Render TikZ diagrams to SVG and upload them directly to ORCA.') }}</p>
    </div>

    {{-- Input card --}}
    <div class="bg-white rounded-lg shadow p-6 mb-6 flex flex-col gap-4">
        <h2 class="text-base font-semibold text-gray-900">
            <i class="fas fa-pen mr-2 text-gray-400"></i>{{ __('TikZ Input') }}
        </h2>

        {{-- Examples --}}
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

        <p class="text-xs text-gray-500">
            <i class="fas fa-info-circle mr-1"></i>
            {{ __('All') }} <code class="font-mono bg-gray-100 px-1 rounded">\begin{tikzpicture}...\end{tikzpicture}</code>
            {{ __('blocks will be extracted and rendered separately.') }}
        </p>

        {{-- Textarea --}}
        <textarea
            x-model="tikzCode"
            rows="12"
            class="w-full font-mono text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent resize-y"
            placeholder="{{ __('Paste TikZ code or a full LaTeX document here…') }}">
        </textarea>

        {{-- Render button --}}
        <div class="flex items-center gap-4">
            <button
                @click="render()"
                :disabled="rendering || !tikzCode.trim()"
                class="inline-flex items-center gap-2 px-5 py-2 bg-orca-teal text-white text-sm font-medium rounded-md hover:bg-orca-teal-hover disabled:opacity-50 disabled:cursor-not-allowed transition-colors">

                <template x-if="rendering">
                    <i class="fas fa-spinner fa-spin"></i>
                </template>
                <template x-if="!rendering">
                    <i class="fas fa-bezier-curve"></i>
                </template>
                <span x-text="rendering ? '{{ __('Rendering…') }}' : '{{ __('Render') }}'"></span>
            </button>
            <button
                @click="clearCode()"
                :disabled="!tikzCode.trim() && results.length === 0"
                class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-600 text-sm font-medium rounded-md hover:border-gray-400 hover:text-gray-800 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                <i class="fas fa-times"></i>
                {{ __('Clear') }}
            </button>
            <div class="flex items-center gap-2">
                <label class="text-sm text-gray-600 whitespace-nowrap">{{ __('Edge padding (pt)') }}</label>
                <input
                    type="number"
                    x-model.number="viewBoxPadding"
                    min="0"
                    max="50"
                    step="1"
                    class="w-16 text-sm border border-gray-300 rounded-md px-2 py-2 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent">
            </div>
            <template x-if="rendering">
                <span class="text-sm text-gray-500">{{ __('TikZJax is processing your diagrams. This may take a moment on first load.') }}</span>
            </template>
        </div>

        {{-- Render error --}}
        <template x-if="renderError">
            <div class="flex items-center gap-2 p-3 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">
                <i class="fas fa-exclamation-circle"></i>
                <span x-text="renderError"></span>
            </div>
        </template>
    </div>

    {{-- Hidden iframe for TikZJax rendering --}}
    <iframe id="tikz-iframe" style="display:none" sandbox="allow-scripts"></iframe>

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

        {{-- Result cards grid --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
            <template x-for="(result, index) in results" :key="index">
                <div
                    :class="result.selected ? 'border-orca-teal ring-1 ring-orca-teal' : 'border-gray-200'"
                    class="border rounded-lg p-4 flex flex-col gap-3 transition-all">

                    {{-- Checkbox + name --}}
                    <div class="flex items-center gap-2">
                        <input
                            type="checkbox"
                            x-model="result.selected"
                            :disabled="!!result.uploaded"
                            class="rounded border-gray-300 text-orca-teal focus:ring-orca-teal">
                        <input
                            type="text"
                            x-model="result.name"
                            :disabled="!!result.uploaded"
                            class="flex-1 min-w-0 font-mono text-xs border border-gray-200 rounded px-2 py-1 focus:outline-none focus:ring-1 focus:ring-orca-teal focus:border-orca-teal disabled:bg-gray-50 disabled:text-gray-400"
                            placeholder="diagram-1.svg">
                    </div>

                    {{-- SVG preview --}}
                    <div class="flex items-center justify-center min-h-[120px] bg-gray-50 rounded border border-gray-100 p-2 overflow-hidden">
                        <div x-html="result.svg" class="max-w-full [&>svg]:max-w-full [&>svg]:h-auto"></div>
                    </div>

                    {{-- Upload state --}}
                    <template x-if="result.uploaded">
                        <div class="flex items-center gap-2 text-sm text-green-700">
                            <i class="fas fa-check-circle text-green-600"></i>
                            <a :href="result.uploaded.asset_url" target="_blank" class="underline hover:text-green-900 truncate" x-text="result.uploaded.filename"></a>
                        </div>
                    </template>
                    <template x-if="result.uploading">
                        <div class="flex items-center gap-2 text-sm text-gray-500">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span>{{ __('Uploading…') }}</span>
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

</div>

<script>
window.__pageData = {
    folders: @json($folders),
    rootFolder: @json($rootFolder),
    uploadUrl: '{{ route('tools.tikz-svg.upload') }}',
    csrfToken: '{{ csrf_token() }}',
};
</script>
@endsection
