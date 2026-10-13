# Menu recolhível e espaço da conversa

Implementação local no AlfaMatriz, inspirada no menu operacional do AlfaControl
(`frontend/src/components/Sidebar.tsx`), usando Blade, Alpine e tokens existentes.

## Uso

- Botão ao lado da marca recolhe/expande a navegação no desktop. O controle do cabeçalho continua disponível.
- Clique no título Painéis, Comercial, Financeiro, Desenvolvimento ou Sistema para recolher a seção.
- Com o menu compacto, os ícones continuam disponíveis, inclusive os das seções recolhidas. Seus nomes aparecem no tooltip nativo.
- Na Central, **Recolher projetos** libera a largura da conversa; **Mostrar projetos** restaura a lista.
- **Recolher indicadores** oculta os badges; **Mostrar indicadores** os restaura. Avisos de erro permanecem visíveis.
- No celular, Projetos continua abrindo uma gaveta sobre a conversa, independentemente da preferência desktop.

Preferências de aparência são salvas no navegador; rascunhos e conteúdo privado não são persistidos por estes controles.
Recolher painéis não interrompe atualização, troca de projeto ou envio, nem altera permissões.

## Verificação

Build Vite, sintaxe JavaScript, testes existentes de histórico/progresso e teste de
recolher/restaurar com persistência e preservação do rascunho executados.
Testes PHP da Central executados em laboratório isolado sem rede e com SQLite.
Conferência visual manual desktop/celular ainda necessária pelo proprietário; nenhum teste visual automatizado foi executado.

## Arquivos

- resources/js/app.js: estado e persistência das seções do menu.
- resources/views/layouts/navigation.blade.php: botão lateral e grupos expansíveis.
- resources/js/central-ia.js: preferências dos painéis.
- resources/views/central-ia/index.blade.php: controles de projetos e indicadores.

Nenhuma migração, chamada de IA, commit, reinício ou mudança de produção.
Rollback da interface: reverter somente os trechos deste ajuste nos quatro arquivos e refazer o build,
preservando alterações anteriores de fallback, anexos, consumo e progresso.
