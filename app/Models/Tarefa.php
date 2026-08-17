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
     */
    public const STATUS = [
        'aberta' => 'Aberta',
        'backlog' => 'Backlog',
        'em_desenvolvimento' => 'Em andamento',
        'em_revisao' => 'Em revisão',
        'em_staging' => 'Em staging',
        'pronta_producao' => 'Pronta p/ produção',
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
     */
    public const PORTOES = ['em_revisao', 'em_staging', 'pronta_producao'];

    /**
     * Os portões onde a bola muda de mão para EXAME — revisão e staging.
     *
     * É neles que o painel do movimento oferece apontar quem revisa ou quem
     * testa, e é neles que a conversa recomeça a cada entrada (Q-037): quem
     * chega fala com o examinador DESTA passagem, não com o da etapa
     * anterior. Pronta p/ produção fica de fora — lá a fila é do admin
     * inteiro, e apontar uma pessoa não acrescentaria lado nenhum.
     */
    public const PORTOES_DE_EXAME = ['em_revisao', 'em_staging'];

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
        'em_staging' => 'na main · dev valida',
        'pronta_producao' => 'fila do admin · tag v*',
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
        'pronta_producao' => 'Nada para subir',
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
     */
    public const RETORNO_POR_ORIGEM = [
        'em_revisao' => 'Voltou da revisão',
        'em_staging' => 'Voltou do staging',
        'pronta_producao' => 'Voltou da porta da produção',
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
     * Os dois saem do FLUXO, não do vocabulário: as tarefas encerradas antes da
     * mudança passaram por eles de verdade, e o histórico delas continua tendo
     * de saber pronunciar esses nomes.
     */
    public const ETAPAS_APOSENTADAS = [
        'bloqueada' => 'Bloqueada',
        'em_testes' => 'Em testes',
        'ajustes_necessarios' => 'Ajustes necessários',
    ];

    /**
     * O tom de cada etapa, num lugar só.
     *
     * O quadro pinta a coluna com ele, e o menu "Mover ▾" pinta com ele o ponto
     * de cada destino — inclusive os terminais, que não têm coluna. Vivia
     * privado no controller, e a view que precisava da mesma resposta teria de
     * repetir a escala: escala copiada é escala que diverge.
     *
     * `pronta_producao` compartilha o tom de `concluida` porque, do ponto de
     * vista de quem olha o quadro, o que está ali já passou por tudo que havia
     * para passar. `cancelada` fica neutra: é terminal sem valor, e não disputa
     * atenção com as outras.
     */
    public static function corDaEtapa(string $status): string
    {
        return match ($status) {
            'aberta', 'backlog' => 'accent',
            'em_desenvolvimento', 'em_revisao', 'em_staging' => 'brand',
            'pronta_producao', 'concluida' => 'good',
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
        'pronta_producao' => 24,
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
        'nao_definida' => 'atencao',
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
     * @return list<string>
     */
    public function destinosPara(?User $usuario): array
    {
        if ($this->motivoParaNaoMover($usuario)) {
            return [];
        }

        return $usuario?->podeTriarTarefas()
            ? FluxoTarefaService::transicoesLivres($this)
            : FluxoTarefaService::transicoesDe($this);
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
