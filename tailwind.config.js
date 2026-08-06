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
                // Interface inteira. Números têm família própria (mono) porque
                // é o que alinha as colunas de valor.
                sans: ['Geist', ...defaultTheme.fontFamily.sans],
                // A direção nova não usa fonte de display: títulos são Geist.
                display: ['Geist', ...defaultTheme.fontFamily.sans],
                mono: ['"Geist Mono"', ...defaultTheme.fontFamily.mono],
            },

            // Cada cor aponta para uma custom property definida em app.css.
            // É o que faz a troca de tema acontecer sem duplicar classe:
            // `bg-panel` vale nos dois temas, mudando só o valor da variável.
            colors: {
                bg: 'var(--bg)',
                sidebar: 'var(--sidebar)',
                panel: 'var(--panel)',
                raised: 'var(--raised)',
                line: {
                    DEFAULT: 'var(--border)',
                    soft: 'var(--border-soft)',
                },
                ink: 'var(--ink)',
                dim: 'var(--dim)',
                mute: 'var(--mute)',
                // Marca e gráfico são a MESMA cor viva, com usos distintos:
                // `brand` para detalhes de identidade, `chart` para séries.
                brand: 'var(--brand)',
                chart: 'var(--chart)',
                nav: {
                    active: 'var(--nav-active)',
                    hover: 'var(--nav-hover)',
                },
                good: 'var(--good)',
                warn: 'var(--warn)',
                bad: 'var(--bad)',
                track: 'var(--track)',
                track2: 'var(--track2)',

            },

            borderRadius: {
                card: '8px',
                summary: '8px',
                control: '6px',
                pill: '4px',
                modal: '12px',
            },

            // Sem sombra na interface: a separação é por borda e por diferença
            // de superfície. A única exceção é o que flutua sobre o conteúdo.
            boxShadow: {
                overlay: '0 10px 30px -10px rgba(0,0,0,.5)',
            },

            transitionDuration: {
                sidebar: '180ms',
            },
        },
    },

    plugins: [forms],
};
