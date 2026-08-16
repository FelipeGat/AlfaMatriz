<?php

namespace Database\Seeders;

use App\Models\PrecoAtacado;
use App\Models\Sistema;
use Illuminate\Database\Seeder;

class SistemasPrecosSeeder extends Seeder
{
    /**
     * Sistemas da Alfa Tecnologia + tiers de atacado (Alfa -> Revenda).
     *
     * Modelo de venda: a Alfa licencia a plataforma pra revenda por um valor
     * FIXO por tier (com teto de unidades ativas), exceto onde marcado como
     * "metrado" (unidades_inclusas = 0 + valor_excedente_unidade preenchido),
     * que cobra direto por unidade ativa sem tier fechado.
     *
     * Baseado no levantamento de mercado + preços já praticados internamente
     * (05/08/2026).
     *
     * O AlfaSchool saiu daqui em 06/08/2026: o produto não existe. Ele estava
     * cadastrado sem cliente, sem tier e sem cobrança, e aparecia na tela de
     * Produtos como pendência permanente ("sem tier de atacado"), pedindo uma
     * configuração que nunca viria. Removê-lo só do banco não bastava — esta
     * carga o recriaria no próximo `db:seed`.
     */
    public function run(): void
    {
        $sistemas = [
            [
                'nome' => 'AlfaGym', 'slug' => 'alfagym', 'categoria' => 'saas',
                'unidade_cobranca' => 'academia ativa',
                // Paridade com o backfill da migration `adicionar_capacidades_aos_sistemas`,
                // que é quem vale em produção (o deploy roda migrate, não seed).
                'capacidades' => [
                    'sincroniza', 'sincroniza_licencas', 'provisiona_revenda',
                    'provisiona_cliente', 'exige_admin_no_cliente', 'gerencia_licenca',
                ],
                'tiers' => [
                    ['nome' => 'Start', 'preco_base' => 99.00, 'unidades_inclusas' => 10, 'limite_unidades' => 10, 'ordem' => 1],
                    ['nome' => 'Growth', 'preco_base' => 249.00, 'unidades_inclusas' => 30, 'limite_unidades' => 30, 'ordem' => 2],
                    ['nome' => 'Scale', 'preco_base' => 499.00, 'unidades_inclusas' => null, 'limite_unidades' => null, 'ordem' => 3],
                ],
            ],
            [
                'nome' => 'AlfaControl', 'slug' => 'alfacontrol', 'categoria' => 'saas',
                'unidade_cobranca' => 'condomínio ativo',
                // Fase 1 da implantação: a Matriz só lê. Revenda, cliente,
                // licença e módulo continuam sendo operados no painel do AlfaControl.
                //
                // Sem `sincroniza_licencas`: renovar no AlfaControl encadeia
                // licenças ativas em vez de substituir a anterior, e espelhar
                // esse retrato faria a Matriz faturar em cima do defeito.
                // Ligar quando a origem estiver corrigida.
                'capacidades' => ['sincroniza', 'sincroniza_modulos', 'sincroniza_uso'],
                'tiers' => [
                    ['nome' => 'Start', 'preco_base' => 99.00, 'unidades_inclusas' => 5, 'limite_unidades' => 5, 'ordem' => 1],
                    ['nome' => 'Growth', 'preco_base' => 299.00, 'unidades_inclusas' => 20, 'limite_unidades' => 20, 'ordem' => 2],
                    ['nome' => 'Scale', 'preco_base' => 699.00, 'unidades_inclusas' => null, 'limite_unidades' => null, 'ordem' => 3],
                ],
            ],
            [
                'nome' => 'AlfaHome', 'slug' => 'alfahome', 'categoria' => 'saas',
                'unidade_cobranca' => 'cliente ativo (família)',
                'tiers' => [
                    ['nome' => 'Único', 'preco_base' => 0, 'unidades_inclusas' => 0, 'valor_excedente_unidade' => 10.00, 'limite_unidades' => null, 'ordem' => 1],
                ],
            ],
            [
                'nome' => 'AlfaMed', 'slug' => 'alfamed', 'categoria' => 'saas',
                'unidade_cobranca' => 'vidas agregadas',
                'tiers' => [
                    ['nome' => 'Start', 'preco_base' => 400.00, 'unidades_inclusas' => 300, 'limite_unidades' => 300, 'ordem' => 1],
                    ['nome' => 'Growth', 'preco_base' => 900.00, 'unidades_inclusas' => 1000, 'limite_unidades' => 1000, 'ordem' => 2],
                    ['nome' => 'Scale', 'preco_base' => 1800.00, 'unidades_inclusas' => null, 'limite_unidades' => null, 'ordem' => 3],
                ],
            ],
            [
                'nome' => 'AlfaJornada', 'slug' => 'alfajornada', 'categoria' => 'saas',
                'unidade_cobranca' => 'funcionário ativo',
                // Metrado: a unidade é o funcionário ativo, que só a origem
                // sabe contar — daí o `sincroniza_uso` valer mais aqui do que
                // em qualquer outro sistema.
                'capacidades' => ['sincroniza', 'sincroniza_uso'],
                'tiers' => [
                    ['nome' => 'Único', 'preco_base' => 0, 'unidades_inclusas' => 0, 'valor_excedente_unidade' => 2.50, 'limite_unidades' => null, 'ordem' => 1],
                ],
            ],
            [
                'nome' => 'Gestor', 'slug' => 'gestor', 'categoria' => 'crm',
                'unidade_cobranca' => 'usuário',
                'tiers' => [
                    // Único tier, sem feature-gating: todos os módulos (financeiro, comercial,
                    // RH/ponto, obras, campanhas) liberados independente de quantos usuários.
                    ['nome' => 'Único (todos os módulos)', 'preco_base' => 800.00, 'unidades_inclusas' => 5, 'valor_excedente_unidade' => 40.00, 'limite_unidades' => null, 'ordem' => 1],
                ],
            ],
        ];

        foreach ($sistemas as $dados) {
            $tiers = $dados['tiers'];
            unset($dados['tiers']);

            $sistema = Sistema::updateOrCreate(['slug' => $dados['slug']], $dados);

            foreach ($tiers as $tier) {
                PrecoAtacado::updateOrCreate(
                    ['sistema_id' => $sistema->id, 'revenda_id' => null, 'nome' => $tier['nome']],
                    array_merge($tier, ['vigencia_inicio' => now()->toDateString()])
                );
            }
        }
    }
}
