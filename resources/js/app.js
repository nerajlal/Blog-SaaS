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
    document.addEventListener('keydown', event => { if (event.key === 'Escape') { setOpen(false); sidebarToggle.focus(); } });
    window.matchMedia('(min-width: 768px)').addEventListener('change', () => setOpen(false));
}
