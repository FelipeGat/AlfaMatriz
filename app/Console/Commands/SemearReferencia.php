<?php

namespace App\Console\Commands;

use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;

/**
 * Aplica as cargas de REFERÊNCIA — o dado que o código pressupõe existir e que
 * nenhuma tela cadastra.
 *
 * Existe porque o deploy roda `migrate`, nunca `db:seed`: permissão é dado
 * semeado, não migrado. Produção foi semeada uma única vez, na implantação, e
 * cada recurso criado depois ficou para trás. O sintoma não é erro — o item
 * some do menu e a rota devolve 403, indistinguível de "sem permissão", até
 * alguém perguntar onde foi parar a tela. Foi assim com `faturamento`, `leads`
 * e `tarefas`, e com o perfil `revenda`.
 *
 * Roda a cada publicação, então só entra aqui seeder que aguente rodar sempre:
 * idempotente, aditivo e sem sobrescrever o que a operação editou pela tela.
 */
class SemearReferencia extends Command
{
    protected $signature = 'alfa:semear-referencia {--dry-run : Só lista o que rodaria, sem tocar no banco}';

    protected $description = 'Aplica as cargas de referência idempotentes (permissões e perfis)';

    /**
     * A lista é curta de propósito. Cada entrada é uma promessa de que rodar de
     * novo em cima de produção não estraga nada.
     *
     * Ficam DE FORA, e cada uma por um motivo diferente:
     *
     * - DadosIniciaisSeeder: recria a conta de administrador a partir de
     *   ADMIN_EMAIL. Se a variável ficar para trás de uma troca de e-mail, o
     *   deploy ressuscita o acesso antigo.
     * - SistemasPrecosSeeder: carrega preço de atacado. A tela de Produtos
     *   edita esses valores; reaplicar a carga desfaria o reajuste no próximo
     *   deploy. Quando um campo desse seeder precisa valer em produção, o
     *   caminho é backfill em migration — foi o que se fez com `capacidades`.
     * - DespesasAlfaSeeder: retrato manual do Gestor.Alfa, dado de negócio.
     *
     * @var array<int, class-string<Seeder>>
     */
    private const SEEDERS = [
        PerfilPermissaoSeeder::class,
    ];

    public function handle(): int
    {
        $seco = (bool) $this->option('dry-run');

        foreach (self::SEEDERS as $seeder) {
            $nome = class_basename($seeder);

            if ($seco) {
                $this->line("  {$nome}: rodaria");

                continue;
            }

            $this->callSilent('db:seed', ['--class' => $seeder, '--force' => true]);
            $this->line("  {$nome}: aplicado");
        }

        $this->info('Cargas de referência em dia.');

        return self::SUCCESS;
    }
}
