/**
 * WebApp ESLint config / NEXO Institucional
 * Responsabilidad: Reglas de lint para React 18 con JSX runtime, hooks y react-refresh.
 * Desactiva prop-types y define entorno browser/es2020.
 * Dependencias: eslint, eslint-plugin-react, eslint-plugin-react-hooks, eslint-plugin-react-refresh.
 */
module.exports = {
  root: true,
  env: { browser: true, es2020: true },
  extends: [
    'eslint:recommended',
    'plugin:react/recommended',
    'plugin:react/jsx-runtime',
    'plugin:react-hooks/recommended',
  ],
  ignorePatterns: ['dist', '.eslintrc.cjs'],
  parserOptions: { ecmaVersion: 'latest', sourceType: 'module' },
  settings: { react: { version: '18.2' } },
  plugins: ['react-refresh'],
  rules: {
    'react-refresh/only-export-components': [
      'warn',
      { allowConstantExport: true },
    ],
    'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
    'react/prop-types': 'off',
    'react-hooks/rules-of-hooks': 'error',
    'no-useless-escape': 'warn',
    'no-undef': 'warn',
    'react/jsx-no-undef': 'warn',
  },
}
