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
            'imagens' => $this->imagens($o, $previa),
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

    /** A pasta, no disco do módulo, das imagens do corpo da proposta. */
    public const PASTA_IMAGENS = 'orcamentos/imagens';

    /**
     * As imagens do corpo agrupadas pelo lugar onde entram, cada uma com o endereço que
     * o Chromium abre (do disco) ou o navegador (pela rota, na prévia). Arquivo que sumiu
     * do disco não entra, em vez de deixar um quadro vazio na página.
     *
     * @return array<string, list<array{src: string, legenda: string, retrato: bool}>>
     */
    public function imagens(Orcamento $o, bool $previa): array
    {
        $disco = Storage::disk(self::DISCO);
        $r = [];
        foreach ($o->imagensDoCorpo() as $n => $i) {
            if (! $disco->exists($i['arquivo'])) {
                continue;
            }
            // Em pé (tela de celular, folheto) entra lado a lado; deitada ocupa a largura da folha.
            [$largura, $altura] = @getimagesize($disco->path($i['arquivo'])) ?: [1, 0];
            $r[$i['onde']][] = ['src' => $previa && $o->id ? route('orcamentos.imagem', [$o->id, $n]) : 'file://'.$disco->path($i['arquivo']),
                'legenda' => $i['legenda'], 'retrato' => $altura > $largura * 1.15];
        }

        return $r;
    }

    /** O PDF atual: o guardado, se o orçamento não mudou depois dele; senão gera de novo. */
    public function atual(Orcamento $o): string
    {
        $disco = Storage::disk(self::DISCO);
        if ($o->pdf_arquivo && $o->pdf_gerado_em && $o->updated_at && $o->pdf_gerado_em->gte($o->updated_at)
            && $o->pdf_gerado_em->timestamp >= self::versaoDoModelo() && $disco->exists($o->pdf_arquivo)) {
            return $o->pdf_arquivo;
        }

        return $this->gerar($o);
    }

    /** Quando o modelo mudou por último: PDF gerado antes disso saiu no desenho velho. */
    public static function versaoDoModelo(): int
    {
        return (int) collect([resource_path('views/orcamentos/pdf.blade.php'), ...glob(resource_path('views/orcamentos/pdf/*.blade.php')) ?: []])
            ->map(fn ($f) => @filemtime($f))->max();
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
