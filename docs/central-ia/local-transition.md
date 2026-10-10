> **Histórico.** Este documento descreve a primeira versão (aplicação separada).
> Em 10/10/2026 o dono decidiu outro caminho — ver [README.md](README.md).

# Transição local — etapas 1 e 2

## Ambientes e limites

O AlfaMatriz já utilizado pelo proprietário está em `http://localhost:8096`
no Windows e `http://home:8096` no Mac. O contêiner `alfamatriz-dev-app`
monta `/home/duque_de_caxias/projetos/alfamatriz` em `/var/www`.
Esse ambiente foi identificado por inspeção de caminhos e rótulos, sem
ler variáveis de credenciais nem alterar seus serviços.

O candidato isolado está em `https://localhost:8110/central-ia`. Ele não
substitui o serviço 8096. Seus usuários e mensagens são fixtures; não há
importação de contas, histórico ou pareamento reais. O certificado é local,
autossinado. A porta é publicada somente em 127.0.0.1: acesso direto pelo
Mac usando `home:8110` não está habilitado. Tailscale permanece uma etapa futura.

## Operação dos novos serviços

No diretório `/home/duque_de_caxias/projetos/central-agentes`:

```bash
python3 development/local-services.py up
python3 development/local-services.py status
python3 development/local-services.py check
python3 development/local-services.py stop
```

Esses comandos operam somente o projeto Compose `alfamatriz-central-local-dev`.
Não param ou reiniciam AlfaMatriz 8096, Central 8100 ou outros projetos.
Os seis novos serviços usam armazenamento persistente próprio e
`restart: unless-stopped`. Não precisam do VS Code aberto; precisam de
Docker/WSL em funcionamento. Inicialização automática do Windows não foi alterada.

## Identidade exclusiva

O candidato exige conta ativa, permissão nativa específica, ID estável e
subject UUID correspondentes à política do proprietário. Não concede
exceção administrativa. A política real permanece `confirmed: false`,
portanto ninguém pode acessar a Central neste candidato em operação normal.
As concessões para fixtures foram usadas somente durante os testes e removidas.

O endereço e o e-mail não comprovam a identidade. Ainda é necessário confirmar
o ID a partir da autenticação nativa do AlfaMatriz 8096, sem copiar sessão,
APP_KEY, senha ou credenciais para o candidato. Não associar automaticamente
IDs numéricos coincidentes entre bancos de fixtures e o banco existente.
Não ativar histórico ou Telegram reais antes dessa confirmação e da autorização
específica para sua vinculação.

## Evidências

- AlfaMatriz: suíte integral com 1.409 testes aprovados e 8.541 assertions;
  duas depreciações preexistentes de PHPUnit.
- Central: 77 testes aprovados e 426 assertions em SQLite e PostgreSQL;
  seis testes HTTPS separados aprovados, com 70 assertions.
- Navegador: cinco verificações aprovadas, incluindo isolamento das três
  identidades fictícias e administrador sem acesso privilegiado. Trinta
  leituras com seis requisições concorrentes responderam HTTP 200.
- Reinício dos seis novos contêineres: manifests antes/depois idênticos
  para identidades, mensagens, marcador em storage e marcador Redis.
- 586 arquivos da origem previamente inventariados permaneceram idênticos.

Os relatórios estão em `development/reports/local-browser.json`,
`local-persistence.json` e `local-503-diagnostic.json`. Não usam dados reais
nem chamadas de inferência.

## HTTP 503

O teste controlado com um worker FPM reproduziu HTTP 503: AlfaMatriz espera
Central, que consulta a autoridade de identidade no próprio AlfaMatriz;
sem worker livre a consulta expira. Restaurando oito workers, a mesma
verificação respondeu HTTP 200. Isso comprova a causa da reprodução,
não a causa exata do episódio histórico. O relatório mantém essa distinção.

O novo gateway resolve novamente os endereços Docker dos serviços após
recriação, e utiliza os front controllers FPM adequados. Essa configuração
foi validada após o reinício dos novos serviços. Nenhuma configuração do
AlfaMatriz existente foi alterada para aplicar a correção.

## Próximos passos e rollback

1. Confirmar a identidade autenticada no AlfaMatriz 8096 e definir a ativação
   do candidato sem importar credenciais nem autorizar fixtures.
2. Apresentar backup/restauração dos dados reais antes de qualquer vinculação
   ou migração; o teste de persistência atual cobre somente dados fictícios.
3. Ativar histórico e Telegram somente após autorização específica; manter
   um consumidor do bot. Não habilitar inferência nesta etapa.
4. Iniciar uma semana de homologação somente quando a conta real e a conversa
   real estiverem disponíveis, incluindo revogação, reconexão e recuperação.

Rollback desta etapa: parar somente os seis novos serviços com o comando
`stop`, preservando seus diretórios de dados. Os ambientes existentes
continuam independentes. Não remover volumes, dados, backups ou credenciais.
