<?php

namespace App\Console\Commands;

use App\Models\VigiaIgnorado;
use Illuminate\Console\Command;

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
 * nem avisa. Sem tela de administração por enquanto (decisão da #219): a
 * lista é curta e quem mexe nela é quem administra o servidor.
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

        if ($this->option('remover')) {
            $quantos = VigiaIgnorado::where('padrao', $padrao)->where('sistema_id', $sistema?->id)->delete();
            $this->info($quantos > 0 ? 'Padrão removido da lista.' : 'Esse padrão não estava na lista'.($sistema ? " de {$sistema->nome}" : ' global').'.');

            return self::SUCCESS;
        }

        $item = new VigiaIgnorado(['padrao' => $padrao, 'sistema_id' => $sistema?->id]);

        // A regex é testada na entrada: inválida, ela nunca casaria, e o erro
        // que alguém quis calar continuaria abrindo tarefa sem ninguém saber
        // por quê.
        if ($item->ehRegex() && @preg_match($padrao.'iu', '') === false) {
            $this->error('Expressão regular inválida: '.$padrao);

            return self::FAILURE;
        }

        $item->save();
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
