<?php

namespace App\Services\Secretario;

use App\Models\CentralIaTelegramBot;
use App\Models\CentralIaTelegramVinculo;
use App\Models\Notificacao;
use App\Models\SecretarioReuniao;
use App\Models\SecretarioReuniaoTrecho;
use App\Models\User;
use App\Services\CentralIa\Eventos;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Do áudio à transcrição (docs/specs/secretario-reunioes). O áudio fica no
 * disco PRIVADO só até o runner da pessoa devolver os trechos — com sucesso ou
 * falha, ele é apagado (decisão do dono, 17/10/2026). Quem transcreve é a
 * máquina da pessoa; o servidor não ouve nada.
 */
class Reunioes
{
    public const MAX_BYTES = 12 * 1024 * 1024;
    public const DITADO_MAX_S = 60;
    public const PRESA_MIN = 60;
    public const MAX_TENTATIVAS = 3;
    public const EXPIRA_DIAS = 7;
    public const DISCO = 'central_ia';

    /** Extensão => tipos reais aceitos (o finfo chama o mesmo áudio de nomes diferentes). */
    public const TIPOS = [
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'wav' => ['audio/x-wav', 'audio/wav', 'audio/vnd.wave', 'audio/wave'],
        'm4a' => ['audio/x-m4a', 'audio/mp4', 'video/mp4', 'audio/m4a'],
        'ogg' => ['audio/ogg', 'application/ogg', 'audio/opus', 'video/ogg'],
        'opus' => ['audio/ogg', 'application/ogg', 'audio/opus'],
        'oga' => ['audio/ogg', 'application/ogg'],
        'webm' => ['audio/webm', 'video/webm'],
    ];

    /** A extensão a usar, ou null se o conteúdo não é áudio aceito. */
    public static function extensao(string $nome, string $detectado): ?string
    {
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if (isset(self::TIPOS[$ext])) {
            return in_array($detectado, self::TIPOS[$ext], true) ? $ext : null;
        }
        // Sem extensão (voz do Telegram, gravação do navegador): vale o tipo real.
        foreach (self::TIPOS as $e => $tipos) {
            if (in_array($detectado, $tipos, true)) {
                return $e === 'oga' || $e === 'opus' ? 'ogg' : $e;
            }
        }

        return null;
    }

    /**
     * Guarda o áudio e registra a reunião. A requisição volta na hora; o resto
     * acontece na máquina da pessoa (FR-003).
     */
    public function receber(User $user, string $caminho, string $nome, string $origem, string $modo = 'reuniao', ?int $telegramUpdateId = null, ?int $duracao = null): SecretarioReuniao
    {
        $tamanho = filesize($caminho) ?: 0;
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($caminho);
        $ext = self::extensao($nome, $mime);
        if (! $ext || $tamanho < 1 || $tamanho > self::MAX_BYTES) {
            throw ValidationException::withMessages(['audio' => $tamanho > self::MAX_BYTES
                ? 'Áudio acima de 12 MB. Grave em OGG/Opus (cabe ~1 h) ou divida o arquivo.'
                : 'Envie áudio MP3, WAV, M4A, OGG, OPUS ou WEBM.']);
        }
        if ($telegramUpdateId && ($ja = SecretarioReuniao::where('telegram_update_id', $telegramUpdateId)->first())) {
            return $ja;
        }
        $destino = 'secretario/reunioes/'.$user->id.'/'.Str::uuid().'.'.$ext;
        Storage::disk(self::DISCO)->put($destino, fopen($caminho, 'r'));
        $titulo = $modo === 'ditado' ? 'Ditado de '.now()->format('d/m H:i')
            : ($origem === 'upload' && pathinfo($nome, PATHINFO_FILENAME) !== '' ? Str::limit(pathinfo($nome, PATHINFO_FILENAME), 150, '…')
                : ($origem === 'gravacao' ? 'Gravação de ' : 'Áudio do Telegram de ').now()->format('d/m H:i'));
        $r = SecretarioReuniao::create([
            'user_id' => $user->id, 'titulo' => $titulo, 'origem' => $origem, 'modo' => $modo, 'arquivo' => $destino, 'mime' => $mime,
            'tamanho' => $tamanho, 'sha256' => hash_file('sha256', $caminho), 'duracao_s' => $duracao, 'telegram_update_id' => $telegramUpdateId,
        ]);
        Eventos::registrar($user, 'secretario_reuniao_recebida', "Reunião #{$r->id} recebida ({$origem}, {$modo}).", ['origem' => $origem === 'telegram' ? 'telegram' : 'web']);

        return $r;
    }

