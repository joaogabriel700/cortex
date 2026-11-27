// Development only: the Nuxt dev server forwards API calls. In the built app, Caddy does it.
const apiProxyTarget = process.env.API_PROXY_TARGET

// Single-page app: private screens, offline first, served as static files.
// Nothing that changes between environments may be baked into this build.
export default defineNuxtConfig({
  compatibilityDate: '2026-11-01',
  devtools: { enabled: true },
  ssr: false,
  modules: ['@nuxtjs/i18n', '@vite-pwa/nuxt'],

  app: {
    head: {
      title: 'Cortex',
      meta: [{ name: 'theme-color', content: '#07080D' }],
      link: [{ rel: 'icon', type: 'image/svg+xml', href: '/icons/cortex-app-icon.svg' }],
    },
  },

  i18n: {
    strategy: 'no_prefix',
    defaultLocale: 'pt-BR',
    locales: [
      { code: 'pt-BR', language: 'pt-BR', name: 'Português (Brasil)', file: 'pt-BR.json' },
      { code: 'en', language: 'en-US', name: 'English', file: 'en.json' },
    ],
    detectBrowserLanguage: { useCookie: true, cookieKey: 'cortex_locale', fallbackLocale: 'pt-BR' },
  },

  pwa: {
    registerType: 'autoUpdate',
    manifest: {
      name: 'Cortex',
      short_name: 'Cortex',
      lang: 'pt-BR',
      start_url: '/',
      display: 'standalone',
      theme_color: '#07080D',
      background_color: '#07080D',
      // PNG icons (192 and 512, plus maskable) still have to be exported from this SVG.
      icons: [{ src: '/icons/cortex-app-icon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any' }],
    },
    generateSW: {
      globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2,json}'],
      navigateFallback: '/',
      // The service worker never answers for the API.
      navigateFallbackDenylist: [/^\/api\//, /^\/sanctum\//, /^\/up$/],
    },
    devOptions: { enabled: false },
  },

  nitro: {
    devProxy: apiProxyTarget
      ? {
          '/api': { target: `${apiProxyTarget}/api` },
          '/sanctum': { target: `${apiProxyTarget}/sanctum` },
        }
      : {},
  },
})
