<?php

namespace App\Http\Controllers;

use App\Services\OQueEsperaVoce;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * O aviso "O que espera você" ao entrar (#300).
 *
 * O aviso não vem desenhado em toda página: a moldura pergunta aqui, depois de
 * a tela carregar, e só quando a pessoa não silenciou o aviso — que é a conta
 * mais barata possível, uma coluna da própria conta. Desenhar o aviso dentro
 * do layout custaria as cinco consultas em CADA tela de CADA pessoa, para
 * mostrar algo que na maioria das aberturas já foi visto.
 */
class OQueEsperaVoceController extends Controller
{
    public function __construct(private readonly OQueEsperaVoce $espera) {}

    /**
     * O aviso pronto, ou 204 quando não há o que mostrar.
     *
     * Ao ser entregue, o aviso já se adia sozinho: fechar pelo X, pelo Esc ou
     * clicando fora vale como "Lembrar mais tarde". Sem isso, quem fecha sem
     * escolher veria o aviso de novo em cada tela que abrisse — e um aviso que
     * persegue ensina a fechar sem ler.
     */
    public function aviso(Request $request)
    {
        $usuario = $request->user();

        if (! $this->espera->avisaAoEntrar($usuario)) {
            return response()->noContent();
        }

        $pendencias = $this->espera->pendencias($usuario);

        if ($pendencias->isEmpty()) {
            return response()->noContent();
        }

        $this->espera->adiar($usuario);

        return response()->view('o-que-espera._aviso', ['pendencias' => $pendencias]);
    }

    /**
     * As duas listas, para o botão do quadro — buscadas só quando ele é clicado.
     *
     * Embutidas no quadro elas desmentiam o filtro: "Minhas tarefas" ignora o
     * recorte de propósito, e o título de uma tarefa filtrada passava a estar
     * na página. De quebra, quem nunca abre o botão não paga as listas.
     */
    public function listas(Request $request)
    {
        abort_unless($this->espera->podeVer($request->user()), 403);

        return response()->view('o-que-espera._listas', ['espera' => [
            'pendencias' => $this->espera->pendencias($request->user()),
            'minhas' => $this->espera->minhas($request->user()),
        ]]);
    }

    /** "Ok, vi" — não volta hoje. */
    public function visto(Request $request): Response
    {
        $this->espera->marcarVisto($request->user());

        return response()->noContent();
    }
}
