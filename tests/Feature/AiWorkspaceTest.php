<?php

namespace Tests\Feature;

use App\Jobs\GenerateAiContent;
use App\Models\AiProfile;
use App\Models\AiProfileVersion;
use App\Models\AiRun;
use App\Models\Blog;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\Claude;
use App\Services\Ai\WebsiteReader;
use App\Services\Ai\WorkspaceAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['ai.enabled' => true, 'ai.key' => 'test-only-key', 'ai.model' => 'test-claude', 'ai.monthly_requests' => 5]);
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::create(['name' => 'Perfume studio', 'slug' => 'perfume-studio', 'owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner, ['role' => 'owner']);
        $this->owner->update(['current_workspace_id' => $this->workspace->id]);
        $this->project = Project::create(['user_id' => $this->owner->id, 'workspace_id' => $this->workspace->id, 'name' => 'Fresh scents', 'api_key' => 'pk_ai_test']);
        $this->actingAs($this->owner);
    }

    private function profile(): void
    {
        $data = ['name' => 'Fresh scents', 'summary' => 'A perfume shop with a citrus discovery collection.', 'audience' => 'First-time fragrance shoppers', 'facts' => 'Three 5 ml samples.'];
        $this->put(route('admin.ai.profile.save', $this->project), ['revision' => 0, 'data' => $data])->assertRedirect();
        $this->post(route('admin.ai.profile.approve', $this->project), ['revision' => 1, 'confirm' => 1])->assertRedirect();
        $this->put(route('admin.ai.settings', $this->project), ['enabled' => 1, 'monthly_limit' => 5])->assertRedirect();
    }

    private function requestRun(array $data = []): AiRun
    {
        $this->post(route('admin.ai.generate', $this->project), $data + ['action' => 'outline', 'request_key' => (string) Str::uuid(), 'brief' => 'Choose a fresh fragrance', 'consent' => 1])->assertRedirect();

        return AiRun::latest('id')->firstOrFail();
    }

    private function complete(AiRun $run, array $result, string $stop = 'end_turn'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => $stop, 'content' => [['type' => 'text', 'text' => json_encode($result)]], 'usage' => ['input_tokens' => 400, 'output_tokens' => 100]], 200, ['request-id' => 'req_test'])]);
        (new GenerateAiContent($run->id))->handle(app(Claude::class), app(WebsiteReader::class), app(WorkspaceAi::class));
    }

    public function test_profile_approval_encryption_and_optimistic_updates(): void
    {
        $this->profile();
        $this->get(route('admin.ai.profile', $this->project))->assertOk()->assertSee('Fresh scents');
        $this->assertStringNotContainsString('citrus', DB::table('ai_profiles')->value('data'));
        $this->put(route('admin.ai.profile.save', $this->project), ['revision' => 0, 'data' => ['name' => 'Bad', 'summary' => 'Bad', 'audience' => 'Bad']])->assertConflict();
        $this->put(route('admin.ai.profile.save', $this->project), ['revision' => 1, 'data' => ['name' => 'Fresh scents', 'summary' => 'Updated description', 'audience' => 'Gift buyers']])->assertRedirect();
        $this->assertSame('First-time fragrance shoppers', app(WorkspaceAi::class)->approved($this->project)->data['audience']);
        $this->post(route('admin.ai.profile.approve', $this->project), ['revision' => 1, 'confirm' => 1])->assertConflict();
    }

    public function test_editors_cannot_change_profile_approve_or_enable_spending_and_outsiders_cannot_view(): void
    {
        $this->profile();
        $editor = User::factory()->create();
        $this->workspace->users()->attach($editor, ['role' => 'editor']);
        $this->actingAs($editor)->put(route('admin.ai.profile.save', $this->project), [])->assertForbidden();
        $this->post(route('admin.ai.profile.approve', $this->project), [])->assertForbidden();
        $this->put(route('admin.ai.settings', $this->project), [])->assertForbidden();
        $this->post(route('admin.ai.generate', $this->project), ['action' => 'analyze', 'request_key' => (string) Str::uuid(), 'source_text' => 'Facts', 'consent' => 1])->assertForbidden();
        $this->get(route('admin.ai.studio', $this->project))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('admin.ai.profile', $this->project))->assertForbidden();
    }

    public function test_disabled_ai_missing_consent_and_unapproved_profiles_do_not_dispatch(): void
    {
        $values = ['action' => 'outline', 'request_key' => (string) Str::uuid(), 'brief' => 'Topic', 'consent' => 1];
        $this->post(route('admin.ai.generate', $this->project), $values)->assertSessionHasErrors('profile');
        $this->profile();
        config(['ai.enabled' => false]);
        $this->post(route('admin.ai.generate', $this->project), $values)->assertUnprocessable();
        config(['ai.enabled' => true]);
        unset($values['consent']);
        $this->post(route('admin.ai.generate', $this->project), $values)->assertSessionHasErrors('consent');
        $this->assertSame(0, AiRun::count());
        Http::assertNothingSent();
    }

    public function test_duplicate_requests_count_once_and_workspace_quota_is_shared(): void
    {
        $this->profile();
        $key = (string) Str::uuid();
        $run = $this->requestRun(['request_key' => $key]);
        $this->requestRun(['request_key' => $key]);
        $this->assertSame(1, AiRun::count());
        $this->assertSame(1, DB::table('ai_usage_months')->value('requests'));
        Queue::assertPushed(GenerateAiContent::class, 1);
        $run->update(['state' => 'failed']);
        $this->put(route('admin.ai.settings', $this->project), ['enabled' => 1, 'monthly_limit' => 1]);
        $this->post(route('admin.ai.generate', $this->project), ['action' => 'outline', 'request_key' => (string) Str::uuid(), 'brief' => 'Different', 'consent' => 1])->assertSessionHasErrors('ai');
    }

    public function test_outline_to_private_draft_is_reviewed_sanitized_and_idempotent(): void
    {
        $this->profile();
        $outline = $this->requestRun();
        $this->complete($outline, ['title' => 'Fresh fragrance', 'outline' => 'Explain scent families, then sampling.', 'warnings' => 'Verify product details.']);
        $outline->refresh();
        $this->assertSame('complete', $outline->state);
        $this->get(route('admin.ai.run', [$this->project, $outline]))->assertOk()->assertSee('Explain scent families');
        $this->put(route('admin.ai.result.save', [$this->project, $outline]), ['revision' => 1, 'result' => $outline->result, 'reviewed' => 1])->assertRedirect();
        $draft = $this->requestRun(['action' => 'draft', 'parent_id' => $outline->id]);
        $this->complete($draft, ['title' => 'Choosing fresh fragrances', 'excerpt' => 'A guide to sampling.', 'body_html' => '<h2>Try a sample</h2><p>Explore preferences.</p><script>alert(1)</script>', 'seo_description' => 'A practical fragrance guide.', 'warnings' => '']);
        $draft->refresh();
        $this->put(route('admin.ai.result.save', [$this->project, $draft]), ['revision' => 1, 'result' => $draft->result, 'reviewed' => 1])->assertRedirect();
        $category = $this->project->categories()->create(['name' => 'Guides', 'slug' => 'guides']);
        foreach ([1, 2] as $i) {
            $this->post(route('admin.ai.draft', [$this->project, $draft]), ['revision' => 2, 'category_id' => $category->id, 'confirm' => 1])->assertRedirect();
        }
        $this->assertSame(1, Blog::count());
        $blog = Blog::first();
        $this->assertSame('draft', $blog->status);
        $this->assertNull($blog->published_at);
        $this->assertStringNotContainsString('<script', $blog->sections->first()->text_content);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.anthropic.com/v1/messages' && $r->hasHeader('Authorization', 'Bearer test-only-key') && $r['model'] === 'test-claude');
    }

    public function test_stale_article_blocks_review_and_foreign_blog_cannot_be_used(): void
    {
        $this->profile();
        $blog = $this->project->blogs()->create(['user_id' => $this->owner->id, 'title' => 'A scent guide', 'slug' => 'scent', 'content' => 'Try samples', 'status' => 'draft']);
        $run = $this->requestRun(['action' => 'social', 'blog_id' => $blog->id, 'platform' => 'reddit']);
        $this->complete($run, ['title' => 'How do you sample?', 'body' => 'I represent Fresh scents. [BLOG_URL]', 'hashtags' => '', 'carousel' => '', 'warnings' => 'Review community rules.']);
        $this->get(route('admin.ai.run', [$this->project, $run]))->assertOk()->assertSee('Community rules were not supplied');
        $blog->update(['title' => 'Changed title']);
        $this->put(route('admin.ai.result.save', [$this->project, $run]), ['revision' => 1, 'result' => $run->fresh()->result, 'reviewed' => 1])->assertConflict();
        $this->get(route('admin.ai.export', [$this->project, $run]))->assertOk()->assertSee('STALE');
        $foreign = Project::create(['user_id' => $this->owner->id, 'name' => 'Different', 'api_key' => 'pk_different']);
        $foreignBlog = $foreign->blogs()->create(['user_id' => $this->owner->id, 'title' => 'Private', 'slug' => 'private', 'content' => '', 'status' => 'draft']);
        $this->post(route('admin.ai.generate', $this->project), ['action' => 'social', 'blog_id' => $foreignBlog->id, 'platform' => 'reddit', 'request_key' => (string) Str::uuid(), 'consent' => 1])->assertNotFound();
    }

    public function test_truncated_and_failed_provider_results_never_become_drafts(): void
    {
        $this->profile();
        $run = $this->requestRun();
        $this->complete($run, ['title' => 'Partial'], 'max_tokens');
        $this->assertSame('failed', $run->fresh()->state);
        $this->assertNull($run->fresh()->result);
        $this->assertSame(0, Blog::count());
        $other = $this->requestRun(['brief' => 'Second topic']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'secret provider detail']], 429)]);
        (new GenerateAiContent($other->id))->handle(app(Claude::class), app(WebsiteReader::class), app(WorkspaceAi::class));
        $this->assertSame('failed', $other->fresh()->state);
        $this->assertStringNotContainsString('secret', $other->fresh()->detail);
    }

    public function test_jobs_recheck_access_and_cancellation_before_provider_call(): void
    {
        $this->profile();
        $run = $this->requestRun();
        $this->workspace->users()->detach($this->owner);
        (new GenerateAiContent($run->id))->handle(app(Claude::class), app(WebsiteReader::class), app(WorkspaceAi::class));
        $this->assertSame('cancelled', $run->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_manual_analysis_requires_review_and_preserves_manual_profile_until_adoption(): void
    {
        $this->profile();
        $run = $this->requestRun(['action' => 'analyze', 'source_text' => 'The citrus discovery set contains three 5 ml samples.']);
        $this->complete($run, ['summary' => 'A citrus discovery shop', 'products' => 'Discovery set', 'audience' => 'New shoppers', 'tone' => 'Friendly', 'facts' => 'Three 5 ml samples (manual-1).', 'source_notes' => 'manual-1: user-provided text, verify before approval.', 'warnings' => 'Shipping unknown.']);
        $run->refresh();
        $this->assertSame('complete', $run->state);
        $this->put(route('admin.ai.result.save', [$this->project, $run]), ['revision' => 1, 'result' => $run->result, 'reviewed' => 1])->assertRedirect();
        $this->post(route('admin.ai.adopt', [$this->project, $run]), ['fields' => ['products', 'facts'], 'confirm' => 1])->assertRedirect();
        $this->assertSame('Discovery set', AiProfile::first()->data['products']);
        $this->assertArrayNotHasKey('products', app(WorkspaceAi::class)->approved($this->project)->data);
        $this->assertSame(2, AiProfile::first()->revision);
    }

    public function test_shared_workspace_allowance_applies_to_a_second_project(): void
    {
        $this->profile();
        $run = $this->requestRun();
        $run->update(['state' => 'failed']);
        $this->put(route('admin.ai.settings', $this->project), ['enabled' => 1, 'monthly_limit' => 1]);
        $other = Project::create(['user_id' => $this->owner->id, 'workspace_id' => $this->workspace->id, 'name' => 'Second publication', 'api_key' => 'pk_second_ai']);
        AiProfileVersion::create(['project_id' => $other->id, 'approved_by' => $this->owner->id, 'revision' => 1, 'data' => ['name' => 'Second', 'summary' => 'Different shop', 'audience' => 'Gift buyers']]);
        $this->post(route('admin.ai.generate', $other), ['action' => 'outline', 'brief' => 'Gift ideas', 'request_key' => (string) Str::uuid(), 'consent' => 1])->assertSessionHasErrors('ai');
        $this->assertSame(1, DB::table('ai_usage_months')->count());
        $this->assertSame(1, AiRun::count());
    }

    public function test_result_edits_use_revision_checks_and_cancellation_never_restarts_work(): void
    {
        $this->profile();
        $run = $this->requestRun();
        $this->complete($run, ['title' => 'Try a scent', 'outline' => 'Explore samples first', 'warnings' => '']);
        $values = ['revision' => 1, 'result' => $run->fresh()->result];
        $this->put(route('admin.ai.result.save', [$this->project, $run]), $values)->assertRedirect();
        $this->put(route('admin.ai.result.save', [$this->project, $run]), $values)->assertConflict();
        $pending = $this->requestRun(['brief' => 'A new brief']);
        $this->post(route('admin.ai.cancel', [$this->project, $pending]))->assertRedirect();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        (new GenerateAiContent($pending->id))->handle(app(Claude::class), app(WebsiteReader::class), app(WorkspaceAi::class));
        Http::assertNothingSent();
        $this->assertSame('cancelled', $pending->fresh()->state);
    }

    public function test_maintenance_retains_content_and_only_expires_abandoned_tasks(): void
    {
        $this->profile();
        $run = $this->requestRun();
        $run->update(['created_at' => now()->subMinutes(31)]);
        $this->artisan('ai:maintain')->assertSuccessful();
        $this->assertSame('failed', $run->fresh()->state);
        $this->assertSame('Choose a fresh fragrance', $run->fresh()->input['brief']);
        $this->assertSame(1, AiRun::count());
    }
}
