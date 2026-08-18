@php
    /**
     * Os destinos cuja chegada exige um texto — o que abre o painel de motivo.
     *
     * Em andamento está na lista, mas com ressalva: só pede texto quando a
     * tarefa vem de um portão (ver `pedeTexto`). Vindo do Backlog é só começar
     * a trabalhar, e um painel ali pediria justificativa para pegar a própria
     * tarefa.
     *
     * Os portões de exame entraram DEPOIS de rodar sem eles: a primeira versão
     * deixava o arrasto passar direto (a escolha de quem revisa/testa era só
     * do menu), e no primeiro dia de uso o dono arrastou para a revisão
     * esperando a pergunta — arrasto que passa reto se lê como feature que não
     * existe. O painel deles não cobra texto: oferece uma pessoa, opcional.
     */
    $etapasComTexto = ['em_desenvolvimento', 'em_revisao', 'em_staging', 'cancelada', 'concluida', 'pronta_producao'];
@endphp

<x-app-layout>
    <x-slot name="titulo">Tarefas</x-slot>
    {{-- Com filtro ligado o cabeçalho diz "X de Y": sem o denominador, um
         quadro recortado é indistinguível de um quadro vazio. --}}
    <x-slot name="contexto">
        {{ $tarefas->count() < $totalNoQuadro
            ? $tarefas->count().' de '.$totalNoQuadro.' tarefas no quadro'
            : $tarefas->count().' tarefas no quadro' }}
    </x-slot>
    <x-slot name="acoes">
        <button type="button" x-data @click="$dispatch('open-modal', 'nova-tarefa')"
                class="h-[34px] px-3 rounded-control bg-brand text-on-brand font-semibold text-[12.5px]
                       hover:bg-brand-bright transition whitespace-nowrap">
            + Nova tarefa
        </button>
    </x-slot>

    {{--
        Mesmo truque do Funil de Vendas: a tela ocupa a altura da janela e o
        quadro cresce dentro dela, senão a coluna mais cheia estica a página
        inteira e as outras ficam desalinhadas.
    --}}
    <div class="flex flex-col gap-4" style="height: calc(100vh - 120px); min-height: 520px">
        {{-- O chip "N p/ você" NÃO vive aqui: ele é o primeiro dos chips do
             cabeçalho do quadro, junto das outras duas contagens. Solto acima
             das abas, ele seria a única contagem fora do quadro que conta. --}}
        @include('tarefas._abas', ['ativa' => 'quadro'])

        {{--
            NÃO há faixa de KPI aqui, e isso é decisão, não esquecimento.

            O `AlfaMatriz Sistema.dc.html` mostra quatro números no topo desta
            tela, mas ele é a VERSÃO RESUMIDA — o próprio README diz isso. A
            referência do quadro é o `AlfaMatriz Tarefas.dc.html`, e lá o quadro
            começa logo abaixo dos filtros e ocupa a altura inteira. Uma faixa
            de cards aqui rouba ~100px de uma tela cuja única queixa era caber
            pouco card por coluna.
        --}}
        {{-- O invólucro existe pela TELA CHEIA: lá o quadro cobre a página, e
             esta barra ficava embaixo dele — sem busca e sem filtros, e sem
             nada que dissesse onde eles foram parar. O botão do cabeçalho do
             quadro a traz para cima, flutuando (regra no `app.css`), em vez de
             uma segunda cópia dos mesmos selects. Ela é o alvo do atributo, e
             não o `<form>`, porque o `_filtros` é a partial que o histórico
             também usa — e lá não há tela cheia nenhuma. --}}
        <div data-barra-de-filtros class="shrink-0">
            @include('tarefas._filtros', [
                'filtros' => $filtros,
                'sistemas' => $sistemas,
                'usuarios' => $usuarios,
                'raias' => $raias,
            ])
        </div>

        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif
        @if (session('erro'))
            <x-aviso tom="critico">{{ session('erro') }}</x-aviso>
        @endif

        {{-- Onde aterrissa o aviso de uma ação que NÃO recarregou a página.
             Vazio no carregamento: quem chega por redirect é atendido pelos
             dois blocos de sessão acima. O `x-aviso` se teleporta sozinho para
             a pilha fixa do layout, então este bloco não ocupa altura nenhuma
             nem empurra o quadro para baixo quando recebe conteúdo. --}}
        <div data-avisos-parciais></div>

        {{-- O molde da recusa que não passou pelo controlador (validação,
             permissão, sessão vencida). Ele existe para o JavaScript não
             precisar remontar o `x-aviso`: copiar os tokens de cor para dentro
             de uma string divergiria do componente no primeiro ajuste de tema.
             O `<template>` não é desenhado nem lido por leitor de tela. --}}
        <template data-molde-de-erro>
            <x-aviso tom="critico" :segundos="0">__FRASE__</x-aviso>
        </template>

        {{-- O teclado escuta na JANELA: o quadro não recebe foco, e exigir que
             ele recebesse obrigaria a clicar no fundo antes de a primeira seta
             funcionar. Quem filtra o que não deve disparar é o próprio
             `aoTeclar` (campo em foco, tecla com modificador). --}}
        {{-- Antes da primeira pintura, como o tema faz no layout: sem isto o
             quadro nasceria na moldura e pularia para a tela cheia à vista de
             quem abriu a página. Falha em silêncio na navegação anônima — o
             modo simplesmente não vem ligado, que é o padrão. --}}
        <script>
            try {
                if (localStorage.getItem('alfamatriz:quadro-tela-cheia') === '1') {
                    document.documentElement.setAttribute('data-tela-cheia', '');
                }
            } catch (erro) {}
        </script>

        {{-- Clicar no quadro fecha a barra flutuante da tela cheia: ela cobre a
             primeira coluna, e a saída não pode ser só o botão que a abriu. --}}
        <div x-data="quadroTarefas" @keydown.window="aoTeclar($event)"
             @paste.window="colarImagens($event)" data-corpo-do-quadro
             @click="filtrosAbertos && alternarFiltros(false)"
             class="relative flex-1 min-h-0 flex flex-col rounded-panel border border-line bg-board overflow-hidden">
            @include('tarefas._quadro', [
                'chips' => $chips,
                'etapas' => $etapas,
                'raias' => $raias,
                'filtros' => $filtros,
                'recortes' => $recortes,
            ])
        </div>
    </div>

    {{--
        Script clássico e inline de propósito, como no `funil`: precisa
        registrar o componente antes de o Alpine iniciar, o que o bundle do
        Vite (módulo, portanto adiado) não garante.
    --}}
    <script>
        document.addEventListener('alpine:init', () => {
            /**
             * O checklist de uma tarefa: arrastar para reordenar.
             *
             * Mora aqui, e não num `x-data` no HTML, porque o atributo é
             * delimitado por aspas duplas — qualquer aspa dupla dentro dele,
             * até num comentário de código, o fecha no meio e entrega
             * JavaScript truncado ao Alpine. A lista continua aparecendo, e
             * nada nela responde.
             */
            Alpine.data('checklist', (tarefaId) => ({
                arrastando: null,

                soltarSobre(alvo) {
                    if (! this.arrastando || this.arrastando === alvo) {
                        return;
                    }

                    const lista = this.$refs.lista;
                    const linhas = [...lista.children];
                    const destino = linhas.indexOf(alvo);
                    const origem = linhas.indexOf(this.arrastando);

                    lista.insertBefore(this.arrastando, origem < destino ? alvo.nextSibling : alvo);
                    this.salvarOrdem();
                },

                /**
                 * A ordem vai INTEIRA, lida do DOM depois do arrasto.
                 *
                 * Mandar só "o item X foi para a posição N" obrigaria o
                 * servidor a recalcular o resto — e a divergir do que está na
                 * tela assim que dois arrastos chegassem fora de ordem.
                 */
                salvarOrdem() {
                    const envio = document.getElementById('ordenar-checklist-' + tarefaId);

                    envio.querySelectorAll('input[data-ordem]').forEach((campo) => campo.remove());

                    [...this.$refs.lista.children].forEach((linha) => {
                        const campo = document.createElement('input');
                        campo.type = 'hidden';
                        campo.name = 'ordem[]';
                        campo.dataset.ordem = '1';
                        campo.value = linha.dataset.item;
                        envio.appendChild(campo);
                    });

                    envio.requestSubmit();
                },
            }));

            /**
             * Os anexos de uma tarefa (US-064).
             *
             * Envia por `fetch`, e não por formulário como o checklist: o gesto
             * que esta seção serve é colar um print no meio de escrever o
             * comentário que o explica, e recarregar a tela ali descartaria o
             * texto ainda não publicado.
             *
             * `tarefaId` nulo é a CRIAÇÃO (AC-234): a mesma seção, o mesmo
             * gesto e a mesma cara, mas sem servidor do outro lado — não há id
             * a que prender arquivo. Ali os arquivos ficam na tela e saem no
             * POST que cria a tarefa, pelo `input` que o Blade nomeia; o que
             * muda é o destino de `enviar` e de `remover`, não o resto.
             */
            Alpine.data('anexosDaTarefa', (tarefaId, iniciais) => ({
                anexos: iniciais,
                enviando: false,
                erro: null,

                /** A tarefa ainda não existe: nada aqui fala com o servidor. */
                criacao: tarefaId === null,

                /** Só para dar chave ao `x-for` do que ainda não tem id no banco. */
                sequencia: 0,

                // A mesma separação que o Blade faz no modo leitura, refeita
                // aqui porque a lista muda sem a página recarregar: figura vai
                // para a grade de miniaturas, o resto vira linha.
                get imagens() {
                    return this.anexos.filter((anexo) => anexo.eh_imagem);
                },

                get arquivos() {
                    return this.anexos.filter((anexo) => ! anexo.eh_imagem);
                },

                // Na criação nada é enviado — o que ocupa o botão é o encolher
                // da figura grande. "Enviando…" ali prometeria uma viagem que
                // só vai acontecer no Salvar.
                get rotuloDoBotao() {
                    if (! this.enviando) {
                        return 'Anexar arquivo';
                    }

                    return this.criacao ? 'Preparando…' : 'Enviando…';
                },

                /**
                 * Os tetos do PHP de produção, repetidos aqui porque são eles
                 * que decidem se o arquivo chega ou não.
                 *
                 * `upload_max_filesize` são 12 MB e `post_max_size` são 16 MB
                 * (ver `deploy/provisionar.sh`). Os dois precisam ser
                 * respeitados, e o segundo é o traiçoeiro: estourá-lo faz o PHP
                 * descartar o corpo INTEIRO do POST, e o que chega ao navegador
                 * é um erro de CSRF sem relação nenhuma com tamanho. Por isso a
                 * SOMA do lote é conferida, e não só cada arquivo — três de
                 * 12 MB passam um a um e derrubam o envio juntos.
                 *
                 * 15 e não 16: o corpo multipart carrega os limites, os nomes
                 * dos campos e o token junto dos arquivos.
                 */
                teto: 12 * 1024 * 1024,
                tetoDoEnvio: 15 * 1024 * 1024,
                porEnvio: 3,

                /**
                 * Colar da área de transferência.
                 *
                 * O ouvinte é de `window` porque não há onde mais pendurá-lo:
                 * ninguém dá foco à seção antes de colar — cola-se com o
                 * cursor no campo de comentário, que é justamente onde se
                 * estava escrevendo. Em troca, ele precisa saber se ESTE modal
                 * é o que está aberto: o quadro desenha um por card, e sem a
                 * pergunta um Ctrl+V anexaria a mesma imagem em cinquenta
                 * tarefas de uma vez. `offsetParent` é nulo debaixo de um
                 * `display:none`, que é como o modal fechado fica.
                 */
                colar(evento) {
                    if (! this.$el.offsetParent || this.enviando) {
                        return;
                    }

                    // Qualquer arquivo, e não só imagem: copiar um `.log` no
                    // gerenciador de arquivos e colar aqui é o mesmo gesto de
                    // colar um print, e quem faz um espera o outro. O que
                    // continua passando direto é texto — `kind` só é `file`
                    // quando há arquivo de verdade, então colar uma frase no
                    // comentário segue sendo colar uma frase.
                    const arquivos = [...(evento.clipboardData?.items ?? [])]
                        .filter((item) => item.kind === 'file')
                        .map((item) => item.getAsFile())
                        .filter(Boolean);

                    if (! arquivos.length) {
                        return;
                    }

                    evento.preventDefault();
                    this.enviar(arquivos);
                },

                escolher(evento) {
                    const arquivos = [...evento.target.files];

                    // Zerar o campo ANTES de enviar: sem isso, escolher o mesmo
                    // arquivo duas vezes seguidas não dispara `change` na
                    // segunda — o valor não mudou —, e quem removeu uma imagem
                    // por engano não consegue anexá-la de novo.
                    //
                    // Na criação o campo não é zerado: ele é DEVOLVIDO à lista
                    // da tela, que é a carga do formulário. O seletor acabou de
                    // escrever ali só o que foi escolhido agora, e essa escolha
                    // ainda pode ser recusada logo abaixo — por tipo, tamanho ou
                    // por já haver três. Sem devolver, a tela mostraria os três
                    // anexos de sempre e o envio levaria o quarto, sozinho.
                    if (this.criacao) {
                        this.sincronizar();
                    } else {
                        evento.target.value = '';
                    }

                    if (arquivos.length) {
                        this.enviar(arquivos);
                    }
                },

                async enviar(arquivos) {
                    this.erro = null;

                    // Na criação o teto conta a LISTA INTEIRA, e não o lote: os
                    // três vão num POST só, junto do formulário, então não há
                    // "próxima leva" antes de a tarefa existir. Com a tarefa
                    // aberta, o mesmo três é por envio — e quem tem mais manda
                    // em duas vezes.
                    const lugares = this.porEnvio - (this.criacao ? this.anexos.length : 0);

                    if (arquivos.length > lugares) {
                        this.erro = this.criacao
                            ? 'A tarefa nasce com até ' + this.porEnvio + ' anexos — o resto entra com ela já aberta.'
                            : 'Até ' + this.porEnvio + ' arquivos por vez — os demais ficaram de fora.';
                        arquivos = arquivos.slice(0, Math.max(lugares, 0));

                        if (! arquivos.length) {
                            return;
                        }
                    }

                    this.enviando = true;

                    try {
                        // Encolher ANTES de somar: é a redução que decide o
                        // tamanho real do lote, e um print de 20 MB que vira
                        // 900 KB não pode ser recusado pelo que ele pesava
                        // antes de ser reduzido.
                        const preparados = [];

                        for (const arquivo of arquivos) {
                            preparados.push(await this.reduzir(arquivo));
                        }

                        // O que já está na tela pesa junto na criação: os
                        // anexados antes ainda não saíram daqui, e vão no mesmo
                        // corpo que os de agora.
                        const soma = preparados.reduce((total, arquivo) => total + arquivo.size, 0)
                            + (this.criacao ? this.anexos.reduce((total, anexo) => total + anexo.tamanho, 0) : 0);

                        // A recusa é DITA aqui porque o servidor não teria como
                        // dizê-la: passando de `post_max_size`, o PHP descarta o
                        // corpo antes de o Laravel existir, e a resposta que
                        // chega fala de CSRF. Melhor uma frase que diz o que
                        // fazer do que um erro que fala de outra coisa.
                        if (soma > this.tetoDoEnvio) {
                            throw new Error(
                                'Os arquivos somam ' + Math.round(soma / 1048576) + ' MB e o envio aceita até '
                                + Math.round(this.tetoDoEnvio / 1048576) + ' MB de uma vez — mande em duas levas.'
                            );
                        }

                        // Daqui em diante é viagem, e na criação não há para
                        // onde: o arquivo fica na tela até o Salvar levá-lo.
                        if (this.criacao) {
                            this.guardar(preparados);

                            return;
                        }

                        const corpo = new FormData();

                        for (const arquivo of preparados) {
                            corpo.append('anexos[]', arquivo);
                        }

                        const resposta = await fetch('{{ url('tarefas') }}/' + tarefaId + '/anexos', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                'Accept': 'application/json',
                            },
                            body: corpo,
                        });

                        if (! resposta.ok) {
                            const dados = await resposta.json().catch(() => ({}));

                            // A recusa da validação vem em `errors`, uma lista
                            // por campo. Sem juntá-las, "Cada arquivo precisa
                            // ter até 12 MB" viraria um "Falha ao enviar" que
                            // não diz o que mudar.
                            throw new Error(
                                Object.values(dados.errors ?? {}).flat().join(' ')
                                || dados.message
                                || 'Não foi possível anexar o arquivo.'
                            );
                        }

                        const dados = await resposta.json();
                        this.anexos = [...this.anexos, ...dados.anexos];
                    } catch (erro) {
                        this.erro = erro.message;
                    } finally {
                        this.enviando = false;
                    }
                },

                /**
                 * Guarda na tela o que ainda não tem tarefa a que se prender.
                 *
                 * O item nasce com os MESMOS campos que o servidor devolveria —
                 * `nome_original`, `tamanho_formatado`, `url`, `eh_imagem`,
                 * `autor_id` —, e é isso que faz a grade e a lista serem as
                 * mesmas nos dois modos. A alternativa era um segundo desenho
                 * da seção, que divergiria do primeiro no dia em que um dos dois
                 * mudasse.
                 *
                 * `id` é texto (`novo-1`) e não número de propósito: ele só
                 * serve de chave para o `x-for`, e um inteiro se pareceria com
                 * id de banco para quem chamasse `remover` sem olhar.
                 */
                guardar(arquivos) {
                    for (const arquivo of arquivos) {
                        // `blob:` do próprio navegador: a miniatura mostra o
                        // arquivo que está na máquina de quem anexou, porque o
                        // servidor ainda não o viu.
                        //
                        // Os dois endereços são o MESMO aqui, e este é o único
                        // lugar em que isso acontece: a versão pequena nasce no
                        // servidor, que ainda não recebeu este arquivo. Não
                        // custa nada — o `blob:` já está na máquina e não
                        // viaja. Depois do Salvar o servidor redesenha a tela e
                        // a grade passa a pedir a miniatura de verdade.
                        const url = URL.createObjectURL(arquivo);

                        this.anexos = [...this.anexos, {
                            id: 'novo-' + (++this.sequencia),
                            arquivo,
                            autor_id: {{ auth()->id() ?? 'null' }},
                            nome_original: this.batizar(arquivo),
                            tamanho: arquivo.size,
                            tamanho_formatado: this.emTamanho(arquivo.size),
                            eh_imagem: arquivo.type.startsWith('image/'),
                            url,
                            url_miniatura: url,
                        }];
                    }

                    this.sincronizar();
                },

                /**
                 * A lista da tela vira a carga do formulário.
                 *
                 * O campo de arquivo é o único que não se escreve: o que ele
                 * carrega só muda por escolha de quem usa, ou por uma
                 * `FileList` montada à mão — que é para isso que o
                 * `DataTransfer` existe. Sem esta linha, o que fosse COLADO
                 * nunca entraria no envio (nunca passou pelo seletor) e o que
                 * fosse REMOVIDO da tela continuaria indo junto.
                 *
                 * É também o que faz o print grande viajar já encolhido: volta
                 * para o campo o arquivo reduzido, não o que foi escolhido.
                 */
                sincronizar() {
                    const carga = new DataTransfer();

                    for (const anexo of this.anexos) {
                        carga.items.add(anexo.arquivo);
                    }

                    this.$refs.carga.files = carga.files;
                },

                /**
                 * O nome com que o anexo colado se apresenta.
                 *
                 * É a mesma regra do `nomeDeExibicao` do controlador, repetida
                 * aqui por um motivo que só existe na criação: a legenda aparece
                 * ANTES de o servidor ver o arquivo. Sem isto, três prints
                 * colados viram três "image.png" na tela e trocam de nome
                 * sozinhos depois de salvar — e a legenda existe justamente para
                 * distinguir o que a miniatura, pequena, já não distingue.
                 *
                 * O servidor continua batizando o que chega sem nome: é ele que
                 * alcança a tarefa já aberta e o envio sem JavaScript.
                 */
                batizar(arquivo) {
                    const nome = arquivo.name || '';

                    if (! ['', 'image', 'blob'].includes(nome.replace(/\.[^.]+$/, ''))) {
                        return nome;
                    }

                    const agora = new Date();
                    const dois = (valor) => String(valor).padStart(2, '0');

                    return 'captura-'
                        + agora.getFullYear() + '-' + dois(agora.getMonth() + 1) + '-' + dois(agora.getDate())
                        + '-' + dois(agora.getHours()) + dois(agora.getMinutes()) + dois(agora.getSeconds())
                        + '.' + (nome.includes('.') ? nome.split('.').pop() : 'png');
                },

                /** A mesma régua do `tamanho_formatado` do servidor — a lista
                 *  da criação é desenhada sem ele ter visto o arquivo. */
                emTamanho(bytes) {
                    if (bytes < 1024) {
                        return bytes + ' B';
                    }

                    if (bytes < 1048576) {
                        return Math.round(bytes / 1024 * 10) / 10 + ' KB';
                    }

                    return Math.round(bytes / 1048576 * 10) / 10 + ' MB';
                },

                /**
                 * O formulário foi esvaziado — a lista vai junto.
                 *
                 * Quem chama o `reset()` é o quadro, depois de a tarefa nascer:
                 * o "nova tarefa" é o único modal que o servidor não redesenha,
                 * então ele guardaria o que acabou de ser gravado. Sem isto, os
                 * anexos da tarefa criada continuariam na tela e a PRÓXIMA
                 * tarefa nasceria com eles — desta vez de verdade, porque o
                 * `reset` limpa o campo mas não a lista que o reescreve.
                 *
                 * `contains` porque o ouvinte é de `window`: o evento sobe de
                 * qualquer formulário da página, e só o que me contém é meu.
                 */
                esvaziar(evento) {
                    if (! this.criacao || ! evento.target.contains(this.$el)) {
                        return;
                    }

                    for (const anexo of this.anexos) {
                        URL.revokeObjectURL(anexo.url);
                    }

                    this.anexos = [];
                    this.erro = null;
                    this.sincronizar();
                },

                /**
                 * Encolhe a FIGURA que não caberia no POST — e só isso.
                 *
                 * Só figura, porque só ela pode ser redesenhada sem deixar de
                 * ser o que era: um log cortado pela metade não é um log menor,
                 * é outro arquivo. O que não é imagem e não cabe é recusado com
                 * a frase que diz o tamanho — não há cortesia possível.
                 *
                 * O arquivo que já cabe é enviado INTACTO, byte a byte: uma
                 * recodificação preventiva borraria o texto de todo print por
                 * um problema que aquele arquivo não tinha. Com o teto em 12 MB
                 * isso passou a valer para quase todo print — o caso que sobra
                 * aqui é a foto de máquina fotográfica, não a captura de tela,
                 * que antes do teto novo era reduzida à toa.
                 *
                 * Se algo aqui falhar (navegador antigo, imagem corrompida), o
                 * original segue como estava e quem responde é o servidor, com
                 * a frase certa. Encolher é uma cortesia, não um pré-requisito.
                 */
                async reduzir(arquivo) {
                    if (arquivo.size <= this.teto || ! arquivo.type.startsWith('image/')) {
                        return arquivo;
                    }

                    try {
                        const bitmap = await createImageBitmap(arquivo);

                        // 1920 no maior lado: é a largura em que um print de
                        // tela continua legível linha a linha. Reduzir mais
                        // apagaria justamente o texto que o print foi anexado
                        // para mostrar.
                        const escala = Math.min(1, 1920 / Math.max(bitmap.width, bitmap.height));
                        const tela = document.createElement('canvas');
                        tela.width = Math.round(bitmap.width * escala);
                        tela.height = Math.round(bitmap.height * escala);
                        tela.getContext('2d').drawImage(bitmap, 0, 0, tela.width, tela.height);
                        bitmap.close();

                        // JPEG, e em três qualidades: PNG redesenhado por canvas
                        // costuma sair MAIOR que o original (ele perde a
                        // otimização de quem o gerou), e aí a redução não
                        // reduziria nada.
                        for (const qualidade of [0.92, 0.8, 0.65]) {
                            const blob = await new Promise((r) => tela.toBlob(r, 'image/jpeg', qualidade));

                            if (blob && blob.size <= this.teto) {
                                const nome = (arquivo.name || 'captura').replace(/\.[^.]+$/, '');

                                return new File([blob], nome + '.jpg', { type: 'image/jpeg' });
                            }
                        }
                    } catch (erro) {
                        // Segue com o original: o servidor recusa com a frase
                        // certa, que é melhor do que um erro de JavaScript.
                    }

                    return arquivo;
                },

                async remover(anexo) {
                    this.erro = null;

                    // Na criação o anexo nunca saiu daqui: some da tela, sai da
                    // carga e devolve o `blob:` ao navegador, sem ninguém a quem
                    // pedir licença — não há linha no banco nem arquivo no
                    // disco para desfazer.
                    if (this.criacao) {
                        this.anexos = this.anexos.filter((atual) => atual.id !== anexo.id);
                        URL.revokeObjectURL(anexo.url);
                        this.sincronizar();

                        return;
                    }

                    // Some da tela antes da resposta: a remoção é do próprio
                    // autor e o servidor já concordou com a mesma regra. Se
                    // recusar, ele volta — e aí a frase explica por quê.
                    const antes = this.anexos;
                    this.anexos = this.anexos.filter((atual) => atual.id !== anexo.id);

                    try {
                        const resposta = await fetch('{{ url('tarefas/anexos') }}/' + anexo.id, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                'Accept': 'application/json',
                            },
                        });

                        if (! resposta.ok) {
                            throw new Error('Não foi possível remover o anexo.');
                        }
                    } catch (erro) {
                        this.anexos = antes;
                        this.erro = erro.message;
                    }
                },
            }));

            Alpine.data('quadroTarefas', () => ({
                arrastando: null,
                sobre: null,

                /**
                 * As colunas recolhidas, guardadas entre visitas.
                 *
                 * Recolher é escolha de quem trabalha numa etapa só e não quer
                 * as outras cinco comendo largura — e ela não pode se desfazer
                 * a cada F5, senão vira um gesto que se repete todo dia. O
                 * `localStorage` é o mesmo lugar do tema e do rail.
                 */
                recolhidas: [],

                /**
                 * A tela cheia do quadro, guardada entre visitas.
                 *
                 * Quem trabalha no quadro fica nele o dia inteiro, e reativar o
                 * modo a cada visita seria o mesmo gesto repetido todo dia —
                 * a razão pela qual as colunas recolhidas também persistem.
                 *
                 * O que LIGA o modo é o atributo `data-tela-cheia` no <html>,
                 * escrito antes da primeira pintura (ver o script logo acima do
                 * quadro); esta propriedade só espelha o estado para o botão
                 * saber que cara ter. Fossem duas fontes de verdade, o botão
                 * mostraria "expandir" num quadro já expandido no primeiro
                 * carregamento.
                 */
                telaCheia: false,

                /**
                 * O painel de filtros da tela cheia. Mora AQUI, e não num
                 * `x-data` do botão, porque o Esc é do quadro: fechar o painel
                 * vem antes de sair da tela cheia, senão um Esc fecharia as
                 * duas — e a de fora é a que ninguém pediu (AC-359).
                 */
                filtrosAbertos: false,

                /**
                 * O elemento do card na mão e a vaga de onde ele saiu.
                 *
                 * A reordenação é ao vivo: o próprio card, meio apagado, abre o
                 * vão onde vai cair (decisão do dono em 16/08/2026, revendo a
                 * linha de inserção do design). Se o gesto morrer — Esc, soltar
                 * fora — a vaga original é o caminho de volta; `ordemEnviada`
                 * distingue o arrasto confirmado do abandonado.
                 */
                cardEmMovimento: null,
                vagaOriginal: null,
                ordemEnviada: false,

                /**
                 * A faixa de onde o card saiu, e a que está sob o ponteiro.
                 *
                 * Só diferem com raias ligadas. A faixa é o RESPONSÁVEL (ou o
                 * sistema) da tarefa, e arrastar não troca nenhum dos dois —
                 * quem troca é o campo do formulário. Sem elas, a célula da
                 * outra pessoa acendia, aceitava o solto e nada acontecia.
                 */
                faixaArrastada: null,
                faixaSobOPonteiro: null,

                init() {
                    try {
                        this.recolhidas = JSON.parse(
                            localStorage.getItem('alfamatriz:colunas-recolhidas') || '[]'
                        );
                    } catch (erro) {
                        this.recolhidas = [];
                    }

                    this.telaCheia = document.documentElement.hasAttribute('data-tela-cheia');

                    // A rolagem durante o arrasto: um ouvinte só, no documento
                    // e na captura. O porquê dos dois está em
                    // `acompanharPonteiro` — sem eles, o card na mão não vê
                    // metade do quadro.
                    document.addEventListener('dragover', (evento) => this.acompanharPonteiro(evento), true);

                    // Um ponto só decide onde o pedido de motivo aparece, e é
                    // este. `pendente` é zerado de quatro lugares — o cancelar,
                    // o Esc, o atalho e a resposta parcial lá do `aplicar()`, que
                    // mexe no estado de fora — e um caminho esquecido deixaria o
                    // painel aberto num card que já mudou de lugar.
                    this.$watch('pendente', (valor) => this.sincronizarPainelDoMotivo(valor));
                },

                /**
                 * Liga e desliga a tela cheia.
                 *
                 * O atributo vai no <html> e a regra que move o quadro mora no
                 * CSS (`app.css`): é o que permite o estado valer já na primeira
                 * pintura, sem o quadro pular de tamanho depois que o Alpine
                 * acorda.
                 */
                alternarTelaCheia() {
                    this.telaCheia = ! this.telaCheia;

                    document.documentElement.toggleAttribute('data-tela-cheia', this.telaCheia);

                    try {
                        localStorage.setItem('alfamatriz:quadro-tela-cheia', this.telaCheia ? '1' : '0');
                    } catch (erro) {
                        // Navegação anônima ou cota cheia: vale para esta
                        // sessão e não sobrevive, o que não justifica quebrar
                        // o quadro.
                    }
                },

                /**
                 * Traz a barra de busca e filtros para cima do quadro expandido.
                 *
                 * O atributo vai na barra, que mora FORA deste componente — ela
                 * é irmã do quadro, e um `x-show` daqui não a alcança. É o mesmo
                 * caminho do `data-tela-cheia` no <html>: quem manda no
                 * desenho é o CSS, e o estado só escreve a marca.
                 */
                alternarFiltros(abrir = null) {
                    this.filtrosAbertos = abrir ?? ! this.filtrosAbertos;

                    document.querySelector('[data-barra-de-filtros]')
                        ?.toggleAttribute('data-aberta', this.filtrosAbertos);
                },

                alternarColuna(chave) {
                    this.recolhidas = this.recolhidas.includes(chave)
                        ? this.recolhidas.filter((x) => x !== chave)
                        : [...this.recolhidas, chave];

                    try {
                        localStorage.setItem('alfamatriz:colunas-recolhidas', JSON.stringify(this.recolhidas));
                    } catch (erro) {
                        // Navegação anônima ou cota cheia: a escolha vale para
                        // esta sessão e não sobrevive — o que não justifica
                        // quebrar o quadro.
                    }
                },

                // Para onde o card que está na mão pode ir. Sai do mesmo lugar
                // que alimenta o menu "Mover ▾" (`FluxoTarefaService`), e é o
                // que permite ao quadro MOSTRAR a regra em vez de aplicá-la por
                // cima de quem já soltou: sem isso, arrastar uma tarefa
                // operacional até Em testes era um caminho aberto que só
                // respondia "transição inválida" depois do fato.
                destinos: [],

                // Tipo do card na mão: é ele que decide se concluir pede
                // relatório de teste, e o painel é do quadro, não do card.
                tipoArrastado: null,

                // A etapa em que o card estava quando foi pego: viaja no envio
                // para o servidor recusar movimento sobre movimento alheio.
                statusArrastado: null,

                // Posicionar card na coluna é organizar trabalho alheio, e
                // segue a mesma capacidade de priorizar e direcionar.
                podeTriar: {{ auth()->user()?->podeTriarTarefas() ? 'true' : 'false' }},

                // A etapa que o celular está mostrando. No quadro largo ela não
                // faz nada: quem esconde as outras é o CSS, por media query.
                etapaMobile: @json($etapas[0]['chave'] ?? 'aberta'),

                // A ordem das etapas, para as setas saberem o que é "a próxima".
                etapasEmOrdem: @json(array_column($etapas, 'chave')),

                // Etapas que só se alcança com texto: o teclado abre o painel
                // nelas, como o arraste faz.
                {{-- Por variável, e não literal: o `@json` do Blade separa os
                     argumentos com `explode(',')`, então um array escrito à mão
                     com quatro elementos perde o último e sai com o colchete
                     aberto — erro de sintaxe na view compilada, não no Blade. --}}
                etapasComTexto: @json($etapasComTexto),

                // Os portões do ciclo: é de onde a tarefa VEM que decide se
                // voltar para a bancada é reprovação ou só começar a trabalhar.
                portoes: @json(\App\Models\Tarefa::PORTOES),

                selecionado: null,
                atalhosAbertos: false,

                // A legenda da esteira, atrás do ícone de dúvida do cabeçalho.
                // Vive aqui, e não na partial, pelo mesmo motivo dos atalhos:
                // o quadro é redesenhado a cada ação parcial, e o estado do
                // componente é o que sobrevive à troca.
                fluxoAberto: false,

                // O que o painel de motivo está perguntando agora.
                pendente: null,
                textoPendente: '',
                enviandoPendente: false,

                // As imagens escolhidas para a devolução, antes do envio: o
                // File para reescrever a carga do input e o `blob:` para a
                // prévia. O input do clone é guardado porque o `$refs` do
                // quadro não o alcança — o clone é iniciado com raiz própria
                // (ver `sincronizarPainelDoMotivo`).
                imagensPendentes: [],
                avisoDeImagens: '',
                entradaDeImagens: null,

                // Modelo de rota com marcador: o id só é conhecido no solto.
                rotaMover: @json(route('tarefas.mover', ['tarefa' => '__ID__'])),
                rotaBloquear: @json(route('tarefas.bloquear', ['tarefa' => '__ID__'])),

                // Rótulos das etapas para o select do menu "Mover ▾": montados
                // no cliente (x-text), não em Blade — se saíssem como
                // <option> literal, o rótulo de uma etapa mais à frente no
                // quadro (ex.: "Cancelada") apareceria no HTML antes do
                // cabeçalho da própria coluna, quebrando a ordem das colunas.
                rotulosStatus: @json(\App\Models\Tarefa::STATUS),

                temMaisEsquerda: false,
                temMaisDireita: false,

                medirBordas() {
                    const q = this.$refs.quadro;
                    if (! q) return;
                    this.temMaisEsquerda = q.scrollLeft > 1;
                    this.temMaisDireita = q.scrollLeft + q.clientWidth < q.scrollWidth - 1;
                },

                /**
                 * A rolagem com o card na mão.
                 *
                 * Durante um arrasto o navegador engole os eventos de ponteiro:
                 * a barra de rolagem não se pega, a roda do mouse não anda e o
                 * teclado não chega. Numa coluna com mais cards do que cabem na
                 * tela, isso reduzia a reordenação ao trecho visível — para
                 * levar um card ao pé da fila era preciso soltar, rolar e pegar
                 * de novo, e a cada volta o vão aberto se desfazia. Vale igual
                 * para a etapa que está fora da vista, à direita.
                 *
                 * `dragover` é o único sinal que continua chegando, e ele traz a
                 * posição do ponteiro. O resto é conta: perto de uma borda, o
                 * contêiner sob o ponteiro anda sozinho.
                 */
                ponteiroDoArrasto: null,
                rolagemDoArrasto: null,

                /**
                 * O ouvinte é do DOCUMENTO, e na CAPTURA.
                 *
                 * Na captura porque `permitirSobreCard` corta a propagação do
                 * `dragover` justamente quando o ponteiro está sobre um card —
                 * ou seja, na maior parte de uma coluna cheia, que é a que
                 * precisa rolar. Quem espera o evento subir nunca o veria ali.
                 *
                 * No documento porque a coluna que RECUSA o card não é alvo de
                 * solto, mas continua sendo lugar de onde se rola para procurar
                 * onde soltar — e porque o vão entre colunas, o cabeçalho e o pé
                 * também contam.
                 */
                acompanharPonteiro(evento) {
                    if (this.arrastando === null) {
                        return;
                    }

                    this.ponteiroDoArrasto = { x: evento.clientX, y: evento.clientY };

                    if (this.rolagemDoArrasto === null) {
                        this.rolagemDoArrasto = requestAnimationFrame(() => this.rolarNoArrasto());
                    }
                },

                /**
                 * Um quadro de rolagem, e o próximo enquanto o gesto durar.
                 *
                 * O laço se mantém sozinho em vez de andar a cada `dragover`:
                 * parado na borda a rolagem SEGUE, que é como se desce uma
                 * coluna longa sem sacudir o mouse. E ele morre no primeiro
                 * quadro sem card na mão, sem depender de o `dragend` lembrar
                 * de desligá-lo — arrasto cancelado com Esc não passa por lá.
                 */
                rolarNoArrasto() {
                    this.rolagemDoArrasto = null;

                    if (this.arrastando === null || ! this.ponteiroDoArrasto) {
                        this.ponteiroDoArrasto = null;

                        return;
                    }

                    const { x, y } = this.ponteiroDoArrasto;

                    if (this.rolarPertoDaBorda(x, y)) {
                        this.remirarVao(x, y);
                    }

                    this.rolagemDoArrasto = requestAnimationFrame(() => this.rolarNoArrasto());
                },

                /**
                 * Refaz a mira do vão depois que a vista andou.
                 *
                 * Com a mão parada o navegador NÃO repete o `dragover`: os
                 * cards deslizam por baixo do cursor e a prévia fica congelada
                 * no vizinho onde a mão parou. Soltar ali não reordena nada —
                 * `confirmarVao` compara com a vaga original, vê que ninguém
                 * mudou de lugar e não manda envio nenhum. Sem esta linha a
                 * rolagem anda mas não serve para aquilo que ela existe.
                 */
                remirarVao(x, y) {
                    if (! this.podeTriar || this.arrastando === null) {
                        return;
                    }

                    // O ITEM da lista, não o <article> de dentro: os dois
                    // carregam `data-tarefa`, e o de dentro não é irmão do card
                    // na mão — a mira cairia fora na primeira conferência.
                    const vizinho = document.elementFromPoint(x, y)?.closest('[data-cards] > [data-tarefa]');

                    if (vizinho && Number(vizinho.dataset.tarefa) !== this.arrastando) {
                        this.mirarVao(vizinho, y);
                    }
                },

                /**
                 * Rola o contêiner sob o ponteiro, do mais interno ao mais
                 * externo, e diz se ALGUMA coisa andou.
                 *
                 * Empurrar e conferir, em vez de perguntar ao CSS quem rola:
                 * quem chegou ao fim do próprio curso não se mexe, e o
                 * `scrollTop` que não mudou é o sinal de que a vez é do pai. É
                 * assim que, na beirada direita, a coluna — que não rola na
                 * horizontal — entrega o eixo ao quadro, sem uma lista de quem
                 * rola que ficaria desatualizada na primeira caixa nova.
                 *
                 * Cada caixa é medida pela PRÓPRIA borda. Em raias, onde a
                 * célula acaba no meio da tela, chegar ao pé dela não arrasta o
                 * quadro junto: para descer as faixas o ponteiro vai ao pé DO
                 * QUADRO, que é onde a rolagem daquela caixa mora.
                 */
                rolarPertoDaBorda(x, y) {
                    // A faixa onde a rolagem começa e o passo por quadro
                    // encostado na borda. A faixa é grossa o bastante para ser
                    // acertada sem pontaria e fina o bastante para sobrar
                    // miolo: a menor caixa do quadro é a célula de raia, de
                    // 180px de altura, onde duas faixas de 56px ainda deixam
                    // 68px de repouso no meio. O passo é proporcional à
                    // distância — encostado anda os 18px, na entrada da faixa
                    // quase não anda — porque velocidade única tem só dois
                    // estados, parada e disparada.
                    const FAIXA = 56;
                    const PASSO = 18;

                    const passoNaBorda = (distancia) => Math.ceil(PASSO * (1 - distancia / FAIXA));

                    // A borda MAIS PERTO decide o sentido: numa caixa mais
                    // baixa que duas faixas as duas valem ao mesmo tempo, e sem
                    // isto a de cima ganharia sempre, rolando para trás quem
                    // está descendo.
                    const quantoAndar = (antes, depois) => {
                        if (Math.min(antes, depois) >= FAIXA) {
                            return 0;
                        }

                        return antes < depois ? -passoNaBorda(antes) : passoNaBorda(depois);
                    };

                    // Empurra e devolve se ANDOU: é o que passa a vez ao pai.
                    //
                    // Mas só em quem rola de VERDADE. `overflow: hidden` também
                    // anda quando empurrado por script, e a primeira vítima
                    // disso foi o `truncate` do título do card: o eixo
                    // horizontal morria dentro de um texto cortado — que saía
                    // do lugar, ainda por cima — e o quadro não andava. A raiz
                    // da página entra à parte: ela rola sem dizer nada no
                    // `overflow`.
                    const rolaMesmo = (el, eixo) => {
                        if (el === document.scrollingElement) {
                            return true;
                        }

                        const como = getComputedStyle(el)[eixo === 'scrollTop' ? 'overflowY' : 'overflowX'];

                        return como === 'auto' || como === 'scroll' || como === 'overlay';
                    };

                    const empurrar = (el, eixo, quanto) => {
                        if (! rolaMesmo(el, eixo)) {
                            return false;
                        }

                        const antes = el[eixo];
                        el[eixo] += quanto;

                        return el[eixo] !== antes;
                    };

                    let alvo = document.elementFromPoint(x, y);
                    let rolouY = false;
                    let rolouX = false;

                    while (alvo && ! (rolouY && rolouX)) {
                        const r = alvo.getBoundingClientRect();

                        // Só quem tem o ponteiro DENTRO da própria caixa: um
                        // ancestral que não corta o conteúdo pode estar longe
                        // do ponto, e ali "perto da borda" não quer dizer nada.
                        const dentro = x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;

                        if (dentro && ! rolouY) {
                            const quanto = quantoAndar(y - r.top, r.bottom - y);

                            rolouY = quanto !== 0 && empurrar(alvo, 'scrollTop', quanto);
                        }

                        if (dentro && ! rolouX) {
                            const quanto = quantoAndar(x - r.left, r.right - x);

                            rolouX = quanto !== 0 && empurrar(alvo, 'scrollLeft', quanto);
                        }

                        alvo = alvo.parentElement;
                    }

                    return rolouY || rolouX;
                },

                /**
                 * Esta lista de cards pode receber o vão do card que está na mão?
                 *
                 * A ordem automática responde "o que é mais grave", que não é a
                 * mesma pergunta que "qual eu pego primeiro" — entre duas altas,
                 * quem conhece o trabalho sabe que uma destrava a outra.
                 *
                 * Posicionar é organizar trabalho alheio, então segue a mesma
                 * capacidade de priorizar: sem ela, o solto sobre card não faz
                 * nada e o evento sobe para a coluna, que decide se é movimento
                 * de etapa.
                 *
                 * A de OUTRA coluna também recebe, desde que a etapa seja
                 * destino do card: escolher a etapa e escolher o lugar na fila
                 * eram dois gestos, e arrastar do Backlog para o terceiro lugar
                 * de Em andamento largava o card onde a régua automática
                 * mandasse (relato do dono, 17/08/2026). A de outra FAIXA não:
                 * soltar na faixa de outra pessoa não torna a tarefa dela.
                 */
                listaAceita(lista) {
                    const chave = lista?.dataset?.cards;

                    if (! this.podeTriar || this.arrastando === null || ! chave) {
                        return false;
                    }

                    const [faixa, status] = this.enderecoDaLista(lista);

                    return this.naFaixaDoArrasto(faixa) && this.aceita(status);
                },

                /**
                 * Este ponto do quadro está na MESMA faixa de onde o card saiu?
                 *
                 * Sem raias há uma faixa só e a conta é sempre verdadeira. Com
                 * raias, é a recusa que faltava: a célula da outra pessoa
                 * aceitava o solto e nada acontecia — gesto que morre em
                 * silêncio se lê como sistema quebrado. Agora ela apaga junto
                 * com as colunas fora do fluxo, e a recusa chega ANTES do gesto.
                 */
                naFaixaDoArrasto(faixa) {
                    return this.arrastando === null
                        || this.faixaArrastada === null
                        || faixa === null
                        || faixa === this.faixaArrastada;
                },

                permitirSobreCard(evento, alvo, id) {
                    if (! this.listaAceita(alvo.parentElement)) {
                        return;
                    }

                    evento.preventDefault();
                    evento.stopPropagation();

                    // O realce da coluna sai daqui porque a propagação parou:
                    // sobre um card — onde o ponteiro passa a maior parte do
                    // tempo numa coluna cheia — o `dragover` da coluna não
                    // chega, e a de destino ficaria sem anel nenhum.
                    const [faixa, status] = this.enderecoDaLista(alvo.parentElement);

                    this.faixaSobOPonteiro = faixa;
                    this.permitir(status);

                    // Sobre o próprio card na mão não há o que abrir: o vão é
                    // ele mesmo, meio apagado, já ocupando o lugar do pouso.
                    if (id === this.arrastando) {
                        return;
                    }

                    this.mirarVao(alvo, evento.clientY);
                },

                /**
                 * O `faixa::status` de uma lista de cards, partido em dois. A
                 * etapa e a faixa saem do `data-cards` em vez de virem escritas
                 * em cada card: repetidas em 120 deles, custavam ~10 KB de HTML
                 * para dizer o que a lista já dizia.
                 */
                enderecoDaLista(lista) {
                    return (lista?.dataset?.cards ?? '').split('::');
                },

                /**
                 * Onde o vão abre, dado o vizinho sob o ponteiro.
                 *
                 * Separado do `dragover` porque a rolagem automática precisa da
                 * mesma mira sem ter evento nenhum na mão (ver `remirarVao`).
                 */
                mirarVao(alvo, ondeY) {
                    // A lista é a do card SOB o ponteiro, e quem decide se ela
                    // serve é o `listaAceita`: a mesma etapa aparece uma vez por
                    // raia, e a coluna de outra etapa só abre o vão quando é
                    // destino possível do card na mão.
                    const arrastado = this.cardEmMovimento;
                    const lista = alvo.parentElement;

                    if (! arrastado || ! this.listaAceita(lista)) {
                        return;
                    }

                    // A metade do vizinho decide o lado, como no protótipo:
                    // metade de cima, o card cai antes dele; de baixo, depois.
                    const r = alvo.getBoundingClientRect();
                    const referencia = ondeY < r.top + r.height / 2 ? alvo : alvo.nextElementSibling;

                    // Já está no vão: mover de novo só faria a coluna tremer. Só
                    // dentro da MESMA lista — vindo de outra, dois `null` (card
                    // no fim de uma, alvo no fim da outra) se igualariam por
                    // acaso e cancelariam o pouso no pé da coluna de destino.
                    if (arrastado.parentElement === lista
                        && (referencia === arrastado || referencia === arrastado.nextElementSibling)) {
                        return;
                    }

                    this.abrirVao(lista, arrastado, referencia);
                },

                /**
                 * Move o card na lista com os vizinhos deslizando (FLIP): cada
                 * um é fotografado, movido no DOM, devolvido por transform à
                 * posição antiga e solto para a transição levá-lo à nova. Sem
                 * isso o vão abre num salto — e o salto lê como falha, não
                 * como prévia.
                 */
                abrirVao(lista, arrastado, referencia) {
                    // As DUAS listas entram na medição: mudando de coluna, os
                    // vizinhos que o card deixa também mudam de lugar, e medir
                    // só o destino os faria fechar o buraco num salto — o
                    // mesmo salto que a prévia existe para não dar.
                    const vizinhanca = [...new Set([lista, arrastado.parentElement].filter(Boolean))];
                    const antes = new Map();

                    vizinhanca.forEach((caixa) => [...caixa.children]
                        .forEach((el) => antes.set(el, el.getBoundingClientRect().top)));

                    lista.insertBefore(arrastado, referencia);

                    [...antes.keys()].forEach((el) => {
                        const salto = (antes.get(el) ?? el.getBoundingClientRect().top) - el.getBoundingClientRect().top;

                        if (! salto) {
                            return;
                        }

                        el.style.transition = 'none';
                        el.style.transform = `translateY(${salto}px)`;

                        requestAnimationFrame(() => {
                            el.style.transition = 'transform 150ms ease';
                            el.style.transform = '';
                        });
                    });
                },

                soltarSobreCard(evento, alvo) {
                    if (! this.listaAceita(alvo.parentElement)) {
                        return;
                    }

                    evento.preventDefault();
                    evento.stopPropagation();

                    // O mesmo caminho do solto na coluna, e não um atalho para o
                    // `confirmarVao`: sobre um card de OUTRA etapa o gesto é
                    // movimento, não reordenação, e é o `soltar` que sabe
                    // separar os dois. A propagação parou aqui, então ele é
                    // chamado uma vez só — pelo card, não pela coluna.
                    const [, status] = this.enderecoDaLista(alvo.parentElement);

                    this.soltar(status, this.pedeTexto(status));
                },

                /**
                 * Soltar confirma o que a tela já mostra: o vão aberto durante
                 * o arrasto É a prévia, e a coluna inteira vai no envio, lida
                 * do DOM — mandar só "X foi para N" faria o servidor recalcular
                 * o que o navegador já sabe, e divergir no segundo arrasto.
                 * Nada mudou de lugar? Nada a enviar.
                 */
                confirmarVao() {
                    const arrastado = this.cardEmMovimento;
                    const lista = arrastado?.closest('[data-cards]');
                    const status = this.statusArrastado;

                    // O vão pode estar em OUTRA coluna: mirou lá e o solto veio
                    // na coluna de origem. Ali não há reordenação a confirmar —
                    // a prévia perdeu a validade, e o `largar` devolve o card à
                    // vaga. Sem esta guarda, a ordem da coluna de destino seria
                    // gravada com o nome da coluna de origem.
                    const ficouOndeEstava = ! arrastado || ! lista
                        || lista !== this.vagaOriginal?.lista
                        || arrastado.nextElementSibling === this.vagaOriginal?.proximo;

                    if (ficouOndeEstava) {
                        this.largar();

                        return;
                    }

                    this.$refs.statusPosicionar.value = status;
                    this.escreverOrdem(this.$refs.formPosicionar, this.ordemDaLista(lista));

                    this.$refs.formPosicionar.requestSubmit();
                    this.ordemEnviada = true;
                    this.largar();
                },

                /** Os ids de uma lista de cards, na ordem em que a tela mostra. */
                ordemDaLista(lista) {
                    return [...lista.children]
                        .map((linha) => linha.dataset.tarefa)
                        .filter(Boolean);
                },

                /**
                 * A coluna de DESTINO inteira, quando o vão foi aberto fora da
                 * lista de origem — o endereço exato onde o card vai pousar.
                 *
                 * Vazio quando o card não saiu da própria coluna: ali quem
                 * confirma é o `confirmarVao`, pela rota de posicionar.
                 */
                ordemDoVao() {
                    const arrastado = this.cardEmMovimento;
                    const lista = arrastado?.closest('[data-cards]');

                    return ! lista || lista === this.vagaOriginal?.lista
                        ? []
                        : this.ordemDaLista(lista);
                },

                /**
                 * Escreve a ordem de uma coluna num formulário. Um lugar só
                 * porque três envios a mandam, e `data-ordem` é o que permite
                 * limpar a do gesto anterior sem levar junto o `status` e o
                 * CSRF, que moram no mesmo formulário.
                 */
                escreverOrdem(form, ordem) {
                    form.querySelectorAll('input[data-ordem]').forEach((campo) => campo.remove());

                    ordem.forEach((id) => {
                        const campo = document.createElement('input');
                        campo.type = 'hidden';
                        campo.name = 'ordem[]';
                        campo.dataset.ordem = '1';
                        campo.value = id;
                        form.appendChild(campo);
                    });
                },

                /**
                 * O teclado do quadro.
                 *
                 * Quem passa o dia aqui move dezenas de cards, e cada um custa
                 * pegar o mouse, mirar e arrastar. As setas fazem o mesmo
                 * percurso sem tirar a mão de onde ela já está.
                 *
                 * Nada dispara enquanto se digita: sem esta guarda, escrever
                 * "backlog" na busca moveria cards pelo caminho — o `b` bloqueia
                 * e o `c` abre criação rápida. É a primeira coisa a conferir,
                 * porque o estrago é silencioso.
                 */
                aoTeclar(evento) {
                    const digitando = evento.target.closest('input, textarea, select, [contenteditable]');
                    const tecla = evento.key;

                    if (tecla === 'Escape') {
                        /**
                         * Esc fecha o que estiver POR CIMA, de dentro para fora.
                         *
                         * A tela cheia é a última camada a ceder: sair dela no
                         * meio de um pedido de motivo levaria junto o texto já
                         * digitado, e quem apertou Esc queria fechar o painel.
                         *
                         * O modal entra na conta pelo DOM, e não por uma
                         * propriedade daqui, porque ele não é nosso: o
                         * `x-modal` guarda o próprio `show` e fecha sozinho no
                         * Esc, pela janela, sem avisar ninguém. Sem esta linha,
                         * fechar uma tarefa aberta derrubava a tela cheia junto
                         * — um Esc, duas coisas, e a de fora era a que ninguém
                         * pediu. `[data-modal]` é atributo que o componente já
                         * põe no DOM, e `x-show` escreve o display inline.
                         */
                        const modalAberto = [...document.querySelectorAll('[data-modal]')]
                            .some((el) => el.style.display !== 'none');

                        const algoAberto = modalAberto || this.pendente || this.atalhosAbertos
                            || this.fluxoAberto || this.filtrosAbertos;

                        this.fecharPendente();
                        this.atalhosAbertos = false;
                        this.fluxoAberto = false;
                        this.alternarFiltros(false);

                        if (! algoAberto && this.telaCheia) {
                            this.alternarTelaCheia();
                        }

                        return;
                    }

                    if (digitando || evento.metaKey || evento.ctrlKey || evento.altKey) {
                        return;
                    }

                    const acoes = {
                        // `document` e não `$refs`: a busca vive na barra de
                        // filtros, que é irmã do quadro e não filha dele — e a
                        // criação rápida se repete por coluna, então um `ref`
                        // ali seria um nome disputado por vários elementos.
                        //
                        // Em tela cheia a barra está escondida embaixo do
                        // quadro: focá-la punha o cursor num campo invisível.
                        // O `/` a traz junto, que é o que ele sempre prometeu.
                        '/': () => {
                            if (this.telaCheia) {
                                this.alternarFiltros(true);
                            }

                            document.querySelector('input[name="busca"]')?.focus();
                        },
                        '?': () => (this.atalhosAbertos = ! this.atalhosAbertos),
                        n: () => this.$dispatch('open-modal', 'nova-tarefa'),
                        c: () => document.querySelector('[data-criacao-rapida]')?.focus(),
                        ArrowUp: () => this.andarNaColuna(-1),
                        ArrowDown: () => this.andarNaColuna(1),
                        ArrowLeft: () => (evento.shiftKey ? this.moverUmaEtapa(-1) : this.andarEntreColunas(-1)),
                        ArrowRight: () => (evento.shiftKey ? this.moverUmaEtapa(1) : this.andarEntreColunas(1)),
                        Enter: () => this.abrirSelecionado(),
                        m: () => this.abrirMenuDoSelecionado(),
                        b: () => this.travarSelecionado(),
                    };

                    const acao = acoes[tecla] ?? acoes[tecla.toLowerCase?.()];

                    if (acao) {
                        evento.preventDefault();
                        acao();
                    }
                },

                /** Os cards visíveis de uma etapa, na ordem em que estão na tela. */
                cardsDaEtapa(status) {
                    return [...document.querySelectorAll(`section[data-status="${status}"] [data-tarefa]`)];
                },

                elementoSelecionado() {
                    return this.selecionado === null
                        ? null
                        : document.querySelector(`[data-tarefa="${this.selecionado}"]`);
                },

                etapaDoSelecionado() {
                    return this.elementoSelecionado()?.closest('section[data-status]')?.dataset.status ?? null;
                },

                selecionar(elemento) {
                    if (! elemento) {
                        return;
                    }

                    this.selecionado = Number(elemento.dataset.tarefa);
                    elemento.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                },

                andarNaColuna(passo) {
                    const status = this.etapaDoSelecionado() ?? this.etapasEmOrdem[0];
                    const cards = this.cardsDaEtapa(status);
                    const atual = cards.findIndex((c) => Number(c.dataset.tarefa) === this.selecionado);

                    this.selecionar(cards[Math.max(0, Math.min(cards.length - 1, atual + passo))] ?? cards[0]);
                },

                andarEntreColunas(passo) {
                    const status = this.etapaDoSelecionado() ?? this.etapasEmOrdem[0];
                    let indice = this.etapasEmOrdem.indexOf(status);

                    // Pula as etapas vazias: parar numa coluna sem card seria
                    // perder a seleção e obrigar a voltar.
                    for (let i = 0; i < this.etapasEmOrdem.length; i++) {
                        indice += passo;

                        if (indice < 0 || indice >= this.etapasEmOrdem.length) {
                            return;
                        }

                        const cards = this.cardsDaEtapa(this.etapasEmOrdem[indice]);

                        if (cards.length) {
                            this.etapaMobile = this.etapasEmOrdem[indice];
                            this.selecionar(cards[0]);

                            return;
                        }
                    }
                },

                abrirSelecionado() {
                    if (this.selecionado !== null) {
                        this.$dispatch('abrir-tarefa', this.selecionado);
                    }
                },

                abrirMenuDoSelecionado() {
                    const alvo = this.elementoSelecionado();

                    if (alvo) {
                        Alpine.$data(alvo).menuAberto = ! Alpine.$data(alvo).menuAberto;
                    }
                },

                travarSelecionado() {
                    const alvo = this.elementoSelecionado();

                    if (! alvo) {
                        return;
                    }

                    // Destravar é imediato — não há o que perguntar. Travar
                    // passa pelo painel, porque o motivo é obrigatório.
                    if (alvo.dataset.bloqueada) {
                        alvo.querySelector('form[action*="bloquear"]')?.requestSubmit();

                        return;
                    }

                    // `prepararTeclado` já põe o card no mesmo estado que um
                    // `dragstart` põe — etapa e tipo inclusive. Por isso o
                    // `abrirPendente` daqui não precisa dos dois argumentos:
                    // eles já valem quando ele é chamado.
                    this.prepararTeclado(alvo);
                    this.abrirPendente(this.selecionado, 'bloqueio');
                },

                /**
                 * Põe o card selecionado no mesmo estado que um `dragstart` põe.
                 *
                 * `segurar` e não `pegar`: o elemento vai junto — sem ele o
                 * teclado empurrava tudo uma casa para a esquerda e o quadro
                 * ficava com a LISTA de destinos no lugar do id, `destinos`
                 * virava a string do tipo e ⇧← ⇧→ não moviam nada, com um erro
                 * no console a cada tecla. E `pegar` esconderia o card,
                 * levando junto o painel de motivo que o B abre dentro dele.
                 */
                prepararTeclado(alvo) {
                    this.segurar(
                        alvo,
                        Number(alvo.dataset.tarefa),
                        JSON.parse(alvo.dataset.destinos || '[]'),
                        alvo.dataset.tipo,
                        !! alvo.dataset.bloqueada,
                        alvo.closest('section[data-status]')?.dataset.status,
                    );
                },

                moverUmaEtapa(passo) {
                    const alvo = this.elementoSelecionado();

                    if (! alvo) {
                        return;
                    }

                    const status = this.etapaDoSelecionado();
                    const destino = this.etapasEmOrdem[this.etapasEmOrdem.indexOf(status) + passo];

                    this.prepararTeclado(alvo);

                    // Fora do fluxo, nada acontece — e não é silêncio: a coluna
                    // vizinha já está apagada na tela desde que a seleção
                    // aconteceu, então a recusa foi anunciada antes do gesto.
                    if (! destino || ! this.aceita(destino)) {
                        this.largar();

                        return;
                    }

                    this.soltar(destino, this.pedeTexto(destino));
                },

                /**
                 * Este destino abre o painel de motivo?
                 *
                 * Em andamento é o caso que não se resolve só pelo destino:
                 * vindo de um portão ele é reprovação e cobra o texto; vindo do
                 * Backlog é só começar a trabalhar, e não há motivo a dar. Um
                 * painel ali pediria justificativa para pegar a própria tarefa.
                 */
                pedeTexto(destino) {
                    if (destino === 'em_desenvolvimento') {
                        return this.portoes.includes(this.statusArrastado);
                    }

                    return this.etapasComTexto.includes(destino);
                },

                // Onde o ponteiro desceu, e quando o último arrasto terminou.
                inicioDoClique: null,
                fimDoArrasto: 0,

                marcarInicioDoClique(evento) {
                    this.inicioDoClique = { x: evento.clientX, y: evento.clientY };
                },

                /**
                 * Foi clique, ou foi o fim de um arrasto?
                 *
                 * O card abre o detalhe no clique E arrasta, e sem separar os
                 * dois o gesto de arrastar terminava com o modal aberto por
                 * cima — inclusive quando o arrasto foi recusado, porque aí o
                 * `click` chega igual.
                 *
                 * Duas peneiras, porque uma só não pega os dois casos: 4px de
                 * folga para a mão que treme sobre um clique legítimo, e 300ms
                 * de carência depois de um arrasto, para o `click` que o
                 * navegador dispara ao soltar não passar por clique.
                 */
                foiClique(evento) {
                    if (Date.now() - this.fimDoArrasto < 300) {
                        return false;
                    }

                    if (! this.inicioDoClique) {
                        return true;
                    }

                    const andou = Math.hypot(
                        evento.clientX - this.inicioDoClique.x,
                        evento.clientY - this.inicioDoClique.y,
                    );

                    return andou < 4;
                },

                /**
                 * Põe o card na coluna de destino sem esperar o servidor.
                 *
                 * A faixa vem do próprio card: com raias ligadas, a mesma etapa
                 * aparece uma vez por raia, e mandar o card para a primeira
                 * delas o faria pular de pessoa — visivelmente errado até a
                 * resposta chegar e consertar.
                 */
                adiantarMovimento(tarefa, status) {
                    const card = document.querySelector(`[data-tarefa="${tarefa}"]`);
                    const listaAtual = card?.closest('[data-cards]');

                    if (! listaAtual) {
                        return;
                    }

                    const faixa = listaAtual.dataset.cards.split('::')[0];
                    const destino = document.querySelector(`[data-cards="${faixa}::${status}"]`);

                    if (! destino) {
                        return;
                    }

                    // O vão aberto durante o arrasto já pôs o card no lugar
                    // mirado: mandá-lo ao topo aqui desfaria a mira no último
                    // instante do gesto — a prévia desmentida pelo próprio
                    // solto que a confirmou.
                    if (listaAtual !== destino) {
                        destino.prepend(card);
                    }

                    // A vista acompanha o card: a célula de destino pode
                    // estar noutro ponto da rolagem, e sem isto o solto
                    // fazia o card sumir — movimento certo lendo como
                    // card perdido.
                    card.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
                },

                /**
                 * Esta etapa é destino possível para o card que está na mão?
                 *
                 * A PRÓPRIA coluna entra na conta: soltar nela é reordenar, e o
                 * mapa do fluxo não lista a etapa onde o card já está — sem
                 * isso ela apagava a 25% ao se pegar um card dela, anunciando
                 * recusa justamente onde o gesto mais acontece.
                 */
                aceita(status) {
                    return this.arrastando === null
                        || status === this.statusArrastado
                        || this.destinos.includes(status);
                },

                /**
                 * O card na mão, do jeito que o quadro inteiro lê — o mesmo
                 * estado para o arrasto e para o teclado.
                 *
                 * O ELEMENTO entra junto do id: é dele que saem a vaga de
                 * origem, para o gesto abandonado ter caminho de volta, e a
                 * lista onde o vão abre.
                 */
                segurar(el, tarefa, destinos, tipo, bloqueada, status) {
                    this.arrastando = tarefa;
                    // Bloquear é destino de quem ainda não está travado; para
                    // quem já está, a saída é o botão da própria tarja.
                    this.destinos = bloqueada ? destinos : [...destinos, 'bloqueio'];
                    this.tipoArrastado = tipo;
                    this.statusArrastado = status;
                    this.cardEmMovimento = el;
                    this.vagaOriginal = { lista: el.parentElement, proximo: el.nextElementSibling };
                    // A faixa de onde o card saiu, lida do endereço da lista
                    // (`faixa::status`): com raias ligadas ela é o responsável
                    // — ou o sistema — da tarefa, e é o que decide se a célula
                    // sob o ponteiro pode receber o solto.
                    this.faixaArrastada = el.parentElement?.dataset.cards?.split('::')[0] ?? null;
                    this.ordemEnviada = false;
                },

                /**
                 * `dragstart`: segura o card e o SOME da lista.
                 *
                 * Sumir é do arrasto, não do estado — por isso mora aqui e não
                 * no `segurar`. Pelo teclado o card continua na tela: o B abre o
                 * painel de motivo DENTRO dele, e um card invisível levaria o
                 * painel junto.
                 */
                pegar(el, tarefa, destinos, tipo, bloqueada, status) {
                    this.segurar(el, tarefa, destinos, tipo, bloqueada, status);

                    // Um quadro DEPOIS do dragstart: esconder no próprio evento
                    // faria o navegador fotografar um card invisível, e o
                    // fantasma sob o cursor nasceria em branco. Escondido, o
                    // vão do pouso fica vazio de verdade — o card vive só no
                    // fantasma até o gesto terminar.
                    requestAnimationFrame(() => {
                        if (this.arrastando === tarefa) {
                            el.classList.add('invisible');
                        }
                    });
                },

                largar() {
                    if (this.arrastando !== null) {
                        this.fimDoArrasto = Date.now();
                    }

                    // Gesto que morreu no meio desfaz o vão: o card volta
                    // deslizando para a vaga de onde saiu. `ordemEnviada`
                    // distingue o gesto confirmado do abandonado — no
                    // confirmado o que está na tela É o que foi enviado, e
                    // devolvê-lo seria desmentir o próprio envio.
                    const arrastado = this.cardEmMovimento;

                    if (! this.ordemEnviada) {
                        this.desfazerVao();
                    }

                    // O fim do gesto devolve o card à tela, onde quer que ele
                    // tenha parado — confirmado, desfeito ou levado de etapa.
                    arrastado?.classList.remove('invisible');

                    this.cardEmMovimento = null;
                    this.vagaOriginal = null;
                    this.faixaArrastada = null;
                    this.faixaSobOPonteiro = null;
                    this.ordemEnviada = false;
                    this.arrastando = null;
                    this.destinos = [];
                    this.tipoArrastado = null;
                    this.statusArrastado = null;
                    this.sobre = null;
                },

                /**
                 * Devolve o card à vaga de onde saiu, deslizando.
                 *
                 * Vale para o gesto abandonado E para o que mirou noutra coluna
                 * sem confirmar: nos dois, o que está na tela deixou de valer.
                 * Vindo de outra lista é o caso que o `largar` não cobria — ele
                 * só devolvia dentro da origem, porque o vão nunca saía dela.
                 */
                desfazerVao() {
                    const arrastado = this.cardEmMovimento;
                    const vaga = this.vagaOriginal;

                    if (! arrastado || ! vaga) {
                        return;
                    }

                    if (arrastado.parentElement !== vaga.lista
                        || arrastado.nextElementSibling !== vaga.proximo) {
                        this.abrirVao(vaga.lista, arrastado, vaga.proximo);
                    }
                },

                /**
                 * O que o painel pergunta, por destino.
                 *
                 * O texto do "porquê" não é enfeite: é ele que transforma uma
                 * exigência em pedido. Sem a linha, o painel só informa que
                 * falta preencher um campo — que é a mesma notícia que o
                 * `required` do navegador já dava, e a razão de o gesto parecer
                 * castigo em vez de conversa.
                 */
                receita(destino) {
                    const ehDev = this.tipoArrastado === 'desenvolvimento';

                    const receitas = {
                        bloqueio: {
                            verbo: 'Marcando como', label: 'Bloqueada',
                            porque: 'A tarefa fica onde está — só passa a contar como travada. Diga o que está esperando: é esse texto que permite outra pessoa destravar depois.',
                            placeholder: 'Esperando quem, o quê…',
                            acaoRotulo: 'Bloquear tarefa',
                            cor: 'warn', campo: 'motivo', obrigatorio: true, pedeAprovacao: false,
                        },
                        // A devolução muda de sentido conforme o portão que
                        // reprovou — é isso que a coluna única de Ajustes
                        // achatava. Vindo do staging o código JÁ está na main,
                        // e a recuperação é materialmente outra.
                        em_desenvolvimento: {
                            verbo: 'Devolvendo para', label: 'Em andamento',
                            porque: {
                                em_revisao: 'Diga o que precisa ser corrigido no PR. Sem isso, quem recebe abre o card sem saber o que reprovou.',
                                em_staging: 'Falhou em staging — o código JÁ está na main. Diga o que quebrou e se precisa voltar a versão (deploy/voltar.sh) ou dá para corrigir seguindo em frente.',
                                pronta_producao: 'Reprovada antes de a tag subir. Diga o que apareceu.',
                            }[this.statusArrastado] ?? 'Diga o que precisa ser corrigido.',
                            placeholder: {
                                em_revisao: 'O que precisa ser corrigido no PR…',
                                em_staging: 'O que quebrou no staging · voltar ou corrigir em frente…',
                                pronta_producao: 'O que apareceu antes de subir…',
                            }[this.statusArrastado] ?? 'O que precisa ser corrigido…',
                            acaoRotulo: 'Devolver para correção',
                            cor: 'warn', campo: 'motivo', obrigatorio: true, pedeAprovacao: false,
                            // Só a devolução para correção oferece imagem: é o
                            // painel em que "o botão saiu do lugar" mais
                            // precisa do print que a frase não carrega. As
                            // imagens viajam no MESMO envio que move o card.
                            imagens: true,
                        },
                        // O carimbo do staging: é aqui que o dev afirma ter
                        // validado, e é essa nota que o admin lê antes de
                        // taggear. Texto opcional; o que importa é o carimbo.
                        pronta_producao: {
                            verbo: 'Liberando para', label: 'Pronta p/ produção',
                            porque: 'Vai para a fila do admin, que sobe a tag. Diga o que você conferiu no staging — é o que ele lê antes de subir.',
                            placeholder: 'O que foi conferido no staging…',
                            acaoRotulo: 'Liberar para o admin subir',
                            cor: 'good',
                            campo: ehDev ? 'relatorio_notas' : null,
                            obrigatorio: false,
                            pedeAprovacao: ehDev,
                        },
                        // Quem revisa / quem testa (US-087): o apontamento mora
                        // no gesto de mover — menu, arrasto e teclado abrem o
                        // mesmo painel (Q-038, revisada no primeiro dia de uso:
                        // o arrasto que passava reto se lia como feature que
                        // não existe). Opcional de propósito: sem escolha, a
                        // coluna segue como fila.
                        //
                        // A volta vinda de MAIS ADIANTE — só o movimento livre
                        // a oferece — é outra conversa: reprovação, como a
                        // devolução para a bancada, e o motor recusa sem o
                        // motivo. O apontamento continua junto, porque a
                        // tarefa está reentrando no exame.
                        em_revisao: ['em_staging', 'pronta_producao', 'concluida'].includes(this.statusArrastado) ? {
                            verbo: 'Devolvendo para', label: 'Em revisão',
                            porque: {
                                em_staging: 'Voltando do staging para o exame — o código JÁ está na main. Diga por que o PR precisa ser reexaminado.',
                                pronta_producao: 'Saindo da fila da produção de volta para o exame. Diga o que apareceu antes de subir.',
                                concluida: 'Reabrindo direto na revisão — a versão que subiu continua no ar. Diga o que precisa ser reexaminado.',
                            }[this.statusArrastado],
                            placeholder: 'Por que está voltando para a revisão…',
                            acaoRotulo: 'Devolver para revisão',
                            cor: 'warn', campo: 'motivo', obrigatorio: true, pedeAprovacao: false,
                            pessoa: 'Quem revisa?',
                        } : {
                            verbo: 'Enviando para', label: 'Em revisão',
                            porque: 'O PR vai para exame. Aponte quem revisa e o sino avisa a pessoa na hora — sem apontar, a coluna fica como fila.',
                            acaoRotulo: 'Enviar para revisão',
                            cor: 'brand', campo: null, obrigatorio: false, pedeAprovacao: false,
                            pessoa: 'Quem revisa?',
                        },
                        em_staging: {
                            verbo: 'Enviando para', label: 'Em staging',
                            porque: 'O código vai rodar no staging. Aponte quem testa e o sino avisa a pessoa na hora — sem apontar, a coluna fica como fila.',
                            acaoRotulo: 'Enviar para staging',
                            cor: 'brand', campo: null, obrigatorio: false, pedeAprovacao: false,
                            pessoa: 'Quem testa?',
                        },
                        cancelada: {
                            verbo: 'Encerrando como', label: 'Cancelada',
                            porque: 'Cancelar encerra a tarefa. O motivo fica no histórico, e é o que explica a decisão para quem a reabrir um dia.',
                            placeholder: 'Motivo do cancelamento…',
                            acaoRotulo: 'Cancelar tarefa',
                            cor: 'crit', campo: 'motivo', obrigatorio: true, pedeAprovacao: false,
                        },
                        concluida: {
                            verbo: 'Encerrando como', label: 'Concluída',
                            porque: ehDev
                                ? 'Concluída significa EM PRODUÇÃO: a tag subiu e o vigia aplicou. Registre a versão — é ela que responde "desde quando o cliente tem isso".'
                                : 'Tarefa operacional não passa por PR nem por tag. Registre o que foi feito — é o que sobra como prova depois.',
                            placeholder: ehDev ? 'v1.4.2' : 'O que foi feito…',
                            acaoRotulo: ehDev ? 'Subiu para produção' : 'Encerrar tarefa',
                            cor: 'good',
                            campo: ehDev ? 'versao_producao' : 'relatorio_notas',
                            obrigatorio: ehDev,
                            pedeAprovacao: false,
                        },
                    };

                    return receitas[destino] ?? null;
                },

                /**
                 * Abre o painel de motivo de um card.
                 *
                 * `de` e `tipo` chegam explícitos quando o painel é aberto pelo
                 * MENU ou pelos botões do rodapé — fora do arrasto, os campos do
                 * card na mão estão nulos, e sem eles o painel escolheria a cópia
                 * errada (a devolução não saberia de qual portão veio) e o envio
                 * iria sem `de_status`, desligando a guarda de concorrência.
                 * Arrastando, os dois já valem e os argumentos são dispensáveis.
                 */
                abrirPendente(tarefa, destino, de = null, tipo = null) {
                    if (de !== null) {
                        this.statusArrastado = de;
                    }

                    if (tipo !== null) {
                        this.tipoArrastado = tipo;
                    }

                    const receita = this.receita(destino);

                    if (! receita) {
                        return;
                    }

                    const ehBloqueio = destino === 'bloqueio';

                    this.textoPendente = '';
                    this.enviandoPendente = false;
                    this.limparImagens();
                    this.pendente = {
                        ...receita,
                        destino,
                        // O id vai junto porque o painel agora mora DENTRO do
                        // card: cada card pergunta se o pendente é dele, e sem
                        // isso todos abririam o painel ao mesmo tempo.
                        id: tarefa,
                        // Bloquear tem rota própria: travar não é mover.
                        status: ehBloqueio ? null : destino,
                        de: this.statusArrastado,
                        // A posição mirada no arrasto: o `soltar` a escreve logo
                        // depois desta chamada. Nasce vazia porque o painel
                        // também abre pelo menu, sem mira nenhuma — e uma ordem
                        // herdada reposicionaria a coluna sem ninguém pedir.
                        ordem: [],
                        acao: (ehBloqueio ? this.rotaBloquear : this.rotaMover).replace('__ID__', tarefa),
                    };

                },

                /**
                 * Fechar o painel encerra o GESTO, não só o painel.
                 *
                 * Pelo mouse o gesto já acabou quando o painel abre (`soltar`
                 * larga antes de perguntar). Pelo teclado não: o B segura o
                 * card para saber de onde ele vem, e sem largar aqui o quadro
                 * ficava com as colunas fora do fluxo apagadas depois de um Esc
                 * — a tela contando um arrasto que ninguém está fazendo.
                 */
                fecharPendente() {
                    this.pendente = null;
                    this.textoPendente = '';
                    this.limparImagens();
                    this.largar();
                },

                escolherImagens(evento) {
                    this.acrescentarImagens([...evento.target.files]);
                },

                /**
                 * Colar da área de transferência, com o painel aberto.
                 *
                 * O ouvinte é de `window` pelo mesmo motivo do modal: ninguém
                 * dá foco ao painel antes de colar. As guardas respondem, na
                 * ordem, "há painel de devolução aberto?" e "o Ctrl+V é mesmo
                 * dele?" — com um modal aberto POR CIMA, quem cola está
                 * colando no modal, e tratar aqui também poria a mesma imagem
                 * duas vezes: uma no acervo, outra na devolução. O modal
                 * fechado fica com `display:none` no próprio `[data-modal]`
                 * (`x-show`), e é isso que a segunda guarda lê — `offsetParent`
                 * não serve aqui porque o modal é `position:fixed`, que devolve
                 * null até visível.
                 */
                colarImagens(evento) {
                    if (! this.pendente || ! this.pendente.imagens || this.enviandoPendente) {
                        return;
                    }

                    if ([...document.querySelectorAll('[data-modal]')]
                        .some((modal) => modal.style.display !== 'none')) {
                        return;
                    }

                    const arquivos = [...(evento.clipboardData?.items ?? [])]
                        .filter((item) => item.kind === 'file')
                        .map((item) => item.getAsFile())
                        .filter(Boolean);

                    if (! arquivos.length) {
                        return;
                    }

                    evento.preventDefault();
                    this.acrescentarImagens(arquivos);
                },

                /**
                 * As imagens da devolução, das duas portas — seletor e Ctrl+V
                 * — para a carga do envio.
                 *
                 * O mesmo funil do `anexosDaTarefa`, na mesma ordem — figura,
                 * três por devolução, 12 MB cada, 15 MB a soma do lote — e
                 * pelos mesmos tetos: os do PHP de produção (ver o comentário
                 * dos tetos lá). O que não passa fica de fora COM a frase
                 * dizendo qual e por quê; o print grande não é encolhido aqui
                 * como o modal encolhe — a frase aponta a tarefa aberta, onde
                 * o redutor mora.
                 */
                acrescentarImagens(candidatos) {
                    this.avisoDeImagens = '';

                    let arquivos = candidatos
                        .filter((arquivo) => arquivo.type.startsWith('image/'));

                    if (arquivos.length < candidatos.length) {
                        this.avisoDeImagens = 'A devolução aceita só imagem — o resto entra pela tarefa aberta.';
                    }

                    const lugares = 3 - this.imagensPendentes.length;

                    if (arquivos.length > lugares) {
                        this.avisoDeImagens = 'Até três imagens por devolução — as demais ficaram de fora.';
                        arquivos = arquivos.slice(0, Math.max(lugares, 0));
                    }

                    for (const arquivo of arquivos) {
                        if (arquivo.size > 12 * 1024 * 1024) {
                            this.avisoDeImagens = 'Cada imagem precisa ter até 12 MB — "'
                                + arquivo.name + '" ficou de fora; pela tarefa aberta ela é reduzida e entra.';

                            continue;
                        }

                        const soma = this.imagensPendentes
                            .reduce((total, imagem) => total + imagem.arquivo.size, 0);

                        if (soma + arquivo.size > 15 * 1024 * 1024) {
                            this.avisoDeImagens = 'As imagens passam de 15 MB juntas — "'
                                + arquivo.name + '" ficou de fora; ela entra depois, pela tarefa aberta.';

                            continue;
                        }

                        // `blob:` do próprio navegador para a prévia, como na
                        // criação da tarefa: o servidor ainda não viu nada.
                        this.imagensPendentes.push({ arquivo, url: URL.createObjectURL(arquivo) });
                    }

                    this.sincronizarImagens();
                },

                removerImagem(indice) {
                    const [imagem] = this.imagensPendentes.splice(indice, 1);

                    if (imagem) {
                        URL.revokeObjectURL(imagem.url);
                    }

                    this.avisoDeImagens = '';
                    this.sincronizarImagens();
                },

                /**
                 * Reescreve a carga do input com o que a prévia mostra.
                 *
                 * A carga viaja NO input do clone, dentro do próprio envio do
                 * painel — e um input não sabe tirar um arquivo da própria
                 * lista. Sem a reescrita, remover uma prévia deixaria o
                 * arquivo removido viajando escondido no POST.
                 */
                sincronizarImagens() {
                    if (! this.entradaDeImagens) {
                        return;
                    }

                    const carga = new DataTransfer();
                    this.imagensPendentes.forEach((imagem) => carga.items.add(imagem.arquivo));
                    this.entradaDeImagens.files = carga.files;
                },

                // Os `blob:` são devolvidos ao navegador: o painel abre e
                // fecha dezenas de vezes por dia, e cada prévia esquecida é
                // memória que só se devolve fechando a aba.
                limparImagens() {
                    this.imagensPendentes.forEach((imagem) => URL.revokeObjectURL(imagem.url));
                    this.imagensPendentes = [];
                    this.avisoDeImagens = '';
                    this.entradaDeImagens = null;
                },

                /**
                 * Põe (ou tira) o pedido de motivo de dentro do card.
                 *
                 * O formulário existe uma vez só, como molde, e é clonado para
                 * `[data-motivo="{id}"]` — que mora dentro do `<article>` da
                 * tarefa. Continuar aparecendo DENTRO do card é requisito: ele
                 * já foi um bloco flutuante no rodapé do quadro e voltou para
                 * cá, porque o texto pedido longe do card perdia a ligação com
                 * o gesto que o pediu.
                 *
                 * `mutateDom` cala o observador do Alpine enquanto o DOM muda, e
                 * os dois passos de dentro fazem o que ele faria — é o mesmo
                 * cuidado do `trocar()` lá embaixo. Sem `destroyTree`, cada
                 * abertura deixa para trás os efeitos do painel anterior; sem
                 * `initTree`, o clone é HTML morto: o botão não envia e o campo
                 * não escreve em `textoPendente`.
                 */
                sincronizarPainelDoMotivo(pendente) {
                    this.$el.querySelectorAll('[data-motivo]').forEach((lugar) => {
                        if (! lugar.firstElementChild) {
                            return;
                        }

                        Alpine.mutateDom(() => {
                            [...lugar.children].forEach((filho) => Alpine.destroyTree(filho));
                            lugar.innerHTML = '';
                        });
                    });

                    if (! pendente) {
                        return;
                    }

                    const molde = this.$el.querySelector('[data-molde-do-motivo]');
                    const lugar = this.$el.querySelector(`[data-motivo="${pendente.id}"]`);

                    if (! molde || ! lugar) {
                        return;
                    }

                    Alpine.mutateDom(() => {
                        lugar.appendChild(molde.content.cloneNode(true));
                        Alpine.initTree(lugar);
                    });

                    // O campo é procurado no DOM, e NÃO por `$refs`.
                    //
                    // `initTree` no `lugar` faz dele a raiz da árvore que está
                    // sendo iniciada, e o `x-ref` de dentro passa a se registrar
                    // ali em vez de no quadro — `this.$refs.textoPendente` fica
                    // `undefined` e o `?.` engole a falha em silêncio: o painel
                    // abre certo e o cursor não vai para o campo. Enquanto o
                    // formulário morava direto no card não havia raiz no meio, e
                    // por isso o `$refs` funcionava.
                    this.$nextTick(() => {
                        // O gesto que pediu o painel pode ter terminado longe
                        // dele: o arrasto acaba na coluna de DESTINO, e o
                        // painel abre no card de ORIGEM — encoberto pela
                        // rolagem, ele lia como bug ("soltei e nada
                        // aconteceu"), com o formulário esperando fora da
                        // vista. `nearest` move o mínimo: quem já está vendo o
                        // card não é levado a passeio.
                        lugar.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });

                        // `textarea, select`, e não só `textarea`: os portões
                        // de exame perguntam QUEM, num select — sem o reserva,
                        // o cursor não ia a lugar nenhum. `preventScroll` para
                        // o foco não atropelar a rolagem suave logo acima.
                        lugar.querySelector('textarea, select')?.focus({ preventScroll: true });

                        // O input de imagens do clone, guardado AQUI e não no
                        // `escolher`: o Ctrl+V precisa dele antes de qualquer
                        // clique no seletor, e o `$refs` do quadro não alcança
                        // o clone (ver o comentário acima). Nulo quando a
                        // receita não oferece imagem — e aí o colar nem chega
                        // a procurá-lo, barrado em `pendente.imagens`.
                        this.entradaDeImagens = lugar.querySelector('input[type=file]');
                    });
                },

                permitir(status) {
                    if (this.arrastando !== null && this.aceita(status)
                        && this.naFaixaDoArrasto(this.faixaSobOPonteiro)) {
                        this.sobre = status;
                    }
                },

                soltar(status, exigeTexto) {
                    // Soltar na faixa de outra pessoa não muda o dono da tarefa:
                    // a raia agrupa a VISTA. A célula já está apagada desde o
                    // começo do arrasto — aqui a recusa se confirma sem envio.
                    if (! this.naFaixaDoArrasto(this.faixaSobOPonteiro)) {
                        this.largar();

                        return;
                    }

                    // Soltar na própria coluna — no vão entre cards, no
                    // cabeçalho, no pé — confirma a reordenação que a tela já
                    // mostra: exigir pontaria num card seria desfazer o gesto
                    // por dez pixels de margem. Sem a capacidade de triagem não
                    // há o que confirmar, e mover para a etapa em que o card já
                    // está não é movimento.
                    if (status === this.statusArrastado) {
                        if (this.podeTriar) {
                            this.confirmarVao();
                        } else {
                            this.largar();
                        }

                        return;
                    }

                    const tarefa = this.arrastando;
                    const permitido = this.aceita(status);
                    const tipo = this.tipoArrastado;
                    const de = this.statusArrastado;

                    // A posição mirada na coluna de DESTINO, lida do DOM com o
                    // vão ainda aberto. Viaja no MESMO envio que move o card:
                    // dois envios fariam o quadro assentar na ordem automática
                    // e só depois pular para a escolhida.
                    const ordem = permitido ? this.ordemDoVao() : [];

                    // Com o vão aberto no destino o card JÁ está onde vai ficar,
                    // e desfazê-lo para o `adiantarMovimento` recolocá-lo no
                    // topo custaria duas animações e a posição errada. Pedindo
                    // texto é o contrário: o card só anda quando o motivo chega.
                    if (ordem.length && ! exigeTexto) {
                        this.ordemEnviada = true;
                    }

                    this.largar();
                    this.tipoArrastado = tipo;
                    this.statusArrastado = de;

                    if (tarefa === null || ! permitido) {
                        this.tipoArrastado = null;
                        this.statusArrastado = null;

                        return;
                    }

                    // Bloqueio, ajustes, cancelamento e conclusão pedem um
                    // texto que o solto não tem como responder. Antes o arrasto
                    // simplesmente morria aqui, sem mover e sem dizer nada — o
                    // que se lê como sistema quebrado, não como regra. Agora
                    // ele abre o painel, que nomeia a ação e diz por que está
                    // pedindo: o gesto vira o começo da pergunta.
                    if (exigeTexto) {
                        this.abrirPendente(tarefa, status);

                        // A mira do gesto que abriu o painel sobrevive à espera:
                        // sem esta linha, responder ao motivo entregava o card
                        // no fim da coluna, desfazendo a única coisa que o
                        // arrasto tinha escolhido além da etapa.
                        if (this.pendente) {
                            this.pendente.ordem = ordem;
                        }

                        return;
                    }

                    // O card muda de coluna ANTES da resposta.
                    //
                    // Sem isto, soltar não fazia nada visível até o servidor
                    // responder: o card ficava na coluna de origem por meio
                    // segundo, e o gesto parecia ter falhado — a ponto de se
                    // arrastar de novo. Quem chega depois é a verdade: a
                    // resposta redesenha o quadro e corrige a posição, inclusive
                    // a ordem dentro da coluna, que é o servidor quem decide.
                    // Se o movimento for recusado, o card volta pelo mesmo
                    // caminho, com a frase explicando.
                    this.adiantarMovimento(tarefa, status);

                    this.$refs.formMover.action = this.rotaMover.replace('__ID__', tarefa);
                    // A resposta redesenha o quadro e devolve a rolagem de
                    // ANTES do gesto — `data-acompanha` é o que faz a tela
                    // terminar no card, onde quer que o destino esteja. Vale
                    // também para a recusa: o quadro volta com o card na
                    // origem, e é para lá que a vista vai, junto da frase.
                    this.$refs.formMover.dataset.acompanha = tarefa;
                    this.$refs.statusMover.value = status;
                    this.$refs.deStatusMover.value = de ?? '';
                    // A coluna de destino inteira, quando o gesto mirou um lugar
                    // nela. Vazia, o servidor decide pela régua automática, que
                    // é o que sempre aconteceu quando ninguém mirou.
                    this.escreverOrdem(this.$refs.formMover, ordem);
                    this.tipoArrastado = null;
                    this.statusArrastado = null;
                    // `requestSubmit` e não `submit`: só o primeiro dispara o
                    // evento `submit`, que é por onde a camada parcial escuta.
                    // Com `submit()` o navegador envia por cima dela, e a tela
                    // recarrega como antes — sem erro nenhum a acusar.
                    this.$refs.formMover.requestSubmit();
                },
            }));
        });

        /**
         * Os envios que NÃO recarregam a página.
         *
         * Mexer numa tarefa quase sempre acontece com ela aberta e no meio de
         * uma leitura: marcar dois itens do checklist, corrigir um comentário,
         * responder a dúvida que está na tarja. Recarregar ali cobrava três
         * coisas de uma vez — o texto ainda não publicado do campo de
         * comentário, a rolagem do quadro e a posição na conversa. O
         * `tarefa-aberta` da sessão remendava só o sintoma mais visível,
         * reabrindo o modal; reabrir, porém, não é não ter fechado.
         *
         * O que sobra recarregando é deliberado: mover card, criar tarefa,
         * salvar o formulário e excluir. Nessas quatro, ou a tarefa deixa de
         * estar onde estava, ou o modal tinha mesmo que fechar.
         */
        (() => {
            /**
             * A ordem das respostas, não a dos envios.
             *
             * Marcar três itens seguidos dispara três envios, e nada garante
             * que voltem na ordem em que saíram. Sem este contador, a resposta
             * mais VELHA a chegar seria a última a pintar — e a tela mostraria
             * o penúltimo estado do checklist como se fosse o atual.
             */
            let ultimoEnvio = 0;

            /**
             * Quantos envios estão no ar AGORA.
             *
             * `ultimoEnvio` responde "qual é o mais novo", não "ainda tem algum
             * viajando" — e é a segunda pergunta que a atualização automática
             * faz antes de redesenhar. Perguntar pelo quadro no meio de uma
             * gravação traria o quadro de ANTES dela, e pintá-lo desfaria na
             * tela o que a pessoa acabou de fazer.
             */
            let enviosEmVoo = 0;

            /**
             * A impressão digital do quadro que está na tela.
             *
             * É ela que viaja na pergunta da atualização automática, e é por
             * ela que o servidor responde "nada mudou" sem montar o quadro
             * inteiro (ver `TarefaController::assinaturaDoQuadro`). Toda
             * resposta parcial traz a assinatura nova junto — inclusive as que
             * só trocam pedaços —, senão a própria ação da pessoa deixaria a
             * tela desatualizada aos olhos da pergunta seguinte.
             */
            let assinaturaDoQuadro = @json($assinatura);

            const quadro = () => document.querySelector('[data-corpo-do-quadro]');

            /**
             * Troca o conteúdo de uma região, com o Alpine sabendo.
             *
             * `mutateDom` cala o observador do Alpine durante a troca, e os
             * dois passos de dentro fazem o que ele faria: `destroyTree` solta
             * os efeitos e os `x-ref` do que sai, `initTree` liga o que entra.
             * Sem o primeiro, cada troca deixa para trás os observadores dos
             * cards que sumiram; sem o segundo, o quadro novo é HTML morto.
             *
             * `initTree` no PRÓPRIO alvo é de propósito: ele pula quem já foi
             * iniciado (o Alpine marca cada elemento), então o invólucro — que
             * é quem carrega o `x-data` do quadro e o do formulário da tarefa —
             * passa incólume, e é por isso que a coluna recolhida, a etapa do
             * celular e a seleção do teclado sobrevivem à troca.
             */
            const trocar = (alvo, html) => {
                Alpine.mutateDom(() => {
                    [...alvo.children].forEach((filho) => Alpine.destroyTree(filho));
                    alvo.innerHTML = html;
                    Alpine.initTree(alvo);
                });
            };

            /**
             * Abre a tarefa: busca o modal dela e só então manda mostrar.
             *
             * O quadro não traz mais modal nenhum — ele trazia o de todas as
             * tarefas, e era isso que fazia a tela pesar megabytes. O preço da
             * troca é esta viagem, e ela é curta: um modal tem ~45 KB.
             *
             * Um por vez, e sempre novo. Guardar os já abertos pouparia a
             * segunda viagem e traria de volta o defeito que a mudança
             * resolveu: modal guardado é a foto de quando foi buscado, e
             * reabrir a tarefa depois de alguém tê-la mexido mostraria o estado
             * velho — com o agravante de que o Salvar dali gravaria por cima.
             *
             * Enquanto a viagem não volta, nada acontece na tela. É de
             * propósito: abrir um modal vazio e preenchê-lo depois faria a
             * tarefa piscar, e o vazio duraria o suficiente para alguém clicar
             * nele. Falhou, recarrega — é o mesmo caminho de `enviar()` para a
             * sessão vencida.
             */
            const abrirTarefa = async (id) => {
                try {
                    const resposta = await fetch('{{ url('tarefas') }}/' + id + '/modal', {
                        headers: { 'Accept': 'text/html' },
                    });

                    if (! resposta.ok) {
                        location.reload();

                        return;
                    }

                    trocar(document.querySelector('[data-modais]'), await resposta.text());

                    window.dispatchEvent(new CustomEvent('open-modal', { detail: 'editar-tarefa-' + id }));
                } catch (erro) {
                    location.reload();
                }
            };

            // Um ponto de entrada só, por evento: o clique no card, o Enter do
            // teclado e a reabertura depois de comentar pedem a mesma coisa, e
            // moram em três lugares que não se enxergam — a partial da coluna,
            // o componente Alpine do quadro e um script solto no fim da página.
            window.addEventListener('abrir-tarefa', (evento) => abrirTarefa(evento.detail));

            /**
             * Devolve o foco a quem o tinha, se ele sobreviveu à troca.
             *
             * O campo de novo item é a razão desta função existir: escrevem-se
             * vários itens seguidos, e um Enter que tira o cursor do campo
             * transforma uma lista de cinco linhas em cinco cliques a mais.
             */
            const guardarFoco = () => {
                const ativo = document.activeElement;

                if (! ativo?.id || ! ('selectionStart' in ativo)) {
                    return () => {};
                }

                const { id, selectionStart, selectionEnd } = ativo;

                return () => {
                    const voltou = document.getElementById(id);

                    if (! voltou) {
                        return;
                    }

                    voltou.focus();

                    // Só onde faz sentido: `setSelectionRange` estoura em campo
                    // de tipo número ou e-mail, e o foco já estaria dado.
                    try {
                        voltou.setSelectionRange(selectionStart, selectionEnd);
                    } catch (erro) {
                        // campo sem seleção posicionável; o foco basta
                    }
                };
            };

            /**
             * As rolagens que a troca zeraria.
             *
             * O quadro é uma faixa de seis colunas que rola na horizontal, e
             * cada coluna rola por dentro. Redesenhá-los devolve tudo ao
             * começo: quem marcou um item na quinta coluna reencontrava o
             * próprio card à mão, e a queixa que o redesenho existia para
             * resolver voltava por outra porta.
             */
            const guardarRolagem = () => {
                const posicoes = new Map();

                document.querySelectorAll('[data-rolagem]').forEach((area) => {
                    posicoes.set(area.dataset.rolagem, [area.scrollLeft, area.scrollTop]);
                });

                return () => document.querySelectorAll('[data-rolagem]').forEach((area) => {
                    const posicao = posicoes.get(area.dataset.rolagem);

                    if (posicao) {
                        [area.scrollLeft, area.scrollTop] = posicao;
                    }
                });
            };

            /**
             * O que está escrito e ainda não foi enviado.
             *
             * Corrigir um comentário publicado redesenha a conversa INTEIRA, e
             * o campo de comentário novo mora dentro dela. Sem guardar o valor,
             * consertar a vírgula de uma frase antiga apagaria o parágrafo que
             * ainda estava sendo escrito logo abaixo — que é exatamente o
             * prejuízo que estas ações deixaram de causar ao parar de recarregar.
             */
            const guardarRascunhos = () => {
                const valores = new Map();

                document.querySelectorAll('[data-preserva][id]').forEach((campo) => {
                    valores.set(campo.id, campo.value);
                });

                return () => valores.forEach((valor, id) => {
                    const campo = document.getElementById(id);

                    if (campo) {
                        campo.value = valor;
                    }
                });
            };

            const aplicar = (dados) => {
                // Antes de qualquer troca: o que a resposta trouxe é o retrato
                // de agora, e é ele que a próxima pergunta compara.
                if (dados.assinatura) {
                    assinaturaDoQuadro = dados.assinatura;
                }

                const restaurarFoco = guardarFoco();
                const restaurarRolagem = guardarRolagem();
                const restaurarRascunhos = guardarRascunhos();
                const corpo = quadro();

                if (corpo && dados.quadro) {
                    trocar(corpo, dados.quadro);
                }

                for (const [nome, html] of Object.entries(dados.pedacos ?? {})) {
                    const alvo = document.querySelector(`[data-pedaco="${nome}"]`);

                    if (alvo) {
                        trocar(alvo, html);
                    }
                }

                // Os modais só voltam quando a ação mudou QUAIS tarefas existem
                // no quadro. Sem esta troca, a tarefa recém-criada apareceria no
                // quadro e não abriria ao clique — o modal dela não existiria —,
                // e a excluída deixaria o dela para trás. Trocá-los também é o
                // que FECHA o modal depois de salvar e de excluir: cada um nasce
                // com `show: false`, como acontecia quando a página recarregava.
                if (dados.modais !== null && dados.modais !== undefined) {
                    trocar(document.querySelector('[data-modais]'), dados.modais);
                }

                // O "nova tarefa" é o único modal fora do bloco acima — ele não
                // pertence a tarefa nenhuma —, então é fechado pelo nome. E
                // esvaziado: por não ser trocado, ele guardaria o que acabou de
                // ser gravado, e a próxima tarefa nasceria com o título da
                // anterior no campo. A recarga fazia essa limpeza de graça.
                if (dados.fecharModal) {
                    window.dispatchEvent(new CustomEvent('close-modal', { detail: dados.fecharModal }));
                }

                // Esvaziar é pedido à parte, e não consequência de fechar: no
                // modal de edição os campos JÁ contêm o que foi salvo, e um
                // `reset()` os devolveria ao valor que o servidor imprimiu
                // antes — desfazendo na tela a edição que acabou de gravar.
                if (dados.limparModal && dados.fecharModal) {
                    document.querySelectorAll(`[data-modal="${dados.fecharModal}"] form`)
                        .forEach((form) => form.reset());
                }

                // O painel de motivo é estado do quadro, não do HTML: sem
                // fechá-lo, ele reabriria sozinho assim que o card voltasse a
                // ser desenhado, já com a tarefa travada atrás dele.
                if (corpo) {
                    const estado = Alpine.$data(corpo);
                    estado.pendente = null;
                    estado.textoPendente = '';
                    estado.enviandoPendente = false;
                    // As prévias da devolução que acabou de ser enviada — sem
                    // isto, o próximo painel abriria com as imagens da
                    // anterior, e os `blob:` ficariam presos até o F5.
                    estado.limparImagens();
                    // E o gesto que abriu o painel: pelo teclado ele continua
                    // de pé até aqui, e o quadro redesenhado voltaria com as
                    // colunas fora do fluxo apagadas. Pelo mouse já não há
                    // gesto nenhum, e largar de novo não faz nada.
                    estado.largar();
                }

                // O rodapé do modal decide entre travar e destravar por um
                // booleano do Alpine, porque a decisão muda sem recarga.
                const avisos = document.querySelector(`[data-pedaco="avisos-${dados.tarefa}"]`);
                const formulario = avisos?.closest('form');

                if (formulario) {
                    const estado = Alpine.$data(formulario);
                    estado.bloqueada = dados.bloqueada;
                    estado.travando = false;
                }

                if (dados.aviso) {
                    // Um aviso por vez: o `x-aviso` se teleporta para a pilha
                    // fixa do layout, e limpar o suporte leva junto o anterior.
                    trocar(document.querySelector('[data-avisos-parciais]'), dados.aviso);
                }

                // Nesta ordem: o texto volta ao campo antes de a rolagem e o
                // cursor procurarem por ele.
                restaurarRascunhos();
                restaurarRolagem();
                restaurarFoco();
            };

            const enviar = async (form, quemEnviou) => {
                const meuEnvio = ++ultimoEnvio;

                // Lido ANTES da viagem: a resposta redesenha o quadro e leva o
                // formulário junto — depois dela o atributo já não existe.
                const acompanhar = form.dataset.acompanha;

                // A query string vai junto porque o quadro que volta é o quadro
                // FILTRADO: sem ela, marcar um item dentro de um recorte
                // devolveria o quadro inteiro e desfaria o filtro na tela.
                //
                // O botão que enviou vai no FormData: o menu "Mover ▾" é UM
                // formulário com um submit por destino, e o destino viaja no
                // `name`/`value` do botão clicado — que `new FormData(form)`
                // sozinho NÃO inclui. O envio nativo incluía, e foi assim que
                // a falta passaria despercebida até o primeiro clique.
                const resposta = await fetch(form.action + location.search, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: quemEnviou ? new FormData(form, quemEnviou) : new FormData(form),
                });

                if (! resposta.ok) {
                    const dados = await resposta.json().catch(() => ({}));
                    const frase = Object.values(dados.errors ?? {}).flat().join(' ') || dados.message;

                    // Sem frase que explique, quem responde é a recarga: sessão
                    // expirada e CSRF vencido chegam assim, e insistir em ficar
                    // na página deixaria a pessoa clicando no vazio.
                    if (! frase) {
                        location.reload();

                        return;
                    }

                    throw new Error(frase);
                }

                const dados = await resposta.json();

                // Resposta atrasada de um envio que já foi superado: aplicar
                // agora seria voltar a tela para um estado anterior.
                if (meuEnvio === ultimoEnvio) {
                    aplicar(dados);

                    // O foco acompanha o card movido. `aplicar` devolve as
                    // rolagens a onde ESTAVAM — certo para quem marcou um item,
                    // errado para quem moveu: o card acabou de trocar de
                    // coluna, e a coluna pode estar noutro ponto da tela. Só
                    // quando o quadro voltou redesenhado: sem ele, o card não
                    // saiu do lugar e não há o que perseguir.
                    if (acompanhar && dados.quadro) {
                        document.querySelector(`[data-tarefa="${acompanhar}"]`)
                            ?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
                    }
                }

                // O campo que o envio esvaziou. Perguntar publica o que está
                // escrito no comentário — deixá-lo na tela faria o Salvar
                // seguinte publicar o mesmo texto de novo.
                if (form.dataset.limpa) {
                    const campo = document.querySelector(form.dataset.limpa);

                    if (campo) {
                        campo.value = '';
                    }
                }
            };

            document.addEventListener('submit', (evento) => {
                const form = evento.target;

                if (! (form instanceof HTMLFormElement) || ! form.hasAttribute('data-parcial')) {
                    return;
                }

                evento.preventDefault();

                // Aqui, e não dentro de `enviar`: o contador precisa subir
                // ANTES do primeiro `await`, senão sobra uma fresta em que a
                // atualização automática ainda se acha livre para redesenhar.
                enviosEmVoo++;

                // A recusa que veio sem passar pelo controlador — validação,
                // permissão — é dita pelo molde de aviso que a própria tela
                // imprime. Remontar o `x-aviso` em JavaScript significaria
                // copiar os tokens de cor para cá, e a cópia divergiria do
                // componente na primeira mudança de tema.
                enviar(form, evento.submitter).catch((erro) => {
                    const escapado = document.createElement('div');
                    escapado.textContent = erro.message;

                    trocar(
                        document.querySelector('[data-avisos-parciais]'),
                        document.querySelector('[data-molde-de-erro]').innerHTML
                            .replace('__FRASE__', escapado.innerHTML),
                    );
                }).finally(() => {
                    enviosEmVoo--;

                    /*
                     * A viagem acabou — quem se trancou no clique precisa saber.
                     *
                     * Trocar a recarga por um `fetch` tirou de cena algo que
                     * ninguém tinha assumido: era a recarga que devolvia o
                     * formulário ao repouso. O botão que se tranca para o
                     * segundo clique não publicar duas vezes ficava trancado
                     * para sempre nos dois formulários que a resposta NÃO
                     * redesenha — o de editar (o bloco de modais só volta
                     * quando o conjunto de tarefas muda) e o de nova tarefa
                     * (que mora fora daquele bloco). Reabrir a tarefa mostrava
                     * "Salvando…" num botão morto.
                     *
                     * No `finally`, e não no `aplicar`: a recusa é justamente
                     * quando se precisa do botão de volta — "Alguém já moveu
                     * esta tarefa" deixava a tela sem como tentar outra vez.
                     *
                     * Um evento, e não `Alpine.$data(form).enviando = false`:
                     * assim as duas metades do estado moram lado a lado no
                     * formulário que as tem, e o `data-parcial` de quem não se
                     * tranca ignora o aviso sem precisar saber dele.
                     */
                    form.dispatchEvent(new CustomEvent('envio-terminou'));
                });
            });

            /*
             * A atualização automática: o quadro que mudou na mão de OUTRA
             * pessoa.
             *
             * O quadro é de time — a mesma tarefa passa por três pessoas no
             * mesmo dia —, mas a tela só via o que a própria pessoa fazia. Quem
             * a deixava aberta trabalhava sobre o retrato do momento em que
             * abriu: movia um card que já tinha sido movido e recebia "Alguém
             * já moveu esta tarefa", que é a recusa certa dita tarde demais.
             *
             * O que sai daqui é a ASSINATURA, não um pedido de quadro. A
             * resposta de "nada mudou" são duas chaves, e é isso que permite
             * perguntar de trinta em trinta segundos sem mandar ~900 KB por
             * pessoa e por pergunta (ver `TarefaController::atualizacoes`).
             *
             * Redesenhar é `aplicar()`, o mesmo caminho das ações parciais: a
             * rolagem do quadro e de cada coluna, o foco e o que está escrito e
             * não foi enviado voltam para onde estavam. Sem ele, o quadro
             * mudando sozinho jogaria a vista para o começo a cada trinta
             * segundos, que é pior do que estar desatualizado.
             */
            const INTERVALO_DA_ATUALIZACAO = 30000;

            /*
             * Quando NÃO perguntar — e são todos casos de gesto em curso, não
             * de economia. O quadro que volta é HTML novo, e trocá-lo por baixo
             * de um gesto o desfaz:
             *
             * - com um card na mão, o arrasto perde o elemento arrastado;
             * - com o painel de motivo aberto, some o texto sendo escrito nele;
             * - com um envio no ar, o quadro desta viagem pode ser o de ANTES
             *   da ação, e pintá-lo desfaria na tela o que acabou de ser feito.
             *
             * A aba escondida não pergunta pelo mesmo motivo do renovador de
             * token do login: ninguém está olhando, e quem volta pergunta na
             * hora.
             */
            const podeAtualizar = () => {
                if (document.visibilityState !== 'visible' || enviosEmVoo > 0) {
                    return false;
                }

                const corpo = quadro();
                // O Alpine pode ainda não ter percorrido a página: este script
                // roda na análise do HTML, e o primeiro intervalo é só daqui a
                // trinta segundos — mas a volta à aba dispara antes disso.
                const estado = corpo ? Alpine.$data(corpo) : null;

                return !! estado && ! estado.arrastando && ! estado.cardEmMovimento && ! estado.pendente;
            };

            const atualizar = async () => {
                if (! podeAtualizar()) {
                    return;
                }

                const marca = ultimoEnvio;

                // A query string vai junto porque o quadro que volta é o quadro
                // FILTRADO, pela mesma razão de `enviar()`: sem ela, a
                // atualização automática desfaria sozinha o recorte da tela.
                const busca = new URLSearchParams(location.search);
                busca.set('assinatura', assinaturaDoQuadro);

                try {
                    const resposta = await fetch('{{ route('tarefas.atualizacoes') }}?' + busca, {
                        headers: { 'Accept': 'application/json' },
                    });

                    if (! resposta.ok) {
                        return;
                    }

                    const dados = await resposta.json();

                    // Alguém agiu, ou pegou um card, enquanto a viagem
                    // acontecia. Nada se perde ao desistir: a assinatura só é
                    // guardada quando a resposta é aplicada, então a pergunta
                    // seguinte traz a mesma novidade de novo.
                    if (ultimoEnvio !== marca || ! podeAtualizar()) {
                        return;
                    }

                    // Sem quadro é o caso comum — nada mudou. Guardar a
                    // assinatura ainda assim é de graça e mantém as duas pontas
                    // falando do mesmo retrato.
                    if (dados.quadro) {
                        aplicar(dados);
                    } else {
                        assinaturaDoQuadro = dados.assinatura;
                    }
                } catch (erro) {
                    // Sem rede, ou sessão vencida: fica quieto e tenta no
                    // intervalo seguinte. Recarregar por conta — como `enviar()`
                    // faz — é decisão grande demais para algo que acontece
                    // sozinho, e levaria junto o que estiver escrito num modal
                    // aberto.
                }
            };

            setInterval(atualizar, INTERVALO_DA_ATUALIZACAO);

            // Voltar para a aba é quando a defasagem é maior e mais visível:
            // quem passou dez minutos noutra janela reencontra o quadro de dez
            // minutos atrás. Mesmo par `setInterval` + `visibilitychange` do
            // renovador de token do login.
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    atualizar();
                }
            });
        })();
    </script>

    {{-- Modal: nova tarefa --}}
    <x-modal name="nova-tarefa" maxWidth="tarefa">
        @include('tarefas._form', ['tarefa' => null, 'sistemas' => $sistemas, 'usuarios' => $usuarios])
    </x-modal>

    {{-- Modal: editar tarefa — VAZIO ao abrir a tela.

         Ele já foi impresso aqui para todas as tarefas do quadro, e era o que
         fazia a tela pesar: o `_form` traz a conversa inteira, a lista de
         conferência e os anexos de cada tarefa, ~45 KB por card. Cento e vinte
         tarefas somavam 5,5 MB — baixados por completo a cada abertura, para
         mostrar no máximo um modal.

         Agora o bloco recebe UM modal por vez, buscado no clique
         (`tarefas.modal`, ver `abrirTarefa` no script). Duas consequências que
         eram efeito colateral e viraram regra:

         - o modal aberto é sempre o do estado ATUAL da tarefa, porque acabou de
           ser buscado — antes ele era a foto do momento em que a tela abriu;
         - esvaziar o bloco fecha o que estiver aberto, que é o que a troca dos
           modais já fazia depois de Salvar, criar e excluir. --}}
    <div data-modais></div>

    {{--
        Comentar recarrega a página, e sem isto o modal da tarefa fecharia a
        cada frase escrita. O evento é o mesmo que o clique no card dispara, e
        só depois de o Alpine já ter percorrido a página (`alpine:initialized`)
        — em `alpine:init` o modal ainda não está escutando e o aviso cairia no
        vazio.
    --}}
    @if (session('tarefa-aberta') && $tarefas->contains('id', session('tarefa-aberta')))
        <script>
            document.addEventListener('alpine:initialized', () => {
                {{-- `abrir-tarefa`: o modal não está na página, é buscado. Sem
                     isto, o `open-modal` cairia no vazio e a tarefa que a
                     pessoa estava lendo não voltaria. --}}
                window.dispatchEvent(new CustomEvent('abrir-tarefa', {
                    detail: {{ session('tarefa-aberta') }},
                }));
            });
        </script>
    @endif
</x-app-layout>
