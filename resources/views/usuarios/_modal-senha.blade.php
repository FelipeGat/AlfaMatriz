{{-- A senha gerada, mostrada UMA vez.

    Ela vive só no flash da sessão: não é gravada em claro em lugar nenhum e
    não reaparece se a página for recarregada. Perdida antes de ser repassada,
    o caminho é gerar outra — que é justamente o que o botão de nova senha da
    tabela faz. Melhor isso do que um lugar no sistema onde senhas em claro
    ficam esperando alguém abrir. --}}

@if (session('senha_gerada'))
    @php $gerada = session('senha_gerada'); @endphp

    <x-modal name="senha-gerada" maxWidth="md" :show="true">
        <div class="p-5 flex flex-col gap-4" x-data="{ copiado: false }">
            <div>
                <h2 class="font-display text-[15.5px] font-semibold text-ink">Senha de {{ $gerada['nome'] }}</h2>
                <p class="text-[12.5px] text-ink-mute mt-0.5">
                    Anote ou copie agora: ela não será mostrada de novo.
                </p>
            </div>

            <div class="flex flex-col gap-1.5">
                <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">E-mail</span>
                <p class="font-mono text-[13px] text-ink-dim break-all">{{ $gerada['email'] }}</p>
            </div>

            <div class="flex flex-col gap-1.5">
                <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Senha</span>
                <div class="flex items-center gap-2">
                    {{-- `readonly`, e não `disabled`: desabilitado não deixa
                         selecionar com o mouse, que é como metade das pessoas
                         copia. --}}
                    <input type="text" readonly value="{{ $gerada['senha'] }}"
                           x-ref="senha" @focus="$event.target.select()"
                           class="flex-1 min-w-0 h-10 px-3 rounded-control bg-input border border-line font-mono text-[14px] text-ink tracking-wide">
                    <button type="button"
                            @click="navigator.clipboard?.writeText($refs.senha.value).then(() => copiado = true).catch(() => $refs.senha.select())"
                            class="h-10 px-3.5 shrink-0 rounded-control border border-btn-line text-[12.5px] font-semibold text-ink-dim hover:text-brand hover:border-brand transition">
                        <span x-text="copiado ? 'Copiado' : 'Copiar'">Copiar</span>
                    </button>
                </div>
            </div>

            <p class="text-[12.5px] text-ink-mute">
                No primeiro acesso o sistema pede que {{ Str::of($gerada['nome'])->before(' ') }} escolha
                a própria senha — esta aqui vale só até lá.
            </p>

            <div class="flex justify-end">
                <button type="button" x-on:click="$dispatch('close')"
                        class="h-9 px-3.5 rounded-control bg-brand text-on-brand text-[12.5px] font-semibold hover:bg-brand-bright transition">
                    Já anotei
                </button>
            </div>
        </div>
    </x-modal>
@endif
