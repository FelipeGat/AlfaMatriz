<?php

namespace App\Services\Secretario;

use App\Models\Notificacao;
use App\Models\SuporteAtendimento;
use App\Models\SuporteConversa;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * De quem é cada coisa em Acompanhamentos (docs/specs/secretario-acompanhamentos-por-area;
 * Felipe, 07/11/2026): "tudo que for suporte é Alexandre, tudo que for suporte e
 * desenvolvimento é Rossini, tudo que for comercial e administrativo para mim.
 * Tanto do Gestor quanto do Matriz."
 *
 * Lido assim: o atendimento ao cliente (o WhatsApp do Pedro e os chamados) é do
 * suporte; o suporte que mexe em sistema (sincronização, erro, bug) e o
 * desenvolvimento são do desenvolvimento; clientes, licenças, cobrança, contas
 * e o retrato do Gestor são comercial ou administrativo.
 *
 * Classifica na LEITURA, e não numa coluna gravada: vale para os avisos que já
 * estão no sino (114 no dia do pedido), e quem avisa não precisa saber disso —
 * são mais de quarenta lugares que criam notificação, e um esquecido viraria um
 * aviso sem dono para sempre.
 *
 * Quem recebe continua o mesmo. Isto só diz, na tela, de quem é o aviso.
 */
class Areas
{
    /** Na ordem do pedido ("para mim, para o Rossini, para o Alexandre"): é a ordem dos botões na tela. */
    public const AREAS = ['comercial' => 'Comercial', 'administrativo' => 'Administrativo', 'desenvolvimento' => 'Desenvolvimento', 'suporte' => 'Suporte'];

    /**
     * O primeiro trecho do caminho de destino => área. O destino é onde o aviso
     * se resolve, e cada módulo do sistema é de uma área: é o sinal mais
     * estável que a notificação guarda (o `tipo` não separa assunto — o
     * suporte grava `direcionamento`, como o quadro).
     */
    private const PELO_CAMINHO = [
        'suporte' => 'suporte',
        'sistemas' => 'desenvolvimento', 'manutencao' => 'desenvolvimento', 'central-ia' => 'desenvolvimento', 'monitor' => 'desenvolvimento',
        'clientes' => 'comercial', 'comercial' => 'comercial', 'leads' => 'comercial', 'lead-anexos' => 'comercial',
        'revendas' => 'comercial', 'produtos' => 'comercial',
        'cobrancas' => 'administrativo', 'contas-pagar' => 'administrativo', 'contas-fixas-pagar' => 'administrativo',
        'contas-financeiras' => 'administrativo', 'faturamento' => 'administrativo', 'financeiro' => 'administrativo',
        'fornecedores' => 'administrativo', 'usuarios' => 'administrativo',
    ];

    /**
     * Os avisos de tarefa que são da PESSOA que recebe, seja qual for a área:
     * a pergunta feita a ela, a tarefa que ela ganhou, a validação, a volta
     * para corrigir, o prazo dela. Rossini perguntar ao Felipe numa tarefa de
     * desenvolvimento é o Rossini esperando o Felipe — marcar "do Rossini"
     * faria o Felipe passar reto, e a tarefa parar.
     */
    private const DA_TAREFA_PARA_QUEM_RECEBE = ['pergunta', 'resposta', 'direcionamento', 'apontamento', 'retorno', 'validar', 'prazo', 'lembrete', 'compromisso'];

    /** Blocos do retrato do Gestor => área (os de `SecretarioGestorRetrato::BLOCOS`). */
    private const DO_GESTOR = [
        'orcamentos_aprovacao' => 'comercial', 'orcamentos_elaboracao' => 'comercial', 'reunioes_proximo_passo' => 'comercial',
        'funil_parado' => 'comercial', 'cobrancas_vencidas' => 'administrativo', 'cobrancas_hoje' => 'administrativo',
        'pagar_vencendo' => 'administrativo',
    ];

    /** @var array<string, ?User>|null */
    private ?array $donos = null;

