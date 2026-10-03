<?php

namespace Tests\Feature\Vigia;

use App\Models\Sistema;
use App\Models\VigiaIgnorado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `alfa:vigia-token` e `alfa:vigia-ignorar` (#219).
 */
class ComandosDoVigiaTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_token_aparece_uma_vez_e_o_banco_guarda_so_o_hash(): void
    {
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym']);

        Artisan::call('alfa:vigia-token', ['sistema' => 'AlfaGym']);
        preg_match('/^(\d+\|\S+)$/m', Artisan::output(), $m);
        $token = $m[1] ?? null;

        $this->assertNotNull($token, 'O comando tem de mostrar o token.');
        $this->assertStringStartsWith($sistema->id.'|', $token);

        $hash = $sistema->fresh()->vigia_token_hash;
        $this->assertSame(hash('sha256', explode('|', $token, 2)[1]), $hash);
        $this->assertStringNotContainsString(explode('|', $token, 2)[1], (string) $hash);

        // Emitir de novo troca o token: o antigo para de valer.
        $this->artisan('alfa:vigia-token', ['sistema' => 'alfagym'])->assertSuccessful();
        $this->assertNotSame($hash, $sistema->fresh()->vigia_token_hash);

        $this->postJson('/api/vigia/erros', [
            'ambiente' => 'producao',
            'erros' => [['mensagem' => 'x']],
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(401);
    }

    public function test_revogar_apaga_o_token(): void
    {
        $sistema = Sistema::factory()->create(['slug' => 'alfagym']);
        $this->artisan('alfa:vigia-token', ['sistema' => 'alfagym'])->assertSuccessful();

        $this->artisan('alfa:vigia-token', ['sistema' => 'alfagym', '--revogar' => true])->assertSuccessful();

        $this->assertNull($sistema->fresh()->vigia_token_hash);
    }

    public function test_sistema_inexistente_falha(): void
    {
        $this->artisan('alfa:vigia-token', ['sistema' => 'nao-existe'])->assertFailed();
        $this->artisan('alfa:vigia-ignorar', ['padrao' => 'x', '--sistema' => 'nao-existe'])->assertFailed();
    }

    public function test_ignorar_acrescenta_lista_e_remove(): void
    {
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym']);

        $this->artisan('alfa:vigia-ignorar', ['padrao' => 'Broken pipe'])->assertSuccessful();
        $this->artisan('alfa:vigia-ignorar', ['padrao' => '/Redis.*timed out/', '--sistema' => 'alfagym'])->assertSuccessful();

        $this->assertDatabaseHas('vigia_ignorados', ['padrao' => 'Broken pipe', 'sistema_id' => null]);
        $this->assertDatabaseHas('vigia_ignorados', ['padrao' => '/Redis.*timed out/', 'sistema_id' => $sistema->id]);

        $this->artisan('alfa:vigia-ignorar')
            ->expectsTable(['Padrão', 'Tipo', 'Sistema'], [
                ['Broken pipe', 'texto', 'todos'],
                ['/Redis.*timed out/', 'regex', 'AlfaGym'],
            ])
            ->assertSuccessful();

        $this->artisan('alfa:vigia-ignorar', ['padrao' => 'Broken pipe', '--remover' => true])->assertSuccessful();
        $this->assertSame(1, VigiaIgnorado::count());
    }

    public function test_regex_invalida_e_recusada(): void
    {
        $this->artisan('alfa:vigia-ignorar', ['padrao' => '/abre(sem fechar/'])->assertFailed();

        $this->assertSame(0, VigiaIgnorado::count());
    }
}
