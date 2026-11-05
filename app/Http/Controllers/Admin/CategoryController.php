<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\Category;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
    public function store(Request $request, Project $project)
    {
        if ($project->user_id !== Auth::id()) {
            abort(403);
        }

        $request->validate([
            'name' => 'required|string|max:255'
        ]);

        $project->categories()->create([
            'name' => $request->name,
            'slug' => Str::slug($request->name)
        ]);

        return redirect()->back()->with('success', 'Category created successfully.');
    }

    public function destroy(Project $project, Category $category)
    {
        if ($project->user_id !== Auth::id() || $category->project_id !== $project->id) {
            abort(403);
        }

        // If a category is deleted, you might want to either set blogs to null or prevent deletion if blogs exist.
        // Since we have nullOnDelete in the DB, it's fine.
        $category->delete();

        return redirect()->back()->with('success', 'Category deleted successfully.');
    }
}
