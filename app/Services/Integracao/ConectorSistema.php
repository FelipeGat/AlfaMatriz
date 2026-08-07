<?php

namespace App\Services\Integracao;

use App\Services\Integracao\Dto\ContadoresExternos;

/**
 * O que a matriz sabe pedir a um sistema da casa.
 *
 * Esta versão é SÓ LEITURA. Os métodos de escrita (provisionar cliente,
 * liberar licença, modo de manutenção) entram quando a matriz passar a ser
 * dona do cadastro — e entram aqui, na mesma interface.
 *
 * Nenhum serviço instancia um conector direto: todos pedem à fábrica, e é isso
 * que permite trocar o conector real pelo falso nos testes sem que a suíte
 * inteira precise de rede.
 *
 * Toda falha sai como {@see ErroIntegracao}, com código nomeado.
 */
interface ConectorSistema
{
    /** Situação do sistema e versão do contrato que ele fala. */
    public function ping(): array;

    public function revendas(int $pagina = 1): RespostaIntegracao;

    public function clientes(int $pagina = 1): RespostaIntegracao;

    public function planos(int $pagina = 1): RespostaIntegracao;

    public function usuarios(int $pagina = 1): RespostaIntegracao;

    public function licencas(int $pagina = 1): RespostaIntegracao;

    /** O que o sistema cobra dos clientes na competência (AAAA-MM). */
    public function financeiro(string $competencia, int $pagina = 1): RespostaIntegracao;

    public function contadores(string $competencia): ContadoresExternos;
}
