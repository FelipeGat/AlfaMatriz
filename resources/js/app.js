import './bootstrap';

import Alpine from 'alpinejs';
import { centralIa } from './central-ia';
Alpine.data('centralIa', centralIa);

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
/**
 * A cor da barra de status do iPhone (e da barra do Safari) é o fundo do tema
 * em uso. Lida do `--canvas`, e não escrita em hexadecimal: assim ela segue o
 * tema sem uma segunda tabela de cores. Roda ao carregar — o CSS já está
 * aplicado, porque o módulo é adiado — e a cada troca de tema.
 */
function pintarBarraDeStatus() {
    const canvas = getComputedStyle(document.documentElement).getPropertyValue('--canvas').trim();
    if (! canvas) return;
    let meta = document.querySelector('meta[name="theme-color"]');
    if (! meta) {
        meta = document.createElement('meta');
        meta.name = 'theme-color';
        document.head.appendChild(meta);
    }
    meta.content = `rgb(${canvas})`;
}
pintarBarraDeStatus();

/**
 * A folha de filtros do celular (`x-folha-filtros`). Abrir é só marcar o
 * formulário com `data-folha-aberta`: quem o transforma em folha é o CSS,
 * abaixo de 768px.
 *
 * `ativos` conta o que está ligado SEM a busca, que fica à vista ao lado do
 * botão. Conta pelo formulário, e não por uma lista de campos passada por
 * cada tela: assim filtro novo entra na conta sem ninguém lembrar dele.
 */
Alpine.data('folhaDeFiltros', () => ({
    aberta: false,
    ativos: 0,

    init() {
        this.formulario = this.$el.closest('form');
        if (! this.formulario) return;
        // No tique seguinte: os campos escondidos das prioridades nascem de um
        // `x-for` que o Alpine só desenha depois deste botão.
        this.$nextTick(() => this.contar());
    },

    contar() {
        // Por NOME, e não por campo: as prioridades das Tarefas são várias
        // marcadas que viajam em campos escondidos (`prioridade[]`), e contam
        // como um filtro só. Escondido sem `[]` é contexto da tela (seção,
        // competência, raias), e não filtro.
        const ligados = new Set();
        [...this.formulario.elements].forEach((campo) => {
            if (! campo.name || campo.disabled) return;
            if (['search', 'submit', 'button'].includes(campo.type)) return;
            if (campo.type === 'hidden' && ! campo.name.endsWith('[]')) return;
            if ((campo.type === 'checkbox' || campo.type === 'radio') && ! campo.checked) return;
            // Select conta quando sai da PRIMEIRA opção, que é o padrão da
            // tela — e nem sempre vazia: "Maior MRR" nas Revendas, "Vigiados"
            // na Manutenção. Contá-los acenderia o número sem filtro nenhum.
            if (campo.tagName === 'SELECT' && campo.value === (campo.options[0]?.value ?? '')) return;
            if (campo.value === '') return;
            ligados.add(campo.name);
        });
        this.ativos = ligados.size;
    },

    alternar() {
        this.aberta ? this.fechar() : this.abrir();
    },

    abrir() {
        this.aberta = true;
        this.formulario?.setAttribute('data-folha-aberta', '');
    },

    fechar() {
        if (! this.aberta) return;
        this.aberta = false;
        this.formulario?.removeAttribute('data-folha-aberta');
    },
}));

