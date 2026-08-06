@props(['for', 'max'])

{{--
  A live "used / limit" counter for a length-capped field.

  `maxlength` on its own is not feedback — the browser silently stops accepting keystrokes, so a
  user who pastes a long copyright sees nothing at all and has no idea a limit exists, let alone
  that they just hit it. This makes the limit visible: neutral while there is room, amber in the
  last 10%, red at the cap.

  Advisory only. Any HTTP client bypasses the browser, so the server-side rule stays the authority
  — see specs/features/input-validation.md REQ-8.

  Usage: <x-char-counter for="copyright" :max="ColumnLimits::for('assets', 'copyright')" />
  placed after the input whose id is {{ $for }}.
--}}
<p class="mt-1 text-xs text-gray-400 text-right"
   data-char-counter="{{ $for }}"
   data-char-max="{{ $max }}"
   data-testid="char-counter-{{ $for }}"
   aria-live="polite">
    <span data-char-count>0</span> / {{ $max }}
</p>
