# Pedro, o estagiário — conhecimento do grupo, diagnóstico guiado e plantão (Fase 1)

**Feature**: `suporte-pedro-whatsapp` (etapa 4) · **Created**: 2026-10-20

**Pedido do Felipe (20/10/2026):** "analise no mercado como funciona um assistente de IA tipo chatbot para que o
Pedro possa ser 100% completo [...] precisa ser um estagiário muito inteligente, que saiba pedir para o cliente mandar
fotos, analisar a foto e tentar primeiro corrigir o problema."

## O que o mercado faz (levantamento de 20/10/2026)

Os atendentes de IA de WhatsApp bem avaliados têm seis características em comum:

1. **Respondem só com o conhecimento aprovado da empresa.** Nada de resposta genérica da internet.
2. **Fazem diagnóstico guiado:** um passo por vez, esperando o cliente confirmar, com regras de segurança.
3. **Pedem e analisam fotos.**
4. **Passam para um humano com resumo** (*warm handoff*), para o cliente não repetir nada. Gatilhos: o cliente pede,
   a IA não sabe, é reclamação ou é financeiro.
5. **Fora do horário**, registram o pedido e dizem quando e por onde haverá retorno, em vez de ficar mudos.
6. **Registram a conversa inteira.**

O Pedro já cumpre os itens 1, 4 e 6. Esta etapa completa o conhecimento e o diagnóstico guiado (2) e o plantão (5).
As fotos (3) ficam para a Fase 2, porque exigem mudança no Gestor, que guarda a mídia e o token da Meta.

**Teste técnico de 20/10:** o Claude do runner (2.1.304) leu uma imagem enviada pela entrada padrão
(`--input-format stream-json`), sem nenhuma ferramenta liberada. Isso confirma que a Fase 2 é possível.

## Decisões do Felipe (20/10/2026)

1. **Fora do horário:** continua o menu do Gestor, que passa a informar o **plantão (27) 99244-6889** para urgências.
2. **Roteiros de diagnóstico:** o Claude rascunha passos seguros e genéricos, e a equipe (Rossini, Alexandre) revisa
   na tela Suporte › Pedro.
3. **Duas fases.**
   - **Fase 1, só no Matriz:** conhecimento do grupo, contatos, diagnóstico guiado, plantão.
   - **Fase 2, Gestor + Matriz:** fotos e áudio transcrito.

## Conhecimento que o Pedro precisa ter (fontes: os sites, lidos em 20/10, e o Felipe)

**Grupo Soluções.** "Três empresas, uma operação": engenharia, manutenção e tecnologia integradas para indústrias,
condomínios e empresas. Endereço: Rua Pedro Palácios, 79, Centro, Vila Velha/ES, CEP 29.100-190. Contratos com SLA
têm atendimento emergencial 24/7.

| Empresa | Faz | Site |
|---|---|---|
| **GW Soluções** | Engenharia elétrica (instalações, quadros, laudos, termografia) e climatização: ar-condicionado split, multi-split, VRF, refrigeração industrial e câmaras frias | https://gw.solucoesgrupo.com/ |
| **Delta Soluções** | Construção civil, reformas e manutenção predial (hidráulica, elétrica, esquadrias, fachadas), serralheria (portões, grades), pintura, drywall e forros | https://delta.solucoesgrupo.com/ |
| **Invest Soluções** | Segurança eletrônica e TI: CFTV, alarmes, controle de acesso (catraca, facial, biometria, portaria), redes e Wi-Fi; equipamentos Control iD, TopData, Intelbras e Tecnibra | https://invest.solucoesgrupo.com/ |
| **Alfa Soluções** | Braço de tecnologia: sistemas AlfaControl, AlfaGym, AlfaGestor e AlfaHome | — |

**Site do grupo:** https://solucoesgrupo.com/

**Contatos** (fonte: o Felipe):

| Contato | Número | Uso |
|---|---|---|
| PABX e suporte técnico | (27) 4042-4157 | Este número |
| Financeiro | (27) 99652-6457 | |
| Comercial | (27) 99527-3269 | |
| Plantão | (27) 99244-6889 | Fins de semana e das 18:00 às 08:00 do dia seguinte |

**Portal do cliente:** https://gestor.alfa.solucoesgrupo.com/login. Os boletos ficam em Financeiro › Boletos.

## Requisitos

- **FR-01:** a tela Suporte › Pedro guarda e o Pedro usa: apresentação do grupo e das empresas, sites, contatos por
  setor e o portal. A migração semeia esses dados só onde ninguém editou.
- **FR-02 (diagnóstico guiado):** quando há roteiro para o problema, o Pedro conduz um passo de cada vez, espera a
  resposta e só então dá o passo seguinte. Se resolver, confirma e encerra; se não resolver, ou se o cliente não
  conseguir, abre o atendimento com o resumo do que já foi tentado.
- **FR-03 (segurança):** o Pedro nunca orienta abrir equipamento, mexer em fiação ou quadro energizado, subir em
  altura nem desmontar nada. Diante de risco (cheiro de queimado, faísca, fumaça, choque, água em equipamento
  elétrico), orienta só desligar pela tomada ou pelo disjuntor, se for seguro e acessível. Depois abre atendimento
  urgente e, fora do horário, passa o plantão.
- **FR-04 (fotos, Fase 1):** o Pedro pode pedir foto quando ela ajuda (visor, luzes, mensagem de erro, local). Até a
  Fase 2 ele não vê a foto: diz que ela fica registrada para a equipe técnica.
