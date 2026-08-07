<?php

namespace App\Services\Integracao;

use App\Models\Sistema;

/**
 * A importação: trazer para a matriz o cadastro que já existe num sistema.
 *
 * É uma decisão consciente, não automática. Ela (1) garante um retrato fresco,
 * (2) liga automaticamente SÓ o que não gera dúvida — um documento que
 * corresponde a exatamente um par — e (3) marca o sistema como importado, que
 * é o que destrava o corte.
 *
 * REGRA QUE NÃO SE NEGOCIA: a importação NUNCA cria cliente sozinha. Criar um
 * cliente muda a receita da empresa sem ninguém decidir — o faturamento fatura
 * em cima do vínculo cliente-sistema. A criação é sempre uma ação explícita na
 * tela de conferência (AC-093).
 */
class ImportacaoService
{
    public function __construct(
        private readonly SincronizacaoService $sincronizacao,
        private readonly VinculadorService $vinculador,
    ) {}

    /**
     * @return array{
     *     revendas: array{ligados: int, sem_documento: int, sem_par: int, varios_candidatos: int, repetido_no_sistema: int},
     *     clientes: array{ligados: int, sem_documento: int, sem_par: int, varios_candidatos: int, repetido_no_sistema: int}
     * }
     *
     * @throws ErroIntegracao quando o retrato não pôde ser atualizado — importar
     *                        em cima de um retrato velho ou pela metade ligaria
     *                        gente errada, e o sistema nem fica marcado como
     *                        importado.
     */
    public function importar(Sistema $sistema): array
    {
        $execucao = $this->sincronizacao->sincronizar($sistema);

        if (! $execucao->deuCerto()) {
            throw new ErroIntegracao(
                $execucao->erro_codigo ?? 'sincronizacao_incompleta',
                $execucao->erro_mensagem ?? 'O retrato do sistema não está íntegro: a importação foi interrompida.',
            );
        }

        $revendas = $this->vinculador->vincularRevendas($sistema);
        $clientes = $this->vinculador->vincularClientes($sistema);

        $sistema->forceFill(['importado_em' => now()])->save();

        return ['revendas' => $revendas, 'clientes' => $clientes];
    }
}
