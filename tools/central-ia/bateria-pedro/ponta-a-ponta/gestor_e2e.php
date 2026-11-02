<?php
/**
 * Ponta a ponta do orçamento pelo Pedro (docs/specs/pedro-orcamento, tarefa 5.8), no BANCO DE TESTES do
 * Gestor. Roda dentro do container do Gestor com DB_DATABASE=gestor_alfa_test e recusa qualquer outro
 * banco: a gravação é de verdade, mas nunca em produção nem na cópia de trabalho.
 *
 *   preparar <pasta>               cria empresa (id 3, como a INVEST), dois usuários (o dono e um comercial),
 *                                  clientes e atendimentos fictícios; guarda o maior id de cada tabela e
 *                                  imprime os ids em JSON
 *   pdf <id> <email> <saida.pdf>   a rota orcamentos.imprimir, com os middlewares da tela, logado como <email>
 *   desativar <email>
 *   contar <chave>                 orçamentos com essa matriz_chave
 *   orcamento <id>                 o que ficou gravado (total, NF, itens)
 *   limpar <pasta>                 apaga toda linha criada depois do "preparar", em todas as tabelas
 */
require '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Orcamento;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::connection()->getDatabaseName() !== 'gestor_alfa_test') {
    fwrite(STDERR, "recusado: só roda no banco gestor_alfa_test\n");
    exit(2);
}
$saida = fn ($v) => print(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
$tabelas = fn () => collect(DB::select('SHOW TABLES'))->map(fn ($t) => array_values((array) $t)[0])->filter(fn ($t) => Schema::hasColumn($t, 'id'))->values();

switch ($argv[1] ?? '') {
    case 'preparar':
        file_put_contents($argv[2].'/maiores.json', json_encode($tabelas()->mapWithKeys(fn ($t) => [$t => (int) DB::table($t)->max('id')])));
        $perfil = fn (string $slug) => Perfil::firstOrCreate(['slug' => $slug], ['nome' => ucfirst($slug)])->id;
        $usuario = function (string $nome, string $email, string $tipo, string $perfilSlug) use ($perfil) {
            $func = DB::table('funcionarios')->insertGetId(['nome' => $nome, 'ativo' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $u = User::create(['name' => $nome, 'email' => $email, 'password' => bcrypt(bin2hex(random_bytes(12))), 'tipo' => $tipo, 'ativo' => true,
                'funcionario_id' => $func, 'primeiro_acesso' => false]);
            $u->perfis()->sync([$perfil($perfilSlug)]);

            return $u;
        };
        $dono = $usuario('Felipe E2E', 'felipe.e2e@exemplo.local', 'funcionario', 'admin');
        $comercial = $usuario('Comercial E2E', 'comercial.e2e@exemplo.local', 'comercial', 'comercial');
        $empresa = App\Models\Empresa::forceCreate(['id' => 3, 'razao_social' => 'Invest E2E Ltda', 'nome_fantasia' => 'INVEST', 'cnpj' => '00000000000391', 'layout_pdf' => 'empresa3']);
        $cliente = fn (string $nome) => App\Models\Cliente::create(['tipo_pessoa' => 'PJ', 'cpf_cnpj' => (string) random_int(10000000000000, 99999999999999), 'nome' => $nome, 'ativo' => true]);
        $marAzul = $cliente('Residencial Mar Azul');
        $padaria = $cliente('Padaria Sol');
        $assunto = DB::table('assuntos')->insertGetId(['nome' => 'PORTÃO / ACESSO', 'created_at' => now(), 'updated_at' => now()]);
        $atendimento = fn ($c, string $numero, string $descricao) => App\Models\Atendimento::create(['numero_atendimento' => $numero, 'cliente_id' => $c->id,
            'nome_solicitante' => 'Síndico', 'assunto_id' => $assunto, 'descricao' => $descricao, 'data_atendimento' => now()->toDateString(), 'status_atual' => 'aberto', 'empresa_id' => 3]);
        $at812 = $atendimento($marAzul, '812', 'Motor do portão travando na abertura');
        $atendimento($padaria, '813', 'Câmera da entrada sem imagem');
        $saida(['banco' => DB::connection()->getDatabaseName(), 'empresa' => ['id' => $empresa->id, 'nome' => 'INVEST', 'imposto_nf' => Orcamento::percentualImpostoNfVigente($empresa->id)],
            'dono' => $dono->email, 'comercial' => $comercial->email, 'mar_azul' => $marAzul->id, 'padaria' => $padaria->id, 'at812' => $at812->id]);
        break;

    case 'pdf':
        $u = User::where('email', $argv[3])->firstOrFail();
        $app['auth']->guard('web')->setUser($u);
        $http = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $r = $http->handle(Illuminate\Http\Request::create('/orcamentos/'.(int) $argv[2].'/imprimir', 'GET'));
        file_put_contents($argv[4], $r->getContent());
        $saida(['status' => $r->getStatusCode(), 'tipo' => $r->headers->get('Content-Type'), 'bytes' => strlen($r->getContent()), 'comeca' => substr($r->getContent(), 0, 5)]);
        break;

    case 'desativar':
        User::where('email', $argv[2])->update(['ativo' => false]);
        $saida(['desativado' => $argv[2]]);
        break;

    case 'contar':
        $saida(['chave' => $argv[2], 'orcamentos' => Orcamento::where('matriz_chave', $argv[2])->count(), 'todos' => Orcamento::count()]);
        break;

    case 'orcamento':
        $o = Orcamento::with('itens')->findOrFail((int) $argv[2]);
        $saida(['id' => $o->id, 'numero' => $o->numero_orcamento, 'status' => $o->status, 'empresa_id' => $o->empresa_id, 'cliente_id' => $o->cliente_id,
            'atendimento_id' => $o->atendimento_id, 'sem_nf' => (bool) $o->sem_nf, 'imposto_nf_percentual' => (float) $o->imposto_nf_percentual,
            'valor_total' => (float) $o->valor_total, 'created_by' => $o->created_by, 'vendedor_id' => $o->vendedor_id, 'matriz_chave' => $o->matriz_chave,
            'descricao' => $o->descricao, 'itens' => $o->itens->map(fn ($i) => [$i->nome ?? $i->descricao, (float) $i->quantidade, (float) $i->valor_unitario])->all()]);
        break;

    case 'limpar':
        $maiores = json_decode((string) file_get_contents($argv[2].'/maiores.json'), true);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $apagadas = [];
        foreach ($tabelas() as $t) {
            if ($n = DB::table($t)->where('id', '>', (int) ($maiores[$t] ?? 0))->delete()) {
                $apagadas[$t] = $n;
            }
        }
        // perfil_user não tem id: some com os usuários criados.
        DB::table('perfil_user')->whereNotIn('user_id', DB::table('users')->pluck('id'))->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $saida(['apagadas' => $apagadas, 'orcamentos' => Orcamento::count(), 'users' => User::count(), 'empresas' => DB::table('empresas')->count()]);
        break;

    default:
        fwrite(STDERR, "uso: preparar|pdf|desativar|contar|orcamento|limpar\n");
        exit(1);
}
