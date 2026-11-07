document.querySelectorAll('[data-ai-action]').forEach(select => {
    const update = () => select.form.querySelectorAll('[data-ai-for]').forEach(group => {
        group.hidden = group.dataset.aiFor !== select.value;
        group.querySelectorAll('input,select,textarea').forEach(input => { input.disabled = group.hidden; });
    });
    select.addEventListener('change', update);
    update();
});
let dirty = false;
const review = document.querySelector('[data-ai-review]');
review?.addEventListener('input', () => { dirty = true; });
review?.addEventListener('submit', () => { dirty = false; });
window.addEventListener('beforeunload', event => {
    if (dirty) { event.preventDefault(); event.returnValue = ''; }
});
document.querySelectorAll('[data-ai-needs-clean]').forEach(element => {
    element.addEventListener(element.tagName === 'FORM' ? 'submit' : 'click', event => {
        if (dirty) {
            event.preventDefault();
            alert('Save your suggestion edits first so this action uses the latest text.');
        }
    });
});
document.querySelectorAll('[data-ai-copy]').forEach(button => {
    button.addEventListener('click', async () => {
        const field = document.getElementById(button.dataset.aiCopy);
        try {
            await navigator.clipboard.writeText(field.value);
            const original = button.textContent;
            button.textContent = 'Copied ✓';
            setTimeout(() => { button.textContent = original; }, 1800);
        } catch {
            field.focus(); field.select();
            button.textContent = 'Text selected — use your copy shortcut';
        }
    });
});
const task = document.querySelector('[data-ai-poll]');
if (task) {
    let attempts = 0;
    const poll = async () => {
        if (++attempts > 120) return;
        try {
            const response = await fetch(task.dataset.aiPoll, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok || response.redirected) return;
            const result = await response.json();
            if (result.state !== task.dataset.aiState) { location.reload(); return; }
        } catch { /* Transient network failures keep the current page intact. */ }
        setTimeout(poll, 4000);
    };
    setTimeout(poll, 4000);
}
