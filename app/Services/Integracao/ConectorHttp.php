<?php

namespace App\Services\Integracao;

use App\Models\Sistema;
use App\Services\Integracao\Dto\ContadoresExternos;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fala com um sistema de verdade, pelo contrato.
 *
 * É a única classe do projeto que sabe que existe HTTP. Tudo acima dela lida
 * com objetos e com {@see ErroIntegracao} — e é por isso que a suíte inteira
 * roda sem rede, trocando esta implementação pelo dublê.
 */
class ConectorHttp implements ConectorSistema
{
    private const PREFIXO = '/api/matriz/v1';

    public function __construct(private readonly Sistema $sistema) {}

    public function ping(): array
    {
        return $this->obter('/ping')->objeto();
    }

    public function revendas(int $pagina = 1): RespostaIntegracao
    {
        return $this->obter('/revendas', $this->paginacao($pagina));
    }

    public function clientes(int $pagina = 1): RespostaIntegracao
    {
        return $this->obter('/clientes', $this->paginacao($pagina));
    }

    public function planos(int $pagina = 1): RespostaIntegracao
    {
        return $this->obter('/planos', $this->paginacao($pagina));
    }

    public function usuarios(int $pagina = 1): RespostaIntegracao
    {
        return $this->obter('/usuarios', $this->paginacao($pagina));
    }

    public function licencas(int $pagina = 1): RespostaIntegracao
    {
        return $this->obter('/licencas', $this->paginacao($pagina));
    }

    public function contadores(string $competencia): ContadoresExternos
    {
        return ContadoresExternos::deArray(
            $this->obter('/contadores', ['competencia' => $competencia])->objeto()
        );
    }

    private function paginacao(int $pagina): array
    {
        return [
            'pagina' => max(1, $pagina),
            'tamanho' => (int) config('integracao.tamanho_pagina', 200),
        ];
    }

    private function obter(string $caminho, array $consulta = []): RespostaIntegracao
    {
        $resposta = $this->tentar(fn (PendingRequest $pedido) => $pedido->get($caminho, $consulta));

        return RespostaIntegracao::deArray(
            $resposta->json(),
            (int) config('integracao.contrato_major', 1),
        );
    }

    /**
     * Faz o pedido, repetindo só quando repetir pode adiantar.
     *
     * A regra é a razão de este laço ser escrito à mão em vez de usar o
     * repetidor pronto do framework: recusa do sistema (4xx que não seja
     * excesso de pedidos) NUNCA é repetida — um "não" não muda por insistência,
     * e repetir transformaria cada 404 em três chamadas inúteis.
     */
    private function tentar(callable $chamada): Response
    {
        $tentativas = max(1, (int) config('integracao.tentativas', 2));
        $espera = max(0, (int) config('integracao.espera_entre_tentativas', 300));
        $pedido = $this->requisicao();
        $ultimo = null;

        for ($vez = 1; $vez <= $tentativas; $vez++) {
            try {
                $resposta = $chamada($pedido);
            } catch (ConnectionException $falha) {
                $ultimo = $this->erroDeConexao($falha);
                $resposta = null;
            }

            if ($resposta !== null) {
                if ($resposta->successful()) {
                    return $resposta;
                }

                $erro = $this->erroDaResposta($resposta);

                if ($erro->ehRecusa()) {
                    throw $erro;
                }

                $ultimo = $erro;
            }

            if ($vez < $tentativas && $espera > 0) {
                usleep($espera * 1000);
            }
        }

        throw $ultimo ?? ErroIntegracao::conexaoFalhou();
    }

    private function requisicao(): PendingRequest
    {
        $motivo = $this->sistema->motivoIntegracaoIndisponivel();

        if ($motivo !== null) {
            throw ErroIntegracao::configuracao($motivo);
        }

        return Http::baseUrl(rtrim((string) $this->sistema->base_url, '/').self::PREFIXO)
            ->withHeaders([
                'X-Matriz-Key' => $this->chave(),
                'Accept' => 'application/json',
            ])
            ->connectTimeout((int) config('integracao.timeout_conexao', 5))
            ->timeout((int) config('integracao.timeout', 15));
    }

    /**
     * A chave em claro, para o cabeçalho.
     *
     * Vem cifrada do banco. Se a chave da aplicação for trocada no servidor,
     * todas as chaves de integração ficam ilegíveis de uma vez — e sem este
     * tratamento isso apareceria como uma exceção crua de decifragem, que não
     * diz a ninguém o que aconteceu nem o que fazer.
     */
    private function chave(): string
    {
        // A proteção contra chave ilegível mora no modelo, porque a tela do
        // painel precisa dela tanto quanto o conector.
        return (string) $this->sistema->chaveDeIntegracao();
    }

    private function erroDeConexao(ConnectionException $falha): ErroIntegracao
    {
        $mensagem = strtolower($falha->getMessage());

        if (str_contains($mensagem, 'timed out') || str_contains($mensagem, 'timeout')) {
            return ErroIntegracao::tempoEsgotado();
        }

        // A mensagem original NÃO entra: ela carrega a URL completa do pedido,
        // e uma mudança futura no cliente HTTP poderia trazer junto o cabeçalho
        // — que é onde a chave viaja.
        return ErroIntegracao::conexaoFalhou();
    }

    private function erroDaResposta(Response $resposta): ErroIntegracao
    {
        $corpo = $resposta->json();
        $erro = is_array($corpo) && is_array($corpo['erro'] ?? null) ? $corpo['erro'] : [];

        $codigo = (string) ($erro['codigo'] ?? $this->codigoPeloStatus($resposta->status()));
        $mensagem = trim((string) ($erro['mensagem'] ?? ''));

        if ($mensagem === '') {
            $mensagem = "O sistema respondeu {$resposta->status()}.";
        }

        return ErroIntegracao::doSistema(
            $codigo,
            $mensagem,
            $resposta->status(),
            is_array($erro['detalhes'] ?? null) ? $erro['detalhes'] : [],
        );
    }

    private function codigoPeloStatus(int $status): string
    {
        return match (true) {
            $status === 401 => 'nao_autenticado',
            $status === 403 => 'nao_autorizado',
            $status === 404 => 'cliente_nao_encontrado',
            $status === 409 => 'licenca_ja_ativa',
            $status === 422 => 'competencia_invalida',
            $status === 429 => 'limite_de_taxa',
            $status === 501 => 'operacao_nao_suportada',
            $status === 503 => 'indisponivel',
            default => 'erro_interno',
        };
    }
}
