<?php

use App\Services\MiniaturaDeAnexo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A grade de miniaturas para de baixar o arquivo original (US-064).
     *
     * A grade desenha caixas de ~140×105 e apontava o `<img>` para o anexo
     * inteiro — até 12 MB. Ver `MiniaturaDeAnexo` para o porquê de nenhuma
     * camada de fora resolver isso: a rota responde `private`, então a borda da
     * Cloudflare não guarda a figura, e PNG e JPEG não encolhem com `gzip`.
     *
     * Nulo permitido, e não é só por causa das linhas que já existem: a coluna
     * é nula SEMPRE que a miniatura não deve existir — anexo que não é figura,
     * figura que já é menor que a grade, formato que o GD não lê. Nulo aqui
     * significa "use o original", que é o comportamento de hoje, e não falha.
     */
    public function up(): void
    {
        Schema::table('tarefa_anexos', function (Blueprint $table) {
            $table->string('caminho_miniatura')->nullable()->after('caminho');
        });

        /*
         * As miniaturas dos anexos que já estavam no disco.
         *
         * Em migração, e não num comando avulso, porque é dado que precisa
         * valer em PRODUÇÃO — o deploy roda `migrate --force` e mais nada. Um
         * comando que alguém precisa lembrar de rodar é um comando que não
         * roda: as tarefas antigas continuariam baixando o original para
         * sempre, sem nada na tela dizendo por quê.
         *
         * O laço mora no serviço, e não aqui, para a suíte alcançá-lo: uma
         * migração roda uma vez, sobre uma tabela que no ambiente de teste está
         * vazia, e o que ela faz nunca seria exercitado. Lá também está por que
         * nenhuma falha de arquivo derruba a publicação.
         *
         * O `storage:link` do `deploy/publicar.sh` roda ANTES do `migrate`,
         * então o disco já está no lugar aqui. Quando não estiver — banco
         * restaurado sem a metade dos anexos —, o arquivo não é encontrado e a
         * linha fica nula, que é exatamente o que se quer.
         */
        MiniaturaDeAnexo::gerarAsQueFaltam();
    }

    public function down(): void
    {
        Schema::table('tarefa_anexos', function (Blueprint $table) {
            $table->dropColumn('caminho_miniatura');
        });

        // Os arquivos de miniatura ficam no disco, como no `down` das duas
        // migrações anteriores desta tabela: reverter é desfazer o ESQUEMA. E
        // aqui há um motivo a mais para não varrer a pasta — o nome derivado
        // (`-min.jpg`) é uma CONVENÇÃO, e apagar por padrão de nome alcançaria
        // um anexo de verdade que alguém tenha chamado assim.
    }
};
