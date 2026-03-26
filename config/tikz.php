<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LaTeX Binary Path
    |--------------------------------------------------------------------------
    |
    | Path to the latex binary. Uses DVI output mode for dvisvgm compatibility.
    |
    */
    'latex_path' => env('TIKZ_LATEX_PATH', 'latex'),

    /*
    |--------------------------------------------------------------------------
    | dvisvgm Binary Path
    |--------------------------------------------------------------------------
    |
    | Path to the dvisvgm binary for DVI to SVG conversion.
    |
    */
    'dvisvgm_path' => env('TIKZ_DVISVGM_PATH', 'dvisvgm'),

    /*
    |--------------------------------------------------------------------------
    | dvipng Binary Path
    |--------------------------------------------------------------------------
    |
    | Path to the dvipng binary for DVI to PNG conversion.
    |
    */
    'dvipng_path' => env('TIKZ_DVIPNG_PATH', 'dvipng'),

    /*
    |--------------------------------------------------------------------------
    | Compilation Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time in seconds for the LaTeX compilation + conversion pipeline.
    |
    */
    'timeout' => (int) env('TIKZ_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | PNG DPI
    |--------------------------------------------------------------------------
    |
    | Default DPI for PNG output via dvipng.
    |
    */
    'png_dpi' => (int) env('TIKZ_PNG_DPI', 300),

];
