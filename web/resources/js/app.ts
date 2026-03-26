import '../css/app.css'
import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import AppLayout from './Layouts/AppLayout.vue'

createInertiaApp({
  resolve(name: string) {
    const pages = import.meta.glob('./Pages/**/*.vue', { eager: true }) as Record<
      string,
      { default: { layout?: unknown } }
    >
    const page = pages[`./Pages/${name}.vue`]
    if (!page) throw new Error(`Page not found: ${name}`)
    page.default.layout ??= AppLayout
    return page
  },
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el)
  },
})
