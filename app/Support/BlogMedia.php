<?php

namespace App\Support;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class BlogMedia
{
    private array $uploaded = [];

    public static function transaction(Closure $callback): mixed
    {
        $media = new self;
        try {
            return DB::transaction(fn () => $callback($media));
        } catch (Throwable $exception) {
            foreach ($media->uploaded as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $exception;
        }
    }

    public function store(UploadedFile $file, string $directory): string
    {
        $path = $file->store($directory, 'public');
        if (!$path) {
            throw new RuntimeException('The image could not be stored. Please try again.');
        }
        $this->uploaded[] = $path;
        return $path;
    }

    public static function removeAfterCommit(array $urls): void
    {
        DB::afterCommit(function () use ($urls) {
            foreach (array_unique(array_filter($urls)) as $url) {
                // Only remove generated files in the blog media directories.
                if (preg_match('~^/storage/(blogs/(?:banners|avatars|sections)/[A-Za-z0-9]+\.(?:jpe?g|png|webp|gif))$~i', $url, $match)) {
                    Storage::disk('public')->delete($match[1]);
                }
            }
        });
    }
}
