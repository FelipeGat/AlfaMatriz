<x-app-layout>
    <x-slot name="titulo">Cobrança de revendas</x-slot>
    <x-slot name="contexto"><span>{{ $revenda->nome }} · boleto no dia 01, vencimento no dia {{ $revenda->dia_vencimento ?? '—' }}</span></x-slot>

    {{-- docs/specs/cobranca-revendas-boleto: o fechamento do último dia gera a
         cobrança; o boleto sai às 08:00 do dia 01 e a baixa vem da consulta ao
         Mercado Pago, de hora em hora. Aqui: o cadastro que o boleto pede, o
         preço por cliente, quem é faturável, a prévia e as cobranças. --}}
    @php
        $campo = 'w-full rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none';
        $botao = 'inline-flex h-[28px] items-center gap-1 whitespace-nowrap rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
        $rs = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
        $mp = ['approved' => ['bom', 'Pago'], 'pending' => ['atencao', 'Aguardando pagamento'], 'in_process' => ['atencao', 'Em processamento'],
            'cancelled' => ['critico', 'Cancelado'], 'rejected' => ['critico', 'Recusado'], 'refunded' => ['neutro', 'Estornado'], 'charged_back' => ['critico', 'Contestado']];
        $falta = $revenda->faltaParaBoleto();
    @endphp
    <div class="mx-auto w-full max-w-6xl space-y-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="font-display text-[22px] font-semibold leading-tight text-ink">Cobrança de revendas</h1>
                <p class="mt-0.5 text-[13px] text-ink-mute">A Alfa cobra a revenda por cliente ativo: boleto no dia 01, baixa automática quando o Mercado Pago confirma.</p>
            </div>
            @if ($revendas->count() > 1)
                <form method="GET"><select name="revenda" onchange="this.form.submit()" class="{{ $campo }} w-auto">
                    @foreach ($revendas as $r)<option value="{{ $r->id }}" @selected($r->is($revenda))>{{ $r->nome }}</option>@endforeach
                </select></form>
            @endif
        </div>
        @if (session('status'))<x-aviso>{{ session('status') }}</x-aviso>@endif
        @if (session('erro'))<x-aviso tom="critico" :segundos="0">{{ session('erro') }}</x-aviso>@endif
        @if ($revenda->cobranca_por_cliente && $falta)
            <x-aviso tom="atencao" :segundos="0">O boleto não sai enquanto faltar na revenda: {{ implode(', ', $falta) }}.</x-aviso>
        @endif

        {{-- Cobranças --}}
        <section class="rounded-panel border border-line bg-surface">
            <div class="flex items-center gap-2 border-b border-line px-4 py-3">
                <span class="h-5 w-5 text-brand-text"><x-nav-icon name="banknotes" peso="1.7" /></span>
                <h2 class="flex-1 font-display text-[15px] font-semibold text-ink">Cobranças e boletos</h2>
            </div>
            <x-tabela min="860px" class="!rounded-none !border-0 !bg-transparent">
                <thead>
                    <tr class="border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        <th class="px-4 py-2.5 font-normal">Competência</th><th class="px-3 py-2.5 font-normal">Valor</th><th class="px-3 py-2.5 font-normal">Vencimento</th>
                        <th class="px-3 py-2.5 font-normal">Situação</th><th class="px-3 py-2.5 font-normal">Boleto</th><th class="px-3 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($cobrancas as $c)
                        @php [$tom, $rotulo] = $c->status === 'pago' ? ['bom', 'Pago'] : ($c->status === 'cancelado' ? ['neutro', 'Cancelada'] : ($mp[$c->mp_status] ?? (($c->data_vencimento?->isPast()) ? ['critico', 'Vencida'] : ['neutro', 'Sem boleto']))); @endphp
                        <tr class="align-top">
                            <td class="px-4 py-3 text-[13.5px] font-semibold text-ink">{{ \Carbon\Carbon::createFromFormat('Y-m', $c->competencia)->format('m/Y') }}
                                <p class="text-[11.5px] font-normal text-ink-mute">#{{ $c->id }}</p></td>
                            <td class="whitespace-nowrap px-3 py-3 text-[13.5px] text-ink">{{ $rs($c->valor) }}
                                @if ($c->status === 'pago' && $c->valor_pago !== null)<p class="text-[11.5px] text-good">pago {{ $rs($c->valor_pago) }} em {{ $c->data_pagamento?->format('d/m') }}</p>@endif</td>
                            <td class="whitespace-nowrap px-3 py-3 text-[13px] text-ink">{{ $c->data_vencimento?->format('d/m/Y') }}</td>
                            <td class="px-3 py-3"><x-badge :tom="$tom" ponto>{{ $rotulo }}</x-badge>
                                @if ($c->mp_conferido_em)<p class="mt-1 text-[11px] text-ink-faint">conferido {{ $c->mp_conferido_em->format('d/m H:i') }}</p>@endif
                                @if ($c->mp_erro && ! $c->mp_payment_id)<p class="mt-1 max-w-[220px] text-[11.5px] text-crit">{{ $c->mp_erro }}</p>@endif</td>
                            <td class="px-3 py-3 text-[12px]">
                                @if ($c->mp_payment_id)
                                    @if ($c->mp_ticket_url)<a href="{{ $c->mp_ticket_url }}" target="_blank" rel="noopener noreferrer" class="font-semibold text-brand-text hover:underline">Abrir boleto</a>@endif
                                    @if ($c->mp_linha_digitavel)
                                        <p x-data="{ copiado: false }" class="mt-1 flex items-center gap-1.5">
                                            <span class="max-w-[240px] truncate font-mono text-[11px] text-ink-mute" title="{{ $c->mp_linha_digitavel }}">{{ $c->mp_linha_digitavel }}</span>
                                            <button type="button" @click="navigator.clipboard.writeText(@js($c->mp_linha_digitavel)); copiado = true; setTimeout(() => copiado = false, 1500)" class="text-[11px] text-ink-mute hover:text-brand" x-text="copiado ? 'copiado' : 'copiar'"></button>
                                        </p>
                                    @endif
                                    <p class="text-[11px] text-ink-faint">emitido {{ $c->mp_emitido_em?->format('d/m H:i') }} · MP {{ $c->mp_payment_id }}</p>
                                @elseif ($c->status === 'pendente')
                                    <span class="text-ink-mute">{{ $impedimentos[$c->id] ?? 'Sai às 08:00 do próximo dia.' }}</span>
                                @else
                                    <span class="text-ink-faint">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex justify-end gap-1">
                                    @if ($c->status === 'pendente' && ! $c->mp_payment_id && ! ($impedimentos[$c->id] ?? null))
                                        <form method="POST" action="{{ route('cobranca-revendas.emitir', $c->id) }}" onsubmit="return confirm('Emitir agora o boleto de {{ $rs($c->valor) }} para {{ $revenda->nome }}?')">@csrf
                                            <button class="{{ $botao }}">Emitir agora</button></form>
                                    @endif
                                    @if ($c->status === 'pendente' && $c->mp_payment_id)
                                        <form method="POST" action="{{ route('cobranca-revendas.conferir', $c->id) }}">@csrf<button class="{{ $botao }}">Conferir agora</button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-[13px] text-ink-mute">Nenhuma cobrança de licenciamento ainda: o fechamento do último dia do mês gera a primeira.</td></tr>
                    @endforelse
                </tbody>
            </x-tabela>
        </section>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
            {{-- Clientes por produto, com preço e prévia --}}
            <section class="rounded-panel border border-line bg-surface">
                <div class="flex items-center gap-2 border-b border-line px-4 py-3">
                    <span class="h-5 w-5 text-brand-text"><x-nav-icon name="users" peso="1.7" /></span>
                    <h2 class="flex-1 font-display text-[15px] font-semibold text-ink">Clientes e preço por cliente</h2>
                    <span class="text-[13px] text-ink">Próximo boleto: <b>{{ $rs($total) }}</b></span>
                </div>
                <form method="POST" action="{{ route('cobranca-revendas.precos', $revenda) }}" class="flex flex-wrap items-end gap-3 border-b border-line px-4 py-3">
                    @csrf @method('PUT')
                    @foreach ($produtos as $p)
                        <label class="text-[12px] text-ink-mute">{{ $p['sistema']->nome }} (R$ por cliente)
                            <input name="precos[{{ $p['sistema']->id }}]" type="number" step="0.01" min="0" value="{{ $p['preco'] }}" class="{{ $campo }} mt-0.5 w-[130px]"></label>
                    @endforeach
                    <button class="h-[30px] rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Salvar preços</button>
                </form>
                @foreach ($produtos as $p)
                    <div class="border-b border-line px-4 py-3 last:border-b-0">
                        <p class="mb-1.5 flex items-center gap-2 text-[13.5px] font-semibold text-ink">{{ $p['sistema']->nome }}
                            <span class="font-normal text-ink-mute">· {{ $p['faturaveis'] }} de {{ $p['clientes']->count() }} faturáveis · {{ $p['preco'] !== null ? $rs($p['subtotal']) : 'sem preço por cliente' }}</span></p>
                        <ul class="grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-2">
                            @foreach ($p['clientes'] as $cl)
                                <li class="flex items-center gap-2 text-[13px] {{ $cl->pivot->faturavel ? 'text-ink' : 'text-ink-faint line-through' }}">
                                    <form method="POST" action="{{ route('cobranca-revendas.faturavel', $revenda) }}">@csrf
                                        <input type="hidden" name="cliente_id" value="{{ $cl->id }}"><input type="hidden" name="sistema_id" value="{{ $p['sistema']->id }}">
                                        <input type="hidden" name="faturavel" value="{{ $cl->pivot->faturavel ? 0 : 1 }}">
                                        <button title="{{ $cl->pivot->faturavel ? 'Tirar da cobrança' : 'Pôr na cobrança' }}" class="flex h-[16px] w-[16px] items-center justify-center rounded border text-[10px] {{ $cl->pivot->faturavel ? 'border-good bg-good text-on-brand' : 'border-btn-line' }}">{{ $cl->pivot->faturavel ? '✓' : '' }}</button>
                                    </form>
                                    <span class="truncate" title="{{ $cl->nome }}">{{ $cl->nome }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </section>

            {{-- Dados de cobrança --}}
            <section class="rounded-panel border border-line bg-surface">
                <div class="flex items-center gap-2 border-b border-line px-4 py-3">
                    <span class="h-5 w-5 text-brand-text"><x-nav-icon name="building" peso="1.7" /></span>
                    <h2 class="font-display text-[15px] font-semibold text-ink">Dados do boleto · {{ $revenda->nome }}</h2>
                </div>
                <form method="POST" action="{{ route('cobranca-revendas.revenda', $revenda) }}" class="space-y-2 px-4 py-3">
                    @csrf @method('PUT')
                    <label class="flex items-center gap-2 text-[13px] text-ink"><input type="checkbox" name="cobranca_por_cliente" value="1" @checked($revenda->cobranca_por_cliente) class="rounded border-btn-line"> Cobrar por cliente, com boleto automático</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="text-[12px] text-ink-mute">CNPJ<input name="cnpj" value="{{ old('cnpj', $revenda->cnpj) }}" class="{{ $campo }} mt-0.5"></label>
                        <label class="text-[12px] text-ink-mute">Vence no dia<input name="dia_vencimento" type="number" min="1" max="28" value="{{ old('dia_vencimento', $revenda->dia_vencimento) }}" class="{{ $campo }} mt-0.5"></label>
                    </div>
                    <label class="block text-[12px] text-ink-mute">E-mail financeiro<input name="email_financeiro" type="email" value="{{ old('email_financeiro', $revenda->email_financeiro) }}" class="{{ $campo }} mt-0.5"></label>
                    <div class="grid grid-cols-[110px_minmax(0,1fr)] gap-2">
                        <label class="text-[12px] text-ink-mute">CEP<input name="cep" value="{{ old('cep', $revenda->cep) }}" class="{{ $campo }} mt-0.5"></label>
                        <label class="text-[12px] text-ink-mute">Logradouro<input name="logradouro" value="{{ old('logradouro', $revenda->logradouro) }}" class="{{ $campo }} mt-0.5"></label>
                    </div>
                    <div class="grid grid-cols-[90px_minmax(0,1fr)] gap-2">
                        <label class="text-[12px] text-ink-mute">Número<input name="numero" value="{{ old('numero', $revenda->numero) }}" class="{{ $campo }} mt-0.5"></label>
                        <label class="text-[12px] text-ink-mute">Bairro<input name="bairro" value="{{ old('bairro', $revenda->bairro) }}" class="{{ $campo }} mt-0.5"></label>
                    </div>
                    <div class="grid grid-cols-[minmax(0,1fr)_70px] gap-2">
                        <label class="text-[12px] text-ink-mute">Cidade<input name="cidade" value="{{ old('cidade', $revenda->cidade) }}" class="{{ $campo }} mt-0.5"></label>
                        <label class="text-[12px] text-ink-mute">UF<input name="uf" maxlength="2" value="{{ old('uf', $revenda->uf) }}" class="{{ $campo }} mt-0.5 uppercase"></label>
                    </div>
                    <label class="block text-[12px] text-ink-mute">Conta do Caixa que recebe
                        <select name="conta_financeira_id" class="{{ $campo }} mt-0.5"><option value="">—</option>
                            @foreach ($contas as $conta)<option value="{{ $conta->id }}" @selected($revenda->conta_financeira_id === $conta->id)>{{ $conta->nome }}</option>@endforeach
                        </select></label>
                    @if ($errors->any())<p class="text-[12px] text-crit">{{ $errors->first() }}</p>@endif
                    <button class="h-[30px] rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Salvar</button>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
