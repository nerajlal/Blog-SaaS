<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Seo\DomainVerification;
use App\Services\Seo\SeoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $suffix = 'one'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create(['owner_id' => $owner->id, 'name' => 'Studio', 'slug' => $suffix]);
        $workspace->users()->attach($owner, ['role' => 'owner']);
        $owner->update(['current_workspace_id' => $workspace->id]);
        $project = Project::create(['user_id' => $owner->id, 'workspace_id' => $workspace->id, 'name' => 'Studio Journal', 'api_key' => 'pk_'.$suffix]);
        $category = $project->categories()->create(['name' => 'Notes', 'slug' => 'notes']);
        return [$owner, $workspace, $project, $category];
    }

    private function settings(): array
    {
        return ['public_url' => 'https://journal.example.com', 'article_path' => '/blog/{slug}', 'site_name' => 'Studio Journal', 'title_template' => '{title} | {site_name}', 'description' => '', 'locale' => 'en-IN', 'publisher_type' => 'Organization', 'publisher_name' => 'Studio', 'index' => '1', 'follow' => '1'];
    }

    private function configure(Project $project, bool $verified = true): void
    {
        $project->seo_settings = $this->settings();
        $project->seo_domain_token = 'verification-token';
        $project->seo_verified_at = $verified ? now() : null;
        $project->save();
    }

    private function story(Project $project, string $slug = 'a-new-chapter', array $extra = []): Blog
    {
        $blog = $project->blogs()->create(array_merge(['user_id' => $project->user_id, 'title' => 'A new chapter', 'slug' => $slug, 'status' => 'published', 'published_at' => now()->subDay(), 'content' => '', 'author_name' => 'Jamie'], $extra));
        $blog->sections()->create(['text_content' => '<p>A story with <strong>meaning</strong>.</p><p>Another paragraph.</p>', 'order' => 0]);
        return $blog;
    }

    public function test_owners_can_save_defaults_and_editors_cannot_change_project_identity(): void
    {
        [$owner, $workspace, $project] = $this->tenant();
        $this->actingAs($owner)->get('/admin/projects/'.$project->id.'/seo')->assertOk();
        $this->put('/admin/projects/'.$project->id.'/seo', $this->settings())->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNotEmpty($project->fresh()->seo_domain_token);
        $this->assertNull($project->fresh()->seo_verified_at);
        $editor = User::factory()->create(['current_workspace_id' => $workspace->id]);
        $workspace->users()->attach($editor, ['role' => 'editor']);
        $this->actingAs($editor)->put('/admin/projects/'.$project->id.'/seo', $this->settings())->assertForbidden();
        $this->get('/admin/projects/'.$project->id.'/seo')->assertForbidden();
    }

    public function test_unrelated_tenant_cannot_change_or_verify_seo(): void
    {
        [, , $project] = $this->tenant();
        [$other] = $this->tenant('two');
        $this->actingAs($other)->put('/admin/projects/'.$project->id.'/seo', $this->settings())->assertForbidden();
        $this->post('/admin/projects/'.$project->id.'/seo/verify')->assertForbidden();
    }

    public function test_origin_templates_and_unsafe_urls_are_validated(): void
    {
        [$owner, , $project] = $this->tenant();
        $this->actingAs($owner);
        foreach (['http://example.com', 'https://127.0.0.1', 'https://localhost', 'https://user:pass@example.com', 'https://example.com/path', 'https://example.com?x=1', 'https://example.com:444', 'https://example.internal'] as $url) {
            $this->put('/admin/projects/'.$project->id.'/seo', array_merge($this->settings(), ['public_url' => $url]))->assertSessionHasErrors('public_url');
        }
        foreach (['//{slug}', '/blog/{slug}?x=1', '/{slug}/{slug}', '/blog/../{slug}', '/blog/no-slug'] as $path) {
            $this->put('/admin/projects/'.$project->id.'/seo', array_merge($this->settings(), ['article_path' => $path]))->assertSessionHasErrors('article_path');
        }
        $this->put('/admin/projects/'.$project->id.'/seo', array_merge($this->settings(), ['title_template' => '{unknown}']))->assertSessionHasErrors('title_template');
    }

    public function test_domain_changes_reset_verification_and_dns_failure_does_not_verify(): void
    {
        [$owner, , $project] = $this->tenant();
        $this->configure($project);
        $this->actingAs($owner)->put('/admin/projects/'.$project->id.'/seo', array_merge($this->settings(), ['public_url' => 'https://new.example.com']))->assertSessionHasNoErrors();
        $this->assertNull($project->fresh()->seo_verified_at);
        $this->assertNotSame('verification-token', $project->fresh()->seo_domain_token);
        $mock = \Mockery::mock(DomainVerification::class);
        $mock->shouldReceive('matches')->once()->andReturnFalse();
        $this->app->instance(DomainVerification::class, $mock);
        $this->post('/admin/projects/'.$project->id.'/seo/verify')->assertSessionHasErrors('domain');
        $this->assertNull($project->fresh()->seo_verified_at);
    }

    public function test_matching_dns_verifies_domain_and_unchanged_origin_keeps_verification(): void
    {
        [$owner, , $project] = $this->tenant();
        $this->configure($project, false);
        $mock = \Mockery::mock(DomainVerification::class);
        $mock->shouldReceive('matches')->once()->andReturnTrue();
        $this->app->instance(DomainVerification::class, $mock);
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/seo/verify')->assertSessionHasNoErrors();
        $this->assertNotNull($project->fresh()->seo_verified_at);
        $this->put('/admin/projects/'.$project->id.'/seo', $this->settings())->assertSessionHasNoErrors();
        $this->assertNotNull($project->fresh()->seo_verified_at);
    }

    public function test_resolver_inherits_templates_and_builds_a_connected_schema_graph(): void
    {
        [, , $project] = $this->tenant();
        $this->configure($project);
        $blog = $this->story($project);
        $seo = app(SeoResolver::class)->resolve($blog);
        $this->assertSame('A new chapter | Studio Journal', $seo['title']);
        $this->assertSame('A story with meaning. Another paragraph.', $seo['description']);
        $this->assertSame('https://journal.example.com/blog/a-new-chapter', $seo['canonical_url']);
        $this->assertCount(5, $seo['schema']['@graph']);
        $this->assertSame('BlogPosting', $seo['schema']['@graph'][4]['@type']);
        $this->assertSame('Jamie', $seo['schema']['@graph'][4]['author']['name']);
        $this->assertSame($seo['canonical_url'].'#webpage', $seo['schema']['@graph'][4]['mainEntityOfPage']['@id']);
    }

    public function test_unverified_domains_do_not_emit_canonical_schema_or_sitemaps(): void
    {
        [, , $project] = $this->tenant();
        $this->configure($project, false);
        $this->story($project);
        $response = $this->getJson('/api/v1/projects/blogs', ['X-API-KEY' => $project->api_key]);
        $response->assertOk()->assertJsonPath('blogs.0.seo.canonical_url', null)->assertJsonPath('blogs.0.seo.schema', []);
        $this->getJson('/api/v1/projects/sitemap.xml', ['X-API-KEY' => $project->api_key])->assertStatus(409);
    }

    public function test_public_api_resolves_overrides_and_never_leaks_private_seo_or_user_data(): void
    {
        [, , $project] = $this->tenant();
        $this->configure($project);
        $this->story($project, 'overrides', ['cover_image' => '/storage/blogs/banners/Test.jpg', 'cover_image_alt' => 'A quiet room', 'seo_settings' => ['title' => 'Search title', 'description' => 'A custom description.', 'focus_keyphrase' => 'private editorial keyword', 'robots_index' => 'noindex', 'og_title' => 'Sharing title', 'x_title' => 'X title']]);
        $response = $this->getJson('/api/v1/projects/blogs/overrides', ['X-API-KEY' => $project->api_key]);
        $response->assertOk()->assertJsonPath('blog.seo.title', 'Search title')->assertJsonPath('blog.seo.robots.index', false)->assertJsonPath('blog.seo.open_graph.title', 'Sharing title')->assertJsonPath('blog.seo.twitter.title', 'X title');
        $this->assertStringNotContainsString('private editorial keyword', $response->getContent());
        $this->assertStringNotContainsString('verification-token', $response->getContent());
        $response->assertJsonMissingPath('blog.seo_settings')->assertJsonMissingPath('blog.user')->assertJsonMissingPath('blog.project');
        $this->assertStringEndsWith('/storage/blogs/banners/Test.jpg', $response->json('blog.cover_image_url'));
    }

    public function test_single_post_endpoint_hides_drafts_future_and_foreign_posts(): void
    {
        [, , $project] = $this->tenant();
        [, , $otherProject] = $this->tenant('two');
        $this->story($project, 'draft', ['status' => 'draft']);
        $this->story($project, 'future', ['published_at' => now()->addHour()]);
        $this->story($otherProject, 'foreign');
        foreach (['draft', 'future', 'foreign'] as $slug) {
            $this->getJson('/api/v1/projects/blogs/'.$slug, ['X-API-KEY' => $project->api_key])->assertNotFound();
        }
        $this->getJson('/api/v1/projects/blogs/foreign')->assertUnauthorized();
    }

    public function test_sitemap_contains_only_live_indexable_self_canonical_article_urls(): void
    {
        [, , $project] = $this->tenant();
        $this->configure($project);
        $this->story($project, 'live');
        $this->story($project, 'draft', ['status' => 'draft']);
        $this->story($project, 'future', ['published_at' => now()->addHour()]);
        $this->story($project, 'hidden', ['seo_settings' => ['robots_index' => 'noindex']]);
        $this->story($project, 'syndicated', ['seo_settings' => ['canonical_url' => 'https://other.example.com/original']]);
        $response = $this->get('/api/v1/projects/sitemap.xml', ['X-API-KEY' => $project->api_key]);
        $response->assertOk()->assertHeader('X-Sitemap-Pages', '1');
        $xml = simplexml_load_string($response->getContent());
        $this->assertCount(1, $xml->url);
        $this->assertSame('https://journal.example.com/blog/live', (string) $xml->url->loc);
        $this->assertStringNotContainsString('/api/', $response->getContent());
        $this->assertStringNotContainsString($project->api_key, $response->getContent());
        $blog = $project->blogs()->where('slug', 'live')->first();
        $blog->update(['status' => 'draft']);
        $this->assertStringNotContainsString('/blog/live', $this->get('/api/v1/projects/sitemap.xml', ['X-API-KEY' => $project->api_key])->getContent());
    }

    public function test_editor_saves_metadata_and_media_descriptions_and_retains_old_input(): void
    {
        [$owner, , $project, $category] = $this->tenant();
        $data = ['title' => 'A story', 'category_id' => $category->id, 'status' => 'draft', 'cover_image_alt' => 'Cover description', 'cover_image_caption' => 'Cover caption', 'seo' => ['title' => 'A search title', 'focus_keyphrase' => 'writing'], 'sections' => [['text' => '<p>Body</p>', 'image_alt' => 'Section description', 'image_caption' => 'Section caption', 'image_decorative' => '0']]];
        $this->actingAs($owner)->post('/admin/projects/'.$project->id.'/blogs', $data)->assertSessionHasNoErrors()->assertRedirect();
        $blog = Blog::firstOrFail();
        $this->assertSame('A search title', $blog->seo_settings['title']);
        $this->assertSame('Cover description', $blog->cover_image_alt);
        $this->assertSame('Section caption', $blog->sections()->first()->image_caption);
        $this->get('/admin/blogs/'.$blog->id.'/edit')->assertOk()->assertSee('A search title')->assertSee('Section caption');
        $data['seo']['canonical_url'] = 'javascript:alert(1)';
        $this->put('/admin/blogs/'.$blog->id, $data)->assertSessionHasErrors('seo.canonical_url');
        $this->get('/admin/blogs/'.$blog->id.'/edit')->assertOk()->assertSee('Section caption');
        $this->assertSame('A search title', $blog->fresh()->seo_settings['title']);
    }

    public function test_metadata_and_domain_state_survive_legacy_updates_without_seo_inputs(): void
    {
        [$owner, , $project, $category] = $this->tenant();
        $blog = $this->story($project, 'legacy', ['seo_settings' => ['title' => 'Keep this SEO title']]);
        $this->actingAs($owner)->put('/admin/blogs/'.$blog->id, ['title' => 'Renamed', 'category_id' => $category->id, 'status' => 'published', 'sections' => [['text' => '<p>Changed</p>']]])->assertSessionHasNoErrors();
        $this->assertSame('Keep this SEO title', $blog->fresh()->seo_settings['title']);
    }
}