Alpine.data('shell', () => ({
    // Lidos do <html>: repetir a leitura do localStorage aqui só criaria a
    // chance de os dois discordarem.
    railAberto: !document.documentElement.classList.contains('rail-fechado'),

    // Gaveta é coisa de tela estreita: some junto com a navegação.
    gavetaAberta: false,
    gruposMenuFechados: (() => {
        try { const v = JSON.parse(localStorage.getItem('alfamatriz:grupos-menu') || '[]'); return Array.isArray(v) ? v : []; }
        catch { return []; }
    })(),
    alternarGrupoMenu(grupo) {
        this.gruposMenuFechados = this.gruposMenuFechados.includes(grupo)
            ? this.gruposMenuFechados.filter(g => g !== grupo) : [...this.gruposMenuFechados, grupo];
        this.lembrar('alfamatriz:grupos-menu', JSON.stringify(this.gruposMenuFechados));
    },


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
        pintarBarraDeStatus();
        this.lembrar('alfamatriz:tema', this.tema);
    },

    /*
     * O voltar do celular (layouts/app.blade.php). Tela que é item do menu
     * volta ao "Mais" — a gaveta —, porque é de lá que se chega a ela, como
     * nas abas do iPhone. Tela de dentro volta à anterior; aberta direto
     * (link, notificação), sem anterior do próprio Matriz, cai no "Mais"
     * também, e não fora do app.
     */
    telaDoMenu: false,

    voltar() {
        const veioDaqui = document.referrer.startsWith(location.origin);
        if (! this.telaDoMenu && veioDaqui && history.length > 1) {
            history.back();
            return;
        }
        this.gavetaAberta = true;
    },

    init() {
        this.telaDoMenu = [...document.querySelectorAll('#menu-principal a')]
            .some(a => a.pathname === location.pathname);
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
     * Marca UM aviso como lido, sem sair da tela (22/10/2026): a linha perde a barra e o
     * fundo de não lida, o botão some e o contador vem do servidor.
     */
    async marcarLida(id, botao) {
        try {
            const resposta = await fetch(`/notificacoes/${id}/lida`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
            });
            if (! resposta.ok) {
                return;
            }
            this.naoLidas = (await resposta.json()).nao_lidas;
            const linha = botao.closest('a');
            linha?.classList.remove('bg-chip');
            linha?.classList.add('border-l-transparent');
            if (linha) linha.style.borderLeftColor = '';
            botao.remove();
        } catch (erro) {
            // Sem rede: fica como estava; a pessoa tenta de novo.
        }
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
     * Dois toques curtos, subindo — gerados pelo Web Audio, sem
     * arquivo de som para servir, versionar e esperar carregar. Curto de
     * propósito — é aviso de trabalho num escritório, não alarme —, mas alto o
     * bastante para ser ouvido com a cabeça em outra coisa.
     */
    bipe() {
        const contexto = this.contextoDeAudio();

        if (! contexto || contexto.state !== 'running') {
            return; // ainda sem gesto na página: fica o pulso e o card.
        }

        const agora = contexto.currentTime;

        // 0,18 s a 35% do volume, cada toque. Saiu a 0,1 s e 8%, e o dono
        // conferiu em produção: "muito discreta" — passava despercebido, que
        // é justamente o que o som existe para não deixar acontecer.
        [[880, 0], [1320, 0.2]].forEach(([frequencia, atraso]) => {
            const oscilador = contexto.createOscillator();
            const volume = contexto.createGain();

            oscilador.type = 'sine';
            oscilador.frequency.value = frequencia;
            volume.gain.setValueAtTime(0.0001, agora + atraso);
            volume.gain.exponentialRampToValueAtTime(0.35, agora + atraso + 0.01);
            volume.gain.exponentialRampToValueAtTime(0.0001, agora + atraso + 0.18);

            oscilador.connect(volume).connect(contexto.destination);
            oscilador.start(agora + atraso);
            oscilador.stop(agora + atraso + 0.19);
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

/*
 * O nome da coluna em cada célula, para a tabela virar cartão no celular.
 *
 * O cartão mostra "Status  Em dia" em vez de uma coluna sob o título
 * "Status"; o CSS escreve o rótulo com `attr(data-rotulo)`, e é aqui que ele
 * nasce, copiado do cabeçalho. Copiar em vez de escrever à mão em cada `<td>`
 * das 40 tabelas: o rótulo escrito à mão é o que fica para trás quando alguém
 * renomeia a coluna.
 *
 * A coluna sem título ou de "Ações" leva rótulo vazio: botão não precisa de
 * nome ao lado. Tabela sem cabeçalho próprio herda o da anterior — é o caso
 * do Acesso do Monitor, que desenha um cabeçalho só para várias tabelas
 * empilhadas. Um observador repete o serviço quando a tela troca linhas sem
 * recarregar (filtros, paginação, Alpine).
 */
function rotularTabelas() {
    let anteriores = [];
    document.querySelectorAll('table[data-cartoes]').forEach((tabela) => {
        const linha = tabela.querySelector('thead tr:last-child');
        let rotulos = anteriores;
        if (linha) {
            rotulos = [];
            for (const th of linha.children) {
                const texto = th.textContent.replace(/\s+/g, ' ').trim();
                const nome = /^a[çc][õo]es$/i.test(texto) ? '' : texto;
                for (let i = 0; i < (th.colSpan || 1); i++) rotulos.push(nome);
            }
            anteriores = rotulos;
        }
        tabela.querySelectorAll('tbody tr, tfoot tr').forEach((tr) => {
            let coluna = 0;
            for (const td of tr.children) {
                const rotulo = td.colSpan > 1 ? '' : (rotulos[coluna] ?? '');
                if (td.dataset.rotulo !== rotulo) td.dataset.rotulo = rotulo;
                coluna += td.colSpan || 1;
            }
        });
    });
}

let rotulagemPendente = false;
function pedirRotulagem() {
    if (rotulagemPendente) return;
    rotulagemPendente = true;
    requestAnimationFrame(() => {
        rotulagemPendente = false;
        rotularTabelas();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    rotularTabelas();
    new MutationObserver((mudancas) => {
        if (mudancas.some((m) => [...m.addedNodes].some((n) => n.nodeType === 1 && (n.matches('tr, table, tbody') || n.querySelector?.('table[data-cartoes]'))))) {
            pedirRotulagem();
        }
    }).observe(document.body, { childList: true, subtree: true });
});
