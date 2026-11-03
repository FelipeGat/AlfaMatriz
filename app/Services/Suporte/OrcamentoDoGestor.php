<?php

namespace App\Services\Suporte;

use App\Models\SuporteConversa;
use App\Models\SuporteGestorOrcamento;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * O orçamento que um proprietário pede ao Pedro pelo WhatsApp (docs/specs/pedro-orcamento). A IA
 * entende o pedido e devolve o orçamento; aqui ele é conferido contra o retrato do Gestor e a prévia
 * é MONTADA pelo sistema — os números que o dono confirma são os que vão para o Gestor, e não os que
 * a IA escreveu. O "sim" põe UMA linha na fila (chave da prévia), que a máquina grava no Gestor pela
 * regra da tela; o total que vale é o que o Gestor calcular.
 *
 * A prévia guarda o que ele DISSE (o valor com nota, a quantidade que faltou), e não o convertido:
 * a correção de empresa ou de nota refaz a conta a partir do que foi dito (sessão 2 de 02/11/2026).
 */
class OrcamentoDoGestor
{
    /** Prévia que vale para o "sim": a última, de até 3 h, sem pendência (como a da agenda). */
    public const VALIDADE_HORAS = 3;

    public const PERGUNTA = 'Posso criar esse orçamento no Gestor?';

    /** A quantidade sugerida entra na confirmação, e não fica escondida nela (FR-025). */
    public const PERGUNTA_COM_SUGERIDA = 'Posso criar esse orçamento no Gestor, com a quantidade 1 que sugeri?';

    public const CRIANDO = 'Estou criando o orçamento no Gestor. Assim que ele confirmar, mando aqui o número e o link, sem você precisar perguntar.';

    public const SO_PROPRIETARIOS = 'Criar orçamento no Gestor pelo Pedro é só para os proprietários.';

    /** Bugfix de 02/11/2026: o caminho é o celular no cadastro de funcionário, e não pedir o e-mail a quem escreve. */
    public const SEM_VINCULO = 'Para eu criar orçamento no Gestor, preciso saber quem você é lá: ponha este celular no seu cadastro de funcionário do Gestor (o funcionário ligado ao seu usuário), '
        .'ou o e-mail do seu usuário na sua linha da lista do Pedro (Suporte › Pedro). É o seu usuário que vira o vendedor.';

    /** A falha que não diz se o Gestor gravou: a ponte caiu ou não respondeu (suporte.mjs). */
    private const INCERTO = 'conferir no Gestor';

    /**
     * As famílias de assunto do grupo (FR-024): um atendimento só é sugerido para o orçamento do mesmo
     * assunto. Felipe, 02/11/2026: "não quero que um atendimento de câmera seja sugerido como relacionado
     * a um orçamento de controle de portão apenas porque pertence ao mesmo cliente". As palavras saem da
     * descrição e do NOME do assunto do atendimento; a categoria do assunto fica de fora, porque
     * "SEGURANÇA ELETRÔNICA" junta câmera, alarme e acesso.
     */
    private const TEMAS = [
        'portão' => ['portao', 'portoes', 'motor', 'motores', 'cancela', 'cancelas', 'basculante', 'deslizante', 'pivotante', 'automatizador', 'automatizadores',
            'automatizacao', 'cremalheira', 'motorizacao', 'controle', 'controles', 'garagem'],
        'câmeras' => ['camera', 'cameras', 'cftv', 'dvr', 'nvr', 'gravador', 'gravacao', 'imagem', 'imagens', 'lente', 'dome', 'bullet'],
        'alarme' => ['alarme', 'alarmes', 'sensor', 'sensores', 'sirene', 'sirenes', 'cercaeletrica', 'concertina', 'monitoramento'],
        'interfone' => ['interfone', 'interfones', 'interfonia', 'porteiro', 'videoporteiro', 'ramal', 'ramais', 'pabx'],
        'controle de acesso' => ['controleacesso', 'acesso', 'catraca', 'catracas', 'biometria', 'biometrico', 'facial', 'leitor', 'leitora', 'tag', 'tags',
            'fechadura', 'fechaduras', 'eletroima', 'botoeira'],
        'ar-condicionado' => ['arcondicionado', 'split', 'splits', 'climatizacao', 'refrigeracao', 'evaporadora', 'condensadora', 'btu', 'btus'],
        'elétrica' => ['eletrica', 'eletrico', 'disjuntor', 'disjuntores', 'tomada', 'tomadas', 'fiacao', 'iluminacao', 'lampada', 'lampadas', 'refletor', 'refletores', 'energia'],
        'hidráulica' => ['hidraulica', 'hidraulico', 'vazamento', 'cano', 'canos', 'bomba', 'bombas', 'torneira', 'esgoto', 'infiltracao'],
        'informática' => ['computador', 'computadores', 'notebook', 'rede', 'internet', 'wifi', 'roteador', 'switch', 'servidor', 'impressora', 'software', 'nobreak'],
        'obra' => ['alvenaria', 'gesso', 'pintura', 'marcenaria', 'serralheria', 'solda', 'grade', 'grades', 'reforma', 'piso', 'telhado'],
    ];

    /** Expressões de duas palavras que mudam de família ("controle de acesso" não é controle de portão). */
    private const EXPRESSOES = ['controle de acesso' => 'controleacesso', 'controles de acesso' => 'controleacesso', 'cerca eletrica' => 'cercaeletrica',
        'ar condicionado' => 'arcondicionado', 'ar-condicionado' => 'arcondicionado'];

    /** Palavras comuns a qualquer serviço: em comum, não dizem que o assunto é o mesmo. */
    private const GENERICAS = ['manutencao', 'servico', 'servicos', 'instalacao', 'orcamento', 'troca', 'reparo', 'conserto', 'visita', 'tecnica', 'tecnico',
        'cliente', 'corretiva', 'preventiva', 'demanda', 'comercial', 'material', 'materiais', 'entrada', 'saida', 'predio', 'condominio', 'sistema'];

    public function __construct(private DiretorioGestor $diretorio) {}

