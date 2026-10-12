<?php

namespace App\Services\CentralIa;

/**
 * Segunda barreira para segredo que um agente copiou na resposta (um `.env`
 * lido, um token num log). Não substitui a análise só leitura do runner: só
 * evita que o que escapou fique gravado na conversa e vá ao Telegram.
 *
 * Mantém a aspa de abertura e de fechamento no lugar: a resposta carrega o
 * bloco JSON do dossiê, e `"password": "x"` virar `"password": [REMOVIDO]"`
 * quebrava o JSON — a tarefa perdia a dúvida e o "concluída" do agente.
 */
final class ProtecaoDeSegredos
{
    private const REMOVIDO = '[SEGREDO REMOVIDO]';

    public static function texto(string $texto): string
    {
        return preg_replace([
            '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |ENCRYPTED )?PRIVATE KEY-----.*?-----END (?:RSA |EC |DSA |OPENSSH |ENCRYPTED )?PRIVATE KEY-----/s',
            '/\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16}|AIza[0-9A-Za-z_-]{35}|xox[abposr]-[A-Za-z0-9-]{10,})\b/',
            // Token de bot do Telegram e token Sanctum (id|hash).
            '/\b\d{6,12}:[A-Za-z0-9_-]{35}\b/',
            '/\b\d{1,10}\|[A-Za-z0-9]{40,}\b/',
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',
            '/\bbase64:[A-Za-z0-9+\/]{30,}={0,2}/',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]{12,}/i',
            // Usuário e senha dentro de URL (mysql://u:senha@host).
            '/(\b[a-z][a-z0-9+.-]*:\/\/[^\s:\/@]+:)[^\s@\/]+@/i',
            // NOME=valor e "nome": "valor" — a aspa fica onde estava.
            '/((?:password|passwd|senha|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|secret|token|[A-Z][A-Z0-9_]*_(?:PASSWORD|SECRET|TOKEN|KEY))["\x27]?\s*[:=]\s*)(["\x27]?)(?!\[SEGREDO|\[REMOVIDO)[^\s,"\x27;}]{4,}/i',
        ], [self::REMOVIDO, self::REMOVIDO, self::REMOVIDO, self::REMOVIDO, self::REMOVIDO, self::REMOVIDO, 'Bearer [REMOVIDO]', '$1[REMOVIDO]@', '$1$2[REMOVIDO]'], $texto) ?? '[Conteúdo indisponível]';
    }

    public static function dados(mixed $dados): mixed
    {
        if (is_string($dados)) return self::texto($dados);
        if (is_array($dados)) return array_map(self::dados(...), $dados);
        return $dados;
    }
}
