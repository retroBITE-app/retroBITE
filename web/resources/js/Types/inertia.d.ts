import type { PageProps as InertiaPageProps } from '@inertiajs/core'
import type { AppIdentity, SidebarData, UiPreferences } from '@/Types/api'

/**
 * Props SessionMiddleware and AuthMiddleware share with every page, so reading
 * them off usePage() no longer needs a cast.
 */
declare module '@inertiajs/core' {
  interface PageProps extends InertiaPageProps {
    csrf_token: string
    app: AppIdentity
    ui: UiPreferences
    auth?: { user: string | null }
    sidebar?: SidebarData
  }
}
