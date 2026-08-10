<?php

namespace App\Services;

use App\Models\Sistema;
use GuzzleHttp\Psr7\Response as RespostaPsr;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * O transporte do contrato `/api/matriz/v1`, num lugar só.
 *
 * Os quatro serviços que conversam com um sistema integrado repetiam a mesma
 * montagem de URL, o mesmo header, a mesma validação de envelope e a mesma
 * tradução de erro — cada um com o nome "AlfaGym" escrito na mensagem. Com dois
 * sistemas integrados isso deixaria o admin lendo "AlfaGym recusou" enquanto
 * quem recusou foi o AlfaControl.
 *
 * Aqui o nome sai de `$sistema->nome`, e a versão aceita do contrato vive numa
 * constante só — quando houver v2, é um lugar para mexer.
 */
final class ContratoMatriz
{
    /** Versões de contrato que sabemos ler. */
    private const ACEITOS = ['1.0'];

    private const TIMEOUT = 30;

    public function __construct(private readonly Sistema $sistema) {}

    /**
     * O sistema tem endereço e chave? Falta de configuração é estado normal
     * entre publicar a integração e configurá-la — não é falha de rede.
     */
    public function exigirConfigurado(): void
    {
        if (! $this->sistema->base_url || ! $this->sistema->token) {
            throw new \RuntimeException('Sistema sem endereço ou sem chave configurada.');
        }
    }

    public function configurado(): bool
    {
        return filled($this->sistema->base_url) && filled($this->sistema->token);
    }

    public function base(): string
    {
        return rtrim((string) $this->sistema->base_url, '/').'/api/matriz/v1';
    }

    /**
     * Leitura. Devolve `dados` já desembrulhado do envelope.
     *
     * @param  array<string, mixed>  $query
     * @return array<int|string, mixed>
     */
    public function pegar(string $caminho, array $query = []): array
    {
        $resposta = Http::withHeaders(['X-Matriz-Key' => $this->sistema->token])
            ->acceptJson()
            ->timeout(self::TIMEOUT)
            ->retry(2, 500)
            ->get($this->base().$caminho, $query)
            ->throw();

        $corpo = $resposta->json();

        // Contrato que não conhecemos: recusa em vez de gravar dado torto.
        $this->exigirContratoConhecido($corpo['contrato'] ?? null);

        return $corpo['dados'] ?? [];
    }

    /**
     * Escrita. Devolve o envelope inteiro — quem chama costuma precisar de
     * `dados` e de `contrato`.
     *
     * Sem `retry`: repetir um POST recusado por conflito não muda a resposta, e
     * repetir um que já gravou criaria em duplicidade.
     *
     * @param  array<string, mixed>  $corpo
     * @return array<string, mixed>
     */
    public function enviar(string $caminho, array $corpo): array
    {
        try {
            $resposta = Http::withHeaders(['X-Matriz-Key' => $this->sistema->token])
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->post($this->base().$caminho, $corpo)
                ->throw();
        } catch (RequestException $e) {
            throw new \RuntimeException($this->mensagemDeErro($e), 0, $e);
        }

        $envelope = $resposta->json();

        if (! in_array($envelope['contrato'] ?? null, self::ACEITOS, true)) {
            throw new \RuntimeException($this->sistema->nome.' respondeu com contrato incompatível.');
        }

        return $envelope;
    }

    /**
     * Percorre todas as páginas de uma coleção paginada.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function paginas(string $caminho, int $tamanho = 200): \Generator
    {
        $pagina = 1;

        do {
            $dados = $this->pegar($caminho, ['pagina' => $pagina, 'tamanho' => $tamanho]);

            foreach ($dados as $item) {
                yield $item;
            }

            // Página incompleta é a última: evita uma requisição a mais só para
            // descobrir que acabou.
            if (count($dados) < $tamanho) {
                break;
            }

            $pagina++;
        } while (count($dados) > 0);
    }

    /**
     * A mensagem que o admin lê quando o outro lado recusa. Nomeia o sistema
     * certo — com dois integrados, "AlfaGym recusou" para uma recusa do
     * AlfaControl manda o suporte investigar o servidor errado.
     */
    public function mensagemDeErro(RequestException $e): string
    {
        $corpo = $e->response?->json();

        $mensagem = $corpo['erro']['mensagem'] ?? null;

        if (is_string($mensagem) && $mensagem !== '') {
            return $this->sistema->nome.' recusou: '.$mensagem;
        }

        return $this->sistema->nome.' respondeu '.$e->response?->status().'.';
    }

    /** Mensagem de falha de leitura, com o sistema nomeado. */
    public function mensagemDeLeitura(RequestException $e): string
    {
        return $this->sistema->nome.' respondeu '.$e->response->status().'.';
    }

    public function mensagemDeConexao(): string
    {
        return 'Não consegui falar com o '.$this->sistema->nome.'.';
    }

    /**
     * Um envelope de versão desconhecida é falha de leitura, não de rede — mas
     * precisa chegar a quem chama como `RequestException`, que é o que os
     * serviços já tratam.
     */
    private function exigirContratoConhecido(?string $versao): void
    {
        if (in_array($versao, self::ACEITOS, true)) {
            return;
        }

        throw new RequestException(new Response(
            new RespostaPsr(400, [], 'contrato incompatível: recebi '.($versao ?? 'nenhum'))
        ));
    }
}
