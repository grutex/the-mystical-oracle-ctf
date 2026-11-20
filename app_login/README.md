<p align="center">
  <img src="../docs/assets/portal.svg" alt="PORTAL CELESTIAL · Mystical Oracle" width="1040">
</p>

# Portal Mystical Oracle

*O ponto de encontro da travessia.*

O portal reúne login, cadastro, perfil, submissão de flags, gestão de contas, leaderboard, mensagens do administrador e a conclusão da travessia. Para iniciar o conjunto em localhost, siga o [README principal](../README.md).

Com essa configuração, o login é acessado em `http://localhost:8080/`, o Oráculo em `/oracle` e a leaderboard em `/leaderboard/leaderboard.html`. Em hospedagem pública, use o host definido em `PORTAL_HOST`, por exemplo `https://seu-dominio.tld/`. A raiz encaminha ao formulário `/login`; não é necessário um subdomínio `portal`.

## Acesso e perfil

O login usa e-mail e senha. O username é imutável e acompanha a conta nos desafios; o nome público pode ser alterado no perfil. A troca de senha pede a senha atual e a confirmação da nova, com pelo menos nove caracteres, uma letra maiúscula e um símbolo.

Três senhas incorretas consecutivas bloqueiam novos logins da conta por **30 minutos**, inclusive em outro navegador. Durante o bloqueio, a senha correta também é recusada, com HTTP 429 e o tempo restante no cabeçalho `Retry-After`; novas tentativas não prolongam o prazo. Uma senha correta antes do limite zera os erros, e o fim do bloqueio libera três novas chances. A regra vale para jogadores, contas de teste e administradores, preservando sessões já abertas. O início do bloqueio gera um warning nos logs. Aguardar o início ou a retomada do CTF não conta como erro de senha.

O portal emite ingressos assinados ao abrir os desafios. Cada subdomínio recebe sua própria sessão. A sessão do portal tem validade deslizante de seis horas por padrão, configurável em `SESSION_TTL`.

## Evento e administração

| Controle | Efeito |
| :--- | :--- |
| Iniciar CTF | Inicia o contador e libera o acesso dos jogadores. |
| Pausar / retomar | Congela ou retoma o tempo; a pausa invalida sessões de jogadores comuns. |
| Resetar CTF | Limpa resoluções e pontos, reinicia o contador e permite iniciar outra rodada. Preserva contas, coroas, mensagens, logs e snapshots. |
| Encerrar CTF e salvar | Bloqueia novas submissões e registra a classificação final. |
| Salvar horário | Agenda o encerramento, usando o horário escolhido pelo administrador. |
| Tirar print / salvar | Registra uma classificação e permite exportá-la. |

O contador flutuante acompanha as telas. A ajuda administrativa descreve os comandos. Resetar o CTF limpa o progresso pontuado no portal; não apaga os dados internos de cada desafio.

No perfil, **Redefinir progresso** reinicia também os estados dos portões daquela conta: apaga o selo e a autorização da Forja, reinicia suas travas, limpa reflexos, consultas e capturas de Água e faz a constelação da Luz recomeçar. A nova época de progresso isola as visitas anteriores, sem alterar salas, domínio ou fragmentos derivados da conta e de `ACCOUNT_SECRET`. Ingressos e acessos antigos são invalidados; as abas dos desafios retornam ao Oráculo na próxima verificação do contador. A sessão do portal e o progresso das outras contas são preservados.

A página `/accounts` é exclusiva do administrador, com autorização no backend. Seus checkboxes alteram `ignore_leaderboard` somente após confirmação. Contas de teste podem acessar antes do início e durante pausas; cadastros públicos começam como jogadores comuns e entram na classificação.

Em `/accounts` e na aba **Contas e logs** de `/logs`, as contas são ordenadas alfabeticamente pelo trecho do e-mail antes do `@`, ignorando maiúsculas e minúsculas. O domínio não participa da ordenação; empates usam o ID crescente da conta.

Ser uma conta de teste altera a participação no evento, sem conceder o papel de administrador. Gestão de contas, logs e envio de mensagens exigem `acc_role = 'admin'`.

## Classificação

