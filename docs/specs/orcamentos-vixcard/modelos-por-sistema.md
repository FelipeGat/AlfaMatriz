# Modelos de proposta por sistema

**Pedido do Felipe (19/10/2026):** "quero que analise a fundo cada sistema (NexBeer, Vix-ID, Gestor, Jornada, Gym,
Control) para montarmos uma proposta para cada sistema, algo padronizado por sistema."

A análise foi feita em 19/10/2026 lendo o código, a documentação e as propostas já enviadas de cada repositório, só
para leitura. O conteúdo de cada modelo está em `app/Services/Orcamentos/Modelos.php`. Este arquivo guarda o porquê:
o que cada sistema faz de fato, o que **não** prometer e de onde vêm os preços.

## Regras comuns

- O texto descreve só o que existe no código hoje. O que está planejado vai para "Não inclui" ou fica de fora.
- O preço entra só onde há tabela documentada. O resto nasce em R$ 0, e quem orça preenche.
- **Não reaproveitar nada das landings e propostas antigas do AlfaGym e do AlfaControl.** Elas têm depoimentos
  fictícios e números falsos ("centenas de condomínios", "18 módulos", "sistema #1").

## NexBeer (VixCard)

- **O que é:** comanda pós-paga por cartão ou pulseira NFC. O atendente lê o cartão no celular Android, pelo Chrome,
  e o cliente acerta na saída.
- **Não é chope self-service.** Não há torneira, válvula nem fluxômetro.
- **Pronto:**
  - portaria, balcão e caixa;
  - fila offline só para lançar consumo;
  - baixa dividida em vários pagamentos;
  - caixa por operador com contagem cega;
  - troca de cartão perdido e cartões em aberto;
  - relatório do evento;
  - painel da revenda.
- **Não prometer:**
  - produção (ainda não existe deploy);
  - pré-pago, crediário, white label, relatórios por período;
  - integração com PDV, iPhone, leitor USB, QR;
  - processamento de pagamento ou nota fiscal.
- **Preço:** transcrições de áudio, "não é tabela oficial" (fonte: `nex-beer/CLAUDE.md` §9 e
  `ANALISE-COMERCIAL-PIAUI.md`):
  - R$ 79,90/mês por CNPJ à revenda, revendido a R$ 180;
  - cartões: 500 a R$ 11,50 e 1.000 a R$ 8,90.

## Vix-ID (VixCard)

- **O que é:** inventário e controle por RFID UHF. Tem coletor Chainway C72, impressora Zebra, portal de saída
  Control iD iDUHF com o VIX Sync, API de leitura para o ERP, plataforma de três níveis e app que se atualiza sozinho.
- `vix_id_planta` e `vix_id_patrimonio` são **o mesmo produto** em branches diferentes. O segmento do cliente muda só o
  vocabulário das telas.
- **Não prometer:**
  - Gestão Têxtil (proposta, sem merge);
  - planta baixa;
  - carga pelo coletor (container);
  - escrita no ERP e importação do marketplace;
  - gravação e trava de etiquetas na ZT231R (ZPL ainda não confirmado no manual).
- **Corrigir nas propostas:** a Zebra ZD220 citada na da Lorenzo não grava RFID.
- **Preço:** não há tabela oficial. Nas propostas enviadas:
  - **mensalidade:** de R$ 790 a R$ 1.399;
  - **Roupas FRID:**
    - implantação R$ 4.900;
    - C72 R$ 6.500;
    - portal R$ 11.500;
    - impressora R$ 2.490;
    - etiqueta de R$ 0,49 a R$ 0,59;
    - locação em 24 meses: R$ 1.260 a R$ 1.860/mês;
  - **MedSenior:** piloto de 90 dias por R$ 6.000.

## Gestor (Grupo Soluções; vendido como "Gestor VixSoftHouse")

