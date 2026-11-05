<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Project;

class BlogApiController extends Controller
{
    public function index(Request $request)
    {
        $apiKey = $request->query('api_key') ?? $request->header('X-API-KEY');

        if (!$apiKey) {
            return response()->json(['error' => 'API Key is required'], 401);
        }

        $project = Project::where('api_key', $apiKey)->first();

        if (!$project) {
            return response()->json(['error' => 'Invalid API Key'], 401);
        }

        $blogs = $project->blogs()
            ->with(['sections', 'category'])
            ->where('status', 'published')
            ->where(function ($query) {
                $query->whereNull('published_at')
                      ->orWhere('published_at', '<=', now());
            })
            ->latest()
            ->get();

        return response()->json([
            'project' => $project->name,
            'blogs' => $blogs
        ]);
    }
}
