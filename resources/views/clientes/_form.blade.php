@csrf

@php
    $emailsIniciais = old('emails', isset($cliente) ? $cliente->emails->map(fn ($e) => ['email' => $e->email, 'principal' => $e->principal, 'financeiro' => $e->financeiro])->values()->all() : [['email' => '', 'principal' => true, 'financeiro' => true]]);
    $telefonesIniciais = old('telefones', isset($cliente) ? $cliente->telefones->map(fn ($t) => ['telefone' => $t->telefone, 'principal' => $t->principal])->values()->all() : [['telefone' => '', 'principal' => true]]);
@endphp

<div x-data="{
        tipoPessoa: '{{ old('tipo_pessoa', $cliente->tipo_pessoa ?? 'PJ') }}',
        tipoCliente: '{{ old('tipo_cliente', $cliente->tipo_cliente ?? 'AVULSO') }}',
        emails: {{ Illuminate\Support\Js::from($emailsIniciais) }},
        telefones: {{ Illuminate\Support\Js::from($telefonesIniciais) }},
        buscandoCnpj: false,
        erroCnpj: '',
        buscandoCep: false,
        erroCep: '',
        buscarCnpj() {
            const digits = this.$refs.cpfCnpj.value.replace(/\D/g, '');
            if (digits.length !== 14) {
                this.erroCnpj = 'Digite um CNPJ válido (14 dígitos) para buscar.';
                return;
            }
            this.buscandoCnpj = true;
            this.erroCnpj = '';
            fetch(`https://brasilapi.com.br/api/cnpj/v1/${digits}`)
                .then(r => { if (!r.ok) throw new Error('not found'); return r.json(); })
                .then(d => {
                    this.$refs.razaoSocial.value = d.razao_social || '';
                    this.$refs.nomeFantasia.value = d.nome_fantasia || '';
                    if (d.cep) this.$refs.cep.value = d.cep;
                    this.$refs.logradouro.value = d.logradouro || '';
                    this.$refs.numero.value = d.numero || '';
                    this.$refs.bairro.value = d.bairro || '';
                    this.$refs.cidade.value = d.municipio || '';
                    this.$refs.uf.value = d.uf || '';
                })
                .catch(() => { this.erroCnpj = 'CNPJ não encontrado na Receita Federal.'; })
                .finally(() => { this.buscandoCnpj = false; });
        },
        buscarCep() {
            const digits = this.$refs.cep.value.replace(/\D/g, '');
            if (digits.length !== 8) {
                this.erroCep = 'Digite um CEP válido (8 dígitos) para buscar.';
                return;
            }
            this.buscandoCep = true;
            this.erroCep = '';
            fetch(`https://viacep.com.br/ws/${digits}/json/`)
                .then(r => r.json())
                .then(d => {
                    if (d.erro) { this.erroCep = 'CEP não encontrado.'; return; }
                    this.$refs.logradouro.value = d.logradouro || '';
                    this.$refs.bairro.value = d.bairro || '';
                    this.$refs.cidade.value = d.localidade || '';
                    this.$refs.uf.value = d.uf || '';
                })
                .catch(() => { this.erroCep = 'Erro ao consultar o CEP.'; })
                .finally(() => { this.buscandoCep = false; });
        }
     }">

    {{-- Dados básicos --}}
    <div class="rounded-panel border border-line bg-subtle p-4 mb-4">
        <h3 class="font-mono text-[10.5px] font-semibold uppercase tracking-caps-wide text-ink-faint mb-3">Dados básicos</h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <x-input-label for="revenda_id" value="Revenda" />
                @if (auth()->user()->temEscopoDeRevenda())
                    {{-- Uma revenda só cadastra para si: nada a escolher. O
                         controller ignora este campo e usa o escopo do usuário. --}}
                    <div class="mt-1 h-[38px] px-3 flex items-center rounded-md border border-line bg-subtle text-[13px] text-ink-dim">
                        {{ auth()->user()->revenda?->nome }}
                    </div>
                @else
                <select id="revenda_id" name="revenda_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm" required>
                    <option value="" disabled {{ old('revenda_id', $cliente->revenda_id ?? '') === '' ? 'selected' : '' }}>Selecione a revenda</option>
                    @foreach ($revendas as $revenda)
                        <option value="{{ $revenda->id }}" {{ (string) old('revenda_id', $cliente->revenda_id ?? '') === (string) $revenda->id ? 'selected' : '' }}>
                            {{ $revenda->nome }}
                        </option>
                    @endforeach
                </select>
                @endif
            </div>

            <div>
                <x-input-label for="tipo_pessoa" value="Tipo de pessoa" />
                <select id="tipo_pessoa" name="tipo_pessoa" x-model="tipoPessoa" class="mt-1 block w-full border-white/10 rounded-md shadow-sm" required>
                    <option value="PJ">Pessoa Jurídica</option>
                    <option value="PF">Pessoa Física</option>
                </select>
            </div>

            <div>
                <x-input-label for="cpf_cnpj" value="CPF/CNPJ" />
                <div class="mt-1 flex gap-2">
                    <x-text-input x-ref="cpfCnpj" id="cpf_cnpj" name="cpf_cnpj" type="text" class="block w-full" value="{{ old('cpf_cnpj', $cliente->cpf_cnpj ?? '') }}" />
                    <button type="button" x-show="tipoPessoa === 'PJ'" @click="buscarCnpj()" :disabled="buscandoCnpj"
                        class="shrink-0 inline-flex items-center px-3 rounded-md border border-white/10 bg-panel-raised text-ink-dim hover:text-brand-dim hover:border-brand/30 text-xs disabled:opacity-50"
                        title="Buscar dados na Receita Federal">
                        <span x-show="!buscandoCnpj">Buscar CNPJ</span>
                        <span x-show="buscandoCnpj">Buscando...</span>
                    </button>
                </div>
                <p class="mt-1 text-xs text-status-critical" x-show="erroCnpj" x-text="erroCnpj"></p>
                <x-input-error :messages="$errors->get('cpf_cnpj')" class="mt-2" />
            </div>

            <div x-show="tipoPessoa === 'PF'">
                <x-input-label for="nome" value="Nome completo" />
                <x-text-input id="nome" name="nome" type="text" class="mt-1 block w-full" value="{{ old('nome', ($cliente->tipo_pessoa ?? '') === 'PF' ? $cliente->nome ?? '' : '') }}" />
                <x-input-error :messages="$errors->get('nome')" class="mt-2" />
            </div>

            <div x-show="tipoPessoa === 'PJ'">
                <x-input-label for="razao_social" value="Razão social" />
                <x-text-input x-ref="razaoSocial" id="razao_social" name="razao_social" type="text" class="mt-1 block w-full" value="{{ old('razao_social', $cliente->razao_social ?? '') }}" />
                <x-input-error :messages="$errors->get('razao_social')" class="mt-2" />
            </div>

            <div x-show="tipoPessoa === 'PJ'">
                <x-input-label for="nome_fantasia" value="Nome fantasia" />
                <x-text-input x-ref="nomeFantasia" id="nome_fantasia" name="nome_fantasia" type="text" class="mt-1 block w-full" value="{{ old('nome_fantasia', $cliente->nome_fantasia ?? '') }}" />
            </div>

            <div>
                <x-input-label for="data_cadastro" value="Data de cadastro" />
                <x-text-input id="data_cadastro" name="data_cadastro" type="date" class="mt-1 block w-full" value="{{ old('data_cadastro', isset($cliente) ? $cliente->data_cadastro?->toDateString() : now()->toDateString()) }}" />
            </div>

            <div class="flex items-center mt-6">
                <label class="inline-flex items-center">
                    <input type="checkbox" name="ativo" value="1" class="rounded border-white/20 text-brand shadow-sm" {{ old('ativo', $cliente->ativo ?? true) ? 'checked' : '' }}>
                    <span class="ms-2 text-sm text-ink-dim">Cliente ativo</span>
                </label>
            </div>
        </div>
    </div>

    {{-- Contatos --}}
    <div class="rounded-panel border border-line bg-subtle p-4 mb-4">
        <h3 class="font-mono text-[10.5px] font-semibold uppercase tracking-caps-wide text-ink-faint mb-3">Contatos</h3>

        <div class="mb-4">
            <x-input-label value="E-mails" />
            <template x-for="(item, i) in emails" :key="i">
                <div class="flex items-center gap-3 mt-2">
                    <input type="email" :name="`emails[${i}][email]`" x-model="item.email" placeholder="email@exemplo.com" class="flex-1 border-white/10 rounded-md shadow-sm text-sm bg-panel-raised text-ink">
                    <label class="inline-flex items-center text-xs text-ink-mute gap-1">
                        <input type="radio" :name="'emails_principal'" :checked="item.principal" @change="emails.forEach((e,idx) => e.principal = idx === i)" class="text-brand">
                        <input type="hidden" :name="`emails[${i}][principal]`" :value="item.principal ? 1 : 0">
                        Principal
                    </label>
                    <label class="inline-flex items-center text-xs text-ink-mute gap-1">
                        <input type="checkbox" :name="`emails[${i}][financeiro]`" x-model="item.financeiro" value="1" class="rounded text-brand">
                        Financeiro
                    </label>
                    <button type="button" @click="emails.splice(i, 1)" class="text-status-critical text-xs">Remover</button>
                </div>
            </template>
            <button type="button" @click="emails.push({email: '', principal: emails.length === 0, financeiro: false})" class="mt-2 text-xs text-brand hover:text-brand-bright">+ Adicionar e-mail</button>
        </div>

        <div>
            <x-input-label value="Telefones" />
            <template x-for="(item, i) in telefones" :key="i">
                <div class="flex items-center gap-3 mt-2">
                    <input type="text" :name="`telefones[${i}][telefone]`" x-model="item.telefone" placeholder="(27) 99999-9999" class="flex-1 border-white/10 rounded-md shadow-sm text-sm bg-panel-raised text-ink">
                    <label class="inline-flex items-center text-xs text-ink-mute gap-1">
                        <input type="radio" :name="'telefones_principal'" :checked="item.principal" @change="telefones.forEach((t,idx) => t.principal = idx === i)" class="text-brand">
                        <input type="hidden" :name="`telefones[${i}][principal]`" :value="item.principal ? 1 : 0">
                        Principal
                    </label>
                    <button type="button" @click="telefones.splice(i, 1)" class="text-status-critical text-xs">Remover</button>
                </div>
            </template>
            <button type="button" @click="telefones.push({telefone: '', principal: telefones.length === 0})" class="mt-2 text-xs text-brand hover:text-brand-bright">+ Adicionar telefone</button>
        </div>
    </div>

    {{-- Endereço --}}
    <div class="rounded-panel border border-line bg-subtle p-4 mb-4">
        <h3 class="font-mono text-[10.5px] font-semibold uppercase tracking-caps-wide text-ink-faint mb-3">Endereço</h3>
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div>
                <x-input-label for="cep" value="CEP" />
                <div class="mt-1 flex gap-2">
                    <x-text-input x-ref="cep" id="cep" name="cep" type="text" class="block w-full" value="{{ old('cep', $cliente->cep ?? '') }}" />
                    <button type="button" @click="buscarCep()" :disabled="buscandoCep"
                        class="shrink-0 inline-flex items-center px-3 rounded-md border border-white/10 bg-panel-raised text-ink-dim hover:text-brand-dim hover:border-brand/30 text-xs disabled:opacity-50"
                        title="Buscar endereço pelo CEP">
                        <span x-show="!buscandoCep">Buscar CEP</span>
                        <span x-show="buscandoCep">Buscando...</span>
                    </button>
                </div>
                <p class="mt-1 text-xs text-status-critical" x-show="erroCep" x-text="erroCep"></p>
            </div>
            <div class="sm:col-span-2">
                <x-input-label for="logradouro" value="Logradouro" />
                <x-text-input x-ref="logradouro" id="logradouro" name="logradouro" type="text" class="mt-1 block w-full" value="{{ old('logradouro', $cliente->logradouro ?? '') }}" />
            </div>
            <div>
                <x-input-label for="numero" value="Número" />
                <x-text-input id="numero" name="numero" type="text" class="mt-1 block w-full" value="{{ old('numero', $cliente->numero ?? '') }}" />
            </div>
            <div>
                <x-input-label for="bairro" value="Bairro" />
                <x-text-input x-ref="bairro" id="bairro" name="bairro" type="text" class="mt-1 block w-full" value="{{ old('bairro', $cliente->bairro ?? '') }}" />
            </div>
            <div>
                <x-input-label for="cidade" value="Cidade" />
                <x-text-input x-ref="cidade" id="cidade" name="cidade" type="text" class="mt-1 block w-full" value="{{ old('cidade', $cliente->cidade ?? '') }}" />
            </div>
            <div>
                <x-input-label for="uf" value="UF" />
                <input x-ref="uf" id="uf" name="uf" type="text" maxlength="2" class="mt-1 block w-full uppercase border-white/10 rounded-md shadow-sm bg-panel-raised text-ink" value="{{ old('uf', $cliente->uf ?? '') }}">
            </div>
            <div>
                <x-input-label for="complemento" value="Complemento" />
                <x-text-input id="complemento" name="complemento" type="text" class="mt-1 block w-full" value="{{ old('complemento', $cliente->complemento ?? '') }}" />
            </div>
        </div>
    </div>

    {{-- Informações de contrato: o comercial é da Alfa, definido na liberação
         da licença. Para a revenda o cliente nasce avulso. --}}
    @unless (auth()->user()->temEscopoDeRevenda())
    <div class="rounded-panel border border-line bg-subtle p-4 mb-4">
        <h3 class="font-mono text-[10.5px] font-semibold uppercase tracking-caps-wide text-ink-faint mb-3">Informações de contrato</h3>
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div>
                <x-input-label for="tipo_cliente" value="Tipo de cliente" />
                <select id="tipo_cliente" name="tipo_cliente" x-model="tipoCliente" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                    <option value="AVULSO">Avulso</option>
                    <option value="CONTRATO">Contrato mensal</option>
                </select>
            </div>

            <template x-if="tipoCliente === 'CONTRATO'">
                <div>
                    <x-input-label for="valor_mensal" value="Valor mensal (R$)" />
                    <x-text-input id="valor_mensal" name="valor_mensal" type="number" step="0.01" min="0" class="mt-1 block w-full" value="{{ old('valor_mensal', $cliente->valor_mensal ?? '') }}" />
                    <x-input-error :messages="$errors->get('valor_mensal')" class="mt-2" />
                </div>
            </template>

            <template x-if="tipoCliente === 'CONTRATO'">
                <div>
                    <x-input-label for="dia_vencimento" value="Dia do vencimento" />
                    <x-text-input id="dia_vencimento" name="dia_vencimento" type="number" min="1" max="28" class="mt-1 block w-full" value="{{ old('dia_vencimento', $cliente->dia_vencimento ?? '') }}" />
                    <x-input-error :messages="$errors->get('dia_vencimento')" class="mt-2" />
                </div>
            </template>

            <div>
                <x-input-label for="forma_pagamento_recebimento" value="Forma de recebimento" />
                <select id="forma_pagamento_recebimento" name="forma_pagamento_recebimento" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                    <option value="faturado" {{ old('forma_pagamento_recebimento', $cliente->forma_pagamento_recebimento ?? 'faturado') === 'faturado' ? 'selected' : '' }}>Faturado (PIX/transferência)</option>
                    <option value="boleto" {{ old('forma_pagamento_recebimento', $cliente->forma_pagamento_recebimento ?? '') === 'boleto' ? 'selected' : '' }}>Boleto</option>
                </select>
            </div>

            <div class="flex items-center mt-6">
                <label class="inline-flex items-center">
                    <input type="checkbox" name="nota_fiscal" value="1" class="rounded border-white/20 text-brand shadow-sm" {{ old('nota_fiscal', $cliente->nota_fiscal ?? false) ? 'checked' : '' }}>
                    <span class="ms-2 text-sm text-ink-dim">Emite nota fiscal</span>
                </label>
            </div>
        </div>
    </div>
    @endunless

    {{-- Inscrições --}}
    <div class="rounded-panel border border-line bg-subtle p-4 mb-4">
        <h3 class="font-mono text-[10.5px] font-semibold uppercase tracking-caps-wide text-ink-faint mb-3">Inscrições</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <x-input-label for="inscricao_estadual" value="Inscrição estadual" />
                <x-text-input id="inscricao_estadual" name="inscricao_estadual" type="text" class="mt-1 block w-full" value="{{ old('inscricao_estadual', $cliente->inscricao_estadual ?? '') }}" />
            </div>
            <div>
                <x-input-label for="inscricao_municipal" value="Inscrição municipal" />
                <x-text-input id="inscricao_municipal" name="inscricao_municipal" type="text" class="mt-1 block w-full" value="{{ old('inscricao_municipal', $cliente->inscricao_municipal ?? '') }}" />
            </div>
        </div>
    </div>

    {{-- Sistemas --}}
    <div class="rounded-panel border border-line bg-subtle p-4 mb-4">
        <h3 class="font-mono text-[10.5px] font-semibold uppercase tracking-caps-wide text-ink-faint mb-3">Sistemas utilizados</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
            @php
                $sistemasAtivosIds = old('sistemas', ($cliente->sistemas ?? collect())->pluck('id')->all());
            @endphp
            @foreach ($sistemas as $sistema)
                <label class="inline-flex items-center">
                    <input type="checkbox" name="sistemas[]" value="{{ $sistema->id }}" class="rounded border-white/20 text-brand shadow-sm"
                        {{ in_array($sistema->id, $sistemasAtivosIds) ? 'checked' : '' }}>
                    <span class="ms-2 text-sm text-ink-dim">{{ $sistema->nome }}</span>
                </label>
            @endforeach
        </div>
    </div>

    {{-- Usuário administrador: alguns sistemas exigem uma conta para o cliente
         poder entrar lá; outros (o AlfaControl) criam o usuário depois, no
         próprio painel. Quem decide é a capacidade, não o nome do produto.

         Só no cadastro — reeditar não recria a conta dele. --}}
    @if (($modo ?? 'criar') === 'criar')
        @foreach ($sistemas->filter(fn ($s) => $s->suporta('exige_admin_no_cliente')) as $sistemaAdmin)
            <div class="rounded-panel border border-line bg-subtle p-4 mb-4"
                 x-data="{ marcado: {{ in_array($sistemaAdmin->id, $sistemasAtivosIds) ? 'true' : 'false' }} }"
                 x-init="$el.closest('form').addEventListener('change', e => {
                     if (e.target.name === 'sistemas[]' && e.target.value === '{{ $sistemaAdmin->id }}') marcado = e.target.checked
                 })"
                 x-show="marcado" x-cloak>
                <h3 class="font-mono text-[10.5px] font-semibold uppercase tracking-caps-wide text-ink-faint mb-1">
                    Usuário administrador no {{ $sistemaAdmin->nome }}
                </h3>
                <p class="text-[12.5px] text-ink-faint mb-3">
                    Com estes dados o cliente entra no {{ $sistemaAdmin->nome }}. Ele nasce aguardando
                    a liberação da licença pela Alfa.
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <x-input-label for="nome_admin_{{ $sistemaAdmin->id }}" value="Nome" />
                        <x-text-input id="nome_admin_{{ $sistemaAdmin->id }}" name="admins[{{ $sistemaAdmin->id }}][nome]"
                                      type="text" class="mt-1 block w-full"
                                      value="{{ old('admins.'.$sistemaAdmin->id.'.nome') }}" autocomplete="off" />
                        <x-input-error :messages="$errors->get('admins.'.$sistemaAdmin->id.'.nome')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="email_admin_{{ $sistemaAdmin->id }}" value="E-mail" />
                        <x-text-input id="email_admin_{{ $sistemaAdmin->id }}" name="admins[{{ $sistemaAdmin->id }}][email]"
                                      type="email" class="mt-1 block w-full"
                                      value="{{ old('admins.'.$sistemaAdmin->id.'.email') }}" autocomplete="off" />
                        <x-input-error :messages="$errors->get('admins.'.$sistemaAdmin->id.'.email')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="senha_admin_{{ $sistemaAdmin->id }}" value="Senha" />
                        <x-text-input id="senha_admin_{{ $sistemaAdmin->id }}" name="admins[{{ $sistemaAdmin->id }}][senha]"
                                      type="password" class="mt-1 block w-full"
                                      minlength="8" autocomplete="new-password" />
                        <x-input-error :messages="$errors->get('admins.'.$sistemaAdmin->id.'.senha')" class="mt-2" />
                    </div>
                </div>
                <x-input-error :messages="$errors->get('integracao')" class="mt-3" />
            </div>
        @endforeach
    @endif

    {{-- Observações --}}
    <div class="rounded-panel border border-line bg-subtle p-4 mb-4">
        <h3 class="font-mono text-[10.5px] font-semibold uppercase tracking-caps-wide text-ink-faint mb-3">Observações</h3>
        <textarea id="observacoes" name="observacoes" rows="3" class="block w-full">{{ old('observacoes', $cliente->observacoes ?? '') }}</textarea>
    </div>

    <div class="sticky bottom-0 -mx-5 -mb-5 mt-4 flex items-center justify-end gap-2 border-t border-line bg-panel px-5 py-3">
        @if ($emModal ?? false)
            <button type="button" x-on:click="$dispatch('close')"
                    class="h-9 px-3.5 rounded-control border border-btn-line
                           text-[12.5px] font-semibold text-ink-dim hover:text-brand hover:border-brand transition">
                Cancelar
            </button>
        @else
            <a href="{{ route('clientes.index') }}"
               class="h-9 px-3.5 inline-flex items-center rounded-control border border-btn-line
                      text-[12.5px] font-semibold text-ink-dim hover:text-brand hover:border-brand transition">
                Cancelar
            </a>
        @endif
        <button type="submit"
                class="h-9 px-3.5 inline-flex items-center rounded-control bg-brand text-on-brand
                       text-[12.5px] font-semibold hover:bg-brand-bright transition">
            Salvar cliente
        </button>
    </div>
</div>
