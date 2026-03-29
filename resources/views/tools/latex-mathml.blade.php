@extends('layouts.app')

@section('title', __('LaTeX to MathML'))

@push('styles')
<link id="temml-font-css" rel="stylesheet" href="https://cdn.jsdelivr.net/npm/temml@latest/dist/Temml-Local.css">
@endpush

@section('content')
<div class="max-w-7xl mx-auto sm:px-6 lg:px-8" x-data="latexMathml()">

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
            <span class="text-gray-700">{{ __('LaTeX to MathML') }}</span>
        </div>
    </div>

    <div class="mb-6">
        <div class="flex items-center gap-3">
            <h1 class="text-3xl font-bold">{{ __('LaTeX to MathML') }}</h1>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                {{ __('Beta') }}
            </span>
        </div>
        <p class="text-gray-600 mt-1">{{ __('Convert LaTeX math expressions to MathML using Temml.') }}</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">

        {{-- Left: Input --}}
        <div class="bg-white rounded-lg shadow p-6 flex flex-col gap-4">
            <h2 class="text-base font-semibold text-gray-900">
                <i class="fas fa-pen mr-2 text-gray-400"></i>{{ __('LaTeX Input') }}
            </h2>

            {{-- Examples --}}
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">{{ __('Examples') }}</p>
                <div class="flex flex-wrap gap-2">
                    <template x-for="ex in examples" :key="ex.label">
                        <button
                            @click="loadExample(ex.tex)"
                            class="px-2 py-1 text-xs rounded border border-gray-200 text-gray-600 hover:border-orca-teal hover:text-orca-teal transition-colors"
                            x-text="ex.label">
                        </button>
                    </template>
                </div>
            </div>

            {{-- Textarea --}}
            <textarea
                x-model="latex"
                rows="6"
                class="w-full font-mono text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent resize-none"
                placeholder="{{ __('Enter LaTeX here, e.g. a^2 + b^2 = c^2') }}">
            </textarea>

            {{-- Toggles --}}
            <div class="flex flex-wrap gap-4 items-center">
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer select-none">
                    <input type="checkbox" x-model="displayMode" class="rounded border-gray-300 text-orca-teal focus:ring-orca-teal">
                    {{ __('Display mode') }}
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer select-none">
                    <input type="checkbox" x-model="addMmlSemantics" class="rounded border-gray-300 text-orca-teal focus:ring-orca-teal">
                    {{ __('Include LaTeX annotation') }}
                </label>
                <div class="flex items-center gap-2 ml-auto">
                    <label class="text-sm text-gray-700">{{ __('Font') }}:</label>
                    <select x-model="selectedFont"
                        class="text-sm border border-gray-300 rounded-md px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent">
                        <template x-for="f in fonts" :key="f.key">
                            <option :value="f.key" x-text="f.label"></option>
                        </template>
                    </select>
                </div>
            </div>
        </div>

        {{-- Right: Output --}}
        <div class="bg-white rounded-lg shadow p-6 flex flex-col gap-4">
            <h2 class="text-base font-semibold text-gray-900">
                <i class="fas fa-eye mr-2 text-gray-400"></i>{{ __('Preview & MathML') }}
            </h2>

            {{-- Preview --}}
            <div class="min-h-[80px] border border-gray-200 rounded-md px-4 py-3 flex items-center justify-center bg-gray-50">
                <template x-if="previewError">
                    <p class="text-sm text-red-600 font-mono" x-text="previewError"></p>
                </template>
                <template x-if="!previewError && !latex.trim()">
                    <p class="text-sm text-gray-400 italic">{{ __('Preview will appear here') }}</p>
                </template>
                <div id="mathml-preview" class="text-center"></div>
            </div>

            {{-- MathML source --}}
            <div>
                <div class="flex items-center justify-between mb-1">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ __('MathML Source') }}</p>
                    <button
                        @click="copyMathml()"
                        :disabled="!mathmlOutput"
                        class="flex items-center gap-1 text-xs text-orca-teal hover:text-orca-teal-hover disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                        <i class="fas fa-copy"></i>{{ __('Copy') }}
                    </button>
                </div>
                <textarea
                    x-model="mathmlOutput"
                    readonly
                    rows="8"
                    class="w-full font-mono text-xs border border-gray-200 rounded-md px-3 py-2 bg-gray-50 resize-none focus:outline-none"
                    placeholder="{{ __('MathML output will appear here') }}">
                </textarea>
            </div>
        </div>
    </div>

    {{-- Upload to ORCA --}}
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-base font-semibold text-gray-900 mb-4">
            <i class="fas fa-cloud-arrow-up mr-2 text-gray-400"></i>{{ __('Upload to ORCA') }}
        </h2>

        <template x-if="uploadedAsset">
            <div class="flex items-center gap-3 p-4 bg-green-50 rounded-md border border-green-200 mb-4">
                <i class="fas fa-check-circle text-green-600"></i>
                <div class="flex-1 text-sm text-green-800">
                    <span>{{ __('Uploaded:') }} </span>
                    <a :href="uploadedAsset.asset_url" class="font-medium underline hover:text-green-900" x-text="uploadedAsset.filename" target="_blank"></a>
                </div>
                <button @click="uploadedAsset = null" class="text-green-600 hover:text-green-800">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </template>

        <div class="flex flex-wrap gap-4 items-end">
            <div class="w-full">
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Caption') }} <span class="text-gray-400 font-normal">({{ __('optional') }})</span></label>
                <input
                    type="text"
                    x-model="uploadCaption"
                    class="w-full rounded-md px-3 py-2 text-sm border border-gray-300 focus:outline-none focus:ring-2 focus:ring-orca-teal focus:border-transparent"
                    placeholder="{{ __('e.g. Quadratic formula') }}">
            </div>

            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Filename') }}</label>
                <input
                    type="text"
                    x-model="uploadFilename"
                    @input="uploadFilenameError = ''"
                    :class="uploadFilenameError ? 'border-red-500 focus:ring-red-500 focus:border-red-500' : 'border-gray-300 focus:ring-orca-teal focus:border-orca-teal'"
                    class="w-full rounded-md px-3 py-2 text-sm border focus:outline-none focus:ring-2 focus:border-transparent"
                    placeholder="formula.mml">
                <p x-show="uploadFilenameError" x-text="uploadFilenameError" class="mt-1 text-xs text-red-600"></p>
            </div>

            <div class="flex-1 min-w-[200px]">
                <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('Folder') }}</label>
                <select
                    x-model="uploadFolder"
                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-orca-teal focus:ring-orca-teal font-mono text-sm">
                    <x-folder-tree-options :folders="$folders" :root-folder="$rootFolder" />
                </select>
            </div>

            <button
                @click="uploadToOrca()"
                :disabled="uploading || !mathmlOutput"
                class="inline-flex items-center gap-2 px-4 py-2 bg-orca-teal text-white text-sm font-medium rounded-md hover:bg-orca-teal-hover disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                <template x-if="uploading">
                    <i class="fas fa-spinner fa-spin"></i>
                </template>
                <template x-if="!uploading">
                    <i class="fas fa-cloud-arrow-up"></i>
                </template>
                <span x-text="uploading ? '{{ __('Uploading...') }}' : '{{ __('Upload to ORCA') }}'"></span>
            </button>
        </div>
    </div>

    {{-- Instructions --}}
    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg overflow-hidden">
        <button
            @click="showInstructions = !showInstructions"
            class="w-full flex items-center gap-3 px-5 py-3.5 text-left hover:bg-blue-100/50 transition-colors">
            <i class="fas fa-code text-blue-600"></i>
            <span class="font-semibold text-blue-900 text-sm">{{ __('How to use this MathML on your webpage') }}</span>
            <i class="fas fa-chevron-right text-blue-400 text-xs ml-auto transition-transform duration-200"
               :class="showInstructions && 'rotate-90'"></i>
        </button>
        <div x-show="showInstructions" x-collapse class="border-t border-blue-200 px-5 py-4 text-sm text-blue-900 space-y-4">

            <p>{{ __('MathML is rendered natively by modern browsers — no JavaScript library is needed on your webpage.') }}</p>

            {{-- Standard Temml fonts --}}
            <template x-if="selectedFont !== 'arev-sans'">
                <div class="space-y-3">
                    <p class="font-medium">{{ __('1. Add the font stylesheet to your page') }} <code class="text-xs bg-blue-100 px-1 py-0.5 rounded">&lt;head&gt;</code>:</p>
                    <pre class="bg-white border border-blue-200 rounded-md px-4 py-3 text-xs font-mono overflow-x-auto whitespace-pre-wrap">&lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/temml@latest/dist/<span x-text="fonts.find(f => f.key === selectedFont)?.css || 'Temml-Local.css'"></span>"&gt;</pre>

                    <p class="font-medium">{{ __('2. Paste the MathML markup into your HTML:') }}</p>
                    <pre class="bg-white border border-blue-200 rounded-md px-4 py-3 text-xs font-mono overflow-x-auto whitespace-pre-wrap">&lt;!-- Inline math --&gt;
