import './editor-autosave';
import './seo-preview';
import './seo-analysis';
import Quill from 'quill';
import 'quill/dist/quill.snow.css';
window.Quill = Quill;
window.whenEditorDomReady = callback => {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', callback, { once: true });
    else callback();
};
window.dispatchEvent(new Event('blog-editor-ready'));
