<?php

namespace App\Models;

use App\Concerns\Auditavel;
use App\Services\FluxoTarefaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Tarefa extends Model
{
    use Auditavel, HasFactory;

    protected string $recursoAuditoria = 'tarefas';

    /**
     * A posição do card na coluna fica FORA do rastro.
     *
     * Arrastar um card para reordenar não é fato de negócio — é arrumação de
     * mesa, e acontece dezenas de vezes por dia. Registrada, ela empurraria
     * para fora da primeira página a mudança de etapa que aconteceu no meio.
     *
     * Hoje a reordenação nem chega aqui (`TarefaController` a faz por query,
     * que não dispara evento), mas a exclusão é declarada assim mesmo: quem
     * um dia trocar aquele `update` por um salvamento de modelo não deveria
     * descobrir o efeito colateral pela tela de auditoria.
     *
     * @return array<int, string>
     */
    public function camposForaDaAuditoria(): array
    {
        return ['ordem'];
    }

    use SoftDeletes;

    /**
     * Ordem do quadro.
     *
     * "Em andamento" e não mais "Em desenvolvimento": a coluna passou a receber
     * também tarefa operacional, que não é desenvolvida — e um telefonema para
     * o fabricante parado numa coluna chamada "Em desenvolvimento" faria a
     * coluna mentir. A CHAVE continua `em_desenvolvimento` de propósito: ela
     * está gravada em milhares de linhas de `tarefa_eventos`, e renomear o dado
     * para arrumar um rótulo de tela reescreveria histórico.
     *
     * `em_producao` é a etapa em que a tag JÁ subiu e ninguém conferiu no ar
     * ainda. Sem ela a tarefa era dada como concluída no instante do deploy, e o
     * time faz TRÊS testes, não dois: local (que não entra no quadro), staging e
     * produção. O terceiro não tinha onde acontecer — quem esperava alguém
     * validar no ar só tinha o bloqueio para registrar a espera, e ele é a marca
     * errada: tira a tarefa do WIP e não guarda veredito nem quem validou.
     * Concluída passou a significar no ar E CONFERIDO.
     *
     * As duas metades de cada ambiente andam JUNTAS, e é o que dá simetria ao
     * quadro: em Em staging o código sobe e é testado; em Em produção, a mesma
     * coisa. A antiga "Pronta p/ produção" separava a subida do teste só de um
     * dos lados — o staging nunca teve uma fila equivalente —, e duas colunas
     * com "produção" no nome diziam coisas opostas sobre estar no ar.
     */
    public const STATUS = [
        'aberta' => 'Aberta',
        'backlog' => 'Backlog',
        'em_desenvolvimento' => 'Em andamento',
        'em_revisao' => 'Em revisão',
        'em_staging' => 'Em staging',
        'em_producao' => 'Em produção',
        'concluida' => 'Concluída',
        'cancelada' => 'Cancelada',
    ];

    /**
     * Os portões do ciclo de desenvolvimento — onde a tarefa é examinada.
     *
     * Reprovar num deles devolve a tarefa para Em andamento carimbando de qual
     * portão ela voltou. É essa distinção que a coluna única de Ajustes
     * achatava: reprovar um PR, quebrar em staging e ser barrada na porta da
     * produção chegavam todas ao mesmo lugar, sem dizer de onde vinham.
     *
     * `em_producao` é o terceiro, e o único em que o defeito está NO AR enquanto
     * o card volta — a reprovação dele custa mais que a dos outros dois, e por
     * isso é a que menos podia continuar sem lugar no quadro.
     */
    public const PORTOES = ['em_revisao', 'em_staging', 'em_producao'];

    /**
     * Os portões onde a bola muda de mão para EXAME — revisão e staging.
     *
     * É neles que o painel do movimento oferece apontar quem revisa ou quem
     * testa, e é neles que a conversa recomeça a cada entrada (Q-037): quem
     * chega fala com o examinador DESTA passagem, não com o da etapa
     * anterior.
     *
     * Em produção é o terceiro, e o que mais depende do apontamento: quem
     * confere no ar NEM SEMPRE é quem testou no staging. Às vezes é a mesma
     * pessoa, às vezes não — e é justamente por variar que o nome precisa estar
     * no card: sem ele a coluna deixa de responder quem o quadro está
     * esperando, que é a única pergunta que uma coluna existe para responder.
     */
    public const PORTOES_DE_EXAME = ['em_revisao', 'em_staging', 'em_producao'];

    /**
     * Os portões em que alguém registra um VEREDITO — aprovado ou reprovado.
     *
     * Staging e produção: nos dois alguém roda o sistema e diz se passou, e o
     * relatório nasce assinado e preso ao evento da passagem. Em revisão fica
     * de fora — lá se lê um PR, e chamar as duas coisas de "aprovado" daria o
     * mesmo nome a exames que falham de formas diferentes.
     *
     * É esta lista que faz um botão só servir às duas etapas. E o botão existe
     * separado do movimento porque quem valida nem sempre é quem move: travar o
     * registro não impediria o teste, impediria de REGISTRÁ-LO.
     */
    public const PORTOES_DE_VEREDITO = ['em_staging', 'em_producao'];

    /**
     * O que cada portão examina, dito no cabeçalho da própria coluna.
     *
     * Sem isso, "Em revisão" e "Em staging" são dois nomes que só quem escreveu
     * o fluxo distingue. Com a linha, a coluna declara quem analisa e o que ele
     * está olhando — a informação que separa os dois portões que a etapa única
     * de Em testes mantinha embolados.
     */
    public const PORTAO_DA_ETAPA = [
        'em_revisao' => 'PR · admin analisa',
        'em_staging' => 'na main · dev valida, e espera a tag',
        'em_producao' => 'no ar · aguardando validação',
    ];

    /**
     * O que a coluna diz quando está vazia.
     *
     * Uma frase por etapa, e não "Nenhuma tarefa aqui" repetido seis vezes:
     * coluna vazia é informação, e o que ela informa muda conforme a etapa —
     * Backlog vazio é fila sem prioridade definida, Em andamento vazio é
     * ninguém tocando nada. O texto genérico desperdiça as duas notícias.
     */
    public const VAZIO_DA_ETAPA = [
        'aberta' => 'Fila de triagem vazia',
        'backlog' => 'Nada priorizado',
        'em_desenvolvimento' => 'Ninguém tocando nada',
        'em_revisao' => 'Nenhum PR aberto',
        'em_staging' => 'Nada em staging',
        'em_producao' => 'Nada no ar esperando conferência',
    ];

    /**
     * O que a tarja de retorno diz, conforme o portão que reprovou.
     *
     * "Voltou da revisão" e "Voltou do staging" descrevem situações
     * materialmente diferentes — na segunda o código já está na main — e a
     * recuperação de cada uma é outra. Um rótulo só para as três devolveria à
     * tela o mesmo achatamento que a coluna de Ajustes fazia no fluxo.
     *
     * `concluida` é a reabertura: Concluída significa EM PRODUÇÃO, e "Voltou
     * da produção" é a mais grave das quatro — o defeito está no ar enquanto
     * o card volta. É o rótulo do tipo desenvolvimento; a operacional tem o
     * dela em `rotuloDoRetornoVindoDe`.
     *
     * `pronta_producao` continua aqui mesmo tendo saído do fluxo: a tarja diz de
     * onde a tarefa VOLTOU, e as que voltaram da fila da tag voltaram de lá
     * mesmo. É o mesmo princípio de `ETAPAS_APOSENTADAS` — a etapa sai do fluxo,
     * não do vocabulário.
     *
     * `em_producao` e `concluida` acontecem as duas com o código no ar e mesmo
     * assim não dividem rótulo: reprovar na conferência é o portão fazendo o
     * trabalho dele — a tarefa nunca chegou a ser dada por entregue —, e reabrir
     * é o defeito aparecendo depois de todo mundo ter dado por pronto.
     */
    public const RETORNO_POR_ORIGEM = [
        'em_revisao' => 'Voltou da revisão',
        'em_staging' => 'Voltou do staging',
        'pronta_producao' => 'Voltou da porta da produção',
        'em_producao' => 'Reprovou em produção',
        'concluida' => 'Voltou da produção',
    ];

    /**
     * Etapas que existiram e não existem mais.
     *
     * `bloqueada` foi coluna por um dia e virou marca (`bloqueado_em`). Ela sai
     * do fluxo, mas não some do passado: as tarefas encerradas antes da mudança
     * têm eventos que apontam para ela, e apagar esse rótulo faria o histórico
     * delas exibir a chave crua no lugar do nome da etapa.
     *
     * `em_testes` guardava dois portões (a leitura do PR e a validação rodando)
     * e se abriu em três etapas; `ajustes_necessarios` virou a marca de retorno.
     * `pronta_producao` foi a fila do admin entre o staging e a tag, e saiu
     * quando subir e testar viraram uma etapa só dos dois lados — a espera pela
     * tag passou a acontecer dentro de Em staging.
     * Os dois saem do FLUXO, não do vocabulário: as tarefas encerradas antes da
     * mudança passaram por eles de verdade, e o histórico delas continua tendo
     * de saber pronunciar esses nomes.
     */
    public const ETAPAS_APOSENTADAS = [
        'bloqueada' => 'Bloqueada',
        'em_testes' => 'Em testes',
        'ajustes_necessarios' => 'Ajustes necessários',
        'pronta_producao' => 'Pronta p/ produção',
    ];

    /**
     * O tom de cada etapa, num lugar só.
     *
     * O quadro pinta a coluna com ele, e o menu "Mover ▾" pinta com ele o ponto
     * de cada destino — inclusive os terminais, que não têm coluna. Vivia
     * privado no controller, e a view que precisava da mesma resposta teria de
     * repetir a escala: escala copiada é escala que diverge.
     *
     * A escala ficou simples quando a fila da tag saiu do quadro: o tom da marca
     * é o trabalho em curso, e o verde é o fim. `em_producao` fica com os
     * portões — o código está no ar, mas o exame ainda não aconteceu, e pintá-la
     * de verde anunciaria fim de linha bem na coluna de onde o card ainda pode
     * voltar. `cancelada` fica neutra: é terminal sem valor, e não disputa
     * atenção com as outras.
     */
    public static function corDaEtapa(string $status): string
    {
        return match ($status) {
            'aberta', 'backlog' => 'accent',
            'em_desenvolvimento', 'em_revisao', 'em_staging', 'em_producao' => 'brand',
            'concluida' => 'good',
            default => 'line',
        };
    }

    /** O nome de uma etapa, inclusive das que já não existem no fluxo. */
    public static function rotuloDaEtapa(string $status): string
    {
        return self::STATUS[$status] ?? self::ETAPAS_APOSENTADAS[$status] ?? $status;
    }

    /**
     * O tipo escolhe o fluxo da tarefa (ver `FluxoTarefaService`).
     *
     * Não é o mesmo eixo da prioridade: "crítica" dizia ao mesmo tempo que a
     * tarefa é grave e que ela é do ciclo de desenvolvimento. Separando, a
     * gravidade continua sendo gravidade e o tipo passa a responder por onde a
     * tarefa anda — e se ela precisa de teste aprovado para fechar.
     */
    public const TIPOS = [
        'desenvolvimento' => 'Desenvolvimento',
        'operacional' => 'Operacional',
    ];

    public const STATUS_TERMINAIS = ['concluida', 'cancelada'];

    /**
     * Quantas tarefas ANDANDO cabem numa etapa antes de o quadro reclamar.
     *
     * Só as etapas de trabalho têm limite. Fila não tem WIP: encher o Backlog
     * não atrapalha ninguém, e um alarme ali só ensinaria a ignorar o alarme.
     */
    public const LIMITE_DE_WIP = [
        'em_desenvolvimento' => 3,
        'em_revisao' => 3,
        'em_staging' => 3,
    ];

    /**
     * A partir de quantas horas parada numa etapa a tarefa vira aviso.
     *
     * Cada etapa tem a sua régua porque o que é normal em uma é sintoma na
     * outra: três dias escrevendo código é trabalho, três dias esperando alguém
     * testar é fila. O AC-093 media só Aberta e Em testes — mas a tarefa que
     * mais apodrece é a de Em andamento parada há dias, e ela não era medida.
     *
     * Backlog fica de fora: lá a tarefa está esperando a vez, e ficar parada é
     * o que ela deve fazer.
     */
    public const HORAS_ATE_ENVELHECER = [
        'aberta' => 24,
        'em_desenvolvimento' => 72,
        'em_revisao' => 24,
        'em_staging' => 24,
        'em_producao' => 24,
    ];

    /**
     * A escala de gravidade, do mais discreto ao mais grave — e, no fim, a
     * ausência de decisão.
     *
     * "A definir" não é um grau da escala: é a tarefa que ainda não foi
     * triada. Ela existe para que quem abre uma tarefa sem poder priorizá-la
     * não empurre o cadastro para "Média" por omissão, transformando o padrão
     * numa afirmação que ninguém fez.
     */
    public const PRIORIDADES = [
        'baixa' => 'Baixa',
        'media' => 'Média',
        'alta' => 'Alta',
        'critica' => 'Crítica',
        'nao_definida' => 'A definir',
    ];

    /**
     * O tom do selo de cada prioridade, num lugar só.
     *
     * Vivia duplicado no card e no histórico, e a escala já tinha errado uma
     * vez por isso — dois níveis no mesmo neutro, indistinguíveis. Uma escala
     * copiada é uma escala que vai divergir.
     */
    public const TOM_DA_PRIORIDADE = [
        'baixa' => 'neutro',
        'media' => 'marca',
        'alta' => 'ambar',
        'critica' => 'critico',
        'nao_definida' => 'triagem',
    ];

    protected $fillable = [
        'titulo', 'resumo', 'detalhes', 'tipo', 'sistema_id', 'responsavel_id',
        'criado_por_id', 'prioridade', 'status', 'ordem', 'iniciada_em',
    ];

    protected function casts(): array
    {
        return [
            'iniciada_em' => 'datetime',
            'bloqueado_em' => 'datetime',
            'pergunta_em' => 'datetime',
            'rodadas' => 'integer',
            'retorno_anexo_ids' => 'array',
        ];
    }

    /**
     * O número pelo qual a tarefa é chamada fora da tela: "#128".
     *
     * É o `id` e não um contador novo. Ele já é único, já é o que está na URL
     * da tarefa, no `data-tarefa` do card e no nome do modal — um segundo
     * número daria à mesma tarefa dois nomes, e o dia em que os dois
     * divergissem ninguém saberia qual dos dois o outro lado quis dizer.
     *
     * O "#" mora aqui porque três telas o escrevem — card, modal e histórico.
     * Esquecido em uma delas, o mesmo número viraria dois vocabulários, e quem
     * copia de uma tela para procurar na outra não acharia.
     *
     * Sem zeros à esquerda: "#0042" fixa uma largura que a milésima tarefa
     * estoura, e aí o acervo teria dois formatos para a mesma coisa.
     */
    public function codigo(): string
    {
        return '#'.$this->id;
    }

    /**
     * A tarefa está travada esperando alguém?
     *
     * O bloqueio é marca e não etapa: a tarefa continua na coluna em que
     * estava, e é `bloqueado_em` que responde por ele. Fora do `fillable` de
     * propósito — quem bloqueia passa pelo `FluxoTarefaService`, que exige o
     * motivo; um `update` de formulário não deveria conseguir travar tarefa.
     */
    public function estaBloqueada(): bool
    {
        return $this->bloqueado_em !== null;
    }

    /**
     * Por que esta pessoa não pode mover esta tarefa — ou null, se pode.
     *
     * Devolve a FRASE, e não um booleano, porque a recusa precisa dizer o
     * motivo em dois lugares diferentes: no flash da rota e no `title` do card
     * que não arrasta. Com um booleano, cada lugar escreveria a sua versão, e
     * as duas divergiriam na primeira vez que alguém mexesse numa delas.
     *
     * Quem faz triagem move qualquer tarefa; quem não faz move as suas. A fila
     * de triagem fica fora do alcance sem precisar de regra própria: entrar em
     * Aberta solta o responsável (AC-130), então nenhuma tarefa de lá está com
     * ninguém.
     */
    public function motivoParaNaoMover(?User $usuario): ?string
    {
        if (! $usuario || $usuario->podeTriarTarefas() || $this->responsavel_id === $usuario->id) {
            return null;
        }

        if (! $this->responsavel_id) {
            return 'Esta tarefa ainda não tem responsável. Só quem faz triagem move o que está na fila.';
        }

        return 'Esta tarefa está com '.($this->responsavel?->name ?? 'outra pessoa')
            .'. Só quem faz triagem move o trabalho de outra pessoa.';
    }

    /** A tarefa de quem esta é subtarefa — ou null, se ela é de primeiro nível. */
    public function pai(): BelongsTo
    {
        return $this->belongsTo(self::class, 'tarefa_pai_id');
    }

    /**
     * As subtarefas desta, da mais antiga para a mais nova.
     *
     * Ordem de criação, e não de etapa nem de prioridade: quem reporta oito
     * bugs seguidos os escreve numa ordem que quer dizer alguma coisa — e uma
     * lista que se reorganiza sozinha a cada card que anda faz reler tudo para
     * achar o que se acabou de digitar.
     */
    public function subtarefas(): HasMany
    {
        return $this->hasMany(self::class, 'tarefa_pai_id')->orderBy('id');
    }

    public function ehSubtarefa(): bool
    {
        return $this->tarefa_pai_id !== null;
    }

    /**
     * Esta tarefa pode receber subtarefa?
     *
     * Um nível só, e a razão está na migração: profundidade livre multiplicaria
     * por quantos níveis alguém criar as quatro perguntas que a hierarquia
     * obriga a responder — e nenhuma delas foi respondida para "avó".
     *
     * Encerrada também não recebe: pendurar trabalho novo numa tarefa que já
     * saiu do quadro criaria uma filha que ninguém vê e uma mãe que a recusa
     * do encerramento não alcança mais.
     */
    public function podeReceberSubtarefa(): bool
    {
        return ! $this->ehSubtarefa()
            && ! in_array($this->status, self::STATUS_TERMINAIS, true);
    }

    /**
     * O placar das subtarefas — encerradas sobre o total, ou null se não há.
     *
     * "Encerrada" inclui a CANCELADA de propósito: ela é a saída para o bug que
     * não vai ser corrigido, e contá-la como pendente prenderia a mãe a uma
     * decisão que já foi tomada. Espelha `progressoDoChecklist`, inclusive no
     * null — "0/0" anunciaria como ausência o que é só o normal.
     *
     * @return array{feitas: int, total: int}|null
     */
    public function progressoDasSubtarefas(): ?array
    {
        $total = $this->subtarefas->count();

        if ($total === 0) {
            return null;
        }

        return [
            'feitas' => $this->subtarefas->whereIn('status', self::STATUS_TERMINAIS)->count(),
            'total' => $total,
        ];
    }

    /**
     * Por que esta tarefa não pode sair do quadro agora — ou null, se pode.
     *
     * A mãe é guarda-chuva: enquanto houver filha aberta ela não conclui, não
     * cancela e não é excluída. É a resposta à primeira das quatro perguntas
     * que a hierarquia obriga a responder, e vale para as três portas de saída
     * pelo mesmo motivo — todas tiram do quadro a tarefa que ainda responde
     * por trabalho de outras.
     *
     * Cancelar a filha é a saída: ela sai da conta sem ser feita, que é o
     * destino honesto do bug que o time decidiu não corrigir.
     *
     * Devolve a FRASE, como os outros impedimentos: ela é dita no motor, na
     * recusa da exclusão e no `title` do botão que não funciona.
     */
    public function motivoParaNaoEncerrar(): ?string
    {
        $abertas = $this->subtarefas->whereNotIn('status', self::STATUS_TERMINAIS)->count();

        if ($abertas === 0) {
            return null;
        }

        return $abertas === 1
            ? 'Esta tarefa tem 1 subtarefa em aberto. Encerre ou cancele ela antes.'
            : 'Esta tarefa tem '.$abertas.' subtarefas em aberto. Encerre ou cancele cada uma antes.';
    }

    /**
     * Concluída é destino do FLUXO a partir da etapa em que a tarefa está?
     *
     * O botão de concluir do card pergunta isto ALÉM de `destinosPara`, porque
     * para quem faz triagem os dois divergem: o movimento livre oferece
     * Concluída de qualquer etapa, e um botão de concluir num card que está em
     * Em andamento abriria um painel que o portão da entrega recusa. O menu
     * "Mover ▾" continua listando o quadro inteiro — lá o destino é uma escolha
     * declarada, e não um atalho de um clique.
     */
    public function concluirCabeNestaEtapa(): bool
    {
        return in_array('concluida', FluxoTarefaService::transicoesDe($this), true);
    }

    /**
     * Por que esta pessoa não pode ENCERRAR esta tarefa — ou null, se pode.
     *
     * Mover e encerrar deixaram de ser a mesma permissão. Quem confere no ar
     * registra o veredito; quem organiza o quadro é que dá a tarefa por
     * entregue. Sem essa separação, o mesmo dev que subiu o código assinaria
     * sozinho que ele funciona, e o portão da produção viraria uma caixinha que
     * quem tem pressa marca em si mesmo.
     *
     * Só a tarefa de DESENVOLVIMENTO. A operacional não tem PR, staging nem
     * tag: exigir um admin para encerrar um telefonema tiraria de quem executa o
     * registro do próprio trabalho, sem nada a proteger em troca.
     *
     * Devolve a FRASE pelo mesmo motivo de `motivoParaNaoMover`: ela é dita em
     * dois lugares — a recusa da rota e o destino que o menu deixa de oferecer —
     * e uma frase escrita duas vezes é uma frase que diverge.
     */
    public function motivoParaNaoConcluir(?User $usuario): ?string
    {
        if ($this->tipo !== 'desenvolvimento' || ! $usuario || $usuario->podeTriarTarefas()) {
            return null;
        }

        return 'Só quem faz triagem encerra uma tarefa de desenvolvimento. '
            .'Registre o veredito da validação em produção — é ele que libera o encerramento.';
    }

    /**
     * Os destinos que o quadro oferece a ESTA pessoa para ESTA tarefa.
     *
     * A pergunta que o card, a linha da tabela de raias e o card devolvido
     * pelas ações parciais fazem — e por isso mora aqui, ao lado do
     * impedimento, em vez de cada tela combinar as duas respostas por conta
     * própria e divergir na primeira mexida.
     *
     * Quem faz triagem recebe o quadro inteiro (US-079): o mapa do fluxo
     * educa quem executa, e quem organiza precisa justamente do movimento que
     * o mapa recusa — devolver à coluna certa o card que a realidade já
     * desmentiu. Quem não pode mover não recebe destino nenhum: oferecer e
     * recusar depois é o vício que o quadro perdeu.
     *
     * Encerrar sai da lista pela mesma razão, e não por regra de fluxo: o mapa
     * responde por onde a TAREFA pode andar, e quem encerra é pergunta sobre a
     * PESSOA. Deixar "Concluída" no menu de quem não pode usá-la seria montar a
     * recusa dentro do próprio menu.
     *
     * @return list<string>
     */
    public function destinosPara(?User $usuario): array
    {
        if ($this->motivoParaNaoMover($usuario)) {
            return [];
        }

        $destinos = $usuario?->podeTriarTarefas()
            ? FluxoTarefaService::transicoesLivres($this)
            : FluxoTarefaService::transicoesDe($this);

        if ($this->motivoParaNaoConcluir($usuario)) {
            $destinos = array_values(array_diff($destinos, ['concluida']));
        }

        return $destinos;
    }

    /** Há quanto tempo está travada, na régua curta do quadro ("3h", "2d"). */
    public function bloqueadaHa(): ?string
    {
        if (! $this->estaBloqueada()) {
            return null;
        }

        return self::duracaoCurta((int) $this->bloqueado_em->diffInSeconds(now()));
    }

    /**
     * O texto da tarja: "Bloqueada agora" ou "Bloqueada há 2d".
     *
     * O "há" some no primeiro minuto porque a régua curta devolve a palavra
     * "agora" para ele, e "bloqueada há agora" não é frase — é o que sai quando
     * se concatena rótulo com medida sem olhar o resultado na tela.
     */
    public function rotuloDoBloqueio(): ?string
    {
        $duracao = $this->bloqueadaHa();

        return match (true) {
            $duracao === null => null,
            $duracao === 'agora' => 'Bloqueada agora',
            default => 'Bloqueada há '.$duracao,
        };
    }

    /**
     * A tarefa voltou de um portão e ainda não andou para frente?
     *
     * Fora do `fillable` pelo mesmo motivo do bloqueio: a marca nasce da recusa
     * num portão, que exige o motivo, e um `update` de formulário não deveria
     * ser capaz de carimbar retorno de passagem.
     */
    public function temRetorno(): bool
    {
        return $this->retorno_de !== null;
    }

    /** "Voltou da revisão", "Voltou do staging" — ou null, se não voltou. */
    public function rotuloDoRetorno(): ?string
    {
        if (! $this->temRetorno()) {
            return null;
        }

        return $this->rotuloDoRetornoVindoDe($this->retorno_de);
    }

    /**
     * O rótulo de uma volta vinda de `$origem`, no vocabulário do TIPO.
     *
     * A operacional é o caso que o mapa não cobre: a única volta que ela tem é
     * a reabertura, e "Voltou da produção" anunciaria uma produção que um
     * telefonema não teve. Fica "Reaberta" — que é tudo o que aconteceu.
     */
    public function rotuloDoRetornoVindoDe(string $origem): string
    {
        if ($origem === 'concluida' && $this->tipo !== 'desenvolvimento') {
            return 'Reaberta';
        }

        return self::RETORNO_POR_ORIGEM[$origem]
            ?? 'Voltou de '.self::rotuloDaEtapa($origem);
    }

    /**
     * As imagens que vieram com a devolução ATUAL — a metade do motivo que não
     * é texto.
     *
     * Elas são anexos comuns da tarefa; o que esta lista responde é QUAIS deles
     * chegaram junto com a tarja de retorno, para o banner mostrá-los ao lado
     * do motivo. Filtra a coleção já carregada em vez de consultar por id: o
     * modal imprime a seção de anexos da mesma tarefa, e a relação serve às
     * duas leituras com uma consulta só.
     *
     * O anexo que o autor removeu depois da devolução simplesmente sai do
     * filtro — o id órfão na lista não aponta para nada e não quebra nada.
     *
     * @return Collection<int, TarefaAnexo>
     */
    public function retornoAnexos(): Collection
    {
        if (! $this->temRetorno() || empty($this->retorno_anexo_ids)) {
            return new Collection;
        }

        return $this->anexos->whereIn('id', $this->retorno_anexo_ids)->values();
    }

    /** Há uma pergunta esperando resposta? */
    public function temPergunta(): bool
    {
        return $this->pergunta_em !== null;
    }

    /**
     * A bola está com esta pessoa?
     *
     * É o que alimenta o chip "N p/ você" do cabeçalho e o filtro "Só as que
     * esperam por você" — a caixa de entrada de quem abre o quadro.
     */
    public function esperaRespostaDe(?User $usuario): bool
    {
        return $usuario !== null
            && $this->temPergunta()
            && $this->pergunta_para_id === $usuario->id;
    }

    /**
     * Três idas e voltas sem resolver — o quadro sugere devolver para correção.
     *
     * O sinal não é sobre a conversa estar longa: é sobre o PR estar grande
     * demais ou a tarefa ter sido mal especificada. Por isso ele vive na
     * contagem de RODADAS e não na de comentários — cinco dúvidas mandadas de
     * uma vez são uma rodada só, e não são sintoma de nada.
     */
    public function conversaEmpacada(): bool
    {
        return $this->rodadas >= 3;
    }

    /**
     * Para quem a pergunta desta pessoa vai, quando o quadro sabe sozinho.
     *
     * Numa revisão só há dois lados, e por isso não se escolhe destinatário: o
     * outro lado é o responsável, ou o interlocutor de quem já conversou aqui.
     *
     * Devolve null quando NÃO há segundo lado — a tarefa é de quem está
     * perguntando e ninguém entrou na conversa ainda. Isso não é um impedimento,
     * é uma pergunta a mais a fazer: a tela oferece a escolha em vez de esconder
     * o botão, porque não ter com quem falar ainda é diferente de não poder
     * perguntar.
     *
     * Mora aqui, e não no serviço, porque a VIEW precisa da mesma resposta para
     * decidir se mostra o select — duas cópias da regra divergiriam na primeira
     * vez que alguém mexesse numa delas.
     */
    public function outroLadoDe(?User $quemPergunta): ?int
    {
        if (! $quemPergunta) {
            return null;
        }

        if ($this->responsavel_id !== null && $this->responsavel_id !== $quemPergunta->id) {
            return $this->responsavel_id;
        }

        return $this->interlocutor_id !== $quemPergunta->id ? $this->interlocutor_id : null;
    }

    /** As tarefas cuja bola está com esta pessoa. */
    /**
     * As que já passaram no staging e esperam a tag subir.
     *
     * Era uma coluna (`pronta_producao`), e virou um recorte de Em staging: a
     * espera pela tag acontece DENTRO da etapa, como a espera pelo merge
     * acontece dentro de Em revisão. O chip do cabeçalho e o filtro de situação
     * fazem a mesma pergunta, e por isso ela mora aqui — dois lugares montando
     * a consulta por conta própria divergiriam na primeira mexida.
     *
     * "Aprovada" é o veredito MAIS NOVO da passagem atual, e não qualquer
     * aprovado: reprovar depois de aprovar tira a tarefa da fila, e um
     * `whereHas` simples a deixaria lá. Sem agregação de propósito — o MySQL do
     * staging roda com `ONLY_FULL_GROUP_BY`, e `not exists` mais novo responde
     * a mesma pergunta sem `GROUP BY`.
     */
    public function scopeValidadaNoStaging($query)
    {
        return $query->where('status', 'em_staging')
            ->whereExists(fn ($sub) => $sub
                ->selectRaw(1)
                ->from('tarefa_relatorios_teste as r')
                ->join('tarefa_eventos as e', 'e.id', '=', 'r.tarefa_evento_id')
                ->whereColumn('r.tarefa_id', 'tarefas.id')
                ->whereNull('e.saiu_em')
                ->where('r.aprovado', true)
                ->whereNotExists(fn ($mais) => $mais
                    ->selectRaw(1)
                    ->from('tarefa_relatorios_teste as r2')
                    ->whereColumn('r2.tarefa_evento_id', 'r.tarefa_evento_id')
                    ->whereColumn('r2.id', '>', 'r.id')));
    }

    public function scopeEsperandoRespostaDe($query, ?int $usuarioId)
    {
        return $query->whereNotNull('pergunta_em')->where('pergunta_para_id', $usuarioId);
    }

    protected static function booted(): void
    {
        static::creating(function (Tarefa $tarefa): void {
            $tarefa->tipo ??= 'desenvolvimento';
            $tarefa->status ??= $tarefa->responsavel_id ? 'backlog' : 'aberta';
        });

        /*
         * O cascade do banco apaga as LINHAS dos anexos, e é tudo o que ele
         * sabe fazer: arquivo em disco não tem chave estrangeira. Sem isto,
         * excluir uma tarefa deixava cada print e cada log no disco para
         * sempre — sem erro nenhum, sem linha que os apontasse e sem ninguém
         * para procurá-los.
         *
         * `forceDeleting` e não `deleting`: a tarefa tem exclusão reversível, e
         * uma tarefa que ainda pode voltar precisa voltar com as provas.
         *
         * Os arquivos saem por `Storage` em vez de passar cada anexo pelo
         * Eloquent, para o cascade continuar sendo o ÚNICO caminho das linhas —
         * é assim que comentário e item de checklist já somem daqui. Passando
         * um a um, o anexo viraria o único filho a escrever uma linha de
         * auditoria por unidade na exclusão de UMA tarefa, e doze prints
         * empurrariam a exclusão da tarefa para fora da tela.
         */
        static::forceDeleting(function (Tarefa $tarefa): void {
            // Original e miniatura, e o `filter` tira os nulos: a maioria das
            // linhas não tem miniatura — ver `TarefaAnexo::getUrlMiniatura`.
            Storage::disk('public')->delete(
                $tarefa->anexos()
                    ->get(['caminho', 'caminho_miniatura'])
                    ->flatMap(fn (TarefaAnexo $anexo) => [$anexo->caminho, $anexo->caminho_miniatura])
                    ->filter()
                    ->all()
            );
        });
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id');
    }

    /**
     * O outro lado da conversa, lembrado entre uma rodada e outra.
     *
     * Persistido de propósito: responder apaga o ponteiro da pergunta, e sem
     * este campo o sistema esqueceria com quem estava falando exatamente no
     * momento em que a pessoa devolve a bola.
     */
    public function interlocutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interlocutor_id');
    }

    public function perguntaDe(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pergunta_de_id');
    }

    public function perguntaPara(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pergunta_para_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(TarefaEvento::class);
    }

    /**
     * A conversa da tarefa, do mais antigo ao mais novo (US-049).
     *
     * A ordem vive na relação, e não em cada tela, porque comentário fora de
     * ordem não é uma lista bagunçada — é uma resposta antes da pergunta.
     */
    public function comentarios(): HasMany
    {
        return $this->hasMany(TarefaComentario::class)->oldest();
    }

    /**
     * Os anexos da tarefa, do mais antigo ao mais novo (US-064).
     *
     * Cronológica pelo mesmo motivo da conversa: a segunda captura costuma ser
     * resposta à primeira — "era assim" e "ficou assim" só se leem na ordem em
     * que chegaram. Vale igual para o par log-antes/log-depois.
     */
    public function anexos(): HasMany
    {
        return $this->hasMany(TarefaAnexo::class)->oldest();
    }

    /**
     * Duração em forma curta: "agora", "40m", "3h", "12d".
     *
     * Uma régua só para o quadro e o histórico: o chip do card mede o tempo na
     * etapa atual e a linha do histórico mede o ciclo inteiro, mas os dois
     * precisam falar a mesma língua — "3h" tem de querer dizer a mesma coisa
     * nas duas telas.
     */
    public static function duracaoCurta(int $segundos): string
    {
        return match (true) {
            $segundos < 60 => 'agora',
            $segundos < 3600 => intdiv($segundos, 60).'m',
            $segundos < 86400 => intdiv($segundos, 3600).'h',
            default => intdiv($segundos, 86400).'d',
        };
    }

    /**
     * Quanto a tarefa levou da criação até entrar na etapa terminal (AC-133).
     *
     * É o número que justifica cronometrar cada etapa: sem ele, os eventos
     * seriam registro que ninguém lê. Devolve null enquanto a tarefa não
     * encerrou — aí não há ciclo fechado para medir.
     */
    public function duracaoDoCiclo(): ?int
    {
        if (! in_array($this->status, self::STATUS_TERMINAIS, true)) {
            return null;
        }

        $encerramento = $this->eventos
            ->filter(fn (TarefaEvento $evento) => in_array($evento->para_status, self::STATUS_TERMINAIS, true))
            ->sortByDesc('entrou_em')
            ->first()?->entrou_em ?? $this->updated_at;

        return max(0, (int) $this->created_at->diffInSeconds($encerramento));
    }

    public function relatoriosTeste(): HasMany
    {
        return $this->hasMany(TarefaRelatorioTeste::class);
    }

    /**
     * O relatório de teste DESTA passagem pela etapa atual — ou null.
     *
     * "Desta passagem" é o recorte que impede o aprovado antigo de valer de
     * novo: o relatório se prende ao evento aberto, e a tarefa que reentra no
     * staging não herda o carimbo da volta anterior. O recorte é o EVENTO, não
     * a data — por data, reabrir e reconcluir no mesmo segundo deixava o
     * relatório velho passar. Tarefa que nunca se moveu compara nulo com nulo:
     * também é uma passagem, a primeira.
     *
     * Mora aqui porque o portão (`FluxoTarefaService::aprovadaNestaPassagem`)
     * e a tarja do teste na tela fazem a MESMA pergunta — e duas cópias do
     * recorte divergiriam na primeira mexida.
     */
    public function testeDestaPassagem(): ?TarefaRelatorioTeste
    {
        $eventoAberto = $this->eventos()->whereNull('saiu_em')->latest('entrou_em')->value('id');

        return $this->relatoriosTeste()
            ->where('tarefa_evento_id', $eventoAberto)
            ->latest('id')
            ->first();
    }

    /**
     * O checklist da tarefa, na ordem escolhida por quem escreveu.
     *
     * A ordem vive na relação, e não em cada tela, pelo mesmo motivo dos
     * comentários: uma lista de conferência fora de ordem não é uma lista
     * bagunçada — é um passo antes do passo que o habilita.
     */
    public function itens(): HasMany
    {
        return $this->hasMany(TarefaItem::class)->orderBy('ordem')->orderBy('id');
    }

    /**
     * O progresso do checklist, ou null quando não há checklist.
     *
     * Devolve null em vez de "0 de 0" porque tarefa sem checklist não está com
     * o checklist zerado — ela não tem um, e o card não deve anunciar um vazio
     * como se fosse pendência.
     *
     * @return array{feitos: int, total: int}|null
     */
    public function progressoDoChecklist(): ?array
    {
        $total = $this->itens->count();

        if ($total === 0) {
            return null;
        }

        return ['feitos' => $this->itens->where('feito', true)->count(), 'total' => $total];
    }

}
