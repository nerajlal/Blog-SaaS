<?php

namespace Tests\Feature;

use App\Jobs\FetchSearchReport;
use App\Jobs\InspectSearchUrl;
use App\Models\Project;
use App\Models\SearchConnection;
use App\Models\SearchInspection;
use App\Models\SearchReport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SearchConsole\GoogleSearch;
use App\Services\Seo\SeoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SearchConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['search_console.client_id' => 'test-client', 'search_console.client_secret' => 'test-secret', 'search_console.redirect_uri' => 'https://cms.example.com/admin/search-console/callback']);
    }

    private function tenant(string $slug = 'one'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['owner_id' => $user->id, 'name' => 'Studio', 'slug' => $slug]);
        $workspace->users()->attach($user, ['role' => 'owner']);
        $user->update(['current_workspace_id' => $workspace->id]);
        $project = Project::create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'name' => 'Journal', 'api_key' => 'pk_'.$slug, 'seo_settings' => ['public_url' => 'https://journal.example.com', 'article_path' => '/blog/{slug}']]);
        $project->forceFill(['seo_verified_at' => now()])->save();

        return [$user, $workspace, $project];
    }

    private function connect(Project $project): SearchConnection
    {
        return SearchConnection::create(['project_id' => $project->id, 'user_id' => $project->user_id, 'origin' => 'https://journal.example.com', 'property' => 'sc-domain:example.com', 'access_token' => 'access-private', 'refresh_token' => 'refresh-private', 'expires_at' => now()->addHour()]);
    }

    private function fakeGoogle(): void
    {
        Http::fake(['https://www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [['siteUrl' => 'sc-domain:example.com', 'permissionLevel' => 'siteOwner'], ['siteUrl' => 'https://wrong.example/', 'permissionLevel' => 'siteOwner'], ['siteUrl' => 'sc-domain:journal.example.com', 'permissionLevel' => 'siteUnverifiedUser']]]), 'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => [['keys' => [now('America/Los_Angeles')->subDays(5)->toDateString()], 'clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 3.5]]])]);
    }

    public function test_unconfigured_screen_and_role_boundaries(): void
    {
        [$owner,$workspace,$project] = $this->tenant();
        config(['search_console.client_id' => null]);
        $this->actingAs($owner)->get('/admin/projects/'.$project->id.'/search')->assertOk()->assertSee('Google setup required')->assertSee('No completed report');
        $this->post('/admin/projects/'.$project->id.'/search/connect')->assertStatus(409);
        $editor = User::factory()->create();
        $workspace->users()->attach($editor, ['role' => 'editor']);
        $this->actingAs($editor)->get('/admin/projects/'.$project->id.'/search')->assertForbidden();
        $this->post('/admin/projects/'.$project->id.'/search/disconnect')->assertForbidden();
        [$other] = $this->tenant('other');
        $this->actingAs($other)->get('/admin/projects/'.$project->id.'/search')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_oauth_state_is_single_use_user_bound_and_tokens_are_encrypted(): void
    {
        [$owner,,$project] = $this->tenant();
        $response = $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/search/connect')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(GoogleSearch::SCOPE, $query['scope']);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'private-access', 'refresh_token' => 'private-refresh', 'expires_in' => 3600, 'scope' => GoogleSearch::SCOPE])]);
        $this->get('/admin/search-console/callback?'.http_build_query(['state' => $query['state'], 'code' => 'sample-code']))->assertRedirect('/admin/projects/'.$project->id.'/search');
        $connection = SearchConnection::firstOrFail();
        $this->assertSame('private-refresh', $connection->refresh_token);
        $this->assertStringNotContainsString('private-refresh', DB::table('search_connections')->value('refresh_token'));
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->get('/admin/search-console/callback?'.http_build_query(['state' => $query['state'], 'code' => 'sample-code']))->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_oauth_refuses_origin_changes_and_denied_authorization(): void
    {
        [$owner,,$project] = $this->tenant();
        $response = $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/search/connect');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $project->forceFill(['seo_verified_at' => null])->save();
        $this->get('/admin/search-console/callback?'.http_build_query(['state' => $query['state'], 'code' => 'sample-code']))->assertStatus(409);
        Http::assertNothingSent();
        $project->forceFill(['seo_verified_at' => now()])->save();
        $response = $this->post('/admin/projects/'.$project->id.'/search/connect');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->get('/admin/search-console/callback?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied']))->assertRedirect()->assertSessionHasErrors('google');
        $this->assertDatabaseCount('search_connections', 0);
    }

    public function test_only_accessible_origin_covering_properties_can_be_selected(): void
    {
        [$owner,,$project] = $this->tenant();
        $connection = $this->connect($project);
        $this->fakeGoogle();
        $properties = app(GoogleSearch::class)->properties($connection);
        $this->assertCount(1, $properties);
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/search/property', ['property' => 'https://wrong.example/'])->assertStatus(422);
        $this->post('/admin/projects/'.$project->id.'/search/property', ['property' => 'sc-domain:example.com'])->assertRedirect();
        $service = app(GoogleSearch::class);
        $this->assertTrue($service->covers('sc-domain:example.com', 'https://journal.example.com'));
        $this->assertFalse($service->covers('sc-domain:example.com', 'https://evil-example.com'));
        $this->assertFalse($service->covers('https://journal.example.com/sub/', 'https://journal.example.com'));
    }

    public function test_report_fetch_is_queued_origin_filtered_and_totals_are_weighted(): void
    {
        [$owner,,$project] = $this->tenant();
        $connection = $this->connect($project);
        $this->fakeGoogle();
        Queue::fake();
        $dates = ['start_date' => now('America/Los_Angeles')->subDays(30)->toDateString(), 'end_date' => now('America/Los_Angeles')->subDays(3)->toDateString()];
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/search/fetch', $dates)->assertRedirect();
        Queue::assertPushed(FetchSearchReport::class);
        $report = SearchReport::firstOrFail();
        (new FetchSearchReport($report->id))->handle(app(GoogleSearch::class));
        $this->assertSame('complete', $report->fresh()->state);
        $this->assertSame(10, $report->fresh()->rows[0]['clicks']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'searchAnalytics/query') && $r['aggregationType'] === 'auto' && $r['dataState'] === 'final' && str_contains($r['dimensionFilterGroups'][0]['filters'][0]['expression'], 'journal'));
        $response = $this->get('/admin/projects/'.$project->id.'/search')->assertOk()->assertSee('10.00%');
        $this->assertEquals(3.5, $response->viewData('totals')['position']);
        $report->refresh()->update(['rows' => [['clicks' => 10, 'ctr' => .1, 'date' => now('America/Los_Angeles')->subDays(5)->toDateString(), 'impressions' => 100, 'position' => 3.5]]]);
        $export = $this->get('/admin/projects/'.$project->id.'/search/reports/'.$report->id)->assertOk()->assertDownload('search-report-'.$report->id.'.csv');
        $csvLines = explode("\n", trim($export->streamedContent()));
        $this->assertSame([now('America/Los_Angeles')->subDays(5)->toDateString(), '10', '100', '0.1', '3.5'], array_slice(str_getcsv($csvLines[1]), 6));
        $this->post('/admin/projects/'.$project->id.'/search/fetch', $dates)->assertStatus(429);
    }

    public function test_pending_jobs_cancel_after_origin_change_and_report_export_is_scoped(): void
    {
        [$owner,,$project] = $this->tenant();
        $connection = $this->connect($project);
        $report = SearchReport::create(['project_id' => $project->id, 'connection_id' => $connection->id, 'property' => $connection->property, 'origin' => $connection->origin, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10']);
        $project->forceFill(['seo_verified_at' => null])->save();
        (new FetchSearchReport($report->id))->handle(app(GoogleSearch::class));
        $this->assertSame('cancelled', $report->fresh()->state);
        Http::assertNothingSent();
        [$other,,$foreign] = $this->tenant('other');
        $this->connect($foreign);
        $this->actingAs($other)->get('/admin/projects/'.$foreign->id.'/search/reports/'.$report->id)->assertNotFound();
    }

    public function test_refresh_and_disconnect_delete_local_secrets_even_if_revocation_fails(): void
    {
        [$owner,,$project] = $this->tenant();
        $connection = $this->connect($project);
        $connection->update(['expires_at' => now()->subMinute()]);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'refreshed', 'expires_in' => 3600, 'scope' => GoogleSearch::SCOPE]), 'https://oauth2.googleapis.com/revoke' => Http::response([], 503)]);
        $this->assertSame('refreshed', app(GoogleSearch::class)->access($connection));
        $this->assertSame('refresh-private', $connection->fresh()->refresh_token);
        SearchReport::create(['project_id' => $project->id, 'connection_id' => $connection->id, 'property' => $connection->property, 'origin' => $connection->origin, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10']);
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/search/disconnect')->assertRedirect()->assertSessionHas('success', fn ($s) => str_contains($s, 'revocation failed'));
        $this->assertDatabaseCount('search_connections', 0);
        $this->assertDatabaseCount('search_reports', 0);
    }

    public function test_inspection_is_saved_only_for_eligible_project_story_and_is_not_submission(): void
    {
        [$owner,,$project] = $this->tenant();
        $connection = $this->connect($project);
        $blog = $project->blogs()->create(['user_id' => $owner->id, 'title' => 'Story', 'slug' => 'story', 'content' => '', 'status' => 'published']);
        Queue::fake();
        $this->fakeGoogle();
        Http::fake(['https://www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => [['siteUrl' => $connection->property, 'permissionLevel' => 'siteOwner']]]), 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect' => Http::response(['inspectionResult' => ['indexStatusResult' => ['verdict' => 'PASS', 'coverageState' => 'Submitted and indexed', 'ignored' => 'not stored']]])]);
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/search/inspect', ['blog_id' => $blog->id])->assertRedirect();
        Queue::assertPushed(InspectSearchUrl::class);
        $item = SearchInspection::firstOrFail();
        (new InspectSearchUrl($item->id))->handle(app(GoogleSearch::class), app(SeoResolver::class));
        $this->assertSame('complete', $item->fresh()->state);
        $this->assertSame('PASS', $item->fresh()->result['verdict']);
        $this->assertArrayNotHasKey('ignored', $item->fresh()->result);
        $this->get('/admin/projects/'.$project->id.'/search')->assertOk()->assertSee('not a live test');
        $this->post('/admin/projects/'.$project->id.'/search/inspect',['blog_id' => $blog->id])->assertStatus(429);
    }
    public function test_empty_results_and_retention_do_not_invent_zero_traffic(): void
    {
        [$owner,,$project]=$this->tenant();$connection=$this->connect($project);
        $this->fakeGoogle();Http::fake(['https://www.googleapis.com/webmasters/v3/sites'=>Http::response(['siteEntry'=>[['siteUrl'=>$connection->property,'permissionLevel'=>'siteOwner']]]),'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query'=>Http::response([])]);
        $report=SearchReport::create(['project_id'=>$project->id,'connection_id'=>$connection->id,'origin'=>$connection->origin,'property'=>$connection->property,'start_date'=>'2026-10-01','end_date'=>'2026-10-10']);
        (new FetchSearchReport($report->id))->handle(app(GoogleSearch::class));
        $response=$this->actingAs($owner)->get('/admin/projects/'.$project->id.'/search')->assertOk()->assertSee('does not prove zero traffic');
        $this->assertNull($response->viewData('totals')['position']);
        $this->travel(91)->days();try{$this->artisan('seo:prune-search')->assertSuccessful();$this->assertDatabaseCount('search_reports',0);$this->assertDatabaseCount('search_connections',1);}finally{$this->travelBack();}
    }
    public function test_inaccessible_properties_fail_without_persisting_report_data(): void
    {
        [,,$project]=$this->tenant();$connection=$this->connect($project);
        $report=SearchReport::create(['project_id'=>$project->id,'connection_id'=>$connection->id,'origin'=>$connection->origin,'property'=>$connection->property,'start_date'=>'2026-10-01','end_date'=>'2026-10-10']);
        Http::fake(['https://www.googleapis.com/webmasters/v3/sites'=>Http::response(['siteEntry'=>[]])]);
        try{(new FetchSearchReport($report->id))->handle(app(GoogleSearch::class));$this->fail('Inaccessible property accepted');}catch(\Illuminate\Validation\ValidationException){$this->assertNull($report->fresh()->rows);}
        Http::assertSentCount(1);
        (new FetchSearchReport($report->id))->failed(null);$this->assertSame('failed',$report->fresh()->state);
    }

}