    /**
     * O dono de cada área: a conta ATIVA do e-mail configurado
     * (`services.areas`), como os responsáveis do suporte. Sem conta, a área
     * fica sem dono — e a tela diz a área, em vez de inventar uma pessoa.
     *
     * @return array<string, ?User>
     */
    public function donos(): array
    {
        if ($this->donos === null) {
            $emails = array_filter((array) config('services.areas'));
            $contas = User::where('ativo', true)->whereIn('email', array_values($emails))->get()->keyBy(fn (User $u) => mb_strtolower($u->email));
            $this->donos = [];
            foreach (array_keys(self::AREAS) as $area) {
                $this->donos[$area] = $contas->get(mb_strtolower((string) ($emails[$area] ?? ''))) ?? null;
            }
        }

        return $this->donos;
    }

    /**
     * De quem é cada aviso, por id. Uma consulta para as tarefas e uma para
     * saber quais delas são de suporte, e não uma por aviso: a lista tem até 300.
     *
     * @param  Collection<int, Notificacao>  $avisos
     * @return array<int, array{quem: ?int, area: ?string}>
     */
    public function dosAvisos(Collection $avisos, User $quemVe): array
    {
        $ids = $avisos->pluck('tarefa_id')->filter()->unique()->values();
        $tarefas = $ids->isEmpty() ? collect() : Tarefa::withTrashed()->whereIn('id', $ids)->get(['id', 'tipo', 'responsavel_id'])->keyBy('id');
        $deSuporte = $ids->isEmpty() ? collect() : SuporteAtendimento::whereIn('tarefa_id', $ids)->pluck('tarefa_id')
            ->merge(SuporteConversa::whereIn('tarefa_id', $ids)->pluck('tarefa_id'))->flip();

        $de = [];
        foreach ($avisos as $n) {
            $de[$n->id] = $this->doAviso($n, $quemVe, $n->tarefa_id ? $tarefas->get($n->tarefa_id) : null, $n->tarefa_id && $deSuporte->has($n->tarefa_id));
        }

        return $de;
    }

    /** @return array{quem: ?int, area: ?string} */
    private function doAviso(Notificacao $n, User $quemVe, ?Tarefa $tarefa, bool $tarefaDeSuporte): array
    {
        $caminho = $this->caminho($n->rota);

        // O suporte primeiro: a cobrança do atendimento grava `direcionamento` com a tarefa, e
        // pela regra do quadro cairia em quem recebe. Só "Chamaram você" é da pessoa: o cliente
        // pediu por ela pelo nome, e o aviso vai só para ela.
        if ($n->meta === 'Suporte WhatsApp' || $caminho === 'suporte') {
            return str_starts_with((string) $n->titulo, 'Chamaram você')
                ? $this->daPessoa($quemVe, 'suporte')
                : $this->daArea('suporte');
        }

        if ($n->tarefa_id || $caminho === 'tarefas' || $caminho === 'o-que-espera-voce') {
            if (in_array($n->tipo, self::DA_TAREFA_PARA_QUEM_RECEBE, true) || ! $n->tarefa_id) {
                return $this->daPessoa($quemVe, $tarefa ? $this->areaDaTarefa($tarefa, $tarefaDeSuporte) : null);
            }
            // Notícia da tarefa (triagem, conclusão, comentário, bloqueio, staging, PR…): de quem
            // está com ela. Sem responsável — a da triagem —, da área pelo tipo.
            $area = $tarefa ? $this->areaDaTarefa($tarefa, $tarefaDeSuporte) : 'desenvolvimento';
            if ($tarefa?->responsavel_id) {
                return ['quem' => (int) $tarefa->responsavel_id, 'area' => $area];
            }

            return $this->daArea($area ?? 'desenvolvimento');
        }

        if ($n->tipo === 'sincronizacao') {
            return $this->daArea('desenvolvimento');
        }

        if (isset(self::PELO_CAMINHO[$caminho])) {
            return $this->daArea(self::PELO_CAMINHO[$caminho]);
        }

        // Secretário, agenda, perfil e o que não tem destino: da própria pessoa. Na dúvida, o
        // aviso fica com quem o recebeu — esconder dela seria pior do que não classificar.
        return $this->daPessoa($quemVe, null);
    }

    /**
     * A área da tarefa: de suporte se nasceu de um atendimento; de
     * desenvolvimento se passa pelos portões (desenvolvimento e bug). A
     * operacional não tem área própria — é de quem está com ela.
     */
    private function areaDaTarefa(Tarefa $t, bool $deSuporte): ?string
    {
        return $deSuporte ? 'suporte' : (in_array($t->tipo, Tarefa::TIPOS_COM_PORTOES, true) ? 'desenvolvimento' : null);
    }

