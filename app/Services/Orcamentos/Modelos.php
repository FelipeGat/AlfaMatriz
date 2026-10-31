<?php

namespace App\Services\Orcamentos;

/**
 * O ponto de partida de cada produto (docs/specs/orcamentos-vixcard): escolher o
 * produto preenche capa, escopo, passos e itens — tudo editável no orçamento.
 *
 * Textos tirados do que cada sistema FAZ hoje (análise do código de cada
 * repositório, 19/10/2026 — docs/specs/orcamentos-vixcard/modelos-por-sistema.md)
 * e das propostas já enviadas. Todo item nasce em R$ 0: os valores são tratados
 * caso a caso (Felipe, 19/10/2026), e valor de modelo vira valor enviado.
 * A tela de modelos (editar estes textos sem código) é a próxima etapa.
 */
class Modelos
{
    public const PRODUTOS = [
        /* Produtos da VixCard: saíram em 31/10/2026, quando o Matriz ficou só com a Alfa e o
         * Grupo Soluções. Os textos ficam aqui comentados.
        // VixCard — comanda digital por cartão NFC (antes Vix-Beer; ADR 0003 do nex-beer).
        // NÃO é chope self-service: quem lê o cartão é o atendente, no celular Android.
        'nexbeer' => [
            'nome' => 'NexBeer',
            'titulo' => 'Comanda digital',
            'destaque' => 'NexBeer',
            'lead' => 'O cartão é a comanda: o cliente consome a festa toda por aproximação e acerta na saída, sem ficha de papel.',
            'inclui' => "Licença do sistema por estabelecimento (CNPJ)\nPortaria, balcão e caixa no celular Android, sem instalar app\nCartões em aberto e troca de cartão perdido sem perder o consumo\nFechamento de caixa por operador e relatório do evento\nUsuários e perfis de acesso\nTreinamento da equipe e suporte",
            'nao_inclui' => "Processamento de pagamento e maquininha\nEmissão fiscal (NFC-e)\nCelulares Android, impressora e internet\nPré-pago/créditos e crediário",
            'passos' => "Aprovação desta proposta\nCadastro do estabelecimento, produtos e preços\nProdução e entrega do lote de cartões NFC\nTeste nos celulares e treinamento da equipe\nPrimeiro evento acompanhado",
            'itens' => [
                ['Licença NexBeer', 1, 'CNPJ', 0, 'mensal'],
                ['Cartão NFC personalizado', 500, 'un', 0, 'unico'],
            ],
        ],
        // VixCard — inventário e controle por RFID UHF (vix_id_patrimonio / vix_id_planta: mesmo produto).
        'vix-id' => [
            'nome' => 'Vix-ID',
            'titulo' => 'Inventário e controle por RFID',
            'destaque' => 'Vix-ID',
            'lead' => 'Uma etiqueta RFID em cada peça, o coletor na mão e as antenas no portão: ninguém mais conta peça por peça.',
            'inclui' => "Sistema Vix-ID (painel e app do coletor), hospedagem, atualizações, backup e suporte\nImplantação assistida e carga da base\nCalibração dos perfis de leitura\nTreinamento da equipe\nEquipamentos e lote de etiquetas listados no investimento",
            'nao_inclui' => "Obra civil, escavação e pontos novos de energia ou rede\nComputador do portão e internet\nReposição de etiquetas\nIntegração de escrita com o ERP (orçada à parte)",
            'passos' => "Aprovação desta proposta\nVisita técnica\nCarga da base (planilha, localizações e usuários)\nGravação e colagem das etiquetas\nInstalação e calibração do portal de saída\nPrimeiro inventário assistido e treinamento",
            'itens' => [
                ['Implantação e treinamento', 1, 'serv', 0, 'unico'],
                ['Coletor RFID Chainway C72', 1, 'un', 0, 'unico'],
                ['Portal RFID de saída (antena Control iD iDUHF)', 1, 'un', 0, 'unico'],
                ['Etiquetas RFID UHF', 1000, 'un', 0, 'unico'],
                ['Mensalidade Vix-ID (hospedagem, atualizações, backup e suporte)', 1, 'mês', 0, 'mensal'],
            ],
        ],
        'cartoes-nfc' => [
            'nome' => 'Cartões NFC',
            'titulo' => 'Cartões inteligentes',
            'destaque' => 'NFC',
            'lead' => 'Cartões com chip NFC personalizados com a sua marca, levando ao seu perfil digital com um toque.',
            'inclui' => "Arte e prova digital\nCartões NFC NTAG213 impressos\nGravação do link em cada cartão",
            'nao_inclui' => 'Frete fora da Grande Vitória',
            'passos' => "Aprovação e envio da arte\nProva digital\nProdução\nEntrega",
            'itens' => [
                ['Cartão NFC NTAG213 personalizado', 100, 'un', 0, 'unico'],
            ],
        ],
        'vixlink' => [
            'nome' => 'VixLink',
            'titulo' => 'Perfil digital',
            'destaque' => 'VixLink',
            'lead' => 'O perfil digital da equipe e da empresa, aberto por NFC ou QR Code e atualizado a qualquer momento.',
            'inclui' => "Configuração dos perfis\nCartões NFC com QR Code\nPainel para editar os perfis",
            'nao_inclui' => 'Fotografia profissional',
            'passos' => "Aprovação\nEnvio dos dados da equipe\nAtivação dos perfis\nEntrega dos cartões",
            'itens' => [
                ['Ativação de perfil VixLink', 1, 'un', 0, 'unico'],
                ['Cartão NFC VixLink', 1, 'un', 0, 'unico'],
                ['Landing page', 1, 'un', 0, 'unico'],
            ],
        ],
        */
        // ERP do Grupo Soluções, vendido a terceiros como "Gestor VixSoftHouse" (proposta Gustavo, 19/10/2026).
        'gestor' => [
            'nome' => 'Gestor',
            'titulo' => 'ERP completo',
            'destaque' => 'Gestor',
            'lead' => 'Um sistema só, que conhece cada uma das suas empresas: do funil ao orçamento, do orçamento à nota, da nota à cobrança, e que chega em campo pelo app.',
            'inclui' => "Licença por CNPJ com os módulos contratados\nHospedagem, atualizações e backup\nImplantação e treinamento da equipe\nSuporte em horário comercial\nPDFs com a marca de cada empresa",
            'nao_inclui' => "Folha de pagamento e eSocial\nNF-e de produto\nCustos de terceiros (WhatsApp, Mercado Pago, banco, certificado A1)\nDesenvolvimento sob medida",
            'passos' => "Aprovação desta proposta\nEnvio dos dados das empresas e dos usuários\nCadastro das empresas, usuários, perfis e equipe\nAlinhamento das rotinas prioritárias\nTreinamento\nUso diário com suporte",
            'itens' => [
                ['Implantação e treinamento', 1, 'serv', 0, 'unico'],
                ['Licença por CNPJ (plano anual)', 1, 'CNPJ', 0, 'mensal'],
            ],
        ],
        // Alfa Soluções — ponto e jornada. NÃO gera AFD/AEJ: nunca escrever "conforme a Portaria 671".
        'alfajornada' => [
            'nome' => 'AlfaJornada',
            'titulo' => 'Ponto e jornada',
            'destaque' => 'AlfaJornada',
            'lead' => 'Feche o mês sem planilha e sem susto: ponto pelo celular, totem ou relógio, horas extras e faltas apuradas e exportação pronta para a folha.',
            'inclui' => "Licença por colaborador ativo, hospedagem e backup\nApp do colaborador com GPS e modo totem em tablet\nAlfaSync para relógio ControlID\nBanco de horas, espelho de ponto e fechamento do mês\nExportação para a folha (CSV)\nImplantação assistida e treinamento do DP",
            'nao_inclui' => "Relógio de ponto, tablet, computador e internet\nArquivos fiscais (AFD, AEJ) e eSocial\nIntegração direta com o sistema de folha\nFérias, EPI e ASO",
            'passos' => "Aprovação desta proposta\nQuestionário de implantação\nCadastro das empresas, jornadas e colaboradores\nInstalação do AlfaSync ou configuração dos totens\nTreinamento do DP\nPrimeiro fechamento acompanhado",
            'itens' => [
                ['Implantação e treinamento', 1, 'serv', 0, 'unico'],
                ['Licença AlfaJornada por colaborador ativo', 1, 'colab.', 0, 'mensal'],
            ],
        ],
        // Alfa Soluções — gestão de academias. Catracas: ControlID, Topdata e Tecnibra.
        'alfagym' => [
            'nome' => 'AlfaGym',
            'titulo' => 'Gestão de academias',
            'destaque' => 'AlfaGym',
            'lead' => 'Pagou, entra; atrasou, a catraca sabe. Alunos, planos, cobrança, treinos e catraca num sistema só.',
            'inclui' => "Sistema web e app do aluno (Android)\nAlfaSync e configuração das catracas e leitores ControlID, Topdata ou Tecnibra\nMigração dos alunos (planilha do i-Fitness)\nTreinamento da equipe\nSuporte pelo WhatsApp, backup e atualizações",
            'nao_inclui' => "Catracas, leitores e computador da recepção\nInternet e cabeamento\nTaxas de maquininha e de cobrança\nApp para iPhone",
            'passos' => "Aprovação desta proposta\nCadastro da academia, unidades e planos\nMigração dos alunos\nInstalação do AlfaSync e dos equipamentos\nSincronização das fotos e treinamento da equipe\nInício da operação",
            'itens' => [
                ['Implantação e treinamento', 1, 'serv', 0, 'unico'],
                ['Licença AlfaGym por academia', 1, 'mês', 0, 'mensal'],
            ],
        ],
        // Alfa Soluções — controle de acesso (condomínio, empresa, escola, clube). Control iD e Topdata.
        'alfacontrol' => [
            'nome' => 'AlfaControl',
            'titulo' => 'Controle de acesso',
            'destaque' => 'AlfaControl',
            'lead' => 'A autorização dada no painel vira liberação na catraca, e o que acontece na portaria vira relatório.',
            'inclui' => "Licença mensal da plataforma\nAlfaSync e app do morador ou responsável\nImplantação, cadastro inicial e treinamento\nAtualizações, backup diário e suporte remoto em dias úteis\nLGPD: pedidos do titular e registro dos tratamentos",
            'nao_inclui' => "Equipamentos e catracas\nInstalação elétrica e cabeamento\nComputador da portaria, no-break e internet\nMódulos adicionais (financeiro, refeitório, notificações)\nSuporte presencial",
            'passos' => "Aprovação desta proposta\nLevantamento dos equipamentos e da rede\nCadastro dos locais, horários e pessoas\nInstalação do AlfaSync e teste dos leitores\nTreinamento da portaria e da gestão\nLiberação do app aos moradores",
            'itens' => [
                ['Implantação e treinamento', 1, 'serv', 0, 'unico'],
                ['Licença AlfaControl', 1, 'mês', 0, 'mensal'],
            ],
        ],
        'outro' => [
            'nome' => 'Outro',
            'titulo' => 'Proposta',
            'destaque' => 'VixSoftHouse',
            'lead' => '',
            'inclui' => '',
            'nao_inclui' => '',
            'passos' => "Aprovação desta proposta\nProdução\nEntrega",
            'itens' => [['Item', 1, 'un', 0, 'unico']],
        ],
    ];

