{{--
    The guided-demo overlay. Included once by layouts/app.blade.php — deliberately NOT by
    layouts/embed.blade.php, which is iframe chrome with no nav to explain.

    Renders absolutely nothing when no demo is armed (specs/features/guided-demos.md REQ-12):
    no script, no DOM node, no cost beyond one nullable lookup.

    Two things here are load-bearing and easy to undo by accident:

      * Visibility is `x-show` plus an inline `display:none`, NEVER `x-cloak`. This partial
        is in the base layout and the E2E harness waits for `[x-cloak]` to reach zero, so an
        x-data that threw would hang the whole browser suite rather than one spec.
        layouts/navigation.blade.php uses the same pattern for the same reason.
      * The payload is a *namespaced* write onto window.__pageData. dashboard.blade.php
        assigns the whole object, so a root-level write here would be clobbered.
--}}
@php
    $guidedDemo = auth()->check()
        ? app(\App\Demos\DemoRegistry::class)->payload(
            request('demo'),
            auth()->user(),
            (int) request('demoStep', 0),
            \Illuminate\Support\Facades\Route::currentRouteName(),
        )
        : null;
@endphp

@if($guidedDemo)
    @push('scripts')
        <script>
            window.__pageData = window.__pageData || {};
            window.__pageData.guidedDemo = @js($guidedDemo);
        </script>
    @endpush

    <div x-data="guidedDemo()"
         x-show="running"
         style="display: none;"
         data-testid="demo-overlay"
         :data-active="running ? 'true' : 'false'"
         :data-demo="demo ? demo.id : ''"
         :data-step="String(index)"
         :data-target="targetKey"
         :data-awaiting="awaiting ? 'true' : 'false'"
         :data-placement="placement"
         :data-missing="missing ? 'true' : 'false'"
         :data-settled="settled ? 'true' : 'false'"
         @keydown.escape.window="skip()"
         @keydown.window="onKey($event)">

        {{-- Dimming and click-blocking are deliberately separate jobs.

             The dimming is ONE element: the spotlight's own huge outer box-shadow. Four
             tiled shutters used to draw it, and their seams were exact once settled — but
             each animates a different property from a different start value, so mid-morph
             they drifted apart and leaked a visible line along the hole. One element cannot
             have a seam with itself.

             The shutters remain, transparent, purely to swallow clicks outside the hole:
             a box-shadow is not hit-tested, and clicks *inside* the hole must still reach
             the real element — that is what makes an act-to-advance step work. Being
             invisible, they need no transition, so nothing can drift. --}}
        <template x-for="side in shutters" :key="side">
            <div x-show="hasTarget" class="fixed z-[60]" :style="shutter(side)"></div>
        </template>

        {{-- No hole to cut: dim the lot with a single element. --}}
        <div x-show="!hasTarget" class="orca-demo-veil fixed inset-0 z-[60] bg-black/60"></div>

        {{-- A passive step gets a transparent lid so the highlighted control cannot be
             clicked by mistake; an interactive one deliberately does not. --}}
        <div x-show="hasTarget && !awaiting" class="fixed z-[61]" :style="ring"></div>

        <div data-testid="demo-spotlight"
             x-show="hasTarget"
             class="orca-demo-ring fixed z-[61] rounded-md border-2 border-white pointer-events-none"
             :style="ring"></div>

        <div data-testid="demo-popover"
             role="dialog"
             aria-modal="true"
             tabindex="-1"
             aria-label="{{ __('Guided demo') }}"
             class="fixed z-[62] max-w-[calc(100vw-2rem)] rounded-lg bg-white p-5 shadow-2xl"
             :style="popover">

            {{-- The outro: shown only when the demo has a successor this user may play. --}}
            <template x-if="finished">
                <div data-testid="demo-outro">
                    <h2 class="text-lg font-semibold text-gray-900" x-text="ui.finished"></h2>
                    <div class="mt-4 flex items-center justify-end gap-2">
                        <button type="button" data-testid="demo-skip" @click="stop()"
                                class="text-xs text-gray-500 underline">
                            <span x-text="ui.notNow"></span>
                        </button>
                        <button type="button" data-testid="demo-next-demo" @click="startNext()"
                                class="rounded-md bg-orca-black px-3 py-1.5 text-sm text-white hover:bg-orca-black-hover"
                                x-text="ui.startNext.replace(':title', demo.next ? demo.next.title : '')"></button>
                    </div>
                </div>
            </template>

            <template x-if="!finished">
                <div>
                    <h2 data-testid="demo-title" class="text-lg font-semibold text-gray-900"
                        x-text="step.title"></h2>
                    <p data-testid="demo-body" class="mt-2 text-sm text-gray-600" aria-live="polite"
                       x-text="step.body"></p>

                    {{-- The hand-off card: this step lives on another page. Also what a
                         shared link pointing at the wrong page falls back to. --}}
                    <template x-if="!onThisPage">
                        <button type="button" data-testid="demo-goto" @click="goToPage()"
                                class="mt-4 w-full rounded-md bg-orca-black px-3 py-2 text-sm text-white hover:bg-orca-black-hover"
                                x-text="ui.goToPage"></button>
                    </template>

                    <p x-show="awaiting" class="mt-3 text-xs italic text-gray-500" x-text="ui.tryIt"></p>

                    <div class="mt-4 flex items-center justify-between gap-4">
                        <span class="whitespace-nowrap text-xs text-gray-500">
                            <span data-testid="demo-step" x-text="index + 1"></span>
                            /
                            <span data-testid="demo-steps" x-text="total"></span>
                        </span>

                        <div class="flex items-center gap-2">
                            <button type="button" data-testid="demo-skip" @click="skip()"
                                    class="text-xs text-gray-500 underline">{{ __('Skip demo') }}</button>

                            <button type="button" data-testid="demo-prev" @click="prev()"
                                    :disabled="index === 0"
                                    class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm disabled:opacity-40">
                                {{ __('Back') }}
                            </button>

                            {{-- Always enabled, even while awaiting an action: declining to
                                 do the thing must never trap the reader (REQ-8). --}}
                            <button type="button" data-testid="demo-next" x-show="!isLast" @click="next()"
                                    class="rounded-md bg-orca-black px-3 py-1.5 text-sm text-white hover:bg-orca-black-hover">
                                {{ __('Next') }}
                            </button>

                            <button type="button" data-testid="demo-finish" x-show="isLast" @click="finish()"
                                    class="rounded-md bg-orca-black px-3 py-1.5 text-sm text-white hover:bg-orca-black-hover">
                                {{ __('Done') }}
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
@endif
