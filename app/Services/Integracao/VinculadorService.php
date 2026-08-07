<?php

namespace App\Services\Integracao;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaRevenda;
use Illuminate\Support\Collection;

/**
 * Liga o que existe dentro de um sistema ao que existe na matriz.
 *
 * A regra inteira cabe numa frase: liga automaticamente SÓ quando não há
 * dúvida. Zero candidatos ou mais de um deixa sem vínculo, para virar
 * pendência de conferência — porque um vínculo errado é pior que vínculo
 * nenhum: ele passa a faturar o cliente errado, e ninguém percebe.
 */
class VinculadorService
{
    /** Por que um registro do sistema não pôde ser ligado sozinho. */
    public const SEM_DOCUMENTO = 'sem_documento';

    public const SEM_PAR = 'sem_par';

    public const VARIOS_CANDIDATOS = 'varios_candidatos';

    /** O mesmo documento aparece mais de uma vez dentro do próprio sistema. */
    public const REPETIDO_NO_SISTEMA = 'repetido_no_sistema';

    /**
     * @return array{ligados: int, sem_documento: int, sem_par: int, varios_candidatos: int, repetido_no_sistema: int}
     */
    public function vincularClientes(Sistema $sistema): array
    {
        $indice = $this->indiceDeClientes();
        $repetidos = array_flip($this->repetidos(SistemaCliente::class, 'cpf_cnpj', $sistema));
        $resumo = ['ligados' => 0, self::SEM_DOCUMENTO => 0, self::SEM_PAR => 0, self::VARIOS_CANDIDATOS => 0, self::REPETIDO_NO_SISTEMA => 0];

        SistemaCliente::doSistema($sistema)->semVinculo()->chunkById(200, function (Collection $registros) use ($indice, $repetidos, &$resumo) {
            foreach ($registros as $registro) {
                // Duplicata dentro do sistema é dúvida: ligar os dois ao mesmo
                // cliente da matriz faturaria a mesma academia duas vezes.
                if (isset($repetidos[$registro->id])) {
                    $resumo[self::REPETIDO_NO_SISTEMA]++;

                    continue;
                }

                $candidatos = $indice[Documento::normalizar($registro->cpf_cnpj) ?? ''] ?? [];

                $motivo = $this->motivo($registro->cpf_cnpj, count($candidatos));

                if ($motivo !== null) {
                    $resumo[$motivo]++;

                    continue;
                }

                $registro->forceFill([
                    'cliente_id' => $candidatos[0],
                    'vinculo_origem' => 'automatico',
                ])->save();

                $resumo['ligados']++;
            }
        });

        return $resumo;
    }

    /**
     * @return array{ligados: int, sem_documento: int, sem_par: int, varios_candidatos: int, repetido_no_sistema: int}
     */
    public function vincularRevendas(Sistema $sistema): array
    {
        $indice = $this->indiceDeRevendas();
        $repetidos = array_flip($this->repetidos(SistemaRevenda::class, 'cnpj', $sistema));
        $resumo = ['ligados' => 0, self::SEM_DOCUMENTO => 0, self::SEM_PAR => 0, self::VARIOS_CANDIDATOS => 0, self::REPETIDO_NO_SISTEMA => 0];

        SistemaRevenda::doSistema($sistema)->whereNull('revenda_id')->chunkById(200, function (Collection $registros) use ($indice, $repetidos, &$resumo) {
            foreach ($registros as $registro) {
                if (isset($repetidos[$registro->id])) {
                    $resumo[self::REPETIDO_NO_SISTEMA]++;

                    continue;
                }

                $candidatos = $indice[Documento::normalizar($registro->cnpj) ?? ''] ?? [];

                $motivo = $this->motivo($registro->cnpj, count($candidatos));

                if ($motivo !== null) {
                    $resumo[$motivo]++;

                    continue;
                }

                $registro->forceFill([
                    'revenda_id' => $candidatos[0],
                    'vinculo_origem' => 'automatico',
                ])->save();

                $resumo['ligados']++;
            }
        });

        return $resumo;
    }

