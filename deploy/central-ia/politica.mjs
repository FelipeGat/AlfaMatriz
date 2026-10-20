/**
 * O que cada agente pode fazer nesta máquina — decidido AQUI, nunca pelo
 * servidor (dono, 12/10/2026: "tem que ser inviolável").
 *
 * Módulo puro (sem efeito ao importar), para o teste conferir os argumentos
 * gerados para cada CLI, em cada modo e acesso.
 */
import { existsSync, realpathSync } from 'node:fs';
import { join, sep } from 'node:path';

export const NEGADOS_NO_SHELL = ['git push', 'git commit', 'git reset', 'git checkout', 'git switch', 'git rebase', 'git merge', 'rm -rf', 'sudo', 'docker', 'ssh'];

// Analisar é SÓ LEITURA, imposto pela ferramenta (dono, 12/10/2026: "análise
// é para averiguar, não modificar"). Os comandos de leitura liberados ao
// Claude; o resto do Bash é negado no modo -p.
// Ler arquivo é pela ferramenta Read, que respeita SENSIVEIS; cat/grep/find
// no shell passavam por cima (revisão de segurança, 13/10/2026).
export const LEITURA_NO_SHELL = ['git log', 'git status', 'git diff', 'git show', 'git blame', 'git rev-parse', 'ls', 'wc', 'tree',
    'docker ps', 'docker logs', 'docker inspect', 'date', 'pwd'];

// Nem na leitura: credenciais da máquina.
export const SENSIVEIS = ['~/.ssh/**', '~/.config/alfa/**', '~/.aws/**', '~/.codex/auth.json', '~/.claude/.credentials.json', '~/.copilot/**', '**/.env', '**/.env.*'];

