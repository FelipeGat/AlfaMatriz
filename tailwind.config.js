import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import plugin from 'tailwindcss/plugin';

/**
 * Sistema visual do AlfaMatriz — redesign 2026 (feature `redesign-visual`).
 *
 * Nenhuma cor mora aqui: os valores estão em `resources/css/app.css`, como
 * custom properties declaradas duas vezes — `:root` (tema escuro, padrão) e
 * `.theme-light` (tema claro). Este arquivo só dá nome semântico a elas.
 * Trocar de tema é trocar o conjunto inteiro numa classe do <html>.
 *
 * Duas famílias de token, por causa da opacidade:
 *  - cor CHEIA (tinta, marca, status) vira `<canal> <canal> <canal>`, para o
 *    Tailwind poder aplicar `/50` — ex.: `text-ink/70`, `bg-brand/20`.
 *  - cor de SUPERFÍCIE (véu sobre o fundo, régua, borda) já nasce com alpha
 *    própria e entra como valor inteiro — não aceita o modificador `/`.
 */

/** Cor cheia: aceita o modificador de opacidade do Tailwind. */
const cheia = (nome) => `rgb(var(--${nome}) / <alpha-value>)`;

/** Cor de superfície: já traz a própria transparência. */
const veu = (nome) => `var(--${nome})`;

