<?php

namespace App\Services\Seo;

use App\Rules\PublicHttpsUrl;
use Illuminate\Validation\Rule;

final class SeoInput
{
    public static function blogRules(): array
    {
        return [
            'is_cornerstone' => 'sometimes|boolean',
            'seo' => 'sometimes|array:title,description,canonical_url,focus_keyphrase,robots_index,robots_follow,og_title,og_description,og_image_url,x_title,x_description,x_image_url,schema_type',
            'seo.title' => 'nullable|string|max:255',
            'seo.description' => 'nullable|string|max:500',
            'seo.canonical_url' => ['nullable', 'string', 'max:2048', new PublicHttpsUrl],
            'seo.focus_keyphrase' => 'nullable|string|max:120',
            'seo.robots_index' => ['nullable', Rule::in(['inherit', 'index', 'noindex'])],
            'seo.robots_follow' => ['nullable', Rule::in(['inherit', 'follow', 'nofollow'])],
            'seo.og_title' => 'nullable|string|max:255',
            'seo.og_description' => 'nullable|string|max:500',
            'seo.og_image_url' => ['nullable', 'string', 'max:2048', new PublicHttpsUrl],
            'seo.x_title' => 'nullable|string|max:255',
            'seo.x_description' => 'nullable|string|max:500',
            'seo.x_image_url' => ['nullable', 'string', 'max:2048', new PublicHttpsUrl],
            'seo.schema_type' => ['nullable', Rule::in(['BlogPosting', 'Article', 'none'])],
            'cover_image_alt' => 'nullable|string|max:500',
            'cover_image_caption' => 'nullable|string|max:2000',
            'cover_image_decorative' => 'sometimes|boolean',
            'sections.*.image_alt' => 'nullable|string|max:500',
            'sections.*.image_caption' => 'nullable|string|max:2000',
            'sections.*.image_decorative' => 'sometimes|boolean',
        ];
    }
}
