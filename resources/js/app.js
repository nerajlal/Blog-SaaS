import './bootstrap';

const sidebar = document.getElementById('workspace-sidebar');
const sidebarToggle = document.getElementById('sidebar-toggle');
const backdrop = document.getElementById('sidebar-backdrop');
if (sidebar && sidebarToggle && backdrop) {
    const setOpen = open => {
        sidebar.classList.toggle('hidden', !open);
        sidebar.classList.toggle('flex', open);
        backdrop.classList.toggle('hidden', !open);
        sidebarToggle.setAttribute('aria-expanded', String(open));
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) document.getElementById('sidebar-close')?.focus();
    };
    sidebarToggle.addEventListener('click', () => setOpen(sidebarToggle.getAttribute('aria-expanded') !== 'true'));
    backdrop.addEventListener('click', () => { setOpen(false); sidebarToggle.focus(); });
    document.getElementById('sidebar-close')?.addEventListener('click', () => { setOpen(false); sidebarToggle.focus(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && sidebarToggle.getAttribute('aria-expanded') === 'true') { setOpen(false); sidebarToggle.focus(); }
        if (event.key === 'Tab' && sidebarToggle.getAttribute('aria-expanded') === 'true') {
            const focusable = [...sidebar.querySelectorAll('a[href],button,input,select,summary')].filter(node => !node.disabled && node.getClientRects().length);
            const first = focusable[0], last = focusable.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        } });
    window.matchMedia('(min-width: 768px)').addEventListener('change', () => setOpen(false));
}

// Small shared interactions for the product interface.
document.querySelectorAll('[data-toggle-password]').forEach(button => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.togglePassword);
        if (!input) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.textContent = show ? 'Hide' : 'Show';
        button.setAttribute('aria-label', `${show ? 'Hide' : 'Show'} ${input.name === 'password_confirmation' ? 'confirmation password' : 'password'}`);
    });
});
document.querySelectorAll('[data-dismiss-notice]').forEach(button => button.addEventListener('click', () => button.closest('[role="status"]')?.remove()));
document.addEventListener('click', event => {
    document.querySelectorAll('.account-menu[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; });
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') document.querySelectorAll('.account-menu[open]').forEach(menu => { menu.open = false; menu.querySelector('summary').focus(); });
});

// Native dialogs handle modal focus and Escape; restore focus to the trigger on close.
document.querySelectorAll('[data-open-dialog]').forEach(button => {
    const dialog = document.getElementById(button.dataset.openDialog);
    if (!dialog) return;
    button.addEventListener('click', () => dialog.showModal());
    dialog.querySelectorAll('[data-close-dialog]').forEach(cancel => {
        cancel.addEventListener('click', () => dialog.close());
    });
    dialog.addEventListener('close', () => {
        dialog.querySelector('form')?.reset();
        button.focus();
    });
});
