@extends('layouts.app')

@section('title', 'Edit Blog - Blog Admin Panel')

@push('styles')
<style>
    /* Quill CSS */
    @import url('https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css');
    
    /* Custom styles for file inputs */
    input[type="file"] {
        display: none;
    }
    .file-upload-wrapper {
        display: flex;
        align-items: center;
        border: 1px solid #e2e8f0;
        border-radius: 0.5rem;
        padding: 0.5rem;
        background-color: #f8fafc;
    }
    .file-upload-btn {
        background-color: #ecfdf5;
        color: #059669;
        padding: 0.375rem 0.75rem;
        border-radius: 0.375rem;
        font-size: 0.75rem;
        font-weight: 700;
        cursor: pointer;
        transition: background-color 0.2s;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .file-upload-btn:hover {
        background-color: #d1fae5;
    }
    .file-name {
        margin-left: 1rem;
        font-size: 0.875rem;
        font-weight: 500;
        color: #0f172a;
    }
    
    /* Toggle Switch */
    .toggle-checkbox:checked {
        right: 0;
        border-color: #059669;
    }
    .toggle-checkbox:checked + .toggle-label {
        background-color: #059669;
    }

    /* Custom Quill Picker Styles */
    .ql-snow .ql-picker.ql-lineHeight .ql-picker-label::before,
    .ql-snow .ql-picker.ql-lineHeight .ql-picker-item::before { content: 'Line Height'; }
    .ql-snow .ql-picker.ql-lineHeight .ql-picker-label[data-value="1"]::before,
    .ql-snow .ql-picker.ql-lineHeight .ql-picker-item[data-value="1"]::before { content: '1.0'; }
    .ql-snow .ql-picker.ql-lineHeight .ql-picker-label[data-value="1.5"]::before,
    .ql-snow .ql-picker.ql-lineHeight .ql-picker-item[data-value="1.5"]::before { content: '1.5'; }
    .ql-snow .ql-picker.ql-lineHeight .ql-picker-label[data-value="2"]::before,
    .ql-snow .ql-picker.ql-lineHeight .ql-picker-item[data-value="2"]::before { content: '2.0'; }

    .ql-snow .ql-picker.ql-letterSpacing .ql-picker-label::before,
    .ql-snow .ql-picker.ql-letterSpacing .ql-picker-item::before { content: 'Spacing'; }
    .ql-snow .ql-picker.ql-letterSpacing .ql-picker-label[data-value="normal"]::before,
    .ql-snow .ql-picker.ql-letterSpacing .ql-picker-item[data-value="normal"]::before { content: 'Normal'; }
    .ql-snow .ql-picker.ql-letterSpacing .ql-picker-label[data-value="1px"]::before,
    .ql-snow .ql-picker.ql-letterSpacing .ql-picker-item[data-value="1px"]::before { content: '1px'; }
    .ql-snow .ql-picker.ql-letterSpacing .ql-picker-label[data-value="2px"]::before,
    .ql-snow .ql-picker.ql-letterSpacing .ql-picker-item[data-value="2px"]::before { content: '2px'; }
    
    .ql-toolbar.ql-snow .ql-picker.ql-lineHeight { width: 95px; }
    .ql-toolbar.ql-snow .ql-picker.ql-letterSpacing { width: 85px; }
</style>
@endpush

@section('header_left')
    <a href="{{ route('admin.project-details', $project->id) }}" class="mr-4 text-slate-400 hover:text-brand-600 transition-colors">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
    </a>
    <div class="flex flex-col">
        <span class="text-xs text-brand-600 font-bold uppercase tracking-wider">{{ $project->name }}</span>
        <h1 class="text-xl font-extrabold text-slate-900 tracking-tight uppercase">Edit Blog</h1>
    </div>
@endsection

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="bg-white text-slate-800 rounded-3xl overflow-hidden shadow-sm border border-slate-200">
        <div class="py-6 px-4 sm:py-10 sm:px-12">
            
            <!-- Header Section inside the form -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-8 gap-4">
                <div>
                    <h2 class="text-2xl font-extrabold text-slate-900 tracking-tight uppercase">Edit Blog Post</h2>
                    <p class="text-slate-500 text-sm mt-1 font-medium">Update the details of your blog post</p>
                </div>
                <a href="{{ route('admin.project-details', $project->id) }}" class="inline-flex items-center justify-center px-4 py-2 bg-slate-50 hover:bg-slate-100 text-slate-700 text-sm font-bold uppercase tracking-wider rounded-full transition-colors border border-slate-200">
                    Back to List
                </a>
            </div>

            <!-- Main Form -->
            <form action="{{ route('admin.blogs.update', $blog->id) }}" method="POST" enctype="multipart/form-data" class="bg-slate-50 rounded-2xl border border-slate-200 p-4 sm:p-8 shadow-inner">
                @csrf
                @method('PUT')
                
                <div class="space-y-10">
                    
                    @if ($errors->any())
                        <div class="p-4 bg-red-50 border border-red-200 rounded-xl">
                            <ul class="text-sm text-red-600 font-medium space-y-1">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <!-- Post Title and Category Row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-extrabold text-slate-800 uppercase tracking-widest mb-3">Post Title</label>
                            <input type="text" name="title" value="{{ old('title', $blog->title) }}" placeholder="Enter a catchy title..." required class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition-shadow text-base font-medium">
                        </div>
                        <div>
                            <label class="block text-xs font-extrabold text-slate-800 uppercase tracking-widest mb-3">Category</label>
                            <select name="category_id" required class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition-shadow text-base font-medium appearance-none">
                                <option value="" disabled selected>Select a category...</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}" {{ old('category_id', $blog->category_id) == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <!-- Banner Image & Credits Row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-xs font-extrabold text-slate-800 uppercase tracking-widest mb-3">Banner Image (Optional)</label>
                            <div class="file-upload-wrapper">
                                <label for="banner_image" class="file-upload-btn">Choose file</label>
                                <span class="file-name" id="banner-file-name">No file chosen</span>
                                <input type="file" id="banner_image" name="banner_image" accept="image/*" onchange="document.getElementById('banner-file-name').textContent = this.files[0] ? this.files[0].name : 'No file chosen'">
                            </div>
                            @if($blog->cover_image)
                            <p class="text-xs text-slate-500 mt-2 font-medium">Leave empty to keep current image.</p>
                            @endif
                        </div>
                        <div>
                            <label class="block text-xs font-extrabold text-slate-800 uppercase tracking-widest mb-3">Image Credits (Optional)</label>
                            <input type="text" name="image_credits" value="{{ old('image_credits', $blog->image_credits) }}" placeholder="e.g. Photo by Unsplash" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition-shadow text-base font-medium">
                        </div>
                    </div>

                    <!-- Author Details Section -->
                    <div>
                        <div class="mb-4">
                            <h3 class="text-xs font-extrabold text-slate-800 uppercase tracking-widest">Author Details</h3>
                            <p class="text-xs text-slate-500 font-medium mt-1">Customize the author metadata for this post.</p>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-widest mb-3">Author Name</label>
                                <input type="text" name="author_name" value="{{ old('author_name', $blog->author_name) }}" placeholder="e.g. John Doe" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition-shadow text-base font-medium">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-widest mb-3">Author Title/Role</label>
                                <input type="text" name="author_title" value="{{ old('author_title', $blog->author_title) }}" placeholder="e.g. Lead Trainer" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition-shadow text-base font-medium">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 uppercase tracking-widest mb-3">Author Avatar</label>
                                <div class="file-upload-wrapper">
                                    <label for="author_avatar" class="file-upload-btn">Choose file</label>
                                    <span class="file-name" id="avatar-file-name" style="font-size: 0.75rem;">No file</span>
                                    <input type="file" id="author_avatar" name="author_avatar" accept="image/*" onchange="document.getElementById('avatar-file-name').textContent = this.files[0] ? this.files[0].name : 'No file chosen'">
                                </div>
                                @if($blog->author_avatar)
                                <p class="text-xs text-slate-500 mt-2 font-medium">Leave empty to keep current avatar.</p>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Post Content Sections -->
                    <div id="sections-container" class="space-y-6">
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <label class="block text-xs font-extrabold text-slate-800 uppercase tracking-widest">Post Sections</label>
                                <button type="button" onclick="addSection()" class="text-xs font-bold text-brand-600 hover:text-brand-500 uppercase tracking-wider bg-brand-50 px-3 py-1.5 rounded-lg border border-brand-100 transition-colors shadow-sm">
                                    + Add Section
                                </button>
                            </div>
                            <p class="text-xs text-slate-500 font-medium mb-4">Build your blog by adding multiple text and image sections.</p>
                        </div>

                        @forelse($blog->sections as $index => $section)
                        <div class="section-block bg-white border border-slate-200 rounded-2xl p-6 relative shadow-sm {{ $index > 0 ? 'mt-6' : '' }}">
                            <button type="button" onclick="removeSection(this)" class="absolute top-4 right-4 text-slate-400 hover:text-red-500 transition-colors" title="Remove Section">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                            
                            <div class="space-y-4 mt-2">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-widest mb-2">Section Text</label>
                                    <div class="quill-editor bg-white rounded-xl" id="editor-{{ $index }}" style="min-height: 150px;">{!! $section->text_content !!}</div>
                                    <input type="hidden" name="sections[{{ $index }}][text]" id="input-{{ $index }}" value="{{ $section->text_content }}">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-widest mb-2">Section Image (Optional)</label>
                                    
                                    @if($section->image_path)
                                    <div class="mb-3">
                                        <p class="text-xs text-slate-500 mb-1">Current Image:</p>
                                        <img src="{{ $section->image_path }}" class="h-20 rounded-md border border-slate-200 object-cover">
                                        <input type="hidden" name="sections[{{ $index }}][existing_image]" value="{{ $section->image_path }}">
                                    </div>
                                    @endif

                                    <div class="file-upload-wrapper flex items-center border border-slate-200 rounded-lg p-2 bg-slate-50">
                                        <label class="file-upload-btn cursor-pointer bg-brand-50 text-brand-600 px-3 py-1.5 rounded-md text-xs font-bold uppercase tracking-wider hover:bg-brand-100 transition-colors">
                                            Choose file
                                            <input type="file" name="sections[{{ $index }}][image]" accept="image/*" class="hidden" onchange="this.parentElement.nextElementSibling.textContent = this.files[0] ? this.files[0].name : 'No file chosen'">
                                        </label>
                                        <span class="file-name text-xs ml-3 text-slate-500 font-medium">No file chosen</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @empty
                        <!-- Initial Section -->
                        <div class="section-block bg-white border border-slate-200 rounded-2xl p-6 relative shadow-sm">
                            <button type="button" onclick="removeSection(this)" class="absolute top-4 right-4 text-slate-400 hover:text-red-500 transition-colors" title="Remove Section">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                            
                            <div class="space-y-4 mt-2">
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-widest mb-2">Section Text</label>
                                    <div class="quill-editor bg-white rounded-xl" id="editor-0" style="min-height: 150px;"></div>
                                    <input type="hidden" name="sections[0][text]" id="input-0">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-widest mb-2">Section Image (Optional)</label>
                                    <div class="file-upload-wrapper flex items-center border border-slate-200 rounded-lg p-2 bg-slate-50">
                                        <label class="file-upload-btn cursor-pointer bg-brand-50 text-brand-600 px-3 py-1.5 rounded-md text-xs font-bold uppercase tracking-wider hover:bg-brand-100 transition-colors">
                                            Choose file
                                            <input type="file" name="sections[0][image]" accept="image/*" class="hidden" onchange="this.parentElement.nextElementSibling.textContent = this.files[0] ? this.files[0].name : 'No file chosen'">
                                        </label>
                                        <span class="file-name text-xs ml-3 text-slate-500 font-medium">No file chosen</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endforelse
                    </div>

                    <!-- Publish Settings -->
                    <div class="bg-white rounded-2xl p-6 border border-slate-200 shadow-sm space-y-4">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <h4 class="text-sm font-extrabold text-slate-800 uppercase tracking-widest">Publish Status</h4>
                                <p class="text-xs text-slate-500 font-medium mt-1">Choose when this post should be visible.</p>
                            </div>
                            @php
                                $currentStatus = $blog->status;
                                if ($currentStatus === 'published' && $blog->published_at && $blog->published_at > now()) {
                                    $currentStatus = 'scheduled';
                                }
                            @endphp
                            <select name="status" id="status-select" class="px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold text-slate-700 focus:outline-none focus:ring-2 focus:ring-brand-500 appearance-none min-w-[200px]">
                                <option value="draft" {{ old('status', $currentStatus) === 'draft' ? 'selected' : '' }}>Draft (Hidden)</option>
                                <option value="published" {{ old('status', $currentStatus) === 'published' ? 'selected' : '' }}>Publish Immediately</option>
                                <option value="scheduled" {{ old('status', $currentStatus) === 'scheduled' ? 'selected' : '' }}>Schedule for Later</option>
                            </select>
                        </div>
                        
                        <!-- Schedule Date Picker -->
                        <div id="schedule-container" class="pt-4 border-t border-slate-100 {{ old('status', $currentStatus) === 'scheduled' ? '' : 'hidden' }}">
                            <label class="block text-xs font-bold text-slate-700 uppercase tracking-widest mb-2">Publish Date & Time</label>
                            <input type="datetime-local" name="published_at" value="{{ old('published_at', $blog->published_at ? $blog->published_at->format('Y-m-d\TH:i') : '') }}" class="w-full sm:w-auto px-4 py-2 bg-white border border-slate-200 rounded-lg text-sm font-medium text-slate-700 focus:outline-none focus:ring-2 focus:ring-brand-500">
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex justify-end pt-2">
                        <button type="submit" class="px-8 py-3.5 bg-gradient-to-r from-brand-600 to-blue-600 hover:from-brand-500 hover:to-blue-500 text-white font-bold text-sm tracking-wider uppercase rounded-xl transition-all shadow-md transform hover:scale-[1.02]">
                            Update Post
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
    // Initialize Quill custom formats
    const Parchment = Quill.import('parchment');
    const LineHeightStyle = new Parchment.StyleAttributor('lineHeight', 'line-height', {
        scope: Parchment.Scope.BLOCK,
        whitelist: ['1', '1.5', '2']
    });
    Quill.register(LineHeightStyle, true);

    const LetterSpacingStyle = new Parchment.StyleAttributor('letterSpacing', 'letter-spacing', {
        scope: Parchment.Scope.INLINE,
        whitelist: ['normal', '1px', '2px']
    });
    Quill.register(LetterSpacingStyle, true);

    // Initialize Quill editors array
    const quillEditors = {};

    function initQuill(index) {
        if (!document.getElementById('editor-' + index)) return;
        quillEditors[index] = new Quill('#editor-' + index, {
            theme: 'snow',
            modules: {
                toolbar: [
                    [{ 'font': [] }, { 'size': ['small', false, 'large', 'huge'] }],
                    [{ 'header': [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'lineHeight': ['1', '1.5', '2'] }, { 'letterSpacing': ['normal', '1px', '2px'] }],
                    ['blockquote', 'code-block'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    ['link', 'image', 'video'],
                    ['clean']
                ]
            }
        });
        
        quillEditors[index].on('text-change', function() {
            document.getElementById('input-' + index).value = quillEditors[index].root.innerHTML;
        });
    }

    // Initialize the editors on page load
    document.addEventListener('DOMContentLoaded', function() {
        @forelse($blog->sections as $index => $section)
            initQuill({{ $index }});
        @empty
            initQuill(0);
        @endforelse
    });

    const statusSelect = document.getElementById('status-select');
    const scheduleContainer = document.getElementById('schedule-container');

    statusSelect.addEventListener('change', function() {
        if (this.value === 'scheduled') {
            scheduleContainer.classList.remove('hidden');
        } else {
            scheduleContainer.classList.add('hidden');
        }
    });

    let sectionCount = {{ max(1, count($blog->sections)) }};
    function addSection() {
        const container = document.getElementById('sections-container');
        const sectionHtml = `
            <div class="section-block bg-white border border-slate-200 rounded-2xl p-6 relative shadow-sm mt-6">
                <button type="button" onclick="removeSection(this)" class="absolute top-4 right-4 text-slate-400 hover:text-red-500 transition-colors" title="Remove Section">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                </button>
                
                <div class="space-y-4 mt-2">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-widest mb-2">Section Text</label>
                        <div class="quill-editor bg-white rounded-xl" id="editor-${sectionCount}" style="min-height: 150px;"></div>
                        <input type="hidden" name="sections[${sectionCount}][text]" id="input-${sectionCount}">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-widest mb-2">Section Image (Optional)</label>
                        <div class="file-upload-wrapper flex items-center border border-slate-200 rounded-lg p-2 bg-slate-50">
                            <label class="file-upload-btn cursor-pointer bg-brand-50 text-brand-600 px-3 py-1.5 rounded-md text-xs font-bold uppercase tracking-wider hover:bg-brand-100 transition-colors">
                                Choose file
                                <input type="file" name="sections[${sectionCount}][image]" accept="image/*" class="hidden" onchange="this.parentElement.nextElementSibling.textContent = this.files[0] ? this.files[0].name : 'No file chosen'">
                            </label>
                            <span class="file-name text-xs ml-3 text-slate-500 font-medium">No file chosen</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', sectionHtml);
        initQuill(sectionCount);
        sectionCount++;
    }

    function removeSection(button) {
        const block = button.closest('.section-block');
        if (document.querySelectorAll('.section-block').length > 1) {
            block.remove();
        } else {
            alert('You must have at least one section.');
        }
    }
    
    // On form submit, ensure all Quill contents are in hidden inputs
    document.querySelector('form').addEventListener('submit', function() {
        for (let idx in quillEditors) {
            if(document.getElementById('input-' + idx)) {
                // To avoid sending empty HTML strings when practically empty
                let html = quillEditors[idx].root.innerHTML;
                if (html === '<p><br></p>') html = '';
                document.getElementById('input-' + idx).value = html;
            }
        }
    });
</script>
@endpush
