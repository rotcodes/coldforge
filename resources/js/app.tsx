import '../css/app.css';
import axios from 'axios';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

createInertiaApp({
    title: (title) => (title ? `${title} · ColdForge` : 'ColdForge'),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx')),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#8b5cf6' },
});
