<?php

namespace Tests\Feature\Integracao;

use App\Services\Integracao\Documento;
use App\Services\Integracao\Dto\ClienteExterno;
use App\Services\Integracao\Dto\ContadoresExternos;
use App\Services\Integracao\Dto\FaturaExterna;
use App\Services\Integracao\Dto\LicencaExterna;
use App\Services\Integracao\Dto\UsuarioExterno;
use App\Services\Integracao\ErroIntegracao;
use App\Services\Integracao\RespostaIntegracao;
use Tests\TestCase;

class ContratoEmCodigoTest extends TestCase
{
    /**
     * @spec:AC-078 A matriz recusa uma resposta cuja versão de contrato ela não
     * entende, em vez de gravar retrato torto. Retrato torto é pior que
     * retrato ausente: ele parece confiável.
     */
    public function test_versao_de_contrato_desconhecida_e_recusada(): void
    {
        $erro = null;

        try {
            RespostaIntegracao::deArray([
                'contrato' => '2.0',
                'sistema' => 'alfagym',
                'dados' => [['id_externo' => '1']],
            ], majorEsperado: 1);
        } catch (ErroIntegracao $capturado) {
            $erro = $capturado;
        }

        $this->assertNotNull($erro, 'A resposta precisa ser recusada.');
        $this->assertSame('contrato_incompativel', $erro->codigo);
        $this->assertStringContainsString('2.0', $erro->getMessage());
    }

    /**
     * @spec:AC-078 Diferença de versão MENOR é compatível: campo novo do outro
     * lado não pode travar a integração, senão cada acréscimo inofensivo
     * derruba todos os painéis.
     */
    public function test_versao_menor_diferente_continua_compativel(): void
    {
        $resposta = RespostaIntegracao::deArray([
            'contrato' => '1.7',
            'sistema' => 'alfagym',
            'dados' => [['id_externo' => '1', 'campo_que_a_matriz_nao_conhece' => 'ok']],
        ], majorEsperado: 1);

        $this->assertCount(1, $resposta->itens());
    }

    /**
     * @spec:AC-078 Envelope sem versão ou sem dados é recusado com motivo
     * legível, não com erro de acesso a índice inexistente.
     */
    public function test_envelope_malformado_e_recusado_com_motivo(): void
    {
        foreach ([
            [['sistema' => 'alfagym', 'dados' => []], 'falta a versão'],
            [['contrato' => '1.0', 'sistema' => 'alfagym'], 'falta o campo de dados'],
            ['isto não é um objeto', 'não é um objeto'],
        ] as [$corpo, $trecho]) {
            try {
                RespostaIntegracao::deArray($corpo, majorEsperado: 1);
                $this->fail('A resposta malformada precisava ser recusada.');
            } catch (ErroIntegracao $erro) {
                $this->assertSame('resposta_invalida', $erro->codigo);
                $this->assertStringContainsString($trecho, $erro->getMessage());
            }
        }
    }

    /**
     * @spec:AC-084 Situação que o contrato não prevê vira "pendente", nunca
     * "ativo". Tratar como ativo o que não se entende faria a matriz cobrar
     * por engano.
     */
    public function test_situacao_desconhecida_nunca_vira_ativo(): void
    {
        $cliente = ClienteExterno::deArray([
            'id_externo' => '128',
            'nome' => 'Academia Corpo em Movimento',
            'status' => 'em_negociacao_especial',
        ]);

        $this->assertSame('pendente', $cliente->status);

        $licenca = LicencaExterna::deArray([
            'id_externo' => '91',
            'cliente_id_externo' => '128',
            'status' => 'coisa_que_ninguem_previu',
        ]);

        $this->assertSame('pendente', $licenca->status);

        $fatura = FaturaExterna::deArray([
            'id_externo' => 'nf-1',
            'cliente_id_externo' => '128',
            'competencia' => '2026-08',
            'status' => 'parcelado_em_negociacao',
        ]);

        $this->assertSame('aberto', $fatura->status, 'Tratar como pago o que não se entende esconderia inadimplência.');
    }

