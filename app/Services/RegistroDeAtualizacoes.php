<?php

namespace App\Services;

use App\Models\Atualizacao;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Guarda o changelog que foi ao Telegram (#225).
 *
 * Quem chama é o `deploy/publicar-changelog.sh`, logo depois de o Telegram
 * aceitar todas as partes, e o mesmo script com `--so-registrar` para os
 * changelogs antigos. O sistema e a data saem do cabeçalho que todo changelog
 * já tem (`📋 AlfaMatriz — Changelog 03/10/2026`): o script não precisa saber
 * de nada além do arquivo, e um changelog sem cabeçalho é recusado em vez de
 * cair num sistema chutado.
 */
class RegistroDeAtualizacoes
{
    /**
     * @param  list<int>  $tarefas  números das tarefas (os `T-N` dos commits)
     * @return array{atualizacao: Atualizacao, nova: bool}
     *
     * @throws InvalidArgumentException cabeçalho sem sistema ou data, ou sistema desconhecido
     */
    public function registrar(
        string $texto,
        ?string $versao,
        array $tarefas,
        ?User $autor,
        string $origem = 'script',
        ?string $arquivo = null,
    ): array {
        $texto = trim(str_replace("\r\n", "\n", $texto));

        if ($texto === '') {
            throw new InvalidArgumentException('O changelog está vazio.');
        }

        ['sistema' => $nome, 'data' => $data, 'titulo' => $titulo] = self::cabecalho($texto);

        $sistema = $this->sistema($nome);
        $chave = hash('sha256', $texto);

        return DB::transaction(function () use ($sistema, $chave, $data, $versao, $titulo, $texto, $origem, $arquivo, $autor, $tarefas) {
            $atualizacao = Atualizacao::query()->where('sistema_id', $sistema->id)->where('chave', $chave)->first();
            $nova = $atualizacao === null;

            $atualizacao ??= Atualizacao::create([
                'sistema_id' => $sistema->id,
                'chave' => $chave,
                'data' => $data,
                'titulo' => $titulo,
                'texto' => $texto,
                'origem' => $origem,
                'arquivo' => $arquivo,
                'registrado_por_id' => $autor?->id,
            ]);

            // Repetir o registro só ACRESCENTA: a versão que faltava na
            // primeira vez (o changelog sai antes da tag) entra na segunda, e
            // nada do que já estava é apagado.
            if (filled($versao) && blank($atualizacao->versao)) {
                $atualizacao->update(['versao' => trim($versao)]);
            }

            $ids = Tarefa::withTrashed()->whereIn('id', array_unique(array_map('intval', $tarefas)))->pluck('id');
            $atualizacao->tarefas()->syncWithoutDetaching($ids);

            return ['atualizacao' => $atualizacao->fresh(), 'nova' => $nova];
        });
    }

    /**
     * Sistema, data e subtítulo, do cabeçalho do changelog.
     *
     * @return array{sistema: string, data: Carbon, titulo: ?string}
     */
    public static function cabecalho(string $texto): array
    {
        $linhas = array_values(array_filter(
            array_map(fn ($linha) => trim(html_entity_decode(strip_tags($linha))), explode("\n", $texto)),
            fn ($linha) => $linha !== '',
        ));

        if (! preg_match('/^(?:📋\s*)?(.+?)\s+[—–-]\s+Changelog\s+(\d{2}\/\d{2}\/\d{4})/u', $linhas[0] ?? '', $m)) {
            throw new InvalidArgumentException(
                'A primeira linha precisa ser o cabeçalho "📋 <Sistema> — Changelog DD/MM/AAAA".'
            );
        }

        try {
            $data = Carbon::createFromFormat('d/m/Y', $m[2])->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Data inválida no cabeçalho: '.$m[2].'.');
        }

        // O subtítulo é a linha em itálico logo abaixo do cabeçalho, quando há.
        $segunda = explode("\n", $texto)[1] ?? '';
        $titulo = preg_match('/^\s*<i>(.+)<\/i>\s*$/u', $segunda, $t)
            ? Str::limit(trim(html_entity_decode(strip_tags($t[1]))), 250)
            : null;

        return ['sistema' => trim($m[1]), 'data' => $data, 'titulo' => $titulo];
    }

    private function sistema(string $nome): Sistema
    {
        $sistema = Sistema::query()
            ->where(fn ($q) => $q->where('nome', $nome)->orWhere('slug', Str::slug($nome)))
            ->first();

        if (! $sistema) {
            throw new InvalidArgumentException('Não há sistema "'.$nome.'" cadastrado no AlfaMatriz.');
        }

        return $sistema;
    }
}
