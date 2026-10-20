<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * POP de procedimentos básicos (Felipe, 20/10/2026: "crie um pop para
     * procedimentos básicos de impressoras, computadores, catracas, etc. para
     * que fique registrado e ele tenha onde pesquisar"). Entram como roteiros do
     * Pedro, que é onde ele consulta a cada resposta e onde a equipe lê e revisa
     * (Suporte › Pedro).
     *
     * Mesma regra da 2026_10_20_150000: roteiro novo só nasce se não houver outro
     * com o mesmo título; existente só é atualizado se ninguém o editou pela tela.
     * Os passos são genéricos e seguros — a equipe técnica revisa.
     */
    public function up(): void
    {
        $agora = now();
        $depois = (int) DB::table('suporte_roteiros')->max('ordem');
        foreach ($this->roteiros() as $i => $r) {
            $existente = DB::table('suporte_roteiros')->where('titulo', $r['titulo'])->first();
            $dados = $r + ['ativo' => true, 'updated_at' => $agora];
            if (! $existente) {
                DB::table('suporte_roteiros')->insert($dados + ['ordem' => $depois + $i + 1, 'created_at' => $agora]);
            } elseif ($existente->atualizado_por === null) {
                DB::table('suporte_roteiros')->where('id', $existente->id)->update($dados);
            }
        }
    }

    public function down(): void
    {
        // Conteúdo de negócio: a equipe pode tê-lo editado. Reverter não apaga nada.
    }

    /** @return list<array{titulo: string, quando: string, orientacao: string, mensagem: ?string, prioridade: ?string, prazo_horas: ?int, abre_atendimento: bool}> */
    private function roteiros(): array
    {
        return [
            ['titulo' => 'Impressora não imprime', 'prioridade' => 'media', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Impressora que não imprime, imprime em branco ou falhado, mostra erro ou não aparece no computador.',
                'orientacao' => "1. Peça uma foto do painel da impressora (luzes e mensagem) e pergunte o modelo.\n2. Pergunte se está ligada e se há papel na bandeja; luz de papel ou papel enroscado aparente: peça para recolocar o papel ou puxar a folha com cuidado, sem forçar e sem abrir tampas internas.\n3. Peça para desligar a impressora no botão, esperar 30 segundos e ligar de novo.\n4. Se for pelo computador: peça para reiniciar o computador e tentar imprimir de novo uma página simples.\n5. Imprime falhado ou em branco: pergunte se há aviso de tinta ou toner baixo (ver no painel ou na foto).\nSe continuar, abra atendimento com o modelo, a mensagem de erro e o que já foi tentado.",
                'mensagem' => null],
            ['titulo' => 'Computador não liga', 'prioridade' => 'media', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Computador (gabinete ou notebook) que não liga, não acende nenhuma luz ou liga e desliga sozinho.',
                'orientacao' => "1. Pergunte se é notebook ou gabinete e se acende alguma luz ao apertar o botão.\n2. Peça para conferir se o cabo de energia está bem encaixado no computador e na tomada, e se a tomada tem energia (ligar outro aparelho nela). Com filtro de linha ou estabilizador, ver se estão ligados.\n3. Notebook: ligar com o carregador conectado e esperar alguns minutos.\n4. Peça para segurar o botão de ligar por 10 segundos, soltar e apertar de novo.\nNão oriente abrir o gabinete nem tirar peças. Cheiro de queimado ou estalo: desligar da tomada e abrir atendimento urgente. Se não ligar, abra atendimento.",
                'mensagem' => null],
            ['titulo' => 'Computador lento ou travando', 'prioridade' => 'baixa', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Computador muito lento, travando, com tela azul ou mensagem de erro.',
                'orientacao' => "1. Peça uma foto da tela com a mensagem de erro, se houver.\n2. Pergunte desde quando e se foi depois de alguma atualização ou programa novo.\n3. Peça para salvar o que estiver aberto e reiniciar o computador (Iniciar › Reiniciar, não só desligar a tela).\n4. Se for um programa só, fechar e abrir de novo esse programa.\nNão oriente formatar, apagar arquivos, desinstalar programas nem mexer em configurações do sistema. Se continuar, abra atendimento com a mensagem e o que já foi tentado.",
                'mensagem' => null],
            ['titulo' => 'Mouse ou teclado não funciona', 'prioridade' => 'baixa', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Mouse ou teclado (com fio ou sem fio) que não responde, falha ou responde com atraso.',
                'orientacao' => "1. Pergunte qual dos dois falha e se é com fio ou sem fio.\n2. Sem fio: peça para trocar as pilhas ou carregar, conferir se o botão de liga/desliga embaixo está ligado e tirar e recolocar o receptor USB, de preferência em outra porta USB, perto do aparelho.\n3. Com fio: tirar e recolocar o cabo, em outra porta USB.\n4. Peça para reiniciar o computador.\nSe continuar, abra atendimento: pode ser troca do aparelho.",
                'mensagem' => null],
            ['titulo' => 'Monitor sem imagem', 'prioridade' => 'media', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Monitor ou TV do computador sem imagem, com "sem sinal" ou piscando, com o computador ligado.',
                'orientacao' => "1. Pergunte se a luz do monitor acende e se aparece alguma mensagem (ex.: \"sem sinal\"). Peça uma foto da tela.\n2. Peça para conferir se o monitor está ligado no botão e se o cabo de energia está encaixado.\n3. Peça para tirar e recolocar o cabo de vídeo (HDMI ou VGA) nas duas pontas, monitor e computador.\n4. Se o monitor tem botão de entrada (Input/Source), escolher a entrada certa.\n5. Reiniciar o computador.\nSe continuar, abra atendimento.",
                'mensagem' => null],
            ['titulo' => 'Catraca não reconhece face ou digital', 'prioridade' => 'alta', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Catraca ou leitor facial/biométrico acende mas não reconhece a pessoa, não lê a digital ou mostra "não cadastrado".',
                'orientacao' => "1. Pergunte se o problema é com todas as pessoas ou com uma só.\n2. Uma pessoa só: confirmar no sistema (AlfaControl, AlfaGym) se ela está ativa e liberada no horário; se não estiver, é cadastro.\n3. Facial: pedir para olhar de frente, sem boné, óculos escuros ou máscara, na distância indicada no visor, com o rosto iluminado.\n4. Digital: limpar o leitor com pano seco e o dedo, encostar o dedo inteiro sem apertar demais.\n5. Peça uma foto do visor com a mensagem que aparece.\nSe for com todas as pessoas ou continuar, abra atendimento: pode ser preciso recadastrar ou ajustar o equipamento. Para quem está esperando, a portaria libera pelo modo de sempre.",
                'mensagem' => null],
            ['titulo' => 'Nobreak apitando ou desligado', 'prioridade' => 'media', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Nobreak apitando, com luz vermelha ou que desligou os equipamentos ligados nele.',
                'orientacao' => "1. Pergunte se está faltando energia no local: nobreak apitando com falta de energia é normal, ele está segurando os equipamentos pela bateria.\n2. Com energia normal: peça para conferir se o cabo do nobreak está na tomada e se a tomada tem energia.\n3. Peça uma foto do painel e das luzes.\n4. Se houver muitos aparelhos ligados nele (aquecedor, impressora a laser), pedir para desligar os que não são essenciais.\nNão oriente abrir o nobreak nem trocar a bateria. Cheiro de queimado ou bateria estufada: desligar da tomada e abrir atendimento urgente. Se continuar apitando com energia normal, abra atendimento.",
                'mensagem' => null],
            ['titulo' => 'Celular ou computador não conecta no Wi-Fi', 'prioridade' => 'baixa', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Um aparelho só não conecta no Wi-Fi, enquanto os outros navegam normalmente.',
                'orientacao' => "1. Confirme que os outros aparelhos estão navegando; se nenhum navega, siga o roteiro de internet ou Wi-Fi fora.\n2. Peça para desligar e ligar o Wi-Fi do aparelho.\n3. Peça para esquecer a rede e conectar de novo com a senha.\n4. Reiniciar o aparelho.\nNão passe senha de rede. Se continuar, abra atendimento.",
                'mensagem' => null],
        ];
    }
};
