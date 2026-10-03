<script>
    document.addEventListener('alpine:init', () => {
        // A hora e o "carregando" moram num store porque o botão fica no
        // cabeçalho do layout, longe do miolo que ele atualiza.
        Alpine.store('manutencao', {
            hora: document.getElementById('manutencao-conteudo')?.dataset.geradoEm ?? '',
            carregando: false,
        });

        /**
         * Atualizar a tela de Manutenção sem recarregar a página (#235).
         *
         * Busca a MESMA URL (com filtros e aba) e troca só o miolo e a linha de
         * contexto: a tela continua montada num lugar só, no Blade, e o que a
         * atualização mostra é exatamente o que um F5 mostraria. O Alpine liga
         * sozinho os componentes do HTML novo (confirmar, abrir changelog).
         *
         * Automático só na aba Erros, a cada 5 minutos — é a que muda sozinha,
         * de hora em hora, com o lote do vigia. Pausa com a aba do navegador
         * escondida, como o sino (`app.js`), e ao voltar atualiza na hora se o
         * dado já passou do prazo, em vez de esperar o próximo tique.
         */
        Alpine.data('manutencaoTela', ({ automatico }) => ({
            INTERVALO: 5 * 60 * 1000,
            ultimaVez: Date.now(),

            init() {
                if (! automatico) {
                    return;
                }

                setInterval(() => {
                    if (document.visibilityState === 'visible') {
                        this.atualizar({ automatico: true });
                    }
                }, this.INTERVALO);

                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible' && Date.now() - this.ultimaVez >= this.INTERVALO) {
                        this.atualizar({ automatico: true });
                    }
                });
            },

            /**
             * Diálogo de confirmação aberto (Ignorar, Voltar a vigiar) = alguém
             * no meio de uma decisão. A troca automática o fecharia na cara da
             * pessoa; ela espera o próximo tique. O clique no botão vale sempre.
             */
            temDialogoAberto() {
                return [...document.querySelectorAll('[role="dialog"][aria-modal="true"]')]
                    // `display`, e não `offsetParent`: o diálogo é `fixed`, e
                    // elemento fixo tem `offsetParent` nulo mesmo visível.
                    .some((dialogo) => getComputedStyle(dialogo).display !== 'none');
            },

            async atualizar({ automatico = false } = {}) {
                const store = Alpine.store('manutencao');

                if (store.carregando || (automatico && this.temDialogoAberto())) {
                    return;
                }

                store.carregando = true;

                try {
                    const resposta = await fetch(window.location.href, {
                        headers: { Accept: 'text/html' },
                        credentials: 'same-origin',
                    });

                    // Sessão vencida volta como redirecionamento ao login: aí
                    // o certo é a página inteira ir para lá, não o miolo.
                    if (resposta.redirected || ! resposta.ok) {
                        if (! automatico) {
                            window.location.reload();
                        }

                        return;
                    }

                    const pagina = new DOMParser().parseFromString(await resposta.text(), 'text/html');
                    const novo = pagina.getElementById('manutencao-conteudo');

                    if (! novo) {
                        return;
                    }

                    // Pelo id, e não `this.$el`: chamado do `setInterval`, o `$el` não
                    // é garantidamente a raiz do componente.
                    document.getElementById('manutencao-conteudo').innerHTML = novo.innerHTML;
                    store.hora = novo.dataset.geradoEm;

                    const contexto = pagina.getElementById('manutencao-contexto');
                    const atual = document.getElementById('manutencao-contexto');
                    if (contexto && atual) {
                        atual.innerHTML = contexto.innerHTML;
                    }

                    this.ultimaVez = Date.now();
                } catch (erro) {
                    // Sem rede: fica o que estava, e a hora diz de quando é.
                } finally {
                    store.carregando = false;
                }
            },
        }));
    });
</script>
