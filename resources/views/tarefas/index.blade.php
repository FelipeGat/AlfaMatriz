@php
    /**
     * Os destinos cuja chegada exige um texto — o que abre o painel de motivo.
     *
     * Em andamento está na lista, mas com ressalva: só pede texto quando a
     * tarefa vem de um portão (ver `pedeTexto`). Vindo do Backlog é só começar
     * a trabalhar, e um painel ali pediria justificativa para pegar a própria
     * tarefa.
     */
    $etapasComTexto = ['em_desenvolvimento', 'cancelada', 'concluida', 'pronta_producao'];
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
        @include('tarefas._filtros', [
            'filtros' => $filtros,
            'sistemas' => $sistemas,
            'usuarios' => $usuarios,
            'raias' => $raias,
        ])

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
        <div x-data="quadroTarefas" @keydown.window="aoTeclar($event)" data-corpo-do-quadro
             class="relative flex-1 min-h-0 flex flex-col rounded-panel border border-line bg-board overflow-hidden">
            @include('tarefas._quadro', [
                'chips' => $chips,
                'etapas' => $etapas,
                'raias' => $raias,
                'filtros' => $filtros,
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
                        this.anexos = [...this.anexos, {
                            id: 'novo-' + (++this.sequencia),
                            arquivo,
                            autor_id: {{ auth()->id() ?? 'null' }},
                            nome_original: this.batizar(arquivo),
                            tamanho: arquivo.size,
                            tamanho_formatado: this.emTamanho(arquivo.size),
                            eh_imagem: arquivo.type.startsWith('image/'),
                            // `blob:` do próprio navegador: a miniatura mostra o
                            // arquivo que está na máquina de quem anexou,
                            // porque o servidor ainda não o viu.
                            url: URL.createObjectURL(arquivo),
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

                /** Sobre qual card o arrasto está pairando, para a linha de inserção. */
                sobreCard: null,

                init() {
                    try {
                        this.recolhidas = JSON.parse(
                            localStorage.getItem('alfamatriz:colunas-recolhidas') || '[]'
                        );
                    } catch (erro) {
                        this.recolhidas = [];
                    }
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

                // O que o painel de motivo está perguntando agora.
                pendente: null,
                textoPendente: '',
                enviandoPendente: false,

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
                 * Reordenar dentro da coluna, quando o card é solto sobre outro
                 * card da MESMA etapa.
                 *
                 * A ordem automática responde "o que é mais grave", que não é a
                 * mesma pergunta que "qual eu pego primeiro" — entre duas altas,
                 * quem conhece o trabalho sabe que uma destrava a outra.
                 *
                 * Posicionar é organizar trabalho alheio, então segue a mesma
                 * capacidade de priorizar: sem ela, o solto sobre card não faz
                 * nada e o evento sobe para a coluna, que decide se é movimento
                 * de etapa.
                 */
                ehReordenacao(status) {
                    return this.podeTriar
                        && this.arrastando !== null
                        && this.statusArrastado === status;
                },

                permitirSobreCard(evento, status, id) {
                    if (! this.ehReordenacao(status)) {
                        return;
                    }

                    evento.preventDefault();
                    evento.stopPropagation();

                    // A linha aparece acima do card sob o ponteiro — menos no
                    // próprio card arrastado, onde ela só piscaria embaixo do
                    // que já está na mão.
                    this.sobreCard = id === this.arrastando ? null : id;
                },

                soltarSobreCard(evento, alvo, status, chaveDaLista) {
                    if (! this.ehReordenacao(status)) {
                        return;
                    }

                    evento.preventDefault();
                    evento.stopPropagation();

                    // A lista é a da FAIXA, não a do status: com raias ligadas,
                    // a mesma etapa aparece uma vez por raia, e reordenar
                    // pegaria a primeira delas em vez daquela onde se soltou.
                    this.sobreCard = null;

                    const lista = document.querySelector(`[data-cards="${chaveDaLista}"]`);
                    const arrastado = lista.querySelector(`[data-tarefa="${this.arrastando}"]`);

                    this.largar();

                    if (! arrastado || arrastado === alvo) {
                        return;
                    }

                    const linhas = [...lista.children];
                    const destino = linhas.indexOf(alvo);
                    const origem = linhas.indexOf(arrastado);

                    lista.insertBefore(arrastado, origem < destino ? alvo.nextSibling : alvo);

                    // A coluna inteira vai no envio, lida do DOM: mandar só "X
                    // foi para N" faria o servidor recalcular o que o navegador
                    // já sabe, e divergir no segundo arrasto.
                    const envio = this.$refs.formPosicionar;
                    envio.querySelectorAll('input[data-ordem]').forEach((campo) => campo.remove());
                    this.$refs.statusPosicionar.value = status;

                    [...lista.children].forEach((linha) => {
                        const campo = document.createElement('input');
                        campo.type = 'hidden';
                        campo.name = 'ordem[]';
                        campo.dataset.ordem = '1';
                        campo.value = linha.dataset.tarefa;
                        envio.appendChild(campo);
                    });

                    envio.requestSubmit();
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
                        this.fecharPendente();
                        this.atalhosAbertos = false;

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
                        '/': () => document.querySelector('input[name="busca"]')?.focus(),
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
                        this.$dispatch('open-modal', 'editar-tarefa-' + this.selecionado);
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

                /** Põe o card selecionado no mesmo estado que um `dragstart` põe. */
                prepararTeclado(alvo) {
                    this.pegar(
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

                    if (destino) {
                        destino.prepend(card);
                    }
                },

                /** Esta etapa é destino possível para o card que está na mão? */
                aceita(status) {
                    return this.arrastando === null || this.destinos.includes(status);
                },

                pegar(tarefa, destinos, tipo, bloqueada, status) {
                    this.arrastando = tarefa;
                    // Bloquear é destino de quem ainda não está travado; para
                    // quem já está, a saída é o botão da própria tarja.
                    this.destinos = bloqueada ? destinos : [...destinos, 'bloqueio'];
                    this.tipoArrastado = tipo;
                    this.statusArrastado = status;
                },

                largar() {
                    if (this.arrastando !== null) {
                        this.fimDoArrasto = Date.now();
                    }

                    this.arrastando = null;
                    this.sobreCard = null;
                    this.destinos = [];
                    this.tipoArrastado = null;
                    this.statusArrastado = null;
                    this.sobre = null;
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
                        acao: (ehBloqueio ? this.rotaBloquear : this.rotaMover).replace('__ID__', tarefa),
                    };

                    this.$nextTick(() => this.$refs.textoPendente?.focus());
                },

                fecharPendente() {
                    this.pendente = null;
                    this.textoPendente = '';
                },

                permitir(status) {
                    if (this.arrastando !== null && this.aceita(status)) {
                        this.sobre = status;
                    }
                },

                soltar(status, exigeTexto) {
                    const tarefa = this.arrastando;
                    const permitido = this.aceita(status);
                    const tipo = this.tipoArrastado;
                    const de = this.statusArrastado;

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
                    this.$refs.statusMover.value = status;
                    this.$refs.deStatusMover.value = de ?? '';
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

            const enviar = async (form) => {
                const meuEnvio = ++ultimoEnvio;

                // A query string vai junto porque o quadro que volta é o quadro
                // FILTRADO: sem ela, marcar um item dentro de um recorte
                // devolveria o quadro inteiro e desfaria o filtro na tela.
                const resposta = await fetch(form.action + location.search, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: new FormData(form),
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

                // A recusa que veio sem passar pelo controlador — validação,
                // permissão — é dita pelo molde de aviso que a própria tela
                // imprime. Remontar o `x-aviso` em JavaScript significaria
                // copiar os tokens de cor para cá, e a cópia divergiria do
                // componente na primeira mudança de tema.
                enviar(form).catch((erro) => {
                    const escapado = document.createElement('div');
                    escapado.textContent = erro.message;

                    trocar(
                        document.querySelector('[data-avisos-parciais]'),
                        document.querySelector('[data-molde-de-erro]').innerHTML
                            .replace('__FRASE__', escapado.innerHTML),
                    );
                });
            });
        })();
    </script>

    {{-- Modal: nova tarefa --}}
    <x-modal name="nova-tarefa" maxWidth="tarefa">
        @include('tarefas._form', ['tarefa' => null, 'sistemas' => $sistemas, 'usuarios' => $usuarios])
    </x-modal>

    {{-- Modal: editar tarefa — uma por card, como em Clientes. O `_form` já
         traz a conversa dentro dele, porque o comentário é publicado pelo
         mesmo Salvar.

         O bloco é redesenhado inteiro quando uma ação muda QUAIS tarefas estão
         no quadro (criar, excluir, mover para etapa terminal): sem isso, a
         tarefa recém-criada apareceria no quadro e não abriria ao clique. E é
         essa troca que fecha o modal depois de Salvar, como a recarga fazia. --}}
    <div data-modais>
        @include('tarefas._modais', [
            'tarefas' => $tarefas,
            'sistemas' => $sistemas,
            'usuarios' => $usuarios,
        ])
    </div>

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
                window.dispatchEvent(new CustomEvent('open-modal', {
                    detail: 'editar-tarefa-{{ session('tarefa-aberta') }}',
                }));
            });
        </script>
    @endif
</x-app-layout>
