<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * O vigia de logs (#219).
     *
     * O erro do Wellhub no AlfaGym (#191) passou semanas só no log: 36 de 36
     * check-ins falharam, o `catch` registrava um WARN e seguia, e ninguém
     * olha log de servidor por esporte. Cada servidor passa a mandar de hora em
     * hora os erros novos do próprio log para cá, e o AlfaMatriz agrupa, abre
     * Bug no quadro e avisa no Telegram.
     *
     * - `sistemas.vigia_token_hash`: o token com que o servidor de cada sistema
     *   se apresenta. Só o HASH, como o Sanctum faz com o do MCP: quem lê o
     *   banco (um backup, um dump de staging) não ganha a porta. Coluna no
     *   sistema, e não tabela própria, porque é um por sistema — o token diz DE
     *   QUAL sistema é o erro, e é daí que a tarefa tira o `sistema_id`.
     * - `vigia_erros`: uma linha por ASSINATURA (a mensagem sem os números,
     *   ids e datas que mudam a cada ocorrência), por sistema e ambiente. É o
     *   que impede 36 falhas iguais de virarem 36 tarefas.
     * - `vigia_erros_horas`: a contagem por hora, que é o que permite dizer
     *   "pico": dez vezes a média das últimas 24h.
     * - `vigia_ignorados`: o que o time decidiu que não é problema. Casou, só
     *   conta — nem tarefa, nem aviso.
     *
     * E a conta "Vigia de logs", em quem as tarefas abertas pelo vigia nascem.
     * Vai AQUI, e não num seeder, porque o deploy roda só `migrate --force`:
     * uma conta semeada não chegaria a produção, e a primeira tarefa
     * automática morreria procurando o autor.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('sistemas', 'vigia_token_hash')) {
            Schema::table('sistemas', function (Blueprint $tabela) {
                $tabela->string('vigia_token_hash', 64)->nullable();
            });
        }

        Schema::create('vigia_erros', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $tabela->string('ambiente', 20);
            // sha256 da assinatura: a mensagem normalizada sozinha pode passar
            // do limite de índice do MySQL.
            $tabela->char('assinatura', 64);
            // A mensagem já normalizada ("Pedido {n} não encontrado"): é o que
            // a lista de ignorados e quem investiga leem.
            $tabela->string('padrao', 500);
            $tabela->string('nivel', 20);
            $tabela->string('excecao', 255)->nullable();
            $tabela->text('mensagem');
            $tabela->text('trecho')->nullable();
            $tabela->string('origem', 150)->nullable();
            $tabela->dateTime('primeira_vez');
            $tabela->dateTime('ultima_vez');
            $tabela->unsignedInteger('total')->default(0);
            // `nullOnDelete`: a tarefa excluída do quadro não apaga a memória
            // do erro — ele voltando, ganha tarefa nova.
            $tabela->foreignId('tarefa_id')->nullable()->constrained('tarefas')->nullOnDelete();
            $tabela->boolean('ignorado')->default(false);
            // As duas travas de barulho: um comentário por dia na tarefa, um
            // aviso de pico a cada 6h.
            $tabela->dateTime('comentado_em')->nullable();
            $tabela->dateTime('pico_avisado_em')->nullable();
            $tabela->timestamps();

            $tabela->unique(['sistema_id', 'ambiente', 'assinatura']);
        });

        Schema::create('vigia_erros_horas', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->foreignId('vigia_erro_id')->constrained('vigia_erros')->cascadeOnDelete();
            // O início da hora, no fuso da aplicação.
            $tabela->dateTime('hora');
            $tabela->unsignedInteger('total')->default(0);

            $tabela->unique(['vigia_erro_id', 'hora']);
        });

        Schema::create('vigia_ignorados', function (Blueprint $tabela) {
            $tabela->id();
            // Nulo é global: vale para todos os sistemas.
            $tabela->foreignId('sistema_id')->nullable()->constrained('sistemas')->cascadeOnDelete();
            $tabela->string('padrao', 500);
            $tabela->timestamps();
        });

        // A conta do vigia: DESATIVADA (o login recusa conta inativa) e sem
        // perfil nenhum — não entra em tela, não triaga, não aparece entre
        // quem recebe tarefa. Existe só para assinar o que o vigia abre.
        // A senha é aleatória e ninguém a conhece; mesmo reativada por engano,
        // a conta não teria por onde entrar.
        if (! DB::table('users')->where('email', 'vigia-de-logs@alfamatriz.interno')->exists()) {
            $conta = [
                'name' => 'Vigia de logs',
                'email' => 'vigia-de-logs@alfamatriz.interno',
                'password' => Hash::make(Str::random(64)),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            foreach (['ativo' => false, 'primeiro_acesso' => false] as $coluna => $valor) {
                if (Schema::hasColumn('users', $coluna)) {
                    $conta[$coluna] = $valor;
                }
            }

            DB::table('users')->insert($conta);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vigia_ignorados');
        Schema::dropIfExists('vigia_erros_horas');
        Schema::dropIfExists('vigia_erros');

        if (Schema::hasColumn('sistemas', 'vigia_token_hash')) {
            Schema::table('sistemas', function (Blueprint $tabela) {
                $tabela->dropColumn('vigia_token_hash');
            });
        }

        // A conta FICA: as tarefas que ela abriu apontam para ela em
        // `criado_por_id`, e apagá-la quebraria o "aberta por" delas.
    }
};
