{{-- Modais de licença, um por (cliente, sistema).

     O mesmo cliente pode ter licença em mais de um sistema, então o modal
     precisa saber de qual está falando: o identificador carrega o sistema, e o
     formulário posta para a rota daquele sistema. Antes eram um por cliente, e
     a rota era montada com a variável do laço da tabela — o que derrubava a
     tela para cliente licenciado só num sistema de leitura.

     Só existem para quem decide sobre licença: renderizar para a revenda
     deixaria os formulários de liberar/renovar na página dela. --}}
@unless (auth()->user()->temEscopoDeRevenda())
@foreach ($clientes as $cliente)
    @foreach ($cliente->sistemas->filter(fn ($s) => $s->suporta('gerencia_licenca')) as $sistemaLicencaModal)
    @php
        $vinculoModal = $sistemaLicencaModal->pivot;
        $pendenteModal = $vinculoModal->pendente();
        $temLicencaModal = $vinculoModal->temLicenca();
    @endphp

    @if ($pendenteModal)
        <x-modal name="liberar-licenca-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" maxWidth="sm">
            <form method="POST" action="{{ route('clientes.liberarLicenca', [$cliente, $sistemaLicencaModal]) }}" class="p-5">
                @csrf
                <h2 class="font-display text-[15.5px] font-semibold text-ink mb-1">Liberar licença</h2>
                <p class="text-[12.5px] text-ink-faint mb-4">
                    {{ $cliente->nome_exibicao }} — a revenda solicitou; a liberação é feita no {{ $sistemaLicencaModal->nome }}.
                </p>

                @if ($errors->has('licenca'))
                    <div class="mb-3 rounded-md border border-crit/30 bg-crit-tint px-3 py-2 text-[12.5px] text-crit">
                        {{ $errors->first('licenca') }}
                    </div>
                @endif

                <div class="space-y-4">
                    <div>
                        <x-input-label for="tipo-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" value="Tipo de licença" />
                        <select id="tipo-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" name="tipo" class="mt-1 block w-full border-white/10 rounded-md shadow-sm" required>
                            <option value="mensal" @selected(old('tipo') === 'mensal')>Mensal</option>
                            <option value="anual" @selected(old('tipo') === 'anual')>Anual</option>
                        </select>
                        <x-input-error :messages="$errors->get('tipo')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="valor-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" value="Valor (R$)" />
                        <x-text-input id="valor-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" name="valor" type="number" step="0.01" min="0"
                                      :value="old('valor', '')" class="mt-1 block w-full" placeholder="0,00" />
                        <x-input-error :messages="$errors->get('valor')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="obs-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" value="Observação" />
                        <textarea id="obs-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" name="obs" rows="2"
                                  class="mt-1 block w-full rounded-md border-white/10 bg-white/5 text-[13px] text-ink"
                                  placeholder="Contrato, proposta…">{{ old('obs') }}</textarea>
                        <x-input-error :messages="$errors->get('obs')" class="mt-2" />
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" x-on:click="show = false"
                            class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="h-[34px] px-3 inline-flex items-center rounded-control bg-brand text-on-brand font-semibold text-[12.5px] hover:bg-brand-bright transition">
                        Liberar licença
                    </button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($temLicencaModal)
        <x-modal name="renovar-licenca-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" maxWidth="sm">
            <form method="POST" action="{{ route('clientes.renovarLicenca', [$cliente, $sistemaLicencaModal]) }}" class="p-5">
                @csrf
                <h2 class="font-display text-[15.5px] font-semibold text-ink mb-1">Renovar licença</h2>
                <p class="text-[12.5px] text-ink-faint mb-4">
                    {{ $cliente->nome_exibicao }} — um novo período (mensal/anual) é emitido no {{ $sistemaLicencaModal->nome }}.
                </p>

                @if ($errors->has('licenca'))
                    <div class="mb-3 rounded-md border border-crit/30 bg-crit-tint px-3 py-2 text-[12.5px] text-crit">
                        {{ $errors->first('licenca') }}
                    </div>
                @endif

                <div class="space-y-4">
                    <div>
                        <x-input-label for="tipo-ren-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" value="Tipo de renovação" />
                        <select id="tipo-ren-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" name="tipo" class="mt-1 block w-full border-white/10 rounded-md shadow-sm" required>
                            <option value="mensal" @selected(old('tipo') === 'mensal')>Mensal</option>
                            <option value="anual" @selected(old('tipo') === 'anual')>Anual</option>
                        </select>
                        <x-input-error :messages="$errors->get('tipo')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="valor-ren-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" value="Valor (R$)" />
                        <x-text-input id="valor-ren-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" name="valor" type="number" step="0.01" min="0"
                                      :value="old('valor', '')" class="mt-1 block w-full" placeholder="0,00" />
                        <x-input-error :messages="$errors->get('valor')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="obs-ren-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" value="Observação" />
                        <textarea id="obs-ren-{{ $cliente->id }}-{{ $sistemaLicencaModal->id }}" name="obs" rows="2"
                                  class="mt-1 block w-full rounded-md border-white/10 bg-white/5 text-[13px] text-ink"
                                  placeholder="Renovação de contrato…">{{ old('obs') }}</textarea>
                        <x-input-error :messages="$errors->get('obs')" class="mt-2" />
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" x-on:click="show = false"
                            class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="h-[34px] px-3 inline-flex items-center rounded-control bg-brand text-on-brand font-semibold text-[12.5px] hover:bg-brand-bright transition">
                        Renovar licença
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
    @endforeach
@endforeach
@endunless