    /** Quem não pode: o porquê, em uma frase. */
    public static function semPermissao(array $acesso): string
    {
        return ($acesso['perfil'] ?? '') === 'diretor' ? self::SEM_VINCULO : self::SO_PROPRIETARIOS;
    }

    /**
     * Um orçamento novo: confere e guarda a prévia; devolve o texto que vai para quem pediu.
     *
     * @param  array{nome: string, perfil: string, gestor_email: ?string}  $acesso
     */
    public function previa(SuporteConversa $c, array $acesso, array $orcamento): string
    {
        if (! AcessoInterno::criaOrcamento($acesso)) {
            return self::semPermissao($acesso);
        }
        $citados = app(AcessoInterno::class)->clientesCitados(app(Contexto::class)->textoParaRegistrar($c));
        // A IA remontou a prévia do mesmo cliente em vez de corrigir: o aviso já dado não volta (FR-027).
        $antes = $c->previa_orcamento;
        $mesma = $antes && empty($antes['confirmada_em']) && ($antes['orcamento']['cliente'] ?? null) === ($orcamento['cliente'] ?? null)
            && CarbonImmutable::parse($antes['em'])->gt(now()->subHours(self::VALIDADE_HORAS));

        return $this->montar($c, $acesso, $orcamento, $citados, $mesma ? (array) ($antes['avisados'] ?? []) : []);
    }

    /**
     * A correção depois da prévia (FR-026): só o que mudou, aplicado sobre o que já foi dito. Felipe,
     * 02/11/2026: "alterar empresa, NF, descrição, quantidade e valores por mensagens simples, sem perder
     * as demais informações já confirmadas" — a IA remontando a prévia inteira trocava nome de item e
     * observação sem ninguém pedir (cenário 5 da bateria de 02/11).
     *
     * @param  array{nome: string, perfil: string, gestor_email: ?string}  $acesso
     */
    public function corrigir(SuporteConversa $c, array $acesso, array $correcao): string
    {
        if (! AcessoInterno::criaOrcamento($acesso)) {
            return self::semPermissao($acesso);
        }
        $previa = $c->previa_orcamento;
        if (! $previa || ! is_array($previa['orcamento'] ?? null) || CarbonImmutable::parse($previa['em'])->lt(now()->subDay())) {
            return 'Não achei uma prévia de orçamento para corrigir: me diga o orçamento de novo e eu monto a prévia.';
        }
        if (! empty($previa['confirmada_em'])) {
            $enviado = SuporteGestorOrcamento::where('chave', (string) ($previa['chave'] ?? ''))->first();
            if ($enviado?->situacao === 'feito') {
                return 'Esse orçamento já está no Gestor (nº '.($enviado->resultado['numero'] ?? '?').'): a mudança agora é na tela do Gestor'
                    .(! empty($enviado->resultado['link']) ? ', em '.$enviado->resultado['link'] : '').'.';
            }
            if (! $enviado || $enviado->situacao !== 'falhou' || self::incerto($enviado)) {
                return 'Esse orçamento já foi enviado ao Gestor'.($enviado ? ' ('.$this->situacao($enviado).')' : '')
                    .'. Antes de mudar, preciso saber se ele entrou: '.($enviado && $this->repetivel($enviado)
                        ? 'responda "tentar de novo" e eu reenvio o mesmo pedido, sem criar outro.' : 'assim que o Gestor responder, eu aviso aqui.');
            }
            // O Gestor recusou: nada ficou lá, e a correção vira uma prévia nova (com outra chave).
        }

        return $this->montar($c, $acesso, self::aplicar((array) $previa['orcamento'], $correcao), null, (array) ($previa['avisados'] ?? []));
    }

    /**
     * O "sim" de quem pediu: a prévia, conferida de novo, vai para a fila do Gestor uma vez só. Depois
     * de enviada, o "sim" repete o envio que falhou, com a mesma chave (FR-023).
     *
     * @param  array{nome: string, perfil: string, gestor_email: ?string}  $acesso
     */
    public function confirmar(SuporteConversa $c, array $acesso): string
    {
        if (! AcessoInterno::criaOrcamento($acesso)) {
            return self::semPermissao($acesso);
        }
        $previa = $c->previa_orcamento;
        if ($previa && ! empty($previa['confirmada_em'])) {
            $enviado = SuporteGestorOrcamento::where('chave', (string) ($previa['chave'] ?? ''))->first();
            if ($enviado && $this->repetivel($enviado)) {
                return $this->reenviar($enviado);
            }

            return $enviado ? $this->jaEnviado($enviado) : 'Esse orçamento já foi enviado ao Gestor. Para outro orçamento, me diga qual e eu monto a prévia.';
        }
        if (! $previa || CarbonImmutable::parse($previa['em'])->lt(now()->subHours(self::VALIDADE_HORAS))) {
            return 'Não achei uma prévia de orçamento para confirmar: me diga o orçamento de novo e eu monto a prévia.';
        }
        if (($previa['gestor_email'] ?? null) !== $acesso['gestor_email']) {
            return 'A sua linha na lista do Pedro mudou desde a prévia: me diga o orçamento de novo e eu monto a prévia.';
        }
        // Reconfere: o retrato pode ter mudado desde a prévia. O cliente só precisa continuar no Gestor.
        $a = $this->analisar($c, (array) $previa['orcamento'], null);
        if ($a['problemas']) {
            return "Ainda não criei, porque conferi no Gestor e falta acertar:\n".collect($a['problemas'])->map(fn ($p) => '• '.$p)->implode("\n");
        }
        $chave = 'o'.$c->id.'-'.CarbonImmutable::parse($previa['em'])->format('YmdHis');
        SuporteGestorOrcamento::firstOrCreate(['chave' => $chave], ['conversa_id' => $c->id, 'pedido_por' => $previa['pedido_por'],
            'pedido' => $this->pedido($a['o'], (string) $acesso['gestor_email'], $previa['pedido_por'])]);
        $c->update(['previa_orcamento' => ['confirmada_em' => now()->toIso8601String(), 'chave' => $chave] + $previa]);

        return self::CRIANDO;
    }

