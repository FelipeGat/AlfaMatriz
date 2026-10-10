<x-app-layout>
    <x-slot name="titulo">Central de IA</x-slot>
    <x-slot name="contexto">Seu espaço de trabalho</x-slot>

    <section x-data="centralIa({ historico: '{{ route('central-ia.mensagens') }}', enviar: '{{ route('central-ia.enviar') }}', podeEnviar: {{ $podeEnviar ? 'true' : 'false' }} })"
             data-central-ia class="mx-auto max-w-7xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="font-mono text-[11px] uppercase tracking-caps text-ink-faint">Uma conversa, seus canais</p>
                <h2 class="mt-1 font-display text-xl font-semibold text-ink">Sua conversa privada</h2>
                <p class="mt-1 text-sm text-ink-mute">Continue seu trabalho aqui. O histórico acompanha você.</p>
            </div>
            <span role="status" aria-live="polite" class="rounded-badge border border-line bg-panel px-3 py-1 text-xs text-ink-mute" x-text="rotuloConexao"></span>
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-4">
            <div class="min-w-0 overflow-hidden rounded-card border border-line bg-panel xl:col-span-3">
                <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                    <span class="flex items-center gap-2 text-sm font-semibold text-ink"><span class="h-4 w-4 text-brand"><x-nav-icon name="chat" /></span>Conversa central</span>
                    <span class="text-xs text-ink-faint" x-text="telegramVinculado ? 'Telegram vinculado' : 'Telegram não vinculado'"></span>
                </div>

                <div x-show="aviso" x-cloak role="alert" class="border-b border-line px-4 py-3 text-sm text-ink-mute">
                    <p x-text="aviso"></p>
                    <button type="button" @click="reconectar()" x-show="!bloqueado" class="mt-2 font-semibold text-brand">Tentar reconectar</button>
                    <a href="{{ route('login') }}" x-show="bloqueado" class="mt-2 inline-block font-semibold text-brand">Voltar ao acesso do AlfaMatriz</a>
                </div>

                <div x-ref="feed" class="h-96 overflow-y-auto overscroll-contain p-4" role="log" aria-label="Histórico da sua conversa" aria-live="polite" aria-relevant="additions">
                    <div class="mb-4 text-center" x-show="haAnteriores">
                        <button type="button" @click="anteriores()" :disabled="ocupado || bloqueado" class="text-xs font-semibold text-brand disabled:opacity-50">Carregar mensagens anteriores</button>
                    </div>
                    <p x-show="carregando" class="py-6 text-center text-sm text-ink-faint">Carregando seu histórico…</p>
                    <div x-show="!carregando && !mensagens.length && !bloqueado" class="flex h-full flex-col items-center justify-center gap-3 text-center">
                        <span class="h-6 w-6 text-brand"><x-nav-icon name="chat" /></span>
                        <h3 class="font-display text-lg font-semibold text-ink">Seu trabalho começa com uma conversa</h3>
                        <p class="max-w-sm text-sm text-ink-mute">Registre uma ideia, uma decisão ou o próximo passo. Esta conversa pertence à sua conta.</p>
                    </div>
                    <div class="space-y-4">
                        <template x-for="m in mensagens" :key="m.id">
                            <article class="min-w-0 rounded-control border border-line px-4 py-3" :class="m.autor_tipo === 'usuario' ? 'bg-input' : 'bg-canvas'" data-mensagem>
                                <div class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                    <span class="font-semibold text-ink" x-text="autor(m)"></span>
                                    <span class="text-ink-faint" x-text="papel(m)"></span>
                                    <span class="text-ink-faint" x-show="m.origem !== 'agente'" x-text="canal(m.origem)"></span>
                                    <time class="text-ink-faint" :datetime="m.criada_em" x-text="data(m.criada_em)"></time>
                                </div>
                                <p class="whitespace-pre-wrap break-words text-sm leading-relaxed text-ink" x-text="m.conteudo"></p>
                            </article>
                        </template>
                        <article x-show="pendente" x-cloak class="rounded-control border border-line bg-input px-4 py-3" data-pendente>
                            <p class="mb-2 text-xs font-semibold text-ink-mute">Você · aguardando confirmação</p>
                            <p class="whitespace-pre-wrap break-words text-sm text-ink" x-text="pendente?.conteudo"></p>
                            <p class="mt-2 text-xs text-ink-faint">O envio é confirmado pelo histórico. Uma nova tentativa usa a mesma mensagem.</p>
                        </article>
                    </div>
                </div>

                <form @submit.prevent="enviar()" class="space-y-3 border-t border-line bg-panel p-4" aria-label="Enviar mensagem">
                    <label for="central-rascunho" class="sr-only">Sua mensagem</label>
                    <textarea id="central-rascunho" x-model="rascunho" maxlength="{{ config('central_ia.max_texto') }}" rows="3"
                              :readonly="!!pendente" :disabled="bloqueado || !podeEnviar" @keydown.enter="if (!$event.shiftKey && !$event.isComposing && !window.matchMedia('(pointer: coarse)').matches) { $event.preventDefault(); enviar(); }"
                              placeholder="Escreva uma mensagem na sua conversa…"
                              class="w-full resize-y rounded-control border-line bg-input text-sm text-ink placeholder-ink-faint focus:border-brand focus:ring-brand disabled:opacity-50"></textarea>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-xs text-ink-faint" x-text="podeEnviar ? 'Enter envia · Shift + Enter cria uma linha' : 'Sua permissão permite apenas consultar o histórico.'"></p>
                        <button type="submit" :disabled="enviando || bloqueado || !podeEnviar || (!pendente && !rascunho.trim())"
                                class="rounded-control bg-brand px-4 py-2 text-sm font-semibold text-on-brand transition hover:opacity-90 disabled:opacity-50"
                                x-text="enviando ? 'Enviando…' : (pendente ? 'Reenviar com segurança' : 'Enviar mensagem')"></button>
                    </div>
                </form>
            </div>

            <aside class="space-y-4" aria-label="Sobre sua Central">
                <div class="rounded-card border border-line bg-panel p-4">
                    <h3 class="text-sm font-semibold text-ink">Seu contexto, preservado</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-mute">O histórico é só seu. Nenhum outro usuário o vê — nem quem administra o sistema.</p>
                </div>
                <div class="rounded-card border border-line bg-panel p-4">
                    <h3 class="text-sm font-semibold text-ink">Agentes</h3>
                    <p class="mt-2 text-sm leading-relaxed text-ink-mute">Os agentes ainda não estão ligados. Você já pode registrar mensagens e decisões.</p>
                </div>
            </aside>
        </div>
    </section>
</x-app-layout>
