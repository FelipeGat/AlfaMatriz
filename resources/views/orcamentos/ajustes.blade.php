@php
    use App\Services\Orcamentos\Ajustes;
    $campo = 'w-full rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none';
    $rotulo = 'grid gap-1 text-[11.5px] text-ink-mute';
@endphp
<x-app-layout>
    <x-slot name="titulo">Ajustes dos orçamentos</x-slot>
    <x-slot name="contexto"><span>Emissor, clientes que atendemos e capa padrão</span></x-slot>
    <x-slot name="acoes"><a href="{{ route('orcamentos.index') }}" class="inline-flex h-[30px] items-center rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand">Orçamentos</a></x-slot>

    {{-- docs/specs/orcamentos-vixcard/padrao-e-historico.md, FR-H9: o que vai em toda proposta
         e não é de um orçamento só. Vazio não sai no PDF (nada é inventado). --}}
    <form method="POST" action="{{ route('orcamentos.ajustes.salvar') }}" enctype="multipart/form-data" class="mx-auto grid w-full max-w-4xl gap-3">
        @csrf
        @if (session('status'))<x-aviso>{{ session('status') }}</x-aviso>@endif
        @if ($errors->any())<x-aviso tom="critico" :segundos="0">{{ $errors->first() }}</x-aviso>@endif

        <section class="grid gap-2.5 rounded-panel border border-line bg-surface p-4">
            <h2 class="text-[13px] font-semibold text-ink">Emissor <small class="font-normal text-ink-faint">sai no rodapé e no aceite das propostas</small></h2>
            <div class="grid gap-2.5 sm:grid-cols-2">
                @foreach (Ajustes::CAMPOS as $chave => $nome)
                    <label class="{{ $rotulo }}">{{ $nome }}<input name="{{ $chave }}" maxlength="255" value="{{ old($chave, $ajustes->get($chave)) }}" class="{{ $campo }}"></label>
                @endforeach
            </div>
        </section>

        <section class="grid gap-2.5 rounded-panel border border-line bg-surface p-4">
            <h2 class="text-[13px] font-semibold text-ink">Clientes que atendemos <small class="font-normal text-ink-faint">na capa · "## Setor" e um cliente por linha</small></h2>
            <textarea name="clientes" rows="14" maxlength="6000" class="{{ $campo }} font-mono text-[12.5px]">{{ old('clientes', $ajustes->get('clientes')) }}</textarea>
        </section>

        <section class="grid gap-2.5 rounded-panel border border-line bg-surface p-4">
            <h2 class="text-[13px] font-semibold text-ink">Capa padrão <small class="font-normal text-ink-faint">foto de fundo de quem não escolher uma no orçamento</small></h2>
            @if ($ajustes->get('capa_padrao'))
                <img src="{{ route('orcamentos.capa-padrao') }}" alt="Capa padrão atual" class="max-h-48 w-auto rounded-control border border-line">
                <label class="flex items-center gap-1.5 text-[12px] text-ink-dim"><input type="checkbox" name="remover_capa_padrao" value="1" class="rounded border-btn-line"> tirar a capa padrão</label>
            @endif
            <input name="capa_padrao" type="file" accept="image/jpeg,image/png,image/webp" class="{{ $campo }}">
        </section>

        <div class="flex justify-end"><button class="inline-flex h-[32px] items-center rounded-control bg-brand px-4 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Salvar ajustes</button></div>
    </form>
</x-app-layout>
