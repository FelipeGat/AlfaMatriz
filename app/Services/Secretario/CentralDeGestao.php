<?php

namespace App\Services\Secretario;

use App\Models\SecretarioAcompanhamento;
use App\Models\SecretarioEmail;
use App\Models\SecretarioGestorRetrato;
use App\Models\SecretarioItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A Central de gestão em Acompanhamentos (docs/specs/secretario-central-do-dia,
 * FR-003/FR-004): os números do dia e um bloco por empresa — o Grupo Soluções
 * pelo retrato do Gestor, a VixCard (ainda sem sistema) pelos pontos que o
 * dono lança. Tudo da própria conta, sem IA.
 */
class CentralDeGestao
{
    public const EMPRESAS = ['grupo_solucoes' => 'Grupo Soluções', 'vixcard' => 'VixCard'];

    public function __construct(private AgendaConsolidada $agenda) {}

    /** @return array{compromissos: int, nao_lidos: int, vencidas: int, alertas: int} */
    public function numeros(User $user, CarbonImmutable $agora): array
    {
        $hoje = $this->agenda->periodo($user, $agora->startOfDay(), $agora->endOfDay())
            ->reject(fn ($i) => $i['origem'] === 'secretario' && $i['dia_inteiro']);

        return [
            'compromissos' => $hoje->count(),
            'nao_lidos' => SecretarioEmail::de($user)->where('enviado', false)->where('lido', false)
                ->where('recebido_em', '>=', $agora->subDays(\App\Services\Secretario\Email\LeitorDeEmails::DIAS_NAO_LIDOS)->startOfDay())->count(),
            'vencidas' => $this->vencidas($user, $agora)->count(),
            'alertas' => SecretarioAcompanhamento::de($user)->where('situacao', SecretarioAcompanhamento::ATIVO)->count(),
        ];
    }

    /** O retrato do Gestor, bloco a bloco, com a hora; null se a máquina ainda não leu. */
    public function grupoSolucoes(User $user): ?array
    {
        $r = SecretarioGestorRetrato::where('user_id', $user->id)->first();
        if (! $r) {
            return null;
        }
        $blocos = [];
        foreach (SecretarioGestorRetrato::BLOCOS as $chave => $rotulo) {
            $b = $r->dados['blocos'][$chave] ?? null;
            if ($b) {
                $itens = collect($b['itens'] ?? [])->sortBy(fn ($i) => $i['data'] ?? '9999')->take(5)->values()->all();
                $blocos[$chave] = ['rotulo' => $rotulo, 'quantidade' => (int) $b['quantidade'], 'valor' => $b['valor'] ?? null, 'itens' => $itens];
            }
        }

        return ['lido_em' => $r->lido_em, 'velho' => $r->velho(), 'blocos' => $blocos];
    }

    /** @return Collection<int, SecretarioItem> pontos lançados à mão, abertos, da empresa */
    public function pontos(User $user, string $empresa): Collection
    {
        return SecretarioItem::de($user)->where('empresa', $empresa)->where('status', SecretarioItem::ABERTO)
            ->orderByRaw('quando is null')->orderBy('quando')->orderBy('id')->get();
    }

    public function vencidas(User $user, CarbonImmutable $agora): Collection
    {
        return SecretarioItem::de($user)->where('status', SecretarioItem::ABERTO)->whereIn('tipo', ['tarefa', 'acompanhamento'])
            ->whereNotNull('quando')->where('quando', '<', $agora)->get()
            ->filter(fn ($i) => ($i->so_data ? CarbonImmutable::instance($i->quando)->endOfDay() : CarbonImmutable::instance($i->quando))->lessThan($agora))->values();
    }

    public static function atrasado(SecretarioItem $i, ?CarbonImmutable $agora = null): bool
    {
        $agora ??= CarbonImmutable::now();

        return $i->quando !== null && ($i->so_data ? CarbonImmutable::instance($i->quando)->endOfDay() : CarbonImmutable::instance($i->quando))->lessThan($agora);
    }
}
