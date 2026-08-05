# Spec: Gestão de acessos pelo servidor

> feature: gestao-acessos
> status: rascunho

## Contexto

O painel está publicado na internet e o cadastro público foi desativado, então
toda conta nasce e muda pela linha de comando no servidor. Já existe o comando
que cria usuário; falta o de **alterar** o acesso de quem já existe — trocar
e-mail, trocar senha, ou os dois.

Há também uma armadilha: a carga inicial tem o e-mail do administrador fixo no
código (`admin@alfatecnologia.com.br`). Trocar o e-mail só no banco faz a conta
antiga voltar no próximo `db:seed`. Esta entrega fecha esse buraco.

## Histórias

### US-014 — Trocar o acesso de quem já tem conta

Como responsável pela Alfa, quero alterar o e-mail e a senha de uma conta pela
linha de comando, para poder corrigir o acesso do administrador sem depender de
recriar a conta nem de mexer no banco na unha.

#### AC-029 — O comando troca e-mail e senha da conta existente

- **Dado** uma conta existente no painel
- **Quando** o operador roda o comando de alteração informando a conta, o novo
  e-mail e a nova senha
- **Então** a pessoa passa a entrar com o e-mail e a senha novos, a senha antiga
  deixa de funcionar, e continua sendo a mesma conta — com o histórico, os
  perfis e as permissões preservados

#### AC-030 — O comando recusa alteração que quebraria o acesso

- **Dado** um pedido de alteração
- **Quando** a conta informada não existe, ou o novo e-mail já pertence a outra
  pessoa, ou a senha nova é curta demais
- **Então** o comando recusa com mensagem clara e termina com erro, sem alterar
  nada — porque uma alteração pela metade tranca todo mundo para fora

### US-015 — A carga inicial não ressuscita o acesso antigo

Como responsável pela Alfa, quero que a carga inicial respeite o e-mail de
administrador configurado no ambiente, para que um `db:seed` futuro não recrie
a conta antiga que eu acabei de trocar.

#### AC-031 — O e-mail do administrador vem do ambiente

- **Dado** um ambiente com o e-mail de administrador definido
  (`ADMIN_EMAIL`)
- **Quando** a carga inicial roda
- **Então** ela cria (ou atualiza) a conta desse e-mail, e não a do endereço
  que estava fixo no código

## Fora de escopo

- Tela de gestão de usuários no painel: continua tudo por linha de comando.
- Recuperação de senha por e-mail: o painel não tem envio configurado.
- Papéis e permissões: o comando altera acesso, não o que a pessoa pode fazer.
- Exclusão de usuário.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-015 | A senha nova é digitada no terminal do servidor, sem passar por chat nem por argumento de linha de comando (que ficaria no histórico do shell) | confirmada | Decisão do dono em 2026-08-05: o comando pergunta a senha em modo oculto |
| ASM-016 | Trocar o e-mail do administrador não quebra vínculo nenhum, porque as relações usam o identificador interno e não o e-mail | aberta | Confirmar rodando o comando e conferindo perfis e permissões depois |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-007 | Deve existir um tamanho mínimo de senha diferente do atual (12 caracteres) para contas de administrador? | aberta | — |
