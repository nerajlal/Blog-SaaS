<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Seo\ContentAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $suffix = 'one'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['owner_id' => $owner->id, 'name' => 'Studio', 'slug' => $suffix]);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $owner->update(['current_workspace_id' => $workspace->id]);
        $project = Project::create(['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'name' => 'Studio Journal', 'api_key' => 'pk_'.$suffix, 'seo_settings' => ['locale' => 'en', 'site_name' => 'Studio Journal']]);
        $category = $project->categories()->create(['name' => 'Notes', 'slug' => 'notes']);
        return [$owner, $workspace, $project, $category];
    }

    private function story(Project $project, string $slug = 'garden-planning'): Blog
    {
        $blog = $project->blogs()->create(['user_id' => $project->user_id, 'title' => 'Garden planning for a small balcony', 'slug' => $slug, 'status' => 'published', 'published_at' => now()->subDay(), 'content' => '', 'seo_settings' => ['title' => 'Garden planning for a small balcony', 'description' => 'Garden planning can make a small balcony a welcoming outdoor space. Explore practical choices for plants, light, and daily care.', 'focus_keyphrase' => 'garden planning']]);
        $blog->sections()->create(['text_content' => '<p>Garden planning makes room for a little greenery. Start with light and space.</p><h2>Garden planning with purpose</h2><p>Choose suitable plants for your balcony. Read a <a href="https://example.com/guide">helpful guide</a> before buying pots.</p>', 'order' => 0]);
        return $blog;
    }

    private function finding(array $result, string $id, string $group = 'seo'): array
    {
        return collect($result[$group]['findings'])->firstWhere('id', $id);
    }

    private function draft(Blog $blog): array
    {
        return ['blog_id' => $blog->id, 'title' => $blog->title, 'cover_image_present' => (bool) $blog->cover_image, 'seo' => $blog->seo_settings, 'is_cornerstone' => false, 'sections' => $blog->sections->map(fn ($s) => ['text' => $s->text_content, 'image_present' => (bool) $s->image_path, 'existing_image' => $s->image_path, 'image_alt' => $s->image_alt, 'image_decorative' => $s->image_decorative])->all()];
    }

    public function test_separate_scores_are_explainable_and_repeated_capture_is_idempotent(): void
    {
        [, , $project] = $this->tenant();
        $blog = $this->story($project);
        $engine = app(ContentAnalyzer::class);
        $first = $engine->capture($blog);
        $second = $engine->capture($blog);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('seo_analyses', 1);
        $this->assertSame(100, $first->result['seo']['score']);
        $this->assertIsInt($first->result['readability']['score']);
        $this->assertSame('pass', $this->finding($first->result, 'keyword_intro')['status']);
        $this->assertSame('not_applicable', $this->finding($first->result, 'image_alt')['status']);
        $this->assertNotEmpty($first->result['notes']);
    }

    public function test_all_sanitized_sections_are_analyzed_without_scripts_or_editor_markup(): void
    {
        [, , $project] = $this->tenant();
        $blog = $this->story($project);
        $blog->sections()->delete();
        $blog->sections()->create(['text_content' => '<p>First section.</p><script>hidden garbage words here</script>', 'order' => 0]);
        $blog->sections()->create(['text_content' => '<h2>Garden planning</h2><p>Second section with <strong>meaning</strong>.</p>', 'order' => 1]);
        $result = app(ContentAnalyzer::class)->analyze($blog->fresh());
        $this->assertSame(8, $result['metrics']['word_count']);
        $this->assertSame(1, $result['metrics']['heading_count']);
        $this->assertSame('pass', $this->finding($result, 'keyword_headings')['status']);
    }

    public function test_empty_markup_has_no_body_or_readability_score(): void
    {
        [, , $project] = $this->tenant();
        $blog = $this->story($project);
        $blog->sections()->update(['text_content' => '<p><br></p>']);
        $result = app(ContentAnalyzer::class)->analyze($blog->fresh());
        $this->assertSame(0, $result['metrics']['word_count']);
        $this->assertSame('warning', $this->finding($result, 'body_present')['status']);
        $this->assertNull($result['readability']['score']);
    }

    public function test_non_english_checks_are_unavailable_not_failures(): void
    {
        [, , $project] = $this->tenant();
        $project->update(['seo_settings' => ['locale' => 'fr']]);
        $result = app(ContentAnalyzer::class)->analyze($this->story($project));
        $this->assertNull($result['readability']['score']);
        $this->assertNull($result['metrics']['word_count']);
        $this->assertSame('unavailable', $this->finding($result, 'keyword_title')['status']);
        $this->assertSame('unavailable', $this->finding($result, 'title_length')['status']);
        $this->assertSame('pass', $this->finding($result, 'body_present')['status']);
        $this->assertIsInt($result['seo']['score']);
    }

    public function test_duplicate_titles_and_keyphrases_are_scoped_to_the_project(): void
    {
        [, , $project] = $this->tenant();
        [, , $other] = $this->tenant('two');
        $blog = $this->story($project);
        $this->story($other, 'foreign');
        $engine = app(ContentAnalyzer::class);
        $this->assertSame(0, $engine->analyze($blog->fresh())['metrics']['duplicate_titles']);
        $this->story($project, 'same-project');
        $result = $engine->analyze($blog->fresh());
        $this->assertSame(1, $result['metrics']['duplicate_titles']);
        $this->assertSame(1, $result['metrics']['duplicate_keyphrases']);
        $this->assertSame('warning', $this->finding($result, 'duplicate_title')['status']);
    }

    public function test_image_descriptions_distinguish_missing_from_decorative(): void
    {
        [, , $project] = $this->tenant();
        $blog = $this->story($project);
        $blog->update(['cover_image' => '/storage/cover.jpg']);
        $engine = app(ContentAnalyzer::class);
        $this->assertSame('warning', $this->finding($engine->analyze($blog->fresh()), 'image_alt')['status']);
        $blog->update(['cover_image_decorative' => true]);
        $this->assertSame('pass', $this->finding($engine->analyze($blog->fresh()), 'image_alt')['status']);
    }

    public function test_missing_keyphrase_skips_keyword_checks_and_never_requires_stuffing(): void
    {
        [, , $project] = $this->tenant();
        $blog = $this->story($project);
        $blog->update(['seo_settings' => []]);
        $result = app(ContentAnalyzer::class)->analyze($blog);
        $this->assertSame('not_applicable', $this->finding($result, 'keyword_intro')['status']);
        $this->assertSame('warning', $this->finding($result, 'focus_present')['status']);
        $this->assertStringNotContainsString('density', json_encode($result));
    }

    public function test_content_settings_rules_and_duplicate_context_invalidate_snapshots(): void
    {
        [, , $project] = $this->tenant();
        $blog = $this->story($project);
        $engine = app(ContentAnalyzer::class);
        $snapshot = $engine->capture($blog);
        $this->assertTrue($engine->isCurrent($blog->fresh(), $snapshot));
        $blog->sections()->update(['text_content' => '<p>Different text.</p>']);
        $this->assertFalse($engine->isCurrent($blog->fresh(), $snapshot));
        $snapshot = $engine->capture($blog->fresh());
        $project->refresh()->update(['seo_settings' => ['locale' => 'en', 'title_template' => '{title} — New brand']]);
        $this->assertFalse($engine->isCurrent($blog->fresh(), $snapshot));
        $snapshot = $engine->capture($blog->fresh());
        $this->story($project, 'new-content');
        $this->assertFalse($engine->isCurrent($blog->fresh(), $snapshot));
        $snapshot = $engine->capture($blog->fresh());
        $snapshot->rules_version = 'old-rules';
        $this->assertFalse($engine->isCurrent($blog->fresh(), $snapshot));
    }

    public function test_preview_is_private_scoped_and_does_not_write_content_or_snapshots(): void
    {
        [$owner, $workspace, $project] = $this->tenant();
        $blog = $this->story($project);
        $data = $this->draft($blog); $data['title'] = 'Unsaved draft changes';
        $this->actingAs($owner)->postJson('/admin/projects/'.$project->id.'/seo/analyze', $data)->assertOk()->assertJsonPath('analysis.rules_version', ContentAnalyzer::VERSION);
        $this->assertSame('Garden planning for a small balcony', $blog->fresh()->title);
        $this->assertDatabaseCount('seo_analyses', 0);
        $member = User::factory()->create(); $workspace->users()->attach($member, ['role' => 'member']);
        $this->actingAs($member)->postJson('/admin/projects/'.$project->id.'/seo/analyze', $data)->assertForbidden();
        [$other] = $this->tenant('two');
        $this->actingAs($other)->get('/admin/projects/'.$project->id.'/seo/health')->assertForbidden();
        $this->get('/admin/blogs/'.$blog->id.'/seo/report')->assertForbidden();
    }

    public function test_preview_rejects_foreign_blog_ids_and_matches_saved_images(): void
    {
        [$owner, , $project] = $this->tenant();
        [, , $other] = $this->tenant('two');
        $blog = $this->story($project); $foreign = $this->story($other, 'foreign');
        $this->actingAs($owner)->postJson('/admin/projects/'.$project->id.'/seo/analyze', $this->draft($foreign))->assertNotFound();
        $blog->update(['cover_image' => '/storage/cover.jpg', 'cover_image_alt' => 'A garden']);
        $blog->sections()->first()->update(['image_path' => '/storage/section.jpg', 'image_alt' => 'A green leaf']);
        app(ContentAnalyzer::class)->capture($blog);
        $data = $this->draft($blog->fresh()); $data['cover_image_alt'] = 'A garden';
        $this->postJson('/admin/projects/'.$project->id.'/seo/analyze', $data)->assertOk()->assertJsonPath('saved_revision', true);
    }

    public function test_publishing_low_scoring_content_still_succeeds_and_captures_a_report(): void
    {
        [$owner, , $project, $category] = $this->tenant();
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/blogs', ['title' => 'Short', 'category_id' => $category->id, 'status' => 'published', 'is_cornerstone' => '1', 'sections' => [['text' => '<p><br></p>']]])->assertRedirect()->assertSessionHasNoErrors();
        $blog = Blog::firstOrFail();
        $this->assertSame('published', $blog->status);
        $this->assertTrue($blog->is_cornerstone);
        $this->assertNotNull($blog->latestSeoAnalysis);
        $this->assertLessThan(100, $blog->latestSeoAnalysis->result['seo']['score']);
        $this->getJson('/api/v1/projects/blogs', ['X-API-KEY' => $project->api_key])->assertOk()->assertJsonMissingPath('blogs.0.seo_title_key')->assertJsonMissingPath('blogs.0.is_cornerstone')->assertJsonMissingPath('blogs.0.content_reviewed_at')->assertJsonMissingPath('blogs.0.latest_seo_analysis');
    }

    public function test_review_decisions_are_audited_do_not_change_scores_and_expire_with_revision(): void
    {
        [$owner, , $project] = $this->tenant();
        $blog = $this->story($project); $blog->update(['seo_settings' => []]);
        $engine = app(ContentAnalyzer::class); $snapshot = $engine->capture($blog);
        $data = ['input_hash' => $snapshot->input_hash, 'rule_id' => 'focus_present', 'action' => 'dismiss', 'reason' => 'This short editorial intentionally has no target keyword.'];
        $this->actingAs($owner)->post('/admin/blogs/'.$blog->id.'/seo/review', $data)->assertRedirect()->assertSessionHasNoErrors();
        $result = $engine->withReviews($blog, $snapshot->result);
        $this->assertSame($data['reason'], $this->finding($result, 'focus_present')['review']['reason']);
        $this->assertSame($snapshot->result['seo']['score'], $result['seo']['score']);
        $this->assertDatabaseHas('seo_reviews', ['blog_id' => $blog->id, 'user_id' => $owner->id, 'action' => 'dismiss']);
        $this->post('/admin/blogs/'.$blog->id.'/seo/review', array_merge($data, ['reason' => '']))->assertSessionHasErrors('reason');
        $blog->update(['title' => 'A changed story']);
        $this->post('/admin/blogs/'.$blog->id.'/seo/review', $data)->assertStatus(409);
        $fresh = $engine->capture($blog);
        $result = $engine->withReviews($blog, $fresh->result);
        $this->assertNull($this->finding($result, 'focus_present')['review']);
        $this->assertDatabaseCount('seo_reviews', 1);
    }

    public function test_read_only_members_can_read_reports_but_cannot_review_or_refresh(): void
    {
        [, $workspace, $project] = $this->tenant();
        $blog = $this->story($project); app(ContentAnalyzer::class)->capture($blog);
        $member = User::factory()->create(); $workspace->users()->attach($member, ['role' => 'member']);
        $this->actingAs($member)->get('/admin/projects/'.$project->id.'/seo/health')->assertOk();
        $this->get('/admin/blogs/'.$blog->id.'/seo/report')->assertOk()->assertDontSee('Save decision');
        $this->post('/admin/blogs/'.$blog->id.'/seo/refresh')->assertForbidden();
        $this->post('/admin/blogs/'.$blog->id.'/seo/review', [])->assertForbidden();
    }

    public function test_mark_reviewed_preserves_public_modification_date_and_clears_stale_flag(): void
    {
        [$owner, , $project] = $this->tenant();
        $blog = $this->story($project);
        Blog::withoutTimestamps(fn () => Blog::whereKey($blog->id)->update(['updated_at' => now()->subDays(120), 'content_reviewed_at' => null]));
        $blog->refresh(); $before = $blog->updated_at->toDateTimeString();
        $this->assertTrue(app(ContentAnalyzer::class)->reviewDue($blog));
        $this->actingAs($owner)->post('/admin/blogs/'.$blog->id.'/seo/mark-reviewed')->assertRedirect();
        $this->assertSame($before, $blog->fresh()->updated_at->toDateTimeString());
        $this->assertFalse(app(ContentAnalyzer::class)->reviewDue($blog->fresh()));
    }

    public function test_health_filters_and_bounded_refresh_never_cross_projects(): void
    {
        [$owner, , $project] = $this->tenant();
        [, , $other] = $this->tenant('two');
        $blog = $this->story($project); $duplicate = $this->story($project, 'duplicate'); $foreign = $this->story($other, 'foreign');
        $blog->update(['is_cornerstone' => true, 'cover_image' => '/storage/cover.jpg']);
        $this->actingAs($owner)->get('/admin/projects/'.$project->id.'/seo/health?filter=duplicate_titles')->assertOk()->assertSee('Garden planning');
        $this->get('/admin/projects/'.$project->id.'/seo/health?filter=missing_alt')->assertOk()->assertSee('Garden planning');
        $this->get('/admin/projects/'.$project->id.'/seo/health?filter=cornerstone')->assertOk()->assertSee('Garden planning');
        $this->get('/admin/projects/'.$project->id.'/seo/health?filter=missing_metadata')->assertOk();
        $this->post('/admin/projects/'.$project->id.'/seo/refresh-page', ['blog_ids' => [$blog->id, $foreign->id]])->assertNotFound();
        $this->assertDatabaseCount('seo_analyses', 0);
        $this->post('/admin/projects/'.$project->id.'/seo/refresh-page', ['blog_ids' => [$blog->id, $duplicate->id]])->assertRedirect();
        $this->assertDatabaseCount('seo_analyses', 2);
    }

    public function test_oversized_articles_are_unavailable_without_silent_truncation(): void
    {
        [, , $project] = $this->tenant();
        $blog = $this->story($project);
        $blog->sections()->update(['text_content' => '<p>'.str_repeat('word ', 110000).'</p>']);
        $result = app(ContentAnalyzer::class)->analyze($blog->fresh());
        $this->assertNull($result['seo']['score']);
        $this->assertNull($result['readability']['score']);
        $this->assertSame('unavailable', $this->finding($result, 'analysis_limit')['status']);
    }

    public function test_backfill_command_builds_reports_without_changing_public_dates(): void
    {
        [, , $project] = $this->tenant();
        $blog = $this->story($project); $before = $blog->updated_at->toDateTimeString();
        Blog::withoutTimestamps(fn () => Blog::whereKey($blog->id)->update(['seo_title_key' => null, 'seo_focus_key' => null]));
        $this->artisan('seo:analyze', ['--project' => $project->id])->assertSuccessful();
        $this->assertNotNull($blog->fresh()->seo_title_key);
        $this->assertSame($before, $blog->fresh()->updated_at->toDateTimeString());
        $this->assertDatabaseCount('seo_analyses', 1);
        $this->artisan('seo:analyze', ['--project' => 9999])->assertFailed();
    }
    public function test_time_expiry_moves_old_reports_into_needs_analysis(): void
    {
        [$owner, , $project] = $this->tenant();
        $blog = $this->story($project);
        $engine = app(ContentAnalyzer::class);
        $snapshot = $engine->capture($blog);
        $this->travel(91)->days();
        try {
            $this->assertFalse($engine->isCurrent($blog->fresh(), $snapshot));
            $response = $this->actingAs($owner)->get('/admin/projects/'.$project->id.'/seo/health?filter=needs_analysis')->assertOk()->assertSee($blog->title);
            $this->assertSame(0, $response->viewData('stats')['analyzed']);
            $this->post('/admin/blogs/'.$blog->id.'/seo/mark-reviewed')->assertRedirect();
            $fresh = $blog->fresh();
            $this->assertTrue($engine->isCurrent($fresh, $fresh->latestSeoAnalysis));
            $this->assertNotSame($snapshot->id, $fresh->latestSeoAnalysis->id);
        } finally {
            $this->travelBack();
        }
    }

    public function test_review_restore_and_html_reasons_are_safe(): void
    {
        [$owner, , $project] = $this->tenant();
        $blog = $this->story($project); $blog->update(['seo_settings' => []]);
        $engine = app(ContentAnalyzer::class); $snapshot = $engine->capture($blog);
        $data = ['input_hash' => $snapshot->input_hash, 'rule_id' => 'focus_present', 'action' => 'dismiss', 'reason' => '<script>alert("unsafe")</script>'];
        $this->actingAs($owner)->post('/admin/blogs/'.$blog->id.'/seo/review', $data)->assertRedirect();
        $this->get('/admin/blogs/'.$blog->id.'/seo/report')->assertOk()->assertDontSee('<script>alert("unsafe")</script>', false);
        $this->post('/admin/blogs/'.$blog->id.'/seo/review', array_merge($data, ['action' => 'restore', 'reason' => 'This advice should be considered again.']))->assertRedirect();
        $this->assertNull($this->finding($engine->withReviews($blog, $snapshot->result), 'focus_present')['review']);
        $this->post('/admin/blogs/'.$blog->id.'/seo/review', array_merge($data, ['rule_id' => 'invented']))->assertStatus(422);
        $this->assertDatabaseCount('seo_reviews', 2);
    }

    public function test_moving_a_story_does_not_expose_source_project_review_history(): void
    {
        [$owner, , $project] = $this->tenant();
        [$targetOwner, , $target] = $this->tenant('two');
        $blog = $this->story($project); $blog->update(['seo_settings' => []]);
        $engine = app(ContentAnalyzer::class); $snapshot = $engine->capture($blog);
        $reason = 'Confidential editorial note for the original workspace only.';
        $this->actingAs($owner)->post('/admin/blogs/'.$blog->id.'/seo/review', ['rule_id' => 'focus_present', 'input_hash' => $snapshot->input_hash, 'action' => 'dismiss', 'reason' => $reason])->assertRedirect();
        $blog->update(['project_id' => $target->id]);
        $this->assertFalse($engine->isCurrent($blog->fresh(), $snapshot));
        $this->actingAs($targetOwner)->get('/admin/blogs/'.$blog->id.'/seo/report')->assertOk()->assertDontSee($reason)->assertSee('This story has no saved analysis yet.');
        $engine->capture($blog);
        $this->get('/admin/blogs/'.$blog->id.'/seo/report')->assertOk()->assertDontSee($reason);
        $this->assertDatabaseHas('seo_reviews', ['project_id' => $project->id, 'reason' => $reason]);
        $this->assertNotSame($engine->contextHash($project->fresh()), $engine->contextHash($target->fresh()));
    }

}
