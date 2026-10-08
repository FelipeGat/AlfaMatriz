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

    // `sinoBaseline` é o maior id que a pessoa JÁ VIU — guardado no navegador
    // (localStorage), então sobrevive a recarga e a troca de página. É ele que
    // decide o alerta: se o servidor tem id maior que o baseline, há coisa não
    // vista, e o sino pulsa E o card aparece — mesmo que a notificação tenha
    // chegado enquanto a pessoa estava fora, ou antes de a página abrir. Sem
    // isso, o alerta só valia para o que chegava com a tela aberta, e lembrete
    // que caísse na ausência virava só a bolinha discreta — o problema que o
    // recurso existe para não ter.
    //
    // `sinoServidorId` é o último id que o servidor conhece (atualizado a cada
    // poll); `sinoListaId`, o maior que o PAINEL já desenhou (para saber quando
    // buscar a lista fresca ao abrir).
    sinoBaseline: 0,
    sinoServidorId: (window.__sino && window.__sino.ultimoId) || 0,
    sinoNovas: 0,
    sinoAviso: false,
    sinoUltima: null,
    sinoListaId: (window.__sino && window.__sino.ultimoId) || 0,

    // O som (#312). `sinoSomId` é o maior id SONORO que esta aba já conhece.
    // Nasce nulo e a PRIMEIRA consulta (feita ao abrir a página) só o
    // preenche, sem tocar: o som é de quem está com a tela aberta quando a
    // coisa chega, não do que já estava lá. Não vem no HTML da página para não
    // somar uma terceira consulta ao sino em toda tela (AC-243). `sinoSom` é a
    // preferência da conta.
    sinoSomId: null,
    sinoSom: !! (window.__sino && window.__sino.som),
    sinoAudio: null,

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
        this.sinoBaseline = this.lerSinoVisto();
        this.vigiarSino();
    },

    /**
     * O poll do sino: pergunta ao servidor o contador e o último id — a cada
     * 15s com a aba à vista, a cada 60s com ela escondida (#312).
     *
     * Já foi 45s e PARAVA com a aba escondida: quem trabalhava noutra aba só
     * ficava sabendo ao voltar, e o "na hora" do pedido não existia. Escondida,
     * o som é o único jeito de a notícia chegar, então o poll segue, mais
     * espaçado — o navegador já limita timers de aba de fundo a ~1/min, e
     * pedir menos que isso seria pedir o que ele não entrega. O tique é
     * curto e rearmado a cada volta, para a troca de ritmo valer na hora.
     *
     * Conexão aberta (SSE) e websocket ficaram de fora de propósito: cada
     * conexão aberta prende um worker do php-fpm, e o container tem cinco; o
     * Reverb seria um processo a mais para cair junto com o host. Duas
     * contagens rasas a cada 15s, para um time pequeno, é menos de uma
     * requisição por segundo.
     *
     * Volta ao foco dispara na hora — quem estava noutra janela vê o número
     * certo assim que volta. Erro de rede não quebra nada: o tique seguinte
     * tenta de novo.
     */
    vigiarSino() {
        if (! window.__sino) {
            return; // conta de exibição, ou sem sessão: nada a vigiar.
        }

        this.checarSino();
        this.destravarSomNoPrimeiroGesto();

        let ultimaVez = Date.now();

        setInterval(() => {
            const intervalo = document.visibilityState === 'visible' ? 15000 : 60000;

            if (Date.now() - ultimaVez >= intervalo - 1000) {
                ultimaVez = Date.now();
                this.checarSino();
            }
        }, 5000);

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                ultimaVez = Date.now();
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
            this.sinoServidorId = dados.ultimo_id;

            // Id maior que o BASELINE = há coisa que a pessoa ainda não viu. O
            // card aparece e o sino pulsa; o número no card é o de não lidas.
            // Não mexe no baseline aqui: ele só avança quando a pessoa
            // reconhece (abre o sino ou dispensa o card) — senão o alerta se
            // apagaria sozinho no poll seguinte, sem ninguém ter olhado.
            if (dados.ultimo_id > this.sinoBaseline) {
                this.sinoNovas = dados.nao_lidas;
                this.sinoUltima = dados.ultima;
                this.sinoAviso = true;
            }

            // Som só para o que DEPENDE da pessoa e chegou com a aba aberta.
            if (this.sinoSomId === null) {
                this.sinoSomId = dados.ultimo_sonoro_id;
            } else if (dados.ultimo_sonoro_id > this.sinoSomId) {
                this.sinoSomId = dados.ultimo_sonoro_id;
                this.tocarSino(dados.ultimo_sonoro_id);
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
        if (window.__sino && this.sinoServidorId > this.sinoListaId) {
            try {
                const resposta = await fetch(window.__sino.urlLista, {
                    headers: { Accept: 'text/html' },
                });

                if (resposta.ok) {
                    const alvo = document.getElementById('sino-lista');
                    if (alvo) {
                        alvo.innerHTML = await resposta.text();
                        this.sinoListaId = this.sinoServidorId;
                    }
                }
            } catch (erro) {
                // Sem rede: abre com a lista que tem. O contador já avisou que
                // há algo; a lista fresca vem na próxima abertura.
            }
        }

        this.sinoAberto = true;
        this.reconhecerSino();
    },

    dispensarAvisoSino() {
        this.reconhecerSino();
    },

    /**
     * Toca o aviso — uma vez por notificação, por mais abas que estejam
     * abertas: quem tem o quadro e a agenda abertos ouviria o mesmo aviso
     * duas vezes. A primeira aba que vê o id o reivindica no localStorage; as
     * outras encontram o id já tocado e ficam quietas.
     */
    tocarSino(id) {
        if (! this.sinoSom) {
            return;
        }

        try {
            const tocado = parseInt(localStorage.getItem('alfamatriz:sino-tocado'), 10);

            if (Number.isFinite(tocado) && tocado >= id) {
                return;
            }

            localStorage.setItem('alfamatriz:sino-tocado', String(id));
        } catch (erro) {
            // Sem localStorage cada aba toca por si; melhor duas vezes que nenhuma.
        }

        this.bipe();
    },

    /**
     * Dois toques curtos e baixos, subindo — gerados pelo Web Audio, sem
     * arquivo de som para servir, versionar e esperar carregar. Curto e
     * discreto de propósito: é aviso de trabalho num escritório, não alarme.
     */
    bipe() {
        const contexto = this.contextoDeAudio();

        if (! contexto || contexto.state !== 'running') {
            return; // ainda sem gesto na página: fica o pulso e o card.
        }

        const agora = contexto.currentTime;

        [[880, 0], [1320, 0.12]].forEach(([frequencia, atraso]) => {
            const oscilador = contexto.createOscillator();
            const volume = contexto.createGain();

            oscilador.type = 'sine';
            oscilador.frequency.value = frequencia;
            volume.gain.setValueAtTime(0.0001, agora + atraso);
            volume.gain.exponentialRampToValueAtTime(0.08, agora + atraso + 0.01);
            volume.gain.exponentialRampToValueAtTime(0.0001, agora + atraso + 0.1);

            oscilador.connect(volume).connect(contexto.destination);
            oscilador.start(agora + atraso);
            oscilador.stop(agora + atraso + 0.11);
        });
    },

    contextoDeAudio() {
        if (! this.sinoAudio) {
            const Contexto = window.AudioContext || window.webkitAudioContext;

            if (! Contexto) {
                return null;
            }

            this.sinoAudio = new Contexto();
        }

        return this.sinoAudio;
    },

    /**
     * O navegador só deixa tocar som depois de a pessoa interagir com a
     * página. O primeiro clique ou tecla destrava o áudio; antes disso, um
     * aviso que chegue fica no pulso e no card — e, como o quadro se usa no
     * clique, na prática isso já aconteceu quando alguma coisa chega.
     */
    destravarSomNoPrimeiroGesto() {
        const destravar = () => {
            const contexto = this.contextoDeAudio();

            if (contexto && contexto.state === 'suspended') {
                contexto.resume();
            }

            window.removeEventListener('pointerdown', destravar);
            window.removeEventListener('keydown', destravar);
        };

        window.addEventListener('pointerdown', destravar);
        window.addEventListener('keydown', destravar);
    },

    /**
     * Liga ou desliga o som. O ícone troca na hora; o servidor guarda na conta.
     * Ao LIGAR, toca uma vez — é a prova de que o som funciona nesta máquina, e
     * o clique é o gesto que o navegador exige.
     */
    async alternarSomDoSino() {
        this.sinoSom = ! this.sinoSom;

        if (this.sinoSom) {
            const contexto = this.contextoDeAudio();

            if (contexto && contexto.state === 'suspended') {
                await contexto.resume();
            }

            this.bipe();
        }

        try {
            const token = document.querySelector('meta[name="csrf-token"]');

            await fetch(window.__sino.urlSom, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token ? token.content : '',
                },
                body: JSON.stringify({ ligado: this.sinoSom }),
            });
        } catch (erro) {
            // Sem rede: vale nesta página; a preferência é regravada no próximo clique.
        }
    },

    /**
     * Reconhecer o que chegou: some o card, para o pulso, e AVANÇA o baseline
     * até o que o servidor tem — gravado no navegador, para a recarga seguinte
     * já nascer sabendo que isto foi visto. É o que impede o mesmo lembrete de
     * alertar de novo a cada página, sem apagar a bolinha: o número continua
     * sendo as não lidas de verdade, que só somem quando a pessoa marca lidas.
     */
    reconhecerSino() {
        this.sinoBaseline = this.sinoServidorId;
        this.sinoAviso = false;
        this.lembrar('alfamatriz:sino-visto', String(this.sinoBaseline));
    },

    /** Até que id o sino já foi visto — 0 na primeira visita (alerta o que
     *  houver), depois o que ficou guardado. */
    lerSinoVisto() {
        try {
            const guardado = parseInt(localStorage.getItem('alfamatriz:sino-visto'), 10);

            return Number.isFinite(guardado) ? guardado : 0;
        } catch (erro) {
            return 0;
        }
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
