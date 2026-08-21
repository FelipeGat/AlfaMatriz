{{--
    O relógio da sessão — o aviso antes de derrubar, e a volta ao login.

    A página é desenhada uma vez e fica aberta; a sessão continua correndo no
    servidor. Quando ela vence, o HTML na tela não sabe de nada: os botões
    continuam ali, com cara de funcionando, e o primeiro clique volta uma faixa
    vermelha pedindo F5 — que foi o defeito relatado. São duas camadas para
    fechar isso, e as duas moram aqui porque dependem uma da outra:

    1. QUANDO O SERVIDOR RECUSA. Todo `fetch` de mesma origem passa a ser
       observado: 401 ou 419 significa que a sessão acabou, e daí sai a ida ao
       login — sem faixa de erro e sem recarga na mão. É o que o quadro de
       tarefas mais usa, e é o que torna a volta automática de verdade lá: ele
       pergunta ao servidor a cada trinta segundos por conta própria.

    2. QUANDO A PESSOA PAROU. As telas que não conversam sozinhas com o
       servidor (Clientes, Cobranças) ficariam paradas até alguém clicar. Aqui
       um relógio conta a ociosidade REAL — mouse e teclado, não requisição — e
       avisa antes de encerrar. Nunca em silêncio: derrubar sem avisar é o que
       nenhum sistema sério faz.

    Ver `App\Http\Controllers\SessaoController` para as duas pontas no servidor.
--}}

@php
    $vidaEmMinutos = (int) config('session.lifetime');

    // A margem existe para o relógio da PESSOA vencer sempre antes do relógio
    // do SERVIDOR. Sem ela os dois empatariam, e o aviso apareceria com a
    // sessão já morta — o botão "Continuar conectado" não teria o que renovar
    // e levaria ao login, que é exatamente o que ele promete evitar.
    //
    // Ela é também o intervalo mínimo entre duas renovações: enquanto houver
    // gente mexendo, uma ida ao servidor a cada dez minutos mantém a sessão de
    // pé sem transformar o painel em máquina de bater ponto.
    $margemEmMinutos = max(1, min(10, intdiv($vidaEmMinutos, 4)));

    // O painel de parede: conta que só enxerga o quadro e fica aberta o dia
    // todo num monitor da sala. Nela o relógio não roda — ninguém toca no
    // mouse de um monitor, e ele derrubaria a exibição a cada meia hora.
    //
    // A CAMADA 1 continua valendo mesmo aqui: se a sessão morrer por outro
    // motivo (o servidor reiniciou, alguém limpou as sessões), o monitor tem
    // de mostrar a tela de entrada e não um quadro parado com cara de vivo —
    // que é justamente o defeito que tudo isto veio consertar.
    $contaDeExibicao = auth()->user()?->ehContaDeExibicao() ?? false;
@endphp

{{-- O encerramento é envio de formulário, e não `fetch`: o navegador segue o
     redirecionamento sozinho e chega ao login já com a frase do porquê — a
     mesma que o tratamento de 419 do `bootstrap/app.php` usa. --}}
<form id="sessao-encerrar" method="POST" action="{{ route('sessao.encerrar') }}" class="hidden">
    @csrf
</form>

@unless ($contaDeExibicao)
{{-- O aviso. Mesma moldura do <x-confirmar> — nenhum valor novo aqui.

     Ele NÃO é um <x-modal>: aquele fecha no Esc e no clique fora, e um aviso
     que some sozinho deixaria a pessoa sendo derrubada logo depois sem saber
     por quê. Daqui só se sai pelos dois botões.

     Acima da pilha de avisos (z-[60]), que é o teto atual da tela: se a sessão
     está acabando, não há recado mais importante para estar por cima. --}}
