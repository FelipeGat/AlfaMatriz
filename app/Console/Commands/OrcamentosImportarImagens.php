<?php

namespace App\Console\Commands;

use App\Models\Orcamento;
use App\Services\Orcamentos\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * As imagens das propostas antigas, tiradas dos PDFs enviados, de volta nos orçamentos
 * importados (Felipe, 20/10/2026: "com imagens que já existem nos PDFs que você importou").
 * O levantamento traz, por imagem, o PDF de origem, o arquivo extraído, a legenda e a
 * página onde ela estava; a chave do orçamento é o arquivo de origem, como na importação
 * do histórico. Orçamento que já tem imagens fica como está: rodar de novo não duplica.
 */
class OrcamentosImportarImagens extends Command
{
    protected $signature = 'orcamentos:importar-imagens {json : o levantamento (imagens-import.json)} {pasta : a pasta com as imagens extraídas}';

    protected $description = 'Coloca nas propostas importadas as imagens tiradas dos PDFs originais';

    public function handle(): int
    {
        $lista = json_decode((string) @file_get_contents((string) $this->argument('json')), true);
        if (! is_array($lista)) {
            $this->error('Levantamento ilegível.');

            return self::FAILURE;
        }
        $pasta = rtrim((string) $this->argument('pasta'), '/');
        foreach (collect($lista)->groupBy('arquivo') as $arquivo => $imagens) {
            $o = Orcamento::where('origem_arquivo', $arquivo)->first();
            if (! $o) {
                $this->line("sem orçamento: {$arquivo}");

                continue;
            }
            if ($o->imagensDoCorpo()) {
                $this->line("já tem imagens: {$o->codigo()} {$arquivo}");

                continue;
            }
            $lugares = $o->lugaresDeImagem();
            $novas = [];
            foreach ($imagens as $i) {
                $origem = $pasta.'/'.basename((string) $i['imagem']);
                if (! is_file($origem)) {
                    continue;
                }
                // "livre:no quiosque a retirada" (o título da página, sem acento) é a seção livre de mesmo título.
                $onde = str_starts_with((string) $i['onde'], 'livre:') ? 'livre:'.Str::slug(substr((string) $i['onde'], 6)) : (string) $i['onde'];
                $destino = Pdf::PASTA_IMAGENS."/{$o->ano}-".str_pad((string) $o->numero, 3, '0', STR_PAD_LEFT)."-r{$o->revisao}-".basename((string) $i['imagem']);
                Storage::disk(Pdf::DISCO)->put($destino, (string) file_get_contents($origem));
                $novas[] = ['arquivo' => $destino, 'legenda' => mb_substr(trim((string) ($i['legenda'] ?? '')), 0, 300), 'onde' => array_key_exists($onde, $lugares) ? $onde : 'galeria'];
            }
            $o->timestamps = false;
            $o->forceFill(['imagens' => $novas ?: null])->save();
            $this->line("{$o->codigo()}: ".count($novas)." imagem(ns) — {$arquivo}");
        }

        return self::SUCCESS;
    }
}
