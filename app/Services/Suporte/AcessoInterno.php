<?php

namespace App\Services\Suporte;

use App\Models\Orcamento;
use App\Models\SecretarioGestorRetrato;
use App\Models\User;
use App\Services\Secretario\MontadorDeBriefing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Quem consulta o Pedro por dentro (docs/specs/suporte-pedro-whatsapp/acesso-interno.md):
 * decidido SÓ pelo número que escreve, numa lista que só a conta dona do Pedro edita.
 * O que cada perfil não vê não vai para a IA — nem com regra pedindo silêncio (FR-A3).
 */
class AcessoInterno
{
    /** O ajuste com a lista: uma linha por pessoa, "telefone | nome | perfil". */
    public const AJUSTE = 'acessos_internos';

    /** Cada perfil inclui o de baixo. */
    public const PERFIS = [
        'diretor' => 'Diretor: Gestor completo, Matriz (VixCard) e o resumo do dia',
        'gestor' => 'Gestor completo: suporte, financeiro e comercial',
        'suporte' => 'Só os atendimentos do Gestor',
    ];

    private const VE = ['diretor' => ['suporte', 'gestor', 'diretor'], 'gestor' => ['suporte', 'gestor'], 'suporte' => ['suporte']];

    /** @return list<array{telefone: string, nome: string, perfil: string}> as linhas válidas da lista */
    public function lista(): array
    {
        $linhas = [];
        foreach (preg_split('/\R/', app(Conhecimento::class)->ajuste(self::AJUSTE)) ?: [] as $l) {
            [$tel, $nome, $perfil] = array_pad(array_map('trim', explode('|', $l, 3)), 3, '');
            $perfil = mb_strtolower($perfil);
            if (CanalWhatsApp::chave($tel) && $nome !== '' && isset(self::PERFIS[$perfil])) {
                $linhas[] = ['telefone' => $tel, 'nome' => $nome, 'perfil' => $perfil];
            }
        }

        return $linhas;
    }

