<script>
    document.addEventListener('alpine:init', () => {
        // A hora e o "carregando" moram num store porque o botão fica no
        // cabeçalho do layout, longe do miolo que ele atualiza.
        Alpine.store('monitor', {
            hora: document.getElementById('monitor-conteudo')?.dataset.hora ?? '',
            carregando: false,
            // Clientes do Acesso que a pessoa abriu ou recolheu (#402). Moram
            // aqui, e não no x-data do cartão, porque a atualização de 30 s
            // troca o miolo inteiro: no cartão, o que ela abriu fecharia
            // sozinho meio minuto depois.
            abertos: {},
        });

        /**
         * Atualizar as telas do Monitor sem recarregar a página (#394).
         *
         * O mesmo desenho da Manutenção (#235): busca a MESMA URL, com os
         * filtros, e troca só o miolo — o que aparece é exatamente o que um F5
         * mostraria. A diferença é o ritmo: 30 s, e não 5 min, porque aqui o
         * dado é o retrato do monitor, que muda a cada 5 s, e o cache do Matriz
         * segura a consulta (dez abas abertas não viram dez pedidos ao
         * monitor). Pausa com a aba do navegador escondida, como o sino.
         */
        Alpine.data('monitorTela', ({ automatico }) => ({
            INTERVALO: 30 * 1000,
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

            async atualizar({ automatico = false } = {}) {
                const store = Alpine.store('monitor');

                if (store.carregando) {
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
                    const novo = pagina.getElementById('monitor-conteudo');

                    if (! novo) {
                        return;
                    }

                    // Pelo id, e não `this.$el`: chamado do `setInterval`, o
                    // `$el` não é garantidamente a raiz do componente.
                    document.getElementById('monitor-conteudo').innerHTML = novo.innerHTML;
                    store.hora = novo.dataset.hora;

                    const contexto = pagina.getElementById('monitor-contexto');
                    const atual = document.getElementById('monitor-contexto');
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