    /** As linhas do retrato do Gestor: comercial (orçamentos, funil, reuniões de cliente) ou administrativo. @return array{quem: ?int, area: ?string} */
    public function doBlocoDoGestor(string $bloco): array
    {
        return $this->daArea(self::DO_GESTOR[$bloco] ?? 'administrativo');
    }

    /** @return array{quem: ?int, area: ?string} */
    public function daArea(string $area): array
    {
        return ['quem' => $this->donos()[$area]?->id, 'area' => $area];
    }

    /** @return array{quem: ?int, area: ?string} */
    public function daPessoa(User $u, ?string $area): array
    {
        return ['quem' => $u->id, 'area' => $area];
    }

    /**
     * A chave do filtro na tela: `eu` para quem vê, `u<id>` para os outros,
     * `sem` para a área sem dono.
     *
     * @param  array{quem: ?int, area: ?string}  $de
     */
    public static function chave(array $de, User $quemVe): string
    {
        return $de['quem'] === null ? 'sem' : ($de['quem'] === $quemVe->id ? 'eu' : 'u'.$de['quem']);
    }

    /**
     * O que a linha mostra: "Você", "Rossini · Desenvolvimento", "Suporte" (sem dono).
     *
     * @param  array{quem: ?int, area: ?string}  $de
     * @param  array<int, string>  $nomes  id => primeiro nome
     */
    public static function rotulo(array $de, User $quemVe, array $nomes): string
    {
        $quem = $de['quem'] === null ? null : ($de['quem'] === $quemVe->id ? 'Você' : ($nomes[$de['quem']] ?? 'Outra pessoa'));
        $area = $de['area'] ? self::AREAS[$de['area']] : null;

        return implode(' · ', array_filter([$quem, $area])) ?: 'Sem área';
    }

    /**
     * Os botões do filtro, na ordem: Para mim, os donos das áreas, quem mais
     * aparecer (o responsável de uma tarefa) e Sem dono — só os que têm alguma
     * linha, com a contagem.
     *
     * @param  list<array{quem: ?int, area: ?string}>  $contados  o que está a tratar
     * @param  list<array{quem: ?int, area: ?string}>  $outros  o que só leva o nome (as tratadas)
     * @return array{botoes: list<array{chave: string, rotulo: string, n: int}>, nomes: array<int, string>}
     */
    public function filtro(array $contados, User $quemVe, array $outros = []): array
    {
        $ids = collect([...$contados, ...$outros])->pluck('quem')->filter()->unique()->reject(fn ($id) => $id === $quemVe->id);
        $ordem = collect($this->donos())->filter()->map->id->unique()->values();
        $contas = User::whereIn('id', $ids)->get(['id', 'name'])->keyBy('id');
        $nomes = $contas->map(fn (User $u) => Str::before(trim($u->name), ' ') ?: $u->name)->all();

        $n = collect($contados)->countBy(fn ($de) => self::chave($de, $quemVe));
        $botoes = [['chave' => 'eu', 'rotulo' => 'Para mim', 'n' => $n['eu'] ?? 0]];
        $posicao = fn ($id) => sprintf('%02d %s', $ordem->search($id) === false ? 99 : $ordem->search($id), $nomes[$id] ?? '');
        foreach ($ids->sortBy($posicao)->values() as $id) {
            // Quem só aparece nas tratadas não ganha botão: filtrar por ele não mostraria nada a tratar.
            if (! isset($n['u'.$id])) {
                continue;
            }
            $botoes[] = ['chave' => 'u'.$id, 'rotulo' => $nomes[$id] ?? 'Outra pessoa', 'n' => $n['u'.$id] ?? 0];
        }
        if (($n['sem'] ?? 0) > 0) {
            $botoes[] = ['chave' => 'sem', 'rotulo' => 'Sem dono', 'n' => $n['sem']];
        }

        return ['botoes' => $botoes, 'nomes' => $nomes];
    }

    /** O primeiro trecho do caminho do destino gravado (`/sistemas/3/edit` → `sistemas`). */
    private function caminho(?string $rota): string
    {
        return $rota ? explode('/', trim((string) parse_url($rota, PHP_URL_PATH), '/'))[0] : '';
    }
}
