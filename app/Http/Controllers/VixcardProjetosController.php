<?php

namespace App\Http\Controllers;

use App\Services\PainelVixcard;
use Illuminate\View\View;

/**
 * Projetos VixCard (docs/specs/acesso-vixcard): a tela inicial da conta
 * VixCard, e uma visão de bolso dos produtos da VixCard para quem vê o quadro.
 */
class VixcardProjetosController extends Controller
{
    public function index(PainelVixcard $painel): View
    {
        $this->bloquearVisaoDaMatriz();
        $projetos = $painel->projetos();

        return view('vixcard.projetos', ['projetos' => $projetos, 'totais' => $painel->totais($projetos)]);
    }
}
