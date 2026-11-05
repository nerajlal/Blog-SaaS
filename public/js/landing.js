(() => {
    const toggle = document.querySelector('.menu-toggle');
    const menu = document.getElementById('mobile-menu');
    const closeMenu = () => {
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open navigation');
    };
    toggle.addEventListener('click', () => {
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        menu.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    });
    menu.querySelectorAll('a').forEach(link => link.addEventListener('click', closeMenu));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !menu.hidden) { closeMenu(); toggle.focus(); }
    });
    document.addEventListener('click', event => {
        if (!menu.hidden && !menu.contains(event.target) && !toggle.contains(event.target)) closeMenu();
    });
    window.matchMedia('(min-width: 1025px)').addEventListener('change', closeMenu);
    const copy = document.getElementById('copy-code');
    let timeout;
    copy.addEventListener('click', async () => {
        const status = document.getElementById('copy-status');
        try {
            await navigator.clipboard.writeText(document.getElementById('api-example').textContent);
            status.textContent = 'API example copied to clipboard.';
        } catch {
            status.textContent = 'Select the API example to copy it manually.';
        }
        status.hidden = false;
        clearTimeout(timeout);
        timeout = setTimeout(() => { status.hidden = true; }, 3500);
    });
})();