    /** O runner da pessoa pede trabalho: reserva a mais antiga, com trava (FR-004). */
    public function reservar(User $user, string $maquina): ?SecretarioReuniao
    {
        return DB::transaction(function () use ($user, $maquina) {
            $r = SecretarioReuniao::de($user)->where('status', SecretarioReuniao::AGUARDANDO)->whereNotNull('arquivo')
                ->orderBy('id')->lockForUpdate()->first();
            if (! $r) {
                return null;
            }
            $r->update(['status' => SecretarioReuniao::TRANSCREVENDO, 'maquina' => $maquina, 'reservada_em' => now(), 'tentativas' => $r->tentativas + 1, 'erro' => null,
                'progresso' => null, 'progresso_em' => null]);

            return $r;
        });
    }

    /** A reunião que ESTA máquina reservou e ainda está transcrevendo, ou 404. */
    public function daMaquina(User $user, string $maquina, int $id): SecretarioReuniao
    {
        $r = SecretarioReuniao::de($user)->whereKey($id)->first();
        abort_unless($r && $r->status === SecretarioReuniao::TRANSCREVENDO && $r->maquina === $maquina, 404);

        return $r;
    }

    /** A máquina avisa quanto já transcreveu; nunca volta para trás. */
    public function progresso(User $user, string $maquina, int $id, int $percentual, ?float $duracao): void
    {
        $r = $this->daMaquina($user, $maquina, $id);
        $r->update(['progresso' => max((int) $r->progresso, min(100, $percentual)), 'progresso_em' => now(),
            'duracao_s' => $r->duracao_s ?: ($duracao ? (int) round($duracao) : null)]);
    }

    /** O runner devolveu: grava os trechos, apaga o áudio e segue (ditado ou análise). */
    public function resultado(User $user, string $maquina, int $id, array $dados): void
    {
        $r = DB::transaction(function () use ($user, $maquina, $id, $dados) {
            $r = SecretarioReuniao::de($user)->whereKey($id)->lockForUpdate()->first();
            abort_unless($r && $r->status === SecretarioReuniao::TRANSCREVENDO && $r->maquina === $maquina, 404);
            if ($dados['status'] !== 'ok' || empty($dados['trechos'])) {
                $r->update(['status' => SecretarioReuniao::FALHOU, 'erro' => Str::limit((string) ($dados['erro'] ?? 'transcrição sem fala'), 76, '…')]);
                $this->apagarAudio($r);

                return $r;
            }
            SecretarioReuniaoTrecho::where('reuniao_id', $r->id)->delete();
            foreach (array_values($dados['trechos']) as $i => $t) {
                SecretarioReuniaoTrecho::create(['reuniao_id' => $r->id, 'ordem' => $i + 1, 'inicio' => (float) $t['inicio'], 'fim' => (float) $t['fim'],
                    'texto' => mb_substr(trim((string) $t['texto']), 0, 2000)]);
            }
            $r->update(['transcrita_em' => now(), 'duracao_s' => isset($dados['duracao']) ? (int) round($dados['duracao']) : $r->duracao_s,
                'status' => $r->modo === 'ditado' ? SecretarioReuniao::REVISADA : SecretarioReuniao::ANALISANDO]);
            $this->apagarAudio($r);

            return $r;
        });
        Eventos::registrar($user, 'secretario_reuniao_transcrita', "Reunião #{$r->id}: transcrição ".($r->status === SecretarioReuniao::FALHOU ? 'falhou' : 'recebida').'.', ['origem' => 'runner'],
            $r->status === SecretarioReuniao::FALHOU ? 'aviso' : 'info');

        if ($r->status === SecretarioReuniao::FALHOU) {
            $this->avisar($user, $r, "⚠ Não consegui transcrever “{$r->titulo}”: {$r->erro}.");
        } elseif ($r->modo === 'ditado') {
            $this->ditado($user, $r);
        } else {
            app(AnaliseDeReuniao::class)->pedir($user, $r->fresh());
        }
    }

    /**
     * Voz curta do Telegram: mostra o que entendeu e trata como digitado
     * (spec US3). Volta pelo próprio canal do bot, com as mesmas portas de
     * uma mensagem de texto (Secretário, código, canal ativo, Central).
     */
    private function ditado(User $user, SecretarioReuniao $r): void
    {
        $texto = trim(implode(' ', $r->trechos()->pluck('texto')->all()));
        $vinculo = CentralIaTelegramVinculo::where('user_id', $user->id)->first();
        $bot = app(TelegramCanal::class)->botDe($user);
        if (! $vinculo || ! $bot || $bot->situacao === CentralIaTelegramBot::TOKEN_INVALIDO || $texto === '') {
            return;
        }
        app(TelegramDoSecretario::class)->responder($user, "🎙 Entendi: “{$texto}”");
        app(TelegramCanal::class)->receber(['update_id' => (int) $r->telegram_update_id, 'message' => [
            'message_id' => (int) $r->telegram_update_id, 'text' => mb_substr($texto, 0, (int) config('central_ia.max_texto')),
            'chat' => ['id' => (int) $vinculo->chat_id, 'type' => 'private'], 'from' => ['id' => (int) $vinculo->chat_id, 'is_bot' => false],
        ]], $bot);
    }

