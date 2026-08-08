<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Puxa o retrato do AlfaGym pelo contrato /api/matriz/v1 e preenche as
 * tabelas existentes de revendas e clientes, sem criar estrutura nova.
 *
 * Idempotência por (sistema, id_externo na âncora): rodar de novo não duplica.
 * As licenças moram no vínculo cliente_sistema (o retrato da vigência).
 */
class SincronizadorAlfaGymService
{
    private const CONTRATO = '1.0';

    public function __construct(private readonly Sistema $sistema) {}

    /**
     * @return array<string, mixed> resumo para o relatório do comando
     */
    public function sincronizar(): array
    {
        if (! $this->sistema->base_url || ! $this->sistema->token) {
            return ['ok' => false, 'motivo' => 'Sistema sem endereço ou sem chave configurada.'];
        }

        try {
            $resumo = [
                'revendas' => $this->sincronizarRevendas(),
                'clientes' => $this->sincronizarClientes(),
                'licencas' => $this->sincronizarLicencas(),
            ];

            return ['ok' => true, 'resumo' => $resumo];
        } catch (RequestException $e) {
            return ['ok' => false, 'motivo' => 'AlfaGym respondeu '.$e->response->status().'.'];
        } catch (ConnectionException) {
            return ['ok' => false, 'motivo' => 'Não consegui falar com o AlfaGym.'];
        }
    }

    private function base(): string
    {
        return rtrim($this->sistema->base_url, '/').'/api/matriz/v1';
    }

    private function pegar(string $endereco, array $query = []): array
    {
        $resposta = Http::withHeaders(['X-Matriz-Key' => $this->sistema->token])
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 500)
            ->get($this->base().$endereco, $query)
            ->throw();

        $corpo = $resposta->json();

        // Contrato que não conhecemos: recusa em vez de gravar dado torto.
        if (($corpo['contrato'] ?? null) !== self::CONTRATO) {
            throw new RequestException(new \Illuminate\Http\Client\Response(
                new Response(400, [], 'contrato incompatível')
            ));
        }

        return $corpo['dados'] ?? [];
    }

    private function sincronizarRevendas(): array
    {
        $criadas = $atualizadas = 0;

        foreach ($this->todasPaginas('/revendas') as $item) {
            $revenda = Revenda::porOrigemExterna($this->sistema, $item['id_externo']);

            $dados = [
                'nome' => $item['nome'] ?? 'Sem nome',
                'cnpj' => $this->normalizarDocumento($item['cnpj'] ?? null),
                'contato_email' => $item['email'] ?? null,
                'contato_telefone' => $item['telefone'] ?? null,
                'ativo' => $item['ativo'] ?? true,
            ];

            if ($revenda) {
                $revenda->update($dados);
                $atualizadas++;
            } else {
                $revenda = Revenda::create($dados);
                $revenda->ancorarEm($this->sistema, $item['id_externo']);
                $criadas++;
            }
        }

        return ['criadas' => $criadas, 'atualizadas' => $atualizadas];
    }

    private function sincronizarClientes(): array
    {
        $criados = $atualizados = 0;

        foreach ($this->todasPaginas('/clientes') as $item) {
            $cliente = Cliente::porOrigemExterna($this->sistema, $item['id_externo']);

            $dados = [
                'nome' => $item['nome'] ?? 'Sem nome',
                'razao_social' => $item['razao_social'] ?? null,
                'cpf_cnpj' => $this->normalizarDocumento($item['cpf_cnpj'] ?? null),
                'email' => $item['email'] ?? null,
                'telefone' => $item['telefone'] ?? null,
                'cidade' => $item['cidade'] ?? null,
                'uf' => $item['uf'] ?? null,
                'ativo' => $item['ativo'] ?? true,
                'revenda_id' => $this->revendaPorIdExterno($item['revenda_id_externo'] ?? null)?->id,
            ];

            if ($cliente) {
                $cliente->update($dados);
                $atualizados++;
            } else {
                $cliente = Cliente::create($dados);
                $cliente->ancorarEm($this->sistema, $item['id_externo']);
                $criados++;
            }

            // Vínculo com o sistema (retrato local de "quem usa o quê").
            $cliente?->sistemas()->syncWithoutDetaching([$this->sistema->id => [
                'ativo' => $item['ativo'] ?? true,
                'status_saas' => $item['status'] ?? null,
            ]]);
        }

        return ['criados' => $criados, 'atualizados' => $atualizados];
    }

    private function sincronizarLicencas(): array
    {
        $atualizadas = 0;

        foreach ($this->todasPaginas('/licencas') as $item) {
            $cliente = Cliente::porOrigemExterna($this->sistema, $item['cliente_id_externo'] ?? null);

            if (! $cliente) {
                continue;
            }

            $cliente->sistemas()->syncWithoutDetaching([$this->sistema->id => [
                'licenca_status' => $item['status'] ?? null,
                'plano' => $item['plano'] ?? null,
                'licenca_inicio_em' => $item['inicio_em'] ?? null,
                'licenca_fim_em' => $item['fim_em'] ?? null,
                'bloqueia_acesso' => $item['bloqueia_acesso'] ?? null,
                'licenca_id_externo' => $item['id_externo'] ?? null,
            ]]);

            $atualizadas++;
        }

        return ['atualizadas' => $atualizadas];
    }

    private function revendaPorIdExterno(?string $idExterno): ?Revenda
    {
        if (! $idExterno) {
            return null;
        }

        return Revenda::porOrigemExterna($this->sistema, $idExterno);
    }

    /**
     * Percorre todas as páginas de uma coleção paginada.
     */
    private function todasPaginas(string $endereco): \Generator
    {
        $pagina = 1;
        $tamanho = 200;

        do {
            $dados = $this->pegar($endereco, ['pagina' => $pagina, 'tamanho' => $tamanho]);

            foreach ($dados as $item) {
                yield $item;
            }

            if (count($dados) < $tamanho) {
                break;
            }

            $pagina++;
        } while (count($dados) > 0);
    }

    private function normalizarDocumento(?string $documento): ?string
    {
        if ($documento === null) {
            return null;
        }

        $soDigitos = preg_replace('/\D/', '', $documento);

        return $soDigitos === '' ? null : $soDigitos;
    }
}
