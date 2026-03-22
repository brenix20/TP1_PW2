# Aulas - Arranque Rapido

## Requisitos
- XAMPP instalado (Apache + MariaDB)
- PHP e MariaDB disponiveis no XAMPP

## 1) Preparar configuracao de ambiente
Na pasta `php`, cria o ficheiro `.env` a partir do exemplo:

```bash
cp php/.env.example php/.env
```

Depois edita o ficheiro `php/.env` com as tuas credenciais locais:

```env
IPCAVNF_DB_HOST=localhost
IPCAVNF_DB_PORT=3306
IPCAVNF_DB_NAME=ipcavnf
IPCAVNF_DB_USER=SEU_UTILIZADOR_DB
IPCAVNF_DB_PASS=SUA_PASSWORD_DB
```

## 2) Garantir base de dados
Cria/importa a base de dados `ipcavnf` no phpMyAdmin (ou MariaDB CLI).

## 3) Iniciar servicos
No XAMPP, inicia:
- Apache
- MySQL/MariaDB

## 4) Abrir aplicacao
Abre no browser:

- `http://localhost/Ipca/Aulas/php/login.php`

## Nota
- O ficheiro `.env` contem segredos e nao deve ser partilhado.
- O `.env.example` e apenas modelo para outras pessoas configurarem localmente.
