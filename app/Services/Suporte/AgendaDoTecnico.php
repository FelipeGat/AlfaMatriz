<?php

namespace App\Services\Suporte;

use App\Models\SuporteConversa;
use App\Models\SuporteGestorAgenda;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * O dia do técnico (docs/specs/pedro-dia-do-tecnico): a IA propõe as paradas do relato; aqui cada
 * uma é conferida contra o retrato do Gestor — visita e atendimento que existem, técnico da equipe,
 * início e fim, conflito com as visitas do dia — e a prévia fica guardada na conversa até o "sim",
 * que a põe na fila (suporte_gestor_agenda) para a máquina gravar no Gestor. A IA propõe; o que não
 * fecha aqui não vai ao Gestor.
 *
 * A prévia e o resultado são escritos AQUI, a partir das paradas conferidas (bugfix de 02/11/2026: a
 * prévia da IA dizia 20 visitas, o código guardava 15, e o resultado listou 15 sem dizer que faltavam
 * 5). O que a pessoa lê é o que vai ao Gestor, e o total vem do que o Gestor confirmou.
 */
class AgendaDoTecnico
{
    public function __construct(private DiretorioGestor $diretorio) {}

    /**
     * O que se lança de uma vez. Acima disso a prévia recusa, dizendo — nunca corta calada. A máquina
     * grava ~5 por volta de 20 s, e 40 fecham em menos de 3 min.
     */
    public const LIMITE = 40;

    /** Prévia que vale para o "sim": a última, desta pessoa, de até 3 h, sem pendência. */
    public const VALIDADE_HORAS = 3;

    /** Sem o usuário do Gestor de quem pede, a visita sairia no nome de outra pessoa (bugfix de 02/11/2026). */
    public const SEM_AUTOR = 'Para lançar no seu nome, preciso saber quem você é no Gestor: ponha este celular no seu cadastro de funcionário do Gestor '
        .'(o funcionário ligado ao seu usuário), ou o e-mail do seu usuário na sua linha da lista do Pedro (Suporte › Pedro).';

    /**
     * Confere e guarda a prévia; devolve a prévia que a pessoa lê, montada aqui.
     *
     * @param  array{nome: string, perfil: string, gestor_email?: ?string}  $acesso
     * @param  list<array>  $paradas
     */
    public function previa(SuporteConversa $c, array $acesso, array $paradas): string
    {
        if (! AcessoInterno::alteraAgenda($acesso)) {
            return '(Lançar ou corrigir a agenda é só para diretor e gestor.)';
        }
        $anterior = $this->previaAberta($c, $acesso);
        if (count($paradas) > self::LIMITE) {
            $c->update(['previa_agenda' => ['em' => now()->toIso8601String(), 'pedido_por' => $acesso['nome'], 'autor_email' => $acesso['gestor_email'] ?? null,
                'paradas' => $paradas, 'problemas' => ['a programação passou de '.self::LIMITE.' mudanças'], 'avisos' => []]]);

            return 'A programação tem '.count($paradas).' mudanças na agenda, e eu lanço até '.self::LIMITE.' de cada vez. '
                .'Mande em partes (por exemplo, terça e quarta; depois quinta e sexta), e eu monto a prévia de cada uma.';
        }
        $citados = app(AcessoInterno::class)->clientesCitados(app(Contexto::class)->textoParaRegistrar($c));
        [$problemas, $avisos] = $this->conferir($paradas, $citados);
        if (empty($acesso['gestor_email'])) {
            $problemas[] = self::SEM_AUTOR;
        }
        // O aviso que já saiu na prévia anterior não sai de novo (02/11/2026: "Cristiano tem visita das 09:00
        // às 10:00… continua?" voltou depois de o Felipe responder "sim, continua").
        $ditos = $anterior ? (array) ($anterior['avisos_ditos'] ?? $anterior['avisos'] ?? []) : [];
        $novos = array_values(array_diff($avisos, $ditos));
        $c->update(['previa_agenda' => ['em' => now()->toIso8601String(), 'pedido_por' => $acesso['nome'], 'autor_email' => $acesso['gestor_email'] ?? null,
            'paradas' => $paradas, 'problemas' => $problemas, 'avisos' => $avisos, 'avisos_ditos' => array_values(array_unique(array_merge($ditos, $novos)))]]);

        $texto = "Prévia da agenda (as visitas entram agendadas; o técnico finaliza pelo app):\n\n".$this->porDia($paradas, $citados)
            ."\n\n".$this->total($paradas);
        if ($problemas) {
            $texto .= "\n\nAntes de lançar, preciso acertar:\n".collect($problemas)->map(fn ($p) => '• '.$p)->implode("\n");
        }
        if ($novos) {
            $texto .= "\n\nPara você saber:\n".collect($novos)->map(fn ($a) => '• '.$a)->implode("\n");
        }

        return $texto.($problemas ? '' : "\n\nPosso lançar?");
    }

