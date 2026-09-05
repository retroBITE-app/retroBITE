/**
 * Route paths, generated from config/routes.php by `php console routes:ts`.
 * Do not edit by hand — edit the PHP map and regenerate.
 */
const PATHS = {
  'login': '/login',
  'login.submit': '/login',
  'logout': '/logout',
  'dashboard': '/',
  'consoles': '/consoles',
  'consoles.install': '/consoles/install',
  'console': '/consoles/{console}',
  'console.scan': '/consoles/{console}/scan',
  'console.hash': '/consoles/{console}/hash',
  'console.uploadChunk': '/consoles/{console}/upload-chunk',
  'console.mkdir': '/consoles/{console}/mkdir',
  'console.folder.destroy': '/consoles/{console}/folder/{folder:.+}',
  'game': '/consoles/{console}/{game}',
  'game.identify': '/consoles/{console}/{game}/identify',
  'game.metadata': '/consoles/{console}/{game}/metadata',
  'game.move': '/consoles/{console}/{game}/move',
  'game.destroy': '/consoles/{console}/{game}',
  'network.status': '/api/network/status',
  'settings': '/settings',
  'settings.save': '/settings/{group}/{key}',
  'settings.reset': '/settings/{group}/{key}/reset',
} as const

export type RouteName = keyof typeof PATHS

/**
 * Build a URL for a named route, substituting each placeholder. A catch-all
 * placeholder keeps its separators; every other value is one segment.
 */
export function route(name: RouteName, params: Record<string, string | number> = {}): string {
  let url: string = PATHS[name]

  for (const [key, value] of Object.entries(params)) {
    url = url.replace(`{${key}:.+}`, String(value).split('/').map(encodeURIComponent).join('/'))
    url = url.replace(`{${key}}`, encodeURIComponent(String(value)))
  }

  if (url.includes('{')) {
    throw new Error(`Route ${name} is missing a parameter: ${url}`)
  }

  return url
}
