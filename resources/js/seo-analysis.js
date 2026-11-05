const bootAnalysis = () => {
    const root = document.getElementById('editorial-analysis');
    if (!root) return;
    const form = document.getElementById('blog-editor-form');
    const config = JSON.parse(document.getElementById('seo-preview-config').textContent);
    const status = document.getElementById('analysis-status');
    const findings = document.getElementById('analysis-findings');
    let timer;
    let controller;
    let sequence = 0;
    let pendingRetryAfter = 0;
    const fieldValue = name => form.elements.namedItem(name)?.value || '';
    const checked = name => form.querySelector(`input[type="checkbox"][name="${CSS.escape(name)}"]`)?.checked || false;
    const collect = () => {
        const data = { title: fieldValue('title'), blog_id: config.blogId, seo: {}, cover_image_present: Boolean(form.elements.namedItem('banner_image')?.files?.length || config.coverImage), cover_image_replaced: Boolean(form.elements.namedItem('banner_image')?.files?.length), cover_image_alt: fieldValue('cover_image_alt'), cover_image_decorative: checked('cover_image_decorative'), is_cornerstone: checked('is_cornerstone'), sections: [] };
        for (const input of form.querySelectorAll('[name^="seo["]')) {
            const key = input.name.slice(4, -1);
            data.seo[key] = input.value;
        }
        for (const block of document.querySelectorAll('.section-block')) {
            const editor = block.querySelector('.ql-editor');
            const file = block.querySelector('input[type="file"]');
            const existing = block.querySelector('[name$="[existing_image]"]');
            data.sections.push({ text: editor?.innerHTML || '', image_present: Boolean(file?.files?.length || existing?.value), image_replaced: Boolean(file?.files?.length), existing_image: existing?.value || null, image_alt: block.querySelector('[name$="[image_alt]"]')?.value || '', image_decorative: block.querySelector('input[type="checkbox"][name$="[image_decorative]"]')?.checked || false });
        }
        return data;
    };
    const render = data => {
        const result = data.analysis;
        for (const [id, score] of [['analysis-seo-score', result.seo.score], ['analysis-readability-score', result.readability.score]]) {
            const node = document.getElementById(id);
            const value = document.createElement('span'); value.textContent = score === null ? '—' : String(score);
            const suffix = document.createElement('small'); suffix.className = 'text-xs text-slate-400 font-normal';
            suffix.textContent = score === null ? 'Unavailable' : ' / 100';
            if (score === null) suffix.classList.add('block');
            node.replaceChildren(value, suffix);
        }
        const metrics = result.metrics;
        document.getElementById('analysis-metrics').textContent = metrics.word_count === undefined ? '' : `${metrics.word_count === null ? 'Word count unavailable for this language' : `${metrics.word_count} words`} · ${metrics.heading_count} headings · ${metrics.link_count} links · ${metrics.image_count} images`;
        status.textContent = data.saved_revision ? 'Matches the current saved analysis. Scores describe our checks, not rankings.' : 'Live draft analysis. Save the story to record this revision and review individual warnings.';
        findings.replaceChildren();
        for (const [group, label] of [['seo', 'SEO findings'], ['readability', 'Readability findings']]) {
            const details = document.createElement('details');
            details.className = 'rounded-xl border border-slate-200 p-4';
            const summary = document.createElement('summary');
            summary.className = 'cursor-pointer font-semibold text-sm';
            const warnings = result[group].findings.filter(finding => finding.status === 'warning').length;
            summary.textContent = `${label} · ${warnings} ${warnings === 1 ? 'suggestion' : 'suggestions'}`;
            details.append(summary);
            const list = document.createElement('ul'); list.className = 'mt-3 space-y-4';
            for (const finding of result[group].findings) {
                const item = document.createElement('li');
                const title = document.createElement('p'); title.className = 'text-xs font-semibold';
                title.textContent = `${finding.status.replaceAll('_', ' ')} · ${finding.label}`;
                title.style.color = finding.status === 'pass' ? '#047857' : finding.status === 'warning' ? '#92400e' : '#64748b';
                const message = document.createElement('p'); message.className = 'text-xs text-slate-500 mt-1'; message.textContent = finding.message;
                item.append(title, message);
                if (finding.review) { const review = document.createElement('p'); review.className = 'text-xs text-blue-700 mt-1'; review.textContent = `Reviewed: ${finding.review.reason}`; item.append(review); }
                list.append(item);
            }
            details.append(list); findings.append(details);
        }
    };
    const run = async number => {
        if (Date.now() < pendingRetryAfter) { status.textContent = 'Checks are temporarily rate limited. Continue editing; they will retry after a short pause.'; timer = setTimeout(() => schedule(), pendingRetryAfter - Date.now() + 50); return; }
        controller = new AbortController();
        try {
            const response = await fetch(config.analysisUrl, { method: 'POST', credentials: 'same-origin', signal: controller.signal, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value }, body: JSON.stringify(collect()) });
            if (number !== sequence) return;
            if (response.status === 429) { pendingRetryAfter = Date.now() + Math.max(1, Number(response.headers.get('Retry-After')) || 60) * 1000; status.textContent = 'Too many checks. Editing and publishing remain available; analysis will retry shortly.'; timer = setTimeout(() => schedule(), pendingRetryAfter - Date.now() + 50); return; }
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                const first = Object.values(error.errors || {}).flat()[0];
                throw new Error(first || (response.status === 419 ? 'Your session expired. Save your work locally and sign in again.' : 'Checks are unavailable. You can continue editing and publishing.'));
            }
            render(await response.json());
        } catch (error) { if (error.name !== 'AbortError' && number === sequence) status.textContent = error.message; }
    };
    const schedule = () => {
        clearTimeout(timer); controller?.abort(); sequence++;
        document.getElementById('analysis-seo-score').textContent = '—';
        document.getElementById('analysis-readability-score').textContent = '—';
        findings.replaceChildren();
        document.getElementById('analysis-metrics').textContent = '';
        status.textContent = 'Draft changed. Updating editorial checks…';
        const number = sequence; timer = setTimeout(() => run(number), 800);
    };
    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);
    new MutationObserver(schedule).observe(document.getElementById('sections-container'), { childList: true, characterData: true, subtree: true });
    schedule();
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootAnalysis, { once: true });
else bootAnalysis();
