import { defineConfig } from 'vite'

// Library build -> a single self-executing bundle the PHP app loads as a static
// asset from the docroot (htdocs/assets/app/editor.js + editor.css). No module
// loading needed in the page; just <script src=...editor.js defer>.
export default defineConfig({
  build: {
    outDir: '../htdocs/assets/app',
    emptyOutDir: true,
    cssCodeSplit: false,
    lib: {
      entry: 'src/main.js',
      name: 'CodexEditor',
      formats: ['iife'],
      fileName: () => 'editor.js',
    },
    rollupOptions: { output: { assetFileNames: 'editor.[ext]' } },
  },
})
