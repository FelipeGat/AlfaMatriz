<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * O `pattern` de um campo é compilado pelo navegador com a flag `v`, mais
 * estrita que a do PHP: dentro de colchetes, hífen solto na borda (`[a-z_-]`)
 * vira "Invalid character in character class". O navegador não avisa ninguém —
 * só registra no console e deixa de validar o campo (T-390, dois campos da
 * Central de IA). O servidor, em PCRE, aceita a mesma regra sem reclamar, então
 * só uma checagem no texto das views pega o erro antes do staging.
 */
class PatternDosCamposTest extends TestCase
{
    public function test_nenhum_pattern_tem_hifen_solto_na_borda_da_classe(): void
    {
        $views = Finder::create()->files()->in(dirname(__DIR__, 2).'/resources/views')->name('*.blade.php');
        $problemas = [];

        foreach ($views as $arquivo) {
            preg_match_all('/\bpattern="([^"]*)"/', $arquivo->getContents(), $achados);
            foreach ($achados[1] as $pattern) {
                // `-]` ou `[-` sem barra antes: hífen literal que a flag `v` recusa.
                if (preg_match('/(?<!\\\\)-\]|\[\^?-/', $pattern)) {
                    $problemas[] = $arquivo->getRelativePathname().': '.$pattern;
                }
            }
        }

        $this->assertSame([], $problemas, 'Escreva o hífen literal como \- dentro dos colchetes.');
    }
}
