@props(['asset', 'size' => 'thumb', 'refreshable' => false])

<div
    x-data="{
        html: '',
        error: false,
        isRefreshing: false,
        async init() {
            this.isRefreshing = true;
            try {
                const r = await fetch('{{ $asset->url }}');
                if (!r.ok) { this.error = true; return; }
                const t = await r.text();
                const doc = new DOMParser().parseFromString(t, 'application/xml');
                const m = doc.querySelector('math');
                if (m) { this.html = m.outerHTML; } else { this.error = true; }
            } catch(e) { this.error = true; }
            finally { this.isRefreshing = false; }
        },
        refresh() {
            this.html = '';
            this.error = false;
            this.init();
        }
    }"
    class="w-full h-full flex items-center justify-center bg-white relative {{ $size === 'full' ? 'p-8 min-h-[200px]' : 'p-2' }}"
>
    @if($refreshable)
    <button @click="refresh()" title="{{ __('Force a refresh') }}"
            class="absolute top-2 right-2 bg-white/90 hover:bg-white rounded-full p-2 shadow-lg transition-all hover:scale-110 z-10"
            :disabled="isRefreshing">
        <svg class="w-5 h-5 text-gray-700" :class="{ 'animate-spin [animation-direction:reverse]': isRefreshing }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
    </button>
    @endif
    <template x-if="html">
        <div
            x-html="html"
            class="{{ $size === 'full' ? 'text-2xl' : 'text-sm scale-75 origin-center' }} [&_math]:block"
        ></div>
    </template>
    <template x-if="!html && !error">
        <i class="fas fa-spinner fa-spin text-gray-400 {{ $size === 'full' ? 'text-3xl' : 'text-lg' }}"></i>
    </template>
    <template x-if="error">
        <i class="fas fa-square-root-variable {{ $size === 'full' ? 'text-6xl' : 'text-4xl' }} text-indigo-300 opacity-60"></i>
    </template>
</div>
