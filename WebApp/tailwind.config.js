/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        institutional: {
          50: '#F2F8F1',
          100: '#E6F0E4',
          200: '#C8DBC0',
          300: '#A3C293',
          400: '#8CB369', // Verde acento
          500: '#6B9E78',
          600: '#4A7C59', // Verde medio
          700: '#2D5A42',
          800: '#1E4D38', // Verde oscuro icono
          900: '#0D3A26', // Verde NEXO texto (Main)
          950: '#051A11',
        },
      },
      borderRadius: {
        '3xl': '1.5rem',
        '4xl': '2rem',
      },
      boxShadow: {
        'soft': '0 4px 20px -2px rgba(0, 0, 0, 0.05)',
        'soft-dark': '0 4px 20px -2px rgba(0, 0, 0, 0.3)',
      }
    },
  },
  plugins: [],
}