    /**
     * "Tentar de novo" (FR-023): o último orçamento enviado nesta conversa volta à fila com a MESMA
     * chave. O Gestor devolve o que já existe por ela (matriz_chave), então a repetição nunca cria outro
     * — nem quando a ponte caiu depois de gravar.
     *
     * @param  array{nome: string, perfil: string, gestor_email: ?string}  $acesso
     */
    public function repetir(SuporteConversa $c, array $acesso): string
    {
        if (! AcessoInterno::criaOrcamento($acesso)) {
            return self::semPermissao($acesso);
        }
        $o = SuporteGestorOrcamento::where('conversa_id', $c->id)->where('created_at', '>=', now()->subDay())->orderByDesc('id')->first();
        if (! $o) {
            return 'Não achei orçamento enviado ao Gestor nesta conversa nas últimas 24 horas: me diga o orçamento e eu monto a prévia.';
        }

        return $this->repetivel($o) ? $this->reenviar($o) : $this->jaEnviado($o);
    }

    /** O resultado da máquina chegou (ou a gravação desistiu): a mensagem a quem pediu. */
    public function concluir(SuporteGestorOrcamento $o, CanalWhatsApp $canal): void
    {
        $r = (array) ($o->resultado ?? []);
        if ($o->situacao === 'feito') {
            $total = (float) ($r['valor_total'] ?? 0);
            $previa = (float) ($o->pedido['total_previa'] ?? 0);
            $nf = (bool) ($o->pedido['sem_nf'] ?? false) ? 'sem NF' : 'com NF'.(isset($r['imposto_nf_percentual']) ? ' de '.self::pct((float) $r['imposto_nf_percentual']) : '');
            $texto = (($r['ja_estava'] ?? false) ? 'Esse orçamento já estava no Gestor: nada foi criado de novo.' : '✅ Orçamento criado no Gestor.')
                ."\nNº ".($r['numero'] ?? '?').(! empty($r['empresa']) ? ' — '.$r['empresa'] : '').', em elaboração, com '.Str::before($o->pedido_por, ' ').' como vendedor.'
                ."\nTotal calculado pelo Gestor: ".self::brl($total)." ({$nf})."
                .($previa > 0 && abs($previa - $total) >= 0.01 ? "\nNa prévia eu mostrei ".self::brl($previa).': vale o do Gestor.' : '')
                .(! empty($r['link']) ? "\nAbrir no Gestor: ".$r['link'] : '')
                .(! empty($r['pdf']) ? "\nPDF (com login no Gestor): ".$r['pdf'] : '');
        } elseif (self::incerto($o)) {
            // A ponte caiu ou não respondeu: o Gestor pode ter gravado. A repetição usa a mesma chave.
            $texto = '⚠️ O Gestor não confirmou o orçamento: '.($o->erro ?: 'sem resposta').'. Ele pode ter sido criado.'
                ."\nResponda \"tentar de novo\" e eu reenvio o MESMO pedido: se ele já estiver lá, só trago o número, sem criar outro.";
        } else {
            $texto = '❌ Não criei o orçamento no Gestor: '.($o->erro ?: 'o Gestor não gravou').'. Nada ficou gravado.'
                ."\nCorrija o que precisar e eu remonto a prévia, ou responda \"tentar de novo\" para reenviar o mesmo pedido.";
        }
        try {
            $canal->enviar($o->conversa, $texto, 'pedro');
        } catch (ValidationException) {
            // Fora da janela: o resultado fica na fila e em "O QUE FOI ENVIADO AO GESTOR".
        }
    }

    /** O Gestor não confirmou a gravação a tempo (T-494): uma vez; a repetição com a mesma chave é segura. */
    public function semRetorno(SuporteGestorOrcamento $o, CanalWhatsApp $canal): void
    {
        $desde = $o->reservado_em->setTimezone(config('app.timezone'))->format('H:i');
        try {
            $canal->enviar($o->conversa, "O Gestor ainda não confirmou o orçamento que comecei a criar às {$desde}. Ele pode já estar lá. "
                .'Responda "tentar de novo" e eu reenvio o mesmo pedido, sem criar outro; ou espere: quando a confirmação chegar, eu aviso aqui.', 'pedro');
        } catch (ValidationException) {
            // Fora da janela: a situação fica registrada na conversa.
        }
    }

    /** A situação do orçamento enviado, em palavras, para a conversa e para o pedido à IA. */
    public function situacao(SuporteGestorOrcamento $o): string
    {
        return match ($o->situacao) {
            'pendente' => 'na fila, a máquina ainda não gravou',
            'reservado' => $o->erro ? 'gravando, sem confirmação do Gestor' : 'gravando agora',
            'feito' => 'GRAVADO, nº '.($o->resultado['numero'] ?? '?'),
            default => 'NÃO gravou'.($o->erro ? ' — '.$o->erro : ''),
        };
    }

    /**
     * Tudo o que a prévia precisa, a partir do que ele disse: o orçamento como vai ao Gestor (valor sem
     * nota, quantidade sugerida), a conta da nota, os problemas (bloqueiam) e os avisos (por chave, para
     * não repetir na correção).
     *
     * @param  ?array<string, array>  $citados  os clientes que podem ser usados na prévia ("gestor:ID" => cliente); null = só conferir que existe
     * @return array{o: array, nf: ?array, sugerida: bool, problemas: list<string>, avisos: array<string, string>}
     */
    public function analisar(?SuporteConversa $c, array $dito, ?array $citados): array
    {
        $r = $this->resolver($dito);
        [$problemas, $avisos] = $this->conferir($r['o'], $citados, $r['problemas']);
        if ($c && ($trava = $this->trava($c, $r['o']))) {
            $problemas[] = $trava;
        }

        return ['o' => $r['o'], 'nf' => $r['nf'], 'sugerida' => $r['sugerida'], 'problemas' => $problemas, 'avisos' => $avisos];
    }

