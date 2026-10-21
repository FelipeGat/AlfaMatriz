@php use App\Models\SecretarioReuniaoTrecho; @endphp
<!DOCTYPE html>
<html lang="pt-BR" class="theme-light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ata — {{ $r->titulo }}</title>
    @vite(['resources/css/app.css'])
</head>
{{-- Ata para imprimir ou salvar em PDF (Ctrl+P). Tema claro de propósito: é papel. --}}
<body class="bg-canvas px-6 py-8 font-sans text-ink">
    <main class="mx-auto max-w-3xl space-y-5 text-[13.5px] leading-relaxed">
        <header class="border-b border-line pb-3">
            <h1 class="font-display text-[22px] font-semibold">Ata — {{ $r->titulo }}</h1>
            <p class="text-ink-mute">{{ $r->created_at->format('d/m/Y H:i') }}{{ $r->duracao_s ? ' · duração '.SecretarioReuniaoTrecho::horario($r->duracao_s) : '' }} · tipo: {{ $r->tipo_reuniao }}{{ $r->parcial ? ' · análise parcial' : '' }}</p>
            @if ($r->participantes)<p class="text-ink-mute">Nomes citados (inferência): {{ implode(', ', $r->participantes) }}</p>@endif
        </header>
        <section><h2 class="font-semibold">Contexto</h2>
            @foreach (['empresa' => 'Empresa', 'cliente' => 'Cliente', 'projeto' => 'Projeto', 'objetivo' => 'Objetivo', 'problema' => 'Problema', 'necessidade' => 'Necessidade'] as $k => $rot)
                <p><span class="text-ink-mute">{{ $rot }}:</span> {{ $r->contexto[$k] ?? 'não identificado' }}</p>
            @endforeach
        </section>
        <section><h2 class="font-semibold">Resumo</h2><p class="whitespace-pre-line">{{ $r->resumo }}</p></section>
        <section><h2 class="font-semibold">Decisões</h2>
            @forelse ($r->propostas->where('categoria', 'decisao') as $d)<p>• {{ $d->texto }} <span class="text-ink-mute">[{{ implode(', ', $d->evidencias) }}]</span></p>@empty<p class="text-ink-mute">Nenhuma decisão identificada.</p>@endforelse
        </section>
        <section><h2 class="font-semibold">Tarefas</h2>
            @forelse ($r->propostas->where('categoria', 'tarefa') as $t)
                <p>• {{ $t->texto }} — <span class="text-ink-mute">responsável: {{ $t->responsavel ?? 'não identificado' }}; prazo: {{ $t->prazo ?? 'não identificado' }}; {{ $t->status }}</span></p>
            @empty<p class="text-ink-mute">Nenhuma tarefa identificada.</p>@endforelse
        </section>
        <section><h2 class="font-semibold">Pendências</h2>
            @forelse ($r->propostas->where('categoria', 'pendencia') as $p)<p>• {{ $p->texto }}</p>@empty<p class="text-ink-mute">Nenhuma pendência.</p>@endforelse
        </section>
        @if ($r->proximos_passos)<section><h2 class="font-semibold">Próximos passos</h2>@foreach ($r->proximos_passos as $p)<p>• {{ $p }}</p>@endforeach</section>@endif
        @if ($r->roteiro)<section><h2 class="font-semibold">Roteiro de execução</h2>@foreach ($r->roteiro as $i => $p)<p>{{ $i + 1 }}. {{ $p }}</p>@endforeach</section>@endif
        <footer class="border-t border-line pt-3 text-[11.5px] text-ink-mute">Gerada pelo Secretário do AlfaMatriz a partir da transcrição local; os números entre colchetes são os trechos da transcrição.</footer>
    </main>
</body>
</html>
