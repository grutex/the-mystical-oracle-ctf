<p align="center">
  <img src="../docs/assets/portal.svg" alt="REGISTROS DO OLHO · Mystical Oracle" width="1040">
</p>

# Registros do Olho

*O que acontece na travessia permanece registrado.*

Módulo administrativo de logs do Mystical Oracle. Sua implementação fica em `logs/`, na raiz do projeto, e é executada pelo serviço `portal-web`, usando a autenticação e o banco do [portal](../app_login/README.md).

## Os registros

- **Submissões:** username, e-mail, data, IP, desafio, flag enviada e resultado, incluindo contas de teste. Sucessos aparecem em verde; falhas, em vermelho; flags honeypot, em dourado com a identificação da conta.
- **Contas:** dados das contas e popup do histórico individual. A lista segue a ordem alfabética do e-mail antes do `@`, ignorando maiúsculas e minúsculas e usando o ID crescente nos empates, como em `/accounts`. O contador `+x` indica novos registros desde a última abertura, separadamente para cada administrador.
- **Warnings:** sinais para revisão, sem banimento automático. Incluem submissões de flags honeypot, bloqueio temporário de login após 3 senhas incorretas, acesso administrativo sem permissão, login de conta banida, 5 falhas de login em 5 minutos ou 10 falhas de flags em 60 segundos. O bloqueio de login dura 30 minutos e não equivale a banimento.

A URL é `/logs` no mesmo host do portal. Administradores e contas com `acc_role = 'logs'` podem abrir a página e consultar os dados. O papel `logs` é somente leitura e não libera o portal ou os desafios. Para esse papel, o conteúdo das flags submetidas é ocultado nos logs. O HTML e o backend são instalados fora da raiz pública; somente CSS e JavaScript são publicados em `/logs/`. Senhas e seus hashes não entram nos registros.

Publicar no painel **O ABISMO**, que reúne os Sussurros do Abismo, gera o evento `admin_whisper` no histórico da conta administrativa, com referência ao ID da mensagem. O conteúdo dos avisos permanece em `OracleWhispers`; sua leitura ocorre no painel do `/oracle`. O chat e seu contador de leitura são descritos no [README do portal](../app_login/README.md#sussurros-do-abismo).

## Estrutura

```text
logs/
├── backend/
│   ├── lib.php          gravação, consulta, warnings e contadores
│   └── logs.html        página administrativa protegida
├── frontend/
│   ├── logs.css         aparência dos registros e popup
│   └── logs.js          consultas, paginação e interação
└── tests/
    └── system-logs.php  permissões, histórico, contadores e banimento
```

As rotas e a autorização continuam na API do portal. O Docker copia `backend/` para `/var/www/private/logs/` e `frontend/` para `/var/www/html/logs/`. Não há um novo serviço ou banco separado.

## Persistência e verificação

Os dados ficam nas tabelas `SystemLogs`, `Warnings` e `AdminLogReads` do MariaDB do portal. O schema e a [migração 010](../app_login/db/migrations/010_system_logs.sql) permanecem em `app_login/db/`, junto das demais migrações desse banco. Mover o módulo não requer uma nova migração; resets de progresso e do CTF preservam os registros.

O contador dos logs é persistido por administrador no banco. O `+N` dos Sussurros é um marcador separado, salvo por conta no navegador; abrir um painel não marca os registros do outro como lidos.

O teste cria contas temporárias e cobre autorização, submissões, arquivos privados, paginação, `+x` e revogação de sessões. Execute numa instalação descartável, com os serviços dos desafios ativos:

```powershell
Get-Content logs/tests/system-logs.php -Raw | docker compose exec -T portal-web php
```

Para publicar alterações do módulo, reconstrua `portal-web`. Não monte a pasta `logs/` inteira como conteúdo público.

---

[← Voltar ao Oráculo](../README.md)
