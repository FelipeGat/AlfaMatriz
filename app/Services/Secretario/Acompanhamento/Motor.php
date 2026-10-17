<?php

namespace App\Services\Secretario\Acompanhamento;

use App\Models\Perfil;
use App\Models\SecretarioAcompanhamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Roda as regras ligadas e mantém os acompanhamentos (FR-001, FR-003): o que
 * a regra achou vira (ou continua) uma linha por chave; o que ela deixou de
 * achar fica resolvido; regra desligada encerra os seus. "Resolvido" pela
 * pessoa (dispensado) continua calado enquanto a situação valer.
 */
class Motor
{
    public function __construct(private Achados $achados) {}

    /** As contas com o Secretário ligado: as mesmas portas do briefing. */
    public function contas(): Collection
    {
        $perfilId = Perfil::where('slug', 'secretario')->value('id');

        return $perfilId ? User::whereHas('perfis', fn ($q) => $q->whereKey($perfilId))->get()
            ->filter(fn (User $u) => $u->ativo && ! $u->temEscopoDeRevenda() && $u->canPermissao('secretario', 'ler'))->values() : collect();
    }

    /** @return int quantos ativos a conta tem depois da rodada */
    public function rodar(User $user, CarbonImmutable $agora): int
    {
        $config = Regras::de($user);
        foreach ($config['regras'] as $regra => $r) {
            if (! $r['ligada']) {
                SecretarioAcompanhamento::de($user)->where('regra', $regra)->whereIn('situacao', [SecretarioAcompanhamento::ATIVO, SecretarioAcompanhamento::DISPENSADO])
                    ->update(['situacao' => SecretarioAcompanhamento::RESOLVIDO, 'resolvido_em' => $agora]);

                continue;
            }
            try {
                $achou = $this->achados->da($regra, $user, (int) $r['valor'], $agora);
            } catch (\Throwable $e) {
                // Uma regra com defeito não derruba as outras nem resolve o que já existia.
                report($e);

                continue;
            }
            $this->aplicar($user, $regra, $r['prioridade'], $achou['achados'], $achou['intocaveis'], $agora);
        }

        return SecretarioAcompanhamento::de($user)->where('situacao', SecretarioAcompanhamento::ATIVO)->count();
    }

    private function aplicar(User $user, string $regra, string $prioridade, array $achados, array $intocaveis, CarbonImmutable $agora): void
    {
        $chaves = [];
        foreach ($achados as $a) {
            $chaves[] = $a['chave'];
            DB::transaction(function () use ($user, $regra, $prioridade, $a, $agora) {
                $linha = SecretarioAcompanhamento::de($user)->where('chave', $a['chave'])->lockForUpdate()->first();
                $dados = ['prioridade' => $prioridade, 'texto' => $a['texto'], 'link' => $a['link'], 'referencia_em' => $a['referencia_em']];
                if ($linha && $linha->situacao === SecretarioAcompanhamento::RESOLVIDO) {
                    // Deixou de valer e voltou: é situação nova (FR-003). A antiga libera a chave.
                    $linha->update(['chave' => mb_substr($linha->chave, 0, 170).'#'.$linha->id]);
                    $linha = null;
                }
                if ($linha) {
                    $linha->update($dados);

                    return;
                }
                try {
                    SecretarioAcompanhamento::create(['user_id' => $user->id, 'regra' => $regra, 'chave' => $a['chave']] + $dados);
                } catch (UniqueConstraintViolationException) {
                    // Outra rodada criou ao mesmo tempo: a linha já existe.
                }
            });
        }

        SecretarioAcompanhamento::de($user)->where('regra', $regra)
            ->whereIn('situacao', [SecretarioAcompanhamento::ATIVO, SecretarioAcompanhamento::DISPENSADO])
            ->whereNotIn('chave', $chaves ?: [''])
            ->where(function ($q) use ($intocaveis) {
                foreach ($intocaveis as $prefixo) {
                    // Sem escapar o `_`: no SQLite a barra só escapa com ESCAPE, e o `_`
                    // curinga também casa o `_` literal dos prefixos.
                    $q->where('chave', 'not like', $prefixo.'%');
                }
            })
            ->update(['situacao' => SecretarioAcompanhamento::RESOLVIDO, 'resolvido_em' => $agora]);
    }
}