    /**
     * Presas há mais de 60 min voltam para a fila; na 3ª, falham e o áudio sai (FR-014).
     * Conta do último aviso de progresso: reunião longa que ainda avança não está presa.
     */
    public function liberarPresas(): int
    {
        $n = 0;
        $limite = now()->subMinutes(self::PRESA_MIN);
        $presas = SecretarioReuniao::where('status', SecretarioReuniao::TRANSCREVENDO)
            ->where(fn ($q) => $q->where('progresso_em', '<', $limite)->orWhere(fn ($q) => $q->whereNull('progresso_em')->where('reservada_em', '<', $limite)));
        foreach ($presas->get() as $r) {
            DB::transaction(function () use ($r, &$n) {
                $r = SecretarioReuniao::whereKey($r->id)->lockForUpdate()->first();
                if (! $r || $r->status !== SecretarioReuniao::TRANSCREVENDO) {
                    return;
                }
                if ($r->tentativas >= self::MAX_TENTATIVAS) {
                    $r->update(['status' => SecretarioReuniao::FALHOU, 'erro' => 'a transcrição não terminou em 3 tentativas']);
                    $this->apagarAudio($r);
                } else {
                    $r->update(['status' => SecretarioReuniao::AGUARDANDO, 'maquina' => null, 'reservada_em' => null]);
                }
                $n++;
            });
        }
        // Análise que nunca voltou (execução perdida): falha com o botão "Analisar de novo".
        foreach (SecretarioReuniao::where('status', SecretarioReuniao::ANALISANDO)->where('updated_at', '<', now()->subMinutes(self::PRESA_MIN * 2))->get() as $r) {
            $r->update(['status' => SecretarioReuniao::FALHOU, 'erro' => 'a análise não voltou; use Analisar de novo']);
            $n++;
        }

        return $n;
    }

    /**
     * Áudio que nenhuma máquina pegou em EXPIRA_DIAS (máquina desligada, sem a
     * imagem de transcrição): apagado, com aviso — áudio não fica no servidor
     * para sempre (revisão de QA antes da publicação).
     */
    public function expirarParados(): int
    {
        $n = 0;
        foreach (SecretarioReuniao::where('status', SecretarioReuniao::AGUARDANDO)->whereNotNull('arquivo')->where('created_at', '<', now()->subDays(self::EXPIRA_DIAS))->get() as $r) {
            $expirou = DB::transaction(function () use ($r) {
                $r = SecretarioReuniao::whereKey($r->id)->lockForUpdate()->first();
                if (! $r || $r->status !== SecretarioReuniao::AGUARDANDO) {
                    return null;
                }
                $r->update(['status' => SecretarioReuniao::FALHOU, 'erro' => 'nenhuma máquina transcreveu em '.self::EXPIRA_DIAS.' dias; áudio apagado']);
                $this->apagarAudio($r);

                return $r;
            });
            if ($expirou && ($dono = User::find($expirou->user_id))) {
                $this->avisar($dono, $expirou, "⚠ O áudio de “{$expirou->titulo}” esperou ".self::EXPIRA_DIAS." dias sem uma máquina para transcrever e foi apagado.");
                $n++;
            }
        }

        return $n;
    }

    public function apagarAudio(SecretarioReuniao $r): void
    {
        if ($r->arquivo) {
            Storage::disk(self::DISCO)->delete($r->arquivo);
            $r->update(['arquivo' => null]);
        }
    }

    /** Sino sempre; Telegram quando pareado. Sem conteúdo da reunião, só o título e o link. */
    public function avisar(User $user, SecretarioReuniao $r, string $texto): void
    {
        Notificacao::create([
            'destinatario_id' => $user->id, 'tipo' => 'lembrete', 'nivel' => $r->status === SecretarioReuniao::FALHOU ? 'atencao' : 'info',
            'icone' => 'document', 'titulo' => Str::limit($texto, 250), 'meta' => 'Reuniões', 'rota' => route('secretario.reunioes.mostrar', $r->id),
        ]);
        app(TelegramDoSecretario::class)->responder($user, $texto."\n".route('secretario.reunioes.mostrar', $r->id));
    }
}
