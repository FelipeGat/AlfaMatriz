<?php

namespace Tests\Feature\Suporte;

use App\Services\Suporte\DiretorioGestor;
use ReflectionProperty;
use Tests\TestCase;

/**
 * O relato do dia com vários clientes (Felipe, 15/11/2026): ele mandou cinco agendas numa mensagem, e o Pedro
 * disse duas vezes que um cliente de uma palavra só "não apareceu entre os parecidos", embora o cliente estivesse ativo e
 * com atendimento recente. A palavra do nome dele era dividida com dois pré-clientes e pesava 1/3; os nomes
 * de outros clientes citados no mesmo relato, com mais palavras em comum, ocuparam as 10 vagas. Quem teve o
 * nome inteiro dito vem antes de quem só dividiu uma palavra. Dados fictícios.
 */
class PedroBuscaDaCasaEmRelatoLongoTest extends TestCase
{
    private function diretorio(array $clientes): DiretorioGestor
    {
        $d = new DiretorioGestor;
        (new ReflectionProperty($d, 'retrato'))->setValue($d, ['lido_em' => now()->toIso8601String(), 'clientes' => $clientes, 'atendimentos' => [], 'assuntos' => [],
            'equipe' => [], 'showroom' => [], 'visitas' => [], 'orcamentos' => [], 'empresas' => null]);

        return $d;
    }

    public function test_o_nome_inteiro_dito_vem_antes_de_quem_so_dividiu_uma_palavra(): void
    {
        $c = fn (int $id, string $nome, array $extra = []) => $extra + ['id' => $id, 'nome' => $nome, 'fantasia' => $nome, 'razao' => '', 'cidade' => '',
            'documento' => '', 'empresa_id' => 3, 'empresa' => 'INVEST', 'telefones' => []];
        $clientes = [
            $c(1, 'CONDOMINIO IPE ROXO'),
            $c(-2, 'Citius Ipe Roxo', ['pre' => true]),
            $c(-3, 'Jel Ipe Roxo LTDA', ['pre' => true]),
        ];
        // Cada cliente citado só em parte do nome (a terceira palavra não foi dita) pontua 2 de 3, contra 1/3 do Ipê Roxo.
        foreach (['Aurora Mirante', 'Brisa Colina', 'Cedro Planalto', 'Dália Vertente', 'Esmeralda Campina', 'Fortaleza Jacaranda', 'Gaivota Pinheiral',
            'Horizonte Lagoa', 'Iguaçu Cascata', 'Jasmim Terraço', 'Lírio Savana', 'Magnólia Rochedo'] as $i => $nome) {
            $clientes[] = $c(10 + $i, $nome.' '.['Zenite', 'Quimera', 'Bonanza', 'Tamarindo', 'Orquidea', 'Sabia'][$i % 6]);
        }
        $relato = 'Agenda 1: cliente Ipê Roxo. Agenda 2: Aurora Mirante. Agenda 3: Brisa Colina. Agenda 4: Cedro Planalto. Agenda 5: Dália Vertente. '
            .'Agenda 6: Esmeralda Campina. Agenda 7: Fortaleza Jacaranda. Agenda 8: Gaivota Pinheiral. Agenda 9: Horizonte Lagoa. '
            .'Agenda 10: Iguaçu Cascata. Agenda 11: Jasmim Terraço. Agenda 12: Lírio Savana. Agenda 13: Magnólia Rochedo.';

        $ids = array_column($this->diretorio($clientes)->buscarClientes($relato, 11), 'id');

        $this->assertContains(1, $ids, 'o Ipê Roxo, dito por inteiro, não pode ficar de fora por dividir a palavra com dois cadastros');
    }
}
