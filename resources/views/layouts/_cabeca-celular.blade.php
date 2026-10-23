{{--
    O que faz o Matriz abrir como app no iPhone (pedido do dono, 23/10/2026:
    "o responsivo com aparência de app, da mesma forma que o painel").

    Posto na tela de início, ele abre em tela cheia, sem a barra do Safari, com
    o nome curto e o ícone da marca. Fica de fora, de propósito, o service
    worker: o pedido é o visual, e tela guardada no celular é pior que nenhuma
    — o painel das centrais chegou à mesma conclusão.

    A cor da barra de status (`theme-color`) não mora aqui: ela é o `--canvas`
    do tema, lido pelo `app.js` (`pintarBarraDeStatus`) — escrita em hexadecimal
    nesta view, ela não acompanharia a troca de tema.
--}}
<link rel="manifest" href="/manifest.webmanifest">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Matriz">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
