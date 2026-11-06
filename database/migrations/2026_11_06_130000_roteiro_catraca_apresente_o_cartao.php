<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Felipe, 06/11/2026: "informar que é só abrir o sincronizador; registre isso para ele saber fazer para os
     * próximos". Um cliente ficou três manhãs com as catracas paradas ouvindo do Pedro que o caso seguia com a
     * equipe, e a resposta era abrir o AlfaSync. Sem este roteiro, valia o "Catraca desligada ou sem
     * comunicação", que manda olhar tomada e cabo de rede, quando o problema está no computador.
     *
     * Vem antes daquele porque o sintoma é o mesmo "não libera"; este é o que pede o visor aceso com a mensagem.
     * "Apresente o cartão" é o texto que o próprio AlfaSync manda ao visor (TopInnerBridge): fica na tela depois de
     * ele parar, e a catraca sem ele nega todo mundo. Os passos da tela vêm da documentação do AlfaSync; o que
     * responder sobre "todas as manhãs" é a resposta do Felipe; o motivo de voltar a parar, de chamados da equipe.
     *
     * Mesma regra da 2026_10_20_170000: só nasce se não houver outro com o mesmo título, e o que estiver lá
     * (editado pela tela) não é tocado.
     */
    private const TITULO = 'Catraca em "Apresente o cartão" que não libera (AlfaSync)';

    public function up(): void
    {
        if (DB::table('suporte_roteiros')->where('titulo', self::TITULO)->exists()) {
            return;
        }
        $vizinho = DB::table('suporte_roteiros')->where('titulo', 'Catraca desligada ou sem comunicação')->value('ordem');
        DB::table('suporte_roteiros')->insert([
            'titulo' => self::TITULO,
            'quando' => 'Catraca acesa, com o visor em "Apresente o cartão", e o cartão ou o rosto não libera a entrada. Vale também para quem pergunta se precisa abrir o AlfaSync todos os dias. Siga este roteiro antes dos outros de catraca.',
            'orientacao' => "1. Diga que normalmente é só abrir o sincronizador. Oriente abrir o AlfaSync, o programa do computador que liga as catracas ao sistema: no canto inferior direito da tela, ao lado do relógio, clique com o botão direito no ícone dele e escolha \"Abrir AlfaSync\". Se ele aparecer parado, clique em \"Iniciar\". Sem o ícone, abra o programa AlfaSync no computador.\n"
                ."2. Peça para esperar até 2 minutos e testar o cartão de novo: a catraca leva cerca de 1 minuto para voltar depois que ele abre.\n"
                ."3. Se a equipe já mostrou esse caminho a esta pessoa (veja ATENDIMENTOS e MENSAGENS), lembre que é o mesmo procedimento, sem ensinar tudo de novo.\n"
                ."4. Voltou a liberar: está resolvido. Não abra atendimento nem acrescente nada ao que já existe por causa disso.\n"
                ."5. Se perguntarem se é preciso fazer isso todas as manhãs: o ideal é não acontecer. Provavelmente o computador está sendo desligado ao ir embora, e o AlfaSync precisa estar aberto para a catraca funcionar. Recomende criar um lembrete para, sempre que ligar o computador, conferir se o sincronizador com as catracas está ligado.\n"
                .'6. Abra atendimento, dizendo no resumo o que já foi tentado, se: o AlfaSync não abrir ou não existir no computador; aparecer erro ou aviso de licença (peça uma foto da tela); a catraca continuar sem liberar depois dos 2 minutos com ele aberto; ou o problema voltar sempre, mesmo com o lembrete. Neste último caso, peça à equipe no resumo para conferir a opção "Iniciar com o Windows" e o antivírus. Não oriente o cliente a mexer nessas configurações.',
            'mensagem' => null,
            'prioridade' => 'alta',
            'prazo_horas' => null,
            'abre_atendimento' => false,
            'ativo' => true,
            'ordem' => $vizinho !== null ? (int) $vizinho - 1 : (int) DB::table('suporte_roteiros')->max('ordem') + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Conteúdo de negócio: a equipe pode tê-lo editado. Reverter não apaga nada.
    }
};
