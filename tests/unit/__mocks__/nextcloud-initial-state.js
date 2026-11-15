/**
 * `@nextcloud/initial-state` for Jest. The `^@nextcloud/(.*)$` mapper sends
 * every Nextcloud package here, so this re-exports the real `loadState`: it
 * reads the page's hidden inputs (or `window._nc_initial_state`) and returns
 * the fallback without them, which is what a spec without a page needs.
 */
export { loadState } from '../../../node_modules/@nextcloud/initial-state/dist/index.js'
