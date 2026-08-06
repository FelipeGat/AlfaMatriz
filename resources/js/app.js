import './bootstrap';

import Alpine from 'alpinejs';

/**
 * Estado da moldura: menu recolhido e tema.
 *
 * As duas escolhas moram no navegador, não na sessão — são preferência de
 * quem está usando aquela máquina, e precisam valer já na PRIMEIRA pintura da
 * página seguinte. Por isso o tema é aplicado por um script no <head>
 * (`layouts/app.blade.php`) e aqui só continuamos a partir do que ele decidiu.
 */
Alpine.data('shell', () => ({
    // O <head> já leu o localStorage; repetir a leitura aqui só criaria a
    // chance de os dois discordarem.
    railAberto: window.alfaRailAberto ?? true,

    // Gaveta é coisa de tela estreita: some junto com a navegação.
    gavetaAberta: false,

    tema: document.documentElement.classList.contains('theme-light') ? 'claro' : 'escuro',

    alternarRail() {
        this.railAberto = !this.railAberto;
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
