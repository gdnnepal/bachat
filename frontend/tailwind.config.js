/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#eef7f1',
          100: '#d6ecdf',
          200: '#aedac0',
          300: '#7ec19b',
          400: '#4ea476',
          500: '#2f875a',
          600: '#226c47',
          700: '#1c563a',
          800: '#18452f',
          900: '#133727',
        },
      },
      fontFamily: {
        // Mangal / Noto keep Devanagari readable next to the Latin fallback.
        sans: ['Inter', 'Noto Sans Devanagari', 'Mangal', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
