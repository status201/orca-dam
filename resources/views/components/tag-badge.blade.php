@props([
    'tag',
    'linkable' => false,
    'showIcon' => false,
    'showCount' => false,
    'size' => 'sm',
])

@php
    $type = $tag->type;
    $attachedBy = $tag->pivot->attached_by ?? $type;
    $crossOrigin = $type !== $attachedBy;

    $bgClasses = match($type) {
        'ai' => 'bg-purple-100 text-purple-700',
        'reference' => 'bg-orange-100 text-orange-700',
        default => 'bg-blue-100 text-blue-700',
    };

    $hoverClasses = match($type) {
        'ai' => 'hover:bg-purple-200',
        'reference' => 'hover:bg-orange-200',
        default => 'hover:bg-blue-200',
    };

    $ringClasses = $crossOrigin ? match($attachedBy) {
        'ai' => 'ring-2 ring-purple-400',
        'reference' => 'ring-2 ring-orange-400',
        default => 'ring-2 ring-blue-400',
    } : '';

    $sizeClasses = match($size) {
        'xs' => 'text-xs px-2 py-0.5',
        default => 'text-sm px-3 py-1',
    };

    $tooltip = $crossOrigin
        ? $tag->name . ' — ' . __('Created as') . ': ' . __($type) . ', ' . __('Attached by') . ': ' . __($attachedBy)
        : null;
@endphp

@if($linkable)
<a href="{{ route('assets.index', ['tags[]' => $tag->id]) }}"
   class="tag attention inline-flex items-center {{ $sizeClasses }} rounded-full {{ $bgClasses }} {{ $hoverClasses }} {{ $ringClasses }} transition-colors no-underline"
   @if($tooltip) title="{{ $tooltip }}" @endif>
    {{ $tag->name }}@if($showCount && isset($tag->assets_count)) ({{ $tag->assets_count }})@endif
    @if($showIcon)
        @if($tag->type === 'ai')
        <i class="fas fa-robot ml-2 text-xs"></i>
        @elseif($tag->type === 'reference')
        <i class="fas fa-link ml-2 text-xs"></i>
        @endif
    @endif
</a>
@else
<span class="tag attention inline-flex items-center {{ $sizeClasses }} rounded-full {{ $bgClasses }} {{ $ringClasses }}"
      @if($tooltip) title="{{ $tooltip }}" @endif>
    {{ $tag->name }}@if($showCount && isset($tag->assets_count)) ({{ $tag->assets_count }})@endif
    @if($showIcon)
        @if($tag->type === 'ai')
        <i class="fas fa-robot ml-2 text-xs"></i>
        @elseif($tag->type === 'reference')
        <i class="fas fa-link ml-2 text-xs"></i>
        @endif
    @endif
    {{ $slot }}
</span>
@endif
