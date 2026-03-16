@extends('layouts.app')

@section('title', __('Tools'))

@section('content')
<div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">{{ __('Tools') }}</h1>
        <p class="text-gray-600 mt-2">{{ __('Utility tools for working with your digital assets') }}</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- LaTeX to MathML -->
        <a href="{{ route('tools.latex-mathml') }}" class="block bg-white rounded-lg shadow hover:shadow-md transition-shadow duration-200 p-6 group">
            <div class="flex flex-col items-start">
                <div class="flex items-center gap-3 mb-4">
                    <div class="text-orca-teal group-hover:text-orca-teal-hover transition-colors">
                        <i class="fas fa-square-root-variable fa-3x"></i>
                    </div>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                        {{ __('Beta') }}
                    </span>
                </div>
                <h2 class="text-lg font-semibold text-gray-900 mb-2">{{ __('LaTeX to MathML') }}</h2>
                <p class="text-sm text-gray-600 mb-4">{{ __('Convert LaTeX math expressions to MathML for use in web content.') }}</p>
                <span class="inline-flex items-center text-sm font-medium text-orca-teal group-hover:text-orca-teal-hover">
                    {{ __('Open') }} <i class="fas fa-arrow-right ml-2 text-xs"></i>
                </span>
            </div>
        </a>
        <!-- TikZ to SVG -->
        <a href="{{ route('tools.tikz-svg') }}" class="block bg-white rounded-lg shadow hover:shadow-md transition-shadow duration-200 p-6 group">
            <div class="flex flex-col items-start">
                <div class="flex items-center gap-3 mb-4">
                    <div class="text-orca-teal group-hover:text-orca-teal-hover transition-colors">
                        <i class="fas fa-bezier-curve fa-3x"></i>
                    </div>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                        {{ __('Beta') }}
                    </span>
                </div>
                <h2 class="text-lg font-semibold text-gray-900 mb-2">{{ __('TikZ to SVG') }}</h2>
                <p class="text-sm text-gray-600 mb-4">{{ __('Render TikZ diagrams to SVG and upload them directly to ORCA.') }}</p>
                <span class="inline-flex items-center text-sm font-medium text-orca-teal group-hover:text-orca-teal-hover">
                    {{ __('Open') }} <i class="fas fa-arrow-right ml-2 text-xs"></i>
                </span>
            </div>
        </a>
    </div>
</div>
@endsection