A rota é `/leaderboard/leaderboard.html`. Jogadores comuns veem a classificação oficial; contas de teste veem uma classificação separada entre testadores. O administrador pode alternar entre as duas. Ambas usam pontos decrescentes e data da última resolução crescente. Contas empatadas nesses critérios dividem a colocação, por exemplo, 1º, 1º, 3º. Administradores e contas banidas ficam fora de ambas; contas de teste ficam fora da competição oficial.

Nomes, usernames e e-mails identificam as contas. Busca e ordenação visual não alteram a posição oficial. Histórico e exportações PNG/CSV usam os registros salvos.

O prazo é verificado no backend com o relógio do banco e uma transação que serializa submissões e encerramento. O bloqueio independe de uma aba aberta; o snapshot automático é materializado na primeira requisição após o prazo.

## Sussurros do Abismo

O painel lateral **O ABISMO**, no `/oracle`, reúne os Sussurros do Abismo e leva a voz do organizador às contas autenticadas. É um canal de avisos: jogadores e contas de teste apenas leem, sem responder.

| Conta | Acesso ao painel |
| :--- | :--- |
| Administrador | Consulta o histórico e possui a caixa de texto e o botão **Sussurrar**. |
| Jogador ou conta de teste | Consulta o histórico; os controles de envio não são adicionados à interface. |
| Visitante ou conta banida | Não pode consultar nem publicar mensagens. |

A prancheta `📋` abre o painel e `<<` o fecha; o olho permanece no topo. O contador `+N` mostra mensagens novas enquanto o painel está fechado. Receber um aviso não força sua abertura. A marca de leitura é salva por conta no navegador e compartilhada entre suas abas; outro navegador mantém sua própria marca.

Cada registro aparece como **O olho sussurra (DATA-HORA): mensagem**. O servidor registra a data em UTC e o navegador a apresenta no fuso local. Mensagens aceitam até **1.000 caracteres**, incluindo quebras de linha, e são exibidas como texto, sem executar HTML.

O histórico fica em `OracleWhispers`, com autor, conteúdo e data; o painel carrega até as 100 mensagens mais recentes. Redefinir progresso e usar **Resetar CTF** preservam os registros; **Resetar para uma nova rodada**, em `/accounts`, apaga as mensagens da rodada anterior. O administrador pode enviar avisos antes do início e após o encerramento; durante pausas, o acesso das contas segue as regras normais do evento.

O carregamento inicial consulta `GET /api/api.php?action=whispers`. As atualizações usam a verificação de status do contador a cada **sete segundos**, com marcadores para receber mensagens novas. `POST /api/api.php?action=send_whisper` exige sessão válida, CSRF e autorização administrativa no servidor; alterar a interface ou se declarar administrador no corpo do pedido não concede acesso. O envio gera um registro `admin_whisper` no histórico administrativo.

## Oráculo e submissão de flags

`/oracle` reúne os cards dos seis desafios e o formulário de submissão. Os cards mantêm o fundo e as bordas da interface; apenas o texto e o ícone recebem a cor do elemento: violeta, ciano, laranja, azul, verde e os tons de Luz & Trevas.

Em todos os desafios, inclusive Luz & Trevas, o botão **Copiar e Submeter Flag ->** copia a flag e mostra **flag copiada! redirecionando...**. Após um segundo, abre `/oracle` no host do portal configurado. O jogador cola e envia a flag no formulário; a cópia e o redirecionamento não registram pontos automaticamente. Se a cópia falhar, a página informa o erro e permite tentar novamente.

| Desafio | XP |
| :--- | ---: |
| Espírito | 100 |
| Ar | 150 |
| Fogo | 200 |
| Água | 300 |
| Terra | 250 |
| Luz & Trevas | 350 |

Total: **1.350 XP**. Espírito, Ar, Água e Terra entregam flags ligadas ao ID da conta; Fogo deriva a flag do username. Luz & Trevas exige os dois fragmentos individuais antes de emitir a flag final. Sua dificuldade no mapa é **Médio**.

## O último olhar

Ao validar as seis flags, a pupila brilha em branco. Clicar no olho inicia a conclusão: a luz se expande até cobrir a tela e apresenta **bene factum** e **Abyss te resalutat**, em sequência. O Oráculo retorna em tons de cinza; apenas a coroa mantém sua cor e aura douradas. A animação respeita a preferência de movimento reduzido do navegador.

