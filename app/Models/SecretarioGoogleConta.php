<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A conta Google de UMA pessoa no Secretário. Os tokens são cifrados com a
 * chave da aplicação (como o token do bot do Telegram) e nunca saem do
 * modelo: `$hidden` e nenhum log (spec FR-003). Trocar a APP_KEY exige
 * reconectar.
 */
class SecretarioGoogleConta extends Model
{
    public const CONECTADA = 'conectada';
    public const RECONECTAR = 'reconectar';

    protected $table = 'secretario_google_contas';

    protected $fillable = ['user_id', 'conta', 'access_token', 'refresh_token', 'expira_em', 'escopos', 'situacao', 'sincronizado_em', 'erro', 'aviso_reconectar_em'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $attributes = ['situacao' => self::CONECTADA, 'escopos' => ''];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted', 'refresh_token' => 'encrypted',
            'expira_em' => 'datetime', 'sincronizado_em' => 'datetime', 'aviso_reconectar_em' => 'datetime',
        ];
    }

    public function conectada(): bool
    {
        return $this->situacao === self::CONECTADA;
    }
}