    /**
     * O orçamento como vai ao Gestor. Valor dito com a nota (FR-022): a regra do Gestor é NF = subtotal ×
     * percentual, arredondada no centavo e somada ao subtotal (CriacaoDeOrcamento); aqui ela é desfeita,
     * escolhendo o centavo que faz o total do Gestor bater com o valor dito. Quantidade (FR-025): o único
     * item de serviço sem quantidade vai com 1, sugerida.
     *
     * @return array{o: array, nf: ?array, sugerida: bool, problemas: list<string>}
     */
    public function resolver(array $dito): array
    {
        $o = $dito;
        $itens = array_values((array) ($o['itens'] ?? []));
        // "Apenas um serviço" (Felipe, 02/11/2026): o único item de serviço; os materiais do lado não contam,
        // e a quantidade deles continua perguntada (3 controles não viram 1).
        $servicos = array_keys(array_filter($itens, fn ($i) => ($i['tipo'] ?? null) === 'servico'));
        $sugerida = count($servicos) === 1 && ($itens[$servicos[0]]['quantidade'] ?? null) === null;
        if ($sugerida) {
            $itens[$servicos[0]]['quantidade'] = 1;
        }
        $problemas = [];
        $nf = null;
        $totalComNf = is_numeric($o['total_com_nf'] ?? null) && $o['total_com_nf'] > 0 ? (float) $o['total_com_nf'] : null;
        if (($o['valor_com_nf'] ?? false) === true || $totalComNf !== null) {
            // O valor "já com nota" diz que há nota.
            $o['sem_nf'] ??= false;
            $empresa = $this->diretorio->empresas()[$o['empresa_id'] ?? 0] ?? null;
            if ($o['sem_nf'] === true) {
                $problemas[] = 'você disse o valor já com a nota, e o orçamento está sem nota: sem nota, qual o valor de cada item?';
            } elseif ($empresa && $empresa['imposto_nf'] === null) {
                $problemas[] = "não tenho agora o percentual de NF da {$empresa['nome']}: me diga o valor de cada item sem a nota.";
            } elseif ($totalComNf !== null && count($itens) !== 1) {
                $problemas[] = 'com mais de um item, me diga o valor de cada um (com ou sem nota), e não só o total.';
            } elseif ($empresa) {
                [$itens, $nf] = self::tirarNota($itens, (float) $empresa['imposto_nf'], $totalComNf);
            }
            // Sem a empresa, a falta dela já aparece; com ela, a conta é feita.
        }
        $o['itens'] = $itens;

        return ['o' => $o, 'nf' => $nf, 'sugerida' => $sugerida, 'problemas' => $problemas];
    }

    /**
     * Desfaz a nota. Cada valor dito é de UMA unidade com a nota (ou o total, num item só); o alvo é o
     * total que ele disse. Arredondar cada valor no centavo pode deixar o total 1 ou 2 centavos fora:
     * tenta-se ±1 e ±2 centavos num item, e, se nenhum bate, a prévia mostra a diferença.
     *
     * @return array{0: list<array>, 1: ?array}
     */
    private static function tirarNota(array $itens, float $pct, ?float $totalComNf): array
    {
        $fator = 1 + $pct / 100;
        if ($totalComNf !== null) {
            $q = $itens[0]['quantidade'] ?? null;
            if (! is_numeric($q) || $q < 1 || floor((float) $q) != (float) $q) {
                return [$itens, null];
            }
            $itens[0]['valor_unitario'] = $totalComNf / $q;
        }
        foreach ($itens as $i) {
            $q = $i['quantidade'] ?? null;
            if (! is_numeric($i['valor_unitario'] ?? null) || $i['valor_unitario'] <= 0 || ! is_numeric($q) || $q < 1 || floor((float) $q) != (float) $q) {
                return [$itens, null];
            }
        }
        $alvo = round($totalComNf ?? collect($itens)->sum(fn ($i) => $i['quantidade'] * $i['valor_unitario']), 2);
        $total = function (array $bases) use ($itens, $pct): float {
            $sub = 0.0;
            foreach ($itens as $k => $i) {
                $sub += $i['quantidade'] * $bases[$k];
            }
            $sub = round($sub, 2);

            return round($sub + round($sub * $pct / 100, 2), 2);
        };
        $bases = array_map(fn ($i) => round($i['valor_unitario'] / $fator, 2), $itens);
        if (abs($total($bases) - $alvo) >= 0.005) {
            foreach ([0.01, -0.01, 0.02, -0.02] as $delta) {
                foreach (array_keys($bases) as $k) {
                    $tenta = $bases;
                    $tenta[$k] = round($tenta[$k] + $delta, 2);
                    if ($tenta[$k] > 0 && abs($total($tenta) - $alvo) < 0.005) {
                        $bases = $tenta;
                        break 2;
                    }
                }
            }
        }
        $conta = [];
        foreach ($itens as $k => $i) {
            $conta[] = ['n' => $k + 1, 'com_nf' => (float) $i['valor_unitario'], 'sem_nf' => $bases[$k], 'quantidade' => (int) $i['quantidade']];
            $itens[$k]['valor_unitario'] = $bases[$k];
        }

        return [$itens, ['pct' => $pct, 'fator' => $fator, 'alvo' => $alvo, 'total' => $total($bases), 'do_total' => $totalComNf !== null, 'itens' => $conta]];
    }

