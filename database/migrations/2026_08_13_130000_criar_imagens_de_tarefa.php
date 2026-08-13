<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Imagens da tarefa — a prova que o texto não dá.
     *
     * "O botão saiu do lugar" é uma frase que só quem viu a tela entende. Na
     * revisão, que é onde a feature nasceu, descrever o defeito por escrito
     * custa mais rodadas do que mostrá-lo: a captura encerra a dúvida que três
     * idas e voltas de texto não encerravam.
     *
     * Tabela própria, e não uma coluna na tarefa: são várias por tarefa, cada
     * uma com nome, tamanho e autor próprios.
     *
     * A imagem é da TAREFA, e não do comentário. A escolha é do dono do
     * produto (13/08/2026), e o que ela compra é que a prova não depende de
     * achar em qual comentário ela foi parar — a galeria mostra tudo o que a
     * tarefa tem, na ordem em que chegou. O que ela custa é que a imagem não
     * sabe de que fala: quem quiser amarrar uma captura a um argumento escreve
     * o comentário do lado.
     */
    public function up(): void
    {
        Schema::create('tarefa_imagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tarefa_id')->constrained('tarefas')->cascadeOnDelete();

            // Mesmo trato do comentário: a imagem sobrevive à saída de quem a
            // anexou. Quem mandou o print pode deixar a empresa, e a prova do
            // defeito continua sendo o que a tarefa tem de mais caro — sem
            // autor, a legenda diz "Autor removido" em vez de a imagem sumir.
            $table->foreignId('autor_id')->nullable()->constrained('users')->nullOnDelete();

            // O trio nome/caminho/tamanho é o mesmo dos anexos de cobrança e
            // de conta a pagar. `nome_original` é como o arquivo se apresenta
            // a quem lê; `nome_arquivo` é o identificador aleatório no disco,
            // que não diz nada a ninguém e por isso nunca aparece na tela.
            $table->string('nome_original');
            $table->string('nome_arquivo');
            $table->string('caminho');
            $table->unsignedInteger('tamanho');
            $table->timestamps();

            // A leitura é sempre "as imagens desta tarefa, da mais antiga à
            // mais nova" — a galeria é cronológica pelo mesmo motivo da
            // conversa: a segunda captura costuma ser resposta à primeira.
            $table->index(['tarefa_id', 'id']);
        });
    }

    public function down(): void
    {
        // Só a tabela. Os arquivos ficam no disco de propósito: reverter uma
        // migração é desfazer o ESQUEMA, e apagar arquivo é irreversível — um
        // `down` que varre a pasta transformaria um passo atrás no deploy na
        // perda definitiva das capturas de todo mundo.
        Schema::dropIfExists('tarefa_imagens');
    }
};
