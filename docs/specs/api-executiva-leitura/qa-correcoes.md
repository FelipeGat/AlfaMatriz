# Correções do QA independente — 15/11/2026

Estado: correções locais sobre `5c4255a`; quatro commits anteriores preservados.
Sem homologação, novos commits, produção, rede, tokens reais ou dados reais.

## Privacidade: política de minimização

Truncar e limitar campos não protege o conteúdo. CPF, CNPJ, telefone, e-mail,
endereço físico, identificador livre e informações sensíveis podem aparecer em
qualquer posição e grafia, inclusive sem padrões reconhecíveis.

Por isso, nesta versão, a API não devolve nome do remetente, assunto original,
nome da caixa, títulos da agenda, tarefas e notificações, motivo textual de
pendência, nome do sistema/monitor ou padrão do erro. Campos permanecem na lista
branca, com `null` (remetente/caixa) ou `Conteúdo omitido por privacidade`.
Classificação de e-mail usa lista fechada; desconhecido vira `outro`.
A proteção acontece apenas na serialização executiva. O banco, o Secretário e
os e-mails originais permanecem intactos.

Não se afirma uma sanitização perfeita por expressões regulares. A omissão
integral evita também conteúdo ambíguo que regex não reconhece. A utilidade é
menor: permanecem datas, urgência, necessidade de retorno, contagens, situação das
fontes, classificações operacionais e código de tarefa, mas não é possível
identificar pessoas ou assuntos pelo texto. Qualquer futura reintrodução de
texto exige política de classificação e revisão específica; novos campos não
herdam proteção automaticamente. Datas, contagens e códigos também permitem
inferências quando cruzados com outras fontes, portanto a autenticação continua
obrigatória. Não há garantia de anonimização irreversível.

Regressões usam `pessoa@example.test` no `de_nome` e `Documento 123.456.789-00`
no início do assunto, reproduzindo os dados fictícios do QA; incluem ainda CNPJ,
telefone, endereço, identificador e conteúdo médico/ambíguo. Conferem listas de
importantes e aguardando, campos equivalentes e preservação do conteúdo gravado.

## Agenda

O serviço resolve início e fim antes de validar. Início omitido = hoje no fuso
da aplicação; fim omitido = dia seguinte ao início. Rejeita fim anterior e
diferença entre datas maior que 14 dias com 422, antes do envelope de falha de
fonte. Preserva a convenção aprovada de diferença entre datas (inclusive o
limite exato de 14), sem alterar o financeiro.

Regressões: só `ate` longo/passado, ambos invertidos/longos, formatos inválidos,
nenhum parâmetro, só `de`, só `ate`, mesmo dia e limite exato.

## Isolamento: cobertura e limites

A varredura enumera as rotas registradas com `auth`, Sanctum, `abilities` ou
`permissao`, incluindo parâmetros numéricos, UUID e data. Envia explicitamente
Bearer no `call` e reinicia o guard entre requisições. O helper `withToken`
sozinho não fornecia o cabeçalho ao `call` anterior: o teste antigo podia provar
apenas recusa sem autenticação.

O teste desliga **somente** `SubstituteBindings`, para que um recurso inexistente
não produza um 404 que esconda falha de capacidade. Autenticação, capacidades,
permissões e demais middlewares ficam ativos; não aceita mais 404, redirects ou
405 como evidência de isolamento. Cada definição de rota é exercitada pelo
primeiro método diferente de HEAD/OPTIONS; não equivale a auditoria de todos os
verbos, bindings, controladores e recursos reais.

Todas as rotas Sanctum externas ao Executivo são verificadas sem token (401),
com inválido (401) e com executivo (403); exige-se `abilities` na definição.
No sentido inverso, cada capacidade Sanctum existente diferente de
`executivo:ler` é recusada no índice e nos sete blocos (403). Vigia e monitor,
que autenticam no controller e não no middleware Sanctum, têm teste explícito
com os três casos (401). Os testes existentes do Executivo para 401, 403,
405, 429 e auditoria continuam ativos. Não foram alteradas permissões.

