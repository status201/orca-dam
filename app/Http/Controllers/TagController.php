<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Get tags for autocomplete
     */
    public function search(Request $request)
    {
        $search = $request->input('q', '');
        
        $tags = Tag::search($search)
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'type']);

        return response()->json($tags);
    }

    /**
     * Get all tags
     */
    public function index(Request $request)
    {
        $type = $request->input('type'); // 'user' or 'ai' or null for all

        $query = Tag::withCount('assets')->orderBy('name');

        if ($type) {
            $query->where('type', $type);
        }

        $tags = $query->get();

        if ($request->expectsJson()) {
            return response()->json($tags);
        }

        return view('tags.index', compact('tags'));
    }
}
