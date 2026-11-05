<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublishingTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug = 'studio'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Studio', 'slug' => $slug]);
        $workspace->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_workspace_id' => $workspace->id]);
        $project = Project::create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'name' => 'Journal', 'api_key' => 'pk_'.$slug]);
        $category = $project->categories()->create(['name' => 'Notes', 'slug' => 'notes']);
        return [$user, $workspace, $project, $category];
    }

    private function postData($category): array
    {
        return ['title' => 'A new chapter', 'category_id' => $category->id, 'status' => 'draft', 'sections' => [['text' => '<p>Hello <strong>world</strong></p>']]];
    }

    public function test_guests_reach_the_real_login_page(): void
    {
        $this->get('/admin/projects')->assertRedirect('/login');
        $this->get('/admin/login')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }

    public function test_registration_creates_a_workspace_and_login_session(): void
    {
        $this->post('/register', ['name' => 'Jamie', 'email' => 'jamie@example.test', 'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password'])
            ->assertRedirect('/admin/projects');
        $this->assertAuthenticated();
        $user = User::firstOrFail();
        $this->assertNotNull($user->current_workspace_id);
        $this->assertSame('owner', $user->workspaces()->first()->pivot->role);
        $this->get('/admin/projects')->assertOk();
    }

    public function test_another_tenant_cannot_read_modify_or_move_content(): void
    {
        [$owner, , $project, $category] = $this->tenant();
        [$stranger, , $otherProject] = $this->tenant('other');
        $blog = $project->blogs()->create(['user_id' => $owner->id, 'title' => 'Private', 'slug' => 'private', 'content' => '', 'status' => 'draft']);
        $this->actingAs($stranger)->get('/admin/projects/'.$project->id)->assertForbidden();
        $this->post('/admin/projects/'.$project->id.'/blogs', $this->postData($category))->assertForbidden();
        $this->put('/admin/blogs/'.$blog->id, $this->postData($category))->assertForbidden();
        $this->actingAs($owner)->post('/admin/blogs/'.$blog->id.'/move', ['target_project_id' => $otherProject->id])->assertForbidden();
        $this->actingAs($stranger)->post('/workspaces/'.$owner->current_workspace_id.'/switch')->assertForbidden();
    }

    public function test_workspace_editors_can_publish_and_members_are_read_only(): void
    {
        [, $workspace, $project, $category] = $this->tenant();
        $editor = User::factory()->create(['current_workspace_id' => $workspace->id]);
        $workspace->users()->attach($editor, ['role' => 'editor']);
        $this->actingAs($editor)->post('/admin/projects/'.$project->id.'/blogs', $this->postData($category))->assertRedirect();
        $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
        $workspace->users()->attach($member, ['role' => 'member']);
        $this->actingAs($member)->get('/admin/projects/'.$project->id)->assertOk();
        $this->post('/admin/projects/'.$project->id.'/blogs', $this->postData($category))->assertForbidden();
        $this->post('/admin/projects', ['name' => 'Blocked'])->assertForbidden();
    }

    public function test_categories_must_belong_to_the_project(): void
    {
        [$owner, , $project] = $this->tenant();
        [, , , $foreignCategory] = $this->tenant('other');
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/blogs', $this->postData($foreignCategory))->assertSessionHasErrors('category_id');
        $this->assertDatabaseCount('blogs', 0);
    }

    public function test_executable_section_uploads_are_rejected(): void
    {
        Storage::fake('public');
        [$owner, , $project, $category] = $this->tenant();
        $data = $this->postData($category);
        $data['sections'][0]['image'] = UploadedFile::fake()->createWithContent('shell.php', '<?php echo "unsafe";');
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/blogs', $data)->assertSessionHasErrors('sections.0.image');
        $this->assertDatabaseCount('blogs', 0);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_scheduled_posts_require_a_future_date_and_valid_status(): void
    {
        [$owner, , $project, $category] = $this->tenant();
        $data = $this->postData($category);
        $data['status'] = 'scheduled';
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/blogs', $data)->assertSessionHasErrors('published_at');
        $data['published_at'] = now()->subDay()->toDateTimeString();
        $this->post('/admin/projects/'.$project->id.'/blogs', $data)->assertSessionHasErrors('published_at');
        $data['status'] = 'invalid';
        $this->post('/admin/projects/'.$project->id.'/blogs', $data)->assertSessionHasErrors('status');
        $this->assertDatabaseCount('blogs', 0);
    }

    public function test_update_keeps_old_sections_when_validation_fails(): void
    {
        [$owner, , $project, $category] = $this->tenant();
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/blogs', $this->postData($category))->assertRedirect();
        $blog = Blog::firstOrFail();
        $data = $this->postData($category);
        $data['sections'][0]['existing_image'] = '/storage/someone-elses-image.jpg';
        $this->put('/admin/blogs/'.$blog->id, $data)->assertSessionHasErrors('sections.0.existing_image');
        $this->assertSame('<p>Hello <strong>world</strong></p>', $blog->sections()->first()->text_content);
    }

    public function test_api_only_returns_due_published_posts_and_paginates(): void
    {
        [$owner, , $project] = $this->tenant();
        foreach (['draft', 'future', 'live', 'live-two'] as $slug) {
            $blog = $project->blogs()->create(['user_id' => $owner->id, 'title' => $slug, 'slug' => $slug, 'content' => '', 'status' => $slug === 'draft' ? 'draft' : 'published', 'published_at' => $slug === 'future' ? now()->addDay() : now()->subDay()]);
            $blog->sections()->create(['text_content' => '<p>Safe</p><script>alert(1)</script>', 'order' => 0]);
        }
        $this->getJson('/api/v1/projects/blogs')->assertUnauthorized();
        $this->getJson('/api/v1/projects/blogs', ['X-API-KEY' => 'wrong'])->assertUnauthorized();
        $response = $this->getJson('/api/v1/projects/blogs?per_page=1', ['X-API-KEY' => $project->api_key]);
        $response->assertOk()->assertJsonCount(1, 'blogs')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->assertStringNotContainsString('<script', $response->getContent());
        $this->getJson('/api/v1/projects/blogs?per_page=1000', ['X-API-KEY' => $project->api_key])->assertUnprocessable();
    }

    public function test_publishing_a_scheduled_post_with_toggle_makes_it_live(): void
    {
        [$owner, , $project] = $this->tenant();
        $blog = $project->blogs()->create(['user_id' => $owner->id, 'title' => 'Future', 'slug' => 'future', 'content' => '', 'status' => 'published', 'published_at' => now()->addDay()]);
        $this->actingAs($owner)->patch('/admin/blogs/'.$blog->id.'/toggle-status')->assertRedirect();
        $this->patch('/admin/blogs/'.$blog->id.'/toggle-status')->assertRedirect();
        $this->assertTrue($blog->fresh()->published_at->lte(now()));
    }

    public function test_workspace_name_is_escaped_in_flash_messages(): void
    {
        [$owner, $workspace] = $this->tenant();
        $workspace->update(['name' => '<img src=x onerror=alert(1)>']);
        $this->actingAs($owner)->post('/workspaces/'.$workspace->id.'/switch')->assertRedirect();
        $this->get('/admin/projects')->assertOk()->assertDontSee('<img src=x onerror=alert(1)>', false)->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
    }

    public function test_api_supports_cross_origin_read_only_requests(): void
    {
        $this->options('/api/v1/projects/blogs', [], ['Origin' => 'https://example.test', 'Access-Control-Request-Method' => 'GET', 'Access-Control-Request-Headers' => 'x-api-key'])
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
    }
}
