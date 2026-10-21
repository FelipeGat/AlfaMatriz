<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O endereço onde o produto abre (Felipe, 20/10/2026: "quero eles com link
     * para direcionar para o acesso deles"). Não é a `base_url`, que é a API da
     * integração e carrega o token: este é só o link que a tela de Projetos abre.
     *
     * NexBeer e VixLink ainda rodam na máquina do Felipe, publicados pelo Funnel do
     * Tailscale (endereço fixo, HTTPS), até terem servidor próprio.
     */
    public function up(): void
    {
        Schema::table('sistemas', function (Blueprint $table) {
            $table->string('url_acesso', 255)->nullable()->after('base_url');
        });
        foreach ([
            'vix-id' => 'https://vixid.tech',
            'gestor-vixcard' => 'https://gestaodenvios.com.br/',
            'vix-beer' => 'https://home.tail0939dd.ts.net:10000',
            'vixlink' => 'https://home.tail0939dd.ts.net:8443',
        ] as $slug => $url) {
            DB::table('sistemas')->where('slug', $slug)->whereNull('url_acesso')->update(['url_acesso' => $url]);
        }
        // O Vix-Beer virou NexBeer (pasta e marca, 19/10/2026); o slug fica, por causa das tarefas.
        DB::table('sistemas')->where('slug', 'vix-beer')->where('nome', 'Vix-Beer')->update(['nome' => 'NexBeer']);
    }

    public function down(): void
    {
        DB::table('sistemas')->where('slug', 'vix-beer')->where('nome', 'NexBeer')->update(['nome' => 'Vix-Beer']);
        Schema::table('sistemas', function (Blueprint $table) {
            $table->dropColumn('url_acesso');
        });
    }
};
