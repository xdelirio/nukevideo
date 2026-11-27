/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** The API's base URL, e.g. `https://api.example.com/api`. Unset: `/api` on the panel's own origin. */
  readonly VITE_URL_API?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
