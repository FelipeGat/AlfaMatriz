@php
    /**
     * Onde o aviso de tarefas parecidas aparece na criação (#205).
     *
     * Busca enquanto se digita, e não no Salvar: o pedido era mostrar ANTES de
     * salvar sem barrar, e interceptar o envio para perguntar "tem certeza?"
     * seria a trava com outro nome. Aqui quem lê o aviso decide sozinho — e
     * quem não lê salva como sempre.
     *
     * Ouve o formulário inteiro, e não cada campo, para não pendurar atributo
     * no título, no resumo e no sistema: são os campos que mais mudam no
     * formulário, e o aviso cabe numa linha de include.
     *
     * Pausa de 400ms depois da última tecla, e só a resposta do pedido mais
     * recente vale: sem o contador, a resposta lenta de "Wel" chegaria depois
     * da de "Wellhub" e pintaria o aviso velho por cima do certo.
     */
@endphp

<div x-data="{
        html: '',
        espera: null,
        pedido: 0,
        buscar() {
            clearTimeout(this.espera)

            this.espera = setTimeout(async () => {
                const form = this.$root.closest('form')
                const titulo = (form.elements['titulo']?.value ?? '').trim()

                // Menos de quatro letras ainda não é um pedido — é o começo
                // de uma palavra, e casaria com o quadro inteiro.
                if (titulo.length < 4) {
                    this.pedido++
                    this.html = ''

                    return
                }

                const parametros = new URLSearchParams({
                    titulo,
                    resumo: form.elements['resumo']?.value ?? '',
                    sistema_id: form.elements['sistema_id']?.value ?? '',
                })
                const este = ++this.pedido

                try {
                    const resposta = await fetch(@js(route('tarefas.parecidas')) + '?' + parametros, {
                        headers: { 'Accept': 'text/html' },
                    })

                    if (este === this.pedido && resposta.ok) {
                        this.html = (await resposta.text()).trim()
                    }
                } catch (erro) {
                    // Sem aviso é o formulário de sempre: a busca é ajuda, e
                    // a falha dela não pode atrapalhar quem está criando.
                }
            }, 400)
        },
     }"
     x-init="
        const form = $root.closest('form')
        form.addEventListener('input', (e) => { if (['titulo', 'resumo'].includes(e.target.name)) buscar() })
        form.addEventListener('change', (e) => { if (e.target.name === 'sistema_id') buscar() })
        form.addEventListener('reset', () => { pedido++; html = '' })
     "
     x-show="html !== ''" x-cloak
     x-html="html"></div>
