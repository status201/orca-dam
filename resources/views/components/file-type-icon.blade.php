@props(['asset'])

@if($asset->isTex())
<svg {{ $attributes->merge(['class' => 'file-type-icon ' . $asset->getIconColorClass()]) }}
     viewBox="0 0 384 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    {{-- Single compound path: FA body + fold + knocked-out TeX logotype (evenodd) --}}
    <path fill-rule="evenodd" d="M64 0C28.7 0 0 28.7 0 64L0 448c0 35.3 28.7 64 64 64l256 0c35.3 0 64-28.7 64-64l0-288-128 0c-17.7 0-32-14.3-32-32L224 0 64 0zM256 0l0 128 128 0L256 0zM71 250H151V264H118V348H125V360H97V348H104V264H71ZM161 267H223V280H175V305H209V318H175V347H223V360H161ZM233 250H249L273 291.25 297 250H313L281 305 313 360H297L273 318.75 249 360H233L265 305Z"/>
</svg>
@else
<i {{ $attributes->merge(['class' => 'fas ' . $asset->getFileIcon() . ' ' . $asset->getIconColorClass()]) }}></i>
@endif
