<?php

namespace App\Services\Suporte;

use App\Models\SuporteConversa;
use App\Models\SuporteMensagem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * A foto e o áudio que o cliente mandou, para o Pedro ver e ouvir
 * (docs/specs/suporte-pedro-whatsapp/estagiario.md, Fase 2). A mídia vem do
 * Gestor, que a baixou da Meta — o token da Meta não sai de lá —, e fica aqui
 * no disco privado. Vai ao runner em base64, junto com o pedido.
 */
class Midias
{
    /** Quantas fotos e áudios recentes vão junto de cada pedido. */
    public const FOTOS = 3;

    public const AUDIOS = 3;

    /** Vídeo é pesado (quadros e fala): só o mais recente ainda não visto. */
    public const VIDEOS = 1;

    /** Acima disso a mídia fica para a equipe; é o mesmo teto do Gestor. */
    public const MAXIMO = 16 * 1024 * 1024;

    private const EXTENSOES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'm4a', 'audio/amr' => 'amr',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'video/quicktime' => 'mov'];

    public function __construct(private CanalWhatsApp $canal) {}

    /**
     * As fotos, os áudios e o vídeo recentes do cliente nesta conversa, prontos
     * para o runner. Áudio e vídeo só se ainda não foram transcritos ou vistos.
     *
     * @return array{imagens: list<array{id: int, mime: string, base64: string}>, audios: list<array{id: int, extensao: string, base64: string}>, videos: list<array{id: int, extensao: string, base64: string}>}
     */
    public function paraOPedido(SuporteConversa $c): array
    {
        $recentes = $c->mensagens()->reorder()->where('direcao', 'entrada')->whereIn('tipo', ['image', 'audio', 'video'])
            ->orderByDesc('id')->limit(15)->get();
        $imagens = [];
        foreach ($recentes->where('tipo', 'image')->take(self::FOTOS) as $m) {
            if ($bytes = $this->bytes($m)) {
                $imagens[] = ['id' => $m->id, 'mime' => (string) $m->midia_mime, 'base64' => base64_encode($bytes)];
            }
        }
        $audios = [];
        foreach ($recentes->where('tipo', 'audio')->whereNull('transcricao')->take(self::AUDIOS) as $m) {
            if ($bytes = $this->bytes($m)) {
                $audios[] = ['id' => $m->id, 'extensao' => self::EXTENSOES[$m->midia_mime] ?? 'ogg', 'base64' => base64_encode($bytes)];
            }
        }

        $videos = [];
        foreach ($recentes->where('tipo', 'video')->whereNull('transcricao')->take(self::VIDEOS) as $m) {
            if ($bytes = $this->bytes($m)) {
                $videos[] = ['id' => $m->id, 'extensao' => self::EXTENSOES[$m->midia_mime] ?? 'mp4', 'base64' => base64_encode($bytes)];
            }
        }

        return ['imagens' => array_reverse($imagens), 'audios' => array_reverse($audios), 'videos' => $videos];
    }

    /** Os bytes da mídia: do disco, ou buscados no Gestor na primeira vez. Falha = null (o Pedro segue sem ela). */
    public function bytes(SuporteMensagem $m): ?string
    {
        if ($m->midia_path && Storage::disk('local')->exists($m->midia_path)) {
            return Storage::disk('local')->get($m->midia_path);
        }
        if (! $this->canal->viaGestor() || ! $m->wamid) {
            return null;
        }
        try {
            $r = Http::withToken((string) config('services.suporte_gestor.token'))->timeout(30)
                ->get(rtrim((string) config('services.suporte_gestor.url'), '/').'/api/integracao/matriz/whatsapp/midia/'.rawurlencode($m->wamid));
            if (! $r->successful() || strlen($r->body()) === 0 || strlen($r->body()) > self::MAXIMO) {
                return null;
            }
            $mime = strtolower(trim(explode(';', (string) $r->header('Content-Type'))[0]));
            if (! isset(self::EXTENSOES[$mime])) {
                return null;
            }
            $caminho = "suporte/{$m->conversa_id}/{$m->id}.".self::EXTENSOES[$mime];
            Storage::disk('local')->put($caminho, $r->body());
            $m->update(['midia_path' => $caminho, 'midia_mime' => $mime]);

            return $r->body();
        } catch (\Throwable $e) {
            Log::warning('[Pedro] mídia não buscada no Gestor', ['mensagem' => $m->id, 'erro' => $e::class]);

            return null;
        }
    }
}
