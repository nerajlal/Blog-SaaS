<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Project;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    public function index()
    {
        $projects = Auth::user()->projects()->withCount('blogs')->get();
        return view('admin.projects', compact('projects'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $project = Auth::user()->projects()->create([
            'name' => $request->name,
            'description' => $request->description,
            'api_key' => 'pk_' . Str::random(32),
        ]);

        $defaultCategories = ['General', 'News', 'Tutorials'];
        foreach ($defaultCategories as $catName) {
            $project->categories()->create([
                'name' => $catName,
                'slug' => Str::slug($catName),
            ]);
        }

        return redirect()->route('admin.projects');
    }

    public function show(Project $project)
    {
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        $project->load([
            'blogs' => function ($query) {
                $query->latest();
            },
            'blogs.category',
            'categories'
        ]);

        $otherProjects = Auth::user()->projects()
            ->where('id', '!=', $project->id)
            ->with('categories')
            ->get();

        return view('admin.project-details', compact('project', 'otherProjects'));
    }
}