    /** As linhas que não foram entendidas, para a tela avisar em vez de ignorar calada. @return list<string> */
    public static function linhasInvalidas(string $texto): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $texto) ?: []), function ($l) {
            if ($l === '') {
                return false;
            }
            [$tel, $nome, $perfil] = array_pad(array_map('trim', explode('|', $l, 3)), 3, '');

            return ! CanalWhatsApp::chave($tel) || $nome === '' || ! isset(self::PERFIS[mb_strtolower($perfil)]);
        }));
    }

    /** @return array{telefone: string, nome: string, perfil: string}|null */
    public function deQuem(string $telefone): ?array
    {
        $chave = CanalWhatsApp::chave($telefone);

        return $chave ? collect($this->lista())->first(fn ($p) => CanalWhatsApp::chave($p['telefone']) === $chave) : null;
    }

    public static function ve(array $acesso, string $o): bool
    {
        return in_array($o, self::VE[$acesso['perfil']] ?? [], true);
    }

    /** Os blocos de dados que este perfil vê, já em texto para o pedido à IA. @return list<string> */
    public function dados(array $acesso, string $ultimasMensagens): array
    {
        $blocos = [$this->atendimentos($ultimasMensagens)];
        if (self::ve($acesso, 'gestor')) {
            $blocos[] = $this->gestor();
        }
        if (self::ve($acesso, 'diretor')) {
            $blocos[] = $this->resumoDoDia();
            $blocos[] = $this->orcamentosVixCard();
        }

        return $blocos;
    }

    /** Todos os abertos do Gestor, e os citados pelo número (qualquer etapa). */
    private function atendimentos(string $ultimasMensagens): string
    {
        $d = app(DiretorioGestor::class);
        $r = $d->retrato();
        if (! ($r['lido_em'] ?? null)) {
            return "## ATENDIMENTOS DO GESTOR\n- O retrato do Gestor ainda não foi lido: diga que não consegue consultar agora.";
        }
        $equipe = collect($r['equipe'] ?? [])->keyBy('id');
        $linha = function (array $a) use ($d, $equipe) {
            $cl = $d->cliente((int) ($a['cliente_id'] ?? 0));
            $nome = $cl ? (($cl['fantasia'] ?? '') ?: ($cl['nome'] ?? 'cliente')) : 'cliente';
            $quem = collect((array) ($a['funcionarios'] ?? []))->map(fn ($id) => Str::before((string) ($equipe[$id]['nome'] ?? ''), ' '))->filter()->implode(', ');

            return '- nº '.($a['numero'] ?? $a['id']).' — '.$nome.(! empty($cl['empresa']) ? ' ('.$cl['empresa'].')' : '')
                .' — etapa: '.str_replace('_', ' ', (string) ($a['status'] ?? '?'))
                .(! empty($a['criado_em']) ? ' — aberto em '.substr((string) $a['criado_em'], 8, 2).'/'.substr((string) $a['criado_em'], 5, 2) : '')
                .($quem !== '' ? ' — com '.$quem : ' — sem responsável')
                .' — '.Str::limit(trim((string) ($a['descricao'] ?? '')), 110, '…');
        };
        $abertos = collect($r['atendimentos'] ?? [])->reject(fn ($a) => in_array($a['status'] ?? '', ['concluido', 'cancelado'], true))
            ->sortBy(fn ($a) => (string) ($a['criado_em'] ?? ''))->values();
        $porEtapa = $abertos->countBy(fn ($a) => str_replace('_', ' ', (string) ($a['status'] ?? '?')))->map(fn ($n, $e) => "{$e}: {$n}")->implode(', ');
        $texto = "## ATENDIMENTOS DO GESTOR (lido às ".$this->hora($r['lido_em']).")\n"
            .'- Abertos: '.$abertos->count().($porEtapa !== '' ? " ({$porEtapa})" : '').". Lista do mais antigo para o mais novo:\n"
            .$abertos->take(80)->map($linha)->implode("\n")
            .($abertos->count() > 80 ? "\n- (+".($abertos->count() - 80).' mais novos, não listados)' : '');
        // O número citado na conversa ("e o 776?"), aberto ou não.
        preg_match_all('/\b(\d{3,6})\b/', $ultimasMensagens, $m);
        $citados = collect($m[1])->unique()->map(fn ($n) => $d->atendimentoPorNumero($n))->filter()
            ->reject(fn ($a) => $abertos->contains(fn ($x) => $x['id'] === $a['id']));
        if ($citados->isNotEmpty()) {
            $texto .= "\n- Citados na conversa:\n".$citados->map($linha)->implode("\n");
        }

        return $texto;
    }

    /** O retrato do Secretário: financeiro e comercial do Gestor, lido da máquina do dono. */
    private function gestor(): string
    {
        $retrato = ($dono = $this->dono()) ? SecretarioGestorRetrato::where('user_id', $dono->id)->first() : null;
        if (! $retrato) {
            return "## GESTOR — FINANCEIRO E COMERCIAL\n- Ainda não lido: diga que não consegue consultar agora.";
        }
        $partes = ['## GESTOR — FINANCEIRO E COMERCIAL (lido às '.$this->hora($retrato->lido_em).($retrato->velho() ? '; DESATUALIZADO, avise' : '').')'];
        foreach (SecretarioGestorRetrato::BLOCOS as $chave => $rotulo) {
            $b = $retrato->dados['blocos'][$chave] ?? null;
            if (! $b) {
                continue;
            }
            $partes[] = '- '.$b['quantidade'].' '.$rotulo.(! empty($b['valor']) ? ' — total '.$this->brl($b['valor']) : '');
            foreach ((array) ($b['itens'] ?? []) as $i) {
                $partes[] = '  • '.$i['texto'].(! empty($i['data']) ? ' — '.CarbonImmutable::parse($i['data'])->format('d/m/Y') : '').(! empty($i['valor']) ? ' — '.$this->brl($i['valor']) : '');
            }
            if ($b['quantidade'] > count((array) ($b['itens'] ?? []))) {
                $partes[] = '  • (os itens acima são os '.count((array) ($b['itens'] ?? [])).' mais antigos; o total é '.$b['quantidade'].')';
            }
        }

        return implode("\n", $partes);
    }

    /** O mesmo resumo do dia que o Secretário manda no Telegram. */
    private function resumoDoDia(): string
    {
        $dono = $this->dono();

        return "## RESUMO DO DIA DO DIRETOR (o mesmo do Telegram, montado agora)\n"
            .($dono ? app(MontadorDeBriefing::class)->montar($dono, CarbonImmutable::now(config('app.timezone')), true)['texto'] : '- Conta do diretor não encontrada.');
    }

    private function orcamentosVixCard(): string
    {
        $os = Orcamento::whereIn('situacao', ['rascunho', 'enviado', 'negociacao'])->orderBy('ano')->orderBy('numero')->limit(40)->get();
        if ($os->isEmpty()) {
            return "## VIXCARD — ORÇAMENTOS NO MATRIZ\n- Nenhum em aberto.";
        }

        return "## VIXCARD — ORÇAMENTOS NO MATRIZ (em aberto: rascunho, enviado ou em negociação)\n"
            .$os->map(fn ($o) => '- '.$o->codigo().' — '.$o->cliente.' — '.(Orcamento::SITUACOES[$o->situacao] ?? $o->situacao)
                .' — projeto '.$this->brl($o->total_unico).($o->total_mensal > 0 ? ' + '.$this->brl($o->total_mensal).'/mês' : '')
                .' — '.($o->enviado_em ? 'enviado em '.$o->enviado_em->format('d/m') : 'criado em '.$o->created_at?->format('d/m')))->implode("\n");
    }

    private function dono(): ?User
    {
        return User::where('email', config('services.suporte_pedro.conta'))->first();
    }

    private function hora($quando): string
    {
        $q = CarbonImmutable::parse($quando)->setTimezone(config('app.timezone'));

        return $q->isToday() ? $q->format('H:i') : $q->format('d/m H:i');
    }

    private function brl($v): string
    {
        return 'R$ '.number_format((float) $v, 2, ',', '.');
    }
}
