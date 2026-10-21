<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * O Pedro estagiário (docs/specs/suporte-pedro-whatsapp/estagiario.md; Felipe,
     * 20/10/2026): o conhecimento do grupo (dos sites, lidos em 20/10, e dos
     * contatos que o Felipe passou) e os roteiros de diagnóstico guiado.
     *
     * Não passa por cima de ninguém: ajuste só é gravado se estiver vazio; roteiro
     * existente só é atualizado se ninguém o editou pela tela (`atualizado_por`
     * nulo); roteiro novo só nasce se não houver outro com o mesmo título. Os
     * passos são genéricos e seguros — a equipe técnica revisa na tela.
     */
    public function up(): void
    {
        $agora = now();
        $ajustes = [
            'apresentacao' => "Somos o Grupo Soluções — três empresas, uma operação: engenharia, manutenção e tecnologia integradas para indústrias, condomínios e empresas, com equipes próprias e responsabilidade técnica formal. Sede: Rua Pedro Palácios, 79, Centro, Vila Velha/ES. Contratos com SLA têm atendimento emergencial 24/7.\n"
                ."- GW Soluções: engenharia elétrica (instalações, quadros, laudos, termografia) e climatização (ar-condicionado split, multi-split e VRF, refrigeração industrial, câmaras frias).\n"
                ."- Delta Soluções: construção civil, reformas, manutenção predial, serralheria (portões, grades, estruturas metálicas), pintura e fachadas, drywall e forros.\n"
                ."- Invest Soluções: segurança eletrônica e TI — CFTV, alarmes, controle de acesso (catracas, facial, biometria, portaria), cabeamento, redes e Wi-Fi.\n"
                .'- Alfa Soluções: tecnologia — os sistemas AlfaControl, AlfaGym, AlfaGestor e AlfaHome.',
            'site' => "Grupo Soluções — https://solucoesgrupo.com/\nGW Soluções (elétrica e climatização) — https://gw.solucoesgrupo.com/\nDelta Soluções (construção e manutenção predial) — https://delta.solucoesgrupo.com/\nInvest Soluções (segurança eletrônica e TI) — https://invest.solucoesgrupo.com/",
            'contatos' => "PABX e suporte técnico — (27) 4042-4157 (este número)\nFinanceiro — (27) 99652-6457\nComercial — (27) 99527-3269\nPlantão — (27) 99244-6889 (fins de semana e das 18:00 às 08:00 do dia seguinte)",
            'portal' => 'https://gestor.alfa.solucoesgrupo.com/login — os boletos ficam em Financeiro › Boletos',
        ];
        foreach ($ajustes as $chave => $valor) {
            $atual = DB::table('suporte_ajustes')->where('chave', $chave)->value('valor');
            if ($atual === null || trim((string) $atual) === '') {
                DB::table('suporte_ajustes')->updateOrInsert(['chave' => $chave], ['valor' => $valor, 'created_at' => $agora, 'updated_at' => $agora]);
            }
        }

        foreach ($this->roteiros() as $ordem => $r) {
            $existente = DB::table('suporte_roteiros')->where('titulo', $r['titulo'])->first();
            $dados = $r + ['ordem' => $ordem + 1, 'ativo' => true, 'updated_at' => $agora];
            if (! $existente) {
                DB::table('suporte_roteiros')->insert($dados + ['created_at' => $agora]);
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
            ['titulo' => 'Portão parado', 'prioridade' => 'critica', 'prazo_horas' => 3, 'abre_atendimento' => true,
                'quando' => 'Portão (de pedestre ou de garagem) que não abre, não fecha ou travou.',
                'orientacao' => "É urgente: no máximo dois passos antes de abrir o atendimento.\n1. Pergunte qual portão e se ele está parado no meio do curso ou fechado/aberto.\n2. Peça para testar por outro meio: outro controle ou o botão da portaria. Se abrir por outro meio, o problema é o controle (veja o roteiro do controle).\n3. Peça uma foto ou vídeo curto do portão e, se houver, da luz da central do motor.\nNão oriente destravar, empurrar ou forçar o portão. Abra o atendimento crítico.",
                'mensagem' => 'Entendi, portão parado é prioridade para nós: a equipe técnica tem até 3 horas para atender.'],
            ['titulo' => 'Controle do portão não funciona', 'prioridade' => 'baixa', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Só o controle de uma pessoa não abre o portão; pelos outros meios ele abre.',
                'orientacao' => "1. Peça para testar outro controle ou o botão da portaria: se o portão abre, o motor está bom.\n2. Peça para trocar a bateria do controle (geralmente uma bateria pequena, tipo moeda ou 12 V).\n3. Peça para chegar mais perto do portão ao apertar.\nSe ainda não funcionar, abra atendimento: pode ser preciso recadastrar o controle.",
                'mensagem' => null],
            ['titulo' => 'Catraca desligada ou sem comunicação', 'prioridade' => 'alta', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Catraca apagada, sem luz no visor, não libera a passagem, ou aparece offline no sistema (AlfaControl, AlfaGym).',
                'orientacao' => "1. Pergunte se o visor e as luzes da catraca estão acesos.\n2. Apagada: peça para conferir se o cabo de energia está na tomada e se a tomada tem energia (ligar outro aparelho nela).\n3. Se houver disjuntor identificado da catraca, acessível e desarmado: religar uma vez só.\n4. Peça para desligar a catraca da tomada, esperar 30 segundos e ligar de novo.\n5. Acesa mas offline no sistema: peça para ver se o cabo de rede está conectado e se a internet do local está funcionando.\n6. Peça uma foto do visor e das luzes.\nSe o disjuntor desarmar de novo, ou houver cheiro de queimado, pare e abra atendimento urgente. Pessoa presa no giro: atendimento urgente na hora.",
                'mensagem' => null],
            ['titulo' => 'Ar-condicionado não liga', 'prioridade' => 'media', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Ar-condicionado que não liga pelo controle ou não responde.',
                'orientacao' => "1. Peça para trocar as pilhas do controle (ou testar: com a câmera do celular apontada para a ponta do controle, ao apertar um botão deve aparecer uma luz).\n2. Confira com ele o modo e a temperatura no controle: modo refrigerar e temperatura abaixo da do ambiente.\n3. Se o aparelho é de tomada, confirme que está ligado nela.\n4. Se houver disjuntor identificado do ar, acessível e desarmado: religar uma vez só.\n5. Depois de ligar, peça para esperar 3 minutos: o aparelho tem uma proteção que atrasa a partida.\n6. Luz piscando no painel do aparelho: peça uma foto do painel.\nCheiro de queimado, barulho forte ou disjuntor que desarma de novo: pare, oriente desligar e abra atendimento urgente.",
                'mensagem' => null],
            ['titulo' => 'Ar-condicionado não gela ou está pingando', 'prioridade' => 'media', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Ar ligado mas não gela, gela pouco, ou está pingando ou vazando água.',
                'orientacao' => "1. Confira o modo refrigerar e a temperatura no controle.\n2. Pergunte se portas e janelas estão fechadas e há quanto tempo foi a última limpeza ou manutenção.\n3. Pingando ou vazando: oriente desligar o aparelho para não danificar o ambiente e afastar o que estiver embaixo.\n4. Peça uma foto do aparelho e do ponto onde a água aparece.\nNão oriente abrir o aparelho. Abra atendimento de manutenção com o que foi informado.",
                'mensagem' => null],
            ['titulo' => 'Câmera sem imagem', 'prioridade' => 'media', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Câmera sem imagem no monitor, no gravador (DVR/NVR) ou no aplicativo do celular.',
                'orientacao' => "1. Pergunte se é uma câmera ou todas.\n2. Todas: confira se o gravador está com as luzes acesas e se o monitor está ligado.\n3. Peça para desligar o gravador da tomada, esperar 30 segundos, ligar e aguardar 2 minutos.\n4. Só no aplicativo do celular: confira se a internet do local está funcionando.\n5. Peça uma foto da tela do monitor ou do aplicativo.\nSe continuar sem imagem, abra atendimento informando quais câmeras.",
                'mensagem' => null],
            ['titulo' => 'Interfone não funciona', 'prioridade' => 'media', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Interfone mudo, sem chamar, sem áudio ou sem abrir o portão.',
                'orientacao' => "1. Pergunte se é um apartamento ou ramal, ou todos.\n2. Um ramal: peça para conferir se o fone está bem encaixado no gancho e o fio conectado.\n3. Todos: pergunte se a central do interfone está com luz acesa.\n4. Peça uma foto da central ou do aparelho.\nAbra atendimento informando quais ramais.",
                'mensagem' => null],
            ['titulo' => 'Alarme disparando', 'prioridade' => 'alta', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Alarme tocando, disparando sozinho ou que não desarma.',
                'orientacao' => "SEGURANÇA PRIMEIRO: se houver suspeita de invasão, ninguém deve ir verificar sozinho; oriente ligar 190.\n1. Peça para desarmar com a senha no teclado.\n2. Peça uma foto do teclado: ele costuma mostrar a zona que disparou.\n3. Pergunte se há porta ou janela aberta, ou animal no ambiente monitorado.\nNão desarmou, ou continua disparando: atendimento urgente (fora do horário, informe o plantão).",
                'mensagem' => null],
            ['titulo' => 'Internet ou Wi-Fi fora', 'prioridade' => 'media', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Sem internet, Wi-Fi fora ou lento, equipamentos sem rede.',
                'orientacao' => "1. Pergunte se é um aparelho ou todos.\n2. Todos: peça para conferir as luzes do modem e do roteador.\n3. Peça para desligar o modem e o roteador da tomada, esperar 30 segundos, ligar e aguardar 2 minutos.\n4. Ainda sem internet com o modem ligado: pode ser a operadora; sugira confirmar com ela.\n5. Peça uma foto das luzes do modem e do roteador.\nRede montada por nós (cabeamento, Wi-Fi corporativo) ainda fora: abra atendimento.",
                'mensagem' => null],
            ['titulo' => 'Falta de energia ou disjuntor desarmando', 'prioridade' => 'alta', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Falta de energia no local, em parte dele, ou disjuntor que desarma.',
                'orientacao' => "1. Pergunte se falta energia só no local ou também na rua e nos vizinhos. Na rua toda, é com a concessionária de energia.\n2. Disjuntor identificado, acessível e desarmado: religar uma vez só.\n3. Desarmou de novo: não insistir; abra atendimento.\nCheiro de queimado, faísca ou fumaça: oriente desligar o disjuntor geral SE for seguro e acessível, afastar as pessoas, e abra atendimento urgente (fora do horário, informe o plantão). Não oriente abrir quadro nem mexer em fiação.",
                'mensagem' => null],
            ['titulo' => 'Boleto / segunda via', 'prioridade' => null, 'prazo_horas' => null, 'abre_atendimento' => false,
                'quando' => 'Cliente pede boleto, segunda via ou onde pagar.',
                'orientacao' => "Para cliente do Gestor (manutenção e segurança eletrônica): indique o portal do cliente. Informe o e-mail de login SÓ se ele aparecer em CLIENTE como \"Login do portal\"; senão, oriente o \"Esqueci minha senha\".\nOutros assuntos financeiros (negociação, valores, nota): informe o contato do Financeiro.",
                'mensagem' => 'Você baixa o boleto no portal do cliente: https://gestor.alfa.solucoesgrupo.com/login — entre com o seu e-mail e senha e vá em Financeiro › Boletos. Se não lembrar a senha, use "Esqueci minha senha" na tela de entrada.'],
            ['titulo' => 'Comercial', 'prioridade' => 'media', 'prazo_horas' => null, 'abre_atendimento' => true,
                'quando' => 'Quem não é cliente e quer conhecer, contratar ou pedir orçamento.',
                'orientacao' => 'Apresente o Grupo Soluções e a empresa que atende o assunto (A EMPRESA), com o site dela. Pergunte o interesse, o nome e o local, informe o contato do Comercial e encaminhe ao setor comercial.',
                'mensagem' => null],
        ];
    }
};