    /**
     * @spec:AC-084 O documento chega normalizado, venha formatado ou não: é
     * disso que depende o casamento com o cliente da matriz.
     */
    public function test_o_documento_chega_normalizado(): void
    {
        $formatado = ClienteExterno::deArray([
            'id_externo' => '1',
            'nome' => 'A',
            'cpf_cnpj' => '98.765.432/0001-55',
        ]);
        $cru = ClienteExterno::deArray([
            'id_externo' => '2',
            'nome' => 'B',
            'cpf_cnpj' => '98765432000155',
        ]);

        $this->assertSame('98765432000155', $formatado->cpfCnpj);
        $this->assertSame($cru->cpfCnpj, $formatado->cpfCnpj);
    }

    /**
     * @spec:AC-091 Documento ausente nunca casa com documento ausente: dois
     * clientes sem CNPJ não são o mesmo cliente, e tratá-los como iguais
     * juntaria cadastros distintos em silêncio.
     */
    public function test_documento_ausente_nunca_casa_com_documento_ausente(): void
    {
        $this->assertFalse(Documento::iguais(null, null));
        $this->assertFalse(Documento::iguais('', ''));
        $this->assertFalse(Documento::iguais('98765432000155', null));

        $this->assertTrue(Documento::iguais('98.765.432/0001-55', '98765432000155'));
        $this->assertNull(Documento::normalizar('sem dígito nenhum'));
    }

    /**
     * @spec:AC-084 Licença sem identificador próprio ganha um derivado do
     * cliente. A chave única do retrato local depende de ele nunca ser nulo, e
     * chave única sobre coluna nula tem semântica diferente entre bancos.
     */
    public function test_licenca_sem_identificador_ganha_um_derivado_do_cliente(): void
    {
        $licenca = LicencaExterna::deArray([
            'cliente_id_externo' => '128',
            'status' => 'ativa',
        ]);

        $this->assertSame('cliente:128', $licenca->idExterno);
    }

    /**
     * @spec:AC-084 Se o sistema não declarar que bloquear barra o acesso, a
     * matriz assume que NÃO barra. Prometer um efeito que não acontece é pior
     * que não prometer nada.
     */
    public function test_bloqueio_de_acesso_nao_declarado_e_assumido_como_falso(): void
    {
        $naoDeclarou = LicencaExterna::deArray(['cliente_id_externo' => '128']);
        $declarou = LicencaExterna::deArray(['cliente_id_externo' => '128', 'bloqueia_acesso' => true]);

        $this->assertFalse($naoDeclarou->bloqueiaAcesso);
        $this->assertTrue($declarou->bloqueiaAcesso);
    }

    /**
     * @spec:AC-088 A linha do financeiro só é "derivada" quando o sistema
     * declara. O padrão é título de verdade, porque linha oficial precisa
     * entrar na comparação de valores da tela de divergências.
     */
    public function test_a_fatura_so_e_derivada_quando_o_sistema_declara(): void
    {
        $padrao = FaturaExterna::deArray([
            'id_externo' => 'nf-1', 'cliente_id_externo' => '128', 'competencia' => '2026-08',
        ]);
        $derivada = FaturaExterna::deArray([
            'id_externo' => 'lic-91-2026-08', 'cliente_id_externo' => '128',
            'competencia' => '2026-08', 'origem' => 'derivado',
        ]);

        $this->assertSame('titulo', $padrao->origem);
        $this->assertSame('derivado', $derivada->origem);
    }

    /** @spec:AC-088 Competência fora do formato é recusada, não silenciada. */
    public function test_competencia_invalida_e_recusada(): void
    {
        $this->expectException(ErroIntegracao::class);

        FaturaExterna::deArray([
            'id_externo' => 'nf-1',
            'cliente_id_externo' => '128',
            'competencia' => 'agosto de 2026',
        ]);
    }

