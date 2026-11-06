<?php

namespace App\Services\Editor;

use App\Models\Blog;

final class BlogRevision
{
    public function hash(Blog $blog): string
    {
        // Read sections afresh; ordering, moves, metadata and every saved section matter.
        return hash('sha256', json_encode([
            $blog->getAttributes(),
            $blog->sections()->get()->map(fn ($section) => $section->getAttributes())->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
