<?php

namespace App\Services\Secretario\VixOrganize;

use App\Models\SecretarioVixOrganize;

/**
 * O último retrato da fila do VixOrganize, por conta. Falha não apaga o que
 * já foi lido: a tela mostra o retrato com a hora e o erro (FR-004).
 */
class Retrato
{
    /** Lê tudo de novo. Devolve a mensagem de erro, ou null se leu. */
    public function atualizar(SecretarioVixOrganize $conta): ?string
    {
        try {
            $dados = (new Cliente($conta))->lerTudo();
        } catch (ErroDoVixOrganize $e) {
            $conta->update(['erro' => mb_substr($e->getMessage(), 0, 190), 'erro_em' => now()]);

            return $e->getMessage();
        }
        $conta->update(['retrato' => $dados, 'lido_em' => now(), 'erro' => null, 'erro_em' => null]);

        return null;
    }

    /** Troca no retrato o projeto que a resposta de um envio já trouxe atualizado. */
    public function guardar(SecretarioVixOrganize $conta, array $projeto): void
    {
        $retrato = $conta->retrato ?? ['projetos' => []];
        $lista = collect($retrato['projetos'] ?? [])->reject(fn ($p) => ($p['slug'] ?? null) === ($projeto['slug'] ?? null))->push($projeto);
        $retrato['projetos'] = $lista->sortBy('rank')->values()->all();
        $retrato['em_andamento'] = $lista->where('status', 'em_andamento')->count();
        $conta->update(['retrato' => $retrato]);
    }
}
