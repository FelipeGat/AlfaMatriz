<?php

namespace App\Services\Secretario;

use App\Models\Compromisso;
use App\Models\SecretarioGoogleConta;
use App\Models\SecretarioGoogleEvento;
use App\Models\SecretarioItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A agenda num lugar só (spec US2, FR-009–FR-011): o Secretário da pessoa,
 * os compromissos do time EM QUE ELA É PARTICIPANTE e os eventos das agendas
 * Google que ela marcou. Nada de outra conta entra aqui.
 */
class AgendaConsolidada
{
    public const DESATUALIZADO_MIN = 30;
    public const LIVRE_DE = '08:00';
    public const LIVRE_ATE = '18:00';
    public const LIVRE_MIN = 30;
    /** Compromisso do Secretário sem fim informado ocupa uma hora. */
    public const DURACAO_PADRAO_MIN = 60;

    /**
     * @return Collection<int, array{origem: string, origem_rotulo: string, titulo: string, inicio: CarbonImmutable, fim: ?CarbonImmutable, dia_inteiro: bool, link: ?string, ocupa: bool, conflito: bool, ref: string}>
     */
    public function periodo(User $user, CarbonImmutable $de, CarbonImmutable $ate): Collection
    {
        $itens = collect();
        $doGoogle = [];

        foreach (SecretarioGoogleEvento::where('user_id', $user->id)->where('inicio', '<', $ate)->where('fim', '>', $de)
            ->whereHas('agenda', fn ($q) => $q->where('incluida', true))->with('agenda')->orderBy('inicio')->limit(1000)->get() as $e) {
            $doGoogle[$e->agenda_id.':'.$e->google_id] = true;
            $itens->push([
                'origem' => 'google', 'origem_rotulo' => 'Google · '.$e->agenda->nome, 'titulo' => $e->titulo,
                'inicio' => CarbonImmutable::instance($e->inicio), 'fim' => CarbonImmutable::instance($e->fim),
                'dia_inteiro' => $e->dia_inteiro, 'link' => $e->link, 'ocupa' => ! $e->dia_inteiro, 'ref' => 'google:'.$e->id,
            ]);
        }

        foreach (SecretarioItem::de($user)->where('status', SecretarioItem::ABERTO)->whereNotNull('quando')
            ->whereBetween('quando', [$de, $ate])->orderBy('quando')->limit(500)->get() as $i) {
            // O evento que o próprio Secretário criou no Google aparece uma vez só (FR-015).
            if ($i->origem === 'google' && preg_match('/^google:(\d+):(.+)$/', (string) $i->origem_ref, $m) && isset($doGoogle[$m[1].':'.$m[2]])) {
                continue;
            }
            $inicio = CarbonImmutable::instance($i->quando);
            $compromisso = $i->tipo === 'compromisso' && ! $i->so_data;
            $itens->push([
                'origem' => 'secretario', 'origem_rotulo' => 'Secretário · '.ucfirst($i->tipo), 'titulo' => $i->titulo,
                'inicio' => $inicio, 'fim' => $compromisso ? $inicio->addMinutes(self::DURACAO_PADRAO_MIN) : null,
                'dia_inteiro' => $i->so_data, 'link' => null, 'ocupa' => $compromisso, 'ref' => 'secretario:'.$i->id, 'empresa' => $i->empresa,
            ]);
        }

        if ($user->canPermissao('agenda', 'ler')) {
            foreach (Compromisso::naFaixa(\Illuminate\Support\Carbon::instance($de), \Illuminate\Support\Carbon::instance($ate))
                ->deParticipantes([$user->id])->limit(500)->get() as $c) {
                // A faixa do modelo é por DIA; aqui vale a hora.
                if (! $c->comecaEm()->lessThan($ate) || ! $c->terminaEm()->greaterThan($de)) {
                    continue;
                }
                $itens->push([
                    'origem' => 'matriz', 'origem_rotulo' => 'Agenda do Matriz', 'titulo' => $c->titulo,
                    'inicio' => CarbonImmutable::instance($c->comecaEm()), 'fim' => CarbonImmutable::instance($c->terminaEm()),
                    'dia_inteiro' => false, 'link' => null, 'ocupa' => true, 'ref' => 'matriz:'.$c->id,
                ]);
            }
        }

        // O mesmo compromisso lançado no Matriz E no Google (mesmo título e mesmo início) é um só, e não
        // conflito consigo mesmo — 22/10/2026, "Testes da esteira Serrapark" acusado como conflito.
        // Fica o do Matriz, que é o que a Agenda mostra.
        $doMatriz = $itens->where('origem', 'matriz')->map(fn ($i) => $this->mesmo($i))->flip();
        $itens = $itens->reject(fn ($i) => $i['origem'] === 'google' && $doMatriz->has($this->mesmo($i)));

        $itens = $itens->sortBy(fn ($i) => ($i['dia_inteiro'] ? '0' : '1').$i['inicio']->format('Y-m-d H:i'))->values();

        return $this->marcarConflitos($itens);
    }

