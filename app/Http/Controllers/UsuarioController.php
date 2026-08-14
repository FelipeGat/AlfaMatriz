<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Gestão de contas do painel.
 *
 * Até aqui, criar alguém exigia SSH e `alfa:criar-usuario`. Os dois comandos
 * continuam valendo — são a saída para o dia em que ninguém conseguir entrar —,
 * mas deixam de ser o único caminho.
 *
 * A senha nunca é escolhida por quem administra: o sistema gera, mostra uma
 * vez e marca `primeiro_acesso`. Campo de senha no formulário produziria a
 * mesma senha para o time inteiro, que é o que se vê sempre que se oferece um.
 */
class UsuarioController extends Controller
{
    public function __construct()
    {
        $this->bloquearVisaoDaMatriz();
    }

    public function index(Request $request): View
    {
        $filtros = [
            'busca' => trim((string) $request->query('busca', '')),
            'perfil' => (string) $request->query('perfil', ''),
            'situacao' => (string) $request->query('situacao', ''),
        ];

        $usuarios = User::with(['perfis', 'revenda'])
            ->when($filtros['busca'] !== '', function ($query) use ($filtros) {
                $termo = '%'.$filtros['busca'].'%';
                $query->where(fn ($q) => $q->where('name', 'like', $termo)->orWhere('email', 'like', $termo));
            })
            ->when($filtros['perfil'] !== '', fn ($query) => $query->whereHas(
                'perfis',
                fn ($q) => $q->where('slug', $filtros['perfil'])
            ))
            ->when($filtros['situacao'] === 'ativo', fn ($query) => $query->where('ativo', true))
            ->when($filtros['situacao'] === 'desativado', fn ($query) => $query->where('ativo', false))
            ->when($filtros['situacao'] === 'pendente', fn ($query) => $query->where('primeiro_acesso', true))
            ->orderBy('name')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        // Os KPIs falam da BASE, não da página nem do recorte: é o mesmo
        // contrato do resto do painel (ver `Controller::paginarColecao`), e sem
        // ele o cabeçalho passaria a descrever o filtro em vez do sistema.
        $kpis = [
            'ativos' => User::where('ativo', true)->count(),
            'desativados' => User::where('ativo', false)->count(),
            'admins' => User::where('ativo', true)
                ->whereHas('perfis', fn ($q) => $q->where('slug', 'admin'))->count(),
            'pendentes' => User::where('ativo', true)->where('primeiro_acesso', true)->count(),
        ];

        return view('usuarios.index', [
            'aba' => $request->query('aba') === 'perfis' ? 'perfis' : 'usuarios',
            'usuarios' => $usuarios,
            'filtros' => $filtros,
            'kpis' => $kpis,
            'perfis' => Perfil::orderBy('nome')->get(),
            'revendas' => Revenda::orderBy('nome')->get(),
            // A grade da aba de permissões. `descricao` é o rótulo humano do
            // recurso, e é por isso que ela existe na tabela.
            'permissoes' => Permissao::orderBy('descricao')->get(),
            'perfisComGrade' => Perfil::with('permissoes')->orderBy('nome')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validar($request);

        if ($erro = $this->recusarPromocaoIndevida($request, $dados['perfis'])) {
            return $erro;
        }

        $senha = $this->senhaGerada();

        $usuario = new User([
            'name' => $dados['name'],
            'email' => $dados['email'],
            'password' => $senha,
            'revenda_id' => $dados['revenda_id'] ?? null,
            'ativo' => true,
            'primeiro_acesso' => true,
        ]);

        // Fora do construtor porque `email_verified_at` não é fillable — passado
        // ali, ele seria descartado em silêncio e a conta nasceria presa no
        // middleware `verified`. O `alfa:criar-usuario` atribui pelo mesmo
        // motivo. Já nasce liberada porque não há envio de e-mail configurado.
        $usuario->email_verified_at = now();
        $usuario->save();

        $this->sincronizarPerfis($usuario, $dados['perfis']);

        return back()->with('senha_gerada', ['nome' => $usuario->name, 'email' => $usuario->email, 'senha' => $senha]);
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $dados = $this->validar($request, $usuario);

        if ($erro = $this->recusarPromocaoIndevida($request, $dados['perfis'], $usuario)) {
            return $erro;
        }

        // Tirar o próprio admin é a única forma que sobrou de alguém se trancar
        // do lado de fora — o perfil `admin` em si é imutável. A recusa é aqui,
        // e não na view, porque a view não é o que guarda o sistema.
        if ($usuario->id === $request->user()->id && ! $this->contémAdmin($dados['perfis'])) {
            return back()->with('erro', 'Você não pode tirar o próprio perfil de administrador. Peça a outro administrador.');
        }

        $usuario->update([
            'name' => $dados['name'],
            'email' => $dados['email'],
            'revenda_id' => $dados['revenda_id'] ?? null,
        ]);

        $this->sincronizarPerfis($usuario, $dados['perfis']);

        return back()->with('status', "Conta de {$usuario->name} atualizada.");
    }

    /**
     * Nova senha para quem perdeu a que tinha. Não é "ver a senha": ninguém,
     * nem o administrador, consegue ler a atual — ela só existe em hash.
     */
    public function redefinirSenha(Request $request, User $usuario): RedirectResponse
    {
        // Mesma regra de AC-264, do outro lado: quem não é administrador não
        // lê a senha nova de quem é. Sem isto, a permissão `usuarios` sozinha
        // bastaria para tomar a conta do admin — não promovendo, mas trocando
        // a chave da porta dele.
        if ($usuario->ehAdmin() && ! $request->user()->ehAdmin()) {
            Auditoria::registrar(
                recurso: 'usuarios',
                acao: 'senha_admin_recusada',
                alvo: $usuario,
                descricao: $usuario->email,
            );

            return back()->with('erro', 'Só um administrador redefine a senha de outro administrador.');
        }

        $senha = $this->senhaGerada();

        // O automático do trait é calado aqui porque ele diria "alterou
        // usuário: password, primeiro_acesso" — verdade, e não a informação.
        // Quem lê a auditoria quer achar "fulano redefiniu a senha de
        // beltrano", que é uma das poucas ações capazes de dar a conta de
        // alguém a outra pessoa.
        Auditoria::semRegistro(
            fn () => $usuario->update(['password' => $senha, 'primeiro_acesso' => true])
        );

        Auditoria::registrar(
            recurso: 'usuarios',
            acao: 'senha',
            alvo: $usuario,
            // A senha gerada NÃO entra — nem aqui, nem no antes/depois. Ela é
            // mostrada uma vez na tela de quem a gerou e some; guardá-la numa
            // tabela que ninguém apaga desfaria isso.
            descricao: $usuario->email,
        );

        return back()->with('senha_gerada', ['nome' => $usuario->name, 'email' => $usuario->email, 'senha' => $senha]);
    }

    public function alternarAtivo(Request $request, User $usuario): RedirectResponse
    {
        if ($erro = $this->recusarAutoSabotagem($request, $usuario, 'desativar a própria conta')) {
            return $erro;
        }

        $usuario->update(['ativo' => ! $usuario->ativo]);

        return back()->with('status', $usuario->ativo
            ? "{$usuario->name} voltou a ter acesso."
            : "{$usuario->name} não entra mais no painel.");
    }

    public function destroy(Request $request, User $usuario): RedirectResponse
    {
        if ($erro = $this->recusarAutoSabotagem($request, $usuario, 'excluir a própria conta')) {
            return $erro;
        }

        $usuario->delete();

        return back()->with('status', "Conta de {$usuario->name} excluída.");
    }

    /**
     * Troca os perfis da conta e deixa o rastro disso.
     *
     * O rastro precisa ser escrito à mão porque a tabela que muda é a de
     * ligação: `perfil_user` não é modelo, não dispara evento do Eloquent, e
     * portanto passa inteira por baixo do trait de auditoria. Sem isto, a
     * mudança que mais importa na tela de usuários — quem virou administrador,
     * e quando — seria a única que não deixaria linha.
     *
     * Guarda os NOMES, e não os ids: a linha tem de continuar legível anos
     * depois, e "de [Operação] para [Administrador]" responde sozinha, enquanto
     * "de [3] para [1]" exige uma consulta a uma tabela que pode ter mudado.
     *
     * @param  array<int, int>  $perfis
     */
    private function sincronizarPerfis(User $usuario, array $perfis): void
    {
        $antes = $usuario->perfis()->orderBy('nome')->pluck('nome')->all();

        $usuario->perfis()->sync($perfis);

        $depois = $usuario->perfis()->orderBy('nome')->pluck('nome')->all();

        // Salvar o formulário sem mexer nos perfis é o caso comum — a tela é a
        // mesma para corrigir um e-mail. Registrar assim mesmo encheria a
        // auditoria de mudanças de permissão que não mudaram permissão nenhuma,
        // que é o tipo de ruído capaz de fazer a tela parar de ser lida.
        if ($antes === $depois) {
            return;
        }

        Auditoria::registrar(
            recurso: 'usuarios',
            acao: 'permissoes',
            alvo: $usuario,
            descricao: $usuario->email,
            alteracoes: ['perfis' => [
                'de' => $antes ? implode(', ', $antes) : 'nenhum',
                'para' => $depois ? implode(', ', $depois) : 'nenhum',
            ]],
        );
    }

    /**
     * @return array{name: string, email: string, perfis: array<int, int>, revenda_id: ?int}
     */
    private function validar(Request $request, ?User $usuario = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // A unicidade alcança as contas excluídas de propósito: o índice do
            // banco também alcança, e deixar a validação passar só trocaria uma
            // mensagem clara por um erro de integridade na cara do usuário.
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario)],
            'perfis' => ['required', 'array', 'min:1'],
            'perfis.*' => ['integer', Rule::exists('perfis', 'id')],
            'revenda_id' => ['nullable', Rule::exists('revendas', 'id')],
        ], [
            'perfis.required' => 'Escolha ao menos um perfil — sem perfil a conta entra e não abre tela nenhuma.',
            'email.unique' => 'Já existe uma conta com este e-mail (inclusive entre as excluídas).',
        ], [
            'name' => 'nome',
            'email' => 'e-mail',
            'perfis' => 'perfis',
            'revenda_id' => 'revenda',
        ]);
    }

    /**
     * Sem símbolos: esta senha é ditada, copiada e digitada por duas pessoas
     * antes de virar a senha de ninguém — pelo mesmo motivo do
     * `alfa:criar-acessos-de-revendas`. Vinte caracteres alfanuméricos cobrem
     * de sobra a folga que os símbolos dariam.
     */
    private function senhaGerada(): string
    {
        return Str::password(20, symbols: false);
    }

    /** @param  array<int, int>  $perfis */
    private function contémAdmin(array $perfis): bool
    {
        return Perfil::whereIn('id', $perfis)->where('slug', 'admin')->exists();
    }

    /**
     * Só administrador promove alguém a administrador — a mesma trava que
     * `PerfilController` já escreve no cabeçalho dele, aplicada do lado de cá:
     * lá o perfil `admin` não se edita, aqui ninguém que não o tenha o concede.
     *
     * Sem isto, `usuarios` é administrador por outro nome: quem a tem marca o
     * próprio perfil como Administrador e a permissão vira posse do sistema
     * inteiro, não gestão de contas. Hoje só o admin tem `usuarios` — a recusa
     * existe para o dia em que outro perfil a ganhar.
     *
     * A recusa é total, não uma poda do que foi enviado: o formulário volta
     * inteiro sem aplicar nada, e os perfis da conta (nova ou existente)
     * continuam exatamente como estavam.
     *
     * @param  array<int, int>  $perfis
     */
    private function recusarPromocaoIndevida(Request $request, array $perfis, ?User $alvo = null): ?RedirectResponse
    {
        if (! $this->contémAdmin($perfis) || $request->user()->ehAdmin()) {
            return null;
        }

        Auditoria::registrar(
            recurso: 'usuarios',
            acao: 'promocao_recusada',
            alvo: $alvo,
            descricao: $alvo?->email ?? (string) $request->input('email'),
        );

        return back()->with('erro', 'Só um administrador promove alguém a administrador.');
    }

    /**
     * Fechar a própria conta e deixar o sistema sem administrador ativo são o
     * mesmo acidente visto de dois ângulos. O segundo teste parece redundante
     * hoje — só o admin abre esta tela, então sempre sobraria ele —, e existe
     * para o dia em que outro perfil ganhar o recurso `usuarios`.
     */
    private function recusarAutoSabotagem(Request $request, User $usuario, string $acao): ?RedirectResponse
    {
        if ($usuario->id === $request->user()->id) {
            return back()->with('erro', "Você não pode {$acao}. Peça a outro administrador.");
        }

        $ultimoAdmin = User::where('ativo', true)
            ->where('id', '!=', $usuario->id)
            ->whereHas('perfis', fn ($q) => $q->where('slug', 'admin'))
            ->doesntExist();

        if ($ultimoAdmin && $usuario->ehAdmin()) {
            return back()->with('erro', 'Esta é a última conta de administrador ativa. Promova outra pessoa antes.');
        }

        return null;
    }
}
