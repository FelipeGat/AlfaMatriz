<?php

namespace App\Http\Controllers;

use App\Models\SecretarioVixOrganize;
use App\Services\Secretario\VixOrganize\Cliente;
use App\Services\Secretario\VixOrganize\ErroDoVixOrganize;
use App\Services\Secretario\VixOrganize\Retrato;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Projetos da VixCard pelo VixOrganize (docs/specs/secretario-vixorganize):
 * a fila lida da API e as demandas enviadas para lá, tudo da própria conta.
 * Cada envio vai na hora e diz se deu certo (FR-005).
 */
class SecretarioVixOrganizeController extends Controller
{
    public const STATUS = ['aguardando' => 'Aguardando', 'em_andamento' => 'Em andamento', 'pausado' => 'Pausado', 'concluido' => 'Concluído'];

    public function __construct(private Retrato $retrato) {}

    public function index(Request $request): View|RedirectResponse
    {
        $conta = SecretarioVixOrganize::daConta($request->user());
        if (! $conta) {
            return redirect()->to(route('secretario.configuracoes').'#vixorganize')
                ->with('status', 'Ligue o VixOrganize aqui para ver os projetos da VixCard.');
        }
        $projetos = $conta->projetos();
        $slug = (string) $request->query('projeto', '');
        $aberto = $slug !== '' ? $conta->projeto($slug) : collect($projetos)->first(fn ($p) => $p['status'] !== 'concluido');

        return view('secretario.vixorganize', ['conta' => $conta, 'projetos' => $projetos, 'aberto' => $aberto]);
    }

    /** Liga ou troca a conexão. Só guarda se o VixOrganize aceitar a chave. */
    public function conectar(Request $request): RedirectResponse
    {
        $atual = SecretarioVixOrganize::daConta($request->user());
        $d = $request->validate([
            'url' => ['required', 'string', 'max:190'],
            'chave' => [$atual ? 'nullable' : 'required', 'string', 'min:16', 'max:300'],
            'autor_email' => ['nullable', 'email', 'max:190'],
        ]);
        [$url, $erro] = Cliente::normalizarUrl($d['url']);
        if (! $url) {
            return $this->voltarConfig($erro, true);
        }
        $conta = $atual ?? new SecretarioVixOrganize(['user_id' => $request->user()->id]);
        $conta->fill(['url' => $url, 'autor_email' => $d['autor_email'] ?? null] + (empty($d['chave']) ? [] : ['chave' => $d['chave']]));
        try {
            $dados = (new Cliente($conta))->lerTudo();
        } catch (ErroDoVixOrganize $e) {
            return $this->voltarConfig('Não conectou: '.$e->getMessage(), true);
        }
        $conta->fill(['retrato' => $dados, 'lido_em' => now(), 'erro' => null, 'erro_em' => null])->save();
        // O chat de Business do VixOrganize nasce junto (Felipe, 17/10/2026).
        app(\App\Services\Secretario\VixOrganize\NaCentral::class)->garantirChat($request->user(), $conta);

        return $this->voltarConfig('VixOrganize conectado: '.count($dados['projetos']).' projeto(s) na fila.');
    }

    public function desconectar(Request $request): RedirectResponse
    {
        SecretarioVixOrganize::de($request->user())->delete();

        return $this->voltarConfig('VixOrganize desligado. A chave e o retrato foram apagados daqui.');
    }

    public function atualizar(Request $request): RedirectResponse
    {
        $erro = $this->retrato->atualizar($this->conta($request));

        return back()->with($erro ? 'erro' : 'status', $erro ?? 'Fila relida agora.');
    }

    public function criarProjeto(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_date' => ['nullable', 'date'],
        ]);

        return $this->enviar($request, fn (Cliente $c) => $c->criarProjeto(array_filter($d)), 'Projeto criado no VixOrganize.', true);
    }

    public function status(Request $request, string $projeto): RedirectResponse
    {
        $d = $request->validate(['status' => ['required', Rule::in(array_keys(self::STATUS))]]);

        return $this->enviar($request, fn (Cliente $c) => $c->status($projeto, $d['status']), 'Status enviado: '.self::STATUS[$d['status']].'.');
    }

    public function novoPasso(Request $request, string $projeto): RedirectResponse
    {
        $d = $request->validate([
            'titulo' => ['required', 'string', 'max:200'],
            'notas' => ['nullable', 'string', 'max:4000'],
            'fase' => ['nullable', 'integer'],
        ]);

        return $this->enviar($request, fn (Cliente $c) => $c->novoPasso($projeto, $d['titulo'], $d['notas'] ?? null, $d['fase'] ?? null), 'Demanda enviada ao VixOrganize.');
    }

    public function marcar(Request $request, string $projeto, int $passo): RedirectResponse
    {
        $request->validate(['feito' => ['required', 'boolean']]);
        $feito = $request->boolean('feito');

        return $this->enviar($request, fn (Cliente $c) => $c->marcar($projeto, $passo, $feito), $feito ? 'Passo marcado.' : 'Passo desmarcado.');
    }

    public function registrar(Request $request, string $projeto, int $passo): RedirectResponse
    {
        $d = $request->validate(['texto' => ['required', 'string', 'max:4000']]);

        return $this->enviar($request, function (Cliente $c) use ($projeto, $passo, $d) {
            $c->registrar($projeto, $passo, $d['texto']);

            return null;
        }, 'Registro enviado ao VixOrganize.');
    }

    /** Faz o envio e guarda o projeto que voltou; o erro volta para a tela, com o que foi digitado. */
    private function enviar(Request $request, callable $acao, string $ok, bool $irParaONovo = false): RedirectResponse
    {
        $conta = $this->conta($request);
        try {
            $projeto = $acao(new Cliente($conta));
        } catch (ErroDoVixOrganize $e) {
            return back()->withInput()->with('erro', $e->getMessage());
        }
        if (is_array($projeto) && isset($projeto['slug'])) {
            $this->retrato->guardar($conta, $projeto);
        } else {
            $this->retrato->atualizar($conta);
        }
        if ($irParaONovo && isset($projeto['slug'])) {
            return redirect()->route('secretario.vixorganize', ['projeto' => $projeto['slug']])->with('status', $ok);
        }

        return back()->with('status', $ok);
    }

    private function conta(Request $request): SecretarioVixOrganize
    {
        return SecretarioVixOrganize::daConta($request->user()) ?? abort(404);
    }

    private function voltarConfig(string $mensagem, bool $erro = false): RedirectResponse
    {
        return redirect()->to(route('secretario.configuracoes').'#vixorganize')->withInput()->with($erro ? 'erro' : 'status', $mensagem);
    }
}
