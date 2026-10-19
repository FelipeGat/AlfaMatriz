<?php

namespace App\Http\Controllers;

use App\Models\SecretarioReuniao;
use App\Services\PainelVixcard;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Projetos VixCard (docs/specs/acesso-vixcard): a tela inicial da conta
 * VixCard, e uma visão de bolso dos produtos da VixCard para quem vê o quadro.
 */
class VixcardProjetosController extends Controller
{
    public function index(Request $request, PainelVixcard $painel): View
    {
        $this->bloquearVisaoDaMatriz();
        $projetos = $painel->projetos();
        // Reuniões de produto VixCard que o dono compartilhou com a pessoa (secretario-reuniao-produto FR-005).
        $reunioes = SecretarioReuniao::compartilhadasCom($request->user())->whereHas('sistema', fn ($q) => $q->where('natureza', 'vixcard'))
            ->with(['sistema:id,nome', 'dono:id,name'])->orderByDesc('id')->limit(8)->get();

        return view('vixcard.projetos', ['projetos' => $projetos, 'totais' => $painel->totais($projetos), 'reunioes' => $reunioes]);
    }
}
