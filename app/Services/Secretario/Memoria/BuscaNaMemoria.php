<?php

namespace App\Services\Secretario\Memoria;

use App\Models\CentralIaMensagem;
use App\Models\SecretarioEmail;
use App\Models\SecretarioGestorRetrato;
use App\Models\SecretarioItem;
use App\Models\SecretarioReuniao;
use App\Models\SecretarioReuniaoProposta;
use App\Models\SecretarioReuniaoTrecho;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A memória do Secretário é o que as Etapas 1 a 5 já guardam — aqui só se
 * BUSCA, da própria conta (docs/specs/secretario-memoria, research D1). Cada
 * resultado diz de onde veio, quando, o link para o original e se é fato,
 * extraído pela IA (não revisado) ou inferência (FR-003, FR-004).
 */
class BuscaNaMemoria
{
    public const POR_FONTE = 8;
    private const PARADAS = ['o', 'a', 'os', 'as', 'de', 'da', 'do', 'das', 'dos', 'com', 'que', 'e', 'em', 'no', 'na', 'nos', 'nas', 'um', 'uma',
        'para', 'pra', 'por', 'sobre', 'eu', 'me', 'meu', 'minha', 'cliente', 'clientes', 'fornecedor', 'ficou', 'tem', 'ter', 'foi', 'qual', 'quais'];
    private const PESO = ['decisao' => 6, 'tarefa' => 5, 'pendencia' => 5, 'reuniao' => 4, 'item' => 4, 'gestor' => 3, 'email' => 3, 'transcricao' => 2, 'conversa' => 1];

    /** @return list<string> termos normalizados (sem acento, minúsculos, sem palavras vazias) */
    public static function termos(string $texto): array
    {
        $t = self::normalizar($texto);
        $termos = array_filter(preg_split('/[^a-z0-9@._-]+/', $t), fn ($w) => mb_strlen($w) >= 2 && ! in_array($w, self::PARADAS, true));

        return array_values(array_unique($termos));
    }

    public static function normalizar(string $t): string
    {
        $t = mb_strtolower($t, 'UTF-8');

        return strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ú' => 'u', 'ü' => 'u', 'ù' => 'u', 'ç' => 'c', 'ñ' => 'n']);
    }

