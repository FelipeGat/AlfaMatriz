<?php

namespace Database\Seeders;

use App\Models\CentroCusto;
use App\Models\Categoria;
use App\Models\Conta;
use App\Models\ContaFixaPagar;
use App\Models\Fornecedor;
use App\Models\Subcategoria;
use Illuminate\Database\Seeder;

/**
 * Importa as despesas fixas do centro de custo ALFA a partir do Gestor.Alfa
 * (levantado em 05/08/2026, direto do banco de produção/dev do gestor.alfa).
 *
 * Exclui de propósito o item "HOSPEDAGEM VPS GYM" de R$70,99 (fornecedor
 * Demerge, sem data_fim) — parece um registro legado duplicado, substituído
 * pelo de R$108,99 via Hostinger. Sinalizar pro Felipe confirmar/limpar no Gestor.
 */
class DespesasAlfaSeeder extends Seeder
{
    public function run(): void
    {
        $centroAlfa = CentroCusto::firstOrCreate(['nome' => 'Alfa Tecnologia'], ['ativo' => true]);

        $categoriaFixas = Categoria::firstOrCreate(['nome' => 'Despesas Fixas', 'tipo' => 'despesa']);
        $categoriaVariaveis = Categoria::firstOrCreate(['nome' => 'Despesas Variáveis', 'tipo' => 'despesa']);

        $subPessoal = Subcategoria::firstOrCreate(['categoria_id' => $categoriaFixas->id, 'nome' => 'Pessoal']);
        $subFinanceiro = Subcategoria::firstOrCreate(['categoria_id' => $categoriaFixas->id, 'nome' => 'Financeiro']);
        $subEscritorio = Subcategoria::firstOrCreate(['categoria_id' => $categoriaFixas->id, 'nome' => 'Escritório']);
        $subInfra = Subcategoria::firstOrCreate(['categoria_id' => $categoriaVariaveis->id, 'nome' => 'Escritório / Infraestrutura']);
        $subImpostos = Subcategoria::firstOrCreate(['categoria_id' => $categoriaVariaveis->id, 'nome' => 'Impostos']);

        $contaSalarios = Conta::firstOrCreate(['subcategoria_id' => $subPessoal->id, 'nome' => 'Salários']);
        $contaValeAlimentacao = Conta::firstOrCreate(['subcategoria_id' => $subPessoal->id, 'nome' => 'Vale Alimentação']);
        $contaValeTransporte = Conta::firstOrCreate(['subcategoria_id' => $subPessoal->id, 'nome' => 'Vale Transporte']);
        $contaSistemaErp = Conta::firstOrCreate(['subcategoria_id' => $subFinanceiro->id, 'nome' => 'Sistema / ERP']);
        $contaInternet = Conta::firstOrCreate(['subcategoria_id' => $subEscritorio->id, 'nome' => 'Internet']);
        $contaHospedagem = Conta::firstOrCreate(['subcategoria_id' => $subInfra->id, 'nome' => 'Hospedagem']);
        $contaMobilia = Conta::firstOrCreate(['subcategoria_id' => $subInfra->id, 'nome' => 'Mobília']);
        $contaSimplesNacional = Conta::firstOrCreate(['subcategoria_id' => $subImpostos->id, 'nome' => 'Simples Nacional']);

        $fornecedorRossini = Fornecedor::firstOrCreate(['razao_social' => 'Rossini de Souza Santos']);
        $fornecedorHostinger = Fornecedor::firstOrCreate(['razao_social' => 'Hostinger'], ['nome_fantasia' => 'Hostinger']);
        $fornecedorClaude = Fornecedor::firstOrCreate(['razao_social' => 'Claude AI']);
        $fornecedorMercadoPago = Fornecedor::firstOrCreate(['razao_social' => 'Mercado Pago']);
        $fornecedorReceitaFederal = Fornecedor::firstOrCreate(['razao_social' => 'Receita Federal']);

        $despesas = [
            // Rossini (dev) — pago como fornecedor PJ, dividido em quinzenas + benefícios
            ['descricao' => '1ª Quinzena - Rossini', 'valor' => 1000.00, 'dia_vencimento' => 15, 'data_inicio' => '2026-02-15', 'conta_id' => $contaSalarios->id, 'fornecedor_id' => $fornecedorRossini->id],
            ['descricao' => '2ª Quinzena - Rossini', 'valor' => 1000.00, 'dia_vencimento' => 30, 'data_inicio' => '2026-04-30', 'data_fim' => '2027-03-30', 'conta_id' => $contaSalarios->id, 'fornecedor_id' => $fornecedorRossini->id],
            ['descricao' => 'Vale Alimentação - Rossini', 'valor' => 525.00, 'dia_vencimento' => 1, 'data_inicio' => '2026-04-01', 'conta_id' => $contaValeAlimentacao->id, 'fornecedor_id' => $fornecedorRossini->id],
            ['descricao' => 'Vale Transporte - Rossini', 'valor' => 204.00, 'dia_vencimento' => 4, 'data_inicio' => '2026-01-04', 'conta_id' => $contaValeTransporte->id, 'fornecedor_id' => $fornecedorRossini->id],

            // Infraestrutura / hospedagem
            ['descricao' => 'Hospedagem AlfaHome', 'valor' => 108.99, 'dia_vencimento' => 4, 'data_inicio' => '2026-06-04', 'data_fim' => '2027-05-04', 'conta_id' => $contaSistemaErp->id, 'fornecedor_id' => $fornecedorHostinger->id],
            ['descricao' => 'Hospedagem VPS AlfaGym', 'valor' => 108.99, 'dia_vencimento' => 29, 'data_inicio' => '2026-05-29', 'data_fim' => '2027-04-29', 'conta_id' => $contaSistemaErp->id, 'fornecedor_id' => $fornecedorHostinger->id],
            ['descricao' => 'Hospedagem VPS AlfaControl', 'valor' => 108.99, 'dia_vencimento' => 17, 'data_inicio' => '2026-06-17', 'data_fim' => '2027-05-17', 'conta_id' => $contaSistemaErp->id, 'fornecedor_id' => $fornecedorHostinger->id],
            ['descricao' => 'Hospedagem Hostinger (geral)', 'valor' => 108.99, 'dia_vencimento' => 5, 'data_inicio' => '2026-06-05', 'data_fim' => '2027-05-05', 'conta_id' => $contaSistemaErp->id, 'fornecedor_id' => $fornecedorHostinger->id],
            ['descricao' => 'Assinatura Claude AI', 'valor' => 560.00, 'dia_vencimento' => 22, 'data_inicio' => '2026-07-22', 'conta_id' => $contaHospedagem->id, 'fornecedor_id' => $fornecedorClaude->id],

            // Outras
            ['descricao' => 'Computador (10x)', 'valor' => 475.80, 'dia_vencimento' => 22, 'data_inicio' => '2026-04-22', 'data_fim' => '2027-03-22', 'conta_id' => $contaMobilia->id, 'fornecedor_id' => $fornecedorMercadoPago->id],
            ['descricao' => 'DAS - Simples Nacional', 'valor' => 86.90, 'dia_vencimento' => 23, 'data_inicio' => '2026-07-23', 'data_fim' => '2027-06-23', 'conta_id' => $contaSimplesNacional->id, 'fornecedor_id' => $fornecedorReceitaFederal->id],
        ];

        foreach ($despesas as $dados) {
            ContaFixaPagar::updateOrCreate(
                ['descricao' => $dados['descricao'], 'centro_custo_id' => $centroAlfa->id],
                array_merge($dados, ['centro_custo_id' => $centroAlfa->id, 'ativo' => true])
            );
        }
    }
}
