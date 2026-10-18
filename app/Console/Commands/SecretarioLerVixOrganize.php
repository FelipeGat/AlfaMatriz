<?php

namespace App\Console\Commands;

use App\Models\SecretarioVixOrganize;
use App\Services\Secretario\Contas;
use App\Services\Secretario\VixOrganize\Retrato;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Relê a fila do VixOrganize de cada conta conectada
 * (docs/specs/secretario-vixorganize). Só GET; o nome dos projetos não vai
 * para o log.
 */
class SecretarioLerVixOrganize extends Command
{
    protected $signature = 'secretario:vixorganize';

    protected $description = 'Lê a fila de projetos do VixOrganize das contas conectadas';

    public function handle(Retrato $retrato): int
    {
        // Só quem ainda tem o Secretário: perfil tirado, a leitura para.
        $ids = Contas::comSecretario()->pluck('id');
        foreach (SecretarioVixOrganize::query()->whereIn('user_id', $ids)->get() as $conta) {
            try {
                if ($erro = $retrato->atualizar($conta)) {
                    Log::warning('secretario:vixorganize falhou', ['conta' => $conta->id, 'erro' => $erro]);
                }
            } catch (\Throwable $e) {
                Log::error('secretario:vixorganize quebrou', ['conta' => $conta->id, 'erro' => $e::class]);
            }
        }

        return self::SUCCESS;
    }
}
