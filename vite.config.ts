import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';

/**
 * Rewrite React ChatBotify's hardcoded `rcb-` class/keyframe prefix to `pegasus-rcb-` at
 * build time, in BOTH its JS (the className strings + internal identifiers) and its CSS
 * (selectors + @keyframes), so markup and styles stay in sync. RCB has no runtime option for
 * this. Scoped to react-chatbotify's own files so it can never touch our code (which has no
 * `rcb-`). Prevents CSS collisions with any other `rcb-`-prefixed styles on the host site.
 */
function rcbPrefix(): Plugin {
  const MODULE = 'react-chatbotify';
  return {
    name: 'pegasus-rcb-prefix',
    enforce: 'pre',
    transform(code, id) {
      const path = id.split('?')[0];
      if (!path.includes(MODULE) || !/\.(css|c?js|mjs|jsx|ts|tsx)$/.test(path)) return null;
      if (!code.includes('rcb-')) return null;
      // Literal replace of the exact prefix token (no regex specials in 'rcb-').
      return { code: code.split('rcb-').join('pegasus-rcb-'), map: null };
    },
  };
}

/**
 * Two-stage build (build spec §3.4, §6.4):
 *
 *   - Single entry `launcher.ts` → dist/launcher.js. Always enqueued, a few KB, no React.
 *   - The launcher `import()`s `./widget`, so Rollup automatically code-splits the widget
 *     (and the React it pulls in) into a separate, lazily-loaded chunk. React's bytes are
 *     therefore deferred until the bubble is clicked, which is why we can afford to bundle
 *     our own React instead of aliasing WordPress core's (§3.3).
 *
 * `base: './'` makes Rollup emit RELATIVE chunk/asset URLs that resolve against the
 * launcher module's own URL (import.meta.url) at runtime. That is what keeps dynamic
 * imports working on subdirectory WordPress installs without hardcoding the plugin URL at
 * build time (§3.4) — the plugin ships to arbitrary domains whose paths we don't control.
 */
export default defineConfig({
  base: './',
  plugins: [rcbPrefix(), react()],
  build: {
    target: 'es2019',
    outDir: 'dist',
    emptyOutDir: true,
    modulePreload: false, // We control loading order from the launcher; no preload polyfill.
    rollupOptions: {
      input: 'src/launcher.ts',
      output: {
        format: 'es',
        // Stable entry name so PHP can enqueue dist/launcher.js directly (cache-busted by
        // filemtime). Lazy chunks/assets stay content-hashed for long-term caching.
        entryFileNames: 'launcher.js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
  },
});