    /** A prévia ainda não confirmada desta pessoa, dentro da validade. @return array|null */
    private function previaAberta(SuporteConversa $c, array $acesso): ?array
    {
        $p = $c->previa_agenda;

        return $p && empty($p['confirmada_em']) && ($p['pedido_por'] ?? null) === $acesso['nome'] && ! empty($p['em'])
            && CarbonImmutable::parse($p['em'])->gte(now()->subHours(self::VALIDADE_HORAS)) ? $p : null;
    }

    /**
     * O "sim" de quem pediu: as visitas da prévia vão para a fila do Gestor. Abrir atendimento
     * novo segue pelo registro de atendimento, que tem a prévia dele (empresa, assunto, título).
     *
     * @param  array{nome: string, perfil: string, gestor_email?: ?string}  $acesso
     */
    public function confirmar(SuporteConversa $c, array $acesso): string
    {
        if (! AcessoInterno::alteraAgenda($acesso)) {
            return 'Lançar ou corrigir a agenda é só para diretor e gestor.';
        }
        $previa = $c->previa_agenda;
        if (! $previa || ! empty($previa['confirmada_em']) || CarbonImmutable::parse($previa['em'])->lt(now()->subHours(self::VALIDADE_HORAS))) {
            return 'Não achei uma prévia da agenda para confirmar: me mande o dia de novo e eu monto a prévia.';
        }
        if (count($previa['paradas']) > self::LIMITE) {
            return 'Ainda não lancei: a programação passou de '.self::LIMITE.' mudanças. Mande em partes, e eu monto a prévia de cada uma.';
        }
        // Quem confirma é quem vira autor no Gestor: tem de ser o mesmo usuário da prévia.
        if (empty($acesso['gestor_email']) || ($previa['autor_email'] ?? null) !== $acesso['gestor_email']) {
            return 'Ainda não lancei. '.(empty($acesso['gestor_email']) ? self::SEM_AUTOR : 'O seu usuário no Gestor mudou desde a prévia: me mande o dia de novo e eu monto a prévia.');
        }
        // Reconfere: o retrato pode ter mudado desde a prévia.
        [$problemas] = $this->conferir($previa['paradas'], app(AcessoInterno::class)->clientesCitados(app(Contexto::class)->textoParaRegistrar($c)));
        if ($problemas) {
            return "Ainda não lancei, porque conferi no Gestor e falta acertar:\n".collect($problemas)->map(fn ($p) => '• '.$p)->implode("\n");
        }
        $lote = 'c'.$c->id.'-'.CarbonImmutable::parse($previa['em'])->format('YmdHis');
        $n = 0;
        foreach ($previa['paradas'] as $i => $p) {
            if (! in_array($p['tipo'], ['criar_visita', 'reagendar_visita', 'cancelar_visita'], true)) {
                continue;
            }
            $visita = $p['visita_id'] ? $this->diretorio->visita($p['visita_id']) : null;
            SuporteGestorAgenda::firstOrCreate(['chave' => $lote.'-'.$i], [
                'conversa_id' => $c->id, 'lote' => $lote, 'tipo' => $p['tipo'], 'visita_id' => $p['visita_id'],
                // Reagendar e cancelar não citam o atendimento: o número vem da visita, para o resumo.
                'atendimento' => $p['atendimento'] ?? ($visita ? ($this->diretorio->atendimentoPorId((int) $visita['atendimento_id'])['numero'] ?? null) : null),
                'tecnico_id' => $p['tecnico_id'], 'inicio' => $p['hora_inicio'] ? $p['data'].' '.$p['hora_inicio'] : null, 'fim' => $p['hora_fim'] ? $p['data'].' '.$p['hora_fim'] : null,
                'esperado' => $visita ? ['inicio' => $visita['inicio'], 'fim' => $visita['fim'], 'situacao' => $visita['situacao']] : null,
                'motivo' => $p['motivo'], 'observacao' => $p['observacao'], 'pedido_por' => $previa['pedido_por'], 'autor_email' => $previa['autor_email'],
            ]);
            $n++;
        }
        $c->update(['previa_agenda' => ['confirmada_em' => now()->toIso8601String(), 'lote' => $lote] + $previa]);
        $novos = collect($previa['paradas'])->where('tipo', 'abrir_atendimento')->count();

        return ($n ? "Enviei {$n} ".($n === 1 ? 'mudança' : 'mudanças').' da agenda para o Gestor; o resultado chega em seguida.' : 'Nada da agenda para lançar nesta prévia.')
            .($novos ? "\nOs {$novos} atendimento(s) novo(s) seguem pelo registro de atendimento: vamos a eles." : '');
    }

