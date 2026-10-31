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
        'diretor' => 'Diretor: Gestor completo, orçamentos do Matriz e o resumo do dia',
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

    /** @return list<string> as chaves dos telefones da lista (DDD + 8 últimos dígitos), sem repetir */
    public function chaves(): array
    {
        return array_values(array_unique(array_map(fn ($p) => (string) CanalWhatsApp::chave($p['telefone']), $this->lista())));
    }

    /**
     * Manda as chaves ao Gestor (FR-A11). Null quando o número não passa pelo Gestor: aí
     * quem decide o horário é só o Pedro::enfileirar daqui.
     */
    public function mandarAoGestor(): ?bool
    {
        $canal = app(CanalWhatsApp::class);

        return $canal->viaGestor() ? $canal->numerosInternosAoGestor($this->chaves()) : null;
    }

    /** @return array{telefone: string, nome: string, perfil: string}|null */
    public function deQuem(string $telefone): ?array
    {
        $chave = CanalWhatsApp::chave($telefone);

        return $chave ? collect($this->lista())->first(fn ($p) => CanalWhatsApp::chave($p['telefone']) === $chave) : null;
    }

    /** Lançar, corrigir e cancelar visitas da agenda: só diretor e gestor (pedro-dia-do-tecnico, FR-017). */
    public static function alteraAgenda(array $acesso): bool
    {
        return in_array($acesso['perfil'] ?? '', ['diretor', 'gestor'], true);
    }

    public static function ve(array $acesso, string $o): bool
    {
        return in_array($o, self::VE[$acesso['perfil']] ?? [], true);
    }

    /** Os blocos de dados que este perfil vê, já em texto para o pedido à IA. @return list<string> */
    public function dados(array $acesso, string $ultimasMensagens): array
    {
        $blocos = [$this->atendimentos($ultimasMensagens), $this->agenda($ultimasMensagens)];
        if (self::ve($acesso, 'gestor')) {
            $blocos[] = $this->gestor();
        }
        if (self::ve($acesso, 'diretor')) {
            $blocos[] = $this->resumoDoDia();
            $blocos[] = $this->orcamentosDoMatriz();
        }

        return $blocos;
    }

    /**
     * Os clientes do Gestor que combinam com o que a pessoa escreveu, para registrar
     * atendimento: o código que a IA devolve só vale se estiver aqui.
     *
     * @return array<string, array> código "gestor:ID" => cliente do retrato
     */
    public function clientesCitados(string $texto): array
    {
        // Até 10: o relato do dia cita vários clientes, e cada um pode ter mais de um parecido.
        return collect(app(DiretorioGestor::class)->buscarClientes($texto, 10))->mapWithKeys(fn ($c) => ['gestor:'.$c['id'] => $c])->all();
    }

    /**
     * O bloco do pedido para registrar: os clientes citados, com os atendimentos de
     * cada um (para a pessoa escolher entre criar, vincular ou reabrir — Felipe,
     * 21/10/2026), os assuntos e a equipe que pode ficar responsável.
     */
    public function blocoParaRegistrar(string $texto): string
    {
        $d = app(DiretorioGestor::class);
        $clientes = $this->clientesCitados($texto);
        $equipe = collect($d->retrato()['equipe'] ?? []);
        $nomeDe = fn (array $ids) => collect($ids)->map(fn ($id) => (string) ($equipe->firstWhere('id', $id)['nome'] ?? ''))->filter()->implode(', ');

        $lista = $clientes
            ? "## CLIENTES E PRÉ-CLIENTES DO GESTOR PARECIDOS COM O QUE FOI ESCRITO (use só estes códigos)\n"
                .collect($clientes)->map(function ($c, $codigo) use ($d, $nomeDe) {
                    $atendimentos = collect($d->atendimentosDe((int) $c['id'], 8))->map(fn ($a) => '    • nº '.($a['numero'] ?? $a['id']).' — '.str_replace('_', ' ', (string) ($a['status'] ?? '?'))
                        .(! empty($a['criado_em']) ? ' — de '.substr((string) $a['criado_em'], 8, 2).'/'.substr((string) $a['criado_em'], 5, 2).'/'.substr((string) $a['criado_em'], 0, 4) : '')
                        .(($quem = $nomeDe((array) ($a['funcionarios'] ?? []))) !== '' ? ' — com '.$quem : '')
                        .' — '.Str::limit(trim((string) ($a['descricao'] ?? '')), 100, '…'));

                    return '- '.$codigo.': '.(! empty($c['pre']) ? '[PRÉ-CLIENTE] ' : '[CLIENTE] ').trim(($c['fantasia'] ?? '') ?: ($c['nome'] ?? '')).(! empty($c['razao']) ? ' — '.$c['razao'] : '')
                        .' — cidade: '.(! empty($c['cidade']) ? $c['cidade'] : 'não informada').(! empty($c['empresa']) ? ' (empresa '.$c['empresa'].')' : '')
                        ."\n  Atendimentos (mais recentes primeiro):\n".($atendimentos->isNotEmpty() ? $atendimentos->implode("\n") : '    • nenhum')
                        .(($orc = collect($d->orcamentosDe((int) $c['id']))->take(6))->isNotEmpty()
                            ? "\n  Orçamentos em aberto:\n".$orc->map(fn ($o) => '    • orç. '.$o['numero'].' — '.str_replace('_', ' ', (string) $o['status'])
                                .(! empty($o['atendimento_id']) && ($at = $d->atendimentoPorId((int) $o['atendimento_id'])) ? ' — do atendimento nº '.$at['numero'] : '')
                                .(! empty($o['agendado_para']) ? ' — agendado para '.substr($o['agendado_para'], 8, 2).'/'.substr($o['agendado_para'], 5, 2) : '')
                                .' — '.Str::limit((string) $o['descricao'], 90, '…'))->implode("\n") : '')
                        ."\n  Assuntos: ".collect($d->assuntosPara($c))->take(15)->map(fn ($a) => $a['id'].' '.$a['nome'])->implode('; ');
                })->implode("\n")
            : "## CLIENTES E PRÉ-CLIENTES DO GESTOR PARECIDOS COM O QUE FOI ESCRITO\n- Nenhum combina: pergunte o nome como está no Gestor ou ofereça criar um PRÉ-CLIENTE novo (cliente \"novo_pre\").";

        return $lista
            ."\n## EMPRESAS\n".collect($this->empresas())->map(fn ($nome, $id) => "- {$id}: {$nome}\n  Assuntos: ".collect($d->assuntosPara(['id' => 0, 'empresa_id' => $id]))->take(25)->map(fn ($a) => $a['id'].' '.$a['nome'])->implode('; '))->implode("\n")
            ."\n## EQUIPE (técnicos: use o nº em tecnico_id)\n".$equipe->map(fn ($f) => '- '.$f['id'].': '.$f['nome'])->implode("\n");
    }

    /** As empresas do Gestor, como aparecem no retrato (a de cada cliente). @return array<int, string> */
    public function empresas(): array
    {
        return collect(app(DiretorioGestor::class)->retrato()['clientes'] ?? [])->filter(fn ($c) => ! empty($c['empresa_id']) && ! empty($c['empresa']))
            ->mapWithKeys(fn ($c) => [(int) $c['empresa_id'] => (string) $c['empresa']])->sortKeys()->all();
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

    /**
     * A agenda dos técnicos (pedro-dia-do-tecnico, FR-001..FR-003): as visitas dos dias citados na
     * conversa ("ontem", "23/10") e de ontem, hoje e amanhã, do retrato do Gestor. Toda a lista de
     * acesso interno consulta; só o que está na janela do retrato (7 dias para trás, 14 para frente).
     */
    private function agenda(string $ultimasMensagens): string
    {
        $d = app(DiretorioGestor::class);
        $r = $d->retrato();
        if (! ($r['lido_em'] ?? null)) {
            return "## AGENDA DOS TÉCNICOS\n- O retrato do Gestor ainda não foi lido: diga que não consegue consultar a agenda agora.";
        }
        $hoje = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $dias = collect([$hoje->subDay(), $hoje, $hoje->addDay()]);
        $t = mb_strtolower($ultimasMensagens);
        foreach (['anteontem' => -2, 'ontem' => -1, 'hoje' => 0, 'amanhã' => 1, 'amanha' => 1, 'depois de amanhã' => 2] as $palavra => $n) {
            if (str_contains($t, $palavra)) {
                $dias->push($hoje->addDays($n));
            }
        }
        preg_match_all('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $t, $m, PREG_SET_ORDER);
        foreach ($m as $g) {
            [$dia, $mes, $ano] = [$g[1], $g[2], $g[3] ?? ''];
            $ano = $ano === '' ? $hoje->year : ((int) $ano < 100 ? 2000 + (int) $ano : (int) $ano);
            if (checkdate((int) $mes, (int) $dia, $ano)) {
                $dias->push(CarbonImmutable::create($ano, (int) $mes, (int) $dia, 0, 0, 0, config('app.timezone')));
            }
        }
        $dias = $dias->unique(fn ($x) => $x->toDateString())->sortBy(fn ($x) => $x->toDateString())->take(-5)->values();

        $equipe = collect($r['equipe'] ?? [])->keyBy('id');
        $atendimentos = collect($r['atendimentos'] ?? [])->keyBy('id');
        $nome = fn ($id) => (string) ($equipe[$id]['nome'] ?? 'técnico '.$id);
        $texto = "## AGENDA DOS TÉCNICOS (visitas no Gestor, lido às ".$this->hora($r['lido_em']).'; o retrato cobre de '
            .$hoje->subDays(7)->format('d/m').' a '.$hoje->addDays(14)->format('d/m').')';
        foreach ($dias as $dia) {
            $doDia = collect($r['visitas'] ?? [])->filter(fn ($v) => str_starts_with((string) ($v['inicio'] ?? ''), $dia->toDateString()))
                ->sortBy(fn ($v) => $nome($v['tecnico_id'] ?? 0).$v['inicio'])->values();
            $texto .= "\n### ".Str::ucfirst($dia->locale('pt_BR')->isoFormat('dddd, DD/MM/YYYY'));
            if ($dia->lt($hoje->subDays(7)) || $dia->gt($hoje->addDays(14))) {
                $texto .= "\n- Fora do período do retrato: diga que não consegue consultar este dia.";

                continue;
            }
            if ($doDia->isEmpty()) {
                $texto .= "\n- Nenhuma visita de técnico.";

                continue;
            }
            foreach ($doDia as $v) {
                $a = $atendimentos[$v['atendimento_id']] ?? null;
                $cl = $a ? $d->cliente((int) ($a['cliente_id'] ?? 0)) : null;
                $cliente = $cl ? (($cl['fantasia'] ?? '') ?: ($cl['nome'] ?? '')).(! empty($cl['pre']) ? ' (pré-cliente)' : '')
                    : (! empty($a['solicitante']) ? $a['solicitante'].' (sem cadastro)' : 'cliente não identificado no retrato');
                $adicionais = collect((array) ($v['adicionais'] ?? []))->map(fn ($id) => Str::before($nome($id), ' '))->implode(', ');
                $texto .= "\n- ".Str::before($nome($v['tecnico_id'] ?? 0), ' ').': '.substr((string) $v['inicio'], 11, 5).' às '.substr((string) ($v['fim'] ?? ''), 11, 5)
                    .' — '.($a ? 'nº '.$a['numero'] : 'atendimento '.$v['atendimento_id']).' — '.$cliente
                    .' — '.str_replace('_', ' ', (string) $v['situacao'])
                    .($adicionais !== '' ? ' — junto: '.$adicionais : '')
                    .($a ? ' — '.Str::limit(trim(preg_replace('/\s+/', ' ', (string) ($a['descricao'] ?? ''))), 90, '…') : '')
                    .(! empty($v['observacoes']) ? ' — obs.: '.Str::limit((string) $v['observacoes'], 80, '…') : '');
            }
        }
        $tecnicos = collect($r['visitas'] ?? [])->pluck('tecnico_id')->filter()->unique()->map($nome)->sort()->implode(', ');

        return $texto.($tecnicos !== '' ? "\n- Técnicos com visita no período do retrato: {$tecnicos}." : '');
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

    private function orcamentosDoMatriz(): string
    {
        $os = Orcamento::whereIn('situacao', ['rascunho', 'enviado', 'negociacao'])->orderBy('ano')->orderBy('numero')->limit(40)->get();
        if ($os->isEmpty()) {
            return "## ORÇAMENTOS NO MATRIZ\n- Nenhum em aberto.";
        }

        return "## ORÇAMENTOS NO MATRIZ (em aberto: rascunho, enviado ou em negociação)\n"
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
