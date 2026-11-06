<input type="hidden" name="autosave_version" value="0">
<div id="editor-autosave" data-url="{{ route('admin.blogs.autosave.store', $blog) }}" data-revision="{{ $editorRevision }}" class="mb-6 rounded-xl border border-blue-100 bg-blue-50 p-4 space-y-3">
    <p id="autosave-status" role="status" aria-live="polite" class="text-sm font-medium text-blue-900">Checking your recovery draft…</p>
    <p class="text-xs text-slate-600">Text changes are saved privately after you pause typing. Click Update Post to apply them to the story. New image files require Update Post and cannot be recovered after closing this page.</p>
    <div id="autosave-recovery" class="hidden space-y-3">
        <p id="autosave-recovery-message" class="text-sm text-slate-700"></p>
        <div class="flex flex-wrap gap-3"><button id="autosave-restore" type="button" class="rounded-lg bg-blue-600 text-white px-4 py-2 text-sm">Restore draft</button><button id="autosave-discard" type="button" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm">Discard recovery draft</button></div>
    </div>
    <button id="autosave-download" type="button" class="text-sm underline text-blue-800 mr-4">Download text backup</button>
    <button id="autosave-retry" type="button" class="hidden text-sm underline text-blue-800">Retry autosave</button>
</div>
