<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Troca obrigatória da senha entregue pelo administrador.
 *
 * Não pede a senha atual, ao contrário do `PasswordController`: a pessoa está
 * usando uma senha que recebeu pronta, e exigir que ela a digite de novo só
 * repetiria o que acabou de ser usado para entrar — sem provar nada, porque a
 * sessão já é a prova.
 */
class PrimeiroAcessoController extends Controller
{
    public function edit(): View
    {
        return view('auth.primeiro-acesso');
    }

    public function update(Request $request): RedirectResponse
    {
        $validado = $request->validate([
            'password' => ['required', Password::defaults(), 'confirmed'],
        ], [], ['password' => 'senha']);

        $request->user()->update([
            'password' => Hash::make($validado['password']),
            'primeiro_acesso' => false,
        ]);

        // Sessão nova para a senha antiga não valer num cookie ainda vivo em
        // outro lugar — a senha entregue passou por um canal de terceiros.
        $request->session()->regenerate();

        return redirect()->to($request->user()->telaInicial())
            ->with('status', 'Senha trocada. Bem-vindo ao AlfaMatriz.');
    }
}