- **FR-05 (contatos certos):** ao encaminhar, o Pedro informa o canal do setor quando faz sentido (financeiro e
  comercial). Para urgência fora do horário, informa o plantão. Só informa números que estão cadastrados.
- **FR-06 (equipe, ampliação de 20/10):** quando alguém da equipe pergunta de um cliente, o Pedro mostra também os
  atendimentos recentes e abertos desse cliente no Gestor, só para consulta.
- **FR-07 (roteiros iniciais):** cada roteiro semeado tem quando usar, passos seguros, quando parar e prioridade:
  - catraca desligada ou sem comunicação;
  - ar-condicionado não liga;
  - ar-condicionado não gela ou pinga;
  - portão não abre ou não fecha;
  - controle do portão;
  - câmera sem imagem;
  - interfone;
  - alarme disparando;
  - internet e Wi-Fi;
  - falta de energia;
  - boleto (portal);
  - comercial.

  Todos ficam marcados para revisão da equipe.
- **FR-08 (plantão no Gestor):** fora do horário, o menu do Gestor informa o plantão. O cabeçalho do menu é
  configurável (`whatsapp_configs.menu_mensagem`), sem mudança de código.

## Fora desta fase

Ver e analisar fotos, transcrever áudio e responder 24 h. Fotos e áudio estão na Fase 2.

## Fase 2: fotos e áudio (20/10/2026)

- **FR-09, foto:** a mídia que o cliente manda vem do Gestor, pela porta `GET /api/integracao/matriz/whatsapp/midia/{wamid}`
  (contrato do Gestor, seção 5), e fica privada no Matriz.
  - As **3 fotos** mais recentes do cliente vão junto do pedido ao runner.
  - O Claude as recebe como blocos de imagem (`--input-format stream-json`); o Codex, com `-i`. Nos dois casos, sem
    nenhuma ferramenta liberada.
  - O Pedro descreve o que vê e usa isso no diagnóstico, sem afirmar o que a foto não mostra.
- **FR-10, áudio:** os **3 áudios** mais recentes ainda não transcritos vão ao runner.
  - O runner transcreve no mesmo container do Whisper das reuniões, sem rede, e o texto entra no prompt no lugar do
    marcador `⟦AUDIO:id⟧`.
  - O orçamento é de 70 s no total, porque o Gestor manda o menu se o Pedro passar de 2 min. O que não couber vai como
    "áudio não transcrito", e o Pedro pede para o cliente escrever.
  - A transcrição volta ao Matriz e fica registrada na mensagem.
- **Defeito encontrado (corrigido no instalador):** a imagem do Whisper refeita em 19/10 copiou o script com permissão
  0600, e o container (usuário da máquina) não conseguia lê-lo. Isso parou também as transcrições de reunião.
  - O instalador agora garante 0644.
  - Ele também refaz a imagem quando o script não é legível, mesmo que o conteúdo seja o mesmo.

## Limite de tentativas, POP básico e foto como padrão (20/10/2026)

- **FR-11, limite:** o Pedro tenta por até **6 respostas ou 30 minutos**, o que vier primeiro
  (`SUPORTE_PEDRO_LIMITE_RESPOSTAS`, `SUPORTE_PEDRO_LIMITE_MINUTOS`). Passou disso, a mensagem seguinte do cliente
  **não vai à IA**: o Matriz responde "vou encaminhar seu atendimento para um de nossos especialistas, que dará
  continuidade por aqui", abre o atendimento com o resumo e deixa a conversa com a equipe. O pedido fica registrado
  com a ação `especialista`, que zera a contagem quando a conversa volta ao Pedro. A equipe interna fica de fora.
- **FR-12, POP de procedimentos básicos:** oito roteiros novos em Suporte › Pedro: impressora, computador que não
  liga, computador lento, mouse ou teclado, monitor sem imagem, catraca que não reconhece face ou digital, nobreak
  e aparelho que não conecta no Wi-Fi. Mesma regra de segurança dos outros; ficam para a equipe revisar.
- **FR-13, foto ou vídeo:** quando o problema não está claro, o Pedro pede foto (ou vídeo curto, para barulho e
  movimento) já na primeira resposta.
- **FR-14, vídeo (20/10/2026):** o vídeo mais recente ainda não visto vai ao runner (até 16 MB, o teto do Gestor).
  O script do Whisper (`transcrever_reuniao.py --video 3`) tira 3 quadros em JPEG (início, meio e fim, girados como
  o celular gravou, maior lado 768 px) e transcreve a fala, num só container sem rede. Os quadros vão à IA como
  fotos, e a fala entra no lugar do marcador `⟦VIDEO:id⟧`. O que foi visto volta ao Matriz na `transcricao` da
  mensagem, e o vídeo não é mandado de novo. Divide os 70 s com os áudios.
- **FR-15, tela de conversas (20/10/2026):** no molde do WhatsApp do Gestor. Tem indicadores do dia, filtros (busca,
  situação, atendente, período, janela da Meta e não lidas), lista com prévia, selo ATRASADO (cliente esperando há
  mais de 15 min) e mídia exibida na conversa, com a transcrição.
- **FR-16, Showroom comercial (20/10/2026):** o retrato do Gestor passa a trazer a biblioteca do Showroom (categorias e
  conteúdos ativos: título, descrição, texto e links). As apresentações de pré-cliente ficam de fora, porque são de
  uma reunião. A cada pedido vão ao Pedro só os até 4 itens que mais combinam com as 3 últimas mensagens do cliente.
  Ele usa o material para explicar, sem prometer preço, e passa ao comercial o que for proposta.
