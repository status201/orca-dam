@extends('layouts.app')

@section('title', __('About ORCA'))

@section('content')
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="flex gap-8 items-start">

                {{-- Sticky TOC Sidebar (lg+) --}}
                @if(!empty($toc))
                <nav id="about-toc" class="invert-scrollbar-colors hidden lg:block w-96 shrink-0" style="position: sticky; top: 5rem; max-height: calc(100vh - 5rem); overflow-y: auto;">
                    <div class="bg-white shadow sm:rounded-lg p-4 pb-6">
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-3">{{ __('Contents') }}</p>
                        <ul class="space-y-1">
                            @foreach($toc as $entry)
                            <li>
                                <a href="#{{ $entry['id'] }}"
                                   class="toc-link block text-sm text-gray-600 hover:text-orca-teal rounded px-2 py-0.5 transition-colors duration-100
                                          {{ $entry['level'] === 2 ? 'pl-4' : 'pl-2' }}">
                                    {{ $entry['text'] }}
                                </a>
                            </li>
                            @endforeach
                        </ul>
                    </div>
                </nav>
                @endif

                {{-- Main content --}}
                <div class="min-w-0 flex-1">
                    <div class="bg-white shadow sm:rounded-lg p-8 pb-12">
                        <div class="prose-doc" id="about-doc-content" onclick="handleAboutDocClick(event)">
                            {!! $content !!}
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
@endsection

@push('styles')
<style>
.prose-doc {
    font-size: 0.9375rem;
    line-height: 1.7;
    color: #374151;
}

.prose-doc h1,
.prose-doc h2,
.prose-doc h3,
.prose-doc h4 {
    scroll-margin-top: 5.5rem;
}

.prose-doc h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #111827;
    margin-top: 0;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e5e7eb;
}

.prose-doc h2 {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1f2937;
    margin-top: 2rem;
    margin-bottom: 0.75rem;
    padding-bottom: 0.375rem;
    border-bottom: 1px solid #e5e7eb;
}

.prose-doc h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #374151;
    margin-top: 1.5rem;
    margin-bottom: 0.5rem;
}

.prose-doc h4 {
    font-size: 1.1rem;
    font-weight: 600;
    color: #4b5563;
    margin-top: 1.25rem;
    margin-bottom: 0.5rem;
}

.prose-doc p {
    margin-top: 0.75rem;
    margin-bottom: 0.75rem;
}

.prose-doc a {
    color: #2563eb;
    text-decoration: none;
    font-weight: 500;
}

.prose-doc a:hover {
    color: #1d4ed8;
    text-decoration: underline;
}

.prose-doc strong {
    font-weight: 600;
    color: #1f2937;
}

.prose-doc code {
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
    font-size: 0.875em;
    background-color: #f3f4f6;
    color: #dc2626;
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    border: 1px solid #e5e7eb;
}

.prose-doc pre {
    background-color: #1f2937;
    color: #e5e7eb;
    padding: 1rem 1.25rem;
    border-radius: 0.5rem;
    overflow-x: auto;
    margin: 1rem 0;
    border: 1px solid #374151;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.prose-doc pre code {
    background-color: transparent;
    color: #a5f3fc;
    padding: 0;
    border: none;
    font-size: 0.8125rem;
    line-height: 1.6;
}

.prose-doc blockquote {
    border-left: 4px solid #3b82f6;
    background-color: #eff6ff;
    padding: 0.75rem 1rem;
    margin: 1rem 0;
    border-radius: 0 0.375rem 0.375rem 0;
    color: #1e40af;
    font-style: italic;
}

.prose-doc blockquote p {
    margin: 0;
}

.prose-doc ul {
    list-style-type: disc;
    padding-left: 1.5rem;
    margin: 0.75rem 0;
}

.prose-doc ol {
    list-style-type: decimal;
    padding-left: 1.5rem;
    margin: 0.75rem 0;
}

.prose-doc li {
    margin: 0.375rem 0;
    padding-left: 0.25rem;
}

.prose-doc li > ul,
.prose-doc li > ol {
    margin: 0.25rem 0;
}

.prose-doc hr {
    border: none;
    border-top: 2px solid #e5e7eb;
    margin: 2rem 0;
}

.prose-doc table {
    width: 100%;
    border-collapse: collapse;
    margin: 1rem 0;
    font-size: 0.875rem;
}

.prose-doc thead {
    background-color: #f9fafb;
}

.prose-doc th {
    text-align: left;
    padding: 0.75rem 1rem;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.prose-doc td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e5e7eb;
}

.prose-doc tbody tr:hover {
    background-color: #f9fafb;
}

/* Responsive tables */
@media (max-width: 760px) {
    .prose-doc table {
        width: 100%;
        display: flex;
        flex-direction: column;
    }

    .prose-doc thead,
    .prose-doc tbody {
        display: flex;
        flex-direction: column;
    }

    .prose-doc tr {
        display: flex;
        width: 100%;
    }

    .prose-doc th,
    .prose-doc td {
        flex: 1;
    }
}

.prose-doc img {
    max-width: 100%;
    height: auto;
    border-radius: 0.5rem;
    margin: 1rem 0;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.prose-doc input[type="checkbox"] {
    margin-right: 0.5rem;
    accent-color: #2563eb;
}

.prose-doc .emoji {
    font-size: 1.1em;
    vertical-align: middle;
}

/* TOC active state */
#about-toc .toc-link.toc-active {
    color: #0d9488;
    font-weight: 600;
    background-color: #f0fdfa;
}
</style>
@endpush

@push('scripts')
<script>
function handleAboutDocClick(event) {
    const link = event.target.closest('a');
    if (!link) return;
    const href = link.getAttribute('href');
    if (href && href.startsWith('#')) {
        event.preventDefault();
        const target = document.getElementById(href.slice(1));
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
}

(function () {
    const toc = document.getElementById('about-toc');
    if (!toc) return;

    const headings = document.querySelectorAll('#about-doc-content h1, #about-doc-content h2, #about-doc-content h3');
    const links = document.querySelectorAll('#about-toc .toc-link');
    let activeId = null;

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) activeId = entry.target.id;
        });
        links.forEach(link => {
            link.classList.toggle('toc-active', link.getAttribute('href') === '#' + activeId);
        });
    }, { rootMargin: '0px 0px -70% 0px', threshold: 0 });

    headings.forEach(h => observer.observe(h));

    // Also handle TOC link clicks for smooth scroll
    links.forEach(link => {
        link.addEventListener('click', function (e) {
            const href = this.getAttribute('href');
            if (href && href.startsWith('#')) {
                e.preventDefault();
                const target = document.getElementById(href.slice(1));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });
    });
})();
</script>
@endpush
