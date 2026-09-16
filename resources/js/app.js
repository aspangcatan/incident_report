import '../css/app.css';
import './bootstrap';
import './fontawesome';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';

const appName = import.meta.env.VITE_APP_NAME || 'HOPSS e-Incident';

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .component('FontAwesomeIcon', FontAwesomeIcon)
            .mount(el);
    },
    progress: {
        color: '#00236f',
    },
});
