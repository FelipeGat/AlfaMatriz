<?php

namespace App\Services\Secretario\Acompanhamento;

use App\Models\SecretarioEmail;
use App\Models\SecretarioEmailConta;
use App\Models\SecretarioGestorRetrato;
use App\Models\SecretarioGoogleEvento;
use App\Models\SecretarioItem;
use App\Models\SecretarioReuniao;
use App\Models\SecretarioReuniaoProposta;
use App\Models\User;
use App\Services\Secretario\AgendaConsolidada;
use App\Services\Secretario\Datas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * O que cada regra encontra AGORA, só da conta (research D4). Cada achado tem
 * a chave estável, o texto, o link e a data de referência. Fonte que não pôde
 * ser lida (Gestor velho, caixa com erro) entra em `intocaveis`: o motor não
 * resolve o que dependia dela — ausência de leitura não é situação resolvida.
 */
class Achados
{
    private const PROPOSTA = '/\b(propostas?|orcamentos?|cotac(ao|oes))\b/u';

    public function __construct(private AgendaConsolidada $agenda) {}

    /** @return array{achados: list<array{chave: string, texto: string, link: ?string, referencia_em: ?CarbonImmutable}>, intocaveis: list<string>} */
    public function da(string $regra, User $user, int $valor, CarbonImmutable $agora): array
    {
        return $this->{Str::camel($regra)}($user, $valor, $agora);
    }

    private function tarefaVencida(User $user, int $horas, CarbonImmutable $agora): array
    {
        $achados = [];
        foreach (SecretarioItem::de($user)->where('status', SecretarioItem::ABERTO)->whereIn('tipo', ['tarefa', 'acompanhamento'])
            ->whereNotNull('quando')->where('quando', '<', $agora)->orderBy('quando')->limit(200)->get() as $i) {
            // Só data: vence no fim do dia, não à meia-noite do começo.
            $vence = CarbonImmutable::instance($i->quando);
            $vence = $i->so_data ? $vence->endOfDay() : $vence;
            if ($vence->addHours($horas)->greaterThan($agora)) {
                continue;
            }
            $achados[] = $this->achado('tarefa_vencida:item:'.$i->id.':'.$i->quando->format('YmdHi'),
                'Tarefa vencida desde '.Datas::porExtenso($i->quando, $i->so_data, $agora).': '.$i->titulo.($i->origem === 'reuniao' ? ' (de reunião)' : ''),
                route('secretario.meu-dia'), $vence);
        }

        return ['achados' => $achados, 'intocaveis' => []];
    }

    private function reuniaoProxima(User $user, int $minutos, CarbonImmutable $agora): array
    {
        // O que já é item do Secretário tem lembrete próprio (Etapa 1): não avisa duas vezes.
        $itens = SecretarioItem::de($user)->where('status', SecretarioItem::ABERTO)->whereBetween('quando', [$agora->subDay(), $agora->addDay()])->get();
        $achados = [];
        foreach (SecretarioGoogleEvento::where('user_id', $user->id)->where('dia_inteiro', false)
            ->whereBetween('inicio', [$agora, $agora->addMinutes($minutos)])->whereHas('agenda', fn ($q) => $q->where('incluida', true))->orderBy('inicio')->get() as $e) {
            $repetido = $itens->contains(fn ($i) => $i->origem_ref === 'google:'.$e->agenda_id.':'.$e->google_id
                || (mb_strtolower($i->titulo) === mb_strtolower($e->titulo) && $i->quando->equalTo($e->inicio)));
            if ($repetido) {
                continue;
            }
            $faltam = (int) ceil($agora->diffInMinutes($e->inicio, true));
            $achados[] = $this->achado('reuniao_proxima:google:'.$e->id.':'.$e->inicio->format('YmdHi'),
                'Começa às '.$e->inicio->format('H:i')." (em {$faltam} min): ".$e->titulo, $e->link ?: route('secretario.agenda'), CarbonImmutable::instance($e->inicio));
        }

        return ['achados' => $achados, 'intocaveis' => []];
    }

    private function propostaParada(User $user, int $dias, CarbonImmutable $agora): array
    {
        $achados = [];
        $intocaveis = $this->caixasComErro($user, 'proposta_parada:email:');
        foreach ($this->aguardando($user)->where('recebido_em', '<=', $agora->subDays($dias))->get() as $e) {
            if (preg_match(self::PROPOSTA, $this->normalizar($e->assunto))) {
                $achados[] = $this->achado('proposta_parada:email:'.$e->id,
                    'Proposta sem retorno há '.$this->ha($e->recebido_em, $agora).': '.($e->de_nome ?: $e->de_email).' — '.$e->assunto,
                    $e->link() ?? route('secretario.emails.index', ['ver' => 'aguardando']), CarbonImmutable::instance($e->recebido_em));
            }
        }
        // Gestor: um acompanhamento só para o bloco, com o mais antigo que a máquina leu.
        $retrato = SecretarioGestorRetrato::where('user_id', $user->id)->first();
        if (! $retrato || $retrato->velho()) {
            $intocaveis[] = 'proposta_parada:gestor';
        } else {
            $b = $retrato->dados['blocos']['orcamentos_aprovacao'] ?? null;
            $limite = $agora->subDays($dias + 4)->toDateString();
            $velhos = collect($b['itens'] ?? [])->filter(fn ($i) => isset($i['data']) && $i['data'] <= $limite)->sortBy('data')->values();
            if ($velhos->isNotEmpty()) {
                $mais = $velhos->first();
                $achados[] = $this->achado('proposta_parada:gestor',
                    // Sem o total do bloco: o briefing já o mostra na seção do Gestor.
                    'Gestor: orçamento aguardando aprovação há mais de '.($dias + 4).' dias — o mais antigo lido é '.Str::limit($mais['texto'], 70)
                    .', de '.CarbonImmutable::parse($mais['data'])->format('d/m').' ('.$velhos->count().' dos lidos nessa situação)',
                    null, CarbonImmutable::parse($mais['data']));
            }
        }

        return ['achados' => $achados, 'intocaveis' => $intocaveis];
    }