    /**
     * @spec:AC-081 Credencial que o sistema mande por descuido não entra no
     * retrato local. Guardar o resumo de senha de um administrador de cliente
     * porque o outro lado escorregou transformaria um descuido dele num
     * vazamento permanente aqui.
     */
    public function test_credencial_enviada_por_descuido_nao_e_guardada(): void
    {
        $usuario = UsuarioExterno::deArray([
            'id_externo' => '512',
            'cliente_id_externo' => '128',
            'nome' => 'Marina Alves',
            'email' => 'marina@exemplo.com.br',
            'password_hash' => '$2y$12$abcdefghijklmnop',
            'api_key' => 'chave-que-nao-devia-ter-vindo',
            'senha' => 'texto-em-claro',
        ]);

        $guardado = json_encode($usuario->paraEspelho());

        $this->assertStringNotContainsString('$2y$12$', $guardado);
        $this->assertStringNotContainsString('chave-que-nao-devia-ter-vindo', $guardado);
        $this->assertStringNotContainsString('texto-em-claro', $guardado);
        $this->assertStringContainsString('marina@exemplo.com.br', $guardado, 'O que é legítimo continua guardado.');
    }

    /**
     * @spec:AC-089 A quebra por revenda chega com os tipos certos. Sem
     * normalizar, "18" diferente de 18 acusaria divergência onde não há — e
     * falso alarme faz a tela inteira ser ignorada.
     */
    public function test_a_quebra_por_revenda_chega_com_os_tipos_certos(): void
    {
        $contadores = ContadoresExternos::deArray([
            'competencia' => '2026-08',
            'unidade_cobranca' => 'academia ativa',
            'unidades_ativas' => '33',
            'por_revenda' => [
                ['revenda_id_externo' => 3, 'nome' => 'Invest Soluções', 'unidades_ativas' => '18', 'valor' => '4230.00'],
                ['sem_identificador' => true],
            ],
        ]);

        $this->assertSame(33, $contadores->unidadesAtivas);
        $this->assertCount(1, $contadores->porRevenda, 'Linha sem identificador de revenda é descartada.');
        $this->assertSame('3', $contadores->porRevenda[0]['revenda_id_externo']);
        $this->assertSame(18, $contadores->unidadesDaRevenda('3'));
        $this->assertNull($contadores->unidadesDaRevenda('99'), 'Revenda desconhecida não vira zero.');
    }

    /**
     * @spec:AC-078 O erro sabe dizer se vale tentar de novo. Repetir uma
     * recusa não muda um "não", e repetir sem essa distinção transformaria
     * cada 404 em três chamadas inúteis.
     */
    public function test_o_erro_distingue_recusa_de_indisponibilidade(): void
    {
        $recusa = ErroIntegracao::doSistema('cliente_nao_encontrado', 'Não achei.', 404);
        $this->assertTrue($recusa->ehRecusa());
        $this->assertFalse($recusa->ehIndisponibilidade());

        $excesso = ErroIntegracao::doSistema('limite_de_taxa', 'Devagar.', 429);
        $this->assertFalse($excesso->ehRecusa(), 'Excesso de pedidos merece nova tentativa.');
        $this->assertTrue($excesso->ehIndisponibilidade());

        $this->assertTrue(ErroIntegracao::conexaoFalhou()->ehIndisponibilidade());
        $this->assertTrue(ErroIntegracao::configuracao('sem_chave')->ehConfiguracao());
    }

    /**
     * @spec:AC-078 Código de erro fora do catálogo vira erro genérico, mas o
     * que o sistema disse é preservado. Aceitar qualquer código deixaria o
     * painel tratando como conhecido algo que ele não sabe interpretar.
     */
    public function test_codigo_de_erro_fora_do_catalogo_vira_generico(): void
    {
        $erro = ErroIntegracao::doSistema('erro_exotico_do_sistema', 'Deu ruim aqui.', 500);

        $this->assertSame('erro_interno', $erro->codigo);
        $this->assertSame('Deu ruim aqui.', $erro->getMessage());
        $this->assertSame('erro_exotico_do_sistema', $erro->detalhes['codigo_recebido']);
    }
}
