<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\AlfaMatrizServer;
use App\Mcp\Tools\VerTarefa;
use App\Models\Tarefa;
use App\Models\TarefaReferenciaGit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O código ligado pelo GitHub (#211) chega ao agente pelo `ver_tarefa`: é de
 * lá que ele monta o `pr_commits` da entrega.
 */
class CodigoPeloAgenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_ver_tarefa_lista_prs_e_commits_ligados(): void
    {
        $dev = User::factory()->membro()->create();
        $tarefa = Tarefa::factory()->create([
            'status' => 'em_desenvolvimento', 'responsavel_id' => $dev->id, 'criado_por_id' => $dev->id,
        ]);

        TarefaReferenciaGit::create([
            'tarefa_id' => $tarefa->id, 'tipo' => 'pr', 'repositorio' => 'FelipeGat/AlfaGym',
            'chave' => 'FelipeGat/AlfaGym#76', 'numero' => 76, 'titulo' => 'Boleto duplicado',
            'url' => 'https://github.com/FelipeGat/AlfaGym/pull/76', 'autor_github' => 'alexandre-dev', 'estado' => 'mesclado',
        ]);
        TarefaReferenciaGit::create([
            'tarefa_id' => $tarefa->id, 'tipo' => 'commit', 'repositorio' => 'FelipeGat/AlfaGym',
            'chave' => 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678', 'titulo' => 'Corrige o boleto',
            'url' => 'https://github.com/FelipeGat/AlfaGym/commit/a1b2c3d', 'autor_github' => 'alexandre-dev', 'branch' => 'alexandre',
        ]);

        AlfaMatrizServer::actingAs($dev)
            ->tool(VerTarefa::class, ['tarefa' => $tarefa->codigo()])
            ->assertOk()
            ->assertSee('Código (GitHub, marca T-'.$tarefa->id.'):')
            ->assertSee('- PR #76 (Mesclado) FelipeGat/AlfaGym · Boleto duplicado · alexandre-dev · https://github.com/FelipeGat/AlfaGym/pull/76')
            ->assertSee('- commit a1b2c3d FelipeGat/AlfaGym (alexandre) · Corrige o boleto · alexandre-dev');
    }
}