// O Codex só leitura não tem negação de leitura por caminho: um pedido de
// análise que cita credenciais não vai a ele.
export const CITA_CREDENCIAL = /\.ssh\b|id_(?:rsa|ed25519|ecdsa)\b|\.aws\/|(?:^|[\s/'"`])\.env\b|central-ia-runner\.env|\.config\/alfa|\.credentials\.json|auth\.json/i;

/**
 * O acesso que vale de fato: analisar é sempre leitura; implementar é o
 * MENOR entre o que o servidor pediu e o teto desta máquina.
 */
export function acessoEfetivo(job, maximo, analise = 'leitura') {
    // Analisar: a máquina decide (CENTRAL_IA_ANALISE). Dono, 12/10/2026:
    // "quem tem que analisar o que consulta e altera é a própria IA" — com
    // `total`, a análise roda como no VS Code e o rastro fica na auditoria.
    // Análise com acesso total precisa das TRÊS chaves: a máquina, o tipo de
    // análise e o servidor (T-368: o servidor que pede `restrito` não
    // recebia análise com acesso total só porque a máquina permitia).
    if (job.modo !== 'implementar') return analise === 'total' && maximo === 'total' && job.acesso === 'total' ? 'total' : 'leitura';
    return job.acesso === 'total' && maximo === 'total' ? 'total' : 'restrito';
}

/** Caminho dentro da raiz (ou ela mesma), com links resolvidos. */
export function dentroDe(raiz, caminho) {
    try {
        const r = realpathSync(raiz), c = realpathSync(caminho);
        return c === r || c.startsWith(r + sep);
    } catch { return false; }
}

/**
 * Quando a máquina pede o código (dono, 13/10/2026: "só no início do chat e
 * em caso de deploy; ficar pedindo toda hora é bem ruim"), do mais frouxo ao
 * mais rígido:
 *  - nunca:       não pede (escolha de quem não quer o Telegram) — EXCETO deploy;
 *  - deploy:      só pedido de deploy, produção, push ou publicação;
 *  - inicio:      deploy + a primeira implementação de cada chat;
 *  - implementar: deploy + toda implementação;
 *  - sempre:      todo pedido, até análise.
 */
export const REGRAS_DE_CONFIRMACAO = ['nunca', 'deploy', 'inicio', 'implementar', 'sempre'];

/** A regra que vale: a da pessoa (Ajustes), nunca mais frouxa que o mínimo da máquina. */
export function regraEfetiva(minimoDaMaquina, daPessoa) {
    const i = r => Math.max(0, REGRAS_DE_CONFIRMACAO.indexOf(r));
    return REGRAS_DE_CONFIRMACAO[Math.max(i(minimoDaMaquina), REGRAS_DE_CONFIRMACAO.includes(daPessoa) ? i(daPessoa) : 0)];
}

// O que manda código ou versão para fora da máquina. Bloqueado MESMO no
// acesso total, a não ser que o pedido seja de deploy — e pedido de deploy
// sempre passa pelo código (T-368, dono do produto, 14/10/2026: código só no
// início da conversa e em deploy, sem a brecha de escrever "envie ao remoto"
// e o push sair sem código, porque a lista PEDE_DEPLOY é por palavra).
export const PUBLICACAO_NO_SHELL = ['git push', 'git -C', 'ssh', 'scp', 'sftp', 'rsync', 'gh pr merge', 'gh release', 'gh workflow', 'gh api',
    'docker push', 'npm publish'];

/** Pedido de deploy: sempre confirmado por código, e o único que publica. */
export function pedeDeploy(job) {
    return PEDE_DEPLOY.test(semONegado(textoDaPessoa(job?.instrucao)));
}

/**
 * Tira o que vem negado: "Não fazer commit, push, merge, deploy ou alterações
 * em produção" é a proibição, não o pedido (Felipe, 20/10/2026: "toda hora tá
 * pedindo"). Quem escreve isso é a própria IA, ao continuar a tarefa ou passar
 * a outro agente, e a lista PEDE_DEPLOY casava "push" e "deploy" a cada passo.
 * A negação vale até o fim da frase, ou até um "mas"/", pode" que reabre o
 * pedido ("sem testes, pode publicar"). Errar para menos é seguro (pedeDeploy).
 */
const NEGADO = /(^|[^\p{L}])(n[ãa]o|sem|nem|nunca|jamais)(?![\p{L}])[^.;!?\n]*?(?=[.;!?\n]|\s+mas\s|,\s*(e\s+)?(pode|podem|pode\s+\w+|fa[çc]a|faz|suba|sobe|publique|siga)(?![\p{L}])|$)/giu;

export function semONegado(texto = '') {
    return String(texto ?? '').replace(NEGADO, '$1');
}

/**
 * Só as palavras da PESSOA (Felipe, 18/10/2026: "em todas as correções ele
 * manda uma confirmação"). A resposta a uma pergunta da IA chega com a
 * pergunta citada — "Posso fazer o commit (sem push e sem deploy)?" — e a
 * implementação aprovada chega com a proposta da IA inteira: palavra da IA
 * não é pedido de deploy. Sem pedido da pessoa também não há permissão de
 * publicar (`permissoes(..., { deploy })`), então errar para menos é seguro.
 */
export function textoDaPessoa(instrucao = '') {
    const t = String(instrucao ?? '');
    let m = t.match(/^\s*Resposta à sua pergunta \("[\s\S]*"\):\s*([\s\S]*)$/);
    if (m) return m[1];
    m = t.match(/^\s*A pessoa respondeu "([\s\S]*)" à sua última mensagem nesta tarefa:/);
    if (m) return m[1];
    if (/^\s*Implemente o que foi proposto na análise anterior desta tarefa/.test(t)) return '';
    return t;
}

// "Produção" sozinha não é deploy: é também o chão de fábrica da VixCard
// ("o módulo de produção"). Conta com verbo de levar para lá.
export const PEDE_DEPLOY = /\b(deploy|deplo[iy]\w*|publi(car|que|ca[çc][ãa]o)|push|tag\s+v\d|release|merge\s+(na|para\s+a)\s+main|(subir|suba|sobe|mandar|mande|manda|levar|leve|leva|colocar|coloque|coloca|jogar|jogue|joga)\s+(pra|para|em|na)\s+(a\s+)?produ[çc][ãa]o|subir\s+(pra|para)\s+o?\s*ar)\b/i;

/** Precisa do código do dono antes de rodar? `chatConfirmado`: já confirmou neste chat. */
export function exigeConfirmacao(job, regra, chatConfirmado = false) {
    // Deploy, produção, push e publicação pedem o código SEMPRE, em qualquer
    // regra (dono, 13/10/2026: "nem deploy sem autenticação de dois fatores,
    // que é a mensagem no Telegram"). Máquina sem Telegram não executa.
    if (pedeDeploy(job)) return true;
    if (regra === 'nunca') return false;
    if (regra === 'sempre') return true;
    if (regra === 'deploy') return false;
    if (job.modo !== 'implementar') return false;
    return regra === 'implementar' || !chatConfirmado;
}

/** Argumentos de permissão de cada CLI para o acesso efetivo. */
export function permissoes(agente, acesso, { projetos, cwd, deploy = false, codexTotal = false, semFerramentas = false } = {}) {
    // Pedido do Secretário (`job.acesso === 'nenhum'`): leva e-mail e
    // transcrição de terceiros, então nenhuma ferramenta — nem a leitura,
    // nem o WebSearch, que serviria de canal para fora. O Codex já vai em
    // read-only sem rede e o Copilot sem escrita e sem shell (abaixo).
    if (semFerramentas) {
        acesso = 'leitura';
        if (agente === 'claude') return ['--permission-mode', 'default', '--disallowedTools', ...FERRAMENTAS_CLAUDE];
    }
    // O Codex não nega comando por comando: sem pedido de deploy, ele não
    // recebe acesso total (fica no workspace-write, sem rede), e assim não
    // publica nada (T-368). Exceção da MÁQUINA (`CENTRAL_IA_CODEX_TOTAL=sim`,
    // Felipe, 16/10/2026): quem quer o Codex operando como no VS Code (rede,
    // pontes de produção, pastas fora do projeto) liga na própria máquina. O
    // deploy continua pedindo o código no Telegram (exigeConfirmacao).
    if (agente === 'codex' && acesso === 'total' && !deploy && !codexTotal) acesso = 'restrito';
    const total = acesso === 'total', escreve = acesso !== 'leitura';
    const semPublicar = total && !deploy;
    if (agente === 'claude') {
        // A negação vale mesmo no bypassPermissions.
        if (total) return ['--permission-mode', 'bypassPermissions', '--add-dir', projetos,
            ...(semPublicar ? ['--disallowedTools', ...PUBLICACAO_NO_SHELL.map(c => `Bash(${c}:*)`)] : [])];
        if (escreve) return ['--permission-mode', 'acceptEdits', '--allowedTools', 'Read', 'Grep', 'Glob', 'Edit', 'Write', 'Bash',
            '--disallowedTools', ...NEGADOS_NO_SHELL.map(c => `Bash(${c}:*)`), ...SENSIVEIS.map(p => `Read(${p})`)];
        // Sem WebFetch na leitura: era o canal para mandar um arquivo lido para fora.
        return ['--permission-mode', 'default', '--allowedTools', 'Read', 'Grep', 'Glob', 'WebSearch',
            ...LEITURA_NO_SHELL.map(c => `Bash(${c}:*)`),
            '--disallowedTools', 'Edit', 'Write', 'NotebookEdit', ...SENSIVEIS.map(p => `Read(${p})`)];
    }
    if (agente === 'codex') {
        return ['-c', `sandbox_mode="${total ? 'danger-full-access' : (escreve ? 'workspace-write' : 'read-only')}"`, '-c', 'approval_policy="never"'];
    }
    // Copilot: no -p ele exige --allow-all-tools; quem segura é o --deny-tool,
    // que vence. Na leitura, sem escrita e sem shell nenhum.
    const args = ['--add-dir', cwd];
    // No Copilot a negação vence o --allow-all.
    if (total) return [...args, '--allow-all', ...(semPublicar ? PUBLICACAO_NO_SHELL.flatMap(c => ['--deny-tool', `shell(${c})`]) : [])];
    args.push('--allow-all-tools');
    if (escreve) for (const c of NEGADOS_NO_SHELL) args.push('--deny-tool', `shell(${c})`);
    else args.push('--deny-tool', 'write', '--deny-tool', 'shell');
    return args;
}

/** Todas as ferramentas do Claude Code, para o pedido que não pode usar nenhuma. */
export const FERRAMENTAS_CLAUDE = ['Bash', 'Read', 'Grep', 'Glob', 'LS', 'Edit', 'MultiEdit', 'Write', 'NotebookEdit', 'NotebookRead',
    'WebSearch', 'WebFetch', 'Task', 'Agent', 'TodoWrite', 'BashOutput', 'KillShell', 'SlashCommand', 'Skill'];

const NOME_DE_MODELO = /^[A-Za-z0-9._-]{1,60}$/;
const ESFORCOS = ['low', 'medium', 'high'];

/**
 * Modelo e esforço do nível de custo (dono, 12/10/2026: agenda não precisa do
 * modelo mais caro). Nome estranho é ignorado: vira argumento de comando.
 */
export function modeloArgs(agente, modelo, esforco) {
    const m = typeof modelo === 'string' && NOME_DE_MODELO.test(modelo) ? modelo : null;
    const e = ESFORCOS.includes(esforco) ? esforco : null;
    if (agente === 'claude') return [...(m ? ['--model', m] : []), ...(e ? ['--effort', e] : [])];
    if (agente === 'codex') return [...(m ? ['-m', m] : []), ...(e ? ['-c', `model_reasoning_effort="${e}"`] : [])];
    // Copilot em "auto" recusa esforço ("Model auto does not support reasoning
    // effort", 13/10/2026): o esforço só vai com modelo escolhido.
    return [...(m ? ['--model', m] : []), ...(e && m && m !== 'auto' ? ['--reasoning-effort', e] : [])];
}

/**
 * "Sem IA": a lista da agenda é a resposta — nenhuma IA precisa ler. Agrupa
 * por dia quando a linha começa com a data (`AAAA-MM-DD resto`), o formato
 * da Agenda do AlfaMatriz e o que se pede a uma agenda pessoal integrada.
 */
export function agendaSemIa(rotulo, inicio, bruto, fonte = 'sua agenda') {
    const linhas = String(bruto || '').split('\n').map(l => l.trim()).filter(Boolean);
    const br = d => `${d.slice(8, 10)}/${d.slice(5, 7)}`;
    const cab = `📅 Agenda — ${rotulo}${/^\d{4}-\d{2}-\d{2}$/.test(inicio) ? ` (a partir de ${br(inicio)})` : ''}`;
    if (!linhas.length) return `${cab}\n\nNenhum compromisso no período.\n\n_Lido agora (${fonte}), sem IA._`;
    const dias = new Map();
    for (const l of linhas) {
        const m = l.match(/^(\d{4}-\d{2}-\d{2})\s+(.*)$/);
        const dia = m ? br(m[1]) : '';
        (dias.get(dia) ?? dias.set(dia, []).get(dia)).push(m ? m[2] : l);
    }
    const corpo = [...dias].map(([dia, itens]) => (dia ? `**${dia}**\n` : '') + itens.map(i => `- ${i}`).join('\n')).join('\n\n');
    return `${cab}\n\n${corpo}\n\n_${linhas.length} compromisso(s), lido(s) agora (${fonte}), sem IA._`;
}

/**
 * O método cstk (SDD) para QUALQUER IA que implemente em projeto, quando a
 * pessoa liga a opção nos Ajustes (T-374: era imposto a todos, e é o método
 * de trabalho de quem o usa, não regra da Central). Codex e Copilot não têm
 * os skills do cstk, então não implementam frente nova: devolvem ao Claude.
 * Sem projeto (chat de assunto na raiz da pasta de projetos, propostas,
 * planilhas) não se aplica: não é código de repositório.
 */
export function regraCstk(agente, projeto) {
    if (!projeto) return null;
    const comum = [
        '## Regra da pessoa: SDD com cstk',
        'Antes de escrever código novo, confira se existem docs/briefing.md e docs/constitution.md no projeto.',
        'Frente NOVA (feature, módulo, integração) só se implementa com docs/specs/<feature>/{spec,plan,tasks}.md prontos; a implementação segue as tarefas de tasks.md.',
        'Correção pontual de bug num projeto que já tem esses documentos, e ajuste de layout, texto ou configuração, não exigem o pipeline inteiro.',
    ];
    if (agente === 'claude') {
        return [...comum,
            'Você tem os skills do cstk: frente nova sem spec → rode o pipeline (/cstk:briefing e /cstk:constitution se faltarem; depois /cstk:specify → /cstk:clarify → /cstk:plan → /cstk:create-tasks → /cstk:execute-task). Bug pontual → /cstk:bugfix.',
        ].join('\n');
    }
    return [...comum,
        'Você NÃO tem os skills do cstk. Se o pedido for frente nova e o projeto não tiver a spec dela em docs/specs/, NÃO implemente: explique em uma linha e devolva no bloco final "delegar": {"agente": "claude", "modo": "implementar", "pedido": "<o pedido original>, passando pelo pipeline do cstk"}. Correção pontual ou ajuste: pode seguir.',
    ].join('\n');
}

/**
 * Qual MEMORY.md vai aos agentes, escolhido pela máquina (T-374). O servidor
 * só liga ou desliga; se ele apontasse o arquivo, quem o controlasse leria
 * qualquer arquivo da máquina pelo prompt.
 *  - `configurada` (CENTRAL_IA_MEMORIA): `nenhuma` desliga; um caminho vale
 *    se o arquivo existir;
 *  - sem valor: a memória do Claude para a pasta de projetos —
 *    ~/.claude/projects/<pasta com cada caractere fora de [A-Za-z0-9] trocado
 *    por "-">/memory/MEMORY.md, o nome que o próprio Claude Code dá.
 */
export function arquivoDeMemoria(configurada, projetos, home) {
    if (configurada === 'nenhuma') return null;
    const arq = configurada || join(home, '.claude/projects', projetos.replace(/[^A-Za-z0-9]/g, '-'), 'memory/MEMORY.md');
    return existsSync(arq) ? arq : null;
}

/**
 * O que a auditoria local grava quando a execução roda SEM pedir código
 * (T-368: a implementação liberada pela regra saía como "leitura").
 */
export function decisaoSemConfirmacao(job, regra, acesso) {
    if (job.modo !== 'implementar' && acesso === 'leitura') return { decisao: 'leitura' };
    return { decisao: 'sem_confirmacao', regra, acesso };
}

/**
 * Quanto esperar até chamar o AlfaMatriz de novo, e o que dizer no log (T-374).
 * Desconectar a máquina na tela vira 401 aqui; sem isto o runner batia a cada
 * 3 s para sempre e gravava uma linha por tentativa — ~29 mil por dia no PC
 * da pessoa, até alguém desligar o serviço na mão (teste no staging,
 * 14/10/2026). Encerrar não serve: o launchd e o systemd o religam em
 * seguida. Então avisa UMA vez e espaça; quando o acesso volta (o admin
 * liberou a conta de novo), volta ao ritmo normal sozinho. Servidor fora de
 * alcance (Tailscale desligado) é o mesmo laço, com espera menor.
 *  - `estado`: { situacao: 'ok' | 'recusado' | 'rede', espera } — comece com ESTADO_INICIAL;
 *  - `resultado`: o status HTTP da resposta, ou 'rede' quando nem houve resposta.
 * Outros erros (5xx, 429) não mudam nada: são passageiros, o laço normal cuida.
 */
export const ESPERAS = { normal: 3_000, ocioso: 10_000, ociosoApos: 5 * 60_000, recusado: [30_000, 10 * 60_000], rede: [5_000, 2 * 60_000] };
export const ESTADO_INICIAL = { situacao: 'ok', espera: ESPERAS.normal };

/**
 * Espera entre duas perguntas "tem pedido para mim?" quando o acesso está ok
 * (T-374, dono do produto, 14/10/2026). A 3 s fixos, cada máquina parada
 * fazia ~29 mil consultas por dia ao servidor, e o time inteiro multiplica
 * isso. Parada há mais de 5 min, a máquina pergunta a cada 10 s: o primeiro
 * pedido do dia espera no máximo isso. Em conversa (pedido recebido ou
 * terminado há pouco) continua a 3 s, que é quando a demora se sente.
 * `ociosoHaMs`: quanto tempo desde o último trabalho desta máquina.
 */
export function ritmoDaConsulta(ociosoHaMs) {
    return ociosoHaMs < ESPERAS.ociosoApos ? ESPERAS.normal : ESPERAS.ocioso;
}

export function acompanharAcesso(estado, resultado) {
    const situacao = resultado === 'rede' ? 'rede'
        : resultado === 401 || resultado === 403 ? 'recusado'
        : resultado >= 200 && resultado < 300 ? 'ok' : null;
    if (situacao === null) return { estado, aviso: null };
    if (situacao === 'ok') {
        return { estado: ESTADO_INICIAL, aviso: estado.situacao === 'ok' ? null : 'acesso ao AlfaMatriz de volta; voltando a pedir trabalho' };
    }
    const [inicial, maxima] = ESPERAS[situacao];
    if (estado.situacao === situacao) return { estado: { situacao, espera: Math.min(estado.espera * 2, maxima) }, aviso: null };
    return {
        estado: { situacao, espera: inicial },
        aviso: situacao === 'recusado'
            ? `acesso recusado (${resultado}): a máquina foi desconectada em Minhas máquinas ou a conta perdeu a Central. Tento de novo de tempos em tempos (até a cada ${maxima / 60_000} min), sem repetir este aviso. Para conectar de novo, gere um comando novo em Central de IA › Ajustes › Minhas máquinas.`
            : `AlfaMatriz fora de alcance (Tailscale desligado ou servidor fora do ar). Tento de novo de tempos em tempos (até a cada ${maxima / 60_000} min), sem repetir este aviso.`,
    };
}
