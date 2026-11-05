<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\Workspace;

class WorkspaceController extends Controller
{
    public function switchWorkspace(Request $request, Workspace $workspace)
    {
        $user = Auth::user();

        // Check if user belongs to this workspace
        if (!$user->workspaces()->where('workspaces.id', $workspace->id)->exists()) {
            abort(403, 'Unauthorized access to this workspace.');
        }

        $user->current_workspace_id = $workspace->id;
        $user->save();

        return back()->with('success', "Switched to workspace: {$workspace->name}");
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user = Auth::user();

        return DB::transaction(function () use ($request, $user) {
            $slugBase = Str::slug($request->name);
            $slug = $slugBase;
            $counter = 1;
            while (Workspace::where('slug', $slug)->exists()) {
                $slug = $slugBase . '-' . $counter++;
            }

            $workspace = Workspace::create([
                'owner_id' => $user->id,
                'name' => $request->name,
                'slug' => $slug,
            ]);

            $workspace->users()->attach($user->id, ['role' => 'owner']);

            $user->current_workspace_id = $workspace->id;
            $user->save();

            return redirect()->route('admin.projects')->with('success', 'New workspace created!');
        });
    }
}

