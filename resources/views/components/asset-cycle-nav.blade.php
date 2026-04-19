@props(['nav'])

<div class="flex flex-wrap items-center gap-3" data-asset-cycle-nav>
    @if(! empty($nav['summary']))
        <span class="hidden md:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-gray-100 text-gray-600 text-xs"
              title="{{ $nav['summary'] }}">
            <i class="fas fa-filter text-[0.65rem]"></i>
            <span class="max-w-[18rem] truncate">{{ $nav['summary'] }}</span>
        </span>
    @endif

    <div class="inline-flex items-stretch rounded-lg border border-gray-300 bg-white shadow-sm overflow-hidden">
        @if($nav['prev'])
            <a href="{{ $nav['prev']['url'] }}"
               data-cycle="prev"
               class="flex items-center px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-100 transition-colors"
               title="{{ __('Previous asset') }}{{ $nav['prev']['filename'] ? ' · '.$nav['prev']['filename'] : '' }}">
                <i class="fas fa-chevron-left text-xs"></i>
                <span class="sr-only">{{ __('Previous asset') }}</span>
            </a>
        @else
            <span class="flex items-center px-3 py-1.5 text-sm text-gray-300 cursor-not-allowed"
                  aria-disabled="true"
                  title="{{ __('No previous asset') }}">
                <i class="fas fa-chevron-left text-xs"></i>
            </span>
        @endif

        <span class="flex items-center px-3 py-1.5 text-xs text-gray-600 border-x border-gray-200 bg-gray-50 whitespace-nowrap tabular-nums">
            <span class="font-medium text-gray-800">{{ $nav['position'] }}</span>
            <span class="mx-1">{{ __('of') }}</span>
            <span>{{ $nav['total'] }}</span>
        </span>

        @if($nav['next'])
            <a href="{{ $nav['next']['url'] }}"
               data-cycle="next"
               class="flex items-center px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-100 transition-colors"
               title="{{ __('Next asset') }}{{ $nav['next']['filename'] ? ' · '.$nav['next']['filename'] : '' }}">
                <i class="fas fa-chevron-right text-xs"></i>
                <span class="sr-only">{{ __('Next asset') }}</span>
            </a>
        @else
            <span class="flex items-center px-3 py-1.5 text-sm text-gray-300 cursor-not-allowed"
                  aria-disabled="true"
                  title="{{ __('No next asset') }}">
                <i class="fas fa-chevron-right text-xs"></i>
            </span>
        @endif
    </div>
</div>