    private function clienteSemResposta(User $user, int $horas, CarbonImmutable $agora): array
    {
        $achados = [];
        foreach ($this->aguardando($user)->where('recebido_em', '<=', $agora->subHours($horas))->get() as $e) {
            if (preg_match(self::PROPOSTA, $this->normalizar($e->assunto))) {
                continue; // é da regra de proposta
            }
            $achados[] = $this->achado('cliente_sem_resposta:email:'.$e->id,
                'Sem resposta há '.$this->ha($e->recebido_em, $agora).': '.($e->de_nome ?: $e->de_email).' — '.$e->assunto,
                $e->link() ?? route('secretario.emails.index', ['ver' => 'aguardando']), CarbonImmutable::instance($e->recebido_em));
        }

        return ['achados' => $achados, 'intocaveis' => $this->caixasComErro($user, 'cliente_sem_resposta:email:')];
    }

    private function revisaoDeReuniao(User $user, int $horas, CarbonImmutable $agora): array
    {
        $achados = [];
        foreach (SecretarioReuniao::de($user)->where('modo', 'reuniao')->where('status', SecretarioReuniao::PRONTA)
            ->where('analisada_em', '<=', $agora->subHours($horas))->get() as $r) {
            $pendentes = SecretarioReuniaoProposta::where('reuniao_id', $r->id)->where('user_id', $user->id)->where('status', 'pendente')
                ->whereIn('categoria', ['tarefa', 'pendencia'])->count();
            if ($pendentes > 0) {
                $achados[] = $this->achado('revisao_de_reuniao:reuniao:'.$r->id,
                    'Reunião “'.Str::limit($r->titulo, 60).'” ('.$r->created_at->format('d/m').") com {$pendentes} tarefa(s) esperando sua revisão",
                    route('secretario.reunioes.mostrar', $r->id), CarbonImmutable::instance($r->analisada_em));
            }
        }

        return ['achados' => $achados, 'intocaveis' => []];
    }

    private function conflito(User $user, int $dias, CarbonImmutable $agora): array
    {
        $itens = $this->agenda->periodo($user, $agora, $agora->addDays($dias)->endOfDay())->where('ocupa', true)->values();
        $achados = [];
        for ($a = 0; $a < $itens->count(); $a++) {
            for ($b = $a + 1; $b < $itens->count(); $b++) {
                [$x, $y] = [$itens[$a], $itens[$b]];
                if ($x['inicio']->lessThan($y['fim']) && $y['inicio']->lessThan($x['fim'])) {
                    $refs = [$x['ref'], $y['ref']];
                    sort($refs);
                    $quando = $x['inicio']->lessThan($y['inicio']) ? $y['inicio'] : $x['inicio'];
                    $achados[] = $this->achado('conflito:'.implode('|', $refs).':'.$quando->format('YmdHi'),
                        'Conflito '.Datas::porExtenso($quando, false, $agora).': “'.Str::limit($x['titulo'], 50).'” × “'.Str::limit($y['titulo'], 50).'”',
                        route('secretario.agenda'), $quando);
                }
            }
        }

        return ['achados' => $achados, 'intocaveis' => []];
    }

    private function entregaProxima(User $user, int $dias, CarbonImmutable $agora): array
    {
        $achados = [];
        foreach (SecretarioItem::de($user)->where('status', SecretarioItem::ABERTO)->where('so_data', true)
            ->whereBetween('quando', [$agora->startOfDay(), $agora->addDays($dias)->endOfDay()])->orderBy('quando')->get() as $i) {
            if ($i->origem !== 'reuniao' && ! preg_match('/\b(entreg\w*|envi\w*|mandar)\b/u', $this->normalizar($i->titulo))) {
                continue;
            }
            $achados[] = $this->achado('entrega_proxima:item:'.$i->id.':'.$i->quando->format('Ymd'),
                'Entrega '.Datas::porExtenso($i->quando, true, $agora).': '.$i->titulo, route('secretario.meu-dia'), CarbonImmutable::instance($i->quando));
        }

        return ['achados' => $achados, 'intocaveis' => []];
    }

    // ── Apoio ────────────────────────────────────────────────────────────

    private function aguardando(User $user)
    {
        return SecretarioEmail::de($user)->with('conta')->where('enviado', false)->aguardando()->orderBy('recebido_em')->limit(300);
    }

    /** Caixa com erro: os e-mails dela não são resolvidos por ausência (só a conta inteira, por simplicidade). */
    private function caixasComErro(User $user, string $prefixo): array
    {
        return SecretarioEmailConta::de($user)->where('situacao', 'erro')->exists() ? [$prefixo] : [];
    }

    private function ha($quando, CarbonImmutable $agora): string
    {
        $h = (int) floor(CarbonImmutable::instance($quando)->diffInHours($agora, true));

        return $h < 48 ? "{$h} h" : intdiv($h, 24).' dias';
    }

    private function normalizar(string $t): string
    {
        return \App\Services\Secretario\Memoria\BuscaNaMemoria::normalizar($t);
    }

    private function achado(string $chave, string $texto, ?string $link, ?CarbonImmutable $referencia): array
    {
        return ['chave' => mb_substr($chave, 0, 170), 'texto' => mb_substr($texto, 0, 500), 'link' => $link ? mb_substr($link, 0, 500) : null, 'referencia_em' => $referencia];
    }
}
