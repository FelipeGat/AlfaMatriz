<?php

namespace App\Providers;

use App\Listeners\RegistrarAcesso;
use App\Models\Notificacao;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Em produção o painel só existe atrás do Funnel, sempre em HTTPS.
        // Forçar o esquema evita conteúdo misto se algum link for gerado
        // antes de o cabeçalho do proxy ser lido.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Quem já está autenticado e abre /login é desviado — e o desvio padrão
        // do Laravel é a rota `dashboard`, que perfil estreito não alcança: o
        // desvio entregava 403. O destino passa a ser a primeira tela que a
        // conta realmente abre, a mesma que o login usa.
        RedirectIfAuthenticated::redirectUsing(
            fn (Request $request) => $request->user()?->telaInicial() ?? route('login', absolute: false)
        );

        // O rastro de acesso. Aqui, e não numa descoberta automática de
        // listeners, porque estes quatro são de classes do FRAMEWORK: não há
        // onde pendurar a anotação, e uma lista explícita é o único lugar em
        // que se enxerga de uma vez o que está sendo ouvido.
        Event::listen(Login::class, [RegistrarAcesso::class, 'entrou']);
        Event::listen(Logout::class, [RegistrarAcesso::class, 'saiu']);
        Event::listen(Failed::class, [RegistrarAcesso::class, 'recusada']);
        Event::listen(Lockout::class, [RegistrarAcesso::class, 'bloqueado']);

        // O sino vive no rodapé da sidebar, que é servida em TODAS as telas —
        // e um controller de cada tela passando as notificações seria a mesma
        // linha repetida vinte vezes, esperando a primeira ser esquecida. O
        // composer prende os dados ao componente que os usa.
        //
        // As DUAS views, porque o botão e o painel foram separados: o botão
        // mora na sidebar e lê `$naoLidas`; o painel é irmão dela (fugindo do
        // `overflow-hidden` e do `transform` do aside) e lê a lista.
        View::composer(['layouts.navigation', 'layouts.notificacoes'], function ($view) {
            $usuario = auth()->user();

            $view->with([
                'notificacoes' => $usuario
                    ? Notificacao::where('destinatario_id', $usuario->id)->latest('id')->limit(12)->get()
                    : collect(),
                'naoLidas' => $usuario
                    ? Notificacao::naoLidasDe($usuario->id)->count()
                    : 0,
            ]);
        });
    }
}