    /**
     * @param  list<string>  $termos
     * @return Collection<int, array{fonte: string, rotulo: string, texto: string, data: ?CarbonImmutable, link: ?string, natureza: string, aberto: bool, pontos: int}>
     */
    public function buscar(User $user, array $termos, bool $soAbertos = false): Collection
    {
        if (! $termos) {
            return collect();
        }
        $achou = fn (string $texto) => collect($termos)->filter(fn ($t) => str_contains(self::normalizar($texto), $t))->count();
        $r = collect();

        // Reuniões e o que a análise tirou delas.
        $reunioes = SecretarioReuniao::de($user)->where('modo', 'reuniao')->orderByDesc('id')->limit(300)->get()->keyBy('id');
        foreach ($reunioes as $re) {
            $alvo = $re->titulo.' '.$re->resumo.' '.json_encode($re->contexto, JSON_UNESCAPED_UNICODE).' '.implode(' ', (array) $re->participantes);
            if (! $soAbertos && ($n = $achou($alvo))) {
                $r->push($this->linha('reuniao', 'Reunião', $re->titulo.($re->resumo ? ' — '.Str::limit($re->resumo, 160) : ''), $re->created_at,
                    route('secretario.reunioes.mostrar', $re->id), $this->naturezaDaReuniao($re), false, $n));
            }
        }
        $listados = []; // item do Secretário que já veio pela proposta de origem (com evidência e link)
        $propostas = SecretarioReuniaoProposta::where('user_id', $user->id)->whereIn('reuniao_id', $reunioes->keys())->where('status', '!=', 'descartada')->get();
        foreach ($propostas as $p) {
            $re = $reunioes[$p->reuniao_id];
            $aberto = $p->categoria !== 'decisao' && ($p->status === 'pendente' || ($p->item_id && SecretarioItem::whereKey($p->item_id)->where('status', SecretarioItem::ABERTO)->exists()));
            if ($soAbertos && ! $aberto) {
                continue;
            }
            if ($n = $achou($p->texto.' '.$p->responsavel.' '.$re->titulo.' '.json_encode($re->contexto, JSON_UNESCAPED_UNICODE))) {
                $natureza = $p->status === 'aprovada' || $re->status === SecretarioReuniao::REVISADA ? 'fato' : 'extraído pela IA, não revisado';
                if ($p->item_id) {
                    $listados[$p->item_id] = true;
                }
                $rotulo = ['decisao' => 'Decisão', 'tarefa' => 'Tarefa', 'pendencia' => 'Pendência'][$p->categoria];
                $r->push($this->linha($p->categoria, $rotulo.' · reunião “'.Str::limit($re->titulo, 50).'”',
                    $p->texto.($p->responsavel ? ' (responsável: '.$p->responsavel.')' : '').($p->prazo ? ' — prazo '.$p->prazo : ''),
                    $re->created_at, route('secretario.reunioes.mostrar', $re->id).(($ev = (array) $p->evidencias) ? '#t-'.$ev[0] : ''), $natureza, $aberto, $n));
            }
        }
        // Transcrição: busca no banco (pode ser grande), um termo por vez.
        if (! $soAbertos && $reunioes->isNotEmpty()) {
            $trechos = SecretarioReuniaoTrecho::whereIn('reuniao_id', $reunioes->keys())
                ->where(fn ($q) => collect($termos)->each(fn ($t) => $q->orWhere('texto', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $t).'%')))
                ->orderByDesc('reuniao_id')->limit(200)->get();
            foreach ($trechos as $t) {
                if ($n = $achou($t->texto)) {
                    $re = $reunioes[$t->reuniao_id];
                    $r->push($this->linha('transcricao', 'Transcrição · “'.Str::limit($re->titulo, 50).'” '.SecretarioReuniaoTrecho::horario($t->inicio),
                        '“'.Str::limit($t->texto, 180).'”', $re->created_at, route('secretario.reunioes.mostrar', $re->id).'#t-'.$t->ordem, 'inferência (citado na transcrição)', false, $n));
                }
            }
        }
        // Itens do Secretário.
        foreach (SecretarioItem::de($user)->when($soAbertos, fn ($q) => $q->where('status', SecretarioItem::ABERTO))->orderByDesc('id')->limit(1000)->get() as $i) {
            if (! isset($listados[$i->id]) && ($n = $achou($i->titulo.' '.$i->descricao))) {
                $r->push($this->linha('item', 'Secretário · '.ucfirst($i->tipo).($i->status !== 'aberto' ? ' ('.$i->status.')' : ''),
                    $i->titulo.($i->quando ? ' — '.\App\Services\Secretario\Datas::porExtenso($i->quando, $i->so_data) : ''),
                    $i->quando ?? $i->created_at, route('secretario.meu-dia'), 'fato', $i->status === 'aberto', $n));
            }
        }
        // E-mails (metadados).
        foreach (SecretarioEmail::de($user)->with('conta')->where('enviado', false)->when($soAbertos, fn ($q) => $q->aguardando())->orderByDesc('recebido_em')->limit(1000)->get() as $e) {
            if ($n = $achou($e->assunto.' '.$e->de_nome.' '.$e->de_email)) {
                $r->push($this->linha('email', 'E-mail · '.$e->conta?->rotulo, ($e->de_nome ?: $e->de_email).' — '.$e->assunto
                    .($e->pede_retorno && ! $e->respondido ? ' (aguardando resposta)' : ''), $e->recebido_em,
                    $e->link() ?? route('secretario.emails.index', ['ver' => 'todas']), 'fato', $e->pede_retorno && ! $e->respondido, $n));
            }
        }
        // Gestor (último retrato).
        if ($g = SecretarioGestorRetrato::where('user_id', $user->id)->first()) {
            foreach ($g->dados['blocos'] ?? [] as $chave => $b) {
                foreach ($b['itens'] ?? [] as $it) {
                    if ($n = $achou($it['texto'])) {
                        $r->push($this->linha('gestor', 'Gestor · '.(SecretarioGestorRetrato::BLOCOS[$chave] ?? $chave).' (lido '.$g->lido_em->format('d/m H:i').')',
                            $it['texto'], isset($it['data']) ? CarbonImmutable::parse($it['data']) : $g->lido_em, null, 'fato', true, $n));
                    }
                }
            }
        }
        // Conversas da Central (o que a pessoa e os agentes escreveram).
        if (! $soAbertos) {
            // As respostas do próprio Secretário ficam fora: senão ele se cita.
            $conversa = CentralIaMensagem::de($user)->where(fn ($q) => $q->whereNull('autor_nome')->orWhere('autor_nome', '!=', 'Secretário'))->where(fn ($q) => collect($termos)->each(fn ($t) => $q->orWhere('conteudo', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $t).'%')))
                ->orderByDesc('id')->limit(200)->get();
            foreach ($conversa as $m) {
                if ($n = $achou($m->conteudo)) {
                    $r->push($this->linha('conversa', 'Conversa · '.($m->autor_nome ?: $m->autor_tipo), Str::limit(Str::squish($m->conteudo), 180),
                        $m->created_at, route('central-ia.index'), 'fato', false, $n));
                }
            }
        }

        return $r->sortByDesc(fn ($x) => sprintf('%04d%s', $x['pontos'], $x['data']?->format('YmdHis') ?? '0'))->values();
    }

    /**
     * Até POR_FONTE de cada fonte, com o total (FR-005): "Felipe" aparece em
     * tudo, e uma fonte não pode afogar as outras.
     *
     * @return array<string, array{total: int, itens: Collection}>
     */
    public static function porFonte(Collection $resultados): array
    {
        $saida = [];
        foreach (array_keys(self::PESO) as $fonte) {
            $g = $resultados->where('fonte', $fonte)->values();
            if ($g->isNotEmpty()) {
                $saida[$fonte] = ['total' => $g->count(), 'itens' => $g->take(self::POR_FONTE)];
            }
        }

        return $saida;
    }

    private function naturezaDaReuniao(SecretarioReuniao $re): string
    {
        return match ($re->status) {
            SecretarioReuniao::REVISADA => 'fato',
            SecretarioReuniao::PRONTA => 'extraído pela IA, não revisado',
            default => 'sem análise',
        };
    }

    private function linha(string $fonte, string $rotulo, string $texto, $data, ?string $link, string $natureza, bool $aberto, int $termos): array
    {
        $data = $data ? CarbonImmutable::instance($data) : null;
        $recencia = $data ? max(0, 30 - (int) $data->diffInDays(now(), true)) : 0;

        return ['fonte' => $fonte, 'rotulo' => $rotulo, 'texto' => $texto, 'data' => $data, 'link' => $link, 'natureza' => $natureza, 'aberto' => $aberto,
            'pontos' => $termos * 10 + (self::PESO[$fonte] ?? 0) * 3 + (int) round($recencia / 3)];
    }
}
