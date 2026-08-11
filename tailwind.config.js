import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                navy: {
                    DEFAULT: '#17324D',
                    light: '#E8F0F6',
                    dark: '#102337',
                },
                teal: {
                    DEFAULT: '#168A8A',
                    dark: '#116D6D',
                },
                gold: {
                    DEFAULT: '#D9A441',
                    dark: '#B9862A',
                },
                charcoal: '#1F2933',
                'brand-slate': '#64748B',
                offwhite: '#F7F8FA',
                'brand-border': '#E2E8F0',
                success: '#2E8B57',
                warning: '#D99000',
                danger: '#C94A4A',
            },
        },
    },

    plugins: [forms],
};
