<?php

namespace App\Services\Secretario;

use App\Models\SecretarioItem;
use App\Models\SecretarioReuniao;
use App\Models\SecretarioReuniaoProposta;
use App\Models\User;
use App\Services\CentralIa\Eventos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A revisão da reunião (spec US2, FR-008): só a aprovação da pessoa cria o
 * item do Secretário, uma vez por proposta. Decisões são registro (não viram
 * item); tarefas e pendências viram, quando aprovadas.
 */
class RevisaoDeReuniao
{
    public const APROVAVEIS = ['tarefa', 'pendencia'];

    public function __construct(private Despachante $despachante) {}

    public function aprovar(User $user, SecretarioReuniaoProposta $proposta): SecretarioReuniaoProposta
    {
        $p = DB::transaction(function () use ($user, $proposta) {
            $p = SecretarioReuniaoProposta::where('user_id', $user->id)->whereKey($proposta->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($p->categoria, self::APROVAVEIS, true), 422, 'Decisão é registro; só tarefa e pendência viram item.');
            if ($p->status !== 'pendente') {
                return $p;
            }
            $reuniao = SecretarioReuniao::de($user)->findOrFail($p->reuniao_id);
            [$quando, $soData] = $this->prazo($p->prazo);
            $item = SecretarioItem::create([
                'user_id' => $user->id,
                'tipo' => $p->categoria === 'pendencia' ? 'acompanhamento' : ($quando && ! $soData ? 'lembrete' : 'tarefa'),
                'titulo' => Str::limit($p->texto, 200, '…'),
                'descricao' => 'Da reunião “'.$reuniao->titulo.'” (trechos '.implode(', ', (array) $p->evidencias).')'
                    .($p->responsavel ? '. Responsável: '.$p->responsavel : '').'.',
                'quando' => $quando, 'so_data' => $soData,
                'prioridade' => $p->prioridade, 'origem' => 'reuniao', 'origem_ref' => 'reuniao:'.$reuniao->id.':'.$p->id,
            ]);
            $this->despachante->programar($item);
            $p->update(['status' => 'aprovada', 'item_id' => $item->id]);
            Eventos::registrar($user, 'secretario_reuniao_aprovada', "Reunião #{$reuniao->id}: proposta #{$p->id} aprovada (item #{$item->id}).", ['origem' => 'web']);

            return $p;
        });
        $this->fecharSeRevisada($user, $p->reuniao_id);

        return $p;
    }

    /** Em lote: só as pendentes e que não são sugestão nem hipótese (spec US2, cenário 5). */
    public function aprovarLote(User $user, SecretarioReuniao $reuniao): int
    {
        $n = 0;
        foreach (SecretarioReuniaoProposta::where('user_id', $user->id)->where('reuniao_id', $reuniao->id)->where('status', 'pendente')
            ->whereIn('categoria', self::APROVAVEIS)->whereNotIn('classificacao', SecretarioReuniaoProposta::FORA_DO_LOTE)->get() as $p) {
            $this->aprovar($user, $p);
            $n++;
        }

        return $n;
    }

    public function descartar(User $user, SecretarioReuniaoProposta $proposta): void
    {
        SecretarioReuniaoProposta::where('user_id', $user->id)->whereKey($proposta->id)->where('status', 'pendente')->update(['status' => 'descartada']);
        $this->fecharSeRevisada($user, $proposta->reuniao_id);
    }

    /** Completar antes de aprovar: texto, responsável, prazo e prioridade. */
    public function editar(User $user, SecretarioReuniaoProposta $proposta, array $dados): SecretarioReuniaoProposta
    {
        $p = SecretarioReuniaoProposta::where('user_id', $user->id)->whereKey($proposta->id)->where('status', 'pendente')->firstOrFail();
        $p->update(array_intersect_key($dados, array_flip(['texto', 'responsavel', 'prazo', 'prioridade'])));

        return $p;
    }

    /** "AAAA-MM-DD" vira prazo do dia (aviso 08:00); "AAAA-MM-DD HH:MM", lembrete na hora. */
    private function prazo(?string $prazo): array
    {
        if (! $prazo) {
            return [null, false];
        }
        $soData = strlen($prazo) === 10;
        $q = CarbonImmutable::parse($prazo, config('app.timezone'));

        return [$soData ? $q->startOfDay() : $q, $soData];
    }

    private function fecharSeRevisada(User $user, int $reuniaoId): void
    {
        $abertas = SecretarioReuniaoProposta::where('user_id', $user->id)->where('reuniao_id', $reuniaoId)
            ->whereIn('categoria', self::APROVAVEIS)->where('status', 'pendente')->exists();
        if (! $abertas) {
            SecretarioReuniao::de($user)->whereKey($reuniaoId)->where('status', SecretarioReuniao::PRONTA)->update(['status' => SecretarioReuniao::REVISADA]);
        }
    }
}
