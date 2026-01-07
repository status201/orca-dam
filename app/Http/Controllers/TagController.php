<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function __construct()
    {
        #$this->middleware('auth');
    }

    /**
     * Get tags for autocomplete
     */
    public function search(Request $request)
    {
        $search = $request->input('q', '');
        $type = $request->input('type'); // Optional type filter

        $query = Tag::search($search)->orderBy('name')->limit(20);

        if ($type) {
            $query->where('type', $type);
        }

        $tags = $query->get(['id', 'name', 'type']);

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

    /**
     * Update a tag (user tags only)
     */
    public function update(Request $request, Tag $tag)
    {
        // Only allow updating user tags
        if ($tag->type !== 'user') {
            return response()->json([
                'message' => 'AI tags cannot be edited'
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:50|unique:tags,name,' . $tag->id,
        ]);

        $tag->update([
            'name' => strtolower(trim($request->name)),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Tag updated successfully',
                'tag' => $tag,
            ]);
        }

        return redirect()->route('tags.index')->with('success', 'Tag updated successfully');
    }

    /**
     * Delete a tag (user tags only)
     */
    public function destroy(Tag $tag)
    {
        // Only allow deleting user tags
        if ($tag->type !== 'user') {
            return response()->json([
                'message' => 'AI tags cannot be deleted'
            ], 403);
        }

        // The pivot table entries will be automatically deleted
        // because of the relationship cascade (handled by Laravel)
        $tag->delete();

        if (request()->expectsJson()) {
            return response()->json([
                'message' => 'Tag deleted successfully',
            ]);
        }

        return redirect()->route('tags.index')->with('success', 'Tag deleted successfully');
    }
}
