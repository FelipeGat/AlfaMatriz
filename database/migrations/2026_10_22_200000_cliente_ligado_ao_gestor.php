<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada cliente do Matriz ligado ao cliente dele no Gestor (T-488, decisão do dono em
     * 22/10/2026): o suporte é da Invest e todo chamado nasce no Gestor, então a pessoa
     * confirmada pelo cadastro do Matriz precisa do cliente de lá para o chamado ir ao Gestor.
     * O cadastro do Matriz continua valendo para os telefones e os sistemas do cliente.
     *
     * A lista é fixa, e não um cruzamento feito aqui: 17 batem pelo CNPJ com o documento do
     * retrato do Gestor (22/10/2026) e 7 o dono confirmou pelo nome. ALFA TESTE (29) e
     * Academia Vitta (60), de teste, e a INVEST (27) ficam sem ligação. Cada linha só é
     * gravada se o id ainda tem o nome conferido: em outro banco (staging, teste) o mesmo id
     * pode ser outro cliente, e uma ligação errada mandaria o chamado ao cliente errado.
     */
    private const LIGACOES = [
        // Pelo CNPJ.
        25 => [31, 'IDES'],
        28 => [49, 'CEDTEC'],
        32 => [120, 'BIBLIOTECA MUNICIPAL DE LINHARES'],
        33 => [28, 'CENTRO JURIDICO'],
        35 => [23, 'VD CARIACICA'],
        59 => [105, 'STUDIO EVOFIT'],
        62 => [8, 'ACADEMIA BOA FORMA'],
        63 => [127, 'ACADEMIA VIDA ATIVA'],
        64 => [93, 'ACADEMIA INFO FITNESS'],
        65 => [15, 'ACADEMIA FOCUS STUDIO'],
        66 => [132, 'GOFIT ACADEMIA'],
        71 => [82, 'VixCard'],
        72 => [137, 'Instituto Pacoti (Faculdade Apoena)'],
        73 => [65, 'ACADEMIA FIT CLUB'],
        74 => [20, 'WORLD FITNESS'],
        75 => [167, 'Condomínio do Edifício Corais de Parati'],
        76 => [90, 'EXPERIENCE (PORTO SEGURO) Unidade Teixeira de Freitas'],
        // Pelo nome, confirmados pelo dono.
        26 => [84, 'JARAGUA'],
        70 => [119, 'NONA LU Restaurante'],
        34 => [13, 'Condominio Parque das Castanheiras'],
        30 => [19, 'TAJ'],
        31 => [9, 'COLEGIO DUQUE DE CAXIAS'],
        61 => [6, 'ACADEMIA R1'],
        36 => [82, 'Vixcard'],
    ];

    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            // Com sinal, como nas tabelas do suporte: o pré-cliente do Gestor tem id negativo.
            $table->bigInteger('gestor_cliente_id')->nullable()->index();
        });
        foreach (self::LIGACOES as $id => [$gestor, $nome]) {
            DB::table('clientes')->where('id', $id)->where('nome', $nome)->update(['gestor_cliente_id' => $gestor]);
        }
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropIndex(['gestor_cliente_id']);
            $table->dropColumn('gestor_cliente_id');
        });
    }
};
