const boot = () => {
    const panel = document.getElementById('seo-panel');
    const configElement = document.getElementById('seo-preview-config');
    if (!panel || !configElement) return;
    const config = JSON.parse(configElement.textContent);
    const form = document.getElementById('blog-editor-form');
    const text = value => {
        const parsed = new DOMParser().parseFromString(String(value || ''), 'text/html');
        return (parsed.body.textContent || '').replace(/\s+/g, ' ').trim();
    };
    const value = name => form.elements.namedItem(name)?.value?.trim() || '';
    const put = (id, content) => { document.getElementById(id).textContent = content; };
    let coverObjectUrl = '';
    let lastCover;
    const imageElement = document.getElementById('seo-social-image');
    imageElement.addEventListener('error', () => { imageElement.classList.add('hidden'); document.getElementById('seo-social-image-placeholder').classList.remove('hidden'); });
    const update = () => {
        const title = text(value('seo[title]')) || config.template.replaceAll('{title}', text(value('title')) || 'Your story title').replaceAll('{site_name}', text(config.siteName));
        const articleText = [...document.querySelectorAll('.ql-editor')].map(editor => editor.innerText).join(' ').replace(/\s+/g, ' ').trim();
        const fallback = text(config.description) || articleText;
        const description = text(value('seo[description]')) || (fallback.length > 160 ? fallback.slice(0, 160) + '…' : fallback) || 'Your story description will appear here.';
        const url = value('seo[canonical_url]') || (config.publicUrl && config.slug ? config.publicUrl + config.articlePath.replace('{slug}', encodeURIComponent(config.slug)) : 'Your public article URL appears after saving and domain verification');
        put('seo-preview-title', title);
        put('seo-preview-description', description);
        put('seo-preview-url', url);
        const cover = form.elements.namedItem('banner_image')?.files?.[0];
        if (cover !== lastCover) {
            if (coverObjectUrl) URL.revokeObjectURL(coverObjectUrl);
            coverObjectUrl = cover ? URL.createObjectURL(cover) : '';
            lastCover = cover;
        }
        let image = value('seo[og_image_url]') || coverObjectUrl || config.coverImage || config.socialImage;
        if (image && !image.startsWith('blob:')) {
            try {
                const parsed = new URL(image);
                if (!['https:', 'http:'].includes(parsed.protocol)) image = '';
            } catch { image = ''; }
        }
        if (image && imageElement.getAttribute('src') !== image) imageElement.src = image;
        imageElement.classList.toggle('hidden', !image);
        document.getElementById('seo-social-image-placeholder').classList.toggle('hidden', Boolean(image));
        put('seo-social-title', text(value('seo[og_title]')) || title);
        put('seo-social-description', text(value('seo[og_description]')) || description);
        const titleLength = [...title].length;
        const descriptionLength = [...description].length;
        put('seo-title-length', `${titleLength} characters${titleLength > 60 ? ' · May be shortened in search results' : ' · Preview is approximate'}`);
        put('seo-description-length', `${descriptionLength} characters${descriptionLength > 160 ? ' · May be shortened in search results' : ' · Search engines may choose other text'}`);
    };
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    new MutationObserver(update).observe(document.getElementById('sections-container'), { childList: true, characterData: true, subtree: true });
    update();
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
else boot();
