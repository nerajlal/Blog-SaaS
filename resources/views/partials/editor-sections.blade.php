@php
    $savedSections = isset($blog) ? $blog->sections->map(fn ($section) => ['text' => $section->text_content, 'existing_image' => $section->image_path, 'image_alt' => $section->image_alt, 'image_caption' => $section->image_caption, 'image_decorative' => $section->image_decorative])->all() : [];
    $editorSections = array_values(old('sections', $savedSections) ?: [['text' => '']]);
@endphp
<div id="sections-container" class="space-y-6">
    <div class="flex items-center justify-between gap-3">
        <h3 class="text-sm font-bold text-slate-800">Story sections</h3>
        <button type="button" onclick="addSection()" class="text-sm font-semibold text-brand-600">+ Add section</button>
    </div>
    <p class="text-xs text-slate-500">Add text and images. Images: JPG, PNG, GIF, or WebP, up to 2 MB. Publication times use {{ config('app.timezone') }}.</p>
    @foreach($editorSections as $index => $section)
    <div class="section-block bg-white border border-slate-200 rounded-2xl p-6 relative shadow-sm">
        <button type="button" onclick="removeSection(this)" class="absolute top-3 right-4 text-slate-400 hover:text-red-500" aria-label="Remove section">×</button>
        <label for="editor-{{ $index }}" class="block text-xs font-bold text-slate-700 mb-3">Section text</label>
        <div class="quill-editor bg-white rounded-xl" id="editor-{{ $index }}" style="min-height:150px">{!! \App\Support\RichText::clean($section['text'] ?? '') !!}</div>
        <input type="hidden" name="sections[{{ $index }}][text]" id="input-{{ $index }}" value="{{ $section['text'] ?? '' }}">
        @if(!empty($section['existing_image']) && in_array($section['existing_image'], array_column($savedSections, 'existing_image'), true))
            <img src="{{ $section['existing_image'] }}" alt="{{ ($section['image_decorative'] ?? false) ? '' : ($section['image_alt'] ?? 'Current section image') }}" class="h-24 rounded-lg mt-4">
            <input type="hidden" name="sections[{{ $index }}][existing_image]" value="{{ $section['existing_image'] }}">
        @endif
        <label class="block mt-4 text-xs font-medium text-slate-600">Section image (optional)
            <input type="file" name="sections[{{ $index }}][image]" accept="image/jpeg,image/png,image/gif,image/webp" class="block mt-2 text-sm w-full">
        </label>
        <div class="mt-4 grid sm:grid-cols-2 gap-4">
            <label class="text-xs font-medium text-slate-600">Image alt text<input name="sections[{{ $index }}][image_alt]" maxlength="500" value="{{ $section['image_alt'] ?? '' }}" class="block mt-2 w-full border border-slate-300 rounded-lg p-3 text-sm"></label>
            <label class="text-xs font-medium text-slate-600">Image caption<input name="sections[{{ $index }}][image_caption]" maxlength="2000" value="{{ $section['image_caption'] ?? '' }}" class="block mt-2 w-full border border-slate-300 rounded-lg p-3 text-sm"></label>
            <label class="text-xs text-slate-600 sm:col-span-2"><input type="hidden" name="sections[{{ $index }}][image_decorative]" value="0"><input type="checkbox" name="sections[{{ $index }}][image_decorative]" value="1" @checked($section['image_decorative'] ?? false)> Decorative image (empty alt text)</label>
        </div>
    </div>
    @endforeach
</div>
