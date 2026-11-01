<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A mensagem padrão do roteiro "Portão parado" prometia "até 3 horas" ao cliente
     * (docs/specs/pedro-comunicacao, FR-018; Felipe, 31/10/2026: "não prometer SLA ou prazo de
     * atendimento sem confirmação no Gestor ou contrato"). O prazo de 3 h continua no roteiro, para
     * a equipe (a cobrança dos críticos); só a frase ao cliente muda.
     *
     * Só troca o texto que a migração 2026_10_20_150000 gravou, letra por letra: se alguém já
     * escreveu outra mensagem pela tela, ela fica — e o PRAZO do pedido à IA vale acima dela.
     */
    private const ANTES = 'Entendi, portão parado é prioridade para nós: a equipe técnica tem até 3 horas para atender.';

    private const DEPOIS = 'Entendi, portão parado é prioridade para nós e já vai para a equipe técnica.';

    public function up(): void
    {
        DB::table('suporte_roteiros')->where('titulo', 'Portão parado')->where('mensagem', self::ANTES)
            ->update(['mensagem' => self::DEPOIS, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('suporte_roteiros')->where('titulo', 'Portão parado')->where('mensagem', self::DEPOIS)
            ->update(['mensagem' => self::ANTES, 'updated_at' => now()]);
    }
};