<div x-data="{
         restante: null,

         // O foco vai para o botão de continuar na ABERTURA, e só nela: a
         // contagem chega de segundo em segundo, e reagir a toda ela devolveria
         // o foco à força de quem tivesse acabado de tabular para o de sair.
         mostrar(valor) {
             const abrindo = this.restante === null && valor !== null;
             this.restante = valor;

             if (abrindo) {
                 this.$nextTick(() => this.$refs.continuar?.focus());
             }
         },
     }"
     x-on:sessao-aviso.window="mostrar($event.detail)"
     x-show="restante !== null" x-cloak
     class="fixed inset-0 z-[70] flex items-center justify-center p-4"
     role="alertdialog" aria-modal="true" aria-labelledby="sessao-aviso-titulo">
    <div class="absolute inset-0 bg-black/60"></div>

    <div class="relative w-full max-w-[420px] rounded-panel border border-line bg-panel p-5">
        <div class="flex items-start gap-3">
            <span class="h-8 w-8 shrink-0 rounded-tile flex items-center justify-center"
                  style="background: rgb(var(--crit) / var(--tint-alpha)); color: rgb(var(--crit))">
                <span class="h-[17px] w-[17px]"><x-nav-icon name="clock" /></span>
            </span>

            <div class="min-w-0">
                <h2 id="sessao-aviso-titulo" class="font-display text-[15.5px] font-semibold text-ink">
                    Sua sessão está prestes a expirar
                </h2>
                <p class="mt-1 text-[13px] text-ink-dim">
                    Por segurança, você será desconectado em
                    <span class="font-semibold text-ink" x-text="restante"></span>
                    <span x-text="restante === 1 ? 'segundo' : 'segundos'"></span>.
                    O que estiver aberto e não salvo será perdido.
                </p>
            </div>
        </div>

        <div class="mt-5 flex items-center justify-end gap-2">
            <button type="button" @click="$dispatch('sessao-sair')"
                    class="h-9 px-3.5 rounded-control border border-btn-line text-[12.5px] font-semibold
                           text-ink-dim hover:text-ink transition">
                Sair agora
            </button>

            <button type="button" x-ref="continuar" @click="$dispatch('sessao-continuar')"
                    class="h-9 px-3.5 rounded-control bg-brand text-on-brand text-[12.5px] font-semibold
                           hover:bg-brand-bright transition">
                Continuar conectado
            </button>
        </div>
    </div>
</div>
@endunless

