<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Os alertas do AlfaMonitor (#394, fase 1).
     *
     * O monitoramento passa a ser visto no Matriz, sem rede e câmeras. O
     * AlfaMonitor continua independente e continua sendo QUEM AVISA (Telegram,
     * push); aqui só se mostra. Ele empurra cada alerta por webhook, e o
     * Matriz guarda.
     *
     * - `sistemas.monitor_token_hash`: o token com que o monitor se apresenta,
     *   só o hash, como o do vigia (`vigia_token_hash`). Na linha do sistema
     *   que manda — o AlfaMonitor —, e não numa tabela própria: é um token por
     *   fonte, e é o mesmo jeito de emitir, girar e revogar.
     * - `monitor_alertas`: uma linha por (kind, entity), o par que o monitor
     *   usa para não repetir alerta e para ligar o "voltou" à queda. É o estado
     *   de AGORA: fora ou de volta, desde quando, quantas quedas.
     * - `monitor_eventos`: cada notificação que chegou, pelo `event_id` do
     *   monitor. O UNIQUE nele é a trava contra a nova tentativa: o monitor
     *   reenvia por até ~8h40 quando a resposta não é 2xx, e o mesmo evento
     *   pode chegar mais de uma vez.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('sistemas', 'monitor_token_hash')) {
            Schema::table('sistemas', function (Blueprint $tabela) {
                $tabela->string('monitor_token_hash', 64)->nullable();
            });
        }

        Schema::create('monitor_alertas', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->string('kind', 20);
            // A chave estável do monitor ("GYM VPS:cpu", "AlfaControl:123").
            // 191: cabe no índice único do MySQL com utf8mb4 junto do kind.
            $tabela->string('entity', 191);
            // O nome legível (o da catraca, o do servidor). Opcional no
            // contrato: sem ele, a tela mostra o título.
            $tabela->string('entity_name')->nullable();
            // O nome do cliente como veio, e o cliente do Matriz que casou com
            // ele, se casou — sem obrigar: o monitor só manda o nome.
            $tabela->string('cliente')->nullable();
            $tabela->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $tabela->string('severity', 10);
            $tabela->string('title');
            $tabela->text('body')->nullable();
            $tabela->string('link', 500)->nullable();
            $tabela->boolean('aberto')->default(false);
            $tabela->dateTime('caiu_em')->nullable();
            $tabela->dateTime('voltou_em')->nullable();
            // O "voltou" chegou sem a queda que ele fecha: o evento se perdeu,
            // ou ainda está na fila de novas tentativas do monitor.
            $tabela->boolean('voltou_sem_queda')->default(false);
            $tabela->unsignedInteger('quedas')->default(0);
            $tabela->unsignedBigInteger('incident_id')->nullable();
            // O último evento que MUDOU o estado — é contra ele que se decide
            // se o próximo chegou fora de ordem (occurred_at, e o event_id no
            // empate).
            $tabela->dateTime('ultimo_evento_em')->nullable();
            $tabela->unsignedBigInteger('ultimo_event_id')->nullable();
            // O último aviso de qualquer tipo, lembrete incluído.
            $tabela->dateTime('ultimo_aviso_em')->nullable();
            $tabela->timestamps();

            $tabela->unique(['kind', 'entity']);
            $tabela->index(['aberto', 'severity']);
        });

        Schema::create('monitor_eventos', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->foreignId('monitor_alerta_id')->constrained('monitor_alertas')->cascadeOnDelete();
            $tabela->unsignedBigInteger('event_id')->unique();
            $tabela->unsignedBigInteger('incident_id')->nullable();
            $tabela->string('severity', 10);
            $tabela->boolean('resolved')->default(false);
            $tabela->boolean('derived')->default(false);
            $tabela->string('title');
            $tabela->text('body')->nullable();
            // Quando aconteceu (`occurred_at`) e quando o monitor mandou
            // (`at`, que muda a cada nova tentativa).
            $tabela->dateTime('ocorreu_em');
            $tabela->dateTime('enviado_em')->nullable();
            // Falso quando chegou fora de ordem (mais velho que o estado) ou é
            // lembrete: entra no histórico, mas não mexeu no alerta.
            $tabela->boolean('aplicado')->default(true);
            $tabela->timestamps();

            $tabela->index(['monitor_alerta_id', 'ocorreu_em']);
            $tabela->index('ocorreu_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_eventos');
        Schema::dropIfExists('monitor_alertas');

        if (Schema::hasColumn('sistemas', 'monitor_token_hash')) {
            Schema::table('sistemas', function (Blueprint $tabela) {
                $tabela->dropColumn('monitor_token_hash');
            });
        }
    }
};
