import '../css/app.css'
import { createApp, h, type DefineComponent } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

type PageModule = { default: DefineComponent & { layout?: unknown } }

createInertiaApp({
  /**
   * Map an Inertia component name to its eagerly-bundled page module, applying the
   * default layout to any page that has not chosen one.
   */
  resolve(name: string) {
    const pages = import.meta.glob<PageModule>('./Pages/**/*.vue', { eager: true })
    const page = pages[`./Pages/${name}.vue`]

    if (!page) {
      throw new Error(`Page not found: ${name}`)
    }

    page.default.layout ??= AppLayout

    return page.default
  },

  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el)
  },
})