    /**
     * @param  ?array<string, array>  $citados  os clientes que podem ser usados na prévia ("gestor:ID" => cliente); null = só conferir que existe
     * @param  list<string>  $daNota  os problemas da conta da nota, que entram depois dos da nota fiscal
     * @return array{0: list<string>, 1: array<string, string>} problemas (bloqueiam) e avisos (por chave)
     */
    public function conferir(array $o, ?array $citados, array $daNota = []): array
    {
        $problemas = [];
        $avisos = [];
        $cliente = $this->cliente($o['cliente'] ?? null);
        if (! $cliente || ($citados !== null && ! isset($citados[(string) $o['cliente']]))) {
            $problemas[] = 'o cliente não foi confirmado no Gestor: diga o nome como está cadastrado (não crio cliente nem pré-cliente).';
        }
        $empresas = $this->diretorio->empresas();
        if (! ($o['empresa_id'] ?? null)) {
            $problemas[] = 'falta a empresa que emite o orçamento ('.collect($empresas)->pluck('nome')->implode(', ').')'
                .(! empty($cliente['empresa']) ? ' — no cadastro, o cliente é da '.$cliente['empresa'] : '')
                .(($o['valor_com_nf'] ?? false) || isset($o['total_com_nf']) ? '; com ela eu tiro a nota do valor que você disse' : '').'.';
        } elseif (! isset($empresas[$o['empresa_id']])) {
            $problemas[] = 'a empresa não está entre as do Gestor ('.collect($empresas)->pluck('nome')->implode(', ').').';
        }
        if (($o['sem_nf'] ?? null) === null) {
            $problemas[] = 'falta dizer se é com ou sem nota fiscal.';
        }
        array_push($problemas, ...$daNota);
        if (! filled($o['descricao'] ?? null)) {
            $problemas[] = 'falta a descrição do orçamento (o que é, em uma linha).';
        }
        $itens = (array) ($o['itens'] ?? []);
        if (! $itens) {
            $problemas[] = 'falta pelo menos um item: o serviço ou o material, a quantidade e o valor.';
        } elseif (count($itens) > 50) {
            $problemas[] = 'são '.count($itens).' itens, e o Gestor aceita até 50.';
        }
        // Agrupado por tipo de falta (FR-027): uma linha por pergunta, e não uma por item.
        $faltas = ['nome' => [], 'tipo' => [], 'quantidade' => [], 'faixa' => [], 'valor' => [], 'zero' => []];
        foreach (array_slice($itens, 0, 50) as $i => $item) {
            $n = 'item '.($i + 1).(filled($item['nome'] ?? null) ? ' ('.$item['nome'].')' : '');
            if (! filled($item['nome'] ?? null)) {
                $faltas['nome'][] = $n;
            }
            if (! in_array($item['tipo'] ?? null, ['servico', 'produto'], true)) {
                $faltas['tipo'][] = $n;
            }
            $qtd = $item['quantidade'] ?? null;
            if ($qtd === null) {
                $faltas['quantidade'][] = $n;
            } elseif (floor((float) $qtd) != (float) $qtd) {
                $problemas[] = "{$n}: a quantidade precisa ser um número inteiro (a medida vai na descrição do item, ex.: \"2,5 m\").";
            } elseif ($qtd < 1 || $qtd > 9999) {
                $faltas['faixa'][] = $n;
            }
            $valor = $item['valor_unitario'] ?? null;
            if ($valor === null) {
                $faltas['valor'][] = $n;
            } elseif ($valor <= 0 || $valor > 99999999) {
                $faltas['zero'][] = $n;
            }
        }
        foreach (['nome' => 'falta o nome: %s.', 'tipo' => 'é serviço ou material: %s?', 'quantidade' => 'falta a quantidade: %s.',
            'faixa' => 'a quantidade vai de 1 a 9999: %s.', 'valor' => 'falta o valor: %s.', 'zero' => 'o valor precisa ser maior que zero: %s.'] as $tipo => $frase) {
            if ($faltas[$tipo]) {
                $problemas[] = sprintf($frase, self::lista($faltas[$tipo]));
            }
        }
        if (filled($o['validade'] ?? null) && $o['validade'] < now(config('app.timezone'))->toDateString()) {
            $problemas[] = 'a validade já passou.';
        }
        $this->conferirAtendimento($o, $cliente, $problemas, $avisos);

        return [$problemas, $avisos];
    }

    /**
     * O vínculo com o atendimento: só do mesmo cliente. Sem número, avisa só os abertos do MESMO assunto
     * (FR-011, FR-024); se ele já disse que não é de nenhum, não pergunta de novo.
     */
    private function conferirAtendimento(array $o, ?array $cliente, array &$problemas, array &$avisos): void
    {
        $temas = self::temas($this->textoDoOrcamento($o));
        $numero = $o['atendimento'] ?? null;
        if ($numero) {
            $at = $this->diretorio->atendimentoPorNumero((string) $numero);
            if (! $at) {
                $problemas[] = "não achei o atendimento nº {$numero} no Gestor.";
            } elseif ($cliente && (int) ($at['cliente_id'] ?? 0) !== (int) $cliente['id']) {
                $dono = $this->diretorio->cliente((int) ($at['cliente_id'] ?? 0));
                $problemas[] = "o atendimento nº {$numero} é de outro cliente".($dono ? ' ('.self::nome($dono).')' : '').': corrija o número ou tire o vínculo.';
            } elseif ($temas && ($doAtendimento = self::temas($this->textoDoAtendimento($at))) && ! array_intersect($temas, $doAtendimento)) {
                // Ele citou: o vínculo vale, mas o assunto diferente merece um olhar.
                $avisos["assunto:{$numero}"] = "o atendimento nº {$numero} é de ".self::lista($doAtendimento).', e o orçamento é de '.self::lista($temas).': confira se o vínculo está certo.';
            }

            return;
        }
        if (! $cliente || ($o['sem_vinculo'] ?? false)) {
            return;
        }
        $abertos = collect($this->diretorio->atendimentosDe((int) $cliente['id'], 20))
            ->reject(fn ($a) => in_array($a['status'] ?? '', ['concluido', 'cancelado'], true))
            ->filter(fn ($a) => $this->mesmoAssunto($o, $temas, $a))->values();
        if ($abertos->isEmpty()) {
            return;
        }
        $lista = $abertos->take(5)->map(fn ($a) => 'nº '.$a['numero'].' — '.Str::limit(trim((string) ($a['descricao'] ?? '')), 50, '…'))->implode('; ');
        $chave = 'abertos:'.$abertos->take(5)->pluck('numero')->implode(',');
        $avisos[$chave] = $abertos->count() === 1
            ? self::nome($cliente)." tem o atendimento {$lista} em aberto, do mesmo assunto: se o orçamento for dele, diga; senão, fica sem vínculo."
            : self::nome($cliente)." tem atendimentos do mesmo assunto em aberto ({$lista}): se o orçamento for de um deles, diga o número; senão, fica sem vínculo.";
    }

    /**
     * Mesmo assunto: uma família em comum; se o orçamento não cai em família nenhuma, uma palavra
     * significativa em comum. Atendimento sem nada em comum não é sugerido.
     *
     * @param  list<string>  $temas  as famílias do orçamento
     */
    private function mesmoAssunto(array $o, array $temas, array $at): bool
    {
        $texto = $this->textoDoAtendimento($at);
        if ($temas) {
            return (bool) array_intersect($temas, self::temas($texto));
        }

        return (bool) array_intersect(self::significativas($this->textoDoOrcamento($o)), self::significativas($texto));
    }