    /**
     * O Gestor respondeu a última mudança do lote: um resumo só, para quem pediu, com o total que o
     * Gestor confirmou e cada falha nomeada.
     */
    public function concluirLote(string $lote, CanalWhatsApp $canal): void
    {
        $todas = SuporteGestorAgenda::with('conversa')->where('lote', $lote)->orderBy('id')->get();
        if ($todas->isEmpty() || $todas->contains(fn ($m) => in_array($m->situacao, ['pendente', 'reservado'], true))) {
            return;
        }
        $comoParada = fn ($m) => ['tipo' => $m->tipo, 'visita_id' => $m->visita_id, 'atendimento' => $m->resultado['numero'] ?? $m->atendimento,
            'cliente' => null, 'tecnico_id' => (int) $m->tecnico_id, 'data' => $m->inicio ? substr($m->inicio, 0, 10) : null,
            'hora_inicio' => $m->inicio ? substr($m->inicio, 11, 5) : null, 'hora_fim' => $m->fim ? substr((string) $m->fim, 11, 5) : null, 'motivo' => $m->motivo,
            // O horário de antes é o que a prévia mostrou: o retrato pode já trazer o novo.
            'era' => ! empty($m->esperado['inicio']) ? substr((string) $m->esperado['inicio'], 11, 5).' às '.substr((string) ($m->esperado['fim'] ?? ''), 11, 5) : null];
        [$feitas, $falhas] = $todas->partition(fn ($m) => $m->situacao === 'feito');
        $total = $todas->count();
        $jaEstavam = $feitas->filter(fn ($m) => ! empty($m->resultado['ja_estava']))->count();
        $texto = 'Agenda no Gestor: '.match (true) {
            $falhas->isEmpty() => $total === 1 ? 'a mudança foi gravada' : "as {$total} mudanças foram gravadas",
            $feitas->isEmpty() => $total === 1 ? 'a mudança NÃO foi gravada' : "nenhuma das {$total} mudanças foi gravada",
            default => $feitas->count()." de {$total} gravadas; ".$falhas->count().' não '.($falhas->count() === 1 ? 'gravou' : 'gravaram'),
        }
            .($jaEstavam ? " ({$jaEstavam} já estava".($jaEstavam === 1 ? '' : 'm').' lá)' : '').'.';
        if ($falhas->isNotEmpty()) {
            $texto .= "\n\n❌ Não gravaram:\n".$falhas->map(fn ($m) => '• '.$this->rotulo($comoParada($m)).' — '.$this->oQueMuda($comoParada($m)).': '.$m->erro)->implode("\n");
        }
        if ($feitas->isNotEmpty()) {
            $texto .= "\n\n".($falhas->isEmpty() ? '' : "✅ Gravadas:\n").$this->porDia($feitas->map($comoParada)->values()->all());
        }
        $passado = $feitas->contains(fn ($m) => $m->inicio && substr($m->inicio, 0, 10) < now(config('app.timezone'))->toDateString());
        $texto .= $passado ? "\n\nAs visitas de dia que já passou entram agendadas e o Gestor as finaliza às 23:55." : '';
        try {
            // Em partes, como as respostas da casa: o resumo de um lote de 40 passa dos 4.096 do WhatsApp.
            foreach (Contexto::partes($texto) as $parte) {
                $canal->enviar($todas->first()->conversa, $parte, 'pedro');
            }
        } catch (\Illuminate\Validation\ValidationException) {
            // Fora da janela: o resultado fica na fila, para a tela.
        }
    }

