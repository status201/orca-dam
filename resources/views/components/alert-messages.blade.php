{{-- Flash messages from a redirect's ->with('success'|'error'|'warning'). Rendered once per page by
     layouts/app and layouts/embed; `status` is rendered by the auth/profile views that set it. --}}
@foreach([
    'success' => ['bg-green-50 border-green-200 text-green-800', 'fa-check-circle'],
    'error' => ['bg-red-50 border-red-200 text-red-800', 'fa-exclamation-circle'],
    'warning' => ['bg-yellow-50 border-yellow-200 text-yellow-800', 'fa-exclamation-triangle'],
] as $type => [$classes, $icon])
    @if(session($type))
    <div x-data="{ show: true }" x-show="show" data-testid="flash-{{ $type }}"
         role="{{ $type === 'success' ? 'status' : 'alert' }}"
         class="attention mb-6 p-4 border rounded-lg flex items-start justify-between gap-4 {{ $classes }}">
        <div><i class="fas {{ $icon }} mr-2"></i>{{ session($type) }}</div>
        <button type="button" @click="show = false" class="opacity-70 hover:opacity-100" aria-label="{{ __('Close') }}">
            <i class="fas fa-times"></i>
        </button>
    </div>
    @endif
@endforeach
