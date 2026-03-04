@props(['icon', 'bgClass', 'label', 'value', 'link' => null, 'linkClass' => null])

<div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
    <div class="p-6 h-full">
        <div class="flex items-center h-full">
            <div class="flex-shrink-0 {{ $bgClass }} rounded-md p-3 w-14 text-center">
                <i class="{{ $icon }} text-white text-2xl"></i>
            </div>
            <div class="ml-5 w-0 flex-1">
                <dl>
                    <dt class="text-sm font-medium text-gray-500 truncate">{{ $label }}</dt>
                    <dd class="text-3xl font-semibold text-gray-900">{{ $value }}</dd>
                    {{ $slot }}
                </dl>
            </div>
            @if($link)
            <a href="{{ $link }}" class="{{ $linkClass }}">
                <i class="fas fa-arrow-right"></i>
            </a>
            @endif
        </div>
    </div>
</div>
