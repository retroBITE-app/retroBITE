import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import pluginVue from 'eslint-plugin-vue'
import prettier from 'eslint-config-prettier'

export default tseslint.config(
  { ignores: ['public/build/**', 'vendor/**', 'node_modules/**', 'resources/js/routes.ts'] },

  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...pluginVue.configs['flat/recommended'],
  prettier,

  {
    files: ['**/*.{ts,vue}'],
    languageOptions: {
      parserOptions: {
        parser: tseslint.parser,
        ecmaVersion: 'latest',
        sourceType: 'module',
      },
      globals: {
        document: 'readonly',
        window: 'readonly',
        navigator: 'readonly',
        crypto: 'readonly',
        fetch: 'readonly',
        FormData: 'readonly',
        URLSearchParams: 'readonly',
        XMLHttpRequest: 'readonly',
        Blob: 'readonly',
        File: 'readonly',
        FileList: 'readonly',
        Event: 'readonly',
        DragEvent: 'readonly',
        KeyboardEvent: 'readonly',
        MediaQueryListEvent: 'readonly',
        MouseEvent: 'readonly',
        Node: 'readonly',
        HTMLElement: 'readonly',
        HTMLInputElement: 'readonly',
        HTMLImageElement: 'readonly',
        RequestInit: 'readonly',
        BodyInit: 'readonly',
      },
    },
    rules: {
      // Payload types mirror the PHP JSON contract, which is snake_case.
      '@typescript-eslint/naming-convention': 'off',
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      'vue/multi-word-component-names': 'off',
      'vue/no-v-html': 'error',
      // `defineProps<T>()` already marks a prop optional with `?`; demanding a
      // default would force a fake one where `undefined` is the real meaning.
      'vue/require-default-prop': 'off',
      eqeqeq: ['error', 'always'],
      'no-console': ['warn', { allow: ['warn', 'error'] }],
    },
  },

  {
    // Page props come straight from the PHP JSON payload, which is snake_case.
    files: ['resources/js/Pages/**/*.vue'],
    rules: { 'vue/prop-name-casing': 'off' },
  },
)