O primeiro clique válido concede `Account.has_crown = TRUE` e registra `Account.crowned_at`. Resolver a última flag libera o olho, mas não concede a coroa por si só. `CtfInfo.oracle_finale_at` guarda o estado de conclusão do progresso atual; a validação de todas as flags é feita pelo backend.

**Rever final**, no canto superior esquerdo abaixo da barra, aparece quando o jogador possui a coroa e mantém os seis desafios resolvidos. O botão restaura as cores e a pupila branca, sem tocar a animação automaticamente. Um novo clique no olho repete o final, sem exigir recarregar a página e sem alterar a data original da coroa.

**Redefinir progresso** restaura as cores e reinicia os desafios; **Resetar CTF** limpa o progresso pontuado da rodada. Ambos preservam a coroa e sua data. O botão de replay fica indisponível até completar novamente os seis desafios. O campo legado `ctf_completed_at` permanece apenas para compatibilidade com a implementação anterior.

## Banco e contas

`db/init.sql` prepara instalações novas. `db/migrations/` reúne alterações para bancos existentes; faça backup e selecione as migrações conforme o schema instalado. Scripts de inicialização do Docker não são reaplicados a volumes já preenchidos.

| Migração | Recurso |
| :--- | :--- |
| [008 · Pontuação de Luz & Trevas](db/migrations/008_light_dark_points.sql) | Define o desafio em 350 XP para bases anteriores à implementação. |
| [010 · Logs do sistema](db/migrations/010_system_logs.sql) | Tabelas de logs, warnings, marcadores por administrador e banimento. |
| [011 · Conta Gandalf](db/migrations/011_gandalf_password.sql) | Corrige a senha da conta de teste, sem alterar o login dos desafios. |
| [012 · Água e Terra](db/migrations/012_water_earth_points.sql) | Ajusta os valores para 300 e 250 XP, atualiza resoluções existentes e recalcula totais afetados. |
| [013 · Conclusão](db/migrations/013_oracle_finale.sql) | Acrescenta o estado do final e a compatibilidade com registros anteriores. |
| [014 · Coroa](db/migrations/014_account_crown.sql) | Acrescenta a conquista permanente e sua data; preserva coroas anteriores e recupera a data do clique quando disponível. |
| [015 · Sussurros](db/migrations/015_oracle_whispers.sql) | Cria o histórico de mensagens `OracleWhispers`. |
| [016 · Bloqueio de login](db/migrations/016_login_lockout.sql) | Acrescenta contador de senhas incorretas e prazo de bloqueio à conta. |

A tabela destaca as migrações recentes, sem substituir as anteriores necessárias ao seu banco. Aplique os arquivos conforme o schema existente, na ordem das dependências; `013` precede `014`. O rebalanceamento de `012` é serializado com submissões e resets, preservando snapshots históricos.

Para criar ou atualizar uma conta, defina `ORACLE_ACCOUNT_PASSWORD` no terminal e use:

```powershell
docker compose exec -e ORACLE_ACCOUNT_PASSWORD portal-web php /var/www/html/api/manage.php test aprendiz aprendiz@oracle.mystical
```

O primeiro argumento aceita `admin`, `player`, `test` ou `logs`. Use `logs` para criar ou converter uma conta em visualizador de logs. Essa conta é encaminhada diretamente para `/logs`, não recebe dados de desafios e só pode consultar submissões, avisos e históricos individuais. A senha segue as mesmas regras das outras contas. O comando também altera contas existentes, inclusive senha, papel e participação na leaderboard. Remova a variável do terminal após o uso.

## Estrutura e verificação

Administradores e contas com `acc_role = 'logs'` acessam **Logs do Sistema** em `/logs`: submissões de flags, contas com histórico individual e `Warnings`. O visualizador `logs` não pode acessar páginas do jogo, leaderboards, contas administrativas ou operações do CTF; o conteúdo das flags submetidas fica oculto para esse papel. O contador `+x` é salvo por conta de administrador e marca registros posteriores à última abertura do popup da conta. Registros são paginados; resets de progresso e **Resetar CTF** preservam o histórico, enquanto **Resetar para uma nova rodada** remove os logs antigos e registra a própria ação. Senhas, hashes de senha e segredos não são exibidos nem registrados.

