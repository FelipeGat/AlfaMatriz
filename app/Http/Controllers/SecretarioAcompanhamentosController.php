<?php

namespace App\Http\Controllers;

use App\Models\Notificacao;
use App\Models\SecretarioAcompanhamento;
use App\Models\SecretarioItem;
use App\Models\SecretarioPreferencia;
use App\Models\User;
use App\Services\Secretario\Acompanhamento\Politica;
use App\Services\Secretario\Acompanhamento\Regras;
use App\Services\Secretario\Areas;
use App\Services\Secretario\CentralDeGestao;
use App\Services\Secretario\Despachante;
use App\Services\Secretario\Itens;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Secretário → Acompanhamentos (docs/specs/secretario-acompanhamento, US3):
 * a lista, as duas ações, o histórico de envios e as regras. Tudo por
 * `de($request->user())`: de outra conta, administrador incluído, é 404.
 */
class SecretarioAcompanhamentosController extends Controller
{
    /** Quantas não lidas a seção de notificações desenha (ver `notificacoes()`). */
    public const NOTIFICACOES_NA_TELA = 300;

    public function index(Request $request, Politica $politica, CentralDeGestao $central, Areas $areas): View
    {
        $user = $request->user();
        $config = Regras::de($user);
        $agora = CarbonImmutable::now();
        $linhas = $central->linhas($user, $agora);
        $notificacoes = $this->notificacoes($request);

        return view('secretario.acompanhamentos', [
            // Etapa 8: a Central de gestão no topo (números, Grupo Soluções, VixCard).
            'numeros' => $central->numeros($user, $agora),
            'gestor' => $central->grupoSolucoes($user),
            'linhas' => $linhas,
            'atividade' => $central->atividade($user),
            'dispensados' => SecretarioAcompanhamento::de($user)->where('situacao', SecretarioAcompanhamento::DISPENSADO)->count(),
            'config' => $config,
            'silencio' => $politica->emSilencio($config, CarbonImmutable::now()),
            // Os itens do aviso do sino que trouxe a pessoa até aqui, para resolver ali mesmo.
            'doAviso' => ($ids = $this->idsDoAviso($request))
                ? SecretarioAcompanhamento::de($user)->whereIn('id', $ids)->orderByRaw("prioridade = 'alta' desc")->orderBy('referencia_em')->get()
                : collect(),
        ] + $notificacoes + $this->deQuem($user, $areas, $notificacoes, $linhas));
    }

    /**
     * De quem é cada linha da tela (Felipe, 07/11/2026; ver `Areas`): os
     * avisos por id, e o filtro com a contagem do que está A TRATAR — as
     * notificações não lidas e a tabela. As tratadas ganham o rótulo, mas não
     * contam: o número diz quanto falta.
     *
     * @return array{deAviso: array<int, array{quem: ?int, area: ?string}>, filtroQuem: array, donosDasAreas: array<string, ?User>}
     */
    private function deQuem(User $user, Areas $areas, array $n, Collection $linhas): array
    {
        $avisos = $n['notificacoes']->concat($n['tratadas'])->when($n['notificacaoFoco'], fn ($c, $foco) => $c->push($foco))->unique('id');
        $deAviso = $areas->dosAvisos($avisos, $user);
        $aTratar = $n['notificacoes']->map(fn ($a) => $deAviso[$a->id])->values()->all();

        return [
            'deAviso' => $deAviso,
            'filtroQuem' => $areas->filtro([...$aTratar, ...$linhas->pluck('de')->all()], $user, array_values($deAviso)),
            'donosDasAreas' => $areas->donos(),
        ];
    }

