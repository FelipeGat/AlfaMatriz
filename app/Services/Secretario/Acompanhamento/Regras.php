<?php

namespace App\Services\Secretario\Acompanhamento;

use App\Models\SecretarioAcompanhamento;
use App\Models\SecretarioPreferencia;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * As sete regras e a política de envio, com os padrões e as faixas válidas
 * (docs/specs/secretario-acompanhamento, research D1, D3 e D4). A regra é
 * fixa no código; o que a pessoa muda é liga/desliga, o parâmetro e a
 * prioridade — a IA não decide quando avisar (Fase 8.1).
 */
class Regras
{
    /** regra => [rótulo, unidade, padrão, mínimo, máximo, prioridade padrão, ligada padrão] */
    public const REGRAS = [
        'tarefa_vencida' => ['Tarefa vencida e aberta', 'horas depois do prazo', 2, 1, 72, 'normal', true],
        'reuniao_proxima' => ['Reunião do Google chegando', 'minutos antes', 30, 5, 180, 'alta', false],
        'proposta_parada' => ['Proposta aguardando retorno', 'dias sem resposta', 3, 1, 30, 'normal', true],
        'cliente_sem_resposta' => ['Cliente sem resposta', 'horas sem resposta', 24, 2, 168, 'normal', true],
        'revisao_de_reuniao' => ['Reunião com tarefas sem revisão', 'horas depois da análise', 24, 1, 168, 'normal', true],
        'conflito' => ['Conflito de compromissos', 'dias à frente', 2, 1, 7, 'alta', true],
        'entrega_proxima' => ['Entrega prometida próxima', 'dias à frente', 1, 1, 7, 'normal', true],
    ];

    /** política => [rótulo, padrão] */
    public const POLITICA = [
        'silencio_inicio' => ['Silêncio a partir de', '20:00'],
        'silencio_fim' => ['Silêncio até', '07:00'],
        'fim_de_semana' => ['Fim de semana em silêncio', true],
        'intervalo_h' => ['Intervalo mínimo entre resumos (horas)', 3],
        'teto_dia' => ['Máximo de resumos por dia', 4],
        'repetir_h' => ['Repetir a mesma situação a cada (horas)', 24],
        'repeticoes' => ['Máximo de avisos da mesma situação', 3],
    ];

    /** @return array{regras: array<string, array{ligada: bool, valor: int, prioridade: string}>, silencio_inicio: string, silencio_fim: string, fim_de_semana: bool, intervalo_h: int, teto_dia: int, repetir_h: int, repeticoes: int} */
    public static function padroes(): array
    {
        $regras = [];
        foreach (self::REGRAS as $chave => [, , $valor, , , $prioridade, $ligada]) {
            $regras[$chave] = ['ligada' => $ligada, 'valor' => $valor, 'prioridade' => $prioridade];
        }

        return ['regras' => $regras] + array_map(fn ($p) => $p[1], self::POLITICA);
    }

    /** O que vale para a pessoa: o gravado por cima dos padrões (regra nova entra com o padrão). */
    public static function de(User $user): array
    {
        $gravado = (array) (SecretarioPreferencia::de($user)->acompanhamento ?? []);
        $c = self::padroes();
        foreach ($c['regras'] as $chave => $r) {
            $c['regras'][$chave] = array_replace($r, array_intersect_key((array) ($gravado['regras'][$chave] ?? []), $r));
        }
        foreach (array_keys(self::POLITICA) as $k) {
            if (array_key_exists($k, $gravado)) {
                $c[$k] = $gravado[$k];
            }
        }

        return $c;
    }

    /** Regras de validação do formulário (FR-002): cada parâmetro na sua faixa. */
    public static function validacao(): array
    {
        $v = [];
        foreach (self::REGRAS as $chave => [, , , $min, $max]) {
            $v["regras.$chave.ligada"] = ['sometimes', 'boolean'];
            $v["regras.$chave.valor"] = ['required', 'integer', "min:$min", "max:$max"];
            $v["regras.$chave.prioridade"] = ['required', Rule::in(SecretarioAcompanhamento::PRIORIDADES)];
        }

        return $v + [
            'silencio_inicio' => ['required', 'date_format:H:i'],
            'silencio_fim' => ['required', 'date_format:H:i'],
            'fim_de_semana' => ['sometimes', 'boolean'],
            'intervalo_h' => ['required', 'integer', 'min:1', 'max:24'],
            'teto_dia' => ['required', 'integer', 'min:1', 'max:12'],
            'repetir_h' => ['required', 'integer', 'min:4', 'max:168'],
            'repeticoes' => ['required', 'integer', 'min:1', 'max:10'],
        ];
    }

    /** Converte o validado no formato gravado (checkbox ausente = desligado). */
    public static function normalizar(array $dados): array
    {
        $c = ['regras' => []];
        foreach (array_keys(self::REGRAS) as $chave) {
            $r = $dados['regras'][$chave] ?? [];
            $c['regras'][$chave] = ['ligada' => (bool) ($r['ligada'] ?? false), 'valor' => (int) $r['valor'], 'prioridade' => (string) $r['prioridade']];
        }
        foreach (array_keys(self::POLITICA) as $k) {
            $c[$k] = match ($k) {
                'silencio_inicio', 'silencio_fim' => (string) $dados[$k],
                'fim_de_semana' => (bool) ($dados[$k] ?? false),
                default => (int) $dados[$k],
            };
        }

        return $c;
    }
}
