<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function __construct()
    {
        // $this->middleware('auth');
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
        $type = $request->input('type'); // 'user' or 'ai' or 'reference' or null for all
        $sort = $request->input('sort', 'name_asc');
        $search = $request->input('search');

        $query = Tag::withCount('assets');

        if ($type) {
            $query->where('type', $type);
        }

        if ($search) {
            $query->search($search);
        }

        match ($sort) {
            'name_desc' => $query->orderBy('name', 'desc'),
            'most_used' => $query->orderBy('assets_count', 'desc'),
            'least_used' => $query->orderBy('assets_count', 'asc'),
            'newest' => $query->orderBy('created_at', 'desc'),
            'oldest' => $query->orderBy('created_at', 'asc'),
            default => $query->orderBy('name', 'asc'),
        };

        if ($request->expectsJson()) {
            $perPage = (int) $request->input('per_page', 60);
            $perPage = min(max($perPage, 10), 200);

            return response()->json($query->paginate($perPage));
        }

        // For web view, only pass type counts and config — no tags collection
        $counts = Tag::selectRaw('type, COUNT(*) as count')->groupBy('type')->pluck('count', 'type');
        $typeCounts = [
            'all' => $counts->sum(),
            'user' => $counts->get('user', 0),
            'ai' => $counts->get('ai', 0),
            'reference' => $counts->get('reference', 0),
        ];

        return view('tags.index', compact('typeCounts'));
    }

    /**
     * Resolve tags by IDs (for displaying selected tag names)
     */
    public function byIds(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $tags = Tag::withCount('assets')
            ->whereIn('id', $request->input('ids'))
            ->get();

        return response()->json($tags);
    }

    /**
     * Update a tag (user tags only)
     */
    public function update(Request $request, Tag $tag)
    {
        // Only allow updating user and reference tags (not AI tags)
        if ($tag->type === 'ai') {
            return response()->json([
                'message' => 'AI tags cannot be edited',
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:50|unique:tags,name,'.$tag->id,
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
     * Delete a tag (user and AI tags)
     */
    public function destroy(Tag $tag)
    {
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