    /** Por que este registro não pôde ser ligado sozinho — ou null se pôde. */
    public function motivoDeNaoVincular(SistemaCliente|SistemaRevenda $registro): ?string
    {
        $modelo = $registro instanceof SistemaCliente ? SistemaCliente::class : SistemaRevenda::class;
        $coluna = $registro instanceof SistemaCliente ? 'cpf_cnpj' : 'cnpj';
        $documento = $registro->getAttribute($coluna);

        if ($this->repetidoDentroDoSistema($modelo, $coluna, $registro)) {
            return self::REPETIDO_NO_SISTEMA;
        }

        $indice = $registro instanceof SistemaCliente ? $this->indiceDeClientes() : $this->indiceDeRevendas();
        $candidatos = $indice[Documento::normalizar($documento) ?? ''] ?? [];

        return $this->motivo($documento, count($candidatos));
    }

    /** Os clientes da matriz que poderiam corresponder a este registro. */
    public function candidatosParaCliente(SistemaCliente $registro): Collection
    {
        $ids = $this->indiceDeClientes()[Documento::normalizar($registro->cpf_cnpj) ?? ''] ?? [];

        return Cliente::whereIn('id', $ids)->get();
    }

    /** Liga à mão. Um vínculo manual nunca é desfeito por execução automática. */
    public function vincularClienteManualmente(SistemaCliente $registro, Cliente $cliente): void
    {
        $registro->forceFill([
            'cliente_id' => $cliente->id,
            'vinculo_origem' => 'manual',
        ])->save();
    }

    public function vincularRevendaManualmente(SistemaRevenda $registro, Revenda $revenda): void
    {
        $registro->forceFill([
            'revenda_id' => $revenda->id,
            'vinculo_origem' => 'manual',
        ])->save();
    }

    private function motivo(?string $documento, int $candidatos): ?string
    {
        if (Documento::normalizar($documento) === null) {
            return self::SEM_DOCUMENTO;
        }

        return match (true) {
            $candidatos === 0 => self::SEM_PAR,
            $candidatos > 1 => self::VARIOS_CANDIDATOS,
            default => null,
        };
    }

    /**
     * Os ids dos registros do sistema cujo documento aparece mais de uma vez
     * dentro dele mesmo.
     *
     * Feito em duas consultas de propósito: primeiro descobre quais documentos
     * estão repetidos (agregado), depois traz os registros daqueles documentos.
     *
     * @return array<int, int>
     */
    private function repetidos(string $modelo, string $coluna, Sistema $sistema): array
    {
        $repetidos = $modelo::doSistema($sistema)->presentes()
            ->whereNotNull($coluna)
            ->groupBy($coluna)
            ->havingRaw('count(*) > 1')
            ->pluck($coluna);

        if ($repetidos->isEmpty()) {
            return [];
        }

        return $modelo::doSistema($sistema)->presentes()
            ->whereIn($coluna, $repetidos)
            ->pluck('id')
            ->all();
    }

    /** Este registro tem um sósia dentro do próprio sistema? */
    private function repetidoDentroDoSistema(string $modelo, string $coluna, SistemaCliente|SistemaRevenda $registro): bool
    {
        return $modelo::doSistema($registro->sistema_id)
            ->presentes()
            ->where($coluna, $registro->getAttribute($coluna))
            ->whereKeyNot($registro->getKey())
            ->exists();
    }

    /**
     * Documento normalizado → ids dos clientes da matriz com aquele documento.
     *
     * Feito em memória, e não com comparação no banco, porque a base guarda os
     * dois formatos: o cadastro de clientes normaliza para dígitos antes de
     * salvar, mas o de revendas aceita o CNPJ formatado. Uma igualdade em SQL
     * funcionaria para clientes e falharia em silêncio para revendas — que é o
     * pior tipo de defeito: o que não dá erro, só deixa de ligar.
     *
     * @return array<string, array<int, int>>
     */
    private function indiceDeClientes(): array
    {
        return $this->indexar(Cliente::query()->whereNotNull('cpf_cnpj')->pluck('cpf_cnpj', 'id'));
    }

    /** @return array<string, array<int, int>> */
    private function indiceDeRevendas(): array
    {
        return $this->indexar(Revenda::query()->whereNotNull('cnpj')->pluck('cnpj', 'id'));
    }

    /** @return array<string, array<int, int>> */
    private function indexar(Collection $documentosPorId): array
    {
        $indice = [];

        foreach ($documentosPorId as $id => $documento) {
            $chave = Documento::normalizar($documento);

            if ($chave === null) {
                continue;
            }

            $indice[$chave][] = (int) $id;
        }

        return $indice;
    }
}
