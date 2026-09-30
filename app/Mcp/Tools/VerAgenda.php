<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class VerAgenda extends Ferramenta
{
    /** Uma faixa maior que isso é relatório, não agenda. */
    private const DIAS_NO_MAXIMO = 62;

    private const DIAS_DA_SEMANA = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    protected string $name = 'ver_agenda';

    protected string $title = 'Ver agenda';

    protected string $description = 'Os prazos de tarefa e os compromissos do time numa faixa de dias, agrupados por dia. Sem datas, mostra de hoje a sete dias. Filtre por pessoas pelo nome.';

    protected array $permissao = ['agenda', 'ler'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'de' => $schema->string()->description('Primeiro dia, AAAA-MM-DD (padrão: hoje).'),
            'ate' => $schema->string()->description('Último dia, AAAA-MM-DD (padrão: seis dias depois de "de").'),
            'pessoas' => $schema->array()->items($schema->string())
                ->description('Só o que envolve estas pessoas, pelo nome. Vazio é todo mundo.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate([
            'de' => 'nullable|date',
            'ate' => 'nullable|date|after_or_equal:de',
            'pessoas' => 'nullable|array',
            'pessoas.*' => 'string|max:255',
        ]);

        $de = filled($dados['de'] ?? null) ? Carbon::parse($dados['de'])->startOfDay() : today();
        $ate = filled($dados['ate'] ?? null) ? Carbon::parse($dados['ate'])->startOfDay() : $de->copy()->addDays(6);

        if ($de->diffInDays($ate) > self::DIAS_NO_MAXIMO) {
            return Response::error('A faixa vai até '.self::DIAS_NO_MAXIMO.' dias. Peça um pedaço de cada vez.');
        }

        $ids = [];

        foreach ($dados['pessoas'] ?? [] as $nome) {
            $pessoa = $this->pessoa($nome, $usuario);

            if (is_string($pessoa)) {
                return Response::error($pessoa);
            }

            $ids[] = $pessoa->id;
        }

        $itens = app(AgendaService::class)->itens($de, $ate, $ids);

        if ($itens->isEmpty()) {
            return Response::text('Nada marcado entre '.$de->format('d/m').' e '.$ate->format('d/m').'.');
        }

        $dias = $itens->groupBy('data')->map(function ($doDia, string $data) {
            $dia = Carbon::parse($data);

            $linhas = collect($doDia)->map(fn (array $item) => '- '
                .($item['tipo'] === 'tarefa' ? 'Tarefa #' : 'Compromisso #').$item['id']
                .' · '.$item['titulo']
                .(filled($item['meta']) ? ' ('.$item['meta'].')' : '')
                .($item['tipo'] === 'tarefa' && $item['rotulo'] !== 'Tarefa' ? ' · '.str_replace('Tarefa · ', '', $item['rotulo']) : '')
                .(($item['atrasada'] ?? false) ? ' · ATRASADA' : ''));

            return self::DIAS_DA_SEMANA[$dia->dayOfWeek].' '.$dia->format('d/m/Y')."\n".$linhas->implode("\n");
        });

        return Response::text($dias->implode("\n\n"));
    }
}
