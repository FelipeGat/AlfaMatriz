<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Telas de gestão da matriz (dashboards, catálogo, financeiro, cadastros)
     * mostram números de todas as revendas. Usuário com escopo de revenda não
     * as vê: ele trabalha no próprio portfólio (clientes, cobranças, leads,
     * faturamento).
     */
    protected function bloquearVisaoDaMatriz(): void
    {
        abort_if(auth()->user()->temEscopoDeRevenda(), 403, 'Esta tela é exclusiva da matriz.');
    }
}

