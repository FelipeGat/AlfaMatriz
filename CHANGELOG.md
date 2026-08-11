# Changelog — AlfaMatriz

## AlfaMatriz — 11/08/2026 — Busca e conversa nas tarefas, telas mais rápidas

### Novidades

- **Busca e filtros no quadro de tarefas**: achar uma tarefa dependia de percorrer coluna por coluna, ou página por página no histórico. Agora dá para buscar por texto — título, resumo, detalhes e também o que foi escrito nos comentários — e filtrar por sistema, responsável, prioridade e desfecho. O mesmo formulário serve o quadro e o histórico, e o recorte fica no endereço da página: dá para guardar nos favoritos ou mandar o link pronto para alguém.
- **Comentários na tarefa**: o que não cabia no título nem no resumo passa a ter lugar. Dá para escrever, corrigir e remover. Corrigir mantém a data original e o lugar da frase na conversa — até agora a única saída para um comentário errado era apagar e reescrever, perdendo as duas coisas.

### Melhorias

- **Listagens abrem mais rápido**: Revendas, Sistemas, Produtos, Despesas fixas e Histórico de tarefas carregavam a base inteira numa resposta só. Agora vêm de 20 em 20. Os totais e indicadores continuam somando a lista completa, não a página — o número no rodapé não muda ao virar de página.
- **Nova identidade nos ícones**: o símbolo da Matriz passa a valer no ícone do navegador e no atalho instalado no celular, com o desenho preenchido, mais legível em tamanho pequeno.
- **O aviso não empurra mais a tela**: quando uma confirmação aparecia, a página inteira descia um degrau e voltava ao sumir — quem estava lendo perdia a linha e quem estava mirando um botão acabava clicando no de baixo.

### Correções

- **Login em aba parada dava erro sem saída.** A tela de login costuma ficar aberta horas numa aba de fundo; ao clicar em "Entrar", o sistema respondia com uma página de erro sem nenhum caminho de volta, e só abrindo outra aba dava para entrar.
- **O estado do cliente era gravado diferente do que a tela mostrava.** No cadastro, a UF aparecia em maiúscula no campo — "ES" — e chegava ao banco em minúscula. A tela concordava com a intenção e discordava do que ficava guardado.
- **Cadastrar uma despesa fixa lançava o mês inteiro junto.** Salvar uma despesa gerava as parcelas de todas as despesas vigentes, e não apenas a que estava sendo cadastrada.
- **Empresa duplicada ao integrar com outro sistema.** Quando o CNPJ estava cadastrado com máscara — `52.638.029/0001-05` — a comparação com o outro sistema nunca reconhecia o mesmo documento, e o registro entrava de novo. Era a duplicação que a integração existe justamente para evitar, com risco de cobrar a mesma empresa duas vezes.
- **Clique duplo criava registro em dobro.** Em "Nova tarefa" nascia um segundo card no quadro, e no "Salvar" do comentário a mesma frase era publicada duas vezes.
- **A tela de tarefa pedia dois envios para uma só passada.**
- **Menu da tabela abria sem borda no tema claro**, colado no conteúdo e sem recorte visível.
- **O botão "Mover" ficava fora da borda do card** no quadro de tarefas.

## AlfaMatriz — 11/08/2026 — Publicação de novas versões destravada

Uma publicação pedida no meio da tarde não chegou ao sistema, e a apuração mostrou que nada vinha chegando havia 1h20 — enquanto o painel de acompanhamento mostrava tudo normal. As correções abaixo restabelecem a publicação e tiram do escuro as três formas de falha que a mantinham invisível. **Nenhum dado foi perdido e o sistema esteve no ar o tempo todo**: o que ficou parado foi a chegada das versões novas.

### Correções

- **As atualizações pararam de chegar por 1h20, sem nenhum aviso.** Uma marcação de versão antiga foi reaproveitada apontando para outro ponto do histórico. A partir daí, o programa que leva as versões novas ao servidor passou a desistir logo no primeiro passo — e desistia antes de olhar qualquer versão, inclusive a nova, que não tinha relação nenhuma com a marcação alterada. Sem a correção, a próxima notícia seria "publiquei ontem e não está no ar".
- **O painel dizia que estava tudo certo com a publicação parada.** O indicador mostrava o resultado da última publicação bem-sucedida, e não a tentativa em curso. Sistema parado aparecendo como saudável é pior que sistema parado: agora a falha é sinalizada, e o motivo fica registrado em vez de descartado.
- **Recursos novos podiam nascer invisíveis.** A publicação não aplicava as permissões da versão nova, então o ambiente de produção e o de teste chegaram a rodar o mesmo código com funcionalidades diferentes — telas somem do menu e respondem "sem acesso", sem erro nenhum que denuncie a causa.
- **O botão "Publicar" do painel não fazia nada.** Para o AlfaMatriz, o clique caía num "sistema desconhecido" e nada acontecia. Passou despercebido porque o sistema se atualiza sozinho de 5 em 5 minutos: ele andava, só não obedecia a quem mandava.

### Melhorias

- **Duas publicações ao mesmo tempo não se atropelam mais.** Com o botão do painel voltando a funcionar, o clique e a atualização automática podiam cair na mesma janela e trabalhar sobre os mesmos arquivos. Agora a segunda espera a primeira terminar. Aconteceu na prática três minutos depois da correção, e foi tratado como devia.
- **A verificação automática de saúde passou a incluir o AlfaMatriz.** De 5 em 5 minutos o sistema é consultado junto com os demais — até então ele estava fora dessa lista, e uma indisponibilidade só apareceria quando alguém percebesse.
- **O ambiente de teste passou a rodar as tarefas automáticas**, como o fechamento mensal de competência e o retrato dos sistemas integrados. Elas nunca haviam rodado lá: qualquer defeito nesse tipo de rotina estreava direto em produção. O ambiente de teste também ganhou a cópia diária do banco.
- **O acompanhamento do painel deixou de acusar alteração indevida onde não havia** — um alerta fixo e falso que treinava a ignorar justamente o indicador que denuncia servidor rodando versão velha.

> Nenhuma ação é necessária da sua parte. Tudo o que está descrito aqui já está no ar, com exceção do último item, que entra na próxima publicação.
