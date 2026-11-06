<?php

namespace Tests\Feature;

use App\Models\{Blog, BlogAutosave, Project, User, Workspace};
use App\Services\Editor\BlogRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BlogAutosaveTest extends TestCase
{
    use RefreshDatabase;

    private function story(string $suffix = 'one'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['owner_id' => $owner->id, 'name' => 'Studio', 'slug' => $suffix]);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $project = Project::create(['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'name' => 'Journal', 'api_key' => 'pk_'.$suffix]);
        $category = $project->categories()->create(['name' => 'Notes', 'slug' => 'notes']);
        $blog = $project->blogs()->create(['user_id' => $owner->id, 'category_id' => $category->id, 'title' => 'Live title', 'slug' => 'story-'.$suffix, 'content' => '', 'status' => 'published', 'published_at' => now()->subDay()]);
        $blog->sections()->create(['text_content' => '<p>Live text.</p>', 'order' => 0]);
        return [$owner, $workspace, $project, $blog->fresh()];
    }

    private function payload(Blog $blog, int $version = 0): array
    {
        return ['base_hash' => app(BlogRevision::class)->hash($blog->fresh()), 'version' => $version, 'fields' => ['title' => 'Private recovery title', 'category_id' => (string) $blog->category_id, 'status' => 'draft', 'sections[0][text]' => '<p>Private recovery text.</p>']];
    }

    public function test_private_autosave_does_not_change_published_content_and_is_encrypted(): void
    {
        [$owner,, $project, $blog] = $this->story();
        $before = $blog->updated_at;
        $this->actingAs($owner)->postJson('/admin/blogs/'.$blog->id.'/autosave', $this->payload($blog))->assertOk()->assertJsonPath('version', 1);
        $this->assertSame('Live title', $blog->fresh()->title);
        $this->assertSame('published', $blog->fresh()->status);
        $this->assertEquals($before, $blog->fresh()->updated_at);
        $this->assertStringNotContainsString('Private recovery', DB::table('blog_autosaves')->value('snapshot'));
        $this->getJson('/admin/blogs/'.$blog->id.'/autosave')->assertOk()->assertJsonPath('draft.fields.title', 'Private recovery title')->assertJsonPath('draft.stale', false)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/v1/projects/blogs', ['X-API-KEY' => $project->api_key])->assertOk()->assertDontSee('Private recovery');
        $this->assertDatabaseCount('seo_analyses', 0);
    }

    public function test_cross_tenant_and_read_only_access_are_rejected_and_users_have_separate_drafts(): void
    {
        [$owner, $workspace,, $blog] = $this->story();
        [$other] = $this->story('other');
        $this->actingAs($owner)->postJson('/admin/blogs/'.$blog->id.'/autosave', $this->payload($blog))->assertOk();
        $this->actingAs($other)->getJson('/admin/blogs/'.$blog->id.'/autosave')->assertForbidden();
        $this->postJson('/admin/blogs/'.$blog->id.'/autosave', $this->payload($blog))->assertForbidden();
        $member = User::factory()->create(); $workspace->users()->attach($member, ['role' => 'member']);
        $this->actingAs($member)->getJson('/admin/blogs/'.$blog->id.'/autosave')->assertForbidden();
        $editor = User::factory()->create(); $workspace->users()->attach($editor, ['role' => 'editor']);
        $this->actingAs($editor)->getJson('/admin/blogs/'.$blog->id.'/autosave')->assertOk()->assertJsonPath('draft', null);
        $this->postJson('/admin/blogs/'.$blog->id.'/autosave', $this->payload($blog))->assertOk();
        $this->assertDatabaseCount('blog_autosaves', 2);
    }

    public function test_conflicting_tabs_cannot_overwrite_a_newer_snapshot_or_discard_it(): void
    {
        [$owner,,, $blog] = $this->story(); $payload = $this->payload($blog);
        $this->actingAs($owner)->postJson('/admin/blogs/'.$blog->id.'/autosave', $payload)->assertOk();
        $this->postJson('/admin/blogs/'.$blog->id.'/autosave', $payload)->assertConflict();
        $this->postJson('/admin/blogs/'.$blog->id.'/autosave', array_merge($payload, ['version' => 1]))->assertOk()->assertJsonPath('version', 2);
        $this->deleteJson('/admin/blogs/'.$blog->id.'/autosave', ['version' => 1])->assertConflict();
        $this->deleteJson('/admin/blogs/'.$blog->id.'/autosave', ['version' => 2])->assertOk()->assertJsonPath('version', 3);
        $this->assertSame([], BlogAutosave::first()->snapshot);
        $this->postJson('/admin/blogs/'.$blog->id.'/autosave', $payload)->assertConflict();
    }

    public function test_saved_content_changes_pause_autosave_and_mark_recovery_stale(): void
    {
        [$owner,,, $blog] = $this->story(); $payload = $this->payload($blog);
        $this->actingAs($owner)->postJson('/admin/blogs/'.$blog->id.'/autosave', $payload)->assertOk();
        $blog->sections()->first()->update(['text_content' => '<p>A newer saved revision.</p>']);
        $this->postJson('/admin/blogs/'.$blog->id.'/autosave', array_merge($payload, ['version' => 1]))->assertConflict();
        $this->getJson('/admin/blogs/'.$blog->id.'/autosave')->assertJsonPath('draft.stale', true);
        $this->assertSame('Private recovery title', BlogAutosave::first()->snapshot['title']);
    }

    public function test_unsupported_fields_foreign_images_and_script_content_are_handled(): void
    {
        [$owner,,, $blog] = $this->story(); $payload = $this->payload($blog);
        $this->actingAs($owner);
        $bad = $payload; $bad['fields']['user_id'] = '99';
        $this->postJson('/admin/blogs/'.$blog->id.'/autosave', $bad)->assertUnprocessable();
        $bad = $payload; $bad['fields']['sections[0][existing_image]'] = '/storage/foreign.png';
        $this->postJson('/admin/blogs/'.$blog->id.'/autosave', $bad)->assertUnprocessable();
        $payload['fields']['sections[0][text]'] = '<p onclick="alert(1)">Safe</p><script>alert(1)</script>';
        $this->postJson('/admin/blogs/'.$blog->id.'/autosave', $payload)->assertOk();
        $this->assertSame('<p>Safe</p>', BlogAutosave::first()->snapshot['sections[0][text]']);
    }

    public function test_moving_a_post_does_not_expose_original_project_recovery(): void
    {
        [$owner,,, $blog] = $this->story(); [$newOwner,, $newProject] = $this->story('other');
        $this->actingAs($owner)->postJson('/admin/blogs/'.$blog->id.'/autosave', $this->payload($blog))->assertOk();
        $blog->update(['project_id' => $newProject->id, 'category_id' => null]);
        $this->actingAs($newOwner)->getJson('/admin/blogs/'.$blog->id.'/autosave')->assertOk()->assertJsonPath('draft', null);
        $this->actingAs($owner)->getJson('/admin/blogs/'.$blog->id.'/autosave')->assertForbidden();
    }

    public function test_manual_update_applies_changes_and_removes_only_own_known_draft(): void
    {
        [$owner, $workspace,, $blog] = $this->story();
        $payload = $this->payload($blog);
        $this->actingAs($owner)->postJson('/admin/blogs/'.$blog->id.'/autosave', $payload)->assertOk();
        $editor = User::factory()->create(); $workspace->users()->attach($editor, ['role' => 'editor']);
        $this->actingAs($editor)->postJson('/admin/blogs/'.$blog->id.'/autosave', $payload)->assertOk();
        $this->actingAs($owner)->put('/admin/blogs/'.$blog->id, ['title' => 'Approved changes', 'category_id' => $blog->category_id, 'status' => 'published', 'sections' => [['text' => '<p>Approved text.</p>']], 'editor_revision' => $payload['base_hash'], 'autosave_version' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Approved changes', $blog->fresh()->title);
        $this->assertSame(1, BlogAutosave::count()); $this->assertSame($editor->id, BlogAutosave::first()->user_id);
        $this->assertNotNull($blog->fresh()->latestSeoAnalysis);
    }

    public function test_stale_manual_update_is_rejected_without_removing_recovery(): void
    {
        [$owner,,, $blog] = $this->story(); $payload = $this->payload($blog);
        $this->actingAs($owner)->postJson('/admin/blogs/'.$blog->id.'/autosave', $payload)->assertOk();
        $blog->update(['title' => 'Other editor saved this']);
        $this->putJson('/admin/blogs/'.$blog->id, ['title' => 'Stale overwrite', 'category_id' => $blog->category_id, 'status' => 'published', 'sections' => [['text' => 'Stale text']], 'editor_revision' => $payload['base_hash'], 'autosave_version' => 1])->assertConflict();
        $this->assertSame('Other editor saved this', $blog->fresh()->title); $this->assertDatabaseCount('blog_autosaves', 1);
    }
}
