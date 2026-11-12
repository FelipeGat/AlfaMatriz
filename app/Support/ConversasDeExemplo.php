<?php

namespace App\Support;

/**
 * Os dados FIXOS do protótipo da tela Conversas (T-602).
 *
 * Nada aqui vem do banco, de propósito: o protótipo existe para o dono do
 * produto decidir se o chat interno vale a pena, e não pode deixar rastro
 * (tabela, migração, mensagem gravada) se a resposta for não.
 *
 * As conversas das tarefas são cópias de conversas REAIS do quadro (T-554 e
 * T-556, lidas em 12/11/2026), para a tela ser julgada com o volume e o tom
 * do dia a dia — texto de enchimento esconde o problema de mensagem longa.
 * O Geral e as diretas são inventados, mas no mesmo assunto, para mostrar o
 * caminho "falou no Geral → levou para a tarefa".
 */
class ConversasDeExemplo
{
    public static function todas(): array
    {
        return [
            [
                'id' => 'geral',
                'tipo' => 'geral',
                'nome' => 'Geral',
                'descricao' => 'Todo o time',
                'nao_lidas' => 2,
                'mensagens' => [
                    self::msg(1, 'Felipe Henrique', '12/11 08:52', 'Bom dia! Hoje à tarde publico a versão nova do AlfaGym, aviso aqui quando subir.'),
                    self::msg(2, 'Rossini Santos', '12/11 09:03', 'Combinado, Felipe.'),
                    self::msg(3, 'Alexandre Blank', '12/11 10:40', "Falei com o Weliton da Apoena agora: o PC do AlfaSync estava com \"Suspender\" em 30 minutos na tomada. Mudei para Nunca.\nA placa de rede também estava com \"Permitir que o computador desligue este dispositivo\" marcado; desmarquei."),
                    self::msg(4, 'Alexandre Blank', '12/11 10:44', 'Na TAJ ainda não consegui atualizar o AlfaSync, passo lá amanhã cedo.'),
                ],
            ],
            [
                'id' => 'direta-alexandre',
                'tipo' => 'direta',
                'nome' => 'Alexandre Blank',
                'descricao' => 'Conversa direta',
                'nao_lidas' => 1,
                'mensagens' => [
                    self::msg(1, 'Rossini Santos', '11/11 17:20', 'Alexandre, consegue ir na Apoena esta semana?'),
                    self::msg(2, 'Alexandre Blank', '11/11 17:31', 'Consigo na quinta de manhã.'),
                ],
            ],
            [
                'id' => 'direta-felipe',
                'tipo' => 'direta',
                'nome' => 'Felipe Henrique',
                'descricao' => 'Conversa direta',
                'nao_lidas' => 0,
                'mensagens' => [
                    self::msg(1, 'Felipe Henrique', '10/11 14:05', 'Rossini, olhei o PR que você mandou; deixei dois comentários.'),
                    self::msg(2, 'Rossini Santos', '10/11 14:12', 'Vi, já ajustei. Obrigado!'),
                ],
            ],
            [
                'id' => 'tarefa-554',
                'tipo' => 'tarefa',
                'nome' => 'T-554 · Instabilidade no Alfasync apoena',
                'descricao' => 'AlfaControl · Em andamento',
                'nao_lidas' => 0,
                'tarefa' => [
                    'codigo' => 'T-554',
                    'titulo' => 'Instabilidade no Alfasync apoena',
                    'etapa' => 'Em andamento',
                    'sistema' => 'AlfaControl',
                    'tipo' => 'Bug',
                    'prioridade' => 'Alta',
                    'responsavel' => 'Rossini Santos',
                    'pergunta_para' => 'Alexandre Blank',
                ],
                'mensagens' => [
                    self::msg(1, 'Rossini Santos', '30/10 09:26', "Analisei os dados da Apoena no servidor (horários de Brasília).\n\nO que acontece todo dia desde 19/10:\n- O AlfaSync funciona normalmente da hora em que alguém reinicia o computador (entre 11h e 16h) até a última aula, por volta das 21h50.\n- À noite ele para de falar com o servidor e também com os dois leitores faciais.\n- Por volta das 06h00 da manhã a primeira passagem do dia chega na hora. Logo depois ele fica mudo de novo, a manhã inteira, até alguém mexer no computador.\n- Os acessos não se perdem: o próprio leitor guarda as passagens e as manda todas de uma vez quando volta a se comunicar.\n\nMinha hipótese principal: o computador do AlfaSync entra em suspensão (economia de energia) quando fica sem uso."),
                    self::msg(2, 'Rossini Santos', '30/10 09:26', "Alexandre, pelo que vi o computador do AlfaSync da Apoena parece entrar em suspensão quando fica sem uso (detalhes no comentário acima). Consegue conferir com o Weliton, por favor?\n1) Em Configurações > Sistema > Energia, como estão \"Suspender\" e \"Desligar a tela\" (na tomada)? Se estiver suspendendo, coloque \"Nunca\".\n2) No Gerenciador de Dispositivos, na placa de rede, aba Gerenciamento de Energia: \"Permitir que o computador desligue este dispositivo\" está marcado?\n3) Me mande o arquivo agent.log da pasta do AlfaSync (todos os agent.log*), sem reiniciar antes.", pergunta: true),
                    self::msg(3, 'Rossini Santos', '30/10 09:31', 'Parei aqui à espera da resposta do Alexandre: configuração de energia do PC da Apoena, arquivos agent.log, powercfg /lastwake e /requests. Ainda não mexi no código.'),
                    self::msg(4, 'Rossini Santos', '09/11 22:03', 'Alexandre, ainda aguardo sua resposta sobre o PC do AlfaSync da Apoena: como estão "Suspender" e "Desligar a tela" em Energia, a opção de energia da placa de rede, e os arquivos agent.log? Sem isso não consigo confirmar a suspensão. Pode falar com o Weliton, por favor?', pergunta: true),
                ],
            ],
            [
                'id' => 'tarefa-556',
                'tipo' => 'tarefa',
                'nome' => 'T-556 · AlfaSync da TAJ reenvia sem parar acessos que o servidor já tem',
                'descricao' => 'AlfaControl · Em revisão',
                'nao_lidas' => 0,
                'tarefa' => [
                    'codigo' => 'T-556',
                    'titulo' => 'AlfaSync da TAJ reenvia sem parar acessos que o servidor já tem',
                    'etapa' => 'Em revisão',
                    'sistema' => 'AlfaControl',
                    'tipo' => 'Bug',
                    'prioridade' => 'Alta',
                    'responsavel' => 'Rossini Santos',
                    'pergunta_para' => 'Alexandre Blank',
                ],
                'mensagens' => [
                    self::msg(1, 'Rossini Santos', '30/10 10:45', 'Publiquei o AlfaSync 1.5.125 com a correção. O pacote para Windows saiu às 10:37 e o arquivo de atualização já oferece a 1.5.125. O AlfaSync da TAJ ainda está na 1.5.110 e provavelmente precisa de atualização manual no PC da TAJ.'),
                    self::msg(2, 'Rossini Santos', '30/10 10:47', 'Alexandre, atualize o AlfaSync da TAJ para a versão 1.5.125, por favor. Ela já está disponível para download. Hoje ele está na 1.5.110 e não se atualiza sozinho desde julho. Depois de atualizar, deixe o PC ligado por cerca de 1 hora para ele terminar de enviar o que está acumulado. Quando terminar, me avise aqui, por favor.', pergunta: true),
                    self::msg(3, 'Rossini Santos', '30/10 15:15', 'Conferi hoje às 15:15: o AlfaSync da TAJ continua na 1.5.110 e segue reenviando acessos já recebidos, foram 12.101 repetidos nos últimos 10 min. A atualização ainda não foi feita no PC da TAJ.'),
                    self::msg(4, 'Rossini Santos', '09/11 22:03', 'Alexandre, o AlfaSync da TAJ já foi atualizado para a 1.5.125? Está parado há 10 dias. Se não foi, atualize no PC da TAJ (é manual) e me avise aqui, por favor.', pergunta: true),
                    self::msg(5, 'Rossini Santos', '09/11 22:39', 'Conferi agora (09/11 22:40): o AlfaSync da TAJ continua na 1.5.110, mas o reenvio de acessos repetidos parou. Os leitores Saída 4, Entrada 5 e Saída 6 voltaram a registrar acessos novos. O pedido de atualização continua valendo.'),
                ],
            ],
        ];
    }

    /**
     * A mensagem que "chega" durante a demonstração, para mostrar o aviso de
     * mensagem nova. É inventada: na T-554 real o Alexandre ainda não respondeu.
     */
    public static function chegada(): array
    {
        return [
            'conversa' => 'tarefa-554',
            'mensagem' => self::msg(90, 'Alexandre Blank', 'agora', 'Rossini, o Weliton mandou os agent.log e o resultado do powercfg. Anexei aqui.', anexo: 'agent-log-apoena.zip'),
        ];
    }

    private static function msg(int $id, string $autor, string $em, string $texto, bool $pergunta = false, ?string $anexo = null): array
    {
        return compact('id', 'autor', 'em', 'texto', 'pergunta', 'anexo') + ['levada_de' => null];
    }
}
