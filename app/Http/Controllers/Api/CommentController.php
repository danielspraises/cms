<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use Illuminate\Http\Request;

/**
 * Generic comment endpoint for any commentable model (ledger entries, loans,
 * member records). This is the "auditors can comment but not edit" surface —
 * gated by 'comments.create' in the route middleware, entirely separate from
 * 'ledger.edit', so granting comment access never implies edit access.
 */
class CommentController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'commentable_type' => 'required|string',
            'commentable_id' => 'required|integer',
        ]);

        return Comment::where($validated)
            ->with('author:id,full_name')
            ->orderByDesc('created_at')
            ->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'commentable_type' => 'required|string',
            'commentable_id' => 'required|integer',
            'body' => 'required|string|min:1|max:2000',
        ]);

        return Comment::create($validated + [
            'society_id' => $request->attributes->get('society_id'),
            'user_id' => $request->user()->id,
        ])->load('author:id,full_name');
    }
}