Fora desta prova: autenticação positiva dos serviços externos com credenciais
próprias, recursos reais, configuração de proxy/rede, OAuth e produção. Os
segredos dessas integrações não foram carregados. O teste é de fronteira de
capacidade, não substitui os testes de domínio e de isolamento entre recursos.

## Validação

- Antes da correção: três testes de reprodução falharam (vazamento, intervalo e
  nenhuma rota parametrizada na varredura).
- Executivo após correção inicial: 55 testes, 819 assertions; sem falhas, duas
  depreciações do PHPUnit. Depois foi acrescentado o caso de vigia/monitor.
- Executivo final: 56 testes, 825 assertions, sem falhas; duas depreciações do PHPUnit.
- Suíte completa final: 2.354 testes, 15.741 assertions, sem falhas, 30 pulados;
  duas depreciações do PHPUnit. Duração observada: 5 min 57,806 s.
- Pint `--test`: aprovado nos quatro arquivos PHP alterados.
- `git diff --check`: aprovado.
- Testes rodados apenas com dados fictícios, SQLite em memória e rede desativada.

4.4, homologação e exposição à internet permanecem pendentes; financeiro intacto.

Comandos de reprodução/validação neste ambiente (rede desativada, SQLite em
memória, arquivo de ambiente real ocultado pelo laboratório):

```bash
python3 tools/central-ia/run-tests.py --central-root /home/duque_de_caxias/projetos/central-agentes --filter 'regressao_qa|nenhuma_outra_rota'
python3 tools/central-ia/run-tests.py --central-root /home/duque_de_caxias/projetos/central-agentes --filter Executivo
python3 tools/central-ia/run-tests.py --central-root /home/duque_de_caxias/projetos/central-agentes
python3 /tmp/e908-style.py --test
git diff --check
```

O primeiro filtro reproduziu três falhas **antes** da correção; depois deve
passar. O auxiliar de estilo usa a mesma imagem isolada para executar Pint
somente nos quatro arquivos PHP alterados. Logs ficaram em
`/tmp/e908-repro.log`, `/tmp/e908-executivo-final.log` e
`/tmp/e908-completa-final.log`; o auxiliar está fora do repositório, sem dados
reais. Execuções amplas preliminares foram interrompidas para incorporar os
últimos casos de cobertura; não contam como aprovação da suíte.

## Correção e909: classificações com texto livre

`erros_dos_sistemas[].nivel` saía como gravado; o vigia aceita `nivel` como string e o armazena com `strtoupper`, então um CPF fictício chegava à resposta. Agora, em `Blocos.php`, todo campo de classificação passa por lista fechada e o valor cru nunca sai:

- `nivel` (vigia, sino, pendências): debug, info, notice, warning, error, critical, alert, emergency, fatal, critico, atencao, marca; outro valor vira `desconhecido`.
- `ambiente` do vigia: chaves de `VigiaDeLogs::AMBIENTES`; senão `desconhecido`.
- `tipo` e `severidade` do monitor: chaves de `MonitorAlerta::KINDS` e `SEVERIDADES`; senão `outro` / `desconhecido`.
- `tipo` da notificação: lista fechada `Blocos::TIPOS_DE_NOTIFICACAO` (os tipos que o código cria hoje); senão `outro`. A regex de formato anterior (`[a-z_]`, 40 caracteres) foi removida: deixava passar texto livre como `diagnostico_hiv_positivo` (revisão e911; regressão `test_tipo_de_notificacao_so_sai_se_for_conhecido`).

Origens e armazenamento não mudam. Regressão: `test_alertas_so_devolvem_classificacao_de_lista_fechada` (falhou sem a correção, passa com ela).

Limite: tipo de notificação novo cai em `outro` até entrar na lista (perde utilidade, não privacidade). A lista fechada vale para os campos de classificação conhecidos; campo novo exposto no futuro precisa passar pelo mesmo crivo.