    /**
     * @param  list<array>  $paradas
     * @param  array<string, array>  $citados  clientes que podem ser usados ("gestor:ID" => cliente)
     * @return array{0: list<string>, 1: list<string>} problemas (bloqueiam a gravação) e avisos
     */
    public function conferir(array $paradas, array $citados = []): array
    {
        $r = $this->diretorio->retrato();
        $equipe = collect($r['equipe'] ?? [])->keyBy('id');
        $nome = fn ($id) => Str::before((string) ($equipe[$id]['nome'] ?? 'técnico '.$id), ' ');
        $problemas = [];
        $avisos = [];
        $tempos = [];
        if (! $paradas) {
            return [['a prévia veio sem nenhuma parada'], []];
        }

        foreach ($paradas as $p) {
            // A parada é chamada pelo dia, pelo técnico e pelo nº: é o que a pessoa vê na prévia.
            $n = $this->rotulo($p);
            $visita = $p['visita_id'] ? $this->diretorio->visita($p['visita_id']) : null;
            $atendimento = $p['atendimento'] ? $this->diretorio->atendimentoPorNumero($p['atendimento']) : null;
            if (! $p['tipo']) {
                $problemas[] = "{$n}: não ficou claro o que fazer (corrigir, criar, cancelar ou abrir atendimento).";

                continue;
            }
            if (! $p['tecnico_id'] || ! $equipe->has($p['tecnico_id'])) {
                $problemas[] = "{$n}: o técnico não está na equipe do Gestor.";
            }
            if (in_array($p['tipo'], ['reagendar_visita', 'cancelar_visita'], true)) {
                if (! $visita) {
                    $problemas[] = "{$n}: a visita citada não está na agenda do Gestor.";
                } elseif ($visita['situacao'] === 'cancelado') {
                    $problemas[] = "{$n}: a visita já está cancelada no Gestor.";
                } elseif ($visita['situacao'] === 'finalizado') {
                    $avisos[] = "{$n}: a visita das ".substr((string) $visita['inicio'], 11, 5).' já está FINALIZADA no Gestor; mexer nela muda o registro do que foi feito.';
                }
                if ($visita && ! empty($visita['adicionais'])) {
                    $avisos[] = "{$n}: a visita tem técnicos adicionais (".collect($visita['adicionais'])->map($nome)->implode(', ').'); eles não mudam.';
                }
            }
            if ($p['tipo'] === 'cancelar_visita') {
                if (! $p['motivo']) {
                    $problemas[] = "{$n}: falta o motivo do cancelamento.";
                }

                continue;
            }
            if ($p['tipo'] === 'criar_visita' && ! $atendimento) {
                $problemas[] = $p['atendimento'] ? "{$n}: o atendimento nº {$p['atendimento']} não foi achado no Gestor." : "{$n}: falta o nº do atendimento.";
            }
            if ($p['tipo'] === 'abrir_atendimento' && $p['cliente'] !== 'novo_pre' && ! isset($citados[(string) $p['cliente']])) {
                $problemas[] = "{$n}: o cliente não foi confirmado no Gestor (diga o nome como está cadastrado, ou crie um pré-cliente novo).";
            }
            if (! $p['data'] || ! $p['hora_inicio'] || ! $p['hora_fim']) {
                $problemas[] = "{$n}: falta ".collect(['data' => 'o dia', 'hora_inicio' => 'o horário de início', 'hora_fim' => 'o horário de término'])->filter(fn ($_, $k) => ! $p[$k])->implode(' e ').'.';

                continue;
            }
            if ($p['hora_fim'] <= $p['hora_inicio']) {
                $problemas[] = "{$n}: o término ({$p['hora_fim']}) não é depois do início ({$p['hora_inicio']}).";

                continue;
            }
            $tempos[] = ['n' => $n, 'tecnico' => $p['tecnico_id'], 'data' => $p['data'], 'ini' => $p['hora_inicio'], 'fim' => $p['hora_fim'], 'visita' => $p['visita_id']];
        }

        // Conflitos: o mesmo técnico em dois lugares — com as visitas do Gestor que a prévia não muda, e entre as paradas.
        $mexidas = collect($paradas)->pluck('visita_id')->filter()->all();
        foreach ($tempos as $k => $t) {
            foreach ($r['visitas'] ?? [] as $v) {
                if (in_array($v['id'], $mexidas, true) || (int) ($v['tecnico_id'] ?? 0) !== $t['tecnico'] || $v['situacao'] === 'cancelado'
                    || ! str_starts_with((string) $v['inicio'], $t['data'])) {
                    continue;
                }
                [$vi, $vf] = [substr((string) $v['inicio'], 11, 5), substr((string) ($v['fim'] ?? ''), 11, 5)];
                if ($vf !== '' && $t['ini'] < $vf && $vi < $t['fim']) {
                    $a = $this->diretorio->atendimentoPorId((int) $v['atendimento_id']);
                    $problemas[] = "{$t['n']}: conflita com a visita de {$nome($t['tecnico'])} das {$vi} às {$vf}".($a ? ' (nº '.$a['numero'].')' : '').' — qual vale?';
                }
            }
            foreach (array_slice($tempos, $k + 1) as $o) {
                if ($o['tecnico'] === $t['tecnico'] && $o['data'] === $t['data'] && $t['ini'] < $o['fim'] && $o['ini'] < $t['fim']) {
                    $problemas[] = "{$t['n']} e {$o['n']}: o mesmo técnico em dois lugares no mesmo horário.";
                }
            }
        }
        // Visita do técnico no dia que a programação não menciona e que não choca: fica como está, e a
        // pessoa fica sabendo uma vez (a prévia seguinte não repete o que já foi dito).
        foreach (collect($tempos)->map(fn ($t) => $t['tecnico'].'|'.$t['data'])->unique() as $chave) {
            [$tecnico, $data] = explode('|', $chave);
            foreach ($r['visitas'] ?? [] as $v) {
                if ((int) ($v['tecnico_id'] ?? 0) === (int) $tecnico && str_starts_with((string) $v['inicio'], $data) && $v['situacao'] !== 'cancelado'
                    && ! in_array($v['id'], $mexidas, true) && ! collect($problemas)->contains(fn ($p) => str_contains($p, 'das '.substr((string) $v['inicio'], 11, 5).' às'))) {
                    $a = $this->diretorio->atendimentoPorId((int) $v['atendimento_id']);
                    $avisos[] = "{$nome((int) $tecnico)} já tem visita em ".CarbonImmutable::parse($data)->format('d/m').' das '.substr((string) $v['inicio'], 11, 5).' às '.substr((string) ($v['fim'] ?? ''), 11, 5)
                        .($a ? ' (nº '.$a['numero'].')' : '').', que não está nesta programação: ela fica como está.';
                }
            }
        }

        return [array_values(array_unique($problemas)), array_values(array_unique($avisos))];
    }

