import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

export default defineConfig({
  plugins: [vue()],

  build: {
    outDir: 'dist',
    manifest: true,
    rollupOptions: {
      input: {
        strikeawin: resolve(__dirname, 'src/strikeawin.js'),
      },
    },
  },

  server: {
    cors: true,
    host: true,
    port: 5175,
    strictPort: true,
    hmr: { host: 'localhost' },
    watch: {
      include: ['**/*.php'],
      ignored: ['**/node_modules/**', '**/dist/**'],
    },
  },
});
