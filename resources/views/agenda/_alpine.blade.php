<script>
    document.addEventListener('alpine:init', () => {
        /**
         * O estado da Agenda: drawer do dia, detalhe da tarefa e modal de
         * compromisso.
         *
         * O que NÃO está aqui é tão importante quanto o que está: a faixa de
         * datas, a visão e o filtro de pessoas moram na URL (`agenda/_barra`).
         * Eles decidem o que o servidor consulta, e guardá-los aqui obrigaria a
         * tela a carregar o ano inteiro ou a refazer a consulta a cada seta.
         */
        Alpine.data('agendaTela', (inicial) => ({
            equipe: inicial.equipe,
            compromissos: inicial.compromissos,
            podeReagendar: inicial.podeReagendar,
            usuarioId: inicial.usuarioId,
            tarefas: inicial.tarefas,
            hoje: inicial.hoje,

            arrastando: null,

            drawer: { aberto: false, data: null, titulo: '', itens: [], carga: [] },
            detalhe: { aberto: false, reunioes: [] },

            // Nasce com a mesma forma que `modalVazio` devolve, e não com um
            // `null` preenchido no `init`: os `x-if` do modal leem
            // `modal.aberto` na primeira varredura do Alpine, e um null ali
            // derruba a tela inteira antes de o `init` chegar a rodar.
            modal: {
                aberto: false, salvando: false, erro: null, confirmandoExclusao: false,
                somenteLeitura: false,
                id: null, titulo: '', descricao: '',
                data: inicial.hoje, hora: '09:00',
                data_fim: inicial.hoje, hora_fim: '10:00',
                duracao_modo: true, duracao_horas: 1,
                tarefa_id: '', participantes: [],
                vinculoBusca: '', vinculoAberto: false,
                conflitos: [], carga: {},
            },

            /* ---------- cor ---------- */

            /**
             * O mesmo mapa de tons do `x-agenda-item`, aqui porque o drawer e o
             * detalhe desenham itens vindos do JSON, e não do Blade.
             *
             * Duas cópias é uma a mais do que se queria, e o que as mantém
             * juntas é a fonte: as duas leem `item.tom`, que vem do
             * `AgendaService`. Quem muda uma cor muda o token, e o token é um só.
             */
            tokenDe(tom) {
                return ({
                    marca: 'brand', ambar: 'amber', triagem: 'triagem', critico: 'crit',
                    bloqueio: 'bloqueio', retorno: 'retorno', exame: 'exame', warn: 'warn',
                })[tom] ?? null;
            },

            corDe(tom) {
                const token = this.tokenDe(tom);
                return token ? `rgb(var(--${token}))` : 'rgb(var(--ink-mute))';
            },

            tinteDe(tom) {
                const token = this.tokenDe(tom);
                return token ? `rgb(var(--${token}) / var(--tint-alpha))` : 'var(--chip)';
            },

            /* ---------- drawer do dia ---------- */

            async abrirDia(data) {
                // Fecha o que estiver aberto antes de abrir: o drawer nasce por
                // cima do modal, e deixar os dois de pé daria dois alvos de
                // Escape disputando a mesma tecla.
                this.detalhe.aberto = false;
                this.modal.aberto = false;

                const url = new URL(`{{ url('agenda/dia') }}/${data}`, window.location.origin);
                @foreach ($pessoas as $id)
                    url.searchParams.append('pessoas[]', '{{ $id }}');
                @endforeach

                const resposta = await fetch(url, { headers: { Accept: 'application/json' } });
                const dados = await resposta.json();

                this.drawer = { aberto: true, ...dados };
            },

            /* ---------- detalhe da tarefa ---------- */

            async abrirTarefa(id) {
                const resposta = await fetch(`{{ url('agenda/tarefas') }}/${id}`, {
                    headers: { Accept: 'application/json' },
                });

                this.detalhe = { aberto: true, ...(await resposta.json()) };
                this.drawer.aberto = false;
            },

            /* ---------- arraste: reagendar prazo ---------- */

            arrastar(evento, id, dePrazo) {
                this.arrastando = { id, dePrazo };
                evento.dataTransfer.effectAllowed = 'move';
            },

            async soltarEm(data) {
                if (! this.arrastando || this.arrastando.dePrazo === data) {
                    this.arrastando = null;
                    return;
                }

                const { id, dePrazo } = this.arrastando;
                this.arrastando = null;

                // `de_prazo` viaja junto: é o mesmo contrato de `de_status` do
                // `tarefas.mover`. Quem arrasta diz de ONDE saiu, e a rota
                // recusa se outra pessoa já remarcou — sem isso a última tela a
                // soltar ganha em silêncio, e ninguém sabe que houve disputa.
                const resposta = await this.enviar(`{{ url('agenda/tarefas') }}/${id}/reagendar`, 'POST', {
                    prazo: data,
                    de_prazo: dePrazo,
                });

                // Recarrega em qualquer caso: no acerto, porque mover o prazo
                // muda o rótulo ("sem reunião marcada" aparece ou some), a ordem
                // do dia e o grupo de atrasadas — remendar tudo na mão seria
                // reescrever o `AgendaService` em JavaScript. Na recusa, porque a
                // razão foi flashada na sessão ("Alguém já remarcou..."), e é o
                // reload que a traz para a tela — o arraste não tem modal onde
                // mostrar o erro inline.
                window.location.reload();
            },

            /* ---------- modal de compromisso ---------- */

            modalVazio() {
                return {
                    aberto: false, salvando: false, erro: null, confirmandoExclusao: false,
                    somenteLeitura: false,
                    id: null, titulo: '', descricao: '',
                    data: this.hoje, hora: '09:00',
                    data_fim: this.hoje, hora_fim: '10:00',
                    duracao_modo: true, duracao_horas: 1,
                    tarefa_id: '', participantes: [],
                    vinculoBusca: '', vinculoAberto: false,
                    conflitos: [], carga: {},
                };
            },

            novoCompromisso(data = null) {
                this.drawer.aberto = false;
                this.detalhe.aberto = false;

                this.modal = { ...this.modalVazio(), aberto: true, data: data ?? this.hoje };
                this.modal.data_fim = this.modal.data;

                // Quem marca já entra como participante — é o padrão de agenda
                // de mercado (o organizador é um convidado), e é o que faz o
                // lembrete chegar a quem criou a reunião. Removível no chip, para
                // quem está marcando para outros. Sem isso, marcar a própria
                // reunião não lembrava ninguém de nada.
                if (this.usuarioId) {
                    this.modal.participantes = [this.usuarioId];
                }

                this.recalcular();
            },

            abrirCompromisso(id) {
                const guardado = this.compromissos[id];
                if (! guardado) {
                    return;
                }

                this.drawer.aberto = false;
                this.detalhe.aberto = false;

                this.modal = {
                    ...this.modalVazio(),
                    ...guardado,
                    aberto: true,
                    tarefa_id: guardado.tarefa_id ?? '',
                };

                this.conferirConflitos();
            },

            /**
             * Duplicar: transforma o que está aberto numa CÓPIA nova.
             *
             * `id: null` faz o formulário virar "Novo compromisso", então
             * Salvar cria outro em vez de editar este — mantendo título,
             * detalhes, duração, participantes e vínculo. Nada é salvo aqui: a
             * pessoa ajusta o dia/hora e confirma. Zera as marcas transitórias
             * (leitura, exclusão pendente) para a cópia nascer editável.
             */
            duplicarCompromisso() {
                this.modal = {
                    ...this.modal,
                    id: null,
                    salvando: false,
                    erro: null,
                    confirmandoExclusao: false,
                    somenteLeitura: false,
                    vinculoBusca: '',
                    vinculoAberto: false,
                };

                this.recalcular();
            },

            fecharModal() {
                this.modal.aberto = false;
                this.modal.confirmandoExclusao = false;
            },

            alternarModo() {
                this.modal.duracao_modo = ! this.modal.duracao_modo;

                // Ao voltar para Duração o término é recalculado na hora, senão
                // o campo mostraria o horário livre que acabou de ser abandonado
                // enquanto as horas dizem outra coisa.
                this.recalcular();
            },

            /**
             * Recalcula o término a partir do início e da duração.
             *
             * Só no modo Duração: no modo livre o término é digitado, e
             * recalcular ali apagaria o que a pessoa acabou de escrever. É o
             * contrato do recurso — mudar o início ou as horas SEMPRE move o
             * término no modo Duração.
             */
            recalcular() {
                if (this.modal.duracao_modo) {
                    const horas = Number(this.modal.duracao_horas) || 0;
                    const inicio = new Date(`${this.modal.data}T${this.modal.hora}`);

                    if (! isNaN(inicio) && horas > 0) {
                        const fim = new Date(inicio.getTime() + Math.round(horas * 60) * 60000);

                        this.modal.hora_fim = String(fim.getHours()).padStart(2, '0')
                            + ':' + String(fim.getMinutes()).padStart(2, '0');
                        this.modal.data_fim = fim.getFullYear()
                            + '-' + String(fim.getMonth() + 1).padStart(2, '0')
                            + '-' + String(fim.getDate()).padStart(2, '0');
                    }
                }

                this.conferirConflitos();
            },

            /** "(dia seguinte)" quando o compromisso atravessa a meia-noite. */
            sufixoDoDiaSeguinte() {
                return this.modal.data_fim && this.modal.data_fim !== this.modal.data
                    ? ' (dia seguinte)'
                    : '';
            },

            /* ---------- vincular tarefa (busca) ---------- */

            /**
             * As tarefas que batem com a busca — por # ou por nome.
             *
             * A lista inteira já veio na página, então o filtro é no navegador,
             * sem ida ao servidor: digitar responde na hora. O teto de 50 é só
             * contra desenhar mil linhas de uma vez — ninguém rola até a
             * milésima; quem tem muita tarefa refina a busca.
             */
            tarefasFiltradas() {
                const q = this.modal.vinculoBusca.trim().toLowerCase();
                const casa = q === ''
                    ? this.tarefas
                    : this.tarefas.filter((t) =>
                        ('#' + t.id).includes(q) || t.titulo.toLowerCase().includes(q));

                return casa.slice(0, 50);
            },

            /** "#12 — Corrigir importação", ou '' quando nada está vinculado. */
            rotuloTarefa(id) {
                const t = this.tarefas.find((x) => x.id === Number(id));

                return t ? `#${t.id} — ${t.titulo}` : '';
            },

            escolherTarefa(t) {
                this.modal.tarefa_id = t.id;
                this.modal.vinculoBusca = '';
                this.modal.vinculoAberto = false;
            },

            limparVinculo() {
                this.modal.tarefa_id = '';
                this.modal.vinculoBusca = '';
                this.modal.vinculoAberto = false;
            },

            alternarParticipante(id) {
                this.modal.participantes = this.modal.participantes.includes(id)
                    ? this.modal.participantes.filter((p) => p !== id)
                    : [...this.modal.participantes, id];

                this.conferirConflitos();
            },

            /**
             * Pergunta ao servidor quem tem conflito e quanto cada um já tem no
             * dia.
             *
             * Ao servidor porque a agenda dos outros não está na tela: o
             * navegador só conhece o que a visão atual carregou, e o choque
             * costuma estar justamente no dia que não está aberto.
             */
            async conferirConflitos() {
                if (! this.modal.participantes.length || ! this.modal.data || ! this.modal.hora) {
                    this.modal.conflitos = [];
                    return;
                }

                const url = new URL('{{ route('compromissos.conflitos') }}');
                url.searchParams.set('data', this.modal.data);
                url.searchParams.set('hora', this.modal.hora);
                url.searchParams.set('data_fim', this.modal.data_fim || this.modal.data);
                url.searchParams.set('hora_fim', this.modal.hora_fim || this.modal.hora);
                this.modal.participantes.forEach((p) => url.searchParams.append('participantes[]', p));

                if (this.modal.id) {
                    url.searchParams.set('ignorar', this.modal.id);
                }

                try {
                    const resposta = await fetch(url, { headers: { Accept: 'application/json' } });
                    const dados = await resposta.json();

                    this.modal.conflitos = dados.conflitos ?? [];
                    this.modal.carga = dados.carga ?? {};
                } catch (erro) {
                    // A tela continua utilizável sem o aviso: ele é contexto
                    // para decidir, não trava de salvamento. O servidor não
                    // recusa por conflito — pessoas realmente marcam duas
                    // coisas ao mesmo tempo, e a Agenda mostra em vez de proibir.
                    this.modal.conflitos = [];
                }
            },

            rotuloDoChip(id, nome) {
                if (! this.modal.participantes.includes(id)) {
                    return nome;
                }

                if (this.modal.conflitos.includes(id)) {
                    return `${nome} · conflito`;
                }

                const qtd = this.modal.carga[id] ?? 0;

                return qtd > 0 ? `${nome} · ${qtd} no dia` : nome;
            },

            classeDoChip(id) {
                if (! this.modal.participantes.includes(id)) {
                    return 'border-btn-line text-ink-mute hover:text-ink';
                }

                return this.modal.conflitos.includes(id)
                    ? 'border-warn bg-warn/15 text-warn'
                    : 'border-brand bg-brand/15 text-brand-text';
            },

            async salvar() {
                this.modal.salvando = true;
                this.modal.erro = null;

                const destino = this.modal.id
                    ? `{{ url('compromissos') }}/${this.modal.id}`
                    : '{{ route('compromissos.store') }}';

                const resposta = await this.enviar(destino, this.modal.id ? 'PUT' : 'POST', {
                    titulo: this.modal.titulo,
                    descricao: this.modal.descricao,
                    data: this.modal.data,
                    hora: this.modal.hora,
                    data_fim: this.modal.data_fim,
                    hora_fim: this.modal.hora_fim,
                    duracao_modo: this.modal.duracao_modo,
                    duracao_horas: this.modal.duracao_horas,
                    tarefa_id: this.modal.tarefa_id || null,
                    participantes: this.modal.participantes,
                });

                this.modal.salvando = false;

                if (resposta.ok) {
                    window.location.reload();
                }
            },

            async excluir() {
                const resposta = await this.enviar(
                    `{{ url('compromissos') }}/${this.modal.id}`, 'DELETE', {}
                );

                if (resposta.ok) {
                    window.location.reload();
                }
            },

            async virarTarefa() {
                const resposta = await this.enviar(
                    `{{ url('compromissos') }}/${this.modal.id}/virar-tarefa`, 'POST', {}
                );

                if (resposta.ok) {
                    window.location.reload();
                }
            },

            /** Reservar tempo: abre o modal PRÉ-PREENCHIDO, sem criar nada. */
            async reservarTempo(tarefaId) {
                const resposta = await fetch(`{{ url('agenda/tarefas') }}/${tarefaId}/reservar`, {
                    headers: { Accept: 'application/json' },
                });

                const rascunho = await resposta.json();

                this.detalhe.aberto = false;
                this.modal = {
                    ...this.modalVazio(),
                    ...rascunho,
                    aberto: true,
                    tarefa_id: rascunho.tarefa_id ?? '',
                    data_fim: rascunho.data,
                };

                // A HORA é a única coisa que não vem da tarefa — ninguém a
                // sabe além de quem está marcando. O término sai da duração
                // padrão sobre o horário padrão do modal.
                this.recalcular();
            },

            /**
             * Um `fetch` com CSRF, método e tratamento de erro — num lugar só.
             *
             * As cinco ações de escrita da tela mandam o mesmo cabeçalho e
             * tratam a mesma recusa (422 com `erro`); copiado cinco vezes, o
             * primeiro conserto pegaria quatro.
             */
            async enviar(url, metodo, corpo) {
                try {
                    const resposta = await fetch(url, {
                        method: metodo,
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(corpo),
                    });

                    if (! resposta.ok) {
                        const dados = await resposta.json().catch(() => ({}));
                        this.modal.erro = dados.erro
                            ?? Object.values(dados.errors ?? {}).flat()[0]
                            ?? 'Não foi possível salvar.';
                    }

                    return resposta;
                } catch (erro) {
                    this.modal.erro = 'Sem conexão com o servidor.';

                    return { ok: false };
                }
            },
        }));
    });
</script>