    /**
     * As notificações da conta, para tratar uma a uma (Felipe, 07/11/2026: "em
     * acompanhamentos preciso mostrar todas as notificações para tratar elas
     * individualmente"). O sino mostra 12; aqui vão TODAS as não lidas.
     *
     * Tratada é a mesma `lida_em` do sino, de propósito: o número da bolinha e
     * esta lista contam a mesma coisa, e tratar aqui baixa o número lá.
     *
     * O teto de 300 é de tela, não de regra — a conta que acumulou mais do que
     * isso vê as mais recentes e o número das que ficaram de fora, que é o que
     * a tela consegue entregar sem virar uma página de vários megabytes.
     *
     * Por `destinatario_id`, sempre: aviso é da pessoa, administrador incluído.
     * O destaque (`?notificacao=`) de outra conta simplesmente não acha nada.
     */
    private function notificacoes(Request $request): array
    {
        $id = $request->user()->id;
        $daConta = fn () => Notificacao::where('destinatario_id', $id);

        return [
            'notificacoes' => $daConta()->whereNull('lida_em')
                ->orderByRaw("case nivel when 'critico' then 0 when 'atencao' then 1 else 2 end")
                ->latest('id')->limit(self::NOTIFICACOES_NA_TELA)->get(),
            'notificacoesTotal' => $daConta()->whereNull('lida_em')->count(),
            'tratadas' => $daConta()->whereNotNull('lida_em')->where('lida_em', '>=', now()->subDays(7))
                ->latest('lida_em')->limit(50)->get(),
            'notificacaoFoco' => $request->integer('notificacao') > 0
                ? $daConta()->find($request->integer('notificacao'))
                : null,
        ];
    }

    /** Os itens do aviso: pelo envio do sino (`?aviso=`), só da própria pessoa. @return list<int> */
    private function idsDoAviso(Request $request): array
    {
        if (! $request->filled('aviso')) {
            return [];
        }
        $envio = \App\Models\SecretarioAcompanhamentoEnvio::where('user_id', $request->user()->id)->find((int) $request->query('aviso'));

        return array_slice(array_map('intval', (array) ($envio?->acompanhamentos ?? [])), 0, 100);
    }

    public function resolver(Request $request, int $acompanhamento): RedirectResponse
    {
        SecretarioAcompanhamento::de($request->user())->findOrFail($acompanhamento)->update(['situacao' => SecretarioAcompanhamento::DISPENSADO]);

        return back()->with('status', 'Marcado como resolvido. Só volta se a situação deixar de valer e acontecer de novo.');
    }

    public function adiar(Request $request, int $acompanhamento): RedirectResponse
    {
        $a = SecretarioAcompanhamento::de($request->user())->findOrFail($acompanhamento);
        $a->update(['adiado_ate' => now()->addDay()]);

        return back()->with('status', 'Adiado até amanhã às '.$a->adiado_ate->format('H:i').'.');
    }

    public function salvarRegras(Request $request): RedirectResponse
    {
        $dados = $request->validate(Regras::validacao());
        SecretarioPreferencia::updateOrCreate(['user_id' => $request->user()->id], ['acompanhamento' => Regras::normalizar($dados)]);

        // As regras moram em Configurações (Etapa 8).
        return redirect()->to(route('secretario.configuracoes').'#avisos')->with('status', 'Regras dos avisos salvas. Valem a partir da próxima conferência (até 5 min).');
    }

    /** Ponto de gestão lançado à mão (a VixCard ainda não tem sistema): vira tarefa do Secretário, com lembrete no prazo. */
    public function lancarPonto(Request $request, Despachante $despachante): RedirectResponse
    {
        $d = $request->validate([
            'empresa' => ['required', Rule::in(array_keys(CentralDeGestao::EMPRESAS))],
            'titulo' => ['required', 'string', 'max:190'],
            'prazo' => ['nullable', 'date_format:Y-m-d'],
            'prioridade' => ['required', Rule::in(['baixa', 'normal', 'alta'])],
        ]);
        $item = SecretarioItem::create(['user_id' => $request->user()->id, 'tipo' => 'tarefa', 'titulo' => $d['titulo'], 'empresa' => $d['empresa'],
            'quando' => $d['prazo'] ? $d['prazo'].' 00:00:00' : null, 'so_data' => (bool) $d['prazo'], 'prioridade' => $d['prioridade'], 'origem' => 'matriz']);
        $despachante->programar($item);

        return redirect()->to(route('secretario.acompanhamentos').'#'.$d['empresa'])->with('status', 'Ponto lançado em '.CentralDeGestao::EMPRESAS[$d['empresa']].'.');
    }

    public function concluirPonto(Request $request, int $item, Itens $itens): RedirectResponse
    {
        $i = SecretarioItem::de($request->user())->whereNotNull('empresa')->findOrFail($item);
        $itens->agir($request->user(), $i, 'concluir');

        return back()->with('status', 'Ponto concluído.');
    }
}
