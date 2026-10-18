# Feature Specification: Pastas de documentos espelhadas do OneDrive

**Feature**: `documentos-espelhados`
**Created**: 2026-10-18
**Status**: Draft
**Pedido**: Felipe, 18/10/2026: "preciso que tenha pastas de documentos. Fiz essa pasta no OneDrive para os
orçamentos (…/GAT/VIXCARD/Novos_Projetos/Orçamentos). Quero que ela esteja disponível no Matriz apenas para mim e
para o Victor. Já temos uma tela chamada Comercial: ele pode ter acesso, mas somente ao que diz respeito à VixCard.
Essa pasta espelha o que tem ali dentro, como já fazemos no Grupo Soluções (contratos e orçamentos dos clientes)."

## Contexto

- O espelho do Grupo Soluções mora no **Gestor**: o servidor lê o OneDrive montado, guarda uma cópia privada de
  cada arquivo e serve só pela tela (`documentos:sync`, `ClienteDocumento`).
- O servidor do Matriz **não alcança** o OneDrive do Felipe; a máquina dele alcança (`/mnt/c/Users/…/OneDrive`).
  Por isso quem lê é o **runner**, como já fazem a agenda do Google e o retrato do Gestor.
- A pasta de orçamentos tem 73 arquivos e 90 MB (PDF, imagens, vídeos e áudios), uma subpasta por cliente. Dois
  arquivos passam do limite de 13 MB por envio, por isso o envio é feito em partes.
- A tela Comercial de hoje é o painel de vendas da Alfa (MRR, funil, metas). O Victor **não** a vê: ele vê só os
  Documentos, que ficam no mesmo grupo do menu.

## Escopo

1. **Pasta** (`documento_pastas`): nome, dono e quem mais tem acesso (lista de pessoas). O acesso é por pasta, e
   não por perfil: o administrador **não** vê a pasta só por ser administrador.
2. **Runner**: `CENTRAL_IA_DOCUMENTOS="Nome da pasta|caminho"`, com várias pastas separadas por `;`. A cada
   15 min o runner:
   - lista os arquivos (caminho relativo, tamanho e data);
   - pergunta ao Matriz quais precisam subir;
   - envia esses em partes de 8 MB, com o sha256;
   - avisa o que sumiu.
3. **Matriz**: guarda cada arquivo em disco privado; o que sumiu da origem sai do Matriz.
4. **Tela** Comercial › Documentos:
   - as pastas que a pessoa pode ver e, dentro delas, as subpastas;
   - os arquivos com tipo, tamanho e data, e uma busca;
   - PDF, imagem, vídeo e áudio abrem no navegador; tudo pode ser baixado.
   O dono gerencia com quem a pasta está compartilhada.

## Requirements

- **FR-001**: Só o dono e as pessoas com acesso MUST ver a pasta, os arquivos, a prévia e o download. Qualquer
  outro MUST receber 404, administrador inclusive.
- **FR-002**: O envio MUST aceitar só a máquina do dono da pasta. O caminho relativo MUST NOT conter `..`, caminho
  absoluto ou caractere de controle.
- **FR-003**: O arquivo MUST ser servido só pelo controller, com `Content-Disposition` e `nosniff`. Inline só para
  PDF, imagem, vídeo e áudio; os demais formatos, só como download.
- **FR-004**: Reenviar o mesmo arquivo MUST NOT duplicá-lo. Arquivo alterado na origem MUST substituir o anterior.
- **FR-005**: Arquivo removido da origem MUST sair da tela, e a cópia MUST ser apagada do disco.
- **FR-006**: Cada arquivo tem no máximo 200 MB. Executáveis e scripts (`exe`, `bat`, `cmd`, `ps1`, `sh`, `php`,
  `js`, `msi`, `dll`) MUST NOT ser aceitos.
- **FR-007**: O item Documentos MUST aparecer no menu só para quem tem pasta.

## Delta Requirements

**Skip**: frente nova sem `docs/specs/current/`. — Claude (pedido do Felipe), 2026-10-18
