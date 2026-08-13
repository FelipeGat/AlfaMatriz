<?php

namespace Tests\Feature\Deploy;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Nenhuma rota pode ter o mesmo nome de um arquivo ou pasta em `public/`.
 *
 * O nginx da instalação resolve `try_files $uri $uri/ /index.php?$query_string`
 * — o disco é consultado ANTES do Laravel. Uma pasta `public/sistemas/` faz
 * `$uri/` casar com ela, e o nginx tenta servir o diretório: com `autoindex`
 * desligado, isso é **403 Forbidden**, devolvido sem que a requisição chegue ao
 * PHP. A rota some, e some com a cara de problema de permissão.
 *
 * Foi exatamente o que aconteceu: as marcas dos produtos moravam em
 * `public/sistemas/` e derrubaram `GET /sistemas` e `POST /sistemas` no
 * staging. O sintoma era um 403 ao cadastrar sistema — que se lê como gate de
 * perfil, e mandou a investigação para o lado errado do sistema.
 *
 * Este teste existe porque NENHUM outro pode pegar isso. A suíte fala com o
 * Laravel direto: ela nunca passa pelo nginx, então `GET /sistemas` responde
 * 200 aqui e 403 no servidor. É a pior combinação possível — teste verde e
 * tela morta —, e a única defesa é comparar as duas listas de nomes.
 */
class RotaSombreadaPorArquivoTest extends TestCase
{
    public function test_nenhuma_rota_e_sombreada_por_arquivo_publico(): void
    {
        // O primeiro segmento é o que basta: o `try_files` casa em `$uri/`, e
        // só há diretório de verdade no primeiro nível de `public/`. Segmentos
        // variáveis ficam de fora — `{sistema}` não é nome de pasta.
        $rotas = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($rota) => explode('/', $rota->uri())[0])
            ->reject(fn (string $segmento) => $segmento === '' || str_starts_with($segmento, '{'))
            ->unique()
            ->values();

        $publicos = collect(scandir(public_path()))
            ->reject(fn (string $nome) => in_array($nome, ['.', '..'], true))
            ->values();

        $colisoes = $publicos->intersect($rotas)->values()->all();

        $this->assertSame([], $colisoes, implode("\n", [
            'Estes nomes existem em public/ E como rota: '.implode(', ', $colisoes).'.',
            'No servidor o nginx serve o disco primeiro e a rota devolve 403 sem chegar ao PHP.',
            'Renomeie o arquivo ou a pasta — a rota é a que tem endereço público a honrar.',
        ]));
    }
}
