<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

/**
 * A configuração do Nginx é o que torna a troca de versão possível. São quatro
 * decisões que, isoladas, parecem detalhe de arquivo de configuração — e cada
 * uma delas, se cair, quebra o azul/verde de um jeito difícil de diagnosticar.
 */
class ConfiguracaoNginxAzulVerdeTest extends TestCase
{
    private string $conf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conf = file_get_contents(base_path('deploy/nginx-alfamatriz.conf'));
    }

    /**
     * O disco de upload fica fechado, MENOS a marca do sistema.
     *
     * As duas metades precisam andar juntas, e cada uma já quebrou sozinha:
     * sem o `deny`, todo anexo de cobrança e de tarefa volta a ser legível sem
     * sessão; sem a exceção da marca, o logo do sistema some com 403 de quatro
     * telas — foi o que aconteceu em 14/08/2026, ao fechar /storage inteiro
     * afirmando que nenhuma tela montava URL de lá.
     */
    public function test_o_disco_de_upload_fica_fechado_menos_a_marca(): void
    {
        $this->assertMatchesRegularExpression(
            '/location \^~ \/storage\/ \{\s*deny all;/',
            $this->conf,
            'Sem o deny, todo anexo volta a ser legível por quem adivinhar o nome do arquivo.'
        );

        $this->assertStringContainsString(
            'location ^~ /storage/marcas/',
            $this->conf,
            'O x-marca-sistema monta /storage/marcas/… — sem esta exceção o logo dá 403.'
        );

        // `^~` e não `~`: é a forma que vence a regex do `.php$` por
        // precedência do nginx. Com `~` o prefixo casaria por ordem, e um
        // arquivo .php debaixo de /storage voltaria a ser executado.
        $this->assertStringNotContainsString(
            'location ~ /storage/',
            $this->conf,
            'Sem o ^~ a regra passa a depender da ordem, e a regex do .php alcança o disco de upload.'
        );
    }

    /**
     * @spec:AC-167 A raiz servida é o symlink `atual`, nunca uma pasta de
     * versão. Apontar direto para `versoes/azul` faria a publicação alternar
     * cópias enquanto o Nginx serviria sempre a mesma.
     */
    public function test_a_raiz_publica_e_o_symlink_e_nao_uma_versao(): void
    {
        $this->assertStringContainsString('root /var/www/alfamatriz/atual/public;', $this->conf);

        $this->assertStringNotContainsString(
            'root /var/www/alfamatriz/versoes/',
            $this->conf,
            'Servir uma cópia direto tira a indireção que faz a troca funcionar.'
        );
        $this->assertStringNotContainsString(
            'root /var/www/alfamatriz/public;',
            $this->conf,
            'Esta era a raiz do formato antigo, publicado por cima de si mesmo.'
        );
    }

    /**
     * @spec:AC-168 A porta de ensaio serve a versão em preparo e só atende o
     * próprio servidor. Uma versão que ainda não foi aprovada não pode ser
     * alcançável de fora nem por engano.
     */
    public function test_a_porta_de_ensaio_so_atende_o_proprio_servidor(): void
    {
        $this->assertStringContainsString('listen 127.0.0.1:8081;', $this->conf);
        $this->assertStringContainsString('root /var/www/alfamatriz/preparo/public;', $this->conf);

        $this->assertStringNotContainsString(
            'listen 8081;',
            $this->conf,
            'Escutar em todas as interfaces exporia a versão não aprovada.'
        );
    }

    /**
     * @spec:AC-167 O `$realpath_root` não é preferência de estilo: ele entrega
     * ao PHP o caminho REAL do arquivo, e não o caminho pelo symlink. Sem ele,
     * as duas cópias dividiriam a mesma chave de opcache e a troca serviria
     * código velho com arquivos novos — a falha mais difícil de enxergar que
     * este esquema pode produzir.
     *
     * A conferência é nos DOIS server blocks: o de ensaio existe justamente
     * para dizer se a versão nova está de pé, e não serviria a nada se
     * respondesse com o código compilado da outra.
     */
    public function test_o_php_recebe_o_caminho_real_nos_dois_blocos(): void
    {
        $ocorrencias = substr_count($this->conf, 'SCRIPT_FILENAME $realpath_root$fastcgi_script_name');

        $this->assertSame(
            2,
            $ocorrencias,
            'Os dois server blocks (público e de ensaio) precisam entregar o caminho real ao PHP.'
        );

        $this->assertStringNotContainsString(
            'SCRIPT_FILENAME $document_root$fastcgi_script_name',
            $this->conf,
            'O $document_root é o caminho PELO symlink: as duas cópias colidiriam no opcache.'
        );
    }

    /**
     * @spec:AC-167 A telemetria do painel descreve a INSTALAÇÃO, não a versão.
     * Morando no `public/` da versão, a troca a levaria junto — e o painel
     * ficaria sem notícia exatamente no momento em que ela mais importa.
     */
    public function test_a_telemetria_do_painel_sobrevive_a_troca(): void
    {
        $this->assertStringContainsString('location = /deploy-status.json', $this->conf);
        $this->assertStringContainsString('alias /var/www/alfamatriz/deploy-status.json;', $this->conf);

        // E quem escreve o arquivo grava no mesmo lugar de onde o Nginx o lê.
        foreach (['deploy/deploy-tag-watcher-alfamatriz.sh', 'deploy/voltar.sh'] as $script) {
            $this->assertStringNotContainsString(
                'public/deploy-status.json',
                file_get_contents(base_path($script)),
                "{$script} ainda grava a telemetria dentro da versão — ela some na próxima troca."
            );
        }
    }
}
