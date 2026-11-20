// ESLint for the browser scripts in public/. They are plain scripts loaded in order on each page, so helpers that
// ui.js defines (and data the page sets on window) are declared as shared globals for the scripts that use them.
import js from '@eslint/js';
import globals from 'globals';

const fromUi = {
  $: 'readonly', el: 'readonly', clone: 'readonly', icon: 'readonly', ICON: 'readonly',
  toast: 'readonly', ask: 'readonly', store: 'readonly', track: 'readonly',
};

export default [
  { ignores: ['public/vendor/**', 'public/psgc/**', 'node_modules/**', 'vendor/**'] },
  js.configs.recommended,
  {
    files: ['public/**/*.js'],
    languageOptions: { ecmaVersion: 2023, sourceType: 'script', globals: globals.browser },
    rules: {
      // Top-level functions in one script are used by the next script or by markup, so only local ones must be used.
      'no-unused-vars': ['error', { vars: 'local', args: 'none', caughtErrors: 'none' }],
      'no-empty': ['error', { allowEmptyCatch: true }],
    },
  },
  // Scripts loaded after ui.js.
  { files: ['public/**/*.js'], ignores: ['public/ui.js', 'public/sw.js', 'public/form-calib.js', 'public/preview-fit.js'], languageOptions: { globals: fromUi } },
  // forms.js uses the line-up helpers from form-calib.js, which also exports them for Node.
  { files: ['public/forms.js'], languageOptions: { globals: { FormCalib: 'readonly' } } },
  { files: ['public/form-calib.js'], languageOptions: { globals: { module: 'writable' } } },
  { files: ['public/sw.js'], languageOptions: { globals: globals.serviceworker } },
  { files: ['eslint.config.js'], languageOptions: { sourceType: 'module', globals: globals.node } },
];