- **O que é:** ERP multi-CNPJ.
- **Pronto:**
  - funil, orçamentos e portal do cliente;
  - financeiro com conciliação OFX;
  - Mercado Pago e Sicoob;
  - NFS-e de Vila Velha e do padrão nacional;
  - obras e PMOC;
  - RH com ponto e assinatura pelo app;
  - WhatsApp Cloud;
  - app Flutter.
- **Não prometer:**
  - folha de pagamento ou contracheque;
  - NF-e de produto;
  - NFS-e de outros municípios.
- **Atenção:**
  - cada cliente ainda precisa de instalação própria;
  - o `docs/briefing.md` do Gestor põe "venda a terceiros" fora de escopo e precisa ser atualizado.
- **Preço** (proposta do Gustavo, 19/10/2026):
  - licença por CNPJ: R$ 350/mês no plano anual, R$ 375 no trimestral, R$ 399 no mensal;
  - implantação R$ 1.900, em até 10x.

## AlfaJornada (Alfa Soluções)

- **O que é:** ponto e jornada multi-CNPJ, com quatro formas de bater o ponto: relógio ControlID pelo AlfaSync, app
  com GPS, totem facial em tablet e lançamento manual.
- **Pronto:**
  - apuração (HE, atraso, falta, adicional noturno, DSR);
  - banco de horas;
  - espelho de ponto;
  - fechamento com trava;
  - CSV para a folha;
  - folha de ponto para assinatura;
  - LGPD.
- **Nunca escrever "conforme a Portaria 671".** O sistema não gera AFD, AFDT, ACJEF nem AEJ e não tem certificação.
- **Não prometer:**
  - eSocial;
  - integração direta com a folha;
  - 12x36 e escala cíclica;
  - cerca geográfica;
  - férias, EPI e ASO.
- **Preço:** atacado R$ 2,50 por colaborador ativo (`SistemasPrecosSeeder`). A Invest paga R$ 99,90 por cliente por mês.
  Não há preço para o cliente final.

## AlfaGym (Alfa Soluções)

- **O que é:** gestão de academias.
- **Pronto:**
  - alunos e matrícula com contrato digital;
  - planos;
  - financeiro e PDV;
  - treinos e avaliação física;
  - aulas coletivas e CRM;
  - multiunidade;
  - app do aluno no Android;
  - bloqueio da catraca por inadimplência, com ControlID, Topdata e Tecnibra;
  - Wellhub de verdade, por webhook.
- **Não prometer:**
  - TotalPass por API (o check-in é manual);
  - débito automático no cartão;
  - WhatsApp automático;
  - app para iPhone;
  - outros fabricantes de catraca.
- **Preço:**
  - a Invest paga R$ 79,90 por academia por mês;
  - a tabela de atacado e a landing antiga estão desatualizadas;
  - não há preço para o cliente final.

## AlfaControl (Alfa Soluções)

- **O que é:** controle de acesso para condomínio, empresa, escola e clube.
- **Pronto:**
  - pessoas, locais e horários;
  - eventos ao vivo;
  - portaria completa (visitantes, entregas, reservas, comunicação);
  - ocorrências, obras e checklists;
  - API com webhook;
  - LGPD completa;
  - multirrevenda;
  - app do morador.
- **Equipamentos:** Control iD (iDFace, iDAccess, iDUHF) e Topdata.
- **Não prometer:**
  - leitura de placa (LPR);
  - reconhecimento facial em câmera ou IoT;
  - certificados NR-35/ASO (em homologação);
  - leitor de digital;
  - funcionamento sem o computador da portaria no modo online.
- **Preço:**
  - a Invest paga R$ 99,90 por cliente por mês;
  - cotação antiga para escola: R$ 499/mês e implantação de R$ 3.500, com contrato de 12 meses e reajuste pelo IPCA.

## Decisões do Felipe (19/10/2026)

1. **Toda proposta sai como VixSoftHouse**, de qualquer sistema. Contato: (27) 3335-4950 e comercial@vixcard.com.br.
2. **Os valores são tratados caso a caso.** Todo item dos modelos nasce em R$ 0; as evidências de preço acima servem
   só de referência para quem orça.
