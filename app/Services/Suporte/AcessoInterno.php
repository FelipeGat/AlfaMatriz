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

    /**
     * O retrato do Gestor é relido a cada 10 min; com mais de 30 (três leituras perdidas) ele vai marcado
     * DESATUALIZADO, e só então o Pedro fala da hora da leitura (pedro-comunicacao, FR-002).
     */
    public const RETRATO_VELHO_MINUTOS = 30;

    /**
     * As linhas válidas da lista. A 4ª coluna, opcional, é o e-mail do usuário da pessoa no Gestor
     * (pedro-orcamento, 02/11/2026): é ele que liga a linha à pessoa de verdade, e não só ao número —
     * o FR-012 da pedro-inteligencia-operacional ("a lista reconhece; o vínculo autoriza").
     *
     * @return list<array{telefone: string, nome: string, perfil: string, gestor_email: ?string}>
     */
    public function lista(): array
    {
        $linhas = [];
        foreach (preg_split('/\R/', app(Conhecimento::class)->ajuste(self::AJUSTE)) ?: [] as $l) {
            [$tel, $nome, $perfil, $email] = self::colunas($l);
            if (CanalWhatsApp::chave($tel) && $nome !== '' && isset(self::PERFIS[$perfil]) && ($email === '' || self::emailValido($email))) {
                $linhas[] = ['telefone' => $tel, 'nome' => $nome, 'perfil' => $perfil, 'gestor_email' => $email !== '' ? $email : null];
            }
        }

        return $linhas;
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} telefone, nome, perfil (minúsculo) e e-mail do Gestor */
    private static function colunas(string $linha): array
    {
        [$tel, $nome, $perfil, $email] = array_pad(array_map('trim', explode('|', $linha, 4)), 4, '');

        return [$tel, $nome, mb_strtolower($perfil), mb_strtolower($email)];
    }

    private static function emailValido(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** As linhas que não foram entendidas, para a tela avisar em vez de ignorar calada. @return list<string> */
    public static function linhasInvalidas(string $texto): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $texto) ?: []), function ($l) {
            if ($l === '') {
                return false;
            }
            [$tel, $nome, $perfil, $email] = self::colunas($l);

            return ! CanalWhatsApp::chave($tel) || $nome === '' || ! isset(self::PERFIS[$perfil]) || ($email !== '' && ! self::emailValido($email));
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

    /**
     * A linha da lista deste número, com o usuário do Gestor de quem escreve. A 4ª coluna vale
     * quando preenchida; sem ela, o usuário vem do cadastro de funcionário do Gestor que tem este
     * número (bugfix de 02/11/2026: o Pedro pediu o e-mail ao Felipe, e o Gestor já ligava o celular
     * dele ao funcionário e ao usuário). Quem decide se o usuário pode é o Gestor, na gravação.
     *
     * @return array{telefone: string, nome: string, perfil: string, gestor_email: ?string, gestor_email_de: ?string}|null
     */
    public function deQuem(string $telefone): ?array
    {
        $chave = CanalWhatsApp::chave($telefone);
        $linha = $chave ? collect($this->lista())->first(fn ($p) => CanalWhatsApp::chave($p['telefone']) === $chave) : null;
        if (! $linha) {
            return null;
        }
        if ($linha['gestor_email'] !== null) {
            return $linha + ['gestor_email_de' => 'lista'];
        }
        $usuario = app(DiretorioGestor::class)->daEquipe($telefone)['usuario'] ?? null;

        return ['gestor_email' => $usuario, 'gestor_email_de' => $usuario ? 'cadastro' : null] + $linha;
    }

    /** Lançar, corrigir e cancelar visitas da agenda: só diretor e gestor (pedro-dia-do-tecnico, FR-017). */
    public static function alteraAgenda(array $acesso): bool
    {
        return in_array($acesso['perfil'] ?? '', ['diretor', 'gestor'], true);
    }

    /**
     * Criar orçamento no Gestor (pedro-orcamento, FR-001): só os proprietários — perfil diretor com a
     * linha ligada ao usuário do Gestor, que vira o vendedor. Quem decide é o código; o Gestor ainda
     * confere, na gravação, que esse usuário cria orçamento pela tela.
     */
    public static function criaOrcamento(array $acesso): bool
    {
        return ($acesso['perfil'] ?? '') === 'diretor' && filled($acesso['gestor_email'] ?? null);
    }

    public static function ve(array $acesso, string $o): bool
    {
        return in_array($o, self::VE[$acesso['perfil']] ?? [], true);
    }

    /**
     * O que o Pedro faz por quem é da casa, montado aqui e não escrito à mão no pedido (Felipe,
     * 31/10/2026: "evitar oferecer ações que o Pedro ainda não consegue executar"). O "não faz" é o
     * que ele já ofereceu ou que a equipe costuma pedir; quando uma capacidade entrar
     * (pedro-inteligencia-operacional), ela sai daqui e vai para o "faz" junto do código.
     */
    public static function oQueFaz(array $acesso): string
    {
        $faz = ['consultar os atendimentos abertos do Gestor e qualquer um citado pelo número, com a etapa, quem está com ele e a próxima visita',
            'consultar a agenda dos técnicos (de 7 dias atrás a 14 à frente)',
            'registrar atendimento no Gestor (novo, vincular a um aberto ou reabrir um concluído, com técnico e horário), sempre depois da prévia e do "sim"'];
        if (self::alteraAgenda($acesso)) {
            $faz[] = 'lançar, corrigir ou cancelar visitas da agenda, depois da prévia e do "sim"';
        }
        if (self::ve($acesso, 'gestor')) {
            $faz[] = 'consultar o financeiro e o comercial do Gestor (cobranças, contas a pagar, orçamentos aguardando aprovação, funil parado)';
        }
        if (self::ve($acesso, 'diretor')) {
            $faz[] = 'mostrar o resumo do dia e os orçamentos em aberto do Matriz';
        }
        if (self::criaOrcamento($acesso)) {
            $faz[] = 'criar orçamento no Gestor (cliente ou pré-cliente que já existe, empresa, com ou sem NF, itens e valores que ele disser, ligado a um atendimento do cliente), sempre depois da prévia e do "sim"; '
                .'tirar a nota de um valor dito já com NF; corrigir a prévia por partes; mandar o número e o link quando o Gestor criar; repetir o envio que falhou sem criar outro';
        }
        $naoFaz = ['ver andamentos ou o histórico de uma OS', 'concluir ou mudar a etapa de um atendimento', 'criar tarefa no quadro',
            ...(self::criaOrcamento($acesso) ? [] : [self::ve($acesso, 'diretor')
                ? 'criar orçamento no Gestor ainda não: falta ligar o número dele ao usuário dele no Gestor (o celular no cadastro de funcionário)'
                : 'criar orçamento no Gestor (só os proprietários)']),
            'editar, aprovar, enviar ao cliente ou excluir orçamento',
            'mandar mensagem, cobrar ou ligar para cliente, técnico ou fornecedor', 'mandar boleto ou nota fiscal', 'pagar, baixar ou renegociar conta',
            'editar cadastro de cliente', 'criar ou mudar POP'];

        return "## O QUE VOCÊ FAZ POR ELE\n- Faz: ".implode('; ', $faz).".\n- Não faz (não ofereça): ".implode('; ', $naoFaz).'.';
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
        return $this->busca($texto)['clientes'];
    }

    /** Até 10 pelo nome: o relato do dia cita vários clientes, e cada um pode ter mais de um parecido. */
    private const PARECIDOS = 10;

    /**
     * Os clientes citados, e por que o que ele citou pode não estar entre eles (Felipe, 03/11/2026: "por que o
     * Pedro não está encontrando o cliente? PowerFit?"). A POWER-FIT ACADEMIA estava no Gestor, ativa e da
     * INVEST; num pedido de orçamento, os itens ("mega", "tera", que soa como "Terra") puxavam outros cadastros
     * e ela saía das 10 primeiras, e o CNPJ que ele mandou depois não ajudava, porque a busca era só pelo nome.
     * - O CNPJ ou CPF escrito acha o cliente, que entra primeiro e fora do limite dos parecidos;
     * - o que não acha, ou acha cliente de empresa que o Pedro não atende, vai como motivo, para o Pedro dizer
     *   "não achei esse CNPJ" em vez de "não apareceu na lista";
     * - e a lista cortada vai dita, para ele pedir o CNPJ em vez de dizer que o cliente não existe.
     *
     * @return array{clientes: array<string, array>, pelo_documento: list<string>, mais: bool, motivos: list<string>}
     */
    private function busca(string $texto): array
    {
        $d = app(DiretorioGestor::class);
        $escritos = Contexto::documentos($texto);
        $pelo = collect($d->porDocumento($escritos))->mapWithKeys(fn ($c) => ['gestor:'.$c['id'] => $c]);
        $doPelo = $pelo->map(fn ($c) => (string) preg_replace('/\D/', '', (string) ($c['documento'] ?? '')))->all();
        $motivos = [];
        foreach ($escritos as $doc) {
            // CPF de 11 dígitos só vale como motivo se foi escrito como CPF: celular também tem 11, e um em cem passa no dígito.
            if (in_array($doc, $doPelo, true) || (strlen($doc) === 11 && ! preg_match('/\bcpf\b|'.self::mascara($doc, true).'/iu', $texto))) {
                continue;
            }
            $fora = $d->deOutraEmpresa($doc);
            $motivos[] = $fora
                ? '- O '.self::tipoDoc($doc).' '.self::mascara($doc).' que ele informou é de '.trim(($fora['fantasia'] ?? '') ?: ($fora['nome'] ?? '')).', cliente da '.($fora['empresa'] ?: 'outra empresa')
                    .', que o suporte do Pedro não atende (só '.implode(', ', (array) config('services.suporte_pedro.empresas')).'): diga isso, e não registre nem monte orçamento para ele.'
                : '- O '.self::tipoDoc($doc).' '.self::mascara($doc).' que ele informou não está no cadastro de nenhum cliente ou pré-cliente que o Pedro atende no Gestor: diga isso ("não achei esse '
                    .self::tipoDoc($doc).' no Gestor") e peça para ele conferir o número.';
        }
        // Catorze dígitos que não são CNPJ: um número trocado. Sem isso, o Pedro só diria que não achou.
        preg_match_all('/(?<!\d)\d{14}(?!\d)/', (string) preg_replace('/(?<=\d)[.\/\-](?=\d)/', '', $texto), $m);
        foreach (array_diff(array_unique($m[0]), $escritos) as $n) {
            $motivos[] = '- O número '.self::mascara($n).' que ele escreveu tem a forma de um CNPJ, mas o dígito verificador não confere: diga que o CNPJ parece ter um número trocado e peça para ele conferir.';
        }
        $porNome = collect($d->buscarClientes($texto, self::PARECIDOS + 1))->mapWithKeys(fn ($c) => ['gestor:'.$c['id'] => $c]);

        return ['clientes' => $pelo->union($porNome->take(self::PARECIDOS))->all(), 'pelo_documento' => $pelo->keys()->all(),
            'mais' => $porNome->count() > self::PARECIDOS, 'motivos' => $motivos];
    }

    private static function tipoDoc(string $doc): string
    {
        return strlen($doc) === 14 ? 'CNPJ' : 'CPF';
    }

    /** "43.025.652/0001-93"; com $regex, o padrão do número escrito assim, com os pontos e o traço. */
    private static function mascara(string $doc, bool $regex = false): string
    {
        $partes = strlen($doc) === 14 ? [[0, 2], [2, 3], [5, 3], [8, 4], [12, 2]] : [[0, 3], [3, 3], [6, 3], [9, 2]];
        $sep = strlen($doc) === 14 ? ['.', '.', '/', '-'] : ['.', '.', '-'];
        $texto = '';
        foreach ($partes as $i => [$de, $n]) {
            $texto .= ($i ? ($regex ? '\\'.$sep[$i - 1] : $sep[$i - 1]) : '').substr($doc, $de, $n);
        }

        return $texto;
    }

    /**
     * O bloco do pedido para registrar: os clientes citados, com os atendimentos de
     * cada um (para a pessoa escolher entre criar, vincular ou reabrir — Felipe,
     * 21/10/2026), os assuntos e a equipe que pode ficar responsável.
     */
    public function blocoParaRegistrar(string $texto): string
    {
        $d = app(DiretorioGestor::class);
        $busca = $this->busca($texto);
        $clientes = $busca['clientes'];
        $equipe = collect($d->retrato()['equipe'] ?? []);
        $nomeDe = fn (array $ids) => collect($ids)->map(fn ($id) => (string) ($equipe->firstWhere('id', $id)['nome'] ?? ''))->filter()->implode(', ');
        $motivos = implode('', array_map(fn ($m) => $m."\n", $busca['motivos']))
            .($busca['mais'] ? '- Esta lista traz só os '.self::PARECIDOS.' mais parecidos com o nome, e há outros: se o cliente que ele citou não está aqui, diga que ele não ficou entre os parecidos '
                ."e peça o CNPJ (ou o nome como está no Gestor). Nunca diga que o cliente não existe no Gestor nem que está fora da lista que você pode usar.\n" : '');

        $lista = $clientes
            ? "## CLIENTES E PRÉ-CLIENTES DO GESTOR PARECIDOS COM O QUE FOI ESCRITO (use só estes códigos)\n".$motivos
                .collect($clientes)->map(function ($c, $codigo) use ($d, $nomeDe, $busca) {
                    $atendimentos = collect($d->atendimentosDe((int) $c['id'], 8))->map(fn ($a) => '    • nº '.($a['numero'] ?? $a['id']).' — '.str_replace('_', ' ', (string) ($a['status'] ?? '?'))
                        .(! empty($a['criado_em']) ? ' — de '.substr((string) $a['criado_em'], 8, 2).'/'.substr((string) $a['criado_em'], 5, 2).'/'.substr((string) $a['criado_em'], 0, 4) : '')
                        .(($quem = $nomeDe((array) ($a['funcionarios'] ?? []))) !== '' ? ' — com '.$quem : '')
                        .' — '.Str::limit(trim((string) ($a['descricao'] ?? '')), 100, '…'));

                    return '- '.$codigo.': '.(! empty($c['pre']) ? '[PRÉ-CLIENTE] ' : '[CLIENTE] ').trim(($c['fantasia'] ?? '') ?: ($c['nome'] ?? '')).(! empty($c['razao']) ? ' — '.$c['razao'] : '')
                        .' — cidade: '.(! empty($c['cidade']) ? $c['cidade'] : 'não informada').(! empty($c['empresa']) ? ' (empresa '.$c['empresa'].')' : '')
                        .(in_array($codigo, $busca['pelo_documento'], true) ? ' — ACHADO PELO '.self::tipoDoc((string) preg_replace('/\D/', '', (string) $c['documento'])).' QUE ELE INFORMOU' : '')
                        ."\n  Atendimentos (mais recentes primeiro):\n".($atendimentos->isNotEmpty() ? $atendimentos->implode("\n") : '    • nenhum')
                        .(($orc = collect($d->orcamentosDe((int) $c['id']))->take(6))->isNotEmpty()
                            ? "\n  Orçamentos em aberto:\n".$orc->map(fn ($o) => '    • orç. '.$o['numero'].' — '.str_replace('_', ' ', (string) $o['status'])
                                .(! empty($o['atendimento_id']) && ($at = $d->atendimentoPorId((int) $o['atendimento_id'])) ? ' — do atendimento nº '.$at['numero'] : '')
                                .(! empty($o['agendado_para']) ? ' — agendado para '.substr($o['agendado_para'], 8, 2).'/'.substr($o['agendado_para'], 5, 2) : '')
                                .' — '.Str::limit((string) $o['descricao'], 90, '…'))->implode("\n") : '')
                        ."\n  Assuntos: ".collect($d->assuntosPara($c))->take(15)->map(fn ($a) => $a['id'].' '.$a['nome'])->implode('; ');
                })->implode("\n")
            : "## CLIENTES E PRÉ-CLIENTES DO GESTOR PARECIDOS COM O QUE FOI ESCRITO\n".$motivos
                .'- Nenhum combina com o nome: diga que não achou o cliente pelo nome e peça o CNPJ ou o nome como está no Gestor, ou ofereça criar um PRÉ-CLIENTE novo (cliente "novo_pre").';

        return $lista
            ."\n## EMPRESAS\n".collect($d->empresas())->map(fn ($e, $id) => "- {$id}: {$e['nome']}".($e['imposto_nf'] !== null ? ' (NF no orçamento: '.rtrim(rtrim(number_format($e['imposto_nf'], 2, ',', ''), '0'), ',').'%)' : '')."\n  Assuntos: ".collect($d->assuntosPara(['id' => 0, 'empresa_id' => $id]))->take(25)->map(fn ($a) => $a['id'].' '.$a['nome'])->implode('; '))->implode("\n")
            ."\n## EQUIPE (técnicos: use o nº em tecnico_id)\n".$equipe->map(fn ($f) => '- '.$f['id'].': '.$f['nome'])->implode("\n");
    }

    /** As empresas do Gestor: as do retrato (pedro-orcamento) ou, sem elas, as dos clientes. @return array<int, string> */
    public function empresas(): array
    {
        return collect(app(DiretorioGestor::class)->empresas())->map(fn ($e) => $e['nome'])->all();
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
        // A próxima visita de cada um (2ª rodada, 31/10/2026): sem ela, o Pedro disse que o 1203 não tinha visita,
        // e tinha — só não estava nos dias da agenda do pedido.
        $hoje = now(config('app.timezone'))->startOfDay()->format('Y-m-d H:i');
        $visitas = collect($r['visitas'] ?? [])->filter(fn ($v) => (string) ($v['inicio'] ?? '') >= $hoje && in_array($v['situacao'] ?? '', ['agendado', 'em_atendimento'], true))
            ->sortBy('inicio')->groupBy('atendimento_id')->map->first();
        $linha = function (array $a) use ($d, $equipe, $visitas) {
            $cl = $d->cliente((int) ($a['cliente_id'] ?? 0));
            $nome = $cl ? (($cl['fantasia'] ?? '') ?: ($cl['nome'] ?? 'cliente')) : 'cliente';
            $quem = collect((array) ($a['funcionarios'] ?? []))->map(fn ($id) => Str::before((string) ($equipe[$id]['nome'] ?? ''), ' '))->filter()->implode(', ');
            $v = $visitas[$a['id']] ?? null;

            return '- nº '.($a['numero'] ?? $a['id']).' — '.$nome.(! empty($cl['empresa']) ? ' ('.$cl['empresa'].')' : '')
                .' — etapa: '.str_replace('_', ' ', (string) ($a['status'] ?? '?'))
                .(! empty($a['criado_em']) ? ' — aberto em '.substr((string) $a['criado_em'], 8, 2).'/'.substr((string) $a['criado_em'], 5, 2) : '')
                .($quem !== '' ? ' — com '.$quem : ' — sem responsável')
                .($v ? ' — próxima visita: '.substr((string) $v['inicio'], 8, 2).'/'.substr((string) $v['inicio'], 5, 2).' '.substr((string) $v['inicio'], 11, 5)
                    .' ('.Str::before((string) ($equipe[$v['tecnico_id'] ?? 0]['nome'] ?? 'técnico'), ' ').')' : '')
                .' — '.Str::limit(trim((string) ($a['descricao'] ?? '')), 110, '…');
        };
        $abertos = collect($r['atendimentos'] ?? [])->reject(fn ($a) => in_array($a['status'] ?? '', ['concluido', 'cancelado'], true))
            ->sortBy(fn ($a) => (string) ($a['criado_em'] ?? ''))->values();
        $porEtapa = $abertos->countBy(fn ($a) => str_replace('_', ' ', (string) ($a['status'] ?? '?')))->map(fn ($n, $e) => "{$e}: {$n}")->implode(', ');
        $texto = "## ATENDIMENTOS DO GESTOR (".$this->leitura($r['lido_em']).")\n"
            .'- Abertos: '.$abertos->count().($porEtapa !== '' ? " ({$porEtapa})" : '').'. Lista do mais antigo para o mais novo; "próxima visita" é a marcada no Gestor até '
            .now(config('app.timezone'))->addDays(14)->format('d/m').", e quem não tem a linha não tem visita marcada até lá:\n"
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
        $t = mb_strtolower($ultimasMensagens);
        // Os dias pedidos vêm primeiro; ontem, hoje e amanhã completam, até o teto (pedro-comunicacao, FR-013). Os
        // vizinhos ficam porque remarcar uma visita pede o dia em que ela está (pedro-dia-do-tecnico, decisão 7),
        // mas vão marcados "não pedido" (FR-016): sem a marca, a IA misturava os dias na resposta.
        $dias = collect(self::diasDaSemana($t, $hoje));
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
        $pedidos = $dias->map(fn ($x) => $x->toDateString())->unique()->all();
        $dias = $dias->merge([$hoje, $hoje->addDay(), $hoje->subDay()])->unique(fn ($x) => $x->toDateString())->take(self::TETO_DE_DIAS)
            ->sortBy(fn ($x) => $x->toDateString())->values();

        $equipe = collect($r['equipe'] ?? [])->keyBy('id');
        $atendimentos = collect($r['atendimentos'] ?? [])->keyBy('id');
        $nome = fn ($id) => (string) ($equipe[$id]['nome'] ?? 'técnico '.$id);
        $texto = "## AGENDA DOS TÉCNICOS (visitas no Gestor, ".$this->leitura($r['lido_em']).'; o retrato cobre de '
            .$hoje->subDays(7)->format('d/m').' a '.$hoje->addDays(14)->format('d/m').'; '
            .($pedidos ? 'os dias citados na conversa e, só para conferir, os marcados NÃO PEDIDO, que ficam fora da resposta' : 'nenhum dia citado: ontem, hoje e amanhã').')';
        foreach ($dias as $dia) {
            $doDia = collect($r['visitas'] ?? [])->filter(fn ($v) => str_starts_with((string) ($v['inicio'] ?? ''), $dia->toDateString()))
                ->sortBy(fn ($v) => $nome($v['tecnico_id'] ?? 0).$v['inicio'])->values();
            $texto .= "\n### ".Str::ucfirst($dia->locale('pt_BR')->isoFormat('dddd, DD/MM/YYYY'))
                .($pedidos && ! in_array($dia->toDateString(), $pedidos, true) ? ' (NÃO PEDIDO)' : '');
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
                    : (! empty($a['solicitante']) ? $a['solicitante'] : 'cliente não informado');
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

    /** Quantos dias a agenda leva num pedido: a semana que vem inteira e mais dois. */
    public const TETO_DE_DIAS = 7;

    /**
     * Os dias da semana citados (FR-013): "quarta" é a desta semana — a de hoje, se hoje é quarta; se já
     * passou, a desta e a da próxima, e a IA vê as duas datas —; "próxima quarta", a primeira depois de
     * hoje; "esta semana" e "semana que vem", os dias úteis. A semana começa na segunda.
     *
     * @return list<CarbonImmutable>
     */
    public static function diasDaSemana(string $texto, CarbonImmutable $hoje): array
    {
        $segunda = $hoje->startOfDay()->subDays($hoje->dayOfWeekIso - 1);
        $dias = [];
        if (preg_match('/\b(semana que vem|pr[oó]xima semana)\b/u', $texto)) {
            foreach (range(0, 4) as $i) {
                $dias[] = $segunda->addWeek()->addDays($i);
            }
        }
        if (preg_match('/\b(n?es[st]a semana)\b/u', $texto)) {
            foreach (range(0, 4) as $i) {
                $dias[] = $segunda->addDays($i);
            }
        }
        $posicao = ['segunda' => 0, 'terca' => 1, 'terça' => 1, 'quarta' => 2, 'quinta' => 3, 'sexta' => 4, 'sabado' => 5, 'sábado' => 5, 'domingo' => 6];
        preg_match_all('/\b(pr[oó]xim[ao]\s+)?(segunda|ter[cç]a|quarta|quinta|sexta|s[aá]bado|domingo)(?:-feira)?(?![\p{L}])/u', $texto, $m, PREG_SET_ORDER);
        foreach ($m as $g) {
            $nesta = $segunda->addDays($posicao[$g[2]]);
            if ($g[1] !== '') {
                $dias[] = $nesta->gt($hoje) ? $nesta : $nesta->addWeek();
            } else {
                $dias[] = $nesta;
                if ($nesta->lt($hoje)) {
                    $dias[] = $nesta->addWeek();
                }
            }
        }

        return $dias;
    }

    /** O retrato do Secretário: financeiro e comercial do Gestor, lido da máquina do dono. */
    private function gestor(): string
    {
        $retrato = ($dono = $this->dono()) ? SecretarioGestorRetrato::where('user_id', $dono->id)->first() : null;
        if (! $retrato) {
            return "## GESTOR — FINANCEIRO E COMERCIAL\n- Ainda não lido: diga que não consegue consultar agora.";
        }
        $partes = ['## GESTOR — FINANCEIRO E COMERCIAL (lido às '.$this->hora($retrato->lido_em).($retrato->velho() ? '; DESATUALIZADO, avise' : '').')'];
        $item = fn (array $i) => '  • '.$i['texto'].(! empty($i['data']) ? ' — '.CarbonImmutable::parse($i['data'])->format('d/m/Y') : '').(! empty($i['valor']) ? ' — '.$this->brl($i['valor']) : '');
        foreach (SecretarioGestorRetrato::BLOCOS as $chave => $rotulo) {
            $b = $retrato->dados['blocos'][$chave] ?? null;
            if (! $b) {
                continue;
            }
            $partes[] = '- '.$b['quantidade'].' '.$rotulo.(! empty($b['valor']) ? ' — total '.$this->brl($b['valor']) : '');
            // As contas a pagar separadas (pedro-comunicacao, FR-007): vencidas de um lado, de hoje do outro.
            foreach (['vencidas' => 'vencidas', 'hoje' => 'vencendo hoje'] as $parte => $nome) {
                if (isset($b[$parte]['quantidade'])) {
                    $partes[] = '  - '.$nome.': '.$b[$parte]['quantidade'].(! empty($b[$parte]['valor']) ? ' — total '.$this->brl($b[$parte]['valor']) : '');
                }
            }
            $itens = (array) ($b['itens'] ?? []);
            if ($itens) {
                $partes[] = '  Lista (das mais antigas):';
            }
            foreach ($itens as $i) {
                $partes[] = $item($i);
            }
            // 31/10/2026: com 10 itens e 24 no total, o Pedro ofereceu "as outras 19". Só existe o que está aqui.
            if ($b['quantidade'] > count($itens)) {
                $partes[] = $itens
                    ? '  • (ESTA LISTA TEM SÓ '.count($itens).' DOS '.$b['quantidade'].': ofereça só estes; os outros '.($b['quantidade'] - count($itens)).' só no Gestor)'
                    : '  • (SEM A LISTA: só a quantidade; os itens só no Gestor)';
            }
            if ($maiores = (array) ($b['maiores'] ?? [])) {
                $partes[] = '  Maiores por valor ('.count($maiores).($b['quantidade'] > count($maiores) ? ' dos '.$b['quantidade'] : '').'):';
                foreach ($maiores as $i) {
                    $partes[] = $item($i);
                }
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

    /** "lido às 09:52", e DESATUALIZADO quando o retrato passou do limite (FR-002): só aí o Pedro fala da hora. */
    private function leitura($quando): string
    {
        $velho = CarbonImmutable::parse($quando)->lt(now()->subMinutes(self::RETRATO_VELHO_MINUTOS));

        return 'lido às '.$this->hora($quando).($velho ? '; DESATUALIZADO, avise' : '');
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
