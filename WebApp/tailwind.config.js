/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
        mono: ['JetBrains Mono', 'monospace'],
      },
      colors: {
        // Azul Institucional profundo — identidad gubernamental
        gov: {
          50:  '#E8EEF5',
          100: '#C5D4E8',
          200: '#9BB5D5',
          300: '#6E95C2',
          400: '#4D7DB4',
          500: '#2B65A5',
          600: '#1A4F8A',
          700: '#0D3A70',
          800: '#052955',
          900: '#003366', // Primary — Azul Institucional
          950: '#001F40',
        },
        // Verde Esmeralda biométrico — estado activo / verificado
        bio: {
          50:  '#E6F7F3',
          100: '#C0EDE3',
          200: '#85DACC',
          300: '#3EC4AF',
          400: '#00B28E',
          500: '#00A67E', // Primary — Biometric active
          600: '#008F6B',
          700: '#007558',
          800: '#005C44',
          900: '#003D2D',
          950: '#001F17',
        },
        // Blancos quirúrgicos y grises slate para superficies
        surface: {
          0:   '#FFFFFF',
          50:  '#F8FAFC',
          100: '#F1F5F9',
          200: '#E8EDF3',
          300: '#CBD5E1',
        },
        // Borde estándar: Slate-200
        border: {
          DEFAULT: '#E2E8F0',
          strong:  '#CBD5E1',
          subtle:  '#F1F5F9',
        },
        // Estado de alerta / error
        alert: {
          50:  '#FEF2F2',
          100: '#FEE2E2',
          500: '#EF4444',
          600: '#DC2626',
          900: '#7F1D1D',
        },
      },
      spacing: {
        '4.5': '1.125rem',
        '13':  '3.25rem',
        '15':  '3.75rem',
        '18':  '4.5rem',
        '22':  '5.5rem',
        '26':  '6.5rem',
        '30':  '7.5rem',
      },
      borderRadius: {
        'xs':  '0.25rem',
        'sm':  '0.375rem',
        '2xl': '1rem',
        '3xl': '1.5rem',
        '4xl': '2rem',
      },
      borderWidth: {
        '1.5': '1.5px',
      },
      boxShadow: {
        // Sombras "soft-inner" — profundidad sin dramatismo
        'soft':       '0 2px 8px -1px rgba(0,51,102,0.06), 0 1px 3px -1px rgba(0,51,102,0.04)',
        'soft-md':    '0 4px 16px -2px rgba(0,51,102,0.08), 0 2px 6px -2px rgba(0,51,102,0.05)',
        'soft-lg':    '0 8px 32px -4px rgba(0,51,102,0.10), 0 4px 12px -4px rgba(0,51,102,0.06)',
        'inner-soft': 'inset 0 1px 3px 0 rgba(0,51,102,0.06)',
        'gov':        '0 4px 24px -4px rgba(0,51,102,0.18)',
        'bio':        '0 4px 20px -4px rgba(0,166,126,0.30)',
        'none':       'none',
      },
      fontSize: {
        '2xs': ['0.625rem', { lineHeight: '0.875rem', letterSpacing: '0.08em' }],
        'xs':  ['0.75rem',  { lineHeight: '1rem',     letterSpacing: '0.04em' }],
      },
      letterSpacing: {
        'widest-2': '0.2em',
        'widest-3': '0.3em',
      },
      transitionTimingFunction: {
        'gov': 'cubic-bezier(0.25, 0.46, 0.45, 0.94)',
      },
      transitionDuration: {
        '250': '250ms',
        '400': '400ms',
      },
      animation: {
        'pulse-bio': 'pulse-bio 2.4s cubic-bezier(0.4,0,0.6,1) infinite',
        'scan':      'scan 1.8s ease-in-out infinite',
      },
      keyframes: {
        'pulse-bio': {
          '0%, 100%': { opacity: '1' },
          '50%':      { opacity: '0.5' },
        },
        'scan': {
          '0%':   { transform: 'translateY(-100%)', opacity: '0' },
          '20%':  { opacity: '1' },
          '80%':  { opacity: '1' },
          '100%': { transform: 'translateY(100%)', opacity: '0' },
        },
      },
    },
  },
  plugins: [],
}
