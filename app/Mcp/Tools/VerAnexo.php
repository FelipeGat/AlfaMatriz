<?php

namespace App\Mcp\Tools;

use App\Models\TarefaAnexo;
use App\Models\User;
use App\Services\MiniaturaDeAnexo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * O anexo da tarefa, para o agente OLHAR — a porta do `TarefaController::verAnexo`.
 *
 * Sem ela, `ver_tarefa` dizia "há um print" e parava aí: o agente sabia que a
 * prova existia e não tinha como vê-la. Em 01/10/2026 o dono pediu "analise os
 * anexos" e ouviu que não havia anexo nenhum — havia, só não por esta porta.
 *
 * Figura volta como figura, reduzida a um lado em que o texto de uma captura
 * de tela ainda se lê. Texto, log e CSV voltam como texto, com teto: um log de
 * 12 MB inteiro na conversa custaria caro e não seria lido. PDF e planilha
 * ficam de fora, dito com todas as letras — devolver bytes que o agente não
 * sabe abrir seria fingir que entregou.
 */
class VerAnexo extends Ferramenta
{
    /** O lado em que uma captura de tela continua legível para o modelo. */
    private const LADO_LEGIVEL = 1568;

    /** Até quanto o ORIGINAL de uma figura segue como está, quando não dá para reduzir. */
    private const ORIGINAL_ATE = 4 * 1024 * 1024;

    /** Quanto de um arquivo de texto entra na conversa. */
    private const TEXTO_ATE = 60_000;

    protected string $name = 'ver_anexo';

    protected string $title = 'Ver anexo';

    protected string $description = 'Abre um anexo de tarefa pelo número que ver_tarefa mostra ("anexo 12"). Imagem volta como imagem, em tamanho legível; texto, log e CSV voltam como texto. PDF e planilha não são legíveis por aqui.';

    // A mesma porta da rota `tarefas.anexos.ver`: quem enxerga o quadro vê a prova.
    protected array $permissao = ['tarefas', 'ler'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'anexo' => $schema->integer()->required()->min(1)
                ->description('O número do anexo, como aparece em ver_tarefa.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate(['anexo' => 'required|integer|min:1']);

        $anexo = TarefaAnexo::find($dados['anexo']);

        if (! $anexo) {
            return Response::error('Não há anexo '.$dados['anexo'].'. Veja os números em ver_tarefa.');
        }

        $disco = Storage::disk('public');

        if (! $disco->exists($anexo->caminho)) {
            return Response::error('O anexo '.$anexo->id.' ('.$anexo->nome_original.') não foi encontrado no servidor.');
        }

        if ($anexo->eh_imagem) {
            return $this->figura($anexo);
        }

        if ($this->ehTexto($anexo)) {
            return $this->texto($anexo);
        }

        return Response::error('O anexo '.$anexo->id.' ('.$anexo->nome_original.', '.$anexo->tamanho_formatado
            .') é um arquivo que não consigo ler por aqui. Só imagem e texto. Peça o trecho colado ou uma captura de tela.');
    }

    private function figura(TarefaAnexo $anexo): Response
    {
        $reduzida = MiniaturaDeAnexo::reduzida($anexo->caminho, self::LADO_LEGIVEL);

        if ($reduzida !== null) {
            return Response::image($reduzida, 'image/jpeg');
        }

        // Null é "não precisou" (a figura já é pequena) ou "não pôde" (não
        // coube na memória, arquivo que o GD não abre). Nos dois casos o
        // original serve — desde que não seja ele o problema.
        if ($anexo->tamanho <= self::ORIGINAL_ATE) {
            return Response::image(Storage::disk('public')->get($anexo->caminho), $anexo->mime ?: 'image/png');
        }

        return Response::error('A imagem do anexo '.$anexo->id.' ('.$anexo->tamanho_formatado
            .') é grande demais para abrir por aqui e não pôde ser reduzida.');
    }

    private function ehTexto(TarefaAnexo $anexo): bool
    {
        return str_starts_with($anexo->mime ?? '', 'text/')
            || in_array($anexo->mime, ['application/json', 'application/csv'], true);
    }

    private function texto(TarefaAnexo $anexo): Response
    {
        $conteudo = (string) Storage::disk('public')->get($anexo->caminho);

        // Log de servidor antigo costuma vir em Latin-1, e JSON inválido
        // derrubaria a resposta inteira do MCP.
        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1');
        }

        $cortado = mb_strlen($conteudo) > self::TEXTO_ATE;

        return Response::text(
            'Anexo '.$anexo->id.': '.$anexo->nome_original.' ('.$anexo->tamanho_formatado.")\n\n"
            .mb_substr($conteudo, 0, self::TEXTO_ATE)
            .($cortado ? "\n\n[cortado: só os primeiros ".number_format(self::TEXTO_ATE, 0, ',', '.').' caracteres]' : '')
        );
    }
}