    private function mesmo(array $i): string
    {
        return \App\Services\Secretario\Memoria\BuscaNaMemoria::normalizar($i['titulo']).'|'.$i['inicio']->setTimezone(config('app.timezone'))->format('YmdHi');
    }

    /** Sobreposição [início, fim) entre itens que ocupam horário; encostar não é conflito. */
    public function marcarConflitos(Collection $itens): Collection
    {
        $lista = $itens->map(fn ($i) => $i + ['conflito' => false])->all();
        $n = count($lista);
        for ($a = 0; $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                $x = $lista[$a];
                $y = $lista[$b];
                if ($x['ocupa'] && $y['ocupa'] && $x['inicio']->lessThan($y['fim']) && $y['inicio']->lessThan($x['fim'])) {
                    $lista[$a]['conflito'] = $lista[$b]['conflito'] = true;
                }
            }
        }

        return collect($lista);
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> livres de 30 min ou mais entre 08:00 e 18:00 */
    public function livres(Collection $itensDoDia, CarbonImmutable $dia): array
    {
        $cursor = $dia->setTimeFromTimeString(self::LIVRE_DE);
        $fimDoDia = $dia->setTimeFromTimeString(self::LIVRE_ATE);
        $ocupados = $itensDoDia->filter(fn ($i) => $i['ocupa'])->map(fn ($i) => [$i['inicio'], $i['fim']])
            ->sortBy(fn ($o) => $o[0]->getTimestamp())->values();
        $livres = [];
        foreach ($ocupados as [$ini, $fim]) {
            if ($ini->greaterThan($cursor) && $cursor->diffInMinutes(min($ini, $fimDoDia)) >= self::LIVRE_MIN && $cursor->lessThan($fimDoDia)) {
                $livres[] = [$cursor, $ini->lessThan($fimDoDia) ? $ini : $fimDoDia];
            }
            if ($fim->greaterThan($cursor)) {
                $cursor = $fim;
            }
        }
        if ($cursor->lessThan($fimDoDia) && $cursor->diffInMinutes($fimDoDia) >= self::LIVRE_MIN) {
            $livres[] = [$cursor, $fimDoDia];
        }

        return $livres;
    }

    /**
     * Como está a fonte Google: 'ok', 'desatualizado', 'reconectar', 'desconectado'
     * ou 'nao_configurado'. A visão e o chat dizem a limitação (FR-011).
     */
    public function situacaoGoogle(User $user): array
    {
        $conta = SecretarioGoogleConta::where('user_id', $user->id)->first();
        $estado = match (true) {
            ! app(GoogleAgenda::class)->configurado() && ! $conta => 'nao_configurado',
            ! $conta => 'desconectado',
            ! $conta->conectada() && ! $conta->pelaMaquina() => 'reconectar',
            ! $conta->sincronizado_em || $conta->sincronizado_em->lessThan(now()->subMinutes(self::DESATUALIZADO_MIN)) => 'desatualizado',
            default => 'ok',
        };

        return ['estado' => $estado, 'conta' => $conta?->conta, 'sincronizado_em' => $conta?->sincronizado_em];
    }

    /** Texto curto da limitação, ou null quando o Google está em dia. */
    public function aviso(array $situacao): ?string
    {
        $hora = $situacao['sincronizado_em'] ? ' (última leitura '.$situacao['sincronizado_em']->format('d/m H:i').')' : '';

        return match ($situacao['estado']) {
            'ok' => null,
            'desatualizado' => "⚠ O Google não é lido há mais de ".self::DESATUALIZADO_MIN." min{$hora}: pode faltar evento.",
            'reconectar' => '⚠ O Google Agenda precisa ser reconectado (Secretário → Configurações): os eventos dele não estão aqui.',
            default => '⚠ O Google Agenda não está conectado: os eventos dele não entram aqui.',
        };
    }
}
