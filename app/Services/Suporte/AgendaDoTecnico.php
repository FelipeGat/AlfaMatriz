<?php

namespace App\Services\Suporte;

use App\Models\SuporteConversa;
use App\Models\SuporteGestorAgenda;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * O dia do técnico (docs/specs/pedro-dia-do-tecnico): a IA propõe as paradas do relato; aqui cada
 * uma é conferida contra o retrato do Gestor — visita e atendimento que existem, técnico da equipe,
 * início e fim, conflito com as visitas do dia — e a prévia fica guardada na conversa até o "sim",
 * que a põe na fila (suporte_gestor_agenda) para a máquina gravar no Gestor. A IA propõe; o que não
 * fecha aqui não vai ao Gestor.
 */
class AgendaDoTecnico
{
    public function __construct(private DiretorioGestor $diretorio) {}

    /**
     * Confere e guarda a prévia; devolve o que acrescentar à resposta (vazio se tudo fecha).
     *
     * @param  array{nome: string, perfil: string}  $acesso
     * @param  list<array>  $paradas
     */
    public function previa(SuporteConversa $c, array $acesso, array $paradas): string
    {
        if (! AcessoInterno::alteraAgenda($acesso)) {
            return "\n\n(Lançar ou corrigir a agenda é só para diretor e gestor.)";
        }
        [$problemas, $avisos] = $this->conferir($paradas, app(AcessoInterno::class)->clientesCitados(app(Contexto::class)->textoParaRegistrar($c)));
        $c->update(['previa_agenda' => ['em' => now()->toIso8601String(), 'pedido_por' => $acesso['nome'], 'paradas' => $paradas,
            'problemas' => $problemas, 'avisos' => $avisos]]);
        $texto = '';
        if ($problemas) {
            $texto .= "\n\nConferi no Gestor, e antes de lançar preciso acertar:\n".collect($problemas)->map(fn ($p) => '• '.$p)->implode("\n");
        }
        if ($avisos) {
            $texto .= "\n\nAtenção:\n".collect($avisos)->map(fn ($a) => '• '.$a)->implode("\n");
        }

        return $texto;
    }

    /** Prévia que vale para o "sim": a última, desta pessoa, de até 3 h, sem pendência. */
    public const VALIDADE_HORAS = 3;

    /**
     * O "sim" de quem pediu: as visitas da prévia vão para a fila do Gestor. Abrir atendimento
     * novo segue pelo registro de atendimento, que tem a prévia dele (empresa, assunto, título).
     *
     * @param  array{nome: string, perfil: string}  $acesso
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
                'motivo' => $p['motivo'], 'observacao' => $p['observacao'], 'pedido_por' => $previa['pedido_por'],
            ]);
            $n++;
        }
        $c->update(['previa_agenda' => ['confirmada_em' => now()->toIso8601String(), 'lote' => $lote] + $previa]);
        $novos = collect($previa['paradas'])->where('tipo', 'abrir_atendimento')->count();

        return ($n ? "Enviei {$n} ".($n === 1 ? 'mudança' : 'mudanças').' da agenda para o Gestor; o resultado chega em seguida.' : 'Nada da agenda para lançar nesta prévia.')
            .($novos ? "\nOs {$novos} atendimento(s) novo(s) seguem pelo registro de atendimento: vamos a eles." : '');
    }

    /** O Gestor respondeu a última mudança do lote: um resumo só, para quem pediu. */
    public function concluirLote(string $lote, CanalWhatsApp $canal): void
    {
        $todas = SuporteGestorAgenda::with('conversa')->where('lote', $lote)->orderBy('id')->get();
        if ($todas->isEmpty() || $todas->contains(fn ($m) => in_array($m->situacao, ['pendente', 'reservado'], true))) {
            return;
        }
        $verbo = ['criar_visita' => 'visita criada', 'reagendar_visita' => 'horário corrigido', 'cancelar_visita' => 'visita cancelada'];
        $linhas = $todas->map(function ($m) use ($verbo) {
            $quando = $m->inicio ? substr($m->inicio, 8, 2).'/'.substr($m->inicio, 5, 2).' '.substr($m->inicio, 11).' às '.substr((string) $m->fim, 11) : '';
            $numero = $m->resultado['numero'] ?? $m->atendimento;

            return $m->situacao === 'feito'
                ? '• ✅ '.($verbo[$m->tipo] ?? $m->tipo).($numero ? ' no nº '.$numero : '').($quando ? ', '.$quando : '')
                : '• ❌ '.($verbo[$m->tipo] ?? $m->tipo).($numero ? ' no nº '.$numero : '').': não gravou ('.$m->erro.')';
        });
        $passado = $todas->contains(fn ($m) => $m->situacao === 'feito' && $m->inicio && substr($m->inicio, 0, 10) < now(config('app.timezone'))->toDateString());
        $texto = "Agenda no Gestor:\n".$linhas->implode("\n")
            .($passado ? "\nAs visitas de dia que já passou entram agendadas e o Gestor as finaliza às 23:55." : '');
        try {
            $canal->enviar($todas->first()->conversa, $texto, 'pedro');
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

        foreach ($paradas as $i => $p) {
            $n = 'Parada '.($i + 1);
            $visita = $p['visita_id'] ? $this->diretorio->visita($p['visita_id']) : null;
            $atendimento = $p['atendimento'] ? $this->diretorio->atendimentoPorNumero($p['atendimento']) : null;
            $quando = $p['data'] && $p['hora_inicio'] ? substr($p['data'], 8, 2).'/'.substr($p['data'], 5, 2).' '.$p['hora_inicio'] : null;
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
                $problemas[] = "{$n}: o atendimento nº ".($p['atendimento'] ?: '?').' não foi achado no Gestor.';
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
                    $problemas[] = "{$t['n']} e ".mb_strtolower($o['n']).": o mesmo técnico em dois lugares no mesmo horário.";
                }
            }
        }
        // Visita do técnico no dia que o relato não menciona.
        foreach (collect($tempos)->map(fn ($t) => $t['tecnico'].'|'.$t['data'])->unique() as $chave) {
            [$tecnico, $data] = explode('|', $chave);
            foreach ($r['visitas'] ?? [] as $v) {
                if ((int) ($v['tecnico_id'] ?? 0) === (int) $tecnico && str_starts_with((string) $v['inicio'], $data) && $v['situacao'] !== 'cancelado'
                    && ! in_array($v['id'], $mexidas, true) && ! collect($problemas)->contains(fn ($p) => str_contains($p, 'das '.substr((string) $v['inicio'], 11, 5).' às'))) {
                    $a = $this->diretorio->atendimentoPorId((int) $v['atendimento_id']);
                    $avisos[] = "{$nome((int) $tecnico)} tem visita em ".CarbonImmutable::parse($data)->format('d/m').' das '.substr((string) $v['inicio'], 11, 5).' às '.substr((string) ($v['fim'] ?? ''), 11, 5)
                        .($a ? ' (nº '.$a['numero'].')' : '').' que o relato não menciona: ela continua como está?';
                }
            }
        }

        return [array_values(array_unique($problemas)), array_values(array_unique($avisos))];
    }
}
