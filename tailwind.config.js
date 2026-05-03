import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import plugin from 'tailwindcss/plugin';
/* These Tailwind colors have a grayscale effect for our ORCA theme */
const GRAYSCALE_HUES = [
    'blue', 'emerald', 'green', 'purple', 'red', 'pink',
    'teal', 'indigo', 'yellow', 'amber', 'orange', 'cyan',
];
const GRAYSCALE_BG_SHADES = ['500', '600', '700', '800'];
const GRAYSCALE_TEXT_SHADES = ['600', '700', '800', '900'];

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/Models/*.php',
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
                    //teal: '#14b8a6',
                    teal: '#66b2b2',
                    //'teal-hover': '#0d9488',
                    'teal-hover': '#006666',
                },
            },
            screens: {
                'xxl': '1680px',
                // => @media (min-width: 1680px) { ... }
            }
        },
    },

    plugins: [
        forms,
        plugin(({ addUtilities }) => {
            const selectors = [
                ...GRAYSCALE_HUES.flatMap(h => GRAYSCALE_BG_SHADES.map(s => `.bg-${h}-${s}`)),
                ...GRAYSCALE_HUES.flatMap(h => GRAYSCALE_TEXT_SHADES.map(s => `.text-${h}-${s}`)),
            ].join(', ');

            addUtilities({
                [selectors]: { filter: 'grayscale(0.65)' },
            });
        }),
    ],
};
