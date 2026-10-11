# Constituição — AlfaMatriz

Regras extraídas de CLAUDE.md, README.md e docs/central-ia/README.md.
- Laravel/Blade/Alpine; reutilizar tokens e classes existentes.
- Conversas e arquivos privados por conta, inclusive para administradores.
- Um consumidor do Telegram. Nenhum scheduler ou worker genérico no banco local.
- Credenciais fora do banco, navegador, logs e Git.
- Alterações de produção apenas com pedido explícito.
- Validação em SQLite isolado, sem integrações reais.
