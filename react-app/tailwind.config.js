/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        bg: '#0A0B10',
        s1: '#111318',
        s2: '#181B22',
        s3: '#1F2330',
        border: '#2A2E3A',
        primary: '#7C3AED',
        accent: '#00E5A8',
        blue: '#3B82F6',
        amber: '#F59E0B',
        red: '#F87171',
        purple: '#A78BFA',
        pink: '#EC4899',
        cyan: '#06B6D4',
        orange: '#F97316',
      },
      fontFamily: {
        heading: ['Manrope', 'sans-serif'],
        body: ['Inter', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
