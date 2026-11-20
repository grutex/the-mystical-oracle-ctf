# Desempenho e validação

## Melhorias aplicadas

- Status, perfil, ranking, histórico e listagem de contas usam uma transação de leitura consistente, sem adquirir a trava global de `ContestState`. Flags, pontuação, pausa, reset e encerramento continuam serializados. Uma leitura que detecta o prazo vencido passa pelo encerramento transacional e gera um único resultado final.
- A verificação BCrypt do login ocorre antes da trava do evento. A conta e o hash são conferidos novamente dentro da transação, preservando alterações de senha e banimentos concorrentes.
- PHP usa OPcache para reutilizar o código compilado. SQLite reutiliza a conexão dentro da requisição, espera até 5 segundos por uma escrita concorrente e possui índices para as consultas da Água. A Forja verifica a coluna `role` antes de tentar migrar bancos antigos.
- O bot da Água processa contas diferentes em paralelo, com contextos de navegador separados. Visitas da superfície e das profundezas da mesma conta continuam sequenciais. Cada fila tem lotes de até 20; a superfície prioriza o reflexo pendente mais antigo.
- O relógio comum e o Espelho evitam consultas sobrepostas, abortam consultas após 10 segundos, suspendem polling em abas ocultas e atualizam ao voltar à aba.
- Apache libera conexões ociosas após 2 segundos. A Forja mantém 10 processos prontos e uma reserva maior, pois suas quatro travas precisam responder dentro da janela original de 300 ms. A latência artificial de 350 ms permanece intacta.

As vulnerabilidades didáticas, flags, XP, tokens por conta, isolamento das sessões e fluxo dos desafios foram preservados.

## Aplicar no ambiente existente

Na raiz do projeto, em um intervalo sem jogadores resolvendo o Fogo:

```powershell
docker compose up --build -d
docker compose restart gateway
docker compose ps
docker compose logs --tail=50 water-bot
```

O segundo comando faz o Nginx resolver novamente os serviços recriados. Os volumes persistentes são mantidos. O rebuild/restart do Fogo reinicia seu APCu: travas e estado temporário do cofre deixam de existir; os pontos e flags já apresentados ao portal permanecem no banco.

Nenhuma migração manual de MariaDB é necessária para estas mudanças. Os índices de SQLite são criados automaticamente. `.env` e o arquivo de exemplo permanecem como estavam.

## Ajustes opcionais

Os padrões do Compose já funcionam sem adicionar variáveis ao `.env`:

| Variável | Padrão | Efeito |
| --- | ---: | --- |
| `GUARDIA_CONCURRENCY` | 2 | Visitas simultâneas entre contas diferentes, de 1 a 8. `1` restaura o processamento serial. |
| `FIRE_MAX_WORKERS` | 150 | Limite de processos Apache na Forja. Aumentar exige RAM e CPU disponíveis. |

Mantenha **uma única instância do bot**. O agendador impede concorrência entre visitas da mesma conta dentro desse processo; a fila ainda não oferece reserva distribuída para múltiplos bots.

A Água ainda depende de uma fila de visitas reais de Chromium, incluindo navegação e espera de 1,5 segundo. Maior concorrência consome mais memória; o throughput precisa ser medido no servidor escolhido. Um lote de 120 jogadores pode aguardar vários ciclos de revisão.

O Fogo ocupa quatro processos por tentativa paralela. Com 120 jogadores tentando as quatro travas exatamente ao mesmo tempo, há um pico teórico de 480 requisições. O limite padrão de 150 não garante esse cenário. Dimensione processos com base na memória medida e teste o desafio sob carga, preservando sua janela de 300 ms. Não escale o Fogo para múltiplos contêineres sem compartilhar seu estado APCu.

## Repetir a validação isolada

Os comandos abaixo usam outro projeto Docker, bancos, sessões e volumes. A porta 9088 fica restrita ao computador local. As credenciais em `infrastructure/tests/performance.env` são exclusivas do teste descartável.

