<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A galeria da tarefa deixa de ser só de imagem (US-064).
     *
     * A tabela nasceu de manhã guardando print, e a tarde mostrou o resto do
     * caso: o log do erro, a planilha que o cliente mandou, o PDF do boleto.
     * São a mesma coisa que a captura — a prova que o texto da tarefa não dá —
     * e estavam presas ao "Fora de escopo" do spec por não serem figura.
     *
     * RENOMEIA em vez de criar tabela ao lado. Duas tabelas significariam dois
     * modelos, duas rotas, dois componentes, dois selos no card e uma terceira
     * seção no modal de 620px — tudo isso para separar coisas que a pessoa
     * anexa pelo mesmo motivo, no mesmo gesto. `Schema::rename` preserva as
     * linhas: não é backfill, é troca de nome.
     *
     * Os nomes de ÍNDICE e de CHAVE ESTRANGEIRA continuam dizendo
     * `tarefa_imagens_*` depois do rename, de propósito. Renomeá-los pede SQL
     * cru que diverge entre o MySQL de produção e o SQLite da suíte (que os
     * recria ao reconstruir a tabela), e o nome de uma restrição não é lido por
     * ninguém além de quem depurar o schema — que encontrará este comentário.
     */
    public function up(): void
    {
        Schema::rename('tarefa_imagens', 'tarefa_anexos');

        Schema::table('tarefa_anexos', function (Blueprint $table) {
            // O tipo vem do CONTEÚDO do arquivo, não do nome que veio do
            // navegador: é ele que decide se o anexo sai como miniatura ou
            // como linha, e se a rota o entrega embutido ou como download.
            // Confiar na extensão do nome deixaria um `.png` cheio de PDF
            // virar uma miniatura quebrada na tela de todo mundo.
            //
            // Nulo permitido porque a coluna nasce sobre linhas que já
            // existem. A alternativa — NOT NULL e depois `change()` — reconstrói
            // a tabela inteira no SQLite da suíte, o que é risco maior do que a
            // checagem de nulo que o modelo já faz. Linha nova nunca a deixa
            // vazia: o `anexarArquivo` a preenche sempre.
            $table->string('mime')->nullable()->after('nome_arquivo');
        });

        // Toda linha que existe hoje é imagem — a tabela só aceitou imagem
        // desde que nasceu, hoje de manhã (`v2026.08.13.10`). O mime sai da
        // extensão porque é o que se tem sem reabrir cada arquivo no disco, e
        // aqui ela é confiável: passou pela validação `image` do dia anterior.
        foreach (['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'] as $extensao => $mime) {
            DB::table('tarefa_anexos')
                ->whereNull('mime')
                ->where('nome_arquivo', 'like', '%.'.$extensao)
                ->update(['mime' => $mime]);
        }

        // A que não casou com extensão nenhuma ainda é imagem: só isso entrou.
        // Sem esta linha ela viraria "arquivo" na tela e sairia da grade de
        // miniaturas por um erro de digitação no nome do disco.
        DB::table('tarefa_anexos')->whereNull('mime')->update(['mime' => 'image/png']);
    }

    public function down(): void
    {
        Schema::table('tarefa_anexos', function (Blueprint $table) {
            $table->dropColumn('mime');
        });

        Schema::rename('tarefa_anexos', 'tarefa_imagens');

        // Os arquivos que não são imagem ficam no disco e as linhas deles somem
        // junto com a coluna que os distinguia. É o mesmo trato do `down` da
        // migração que criou a tabela: reverter é desfazer o ESQUEMA, e apagar
        // arquivo de gente é irreversível.
    }
};
