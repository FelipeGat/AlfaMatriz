<?php

namespace App\Services\Orcamentos;

use App\Models\Orcamento;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * O PDF padrão das propostas, sempre como VixSoftHouse (docs/specs/orcamentos-vixcard): um modelo só
 * (`orcamentos.pdf`), impresso pelo Chromium sem interface — o mesmo motor das
 * propostas feitas à mão até aqui, com flexbox e gradiente, sem as gambiarras
 * de tabela do dompdf do Gestor. A prévia da tela é o MESMO Blade, então o
 * que se vê editando é o que sai impresso.
 */
class Pdf
{
    public const DISCO = 'central_ia';

    /** O HTML do orçamento. Na prévia as fontes vêm pela URL; no PDF, do disco. */
    public function html(Orcamento $o, bool $previa = false, ?string $capaDataUri = null): string
    {
        return view('orcamentos.pdf', [
            'o' => $o,
            'previa' => $previa,
            'fontes' => $previa ? asset('fonts') : 'file://'.public_path('fonts'),
            'capa' => $capaDataUri ?? $this->capa($o, $previa),
            'ajustes' => app(Ajustes::class),
        ])->render();
    }

    /** A pasta, no disco do módulo, das fotos de capa. */
    public const PASTA_CAPAS = 'orcamentos/capas';

    /**
     * A foto da capa: a do orçamento, senão a capa padrão dos ajustes, senão nenhuma
     * (fica o degradê). Na prévia vai pela rota do Matriz; no PDF, do disco.
     */
    public function capa(Orcamento $o, bool $previa): ?string
    {
        $disco = Storage::disk(self::DISCO);
        if ($o->capa_imagem && $disco->exists($o->capa_imagem)) {
            return $previa && $o->id ? route('orcamentos.capa', $o->id) : 'file://'.$disco->path($o->capa_imagem);
        }
        $padrao = app(Ajustes::class)->get('capa_padrao');
        if ($padrao && $disco->exists($padrao)) {
            return $previa ? route('orcamentos.capa-padrao') : 'file://'.$disco->path($padrao);
        }

        return null;
    }

    /** O PDF atual: o guardado, se o orçamento não mudou depois dele; senão gera de novo. */
    public function atual(Orcamento $o): string
    {
        $disco = Storage::disk(self::DISCO);
        if ($o->pdf_arquivo && $o->pdf_gerado_em && $o->updated_at && $o->pdf_gerado_em->gte($o->updated_at) && $disco->exists($o->pdf_arquivo)) {
            return $o->pdf_arquivo;
        }

        return $this->gerar($o);
    }

    public function gerar(Orcamento $o): string
    {
        $disco = Storage::disk(self::DISCO);
        $relativo = "orcamentos/{$o->ano}/".$o->nomeDoPdf();
        $tmp = sys_get_temp_dir().'/orcamento-'.$o->id.'-'.bin2hex(random_bytes(4));
        @mkdir($tmp, 0700, true);
        try {
            file_put_contents("{$tmp}/proposta.html", $this->html($o->loadMissing('itens')));
            $this->imprimir("{$tmp}/proposta.html", "{$tmp}/proposta.pdf");
            $pdf = (string) @file_get_contents("{$tmp}/proposta.pdf");
            if (! str_starts_with($pdf, '%PDF')) {
                throw new RuntimeException('O Chromium não gerou o PDF.');
            }
            $disco->put($relativo, $pdf);
        } finally {
            // Inteira, com o perfil que o Chromium cria dentro: senão sobra uma pasta em /tmp a cada PDF.
            File::deleteDirectory($tmp);
        }
        // Sem mexer no updated_at: é ele que diz se o PDF ficou velho.
        $o->timestamps = false;
        $o->forceFill(['pdf_arquivo' => $relativo, 'pdf_gerado_em' => now()])->save();
        $o->timestamps = true;

        return $relativo;
    }

    /** Separado para o teste trocar: no servidor é o Chromium do sistema (pacote `chromium`). */
    protected function imprimir(string $html, string $pdf): void
    {
        $perfil = dirname($html).'/perfil';
        $p = new Process([config('services.orcamentos.chromium', '/usr/bin/chromium'), '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
            '--no-pdf-header-footer', '--hide-scrollbars', '--virtual-time-budget=4000', "--user-data-dir={$perfil}", "--print-to-pdf={$pdf}", 'file://'.$html],
            null, ['HOME' => dirname($html)], null, 90);
        $p->run();
        if (! is_file($pdf)) {
            throw new RuntimeException('O Chromium falhou: '.mb_substr(trim($p->getErrorOutput()), 0, 300));
        }
    }
}
