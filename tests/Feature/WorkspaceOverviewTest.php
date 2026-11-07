<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_counts_only_accessible_projects_and_currently_live_stories(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['owner_id' => $owner->id, 'name' => 'Studio', 'slug' => 'studio']);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $owner->update(['current_workspace_id' => $workspace->id]);
        $project = Project::create(['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'name' => 'Visible publication', 'api_key' => 'pk_visible']);
        Project::create(['user_id' => $owner->id, 'name' => 'Legacy publication', 'api_key' => 'pk_legacy']);
        foreach ([['published', now()->subDay()], ['published', now()->addDay()], ['draft', null]] as $index => [$status, $date]) {
            $project->blogs()->create(['user_id' => $owner->id, 'title' => 'Story '.$index, 'slug' => 'story-'.$index, 'content' => '', 'status' => $status, 'published_at' => $date]);
        }
        $other = User::factory()->create();
        $foreign = Project::create(['user_id' => $other->id, 'name' => 'Private foreign project', 'api_key' => 'pk_foreign']);
        $foreign->blogs()->create(['user_id' => $other->id, 'title' => 'Confidential story', 'slug' => 'confidential', 'content' => '', 'status' => 'published']);
        $response = $this->actingAs($owner)->get('/admin/projects')->assertOk()->assertDontSee('Private foreign project')->assertDontSee('Confidential story');
        $this->assertSame(['projects' => 2, 'stories' => 3, 'published' => 1, 'drafts' => 1], $response->viewData('stats'));
        // The Blade project-card loop must not create an arbitrary sidebar project context.
        $response->assertDontSee(route('admin.projects.seo', $project));
        $this->get('/admin/projects/'.$project->id)->assertOk()->assertSee(route('admin.projects.seo', $project));
        $this->get(route('admin.project-details', $project))->assertOk()->assertSee('Your stories')->assertDontSee('id="api-docs"', false)->assertDontSee('Search appearance');
        $this->get(route('admin.projects.integration', $project))->assertOk()->assertSee('id="api-docs"', false)->assertSee('Search appearance')->assertDontSee('aria-label="Project stories"', false);
        $this->actingAs($other)->get(route('admin.projects.integration', $project))->assertForbidden();
        $this->assertSame(4, Blog::count());
    }
}
