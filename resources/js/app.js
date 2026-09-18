import './bootstrap';

import Alpine from 'alpinejs';

/**
 * Estado da moldura: menu recolhido e tema.
 *
 * As duas escolhas moram no navegador, não na sessão — são preferência de
 * quem está usando aquela máquina, e precisam valer já na PRIMEIRA pintura da
 * página seguinte. Por isso quem decide é um script no <head>
 * (`layouts/app.blade.php`), que põe as classes `theme-light` e `rail-fechado`
 * no <html>. Aqui só continuamos a partir do que ele decidiu: a aparência é
 * sempre do CSS, nunca de um `:class` que chega tarde.
 */
Alpine.data('shell', () => ({
    // Lidos do <html>: repetir a leitura do localStorage aqui só criaria a
    // chance de os dois discordarem.
    railAberto: !document.documentElement.classList.contains('rail-fechado'),

    // Gaveta é coisa de tela estreita: some junto com a navegação.
    gavetaAberta: false,

    /**
     * O painel do sino.
     *
     * O estado mora AQUI, e não num `x-data` dentro da sidebar, porque o painel
     * precisa ser irmão do <aside> e não filho dele: o aside tem
     * `overflow: hidden` (para a transição de largura do rail) e um `transform`
     * (para a gaveta do celular). O overflow corta qualquer filho que passe da
     * borda, e o transform faz o aside virar bloco contido — o que impede até
     * `position: fixed` de escapar. Com o botão dentro e o painel fora, os dois
     * precisam de um estado em comum, e o `shell` é o ancestral dos dois.
     */
    sinoAberto: false,

    /*
     * O sino ao vivo.
     *
     * Antes o contador só era calculado quando a página carregava: chegava uma
     * notificação e nada na tela mudava — a pessoa só descobria clicando no
     * sino. Estas quatro peças resolvem isso sem recarregar nada.
     *
     * `naoLidas` é a bolinha, agora reativa. `sinoUltimoId` é o maior id que
     * ESTA página já conhece — é como o poll percebe que CHEGOU algo novo (um
     * id maior), e não só que "ainda há não lidas". `sinoNovas`/`sinoAviso` são
     * o card flutuante: ele aparece quando algo chega durante a sessão e fica
     * até a pessoa fechar (nada de relógio — quem saiu da frente não pode
     * perder o aviso). O pulso do sino acompanha o card.
     *
     * A configuração (baseline e URL) vem do servidor por `window.__sino`, que
     * a moldura injeta — nulo para a conta de exibição, que não recebe aviso e
     * não deve gastar poll no monitor da parede.
     */
    naoLidas: (window.__sino && window.__sino.naoLidas) || 0,
    sinoUltimoId: (window.__sino && window.__sino.ultimoId) || 0,
    sinoNovas: 0,
    sinoAviso: false,
    sinoUltima: null,
    sinoListaId: (window.__sino && window.__sino.ultimoId) || 0,

    tema: document.documentElement.classList.contains('theme-light') ? 'claro' : 'escuro',

    alternarRail() {
        this.railAberto = !this.railAberto;
        document.documentElement.classList.toggle('rail-fechado', !this.railAberto);
        this.lembrar('alfamatriz:rail', this.railAberto ? 'aberto' : 'fechado');
    },

    alternarTema() {
        this.tema = this.tema === 'claro' ? 'escuro' : 'claro';
        document.documentElement.classList.toggle('theme-light', this.tema === 'claro');
        this.lembrar('alfamatriz:tema', this.tema);
    },

    init() {
        this.vigiarSino();
    },

    /**
     * O poll do sino: a cada ~45s pergunta ao servidor o contador e o último id.
     *
     * Pausa com a aba escondida (o `setInterval` continua, mas a viagem é
     * pulada) e dispara na hora em que a aba volta ao foco — quem estava noutra
     * janela vê o número certo assim que volta, sem esperar o próximo tique. Um
     * erro de rede não quebra nada: o tique seguinte tenta de novo.
     */
    vigiarSino() {
        if (! window.__sino) {
            return; // conta de exibição, ou sem sessão: nada a vigiar.
        }

        this.checarSino();

        setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.checarSino();
            }
        }, 45000);

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.checarSino();
            }
        });
    },

    async checarSino() {
        try {
            const resposta = await fetch(window.__sino.url, {
                headers: { Accept: 'application/json' },
            });

            if (! resposta.ok) {
                return;
            }

            const dados = await resposta.json();
            this.naoLidas = dados.nao_lidas;

            // Id maior do que esta página conhecia = chegou coisa nova. O card
            // aparece e o sino pulsa; o número no card é o de não lidas, que é
            // o que está esperando a pessoa.
            if (dados.ultimo_id > this.sinoUltimoId) {
                this.sinoUltimoId = dados.ultimo_id;
                this.sinoNovas = dados.nao_lidas;
                this.sinoUltima = dados.ultima;
                this.sinoAviso = true;
            }
        } catch (erro) {
            // Sem rede: o número fica o que era e o próximo tique tenta de novo.
        }
    },

    /**
     * Abrir o sino é reconhecer o que chegou: o card some e o pulso para.
     *
     * E, se algo chegou depois de a página carregar, o painel é atualizado
     * ANTES de abrir — senão o contador subia mas a lista continuava a da carga
     * inicial ("Nada de novo" com a bolinha em 1). Busca a lista renderizada e
     * troca só o miolo; a marca da linha mora no Blade, um lugar só.
     */
    async abrirSino() {
        if (window.__sino && this.sinoUltimoId > this.sinoListaId) {
            try {
                const resposta = await fetch(window.__sino.urlLista, {
                    headers: { Accept: 'text/html' },
                });

                if (resposta.ok) {
                    const alvo = document.getElementById('sino-lista');
                    if (alvo) {
                        alvo.innerHTML = await resposta.text();
                        this.sinoListaId = this.sinoUltimoId;
                    }
                }
            } catch (erro) {
                // Sem rede: abre com a lista que tem. O contador já avisou que
                // há algo; a lista fresca vem na próxima abertura.
            }
        }

        this.sinoAberto = true;
        this.sinoAviso = false;
    },

    dispensarAvisoSino() {
        this.sinoAviso = false;
    },

    /** Navegação anônima e cotas cheias derrubam o localStorage — e nada disso
     *  justifica quebrar a tela: a preferência simplesmente não sobrevive. */
    lembrar(chave, valor) {
        try {
            localStorage.setItem(chave, valor);
        } catch (erro) {
            // preferência não persistida; a sessão atual continua valendo
        }
    },
}));

window.Alpine = Alpine;

Alpine.start();
