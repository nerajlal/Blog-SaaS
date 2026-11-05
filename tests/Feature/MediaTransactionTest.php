<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\BlogSection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaTransactionTest extends TestCase
{
    use DatabaseMigrations;

    private function setupPost(): array
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $project = Project::create(['user_id' => $owner->id, 'name' => 'Legacy journal', 'api_key' => 'pk_test']);
        $category = $project->categories()->create(['name' => 'Notes', 'slug' => 'notes']);
        $blog = $project->blogs()->create(['user_id' => $owner->id, 'title' => 'Original', 'slug' => 'original', 'content' => '', 'status' => 'published', 'published_at' => now()->subDay()]);
        Storage::disk('public')->put('blogs/sections/OldImage.png', 'old image');
        $blog->sections()->create(['text_content' => '<p>Original text</p>', 'image_path' => '/storage/blogs/sections/OldImage.png', 'order' => 0]);
        $this->actingAs($owner);
        return [$blog, $category];
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('new.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a2ioAAAAASUVORK5CYII='));
    }

    public function test_updates_save_uploaded_images_and_remove_superseded_files_after_commit(): void
    {
        [$blog, $category] = $this->setupPost();
        $this->put('/admin/blogs/'.$blog->id, ['title' => 'New title', 'category_id' => $category->id, 'status' => 'published', 'sections' => [['text' => '<p>New text</p>', 'image' => $this->image()]]])->assertRedirect();
        $section = $blog->sections()->firstOrFail();
        $this->assertSame('<p>New text</p>', $section->text_content);
        $this->assertSame('original', $blog->fresh()->slug);
        Storage::disk('public')->assertExists(substr($section->image_path, strlen('/storage/')));
        Storage::disk('public')->assertMissing('blogs/sections/OldImage.png');
    }

    public function test_failed_section_creation_rolls_back_content_and_removes_new_uploads(): void
    {
        [$blog, $category] = $this->setupPost();
        BlogSection::creating(fn () => throw new \RuntimeException('Simulated database failure'));
        $this->withoutExceptionHandling();
        try {
            $this->put('/admin/blogs/'.$blog->id, ['title' => 'Should roll back', 'category_id' => $category->id, 'status' => 'draft', 'sections' => [['text' => 'Replacement', 'image' => $this->image()]]]);
            $this->fail('The simulated failure was not raised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated database failure', $exception->getMessage());
        } finally {
            BlogSection::flushEventListeners();
        }
        $this->assertSame('Original', $blog->fresh()->title);
        $this->assertSame('<p>Original text</p>', $blog->sections()->firstOrFail()->text_content);
        $this->assertSame(['blogs/sections/OldImage.png'], Storage::disk('public')->allFiles());
    }
}
