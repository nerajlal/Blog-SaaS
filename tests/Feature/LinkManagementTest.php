<?php
namespace Tests\Feature;

use App\Models\{Blog, Project, User, Workspace, SeoLink, SeoLinkCheck, SeoLinkEdit, SeoChangeBatch, SeoNotFound};
use App\Services\Links\{LinkGraph, RedirectManager};
use App\Services\Seo\ContentAnalyzer;
use App\Jobs\{BuildLinkGraph, CheckPublicLink};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LinkManagementTest extends TestCase
{
    use RefreshDatabase;
    private function tenant(string $slug = 'one'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['owner_id' => $owner->id, 'name' => 'Studio', 'slug' => $slug]);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $owner->update(['current_workspace_id' => $workspace->id]);
        $project = Project::create(['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'name' => 'Journal', 'api_key' => 'pk_'.$slug, 'seo_settings' => ['public_url' => 'https://journal.example.com', 'article_path' => '/blog/{slug}', 'locale' => 'en']]);
        $project->forceFill(['seo_verified_at' => now()])->save();
        return [$owner,$workspace,$project];
    }
    private function story(Project $project, string $slug, string $html = '<p>Garden planning guide.</p>'): Blog
    {
        $blog = $project->blogs()->create(['user_id' => $project->user_id, 'title' => 'Garden planning '.$slug, 'slug' => $slug, 'content' => '', 'status' => 'published', 'published_at' => now()->subDay()]);
        $blog->sections()->create(['text_content' => $html, 'order' => 0]); return $blog->fresh();
    }
    private function build(Project $project)
    {
        $graph = app(LinkGraph::class); $run = $graph->start($project->fresh());
        while ($run->status === 'building') $run = $graph->step($run);
        return $run;
    }
    public function test_graph_resolves_relative_links_deduplicates_and_invalidates_on_section_edits(): void
    {
        [$owner,,$project] = $this->tenant();
        $target = $this->story($project, 'target');
        $source = $this->story($project, 'source', '<p><a href="/blog/target#one">Target</a><a href="https://journal.example.com/blog/target#two">Again</a><a href="https://example.com/docs">External</a></p>');
        $run = $this->build($project); $this->assertSame('complete', $run->status);
        $this->assertDatabaseHas('seo_links', ['blog_id' => $source->id, 'target_blog_id' => $target->id, 'occurrences' => 2, 'kind' => 'internal']);
        $response = $this->actingAs($owner)->get('/admin/projects/'.$project->id.'/links')->assertOk();
        $this->assertSame([$source->id], $response->viewData('orphans')->pluck('id')->all());
        $source->sections->first()->update(['text_content' => '<p>Changed.</p>']);
        $this->assertNull(app(LinkGraph::class)->ready($project->fresh()));
        $this->get('/admin/projects/'.$project->id.'/links')->assertOk()->assertSee('No current index');
    }
    public function test_drafts_do_not_prevent_orphan_detection_and_moves_do_not_leak_links(): void
    {
        [$owner,,$project] = $this->tenant(); [,,$other] = $this->tenant('other');
        $target = $this->story($project, 'target');
        $draft = $this->story($project, 'draft', '<a href="/blog/target">Target</a>'); $draft->update(['status' => 'draft']);
        $this->build($project);
        $response = $this->actingAs($owner)->get('/admin/projects/'.$project->id.'/links')->assertOk();
        $this->assertSame([$target->id], $response->viewData('orphans')->pluck('id')->all());
        $draft->update(['project_id' => $other->id]);
        $this->assertNull(app(LinkGraph::class)->ready($project->fresh()));
    }
    public function test_suggestions_are_scoped_and_explicit_insert_and_guarded_undo_work(): void
    {
        [$owner,,$project] = $this->tenant();
        $source = $this->story($project, 'source'); $target = $this->story($project, 'target');
        $hidden = $this->story($project, 'hidden'); $hidden->update(['seo_settings' => ['robots_index' => 'noindex']]);
        $items = app(LinkGraph::class)->suggestions($source->fresh())['items'];
        $this->assertSame([$target->id], array_column(array_column($items, 'blog'), 'id'));
        $hash = app(ContentAnalyzer::class)->fingerprint($source->fresh());
        $this->actingAs($owner)->post('/admin/blogs/'.$source->id.'/links/insert', ['input_hash' => $hash, 'target_id' => $target->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $source->sections()->count()); $edit = SeoLinkEdit::firstOrFail();
        $this->post('/admin/blogs/'.$source->id.'/links/insert', ['input_hash' => $hash, 'target_id' => $target->id])->assertStatus(409);
        $this->post('/admin/blogs/'.$source->id.'/links/'.$edit->id.'/undo')->assertRedirect();
        $this->assertSame(1, $source->sections()->count()); $this->assertNotNull($edit->fresh()->reverted_at);
        $this->post('/admin/blogs/'.$source->id.'/links/'.$edit->id.'/undo')->assertStatus(409);
    }
    public function test_modified_inserted_section_cannot_be_undone(): void
    {
        [$owner,,$project] = $this->tenant(); $source = $this->story($project, 'source'); $target = $this->story($project, 'target');
        $this->actingAs($owner)->post('/admin/blogs/'.$source->id.'/links/insert', ['input_hash' => app(ContentAnalyzer::class)->fingerprint($source), 'target_id' => $target->id])->assertRedirect();
        $edit = SeoLinkEdit::firstOrFail(); $source->sections()->find($edit->section_id)->update(['text_content' => '<p>My revised paragraph.</p>']);
        $this->post('/admin/blogs/'.$source->id.'/links/'.$edit->id.'/undo')->assertStatus(409);
    }
    public function test_permission_boundaries_and_queued_checks_accept_only_current_project_links(): void
    {
        [$owner,$workspace,$project] = $this->tenant(); [$otherOwner,,$other] = $this->tenant('other');
        $source = $this->story($project, 'source', '<a href="https://example.com/a">Link</a>'); $this->build($project);
        $foreign = $this->story($other, 'foreign', '<a href="https://example.com/b">Link</a>'); $this->build($other);
        $link = SeoLink::where('blog_id', $source->id)->firstOrFail(); $foreignLink = SeoLink::where('blog_id', $foreign->id)->firstOrFail();
        Queue::fake();
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/links/check', ['link_ids' => [$foreignLink->id]])->assertNotFound();
        $this->post('/admin/projects/'.$project->id.'/links/check', ['link_ids' => [$link->id]])->assertRedirect();
        Queue::assertPushed(CheckPublicLink::class, 1);
        $this->post('/admin/projects/'.$project->id.'/links/check', ['link_ids' => [$link->id]])->assertRedirect(); Queue::assertPushed(CheckPublicLink::class, 1);
        $this->post('/admin/projects/'.$project->id.'/links/rebuild')->assertRedirect(); Queue::assertPushed(BuildLinkGraph::class);
        $member = User::factory()->create(); $workspace->users()->attach($member, ['role' => 'member']);
        $this->actingAs($member)->get('/admin/projects/'.$project->id.'/links')->assertOk();
        $this->get('/admin/blogs/'.$source->id.'/links')->assertOk();
        $this->post('/admin/projects/'.$project->id.'/links/rebuild')->assertForbidden();
        $this->post('/admin/projects/'.$project->id.'/links/check', ['link_ids' => [$link->id]])->assertForbidden();
        $this->get('/admin/projects/'.$project->id.'/redirects')->assertForbidden();
        $this->actingAs($otherOwner)->get('/admin/projects/'.$project->id.'/links')->assertForbidden();
    }
    public function test_redirect_preview_apply_lookup_undo_and_conflict_guard(): void
    {
        [$owner,,$project] = $this->tenant(); $manager = app(RedirectManager::class);
        $data = ['source_path' => '/old', 'target_path' => '/blog/new', 'status_code' => 301, 'reason' => 'Moved to a clearer URL.'];
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/redirects/preview', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('seo_redirects', 0); $batch = SeoChangeBatch::firstOrFail();
        $this->get('/admin/projects/'.$project->id.'/redirects')->assertOk()->assertSee('Apply reviewed preview');
        $this->post('/admin/projects/'.$project->id.'/redirects/'.$batch->id.'/apply')->assertRedirect();
        $this->getJson('/api/v1/projects/redirect?path=%2Fold%3Fx%3Dsecret', ['X-API-KEY' => $project->api_key])->assertOk()->assertJsonPath('redirect.status', 301)->assertJsonPath('redirect.location', '/blog/new');
        $this->getJson('/api/v1/projects/redirect?path=%2Fold')->assertUnauthorized();
        $this->post('/admin/projects/'.$project->id.'/redirects/'.$batch->id.'/undo')->assertRedirect();
        $this->assertNull($manager->lookup($project, '/old'));
        $second = $manager->preview($project, [$data], $owner->id, 'A fresh preview.');
        $third = $manager->preview($project, [['source_path' => '/gone', 'status_code' => 410]], $owner->id, 'Removed permanently.');
        $manager->commit($project, $third, false, $owner->id);
        $this->post('/admin/projects/'.$project->id.'/redirects/'.$second->id.'/apply')->assertStatus(409);
        $this->assertSame(410, $manager->lookup($project, '/gone')['status']);
    }
    public function test_redirects_reject_live_sources_external_targets_reserved_paths_chains_and_cycles(): void
    {
        [$owner,,$project] = $this->tenant(); $this->story($project, 'live');
        $this->actingAs($owner);
        foreach ([['/blog/live','/new'],['/a','https://evil.example/x'],['/robots.txt','/new'],['/a','//evil.example'],['/a','/a'],['/a','/%2fprivate']] as [$source,$target]) {
            $this->postJson('/admin/projects/'.$project->id.'/redirects/preview', ['source_path' => $source, 'target_path' => $target, 'status_code' => 301, 'reason' => 'Testing protections.'])->assertUnprocessable();
        }
        $manager = app(RedirectManager::class); $batch = $manager->preview($project, [['source_path' => '/a', 'target_path' => '/b', 'status_code' => 301]], $owner->id, 'Moved article.'); $manager->commit($project,$batch,false,$owner->id);
        $this->postJson('/admin/projects/'.$project->id.'/redirects/preview', ['source_path' => '/b', 'target_path' => '/c', 'status_code' => 301, 'reason' => 'Would create a chain.'])->assertUnprocessable();
        $project->forceFill(['seo_verified_at' => null])->save(); $this->assertNull($manager->lookup($project, '/a'));
    }
    public function test_telemetry_needs_separate_secret_deduplicates_and_strips_queries(): void
    {
        [$owner,,$project] = $this->tenant(); $secret = Str::random(64); $project->forceFill(['telemetry_key_hash' => hash('sha256',$secret)])->save();
        $data = ['event_id' => (string) Str::uuid(), 'origin' => 'https://journal.example.com', 'path' => '/missing?token=private#secret', 'referrer' => 'https://example.com/start?private=secret'];
        $this->postJson('/api/v1/projects/not-found', $data, ['X-API-KEY' => $project->api_key])->assertUnauthorized();
        $headers = ['X-Telemetry-Key' => $secret];
        $this->postJson('/api/v1/projects/not-found', $data, $headers)->assertNoContent(); $this->postJson('/api/v1/projects/not-found', $data, $headers)->assertNoContent();
        $this->assertDatabaseHas('seo_404_events', ['path' => '/missing', 'hits' => 1, 'referrer' => 'https://example.com/start']);
        $this->postJson('/api/v1/projects/not-found', array_merge($data, ['event_id' => (string) Str::uuid()]), $headers)->assertNoContent();
        $this->assertSame(2, SeoNotFound::first()->hits);
        $this->postJson('/api/v1/projects/not-found', array_merge($data, ['origin' => 'https://wrong.example.com']), $headers)->assertUnprocessable();
        $this->actingAs($owner)->get('/admin/projects/'.$project->id.'/not-found')->assertOk()->assertSee('/missing')->assertDontSee('token=private');
        $this->post('/admin/projects/'.$project->id.'/not-found/key')->assertRedirect()->assertSessionHas('telemetry_key');
        $this->postJson('/api/v1/projects/not-found', $data, $headers)->assertUnauthorized();
        $this->assertArrayNotHasKey('telemetry_key_hash', $project->toArray());
        $this->travel(91)->days(); $this->artisan('seo:prune-links')->assertSuccessful(); $this->assertDatabaseCount('seo_404_events', 0); $this->assertDatabaseCount('seo_404_receipts', 0); $this->travelBack();
    }
    public function test_csv_redirect_import_export_and_removal_have_reviewable_previews(): void
    {
        [$owner,,$project]=$this->tenant();
        $csv=\Illuminate\Http\UploadedFile::fake()->createWithContent('redirects.csv', "source_path,target_path,status_code\n/old,/new,308\n/gone,,410\n");
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/redirects/preview',['csv'=>$csv,'reason'=>'Importing reviewed URL migrations.'])->assertRedirect()->assertSessionHasNoErrors();
        $batch=SeoChangeBatch::firstOrFail();
        $this->assertDatabaseCount('seo_redirects',0);
        $this->post('/admin/projects/'.$project->id.'/redirects/'.$batch->id.'/apply')->assertRedirect();
        $this->get('/admin/projects/'.$project->id.'/redirects/export')->assertOk()->assertDownload('redirects.csv');
        $this->post('/admin/projects/'.$project->id.'/redirects/remove',['source_path'=>'/old','reason'=>'This old rule is no longer needed.'])->assertRedirect();
        $removal=SeoChangeBatch::latest('id')->first();
        $this->assertCount(1,$removal->changes['after']);
        $this->post('/admin/projects/'.$project->id.'/redirects/'.$removal->id.'/apply')->assertRedirect();
        $this->assertNull(app(RedirectManager::class)->lookup($project,'/old'));
        $this->post('/admin/projects/'.$project->id.'/redirects/'.$removal->id.'/undo')->assertRedirect();
        $this->assertSame(308,app(RedirectManager::class)->lookup($project,'/old')['status']);
    }
    public function test_metadata_csv_preview_apply_undo_and_intervening_edit_protection(): void
    {
        [$owner,,$project]=$this->tenant();$blog=$this->story($project,'source');
        $csv=\Illuminate\Http\UploadedFile::fake()->createWithContent('metadata.csv', "blog_id,title,description,focus_keyphrase,robots_index,robots_follow\n".$blog->id.",A clearer SEO title,Useful description,garden planning,noindex,follow\n");
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/metadata/preview',['csv'=>$csv,'reason'=>'Updating search appearance for this story.'])->assertRedirect()->assertSessionHasNoErrors();
        $batch=SeoChangeBatch::firstOrFail();$this->assertEmpty($blog->fresh()->seo_settings);
        $this->get('/admin/projects/'.$project->id.'/metadata')->assertOk()->assertSee('A clearer SEO title');
        $this->post('/admin/projects/'.$project->id.'/metadata/'.$batch->id.'/apply')->assertRedirect();
        $this->assertSame('A clearer SEO title',$blog->fresh()->seo_settings['title']);
        $this->assertSame('Garden planning source',$blog->fresh()->title);
        $this->get('/admin/projects/'.$project->id.'/metadata/export')->assertOk()->assertDownload('metadata-page-1.csv');
        $this->post('/admin/projects/'.$project->id.'/metadata/'.$batch->id.'/undo')->assertRedirect();$this->assertEmpty($blog->fresh()->seo_settings);
        $manager=app(\App\Services\Links\MetadataBatch::class);
        $row=['blog_id'=>$blog->id,'title'=>'New title','description'=>'','focus_keyphrase'=>'','robots_index'=>'inherit','robots_follow'=>'inherit'];
        $fresh=$manager->preview($project,[$row],$owner->id,'Another metadata change.');
        $blog->update(['title'=>'Intervening edit']);
        $this->post('/admin/projects/'.$project->id.'/metadata/'.$fresh->id.'/apply')->assertStatus(409);
        $fresh=$manager->preview($project,[$row],$owner->id,'Another metadata change.');$manager->commit($project,$fresh,false,$owner->id);
        $blog->refresh()->update(['seo_settings'=>['title'=>'Editor changed this']]);
        $this->post('/admin/projects/'.$project->id.'/metadata/'.$fresh->id.'/undo')->assertStatus(409);
        $bad=\Illuminate\Http\UploadedFile::fake()->createWithContent('bad.csv',"wrong,header\n1,2\n");
        $this->post('/admin/projects/'.$project->id.'/metadata/preview',['csv'=>$bad,'reason'=>'Invalid import check.'])->assertSessionHasErrors('csv');
    }

    public function test_link_check_job_updates_only_the_current_token(): void
    {
        [,,$project]=$this->tenant();$check=SeoLinkCheck::create(['project_id'=>$project->id,'url_hash'=>hash('sha256','https://example.com/a'),'url'=>'https://example.com/a','state'=>'pending','token'=>(string)Str::uuid()]);
        $checker=\Mockery::mock(\App\Services\Links\SafeLinkChecker::class);
        $checker->shouldReceive('check')->once()->with($check->url)->andReturn(['state'=>'broken','http_status'=>404,'final_url'=>$check->url,'detail'=>'Missing.','retryable'=>false]);
        (new CheckPublicLink($check->id,(string)Str::uuid()))->handle($checker);
        $this->assertSame('pending',$check->fresh()->state);
        (new CheckPublicLink($check->id,$check->token))->handle($checker);
        $this->assertSame('broken',$check->fresh()->state);$this->assertNotNull($check->fresh()->checked_at);
    }
    public function test_graph_command_and_partial_extraction_are_explicit(): void
    {
        [,,$project]=$this->tenant();$blog=$this->story($project,'source','<a href="http://127.0.0.1/private">Unsupported</a>');
        $this->artisan('seo:links',['--project'=>$project->id])->expectsOutputToContain('partial')->assertSuccessful();
        $run=app(LinkGraph::class)->ready($project->fresh());$this->assertSame(1,$run->omitted);
        $project->forceFill(['seo_verified_at'=>null])->save();
        $this->artisan('seo:links',['--project'=>$project->id])->assertFailed();
    }
    public function test_json_snapshot_equality_ignores_key_order_but_not_value_type_or_text(): void
    {
        $state=\App\Services\Links\LinkState::class;
        $this->assertTrue($state::same(['a'=>1,'b'=>['x'=>2,'y'=>3]],['b'=>['y'=>3,'x'=>2],'a'=>1]));
        $this->assertFalse($state::same(['a'=>'01'],['a'=>'1']));
        $this->assertFalse($state::same(['a'=>1],['a'=>'1']));
    }

}