Warnings sinalizam submissões de flags honeypot, bloqueio temporário de login após 3 senhas incorretas, acesso administrativo sem permissão, login de conta banida, 5 falhas de login em 5 minutos ou 10 falhas de submissão em 60 segundos. Flags honeypot também aparecem em **Submissões de flags**, com username, e-mail e destaque dourado. São sinais para revisão, sem banimento automático; vulnerabilidades previstas nos desafios não são classificadas como infrações. O IP registrado é o endereço de acesso informado pelo gateway (`X-Real-IP`, sobrescrito pelo Nginx); um proxy ou túnel externo precisa configurar o encaminhamento confiável do IP real para identificar o visitante em vez do proxy.

Em `/accounts`, **Banir jogador** ativa `is_banned`, revoga sessões e bloqueia login, submissões e acesso aos desafios, inclusive para contas de teste. Administradores não podem ser banidos por esse botão. **Resetar para uma nova rodada** exige três confirmações; remove as demais contas, progresso, submissões, logs antigos, avisos, mensagens, snapshots e dados auxiliares dos desafios, preservando Admin, Gandalf, TheFFZ e contas com papel `logs`, e reiniciando o cronômetro. As configurações dos desafios e as credenciais preservadas permanecem. O evento de reset fica registrado nos novos logs. Bancos existentes precisam da migração `010_system_logs.sql`; instalações novas já incluem as tabelas no `init.sql`.

Instalações novas criam apenas as contas Admin, Gandalf e TheFFZ. Contas nomeadas de teste não são mais semeadas; crie-as pelo cadastro ou pelo comando de gestão quando necessário.

- `frontend/login/`: formulários e botão de mostrar/ocultar senha.
- `frontend/landing/`: Oráculo, perfil, painel `oracle-whispers.*` e animação `oracle-finale.*`.
- `frontend/leaderboard/`: classificação e controles do evento.
- `frontend/accounts/`: gestão de contas de teste.
- [`../logs/`](../logs/README.md): módulo de logs, interface administrativa e teste de histórico por conta.
- `backend/`: API, autorização, administração e persistência.
- `tests/`: verificações de autenticação, contas, sessão, evento e interface.

As suítes que criam contas, alteram progresso ou encerram o evento devem rodar em uma instalação descartável. Consulte os requisitos de cada teste antes de executá-lo.

As verificações de interface de mensagens e conclusão usam Node.js e Playwright, com contas e respostas simuladas:

```powershell
node app_login/tests/oracle-whispers.cjs
node app_login/tests/oracle-finale.cjs
```

Execute da raiz do projeto, com Playwright e um navegador disponíveis. `PLAYWRIGHT_EXECUTABLE_PATH` permite indicar um navegador instalado; `PORTAL_TEST_ARTIFACTS` aponta para uma pasta existente para capturas. Essas dependências são para desenvolvimento e não são necessárias para jogar via Docker.

`tests/oracle-whispers.php` verifica autorização, CSRF, datas, histórico e leitura por jogadores e contas de teste. Exige o banco separado `ORACLE_WHISPERS_TEST`, preparado por `tests/oracle-whispers-schema.sql`, e `SESSION_PATH` em uma pasta temporária `/tmp/oracle-whispers-*`. A suíte usa um servidor PHP local na porta `8097`; ao terminar, remova o banco de teste e suas permissões. `tests/oracle-finale.php` verifica a conquista no clique, a repetição da animação e a preservação da coroa após reset e novo login.

`tests/login-lockout.php` verifica as três chances, os 30 minutos, a liberação após o prazo, o isolamento entre contas e a preservação de sessões ativas. Exige o banco vazio `ORACLE_LOGIN_LOCKOUT_TEST`, preparado por `tests/login-lockout-schema.sql`, e `SESSION_PATH` em `/tmp/oracle-login-lockout-*`. Usa um servidor PHP local na porta `8098`; remova o banco e suas permissões depois do teste.

---

[← Voltar ao Oráculo](../README.md)
