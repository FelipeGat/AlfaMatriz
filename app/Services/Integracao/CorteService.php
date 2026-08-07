<?php

namespace App\Services\Integracao;

use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaRevenda;
use App\Models\User;

/**
 * O corte: o momento em que a matriz passa a ser dona do cadastro de um
 * sistema.
 *
 * É praticamente irreversível — depois dele, voltar atrás significa
 * reconciliar duas bases que divergiram. Por isso ele é sistema por sistema, e
 * recusa enquanto sobrar qualquer pendência de conferência.
 */
class CorteService
{
    public function __construct(private readonly VinculadorService $vinculador) {}

    /**
     * O que ainda impede o corte deste sistema, por motivo.
     *
     * @return array<string, int>
     */
    public function pendencias(Sistema $sistema): array
    {
        $clientes = SistemaCliente::doSistema($sistema)->presentes()->semVinculo()->get();
        $revendas = SistemaRevenda::doSistema($sistema)->presentes()->whereNull('revenda_id')->get();

        $contagem = [
            VinculadorService::SEM_DOCUMENTO => 0,
            VinculadorService::SEM_PAR => 0,
            VinculadorService::VARIOS_CANDIDATOS => 0,
            VinculadorService::REPETIDO_NO_SISTEMA => 0,
        ];

        foreach ($clientes->concat($revendas) as $registro) {
            $motivo = $this->vinculador->motivoDeNaoVincular($registro);

            if ($motivo !== null) {
                $contagem[$motivo]++;
            }
        }

        return $contagem;
    }

    public function totalDePendencias(Sistema $sistema): int
    {
        return array_sum($this->pendencias($sistema));
    }

    public function podeAplicar(Sistema $sistema): bool
    {
        return ! $sistema->cadastroNaMatriz()
            && $sistema->importado_em !== null
            && $this->totalDePendencias($sistema) === 0;
    }

    /**
     * Por que o corte não pode ser aplicado agora — ou null se pode.
     *
     * Motivo nomeado, de novo: "não é possível aplicar o corte" faria a pessoa
     * adivinhar entre três causas bem diferentes.
     */
    public function motivoParaNaoAplicar(Sistema $sistema): ?string
    {
        if ($sistema->cadastroNaMatriz()) {
            return 'ja_aplicado';
        }

        if ($sistema->importado_em === null) {
            return 'sem_importacao';
        }

        return $this->totalDePendencias($sistema) > 0 ? 'com_pendencias' : null;
    }

    /**
     * Aplica o corte.
     *
     * @throws ErroIntegracao quando ainda não pode ser aplicado. Recusar aqui,
     *                        e não só esconder o botão na tela, é o que impede
     *                        o corte de acontecer por um pedido montado à mão.
     */
    public function aplicar(Sistema $sistema, ?User $porQuem = null): Sistema
    {
        $motivo = $this->motivoParaNaoAplicar($sistema);

        if ($motivo !== null) {
            throw new ErroIntegracao($motivo, match ($motivo) {
                'ja_aplicado' => 'A matriz já é dona do cadastro deste sistema.',
                'sem_importacao' => 'O cadastro que já existe no sistema precisa ser importado antes do corte.',
                default => 'Ainda há pendências de conferência: resolva todas antes de aplicar o corte.',
            });
        }

        $sistema->forceFill(['cadastro_na_matriz_desde' => now()])->save();

        return $sistema;
    }

    /** Em que estágio da virada este sistema está. */
    public function estagio(Sistema $sistema): string
    {
        return match (true) {
            $sistema->cadastroNaMatriz() => 'matriz_manda',
            $sistema->importado_em !== null => 'conferindo',
            $sistema->sincronizado_em !== null => 'observando',
            default => 'nao_ligado',
        };
    }
}
