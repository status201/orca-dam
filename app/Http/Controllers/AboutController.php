<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\App;
use League\CommonMark\GithubFlavoredMarkdownConverter;

class AboutController extends Controller
{
    public function index()
    {
        $file = App::getLocale() === 'nl' ? 'GEBRUIKERSHANDLEIDING.md' : 'USER_MANUAL.md';
        $filePath = base_path($file);

        if (! file_exists($filePath)) {
            abort(404);
        }

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $html = $converter->convert(file_get_contents($filePath))->getContent();

        $html = preg_replace_callback('/<(h[1-4])>(.*?)<\/\1>/s', function ($m) {
            $text = html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $id = strtolower($text);
            $id = preg_replace('/[^a-z0-9\s-]/u', '', $id);
            $id = preg_replace('/\s/', '-', trim($id));

            return "<{$m[1]} id=\"{$id}\">{$m[2]}</{$m[1]}>";
        }, $html);

        preg_match_all('/<h([1-2]) id="([^"]+)">(.*?)<\/h\1>/s', $html, $matches, PREG_SET_ORDER);
        $toc = array_map(fn ($m) => [
            'level' => (int) $m[1],
            'id' => $m[2],
            'text' => html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ], $matches);

        return view('about.index', ['content' => $html, 'toc' => $toc]);
    }
}