<script>
    (function () {
        const VIDA_MS = {{ $vidaEmMinutos }} * 60 * 1000;

        let prazoDoServidor = Date.now() + VIDA_MS;
        let encerrando = false;
        let avisando = false;

        @unless ($contaDeExibicao)
        // O limite da PESSOA: quanto tempo sem tocar em nada até ser
        // desconectada. Sai da vida da sessão menos a margem — não é número
        // inventado aqui, muda junto com `SESSION_LIFETIME`.
        const LIMITE_MS = {{ $vidaEmMinutos - $margemEmMinutos }} * 60 * 1000;
        const RENOVACAO_MS = {{ $margemEmMinutos }} * 60 * 1000;

        // Um minuto de aviso: dá para ver, ler e clicar sem pressa, e é curto
        // demais para virar uma segunda sessão de quem já foi embora.
        const AVISO_MS = 60 * 1000;

        // A atividade mora no localStorage porque ela é da PESSOA, não da aba:
        // com o painel aberto em duas abas, a que está parada não pode
        // derrubar a que está sendo usada. Gravar tem custo, e a resolução de
        // meio minuto é fina o suficiente para um limite de quase duas horas.
        const CHAVE_ATIVIDADE = 'alfamatriz:atividade';
        const GRAVACAO_MS = 30 * 1000;

        let atividadeLocal = Date.now();
        let ultimaGravacao = 0;
        let ultimoPedido = Date.now();
        @endunless

        @unless ($contaDeExibicao)
        const lerAtividade = () => {
            try {
                const guardada = Number(localStorage.getItem(CHAVE_ATIVIDADE));

                return Number.isFinite(guardada) ? Math.max(guardada, atividadeLocal) : atividadeLocal;
            } catch (erro) {
                // Navegação anônima ou cota cheia: a aba conta sozinha, e o
                // pior que acontece é ela não enxergar a irmã ao lado.
                return atividadeLocal;
            }
        };

        const marcarAtividade = () => {
            // Com o aviso na tela, mexer o mouse NÃO conta. É o único momento
            // em que "ainda estou aqui" precisa ser deliberado: um esbarrão na
            // mesa não pode segurar de pé a sessão de uma tela abandonada.
            if (avisando) {
                return;
            }

            const agora = Date.now();
            atividadeLocal = agora;

            if (agora - ultimaGravacao < GRAVACAO_MS) {
                return;
            }

            ultimaGravacao = agora;

            try {
                localStorage.setItem(CHAVE_ATIVIDADE, String(agora));
            } catch (erro) {
                // ver `lerAtividade`
            }
        };

        @endunless

        const encerrar = () => {
            if (encerrando) {
                return;
            }

            encerrando = true;
            document.getElementById('sessao-encerrar').submit();
        };

        /*
         * Camada 1: o `fetch` que descobre que a sessão acabou.
         *
         * O remendo é no `window.fetch` e não em cada chamada porque elas são
         * nove, em quatro arquivos, e a décima nasceria sem o tratamento — o
         * mesmo motivo que fez o quadro de tarefas ter UM ponto de entrada por
         * evento. Quem chama continua escrevendo `fetch(...)` e não precisa
         * saber que isto existe.
         */
        const fetchOriginal = window.fetch.bind(window);

        window.fetch = async (...argumentos) => {
            const resposta = await fetchOriginal(...argumentos);

            // `basic` é resposta de mesma origem. A consulta de CNPJ e a de CEP
            // do cadastro de cliente voltam como `cors`, e o que elas recusam
            // não diz nada sobre a sessão daqui.
            if (resposta.type !== 'basic') {
                return resposta;
            }

            if (resposta.status === 401 || resposta.status === 419) {
                encerrar();

                // A promessa que nunca resolve. Sem ela quem pediu segue seu
                // curso e pinta "Unauthenticated." na tela no intervalo entre
                // descobrir e sair da página. A saída já está a caminho: não há
                // resposta que valha a pena entregar.
                return new Promise(() => {});
            }

            // Toda ida bem-sucedida ao servidor empurra o vencimento para
            // frente — inclusive a pergunta de trinta em trinta segundos do
            // quadro de tarefas. É por isso que o relógio da PESSOA existe: sem
            // ele, aquela pergunta seguraria a sessão de pé para sempre.
            if (resposta.ok) {
                prazoDoServidor = Date.now() + VIDA_MS;
            }

            return resposta;
        };

        @unless ($contaDeExibicao)
        const renovar = async () => {
            // Marcado ANTES da viagem: sem isso, os tiques que passam enquanto
            // ela acontece disparariam um pedido cada.
            ultimoPedido = Date.now();

            try {
                const resposta = await fetch('{{ route('sessao.estado') }}', {
                    headers: { 'Accept': 'application/json' },
                });

                if (! resposta.ok) {
                    return;
                }

                const dados = await resposta.json();

                if (dados.expira_em) {
                    prazoDoServidor = dados.expira_em;
                }
            } catch (erro) {
                // Sem rede: o prazo continua o que era e o tique seguinte tenta
                // de novo. O 401/419 não cai aqui — o remendo acima o pegou.
            }
        };

        /*
         * Camada 2: o relógio.
         *
         * Quem manda é o mais próximo dos dois prazos — o da pessoa e o do
         * servidor. Enquanto houver atividade, a renovação mantém o do servidor
         * sempre além do da pessoa, e é a ociosidade que decide; parada a
         * atividade, os dois convergem para o mesmo instante.
         *
         * A comparação é de relógio de parede (`Date.now`), e não de contagem
         * de tiques: a aba de fundo tem seus temporizadores freados pelo
         * navegador, e a máquina que dorme não conta nada. Nos dois casos a
         * conta continua certa.
         */
        const tique = () => {
            const agora = Date.now();
            const restante = Math.min(prazoDoServidor, lerAtividade() + LIMITE_MS) - agora;

            if (restante <= 0) {
                encerrar();

                return;
            }

            if (restante <= AVISO_MS) {
                avisando = true;
                window.dispatchEvent(new CustomEvent('sessao-aviso', { detail: Math.ceil(restante / 1000) }));

                return;
            }

            if (avisando) {
                avisando = false;
                window.dispatchEvent(new CustomEvent('sessao-aviso', { detail: null }));
            }

            // Só renova quem tem alguém do outro lado, e só quando o prazo do
            // servidor já andou o bastante para valer a viagem.
            if (prazoDoServidor - agora <= LIMITE_MS
                && agora - lerAtividade() <= RENOVACAO_MS
                && agora - ultimoPedido >= RENOVACAO_MS) {
                renovar();
            }
        };

        ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart'].forEach((evento) => {
            document.addEventListener(evento, marcarAtividade, { passive: true, capture: true });
        });

        window.addEventListener('sessao-continuar', () => {
            avisando = false;
            window.dispatchEvent(new CustomEvent('sessao-aviso', { detail: null }));

            // A ordem importa: primeiro a atividade — que reabre o `marcarAtividade`
            // e move o prazo da pessoa —, depois a renovação, que move o do servidor.
            ultimaGravacao = 0;
            marcarAtividade();
            renovar();
        });

        window.addEventListener('sessao-sair', encerrar);

        /*
         * Com o aviso na tela, o teclado é dele e de mais ninguém.
         *
         * O véu segura o mouse — um clique num card atinge o aviso, não o card
         * —, mas não seguraria a tecla: o Esc atravessava e fechava o modal de
         * tarefa que estava ATRÁS, porque o `x-modal` escuta `escape` na
         * janela. Quem via isso via o aviso mandar embora o que ele acabara de
         * prometer que só se perderia sem salvar.
         *
         * Na CAPTURA, e não na subida: os ouvintes do `x-modal` moram na
         * janela e disparam por último, então parar aqui é a única forma de
         * chegar antes deles.
         */
        window.addEventListener('keydown', (evento) => {
            if (avisando && evento.key === 'Escape') {
                evento.stopImmediatePropagation();
                evento.preventDefault();
            }
        }, { capture: true });

        setInterval(tique, 1000);

        // Voltar para a aba é quando a defasagem é maior: quem passou duas
        // horas noutra janela precisa reencontrar o login, não o painel de duas
        // horas atrás. Voltar NÃO conta como atividade — se contasse, a sessão
        // vencida seria ressuscitada justamente por quem já a tinha perdido.
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                tique();
            }
        });
        @endunless
    })();
</script>
