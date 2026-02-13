# CommitDoc AI

## Overview

O CommitDoc AI automatiza a criação de documentação a partir de commits. A ferramenta conecta ao GitHub, permite escolher um repositório e uma branch, e utiliza IA para interpretar as mudanças e produzir documentação util para o time.

## Stacks

- PHP 8.x
- Laravel 12
- Inertia.js
- Vue 3
- Vuetify
- Vite

## Instalação

Instalar dependências PHP
```bash
composer install
```

Instalar dependências JS (Vite)
```bash
npm install
```

Criar arquivo de ambiente
```bash
cp .env.example .env
```

Gerar chave da aplicação
```bash
php artisan key:generate
```

## Execução

Subir o back-end
```bash
php artisan serve
```

Subir o front-end (Vite)
```bash
npm run dev
```
