<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Project;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    private function getCurrentWorkspace()
    {
        $user = Auth::user();
        if (!$user->current_workspace_id) {
            $ws = $user->workspaces()->first();
            if (!$ws) {
                $ws = $user->ownedWorkspaces()->create([
                    'name' => $user->name . "'s Workspace",
                    'slug' => Str::slug($user->name . '-' . Str::random(4)),
                ]);
                $ws->users()->attach($user->id, ['role' => 'owner']);
            }
            $user->current_workspace_id = $ws->id;
            $user->save();
        }
        return $user->currentWorkspace;
    }

    public function index()
    {
        $workspace = $this->getCurrentWorkspace();
        $projects = Project::where('workspace_id', $workspace->id)
            ->orWhere(function ($query) use ($workspace) {
                // Backward compatibility for existing projects assigned directly to user
                $query->where('user_id', Auth::id())->whereNull('workspace_id');
            })
            ->withCount('blogs')
            ->get();

        $workspaces = Auth::user()->workspaces;

        return view('admin.projects', compact('projects', 'workspace', 'workspaces'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'subdomain' => 'nullable|string|max:100|alpha_dash|unique:projects,subdomain',
            'description' => 'nullable|string',
        ]);

        $workspace = $this->getCurrentWorkspace();

        $subdomain = $request->subdomain ? Str::slug($request->subdomain) : Str::slug($request->name);
        $counter = 1;
        $originalSubdomain = $subdomain;
        while (Project::where('subdomain', $subdomain)->exists()) {
            $subdomain = $originalSubdomain . '-' . $counter++;
        }

        $project = Project::create([
            'user_id' => Auth::id(),
            'workspace_id' => $workspace->id,
            'name' => $request->name,
            'subdomain' => $subdomain,
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

        return redirect()->route('admin.projects')->with('success', 'Project created successfully!');
    }

    public function show(Project $project)
    {
        $workspace = $this->getCurrentWorkspace();

        // Check project access within current workspace or owned by user
        if ($project->workspace_id !== $workspace->id && $project->user_id !== Auth::id()) {
            abort(403);
        }

        $project->load([
            'blogs' => function ($query) {
                $query->latest();
            },
            'blogs.category',
            'categories'
        ]);

        $otherProjects = Project::where('workspace_id', $workspace->id)
            ->where('id', '!=', $project->id)
            ->with('categories')
            ->get();

        $workspaces = Auth::user()->workspaces;

        return view('admin.project-details', compact('project', 'otherProjects', 'workspace', 'workspaces'));
    }
}
