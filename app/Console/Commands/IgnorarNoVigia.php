<?php

namespace App\Console\Commands;

use App\Models\VigiaIgnorado;
use App\Services\Vigia\IgnoradosDoVigia;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * A lista do que o vigia de logs não acompanha (#219).
 *
 *   alfa:vigia-ignorar                                   lista
 *   alfa:vigia-ignorar "Broken pipe"                     ignora em todos os sistemas
 *   alfa:vigia-ignorar "/Redis.*timed out/" --sistema=alfagym
 *   alfa:vigia-ignorar "Broken pipe" --remover
 *
 * Texto casa por trecho, sem diferença de maiúsculas; entre barras é
 * expressão regular. O erro ignorado continua CONTADO — só não abre tarefa
 * nem avisa. A mesma lista se mexe pela aba Erros da tela de Manutenção
 * (#224); a regra das duas portas mora em `IgnoradosDoVigia`.
 */
class IgnorarNoVigia extends Command
{
    protected $signature = 'alfa:vigia-ignorar
                            {padrao? : Texto ou /regex/ a ignorar. Sem ele, lista o que já está ignorado}
                            {--sistema= : Slug, nome ou id do sistema. Sem ele, vale para todos}
                            {--remover : Tira o padrão da lista em vez de acrescentar}';

    protected $description = 'Acrescenta, remove ou lista os padrões de erro que o vigia de logs ignora';

    public function handle(): int
    {
        $sistema = null;

        if (filled($this->option('sistema'))) {
            $sistema = EmitirTokenDoVigia::sistema((string) $this->option('sistema'));

            if (! $sistema) {
                $this->error("Sistema '{$this->option('sistema')}' não encontrado.");

                return self::FAILURE;
            }
        }

        $padrao = trim((string) $this->argument('padrao'));

        if ($padrao === '') {
            return $this->listar();
        }

        $lista = app(IgnoradosDoVigia::class);

        if ($this->option('remover')) {
            $quantos = $lista->remover($padrao, $sistema);
            $this->info($quantos > 0 ? 'Padrão removido da lista.' : 'Esse padrão não estava na lista'.($sistema ? " de {$sistema->nome}" : ' global').'.');

            return self::SUCCESS;
        }

        try {
            $item = $lista->ignorar($padrao, $sistema);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Ignorando '.($item->ehRegex() ? 'a regex ' : 'o texto ').'«'.$padrao.'» '.($sistema ? "no {$sistema->nome}" : 'em todos os sistemas').'.');

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $itens = VigiaIgnorado::with('sistema')->orderBy('sistema_id')->orderBy('padrao')->get();

        if ($itens->isEmpty()) {
            $this->info('O vigia não ignora nada.');

            return self::SUCCESS;
        }

        $this->table(['Padrão', 'Tipo', 'Sistema'], $itens->map(fn (VigiaIgnorado $i) => [
            $i->padrao,
            $i->ehRegex() ? 'regex' : 'texto',
            $i->sistema?->nome ?? 'todos',
        ])->all());

        return self::SUCCESS;
    }
}
