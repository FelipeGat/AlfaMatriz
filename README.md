# AlfaMatriz

Painel interno da Alfa Tecnologia. Controla o negócio de software house: revendas (ex.: Invest Soluções), clientes finais, os sistemas licenciados (AlfaGym, AlfaControl, AlfaHome, AlfaMed, AlfaJornada, AlfaSchool, Gestor), preço de atacado por tier, motor de faturamento mensal das revendas e o financeiro da própria Alfa (receitas, despesas, despesas fixas, caixa).

Não é um produto vendido — é a matriz que enxerga e cobra as revendas dos demais produtos Alfa.

## Stack

- Laravel 12 + Blade + Tailwind (tema dark, marca Alfa)
- MySQL via Docker (`docker-compose.yml`)
- Alpine.js pra interatividade leve (sem SPA)

## Módulos

- **Painéis**: Financeiro (MRR, caixa, entradas/saídas) e Comercial (ranking de sistemas por clientes/valor)
- **Cadastros**: Revendas, Clientes (com endereço completo, e-mails/telefones múltiplos, busca de CNPJ/CEP), Sistemas + tiers de atacado
- **Faturamento**: gera 1 cobrança consolidada por revenda/mês, baseada nos clientes ativos de cada sistema
- **Financeiro**: Receitas, Despesas, Despesas Fixas (recorrentes), Caixa/Contas financeiras
- Fechamento mensal automatizado: `php artisan app:fechar-competencia-mensal` (agendado pro último dia do mês)

## Setup local

```bash
composer install
cp .env.example .env
php artisan key:generate
docker compose up -d
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

Login inicial: `admin@alfatecnologia.com.br` / `AlfaTecnologia@2026` (trocar depois do primeiro acesso).
