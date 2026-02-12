import adapter from '@sveltejs/adapter-static';
import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

const config = {
  preprocess: vitePreprocess(),
  kit: {
    adapter: adapter({
      pages: '../public/mobile',
      assets: '../public/mobile',
      fallback: 'index.html',
      precompress: false,
      strict: false,
    }),
    paths: {
      base: '/mobile',
    },
  },
};

export default config;
