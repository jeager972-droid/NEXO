/**
 * WebApp Tailwind config / NEXO Institucional
 * Responsabilidad: Tokens de diseño del sistema: paleta institucional (gov, bio,
 * surface, alert), tipografía Inter, sombras suaves, animaciones pulse-bio/scan y
 * espaciados extendidos. darkMode basado en clase.
 * Dependencias: tailwindcss v3, postcss.config.js con autoprefixer.
 */
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
        sans: ['Inter Variable', 'Inter', 'system-ui', 'sans-serif'],
        mono: ['JetBrains Mono', 'monospace'],
      },
      colors: {
        // NEXO Quiet Operations — OKLCH semantic tokens (03_DESIGN_SYSTEM.md)
        canvas: {
          DEFAULT: 'oklch(94% 0.025 165)',
          dark:    'oklch(16% 0.020 165)',
        },
        surface: {
          DEFAULT:     'oklch(99% 0.010 80)',
          dark:        'oklch(21% 0.012 80)',
          subtle:      'oklch(96% 0.016 80)',
          'subtle-dark': 'oklch(25% 0.014 80)',
        },
        // Semantic text colors
        ink: {
          DEFAULT: 'oklch(22% 0.030 260)',
          dark:    'oklch(94% 0.010 260)',
          muted:   'oklch(52% 0.025 260)',
          'muted-dark': 'oklch(72% 0.018 260)',
        },
        // Borders
        line: {
          DEFAULT: 'oklch(89% 0.014 80)',
          dark:    'oklch(35% 0.016 80)',
        },
        // Accent — Azul indigo institucional
        accent: {
          DEFAULT: 'oklch(46% 0.130 255)',
          dark:    'oklch(72% 0.115 255)',
          text:    'oklch(99% 0.005 255)',
          surface: 'oklch(from oklch(46% 0.130 255) l c h / 0.08)',
        },
        // Success — Verde esmeralda
        success: {
          DEFAULT: 'oklch(52% 0.130 155)',
          dark:    'oklch(72% 0.120 155)',
          surface: 'oklch(from oklch(52% 0.130 155) l c h / 0.10)',
        },
        // Warning — Naranja cálido
        warning: {
          DEFAULT: 'oklch(62% 0.140 70)',
          dark:    'oklch(78% 0.125 70)',
          surface: 'oklch(from oklch(62% 0.140 70) l c h / 0.10)',
        },
        // Danger — Rojo coral
        danger: {
          DEFAULT: 'oklch(52% 0.180 25)',
          dark:    'oklch(72% 0.150 25)',
          surface: 'oklch(from oklch(52% 0.180 25) l c h / 0.10)',
        },
      },
      spacing: {
        // NEXO scale: 4, 8, 12, 16, 24, 32, 48, 64
        '4.5': '1.125rem',
        '13':  '3.25rem',
        '15':  '3.75rem',
        '18':  '4.5rem',
        '22':  '5.5rem',
        '26':  '6.5rem',
        '30':  '7.5rem',
      },
      borderRadius: {
        // 03_DESIGN_SYSTEM.md: 8 controls, 12 surfaces, 16 panels
        'control': '8px',
        'surface': '12px',
        'panel':   '16px',
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
        // 03_DESIGN_SYSTEM.md: sombras solo para superposición, nunca decoración
        'low':    '0 1px 2px 0 oklch(23% 0.018 245 / 0.04)',
        'medium': '0 2px 8px -1px oklch(23% 0.018 245 / 0.06), 0 1px 3px -1px oklch(23% 0.018 245 / 0.04)',
        'high':   '0 8px 24px -4px oklch(23% 0.018 245 / 0.08), 0 4px 12px -4px oklch(23% 0.018 245 / 0.05)',
        'dialog': '0 12px 40px -8px oklch(23% 0.018 245 / 0.12), 0 4px 16px -4px oklch(23% 0.018 245 / 0.06)',
        'none':   'none',
      },
      fontSize: {
        // 03_DESIGN_SYSTEM.md typography tokens — reduced for denser, calmer layouts
        'display':  ['1.625rem', { lineHeight: '2rem',     fontWeight: '650' }],
        'h1':       ['1.5rem',   { lineHeight: '1.875rem', fontWeight: '650' }],
        'h2':       ['1.1875rem',{ lineHeight: '1.5rem',   fontWeight: '620' }],
        'h3':       ['1rem',     { lineHeight: '1.375rem', fontWeight: '620' }],
        'body':     ['0.875rem', { lineHeight: '1.25rem',  fontWeight: '430' }],
        'body-sm':  ['0.75rem',  { lineHeight: '1rem',     fontWeight: '450' }],
        'label':    ['0.75rem',  { lineHeight: '0.9375rem',fontWeight: '600' }],
        'caption':  ['0.625rem', { lineHeight: '0.8125rem',fontWeight: '520' }],
        // Cifras de métrica (moodboard .nx-stat-num) y eyebrow de sección
        'metric':   ['1.5rem',   { lineHeight: '1.75rem',  fontWeight: '650' }],
        'metric-lg':['1.875rem', { lineHeight: '2.125rem', fontWeight: '650' }],
        'eyebrow':  ['0.5625rem',{ lineHeight: '0.75rem',  fontWeight: '620', letterSpacing: '0.08em' }],
        '2xs':      ['0.5rem',   { lineHeight: '0.6875rem',letterSpacing: '0.08em' }],
        'xs':       ['0.625rem', { lineHeight: '0.8125rem',letterSpacing: '0.04em' }],
      },
      letterSpacing: {
        'widest-2': '0.2em',
        'widest-3': '0.3em',
      },
      maxWidth: {
        // 05 §6: no se estira la lectura más allá de 1280px
        'content': '1280px',
        'reading': '68ch',
      },
      transitionTimingFunction: {
        // 03_DESIGN_SYSTEM.md: cubic-bezier(.22,1,.36,1)
        'out': 'cubic-bezier(0.22, 1, 0.36, 1)',
        'gov': 'cubic-bezier(0.25, 0.46, 0.45, 0.94)',
      },
      transitionDuration: {
        // 03_DESIGN_SYSTEM.md: instant 0, fast 150, standard 200, deliberate 250, max 300
        'instant': '0ms',
        'fast':    '150ms',
        'standard':'200ms',
        'deliberate': '250ms',
        'max':     '300ms',
        '250': '250ms',
        '400': '400ms',
      },
      animation: {
        'pulse-bio': 'pulse-bio 2.4s cubic-bezier(0.4,0,0.6,1) infinite',
        'scan':      'scan 1.8s ease-in-out infinite',
        'skeleton':  'skeleton 1.8s ease-in-out infinite',
        // 08 §2: confirmación de éxito, una sola vez, ≤300ms
        'seal':      'seal 260ms cubic-bezier(0.22,1,0.36,1) both',
        'halo':      'halo 900ms cubic-bezier(0.22,1,0.36,1) both',
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
        'skeleton': {
          '0%, 100%': { opacity: '1' },
          '50%':      { opacity: '0.5' },
        },
        'seal': {
          '0%':   { opacity: '0', transform: 'scale(0.82)' },
          '100%': { opacity: '1', transform: 'scale(1)' },
        },
        'halo': {
          '0%':   { opacity: '0.5', transform: 'scale(0.9)' },
          '100%': { opacity: '0',   transform: 'scale(1.7)' },
        },
      },
    },
  },
  plugins: [],
}
