<?php

namespace App\Models\Scopes;

use App\Models\Compromisso;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Acesso VixCard (docs/specs/acesso-vixcard): quem tem o perfil `vixcard`
 * só enxerga, em QUALQUER consulta da sessão, o que é da VixCard — tarefas de
 * sistema de natureza `vixcard` (e o que pende delas), esses sistemas e os
 * compromissos ligados a eles ou dele.
 *
 * Global, e não filtro por tela, de propósito: o quadro e a Agenda consultam
 * em dezenas de pontos (lista, modal, histórico, binding por id, mãe e
 * subtarefas), e um esquecido seria um vazamento. Sem usuário na sessão (fila,
 * console, webhook) ou sem o perfil, não filtra nada (FR-006).
 *
 * `exists:` de validação NÃO passa por aqui (é consulta crua): quem grava
 * confere com `conferir()`.
 */
class EscopoVixcard implements Scope
{
    public const PERFIL = 'vixcard';

    /** Quem está com o escopo nesta sessão, ou null. */
    public static function usuario(): ?User
    {
        // `hasUser`, e não `user()`: não força resolver a sessão de dentro de
        // uma consulta (o próprio User é resolvido por consulta).
        if (! Auth::hasUser()) {
            return null;
        }
        $u = Auth::user();

        return $u instanceof User && $u->temEscopoVixcard() ? $u : null;
    }

    public function apply(Builder $builder, Model $model): void
    {
        $u = self::usuario();
        if (! $u) {
            return;
        }
        $tabela = $model->getTable();

        match (true) {
            $model instanceof Sistema => $builder->where("{$tabela}.natureza", 'vixcard'),
            $model instanceof Tarefa => $builder->whereIn("{$tabela}.sistema_id", self::sistemas()),
            $model instanceof Compromisso => $builder->where(fn (Builder $q) => $q
                ->whereIn("{$tabela}.tarefa_id", self::tarefas())
                ->orWhereIn("{$tabela}.sistema_id", self::sistemas())
                ->orWhere("{$tabela}.criado_por_id", $u->id)
                ->orWhereExists(fn ($p) => $p->selectRaw('1')->from('compromisso_participantes')
                    ->whereColumn('compromisso_participantes.compromisso_id', "{$tabela}.id")
                    ->where('compromisso_participantes.user_id', $u->id))),
            // Itens, anexos, comentários, entregas, eventos, referências git e
            // relatórios de teste: valem pela tarefa dona.
            default => $builder->whereIn("{$tabela}.tarefa_id", self::tarefas()),
        };
    }

    /** Subconsulta dos sistemas VixCard (sem escopo, para não recursar). */
    public static function sistemas()
    {
        return Sistema::withoutGlobalScopes()->where('natureza', 'vixcard')->select('id');
    }

    /** Subconsulta das tarefas VixCard, inclusive arquivadas e apagadas — o filtro é só de dono. */
    public static function tarefas()
    {
        return Tarefa::withoutGlobalScopes()->whereIn('sistema_id', self::sistemas())->select('id');
    }

    /**
     * As pessoas que a conta VixCard pode ver e escolher: quem responde, abriu
     * ou conversa numa tarefa VixCard, quem participa de compromisso visível
     * a ela, e quem tem o perfil. Nunca a lista inteira da casa (FR-004).
     *
     * @return list<int>
     */
    public static function pessoas(User $u): array
    {
        $tarefas = Tarefa::withoutGlobalScopes()->whereIn('sistema_id', self::sistemas());
        $ids = collect()
            ->merge((clone $tarefas)->whereNotNull('responsavel_id')->pluck('responsavel_id'))
            ->merge((clone $tarefas)->whereNotNull('criado_por_id')->pluck('criado_por_id'))
            ->merge(Compromisso::query()->with('participantes:id')->get()->flatMap(fn ($c) => $c->participantes->pluck('id')->push($c->criado_por_id)))
            ->merge(User::whereHas('perfis', fn ($q) => $q->where('slug', self::PERFIL))->pluck('id'))
            ->push($u->id);

        return $ids->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Confere, para quem está no escopo, os ids que o formulário manda e que o
     * `exists:` aprovou olhando o banco inteiro (FR-002). Sem escopo, não faz nada.
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function conferir(array $dados, bool $exigeSistema = false): void
    {
        $u = self::usuario();
        if (! $u) {
            return;
        }
        $erros = [];
        if (array_key_exists('sistema_id', $dados) || $exigeSistema) {
            if (blank($dados['sistema_id'] ?? null) || ! Sistema::whereKey($dados['sistema_id'])->exists()) {
                $erros['sistema_id'] = 'Escolha um dos produtos da VixCard.';
            }
        }
        if (filled($dados['tarefa_pai_id'] ?? null) && ! Tarefa::whereKey($dados['tarefa_pai_id'])->exists()) {
            $erros['tarefa_pai_id'] = 'Tarefa não encontrada.';
        }
        if (filled($dados['tarefa_id'] ?? null) && ! Tarefa::whereKey($dados['tarefa_id'])->exists()) {
            $erros['tarefa_id'] = 'Tarefa não encontrada.';
        }
        if (filled($dados['compromisso_id'] ?? null) && ! Compromisso::whereKey($dados['compromisso_id'])->exists()) {
            $erros['compromisso_id'] = 'Compromisso não encontrado.';
        }
        $pessoas = null;
        foreach (['responsavel_id', 'interlocutor_id'] as $campo) {
            if (filled($dados[$campo] ?? null) && ! in_array((int) $dados[$campo], $pessoas ??= self::pessoas($u), true)) {
                $erros[$campo] = 'Pessoa fora dos projetos da VixCard.';
            }
        }
        foreach ((array) ($dados['participantes'] ?? []) as $id) {
            if (! in_array((int) $id, $pessoas ??= self::pessoas($u), true)) {
                $erros['participantes'] = 'Há participante fora dos projetos da VixCard.';
            }
        }
        if ($erros) {
            throw \Illuminate\Validation\ValidationException::withMessages($erros);
        }
    }
}
