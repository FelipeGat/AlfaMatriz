<?php

namespace Tests\Feature\Integracao;

use Tests\TestCase;

class ContratoDocumentadoTest extends TestCase
{
    private const CONTRATO = 'docs/integracao/CONTRATO-API-v1.md';

    private const HISTORICO = 'docs/integracao/CHANGELOG.md';

    /**
     * Os endereços que o contrato precisa descrever. Quando um endereço novo
     * entrar aqui sem entrar no documento, este teste falha — é o que impede o
     * documento de virar mentira enquanto o código anda.
     */
    private const ENDERECOS = [
        'ping', 'revendas', 'clientes', 'planos', 'licencas', 'usuarios',
        'contadores',
    ];

    /** Os formatos que cada endereço devolve, e que precisam estar descritos. */
    private const FORMATOS = [
        'ping', 'revenda', 'cliente', 'plano', 'licenca', 'usuario', 'contadores',
    ];

    /** O catálogo é fechado: a matriz só entende estes códigos. */
    private const ERROS = [
        'nao_autenticado', 'nao_autorizado', 'cliente_nao_encontrado',
        'licenca_nao_encontrada', 'licenca_ja_ativa', 'plano_invalido',
        'cnpj_duplicado', 'competencia_invalida', 'operacao_nao_suportada',
        'limite_de_taxa', 'erro_interno', 'indisponivel',
    ];

    /**
     * @spec:AC-078 Existe um documento versionado que descreve o que cada
     * sistema precisa expor: os endereços, o formato de cada resposta e o
     * catálogo de erros. Sem ele, integrar o segundo sistema é reinventar a
     * integração em vez de repetir a primeira.
     */
    public function test_o_contrato_descreve_todos_os_enderecos_e_formatos(): void
    {
        $documento = $this->contrato();

        foreach (self::ENDERECOS as $endereco) {
            $this->assertStringContainsString(
                "/{$endereco}",
                $documento,
                "O contrato não descreve o endereço \"/{$endereco}\"."
            );
        }

        foreach (self::FORMATOS as $formato) {
            $this->assertMatchesRegularExpression(
                '/^###\s+'.preg_quote($formato, '/').'\s*$/mi',
                $documento,
                "O contrato não descreve o formato \"{$formato}\"."
            );
        }
    }

    /**
     * @spec:AC-078 O catálogo de erros é fechado e está escrito: é o que
     * permite ao painel traduzir a recusa de um sistema numa mensagem que
     * alguém entende, em vez de mostrar um código cru.
     */
    public function test_o_contrato_lista_o_catalogo_fechado_de_erros(): void
    {
        $documento = $this->contrato();

        foreach (self::ERROS as $erro) {
            $this->assertStringContainsString(
                $erro,
                $documento,
                "O catálogo de erros não inclui \"{$erro}\"."
            );
        }
    }

    /**
     * @spec:AC-078 O contrato declara como evolui. Sem essa regra escrita,
     * o primeiro campo renomeado quebra em silêncio a integração de quem já
     * tinha implementado.
     */
    public function test_o_contrato_declara_como_a_versao_evolui(): void
    {
        $documento = $this->contrato();

        $this->assertMatchesRegularExpression(
            '/^##\s+Evolução do contrato\s*$/mi',
            $documento,
            'O contrato precisa dizer o que é mudança compatível e o que exige versão nova.'
        );
        $this->assertStringContainsString('/v2', $documento, 'Precisa dizer o que força uma versão nova.');
        $this->assertStringContainsString(
            'contrato_incompativel',
            $documento,
            'Precisa dizer o que a matriz faz ao receber uma versão que não conhece.'
        );
    }

    /**
     * @spec:AC-078 A autenticação é por chave própria da matriz, não pela
     * chave de monitoramento que alguns sistemas já expõem: aquela é só de
     * leitura e já está distribuída, e reaproveitá-la daria ao monitoramento
     * o poder de bloquear cliente pagante quando a escrita entrar.
     */
    public function test_o_contrato_exige_chave_propria_e_guardada_como_resumo(): void
    {
        $documento = $this->contrato();

        $this->assertStringContainsString('X-Matriz-Key', $documento);
        $this->assertStringContainsString(
            'SHA-256',
            $documento,
            'O sistema guarda o resumo da chave, nunca a chave em claro.'
        );
        $this->assertStringContainsString(
            'X-Monitor-Key',
            $documento,
            'O documento precisa explicar por que a chave do monitoramento não é reaproveitada.'
        );
    }

    /**
     * @spec:AC-078 A paginação exige ordenação estável. Sem ela, um registro
     * criado durante a varredura empurra outro para uma página já lida, e ele
     * some do retrato local sem ninguém perceber.
     */
    public function test_o_contrato_exige_ordenacao_estavel_na_paginacao(): void
    {
        $documento = $this->contrato();

        $this->assertStringContainsString('Ordenação estável obrigatória', $documento);
        $this->assertStringContainsString('id_externo', $documento);
    }

    /**
     * @spec:AC-078 Os dois campos que carregam decisão, e não apenas dado,
     * precisam estar explicados — são eles que evitam que a matriz minta.
     */
    public function test_o_contrato_explica_os_campos_que_carregam_decisao(): void
    {
        $documento = $this->contrato();

        // Quantas unidades de cobrança o cliente representa: é o número que a
        // matriz confronta com o que a Alfa faturou da revenda.
        $this->assertStringContainsString('unidades_ativas', $documento);

        // Se vencer a licença realmente barra o acesso naquele sistema.
        $this->assertStringContainsString('bloqueia_acesso', $documento);
    }

    /**
     * @spec:AC-088 O contrato NÃO pede dinheiro a nenhum sistema, e diz isso
     * com todas as letras. O contrato do cliente e o preço da revenda vivem na
     * matriz; pedi-los a cinco sistemas seria manter cinco verdades sobre a
     * mesma coisa, e na primeira divergência ninguém saberia qual acreditar.
     */
    public function test_o_contrato_nao_pede_dinheiro_a_nenhum_sistema(): void
    {
        $documento = $this->contrato();

        $this->assertStringNotContainsString('/financeiro', $documento);
        $this->assertStringNotContainsString('faturado_no_sistema', $documento);
        $this->assertDoesNotMatchRegularExpression(
            '/^###\s+fatura\s*$/mi',
            $documento,
            'Nenhum título de cobrança vem dos sistemas.'
        );
        $this->assertStringContainsString(
            'NENHUM valor em dinheiro',
            $documento,
            'O documento precisa deixar isso explícito para quem for integrar o segundo sistema.'
        );
    }

    /**
     * @spec:AC-078 O histórico do contrato existe e registra a versão em
     * vigor: cinco sistemas leem o documento, e mudança silenciosa quebra a
     * integração de quem já implementou.
     */
    public function test_o_historico_do_contrato_registra_a_versao_em_vigor(): void
    {
        $historico = $this->arquivo(self::HISTORICO);

        $this->assertMatchesRegularExpression(
            '/^##\s+1\.0/m',
            $historico,
            'O histórico precisa registrar a versão 1.0.'
        );

        $this->assertStringContainsString(
            '"contrato": "1.0"',
            $this->contrato(),
            'O documento precisa mostrar a versão que as respostas declaram.'
        );
    }

    private function contrato(): string
    {
        return $this->arquivo(self::CONTRATO);
    }

    private function arquivo(string $caminho): string
    {
        $completo = base_path($caminho);
        $this->assertFileExists($completo, "O contrato depende de {$caminho}.");

        return file_get_contents($completo);
    }
}