```powershell
$perfCompose = @('-p', 'oracle-performance', '--env-file', 'infrastructure/tests/performance.env', '-f', 'docker-compose.yml', '-f', 'infrastructure/tests/performance.compose.yml')
docker compose @perfCompose up --build -d
docker compose @perfCompose restart gateway

docker compose @perfCompose exec -T portal-web php /performance-tests/performance-reads.php
python infrastructure/tests/performance-load.py
docker compose @perfCompose exec -T portal-web php /performance-tests/performance-water.php

docker compose @perfCompose exec -T portal-web php /tests/login-race.php
docker compose @perfCompose exec -T portal-web php /tests/progress-reset.php
docker compose @perfCompose exec -T portal-web php /tests/password-profile.php
docker compose @perfCompose exec -T portal-web php /tests/accounts.php
docker compose @perfCompose exec -T portal-web php /tests/oracle-finale.php
docker compose @perfCompose exec -T portal-web php /light-tests/integration.php
node 'app_chall/04. water/bot/scheduler.test.cjs'
node app_login/tests/domain-routing.cjs
```

O teste de carga cria 120 sessões e realiza 360 consultas autenticadas, com 120 clientes concorrentes. Ele valida status, identidade da sessão e presença dos jogadores no ranking; não resolve os desafios. Requer Python 3. O teste do bot coloca payloads somente nas três contas descartáveis e verifica seis capturas, selos e recibos.

Para testar polling e relógio em Chromium usando a imagem do bot:

```powershell
docker cp infrastructure/nginx/oracle-runtime.js.template oracle-performance-water-bot-1:/tmp/oracle-runtime-test.js
docker cp 'app_chall/04. water/frontend/espelho.js' oracle-performance-water-bot-1:/tmp/oracle-water-test.js
docker cp infrastructure/tests/performance-polling.cjs oracle-performance-water-bot-1:/home/pptruser/polling-test.cjs
docker cp app_login/tests/timer.cjs oracle-performance-water-bot-1:/home/pptruser/timer-test.cjs
docker compose @perfCompose exec -T water-bot node /home/pptruser/polling-test.cjs
docker compose @perfCompose exec -T water-bot node /home/pptruser/timer-test.cjs
```

Limpeza **apenas do projeto de testes**:

```powershell
docker compose @perfCompose down -v
```

## Resultados e limites

Validação em 08/10/2026, no Docker local, com o ambiente do evento também ligado:

- 120 sessões autenticadas e ranking com 120 jogadores: 360 consultas por rodada, sem erros. Nas duas rodadas com esse ranking, o tempo total variou de 1,17 a 3,88 s; mediana de 286 a 763 ms, p95 de 356 a 2.041 ms e máximo de 408 a 2.412 ms. A variação mostra a influência da carga compartilhada da máquina; não é uma comparação antes/depois.
- OPcache confirmado ativo em requisições HTTP atendidas pelo Apache, além da validação de sintaxe de Apache, Compose e dos arquivos PHP alterados.
- Status/ranking responderam enquanto outra transação mantinha `ContestState` travado. O encerramento por prazo criou exatamente um snapshot final.
- Testes funcionais de login e rotação de cookies, senhas, contas, logs e banimento, sussurros, pausa/retomada, reset do evento, pontuação, coroa/finale e Luz/Escuridão passaram em dados descartáveis.
- Fogo: quatro chamadas sequenciais não abrem; quatro paralelas abrem; outra conta permanece fechada; reset revoga ingressos e estado antigo.
- Água: bot real executou Stored XSS e Reflected XSS em três contas distintas, com selos e recibos corretos. O agendador também foi testado com 120 contas e 240 visitas simuladas.
- Chromium confirmou pausa/retomada do relógio, suspensão das consultas em abas ocultas, ausência de sobreposição e recuperação após timeout.

Esses testes não certificam 120 pessoas jogando todos os desafios simultaneamente. Antes do evento, repita no servidor final uma carga com login, leituras, submissões corretas/incorretas, Fogo em paralelo e filas da Água, medindo CPU, RAM, latências, erros e tempo de revisão do bot. O túnel, a internet residencial e a suspensão do notebook também influenciam a disponibilidade.
