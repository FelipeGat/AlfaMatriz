import './bootstrap';

import Alpine from 'alpinejs';

/**
 * Estado da moldura: menu recolhido e tema.
 *
 * As duas escolhas moram no navegador, não na sessão — são preferência de
 * quem está usando aquela máquina, e precisam valer já na PRIMEIRA pintura da
 * página seguinte. Por isso quem decide é um script no <head>
 * (`layouts/app.blade.php`), que põe as classes `theme-light` e `rail-fechado`
 * no <html>. Aqui só continuamos a partir do que ele decidiu: a aparência é
 * sempre do CSS, nunca de um `:class` que chega tarde.
 */
Alpine.data('shell', () => ({
    // Lidos do <html>: repetir a leitura do localStorage aqui só criaria a
    // chance de os dois discordarem.
    railAberto: !document.documentElement.classList.contains('rail-fechado'),

    // Gaveta é coisa de tela estreita: some junto com a navegação.
    gavetaAberta: false,

    tema: document.documentElement.classList.contains('theme-light') ? 'claro' : 'escuro',

    alternarRail() {
        this.railAberto = !this.railAberto;
        document.documentElement.classList.toggle('rail-fechado', !this.railAberto);
        this.lembrar('alfamatriz:rail', this.railAberto ? 'aberto' : 'fechado');
    },

    alternarTema() {
        this.tema = this.tema === 'claro' ? 'escuro' : 'claro';
        document.documentElement.classList.toggle('theme-light', this.tema === 'claro');
        this.lembrar('alfamatriz:tema', this.tema);
    },

    /** Navegação anônima e cotas cheias derrubam o localStorage — e nada disso
     *  justifica quebrar a tela: a preferência simplesmente não sobrevive. */
    lembrar(chave, valor) {
        try {
            localStorage.setItem(chave, valor);
        } catch (erro) {
            // preferência não persistida; a sessão atual continua valendo
        }
    },
}));

window.Alpine = Alpine;

Alpine.start();
