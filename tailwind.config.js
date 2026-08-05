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
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                display: ['"Space Grotesk"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                canvas: '#060b0d',
                panel: '#0b1316',
                'panel-raised': '#101a1e',
                ink: '#e6eef0',
                'ink-dim': '#9db0b6',
                'ink-mute': '#8798a0',
                brand: {
                    DEFAULT: '#029caf',
                    dim: '#5be3ef',
                    bright: '#26d4e6',
                    mute: '#023d44',
                },
                amber: {
                    signal: '#e8a045',
                    chart: '#c98500',
                },
                status: {
                    good: '#0ca30c',
                    warning: '#fab219',
                    critical: '#d03b3b',
                },
            },
            boxShadow: {
                panel: '0 1px 0 0 rgba(255,255,255,0.04) inset, 0 8px 24px -12px rgba(0,0,0,0.6)',
            },
        },
    },

    plugins: [forms],
};
