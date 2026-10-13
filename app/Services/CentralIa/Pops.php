<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaPop;
use App\Models\CentralIaPopVersao;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * POPs das IAs (dono, 12/10/2026): no agendamento do Gestor, ~4 dos ~5 min
 * foram o agente reaprendendo o sistema (tabelas, serviços, a ponte de
 * produção). O que ele aprende fica aqui; o próximo pedido do mesmo
 * contexto já começa sabendo.
 *
 * Sempre da conta: só o dono vê e edita na tela, e só as IAs que rodam pela
 * máquina dele recebem. Nenhum administrador, nenhum repositório.
 */
class Pops
{
    /** Teto do que vai a um prompt: POP é referência, não o histórico. */
    public const MAX_PROMPT = 20000;

    public function pronto(): bool
    {
        return Schema::hasTable('central_ia_pops');
    }

    /**
     * Os POPs que o pedido precisa: os do sistema do projeto e os dos
     * sistemas citados no texto ("…igual ao gestor", "no alfacontrol").
     */
    public function paraPedido(User $user, string $projeto, string $texto): array
    {
        if (! $this->pronto()) return [];
        $todos = CentralIaPop::where('user_id', $user->id)->orderBy('sistema')->orderBy('titulo')->get();
        $t = Str::lower(Str::ascii($texto));
        $usados = $todos->filter(fn ($p) => $p->sistema === $projeto
            || preg_match('/(^|[^a-z0-9])'.preg_quote(Str::lower(Str::ascii($p->sistema)), '/').'($|[^a-z0-9])/', $t));
        $saida = []; $total = 0;
        foreach ($usados as $p) {
            $total += mb_strlen($p->conteudo);
            if ($total > self::MAX_PROMPT) break;
            $saida[] = ['sistema' => $p->sistema, 'titulo' => $p->titulo, 'versao' => $p->versao, 'conteudo' => $p->conteudo];
        }

        return $saida;
    }

    /** Cria ou atualiza; a versão anterior fica guardada. */
    public function gravar(User $user, string $sistema, string $titulo, string $conteudo, string $autor, ?int $execucaoId = null): CentralIaPop
    {
        $sistema = Str::limit(Str::lower(trim($sistema)), 128, '');
        $titulo = Str::limit(trim($titulo), 160, '');
        $conteudo = mb_substr(ProtecaoDeSegredos::texto(trim($conteudo)), 0, 60000);
        abort_if($sistema === '' || $titulo === '' || $conteudo === '', 422, 'POP precisa de sistema, título e conteúdo.');

        return DB::transaction(function () use ($user, $sistema, $titulo, $conteudo, $autor, $execucaoId) {
            $pop = CentralIaPop::where('user_id', $user->id)->where('sistema', $sistema)->where('titulo', $titulo)->lockForUpdate()->first();
            if ($pop && $pop->conteudo === $conteudo) return $pop;
            if ($pop) {
                CentralIaPopVersao::create(['pop_id' => $pop->id, 'versao' => $pop->versao, 'conteudo' => $pop->conteudo, 'autor' => $pop->autor, 'execucao_id' => $pop->execucao_id]);
                $pop->update(['conteudo' => $conteudo, 'versao' => $pop->versao + 1, 'autor' => $autor, 'execucao_id' => $execucaoId]);
            } else {
                $pop = CentralIaPop::create(['user_id' => $user->id, 'sistema' => $sistema, 'titulo' => $titulo, 'conteudo' => $conteudo, 'versao' => 1, 'autor' => $autor, 'execucao_id' => $execucaoId]);
            }
            Eventos::registrar($user, 'pop_atualizado', "POP de {$sistema} \"{$titulo}\" ".($pop->versao > 1 ? "atualizado (versão {$pop->versao})" : 'criado')." por {$autor}",
                ['origem' => $execucaoId ? 'runner' : 'web', 'projeto' => $sistema, 'execucao_id' => $execucaoId]);

            return $pop;
        });
    }

    /** O que o agente devolveu no bloco: um POP ou uma lista de até três. */
    public function doBloco(User $user, mixed $pop, string $autor, int $execucaoId, string $projeto): void
    {
        if (! $this->pronto() || ! is_array($pop)) return;
        $lista = array_is_list($pop) ? array_slice($pop, 0, 3) : [$pop];
        foreach ($lista as $p) {
            if (! is_array($p) || ! is_string($p['titulo'] ?? null) || ! is_string($p['conteudo'] ?? null)) continue;
            // Num projeto, o agente só escreve o POP DESTE sistema: um POP
            // gravado em outro seria injetado nos pedidos de lá (revisão 13/10/2026).
            $sistema = $projeto !== '' ? $projeto : (is_string($p['sistema'] ?? null) ? $p['sistema'] : '');
            if (trim($sistema) === '') continue;
            $this->gravar($user, $sistema, $p['titulo'], $p['conteudo'], $autor, $execucaoId);
        }
    }

    public function lista(User $user): array
    {
        if (! $this->pronto()) return [];

        return CentralIaPop::where('user_id', $user->id)->withCount('versoes')->orderBy('sistema')->orderBy('titulo')->get()
            ->map(fn ($p) => ['id' => $p->id, 'sistema' => $p->sistema, 'titulo' => $p->titulo, 'conteudo' => $p->conteudo, 'versao' => $p->versao,
                'autor' => $p->autor, 'atualizado_em' => $p->updated_at?->toIso8601String(), 'versoes' => $p->versoes_count])->all();
    }
}