    /** O nº do atendimento da parada: o citado ou, na visita que já existe, o dela. */
    private function numero(array $p): ?string
    {
        if (! empty($p['atendimento'])) {
            return (string) $p['atendimento'];
        }
        $v = ($p['visita_id'] ?? null) ? $this->diretorio->visita((int) $p['visita_id']) : null;

        return $v ? ($this->diretorio->atendimentoPorId((int) $v['atendimento_id'])['numero'] ?? null) : null;
    }

    /** "Vitor"; quem não está na equipe, "técnico 77". */
    private function primeiroNome(mixed $id): string
    {
        $f = collect($this->diretorio->retrato()['equipe'] ?? [])->firstWhere('id', (int) $id);

        return $f ? Str::before((string) $f['nome'], ' ') : 'técnico '.($id ?: '?');
    }

    /** "Terça 03/11, Vitor (nº 832)": como a pessoa acha a parada na prévia. */
    private function rotulo(array $p): string
    {
        $numero = $this->numero($p);

        return trim(($p['data'] ?? null) ? $this->dia($p['data']) : '').(($p['data'] ?? null) ? ', ' : '')
            .$this->primeiroNome($p['tecnico_id'] ?? null).($numero ? " (nº {$numero})" : '');
    }

    /** "Terça 03/11". */
    private function dia(string $data): string
    {
        $d = CarbonImmutable::parse($data, config('app.timezone'))->locale('pt_BR');

        return Str::ucfirst(Str::before($d->isoFormat('dddd'), '-')).' '.$d->format('d/m');
    }

