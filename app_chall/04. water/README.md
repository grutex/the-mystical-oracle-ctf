<p align="center">
  <img src="../../docs/assets/water.svg" alt="ÁGUA · Mystical Oracle" width="1040">
</p>

# IV · Água

*O reflexo fica. A correnteza leva.*

**300 XP** · Stored XSS e Reflected XSS · Implementado

Água divide a travessia entre o Espelho da superfície e o Abismo. Duas revisoras visitam conteúdos do jogador, cada uma com informações exclusivas do seu contexto privado.

## Mecânica

Na superfície, o jogador publica um reflexo que a Guardiã revisa. A filtragem de HTML é deliberadamente insuficiente, permitindo XSS armazenado. O primeiro objetivo é obter o ingresso das profundezas.

No Abismo, a Arquivista abre uma consulta. O termo aparece sem escape na página de revisão, permitindo XSS refletido. Essa execução deve enviar a mensagem individual e o recibo à Correnteza.

O jogador consulta `correnteza.php` e preenche **manualmente** mensagem e recibo no Espelho. A recompensa valida ambos no backend antes de emitir a flag.

Ao receber a flag, **Copiar e Submeter Flag ->** copia o valor, mostra **flag copiada! redirecionando...** e abre `/oracle` no portal após um segundo. O jogador cola e envia a flag no formulário para registrar os **300 XP**, valor atual após o rebalanceamento.

## Estrutura

- `frontend/espelho.*` e `backend/`: mural, revisão e coleta da superfície.
- `depth/frontend/abismo.js`: consulta e função real `revelarIngresso()`.
- `depth/backend/`: ingresso, solicitações, revisão e coletor das profundezas.
- `bot/guardia.js`: visitas automatizadas com Puppeteer.
- `infrastructure/private/water-chain.php`, na raiz: suporte compartilhado à cadeia.

Hosts locais: `water.localhost:8080` e `depth-water.localhost:8080`, com a configuração do [README principal](../../README.md). Em domínio: `water.<CHALLENGE_HOST>` e `depth-water.<CHALLENGE_HOST>`. O volume `water-data` mantém os registros.

## Filas, concorrência e atualização da Correnteza

O fluxo de uma captura é: o jogador envia um reflexo ou consulta, o conteúdo entra na fila, a revisora visita a página, o payload XSS executa e envia os dados ao coletor `correnteza.php`. O Espelho consulta as capturas da própria conta para exibi-las. Uma visita sem um payload que realize a coleta pode ser marcada como revisada sem produzir uma captura.

| Configuração | Padrão | O que controla |
| :--- | ---: | :--- |
| `GUARDIA_CONCURRENCY` | `2` | Limite global de visitas simultâneas do bot, entre `1` e `8`, somando Guardiã e Arquivista. |
| `GUARDIA_INTERVAL_MS` | `4000` | Pausa de quatro segundos depois de processar um ciclo das filas. O tempo das visitas se soma a essa pausa. |
| Consulta da Correnteza no Espelho | `3000` ms | Busca novas capturas a cada três segundos enquanto a aba está visível e não há outra consulta em andamento. Esse intervalo está em `frontend/espelho.js`, não no `.env`. |

O bot mantém contextos de navegador separados. As visitas de uma mesma conta continuam sequenciais; contas diferentes podem ser revisadas em paralelo. As filas retornam lotes de até 20 contas na superfície e 20 solicitações nas profundezas. A concorrência limita as visitas em execução e não a quantidade de jogadores conectados.

Para configurar, edite o **`.env` da raiz do projeto**, na máquina que executa o Docker Compose. Por exemplo, para processar uma visita por vez:

```dotenv
GUARDIA_CONCURRENCY=1
GUARDIA_INTERVAL_MS=4000
```

Quando as variáveis não estão definidas, o Compose usa concorrência `2` e intervalo `4000` ms. Depois de editar o `.env`, aplique a configuração recriando o serviço quando necessário:

```bash
docker compose up -d water-bot
```

Aumentar a concorrência pode reduzir a espera na fila, mas aumenta o consumo de CPU e memória do Chromium. Em uma máquina com pouca RAM, comece com `1`; teste valores maiores gradualmente, acompanhando `docker stats`, erros do bot e tempo de revisão. Dimensione pela quantidade de visitas pendentes e pelos recursos disponíveis, não apenas pelo total de participantes.

Como exemplo idealizado, 20 visitas independentes de contas diferentes, com duração fixa de cinco segundos cada, levariam aproximadamente 100 segundos com concorrência `1`, 50 com `2`, 25 com `4` e 15 com `8`. No último caso são três lotes: oito, oito e quatro visitas. Esses valores não incluem a pausa entre ciclos, variação das visitas ou disputa pelos recursos da máquina e não são um benchmark do jogo.

O bot publica na Correnteza quando o payload executa a coleta; não existe envio automático a cada sete segundos. A mensagem e o recibo já capturados são apresentados manualmente no formulário. Sua validação ocorre diretamente no backend, sem aguardar outra visita do bot. O Espelho suspende as consultas periódicas em abas ocultas, atualiza ao retornar à aba e encerra o polling da Correnteza após receber a flag.

Mantenha uma única instância do bot: o controle que impede visitas simultâneas da mesma conta pertence ao agendador desse processo. Consulte também o [guia de desempenho](../../docs/PERFORMANCE.md).

<details>
<summary>Detalhes do percurso · spoilers</summary>

Em `reflexo.php`, a filtragem neutraliza apenas tags `script`; handlers de eventos continuam disponíveis. O cabeçalho `X-Mare-Correnteza` revela o pedido de coleta. No contexto da Guardiã, `window.__MARES__.arquivo` contém o ingresso que deve ser enviado ao coletor.

Após entrar no Abismo, envie este termo no formulário **Solicitar à Arquivista**:

~~~html
<img src=x onerror="revelarIngresso()">
~~~

A consulta refletida é aberta pelo bot. `abismo.js` carrega antes do termo ser inserido, então a função já está disponível quando o `onerror` executa. Ela publica `window.__ARQUIVO__` em `window.__CORRENTEZA__`; esses dados existem apenas no contexto autorizado da Arquivista. Um alerta isolado demonstra execução, mas não conclui a coleta.

A Correnteza retorna `mensagem` e `recibo` para a conta autenticada. Copie-os para o formulário de recompensa. A mensagem deriva de `ACCOUNT_SECRET` e do ID da conta; o recibo está ligado à revisão. Descobrir somente a mensagem não basta.

O bot usa contextos separados e limita requisições à origem de cada visita. `WATER_BOT_TOKEN` autentica o bot; não é a seed da mensagem nem da flag.

</details>

---

[← Voltar ao Oráculo](../../README.md)
