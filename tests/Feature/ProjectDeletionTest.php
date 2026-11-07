<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function publication(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['owner_id' => $owner->id, 'name' => 'Studio', 'slug' => 'studio']);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $owner->update(['current_workspace_id' => $workspace->id]);
        $project = Project::create(['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'name' => 'My publication', 'api_key' => 'pk_delete']);
        return [$owner, $workspace, $project];
    }

    public function test_owner_must_confirm_name_and_deletion_cascades_only_the_selected_project(): void
    {
        [$owner, $workspace, $project] = $this->publication();
        $other = Project::create(['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'name' => 'Keep this', 'api_key' => 'pk_keep']);
        $blog = $project->blogs()->create(['user_id' => $owner->id, 'title' => 'Story', 'slug' => 'story', 'content' => '', 'status' => 'draft']);
        $category = $project->categories()->create(['name' => 'General', 'slug' => 'general']);
        $this->actingAs($owner)->delete(route('admin.projects.destroy', $project), ['project_name' => 'wrong'])->assertSessionHasErrors('project_name');
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->delete(route('admin.projects.destroy', $project), ['project_name' => $project->name])->assertRedirect(route('admin.projects'));
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('blogs', ['id' => $blog->id]);
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('projects', ['id' => $other->id]);
    }

    public function test_only_owner_and_admin_can_delete_workspace_projects(): void
    {
        [$owner, $workspace, $project] = $this->publication();
        foreach (['editor', 'viewer', 'outsider'] as $role) {
            $user = User::factory()->create();
            if ($role !== 'outsider') {
                $workspace->users()->attach($user, ['role' => $role]);
                $user->update(['current_workspace_id' => $workspace->id]);
                $this->actingAs($user)->get(route('admin.projects'))->assertOk()->assertDontSee('Delete project');
            }
            $this->actingAs($user)->delete(route('admin.projects.destroy', $project), ['project_name' => $project->name])->assertForbidden();
        }
        $admin = User::factory()->create();
        $workspace->users()->attach($admin, ['role' => 'admin']);
        $this->actingAs($admin)->delete(route('admin.projects.destroy', $project), ['project_name' => $project->name])->assertRedirect();
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_legacy_projects_can_only_be_deleted_by_their_owner(): void
    {
        [$owner, , $project] = $this->publication();
        $project->update(['workspace_id' => null]);
        $this->actingAs(User::factory()->create())->delete(route('admin.projects.destroy', $project), ['project_name' => $project->name])->assertForbidden();
        $this->actingAs($owner)->get(route('admin.projects'))->assertOk()->assertSee('Delete project');
        $this->delete(route('admin.projects.destroy', $project), ['project_name' => $project->name])->assertRedirect();
    }
}
