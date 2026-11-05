<section id="editorial-analysis" class="border-t border-slate-100 pt-6 space-y-5">
    <div class="flex flex-wrap gap-3 justify-between items-center"><div><h4 class="font-bold text-lg">Editorial assistant</h4><p class="text-xs text-slate-500 mt-1">Checks the sanitized story across all sections. Scores never block publication.</p></div><a class="text-xs text-blue-700 font-semibold" href="{{ route('admin.seo.health', $project) }}">Content health ↗</a></div>
    <div class="grid grid-cols-2 gap-4"><div class="rounded-xl border border-slate-200 p-4 bg-slate-50"><p class="text-xs text-slate-500">SEO checks</p><p id="analysis-seo-score" class="text-xl sm:text-2xl font-bold mt-2">—</p></div><div class="rounded-xl border border-slate-200 p-4 bg-slate-50"><p class="text-xs text-slate-500">Readability</p><p id="analysis-readability-score" class="text-xl sm:text-2xl font-bold mt-2">—</p></div></div>
    <p id="analysis-status" role="status" aria-live="polite" class="text-xs text-slate-500">Preparing editorial checks…</p>
    <p id="analysis-metrics" class="text-xs text-slate-500"></p>
    <div id="analysis-findings" class="space-y-2"></div>
    <p class="text-xs text-slate-400">English-only word/sentence guidance; other languages receive structural checks with unsupported rules marked unavailable. These are editorial heuristics, not search-engine requirements.</p>
    <label class="flex gap-2 items-start text-sm text-slate-600"><input type="hidden" name="is_cornerstone" value="0"><input type="checkbox" name="is_cornerstone" value="1" @checked(old('is_cornerstone', $blog->is_cornerstone ?? false)) class="mt-1"><span>Mark as cornerstone content<small class="block text-xs text-slate-400 mt-1">An important story to prioritize for editorial review.</small></span></label>
    @isset($blog)<a class="inline-block text-xs text-blue-700 font-semibold" href="{{ route('admin.seo.report', $blog) }}">Open saved report and editorial decisions ↗</a>@endisset
</section>
