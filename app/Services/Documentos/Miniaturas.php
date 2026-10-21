<?php

namespace App\Services\Documentos;

use App\Models\Documento;
use Illuminate\Support\Facades\Storage;

/**
 * Miniaturas dos documentos (19/10/2026). Imagem, o próprio servidor reduz com
 * o GD na primeira vez que alguém olha; PDF e vídeo, a máquina do dono gera
 * (primeira página, um quadro) e envia — o servidor não tem poppler nem ffmpeg.
 * Toda miniatura é REGRAVADA aqui como JPEG pequeno: o que se serve nunca é o
 * arquivo que chegou.
 */
class Miniaturas
{
    public const LADO = 480;

    /** Imagem acima disso não vira miniatura no servidor (memória do GD). */
    public const MAX_IMAGEM = 25 * 1024 * 1024;

    public const IMAGENS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** O que a máquina gera: PDF pela primeira página, vídeo por um quadro. */
    public const DA_MAQUINA = ['pdf', 'mp4', 'mov', 'webm', 'm4v'];

    /** O arquivo da miniatura, gerando a de imagem se ainda não existe. Null se não há. */
    public function arquivo(Documento $doc): ?string
    {
        $disco = Storage::disk(Documento::DISCO);
        if ($doc->miniatura === 'ok' && $disco->exists($doc->caminhoMiniatura())) {
            return $doc->caminhoMiniatura();
        }
        if ($doc->miniatura === null && in_array($doc->extensao, self::IMAGENS, true) && $doc->tamanho <= self::MAX_IMAGEM && $disco->exists($doc->arquivo)) {
            $ok = $this->gravar($doc, (string) file_get_contents($disco->path($doc->arquivo)));
            $doc->update(['miniatura' => $ok ? 'ok' : 'sem']);

            return $ok ? $doc->caminhoMiniatura() : null;
        }

        return null;
    }

    /** A miniatura de PDF ou vídeo que a máquina gerou. */
    public function receber(Documento $doc, ?string $bytes): bool
    {
        $ok = $bytes !== null && $this->gravar($doc, $bytes);
        $doc->update(['miniatura' => $ok ? 'ok' : 'sem']);

        return $ok;
    }

    private function gravar(Documento $doc, string $bytes): bool
    {
        $limite = ini_get('memory_limit');
        ini_set('memory_limit', '512M');
        try {
            $img = @imagecreatefromstring($bytes);
            if (! $img) {
                return false;
            }
            [$w, $h] = [imagesx($img), imagesy($img)];
            $escala = min(1, self::LADO / max($w, $h));
            $mini = imagescale($img, max(1, (int) round($w * $escala)), max(1, (int) round($h * $escala)));
            imagedestroy($img);
            if (! $mini) {
                return false;
            }
            // Transparência vira branco (PNG de logo sobre fundo preto não fica ilegível).
            $fundo = imagecreatetruecolor(imagesx($mini), imagesy($mini));
            imagefill($fundo, 0, 0, imagecolorallocate($fundo, 255, 255, 255));
            imagecopy($fundo, $mini, 0, 0, 0, 0, imagesx($mini), imagesy($mini));
            ob_start();
            imagejpeg($fundo, null, 78);
            $jpeg = (string) ob_get_clean();
            imagedestroy($mini);
            imagedestroy($fundo);
            Storage::disk(Documento::DISCO)->put($doc->caminhoMiniatura(), $jpeg);

            return $jpeg !== '';
        } catch (\Throwable) {
            return false;
        } finally {
            ini_set('memory_limit', (string) $limite);
        }
    }
}
