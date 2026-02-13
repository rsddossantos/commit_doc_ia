import './bootstrap'
import '../css/app.css'
import '@mdi/font/css/materialdesignicons.css'

import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { createVuetify } from 'vuetify'
import 'vuetify/styles'
import * as vuetifyComponents from 'vuetify/components'
import * as vuetifyDirectives from 'vuetify/directives'

const vuetify = createVuetify({
    theme: {
        defaultTheme: 'light',
        themes: {
            light: {
                colors: {
                    primary: '#667eea',
                    secondary: '#764ba2',
                    background: '#ffffff',
                    surface: '#ffffff',
                    error: '#ff5252',
                    info: '#2196f3',
                    success: '#4caf50',
                    warning: '#fb8c00',
                },
            },
            dark: {
                colors: {
                    primary: '#667eea',
                    secondary: '#764ba2',
                    background: '#2c2c2c',  // cinza do fundo da tela
                    surface: '#2c2c2c',     // cinza para cards, inputs, colapses
                    error: '#ff5252',
                    info: '#2196f3',
                    success: '#4caf50',
                    warning: '#fb8c00',
                },
            },
        },
    },
    components: vuetifyComponents,
    directives: vuetifyDirectives,
})

createInertiaApp({
    resolve: name =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(vuetify)
            .mount(el)
    },
})
