<?php

namespace App\Services\CentralIa;

use App\Models\{CentralIaExecucao, CentralIaPreferencia, User};
use Illuminate\Support\Facades\DB;

/**
 * Limites da Central de outras contas — só contagens, nunca conversas nem
 * credenciais.
 *
 * Quem administra é quem tem o perfil Administrador E usa a Central (T-374,
 * dono do produto, 14/10/2026: "nada pode estar preso à máquina do Felipe; a
 * Central de IA é para um time"). Antes era um e-mail no `.env`
 * (o "dono" da Central): a governança ficava presa a uma pessoa, e trocar quem
 * cuida exigia mexer no servidor. Admin administra qualquer conta, inclusive
 * a de outro admin (T-368: "admin precisa ter acesso total a tudo"); só não
 * administra a própria, que é dos Ajustes dela.
 */
class Governanca
{
    public function administra(User $user): bool
    {
        return $user->ativo && ! $user->temEscopoDeRevenda() && $user->ehAdmin() && $user->canPermissao('central_ia', 'ler');
    }

    public function usuarios(User $admin): array
    {
        if (! $this->administra($admin)) return [];
        // Só quem usa a Central: a lista de todas as contas do sistema enchia o
        // seletor de gente que nunca vai ter política para administrar.
        // Numa consulta só, e não `canPermissao` conta a conta: o painel chama
        // isto a cada ~5 s, e era uma consulta por conta do sistema a cada vez
        // (revisão da T-374). A regra é a mesma do `canPermissao`: basta UM
        // perfil da conta conceder `ler` em `central_ia`.
        return User::query()->where('id', '!=', $admin->id)
            ->whereHas('perfis.permissoes', fn ($q) => $q->where('permissoes.recurso', 'central_ia')->where('perfil_permissao.ler', true))
            ->orderBy('name')->get(['id', 'name', 'ativo'])
            ->map(fn ($u) => ['id' => $u->id, 'nome' => $u->name, 'ativo' => (bool) $u->ativo])->all();
    }

    public function padrao(): array
    {
        // O total nasce sem teto (T-368): quem limita são os tetos por IA. Com 0
        // aqui, liberar a conta e dar "Claude 1/dia" sem mexer no total deixava
        // a pessoa sem nenhum pedido, sem aviso nenhum.
        return ['revisao' => 0, 'habilitados' => ['claude' => false, 'codex' => false, 'copilot' => false],
            'pedidos_dia' => ['claude' => 0, 'codex' => 0, 'copilot' => 0], 'limite_total' => null, 'paralelo' => 1,
            // Não há executor com ACL comprovada nesta versão. Não existe opção
            // de "liberar" pelo navegador que finja que o isolamento foi feito.
            'execucao_bloqueada' => true, 'deploy' => false, 'onedrive' => false];
    }

    public function execucaoBloqueada(User $user, ?array $politica = null): bool
    {
        // Quem administra não é bloqueado por política. Os demais nascem
        // bloqueados até um administrador liberar pela tela: cada um executa
        // na própria máquina, cujo runner impõe os limites locais (ver
        // deploy/central-ia/README.md), mas quem decide liberar é quem
        // administra a Central.
        if ($this->administra($user)) return false;
        return $politica === null || ($politica['execucao_bloqueada'] ?? true);
    }

    public function politica(User $user): ?array
    {
        if (! app(Preferencias::class)->pronta()) return null;
        $r = CentralIaPreferencia::where('user_id', $user->id)->value('regras');
        return is_array($r) && isset($r['governanca']) ? $r['governanca'] : null;
    }

    public function resumo(User $admin, User $user): array
    {
        abort_unless($this->administra($admin), 403);
        abort_if($user->id === $admin->id, 404);
        $uso = [];
        foreach (array_keys(CentralIaExecucao::AGENTES) as $a) $uso[$a] = app(Preferencias::class)->usados($user, $a);
        $execucoes = CentralIaExecucao::where('user_id', $user->id)->latest('id')->limit(100)
            ->get(['id', 'agente', 'modo', 'status', 'created_at', 'iniciada_em', 'concluida_em'])
            ->map(fn ($e) => ['id' => $e->id, 'agente' => CentralIaExecucao::AGENTES[$e->agente] ?? $e->agente,
                'modo' => $e->modo, 'status' => $e->status, 'em' => $e->created_at->toIso8601String(),
                'iniciada_em' => $e->iniciada_em?->toIso8601String(), 'concluida_em' => $e->concluida_em?->toIso8601String()])->all();
        $eventos = DB::table('central_ia_eventos')->where('user_id', $user->id)->latest('id')->limit(100)
            ->get(['id', 'tipo', 'nivel', 'created_at'])->map(fn ($e) => ['id' => $e->id, 'tipo' => $e->tipo,
                'nivel' => $e->nivel, 'em' => \Illuminate\Support\Carbon::parse($e->created_at)->toIso8601String()])->all();
        return ['usuario' => ['id' => $user->id, 'nome' => $user->name, 'ativo' => (bool) $user->ativo],
            'politica' => $this->politica($user) ?? $this->padrao(), 'configurada' => $this->politica($user) !== null,
            'uso_hoje' => $uso, 'execucoes' => $execucoes, 'eventos' => $eventos];
    }
}