/** @type {import('tailwindcss').Config} */
export default {
    /**
     * As fontes que o Tailwind varre.
     *
     * `storage/framework/views` saiu daqui, e a razão é que ele tornava o build
     * NÃO DETERMINÍSTICO: aquela pasta é o cache de views compiladas, então o
     * CSS gerado dependia de quais telas alguém tinha aberto antes de compilar.
     * O mesmo commit produzia bundles diferentes — 74kB com o cache quente,
     * 66kB com ele frio —, e "por que o CSS mudou sem ninguém mexer em CSS" é
     * das perguntas mais caras de responder.
     *
     * O que ele acrescentava era só a página de erro do modo debug do Laravel
     * (`Foundation/resources/exceptions/renderer`), que **injeta o próprio CSS
     * compilado** e nunca usou este bundle. As demais views de vendor que
     * chegam a ser compiladas são as de paginação — que continuam listadas
     * explicitamente acima, sem depender de cache nenhum — e as de e-mail, que
     * usam estilo inline por exigência dos clientes de e-mail.
     *
     * View de vendor que um dia precise deste CSS entra aqui pelo caminho dela,
     * como a paginação: explícita, e não por efeito colateral de alguém ter
     * aberto uma tela antes do `npm run build`.
     */
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                // Corpo, rótulo de formulário, texto de tabela, botão.
                sans: ['Geist', ...defaultTheme.fontFamily.sans],
                // Título de tela e de painel, e todo número grande de destaque.
                display: ['"Space Grotesk"', ...defaultTheme.fontFamily.sans],
                // Caixa alta, número tabular, data, delta, eixo, atalho.
                mono: ['"Geist Mono"', '"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },

            colors: {
                // ---- estrutura ----
                canvas: cheia('canvas'),          // fundo da aplicação
                panel: {
                    DEFAULT: cheia('panel'),      // sidebar, cabeçalho de painel
                    raised: cheia('panel-raised'),
                },
                head: cheia('head'),              // faixa de cabeçalho, rodapé de tabela
                surface: veu('surface'),          // superfície de card
                subtle: veu('subtle'),            // superfície de painel de lista
                board: veu('board'),              // fundo recuado do quadro do funil
                'card-quadro': veu('card-quadro'), // card do quadro de Tarefas
                input: veu('input'),              // input, select, busca
                chip: veu('chip'),                // chip, hover de linha, tile neutro

                // ---- linhas ----
                line: veu('line'),                // borda de painel, divisória estrutural
                rule: veu('rule'),                // divisória entre linhas de tabela
                'rule-strong': veu('rule-strong'), // divisória entre grupos no rail
                'btn-line': veu('btn-line'),      // borda de botão secundário
                'bar-track': veu('bar-track'),    // trilha de barra de progresso

                // ---- tinta ----
                ink: {
                    DEFAULT: cheia('ink'),        // texto primário
                    dim: cheia('ink-dim'),        // texto secundário
                    mute: cheia('ink-mute'),      // rótulo, metadado
                    faint: cheia('ink-faint'),    // texto terciário (mín. 4,5:1)
                },

                // ---- marca ----
                brand: {
                    DEFAULT: cheia('brand'),      // igual nos dois temas
                    dim: cheia('brand-text'),     // marca em TEXTO (muda por tema)
                    text: cheia('brand-text'),
                    bright: cheia('brand-bright'), // hover de botão primário
                    mute: cheia('brand-mute'),
                },
                'on-brand': cheia('on-brand'),    // texto sobre fundo de marca
                accent: cheia('accent'),          // sparkline, acento
                glow: veu('glow'),                // halo radial
                'nav-active': veu('nav-active'),  // fundo do item de menu ativo

                // ---- sinal ----
                good: cheia('good'),
                warn: cheia('warn'),
                crit: cheia('crit'),
                'chart-out': cheia('chart-out'),  // barra de saídas no gráfico
                amber: {
                    DEFAULT: cheia('amber'),
                    signal: cheia('amber'),
                    chart: cheia('chart-out'),
                },
                status: {
                    good: cheia('good'),
                    warning: cheia('warn'),
                    critical: cheia('crit'),
                },
                'good-tint': veu('good-tint'),
                'good-line': veu('good-line'),
                'warn-tint': veu('warn-tint'),
                'warn-line': veu('warn-line'),
                'crit-tint': veu('crit-tint'),
            },

            backgroundImage: {
                // Superfície dos cards de KPI e das barras de ciclo.
                'card-grad': 'var(--card-grad)',
            },

            /**
             * Raio aninhado e curto: filho sempre menor que o pai. O menu não
             * tem raio — é estrutura, não superfície.
             */
            borderRadius: {
                panel: '8px',   // painel, card, moldura
                control: '6px', // botão de ação, input, select, busca
                ctl: '5px',     // controle pequeno, cartão de lead
                tile: '4px',    // tile de ícone, ação em tabela, chip de conta
                badge: '3px',   // badge, tag, tecla ⌘K
            },

            spacing: {
                sidebar: '228px', // menu expandido
                rail: '60px',     // menu recolhido
                topbar: '56px',   // altura da topbar e do header da sidebar
                item: '34px',     // altura do item de menu
            },

            transitionDuration: {
                rail: '180ms',
            },

            /**
             * A única sombra do sistema, e ela é só do quadro de Tarefas.
             *
             * O painel decidiu não ter sombra — o contraste vem do hairline e
             * do degrau de superfície. O quadro é a exceção porque empilha
             * cards sobre um fundo RECUADO (`board`): sem o 1px de sombra eles
             * encostam no fundo e a pilha vira um bloco só. `card-arrasto` é a
             * mesma peça levantada, e a diferença entre as duas é o que diz que
             * o card saiu do lugar.
             */
            boxShadow: {
                card: 'var(--sombra-card)',
                'card-arrasto': 'var(--sombra-card-arrasto)',
            },

            letterSpacing: {
                caps: '0.14em',   // cabeçalho de tabela
                'caps-wide': '0.16em', // rótulo de seção
                'caps-max': '0.18em',  // rótulo de grupo do menu
            },
        },
    },

    plugins: [
        forms,

        /**
         * Variante `light:` para o punhado de casos em que trocar o token não
         * basta (uma opacidade, uma sombra, um `mix-blend`). O escuro é o
         * padrão, então a exceção que precisa de nome é o claro — não o
         * contrário. Regra: se dá para resolver trocando o token, troque o
         * token; `light:` espalhado pelas telas é cheiro de token faltando.
         */
        plugin(({ addVariant }) => {
            addVariant('light', ['.theme-light &', '.theme-light&']);

            /**
             * `rail:` descreve o menu recolhido. A classe vive no <html> e é
             * posta por um script no <head>, antes da primeira pintura — é o
             * que impede a marca de nascer num lugar e saltar para outro
             * quando o Alpine finalmente acorda.
             */
            addVariant('rail', ['.rail-fechado &', '.rail-fechado&']);
        }),
    ],
};