    /**
     * As paradas por dia, uma linha por lugar e horário, com os técnicos juntos — "Alefe e Vitor" na
     * mesma visita do nº 832 —, na ordem do horário.
     *
     * @param  list<array>  $paradas
     */
    private function porDia(array $paradas, array $citados = []): string
    {
        $nomes = fn (Collection $ps) => self::juntar($ps->map(fn ($p) => $this->primeiroNome($p['tecnico_id'] ?? null))->unique()->values()->all());

        return collect($paradas)->groupBy(fn ($p) => $p['data'] ?? '')->sortKeys()->map(function (Collection $doDia, $data) use ($nomes, $citados) {
            $data = (string) $data;
            $linhas = $doDia->groupBy(fn ($p) => implode('|', [$p['tipo'], $p['visita_id'] ?? '', $p['atendimento'] ?? '', $p['cliente'] ?? '', $p['hora_inicio'] ?? '', $p['hora_fim'] ?? '']))
                ->sortBy(fn (Collection $ps) => ($ps->first()['hora_inicio'] ?? '99').$this->cliente($ps->first(), $citados))
                ->map(function (Collection $ps) use ($nomes, $citados) {
                    $p = $ps->first();
                    $horario = $p['hora_inicio'] && $p['hora_fim'] ? $p['hora_inicio'].' às '.$p['hora_fim'] : 'horário a definir';

                    $numero = $this->numero($p);

                    return '• '.$horario.' — '.$this->cliente($p, $citados).($numero ? ' — nº '.$numero : '').' — '.$this->oQueMuda($p).' — '.$nomes($ps);
                });

            return '*'.($data !== '' ? $this->dia($data) : 'Sem dia').'*'."\n".$linhas->implode("\n");
        })->implode("\n\n");
    }

    /** "Total: 18 visitas novas, 1 horário corrigido e 1 cancelamento." */
    private function total(array $paradas): string
    {
        $por = collect($paradas)->countBy(fn ($p) => $p['tipo'] ?? '?');
        $partes = collect([
            'criar_visita' => ['visita nova', 'visitas novas'], 'reagendar_visita' => ['horário corrigido', 'horários corrigidos'],
            'cancelar_visita' => ['cancelamento', 'cancelamentos'], 'abrir_atendimento' => ['atendimento novo a abrir depois, pelo registro', 'atendimentos novos a abrir depois, pelo registro'],
        ])->filter(fn ($_, $tipo) => $por->has($tipo))->map(fn ($nomes, $tipo) => $por[$tipo].' '.$nomes[$por[$tipo] === 1 ? 0 : 1])->values()->all();
        $semTipo = $por->except(['criar_visita', 'reagendar_visita', 'cancelar_visita', 'abrir_atendimento'])->sum();

        return 'Total: '.self::juntar([...$partes, ...($semTipo ? [$semTipo.' sem o que fazer definido'] : [])]).'.';
    }

    private function oQueMuda(array $p): string
    {
        $visita = ! array_key_exists('era', $p) && ($p['visita_id'] ?? null) ? $this->diretorio->visita((int) $p['visita_id']) : null;
        $antes = $p['era'] ?? ($visita ? substr((string) $visita['inicio'], 11, 5).' às '.substr((string) ($visita['fim'] ?? ''), 11, 5) : null);

        return match ($p['tipo'] ?? null) {
            'criar_visita' => 'visita nova',
            'reagendar_visita' => 'horário corrigido'.($antes ? " (era {$antes})" : ''),
            'cancelar_visita' => 'visita cancelada'.(! empty($p['motivo']) ? ' ('.Str::limit((string) $p['motivo'], 60, '…').')' : ''),
            'abrir_atendimento' => 'atendimento novo, a abrir depois do lançamento',
            default => 'o que fazer não ficou claro',
        };
    }

    /** O nome do cliente da parada, pelo atendimento, pela visita ou pelo cadastro citado. */
    private function cliente(array $p, array $citados = []): string
    {
        if (($p['cliente'] ?? null) === 'novo_pre') {
            return 'pré-cliente novo';
        }
        if (isset($citados[(string) ($p['cliente'] ?? '')])) {
            $c = $citados[(string) $p['cliente']];

            return trim(($c['fantasia'] ?? '') ?: ($c['nome'] ?? 'cliente'));
        }
        $a = ($p['atendimento'] ?? null) ? $this->diretorio->atendimentoPorNumero((string) $p['atendimento']) : null;
        if (! $a && ($p['visita_id'] ?? null) && ($v = $this->diretorio->visita((int) $p['visita_id']))) {
            $a = $this->diretorio->atendimentoPorId((int) $v['atendimento_id']);
        }
        $cl = $a ? $this->diretorio->cliente((int) ($a['cliente_id'] ?? 0)) : null;

        return $cl ? trim(($cl['fantasia'] ?? '') ?: ($cl['nome'] ?? 'cliente')) : (trim((string) ($a['solicitante'] ?? '')) ?: 'cliente não identificado');
    }

    /** "Alefe, Cleison e Vitor". @param list<string> $itens */
    private static function juntar(array $itens): string
    {
        return count($itens) > 1 ? implode(', ', array_slice($itens, 0, -1)).' e '.end($itens) : (string) ($itens[0] ?? '');
    }
}