    private function textoDoOrcamento(array $o): string
    {
        return trim(($o['descricao'] ?? '').' '.collect((array) ($o['itens'] ?? []))->map(fn ($i) => ($i['nome'] ?? '').' '.($i['descricao'] ?? ''))->implode(' '));
    }

    /** A descrição e o nome do assunto do atendimento (FR-024). */
    private function textoDoAtendimento(array $at): string
    {
        $assunto = ! empty($at['assunto_id']) ? collect($this->diretorio->retrato()['assuntos'] ?? [])->firstWhere('id', (int) $at['assunto_id']) : null;

        return trim((string) ($at['descricao'] ?? '').' '.(string) ($assunto['nome'] ?? ''));
    }

    /** @return list<string> as famílias de assunto do texto, na ordem de TEMAS */
    public static function temas(string $texto): array
    {
        $palavras = array_flip(self::palavras($texto));

        return array_values(array_keys(array_filter(self::TEMAS, fn ($lista) => (bool) array_intersect_key(array_flip($lista), $palavras))));
    }

    /** @return list<string> */
    private static function palavras(string $texto): array
    {
        $t = mb_strtolower(Str::ascii($texto));
        $t = strtr($t, self::EXPRESSOES);

        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $t) ?: []));
    }

    /** @return list<string> */
    private static function significativas(string $texto): array
    {
        return array_values(array_diff(array_filter(self::palavras($texto), fn ($p) => strlen($p) >= 5), self::GENERICAS));
    }

    /** O que trava uma prévia nova: um orçamento do mesmo cliente ainda sem resposta certa do Gestor (FR-023). */
    private function trava(SuporteConversa $c, array $o): ?string
    {
        $id = preg_match('/^gestor:(-?\d+)$/', (string) ($o['cliente'] ?? ''), $m) ? (int) $m[1] : 0;
        if (! $id) {
            return null;
        }
        $aberto = SuporteGestorOrcamento::where('conversa_id', $c->id)->where('created_at', '>=', now()->subDay())->whereIn('situacao', ['pendente', 'reservado', 'falhou'])
            ->orderByDesc('id')->get()
            ->first(fn ($x) => ($x->situacao !== 'falhou' || self::incerto($x))
                && ($id > 0 ? (int) ($x->pedido['cliente_id'] ?? 0) === $id : (int) ($x->pedido['pre_cliente_id'] ?? 0) === -$id));
        if (! $aberto) {
            return null;
        }
        $as = $aberto->created_at->setTimezone(config('app.timezone'))->format('H:i');

        // Só passam aqui a falha incerta, o sem retorno (os dois se repetem) e o que ainda está na máquina.
        return $aberto->situacao === 'falhou' || $aberto->erro !== null
            ? "o orçamento que confirmei às {$as} para este cliente ainda não teve resposta certa do Gestor: responda \"tentar de novo\" para eu reenviar o mesmo pedido (sem criar em dobro) antes de montar outro."
            : "o orçamento que confirmei às {$as} para este cliente ainda está sendo criado no Gestor: espere o número chegar (eu mando aqui) antes de montar outro.";
    }

    /** O cliente do retrato pelo código "gestor:ID" (ID negativo = pré-cliente). */
    private function cliente(mixed $codigo): ?array
    {
        return is_string($codigo) && preg_match('/^gestor:(-?\d+)$/', $codigo, $m) ? $this->diretorio->cliente((int) $m[1]) : null;
    }

    /** @return array{subtotal: float, pct: ?float, nf: ?float, total: ?float} a conta da tela do Gestor, com o percentual do retrato */
    public function conta(array $o): array
    {
        $subtotal = round(collect((array) ($o['itens'] ?? []))->sum(fn ($i) => (float) ($i['quantidade'] ?? 0) * (float) ($i['valor_unitario'] ?? 0)), 2);
        if (($o['sem_nf'] ?? null) === true) {
            return ['subtotal' => $subtotal, 'pct' => null, 'nf' => 0.0, 'total' => $subtotal];
        }
        $pct = $this->diretorio->empresas()[$o['empresa_id'] ?? 0]['imposto_nf'] ?? null;
        $nf = $pct === null || ($o['sem_nf'] ?? null) === null ? null : round($subtotal * $pct / 100, 2);

        return ['subtotal' => $subtotal, 'pct' => $pct, 'nf' => $nf, 'total' => $nf === null ? null : round($subtotal + $nf, 2)];
    }

    /**
     * A correção (FR-026) sobre o que ele disse: só os campos que vieram; itens pelo número da prévia
     * (alterar, remover) ou sem número (novo). Remoções depois das alterações, pelos números de antes.
     */
    public static function aplicar(array $dito, array $correcao): array
    {
        foreach (['empresa_id', 'sem_nf', 'valor_com_nf', 'descricao', 'observacoes', 'validade', 'forma_pagamento', 'prazo_pagamento', 'total_com_nf'] as $campo) {
            if (array_key_exists($campo, $correcao) && $correcao[$campo] !== null) {
                $dito[$campo] = $correcao[$campo];
            }
        }
        if (($correcao['valor_com_nf'] ?? null) === false) {
            unset($dito['total_com_nf']);
        }
        if (($correcao['atendimento'] ?? null) === 'nenhum' || ($correcao['sem_vinculo'] ?? null) === true) {
            [$dito['atendimento'], $dito['sem_vinculo']] = [null, true];
        } elseif (filled($correcao['atendimento'] ?? null)) {
            [$dito['atendimento'], $dito['sem_vinculo']] = [(string) $correcao['atendimento'], false];
        }
        $itens = array_values((array) ($dito['itens'] ?? []));
        $remover = [];
        foreach ((array) ($correcao['itens'] ?? []) as $mudanca) {
            $k = isset($mudanca['item']) ? (int) $mudanca['item'] - 1 : null;
            if ($k === null) {
                $itens[] = array_intersect_key($mudanca, array_flip(['nome', 'tipo', 'quantidade', 'valor_unitario', 'descricao']))
                    + ['nome' => null, 'tipo' => null, 'quantidade' => null, 'valor_unitario' => null, 'descricao' => null];
            } elseif (isset($itens[$k])) {
                if ($mudanca['remover'] ?? false) {
                    $remover[] = $k;
                }
                foreach (['nome', 'tipo', 'quantidade', 'valor_unitario', 'descricao'] as $campo) {
                    if (($mudanca[$campo] ?? null) !== null) {
                        $itens[$k][$campo] = $mudanca[$campo];
                    }
                }
            }
        }
        $dito['itens'] = array_values(array_diff_key($itens, array_flip($remover)));

        return $dito;
    }

    /** Monta, guarda e devolve a prévia; o aviso já dado nesta prévia não volta (FR-027). */
    private function montar(SuporteConversa $c, array $acesso, array $dito, ?array $citados, array $avisados): string
    {
        $a = $this->analisar($c, $dito, $citados);
        $novos = array_values(array_diff_key($a['avisos'], array_flip($avisados)));
        $c->update(['previa_orcamento' => ['em' => now()->toIso8601String(), 'pedido_por' => $acesso['nome'], 'gestor_email' => $acesso['gestor_email'],
            'orcamento' => $dito, 'problemas' => $a['problemas'], 'avisos' => $novos,
            'avisados' => array_values(array_unique([...$avisados, ...array_keys($a['avisos'])]))]]);

        return $this->texto($acesso['nome'], $a, $novos);
    }

    /**
     * A prévia no formato combinado (spec, FR-009). Com pendência, só o que falta: uma prévia cheia de "?"
     * vira formulário (o dono pediu "sem transformar a conversa num formulário"), e não há o que confirmar.
     * Os itens são numerados para a correção por número (FR-026).
     *
     * @param  list<string>  $avisos
     */
    private function texto(string $quem, array $a, array $avisos): string
    {
        $o = $a['o'];
        $cliente = $this->cliente($o['cliente'] ?? null);
        $atencao = $avisos ? "\n\nAtenção:\n".collect($avisos)->map(fn ($x) => '• '.$x)->implode("\n") : '';
        $at = ! empty($o['atendimento']) ? $this->diretorio->atendimentoPorNumero((string) $o['atendimento']) : null;
        if ($a['problemas']) {
            // O atendimento achado vai no cabeçalho: com o que falta, ele sabe de qual se trata (cenário 2 de 02/11).
            $doAtendimento = $at && $cliente && (int) ($at['cliente_id'] ?? 0) === (int) $cliente['id']
                ? ' (atendimento nº '.$o['atendimento'].' — '.Str::limit(trim((string) ($at['descricao'] ?? '')), 60, '…').')' : '';

            return Str::before($quem, ' ').', para montar o orçamento'.($cliente ? ' para '.self::nome($cliente) : '').$doAtendimento." falta acertar:\n"
                .collect($a['problemas'])->map(fn ($p) => '• '.$p)->implode("\n").$atencao;
        }
        $empresa = $this->diretorio->empresas()[$o['empresa_id'] ?? 0] ?? null;
        $conta = $this->conta($o);
        $tipo = ['servico' => 'serviço', 'produto' => 'material'];
        $nf = match ($o['sem_nf'] ?? null) {
            true => 'sem NF',
            false => 'com NF'.($conta['pct'] !== null ? ' ('.self::pct($conta['pct']).' da '.($empresa['nome'] ?? 'empresa').' no Gestor)' : ' (o Gestor soma o imposto da empresa ao criar)'),
            default => '?',
        };
        $linhas = [Str::before($quem, ' ').', organizei o orçamento assim:',
            'Cliente: '.($cliente ? self::nome($cliente).' ('.(! empty($cliente['pre']) ? 'pré-cliente' : 'cliente').(! empty($cliente['cidade']) ? ', '.$cliente['cidade'] : '').')' : '?'),
            'Empresa: '.($empresa['nome'] ?? '?'),
            'Vendedor: '.Str::before($quem, ' '),
            'Nota fiscal: '.$nf,
            'Atendimento relacionado: '.(! empty($o['atendimento']) ? 'nº '.$o['atendimento'].($at ? ' — '.Str::limit(trim((string) ($at['descricao'] ?? '')), 60, '…') : '') : 'sem vínculo'),
            'Descrição: '.($o['descricao'] ?? '?'),
            'Itens:'];
        foreach (array_values((array) ($o['itens'] ?? [])) as $k => $i) {
            $q = $i['quantidade'] ?? null;
            $v = $i['valor_unitario'] ?? null;
            $linhas[] = '• Item '.($k + 1).': '.($q !== null ? self::numero((float) $q).' × ' : '? × ').($i['nome'] ?? '?').' ('.($tipo[$i['tipo'] ?? ''] ?? '?').')'
                .(filled($i['descricao'] ?? null) ? ' — '.$i['descricao'] : '')
                .' — '.($v !== null ? self::brl((float) $v).($q !== null && (float) $q != 1 ? ' cada = '.self::brl((float) $q * (float) $v) : '') : 'valor ?')
                .($a['sugerida'] && ($i['tipo'] ?? null) === 'servico' ? ' (quantidade 1 sugerida por mim)' : '');
        }
        $linhas[] = 'Subtotal: '.self::brl($conta['subtotal']);
        if (($o['sem_nf'] ?? null) === false) {
            $linhas[] = $conta['nf'] !== null ? 'NF ('.self::pct($conta['pct']).'): '.self::brl($conta['nf']) : 'NF: calculada pelo Gestor ao criar';
        }
        $linhas[] = 'Total: '.($conta['total'] !== null ? self::brl($conta['total']) : self::brl($conta['subtotal']).' + NF');
        if ($a['nf']) {
            array_push($linhas, ...$this->textoDaNota($a['nf'], (string) ($empresa['nome'] ?? 'empresa')));
        }
        foreach (['observacoes' => 'Observações', 'forma_pagamento' => 'Forma de pagamento', 'prazo_pagamento' => 'Prazo de pagamento'] as $campo => $rotulo) {
            if (filled($o[$campo] ?? null)) {
                $linhas[] = $rotulo.': '.$o[$campo];
            }
        }
        if (filled($o['validade'] ?? null)) {
            $linhas[] = 'Validade: '.CarbonImmutable::parse($o['validade'])->format('d/m/Y');
        }

        return implode("\n", $linhas).$atencao."\n\n".($a['sugerida'] ? self::PERGUNTA_COM_SUGERIDA : self::PERGUNTA);
    }

    /** A conta da nota, para ele conferir sem fazer conta (FR-022). @return list<string> */
    private function textoDaNota(array $nf, string $empresa): array
    {
        $fator = rtrim(rtrim(number_format($nf['fator'], 4, ',', ''), '0'), ',');
        $igual = fn (float $a, float $b) => abs($a / $nf['fator'] - $b) < 0.0005 ? '=' : '≈';
        $linhas = ['Conta da nota: você disse o valor já com NF; tirei os '.self::pct($nf['pct'])." da {$empresa}:"];
        if ($nf['do_total']) {
            $i = $nf['itens'][0];
            $linhas[] = '• Total com NF '.self::brl($nf['alvo'])." ÷ {$fator} ".$igual($nf['alvo'], $i['quantidade'] * $i['sem_nf']).' '.self::brl($i['quantidade'] * $i['sem_nf']).' sem nota'
                .($i['quantidade'] > 1 ? " ({$i['quantidade']} × ".self::brl($i['sem_nf']).')' : '');
        } else {
            foreach ($nf['itens'] as $i) {
                $linhas[] = "• Item {$i['n']}: ".self::brl($i['com_nf'])." ÷ {$fator} ".$igual($i['com_nf'], $i['sem_nf']).' '.self::brl($i['sem_nf']).' sem nota';
            }
        }
        $dif = round($nf['total'] - $nf['alvo'], 2);
        $linhas[] = abs($dif) < 0.005
            ? 'O Gestor soma a NF de volta e o total fica '.self::brl($nf['total']).', igual ao que você disse.'
            : 'O Gestor soma a NF de volta e o total fica '.self::brl($nf['total']).': '.self::brl(abs($dif)).($dif > 0 ? ' acima' : ' abaixo').' do que você disse, por arredondamento de centavos.';

        return $linhas;
    }

    /** O item da fila, no contrato runner-orcamento §1: o orçamento já resolvido (valor sem nota, quantidade). */
    private function pedido(array $o, string $email, string $quem): array
    {
        $id = (int) Str::after((string) $o['cliente'], 'gestor:');

        return ['autor_email' => $email, 'pedido_por' => $quem,
            'cliente_id' => $id > 0 ? $id : null, 'pre_cliente_id' => $id < 0 ? -$id : null,
            'empresa_id' => (int) $o['empresa_id'], 'sem_nf' => (bool) $o['sem_nf'], 'atendimento' => $o['atendimento'] ?? null,
            'descricao' => (string) $o['descricao'], 'observacoes' => $o['observacoes'] ?? null, 'validade' => $o['validade'] ?? null,
            'forma_pagamento' => $o['forma_pagamento'] ?? null, 'prazo_pagamento' => $o['prazo_pagamento'] ?? null,
            'itens' => collect((array) $o['itens'])->map(fn ($i) => ['nome' => (string) $i['nome'], 'tipo' => (string) $i['tipo'],
                'quantidade' => (int) $i['quantidade'], 'valor_unitario' => round((float) $i['valor_unitario'], 2), 'descricao' => $i['descricao'] ?? null])->values()->all(),
            'total_previa' => $this->conta($o)['total']];
    }

    /** Falhou, ou ficou sem retorno (a ponte já desistiu: 10 min contra 3 min de ponte): a mesma chave volta. */
    private function repetivel(SuporteGestorOrcamento $o): bool
    {
        return $o->situacao === 'falhou' || ($o->situacao === 'reservado' && $o->erro !== null);
    }

    /** A falha que não diz se o Gestor gravou. */
    private static function incerto(SuporteGestorOrcamento $o): bool
    {
        return $o->situacao === 'reservado' || ($o->situacao === 'falhou' && str_contains((string) $o->erro, self::INCERTO));
    }

    private function reenviar(SuporteGestorOrcamento $o): string
    {
        // updated_at é o relógio da fila: o prazo da máquina conta da volta à fila, e não do primeiro "sim".
        $o->update(['situacao' => 'pendente', 'erro' => null, 'reservado_em' => null, 'resultado' => null]);

        return 'Reenviei ao Gestor o MESMO pedido, com a mesma chave: se ele já tiver sido criado, eu só trago o número, sem criar outro. '
            .'Assim que o Gestor confirmar, mando aqui o número e o link.';
    }

    private function jaEnviado(SuporteGestorOrcamento $o): string
    {
        return $o->situacao === 'feito'
            ? 'Esse orçamento já está no Gestor: nº '.($o->resultado['numero'] ?? '?').(! empty($o->resultado['link']) ? ' — '.$o->resultado['link'] : '')
                .'. Para outro orçamento, me diga qual e eu monto a prévia.'
            : 'Esse orçamento já foi enviado ao Gestor e está '.$this->situacao($o).'. Assim que ele confirmar, mando aqui o número e o link.';
    }

    /** @param  list<string>  $itens */
    private static function lista(array $itens): string
    {
        return count($itens) > 1 ? implode(', ', array_slice($itens, 0, -1)).' e '.end($itens) : (string) ($itens[0] ?? '');
    }

    private static function nome(array $c): string
    {
        return trim((string) (($c['fantasia'] ?? '') ?: ($c['nome'] ?? 'cliente')));
    }

    public static function brl(float $v): string
    {
        return 'R$ '.number_format($v, 2, ',', '.');
    }

    private static function pct(float $p): string
    {
        return rtrim(rtrim(number_format($p, 2, ',', ''), '0'), ',').'%';
    }

    private static function numero(float $n): string
    {
        return floor($n) == $n ? (string) (int) $n : rtrim(rtrim(number_format($n, 3, ',', ''), '0'), ',');
    }
}