    /**
     * O que não é sistema e por isso fica no PDF clássico (Felipe, 25/10/2026: o modelo VixHouse vale
     * "para TODOS os clientes que for sistema"). Cartão NFC é peça impressa, com a arte do cliente.
     */
    public const FORA_DO_MODELO_VIXHOUSE = ['cartoes-nfc'];

    /** O modelo de PDF com que um orçamento deste produto nasce — editável no orçamento. */
    public static function modeloDoPdf(string $chave): string
    {
        return in_array($chave, self::FORA_DO_MODELO_VIXHOUSE, true) ? 'classico' : 'vixhouse';
    }

    public static function nome(string $chave): string
    {
        return self::PRODUTOS[$chave]['nome'] ?? $chave;
    }

    /** O modelo no formato do formulário (itens como objetos). */
    public static function paraFormulario(string $chave): array
    {
        $m = self::PRODUTOS[$chave] ?? self::PRODUTOS['outro'];

        return ['produto' => $chave, 'modelo' => self::modeloDoPdf($chave)] + collect($m)->except(['nome', 'itens'])->all() + [
            'itens' => array_map(fn ($i) => ['descricao' => $i[0], 'quantidade' => $i[1], 'unidade' => $i[2], 'valor_unitario' => $i[3], 'cobranca' => $i[4]]
                + \App\Models\OrcamentoItem::classificar(['descricao' => $i[0], 'cobranca' => $i[4]]) + ['opcional' => false], $m['itens']),
        ];
    }
}
