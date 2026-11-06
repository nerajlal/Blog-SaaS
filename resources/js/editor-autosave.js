const bootAutosave = async () => {
    const panel = document.getElementById('editor-autosave');
    const form = document.getElementById('blog-editor-form');
    if (!panel || !form) return;
    const status = document.getElementById('autosave-status');
    const recoveryPanel = document.getElementById('autosave-recovery');
    const retry = document.getElementById('autosave-retry');
    const roots = new Set(['title', 'category_id', 'image_credits', 'author_name', 'author_title', 'cover_image_alt', 'cover_image_caption', 'cover_image_decorative', 'is_cornerstone', 'status', 'published_at']);
    let version = 0, loaded = false, blocked = false, saving = false, dirty = false, replaying = false, submitting = false;
    let timer, cooldown = 0, draft = null;
    const filesPending = () => [...form.querySelectorAll('input[type="file"]')].some(input => input.files.length);
    const capture = () => {
        const fields = Object.create(null);
        const indices = new Map([...form.querySelectorAll('.section-block')].map((block, index) => [block.querySelector('[name^="sections["]')?.name.match(/^sections\[(\d+)\]/)?.[1], index]));
        for (const input of form.elements) {
            let name = input.name;
            if (!name || input.type === 'file' || input.disabled || (['checkbox', 'radio'].includes(input.type) && !input.checked)) continue;
            if (name.startsWith('sections[')) {
                const match = name.match(/^sections\[(\d+)\]\[(text|existing_image|image_alt|image_caption|image_decorative)\]$/);
                if (!match || !indices.has(match[1])) continue;
                name = `sections[${indices.get(match[1])}][${match[2]}]`;
                fields[name] = match[2] === 'text' ? input.closest('.section-block').querySelector('.ql-editor')?.innerHTML || input.value : input.value;
            } else if (roots.has(name) || /^seo\[[a-z_]+\]$/.test(name)) fields[name] = input.value;
        }
        return fields;
    };
    let lastSaved = JSON.stringify(capture());
    const note = message => { status.textContent = message; };
    const failure = message => { note(message); retry.classList.remove('hidden'); };
    const request = async (method, body) => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(panel.dataset.url, { method, credentials: 'same-origin', signal: controller.signal,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.elements.namedItem('_token').value },
                body: body ? JSON.stringify(body) : undefined });
            if (response.redirected || [401, 403, 419].includes(response.status)) throw new Error('Autosave failed: your session expired or edit access changed. Keep this tab open and sign in again in another tab.');
            if (response.status === 429) cooldown = Date.now() + Math.max(1, Number(response.headers.get('Retry-After')) || 60) * 1000;
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                if (response.status === 409) blocked = true;
                throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Autosave failed. Keep this tab open and retry.');
            }
            return data;
        } finally { clearTimeout(timeout); }
    };
    const setVersion = number => {
        version = number;
        form.elements.namedItem('autosave_version').value = String(number);
    };
    const save = async () => {
        if (!loaded || blocked || saving || !dirty || submitting) return;
        if (Date.now() < cooldown) { clearTimeout(timer); timer = setTimeout(save, cooldown - Date.now() + 100); return; }
        saving = true;
        const fields = capture(); const snapshot = JSON.stringify(fields);
        note('Saving private draft…'); retry.classList.add('hidden');
        try {
            const data = await request('POST', { base_hash: panel.dataset.revision, version, fields });
            setVersion(data.version); lastSaved = snapshot;
            dirty = JSON.stringify(capture()) !== snapshot;
            note(dirty ? 'More changes waiting to save…' : `Draft saved at ${new Date(data.saved_at).toLocaleTimeString()}${filesPending() ? '. Selected image files still need Update Post.' : '. Click Update Post to apply your changes.'}`);
            if (dirty) { clearTimeout(timer); timer = setTimeout(save, 1500); }
        } catch (error) {
            dirty = true;
            failure(error.name === 'AbortError' ? 'Autosave timed out. Keep this tab open. Retry may report a conflict if the server already saved your draft.' : error.message);
            if (cooldown > Date.now() && !blocked) { clearTimeout(timer); timer = setTimeout(save, cooldown - Date.now() + 100); }
        } finally { saving = false; }
    };
    const changed = () => {
        if (replaying || submitting) return;
        dirty = JSON.stringify(capture()) !== lastSaved;
        if (!dirty) { if (filesPending()) note('Text draft unchanged. Selected image files still need Update Post.'); return; }
        if (!blocked) note('Unsaved text changes…');
        clearTimeout(timer); timer = setTimeout(save, 1500);
    };
    const load = async () => {
        try {
            const data = await request('GET');
            draft = data.draft; setVersion(draft?.version || 0); loaded = true;
            retry.classList.add('hidden');
            if (draft && Object.keys(draft.fields).length) {
                blocked = true; recoveryPanel.classList.remove('hidden');
                document.getElementById('autosave-recovery-message').textContent = `A private draft was saved ${new Date(draft.saved_at).toLocaleString()}. Restore replaces form text and selected section files; reselect any new section images afterwards. ${draft.stale ? 'The saved post has changed since that draft: review restored text before updating.' : 'Choose Restore or Discard before autosave resumes.'}`;
                note('Recovery draft found. Autosave is paused until you choose.');
            } else { blocked = false; note('Autosave ready. Your text changes will be kept private.'); if (dirty) changed(); }
        } catch (error) { failure(error.message); }
    };
    document.getElementById('autosave-restore').addEventListener('click', () => {
        if (!draft || saving) return;
        replaying = true;
        try {
            const fields = draft.fields;
            const ids = [...new Set(Object.keys(fields).map(name => name.match(/^sections\[(\d+)\]/)?.[1]).filter(id => id !== undefined))].sort((a, b) => Number(a) - Number(b));
            if (ids.length) {
                form.querySelectorAll('.section-block').forEach(block => block.remove());
                ids.forEach(() => window.addSection());
            }
            const blocks = [...form.querySelectorAll('.section-block')];
            for (const [savedName, value] of Object.entries(fields)) {
                let name = savedName;
                const match = name.match(/^sections\[(\d+)\]\[([a-z_]+)\]$/);
                if (match) {
                    const block = blocks[ids.indexOf(match[1])]; if (!block) continue;
                    const prefix = block.querySelector('[name^="sections["]').name.match(/^sections\[\d+\]/)[0];
                    name = `${prefix}[${match[2]}]`;
                    if (match[2] === 'existing_image' && value && !block.querySelector('[name$="[existing_image]"]')) {
                        const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = name; block.append(hidden);
                    }
                    if (match[2] === 'text') {
                        const editor = block.querySelector('.quill-editor'); const quill = window.Quill.find(editor);
                        quill.clipboard.dangerouslyPasteHTML(value, 'silent');
                    }
                }
                for (const input of form.querySelectorAll(`[name="${CSS.escape(name)}"]`)) {
                    if (input.type === 'checkbox') input.checked = value === input.value;
                    else if (input.type !== 'file') input.value = value;
                }
            }
            recoveryPanel.classList.add('hidden'); blocked = false; lastSaved = null;
            note('Draft restored into the editor. Review it, then click Update Post.');
        } finally { replaying = false; }
        form.dispatchEvent(new Event('change', { bubbles: true })); changed();
    });
    document.getElementById('autosave-discard').addEventListener('click', async () => {
        if (!draft || saving) return;
        saving = true;
        try {
            const data = await request('DELETE', { version }); setVersion(data.version); draft = null; blocked = false;
            recoveryPanel.classList.add('hidden'); retry.classList.add('hidden');
            note('Recovery draft discarded. Autosave is ready.'); if (dirty) changed();
        } catch (error) { failure(error.message); } finally { saving = false; }
    });
    retry.addEventListener('click', () => { if (!loaded) load(); else if (!blocked) save(); else note('Autosave paused after a conflict. Keep this tab open and review the latest post or recovery draft in another tab.'); });
    form.addEventListener('input', changed); form.addEventListener('change', changed);
    new MutationObserver(changed).observe(document.getElementById('sections-container'), { childList: true, characterData: true, subtree: true });
    const periodic = setInterval(() => { if (dirty && !retry.classList.contains('hidden')) return; save(); }, 10000);
    window.addEventListener('online', () => { if (!blocked) { if (loaded) save(); else load(); } });
    window.addEventListener('beforeunload', event => { if (!submitting && (dirty || filesPending())) { event.preventDefault(); event.returnValue = ''; } });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (submitting) return;
        submitting = true; clearTimeout(timer);
        const buttons = [...form.querySelectorAll('button[type="submit"]')];
        buttons.forEach(button => { button.disabled = true; });
        const controller = new AbortController();
        let timeout;
        try {
            // Let the current autosave acknowledge its version before sending the manual update.
            while (saving) await new Promise(resolve => setTimeout(resolve, 50));
            note('Saving post…');
            timeout = setTimeout(() => controller.abort(), 60000);
            const response = await fetch(form.action, { method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) });
            const destination = new URL(response.url);
            if (response.ok && response.redirected && destination.origin === location.origin && destination.pathname.startsWith('/admin/projects/')) {
                window.location.assign(destination.href); return;
            }
            if (response.status === 409) blocked = true;
            const data = await response.json().catch(() => ({}));
            throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Post save could not be confirmed. Keep this tab open and check your session and saved post before retrying.');
        } catch (error) {
            submitting = false; dirty = true;
            buttons.forEach(button => { button.disabled = false; });
            failure(error.name === 'AbortError' ? 'Post save timed out. Your text is still here. Check the saved post in another tab before retrying.' : error.message);
            panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } finally { clearTimeout(timeout); }
    });
    document.getElementById('autosave-download').addEventListener('click', () => {
        const blob = new Blob([JSON.stringify({ saved_at: new Date().toISOString(), fields: capture() }, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob); const link = document.createElement('a');
        link.href = url; link.download = 'story-text-backup.json'; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
    await load();
};
window.addEventListener('blog-editor-ready', () => setTimeout(bootAutosave, 0), { once: true });