&lt;p&gt;The formula &lt;math&gt;...&lt;/math&gt; is shown inline.&lt;/p&gt;

&lt;!-- Display (block) math --&gt;
&lt;math display="block"&gt;...&lt;/math&gt;</pre>
                </div>
            </template>

            {{-- Arev Sans --}}
            <template x-if="selectedFont === 'arev-sans'">
                <div class="space-y-3">
                    <p class="font-medium">{{ __('1. Host the Arev Sans font files on your server and add CSS:') }}</p>
                    <pre class="bg-white border border-blue-200 rounded-md px-4 py-3 text-xs font-mono overflow-x-auto whitespace-pre-wrap">&lt;!-- Base MathML rendering styles --&gt;
&lt;link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/temml@latest/dist/Temml-Local.css"&gt;

&lt;style&gt;
@@font-face {
    font-family: "Arev Sans";
    src: url("/fonts/arev-sans/Arev.woff") format("woff");
    font-weight: normal;
    font-style: normal;
}
@@font-face {
    font-family: "Arev Sans";
    src: url("/fonts/arev-sans/ArevBd.woff") format("woff");
    font-weight: bold;
    font-style: normal;
}
@@font-face {
    font-family: "Arev Sans";
    src: url("/fonts/arev-sans/ArevIt.woff") format("woff");
    font-weight: normal;
    font-style: italic;
}
@@font-face {
    font-family: "Arev Sans";
    src: url("/fonts/arev-sans/ArevBI.woff") format("woff");
    font-weight: bold;
    font-style: italic;
}
math {
    font-family: "Arev Sans", math;
}
&lt;/style&gt;</pre>

                    <p class="font-medium">{{ __('2. Paste the MathML markup into your HTML:') }}</p>
                    <pre class="bg-white border border-blue-200 rounded-md px-4 py-3 text-xs font-mono overflow-x-auto whitespace-pre-wrap">&lt;!-- Inline math --&gt;
&lt;p&gt;The formula &lt;math&gt;...&lt;/math&gt; is shown inline.&lt;/p&gt;

&lt;!-- Display (block) math --&gt;
&lt;math display="block"&gt;...&lt;/math&gt;</pre>

                    <div class="flex items-start gap-2 p-3 bg-amber-50 border border-amber-200 rounded-md text-amber-800 text-xs">
                        <i class="fas fa-info-circle mt-0.5"></i>
                        <span>{{ __('Arev Sans does not include an OpenType MATH table. The browser may fall back to its default math font for complex layout elements like fractions, radicals, and stretchy delimiters.') }}</span>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
window.__pageData = {
    folders: @json($folders),
    rootFolder: @json($rootFolder),
    uploadUrl: '{{ route('tools.latex-mathml.upload') }}',
    csrfToken: '{{ csrf_token() }}',
};
</script>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/temml@latest/dist/temml.min.js"></script>
@endpush
