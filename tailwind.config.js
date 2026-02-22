import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                orca: {
                    // black: '#2d2d2d',
                    black: '#4d4d4d',
                    // 'black-hover': '#1a1a1a',
                    'black-hover': '#2d2d2d',
                    gray: '#4a4a4a',
                    'gray-light': '#6b7280',
                    teal: '#14b8a6',
                    'teal-hover': '#0d9488',
                },
            },
            screens: {
                'xxl': '1680px',
                // => @media (min-width: 1680px) { ... }
            }
        },
    },

    plugins: [forms],
};
