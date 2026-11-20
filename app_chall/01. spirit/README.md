<p align="center">
  <img src="../../docs/assets/spirit.svg" alt="ESPÍRITO · Mystical Oracle" width="1040">
</p>

# I · Espírito

*O invisível também deixa rastros.*

**100 XP** · Reconhecimento web · Implementado

O primeiro portão ensina a observar além da tela, interpretar uma pista codificada e encontrar o Nome que abre o Véu. A ambientação usa névoa e tons violetas.

## Mecânica

O código-fonte contém uma pista em Base64. Ela conduz à sala do Véu, cujo HTML referencia um grimório de nomes oculto na interface. O jogador testa os candidatos até encontrar o correspondente à sua conta.

A escolha é determinística, derivada de `ACCOUNT_SECRET` e do ID da conta. A flag individual só é emitida depois da validação no servidor.

Ao receber a flag, **Copiar e Submeter Flag ->** copia o valor, mostra **flag copiada! redirecionando...** e abre `/oracle` no portal após um segundo. O jogador cola e envia a flag no formulário para registrar os 100 XP.

## Estrutura

- `frontend/oracle_spirit.html`: portão e pista inicial.
- `frontend/0232a57db92c1c62161124a6ed855f96/`: templates do Véu e do grimório.
- `infrastructure/challenge/`, na raiz do projeto: páginas autenticadas, escolha do Nome e recompensa compartilhada.

Host local: `spirit.localhost:8080`, com a configuração do [README principal](../../README.md). Em domínio: `spirit.<CHALLENGE_HOST>`. Abra pelo portal autenticado.

<details>
<summary>Detalhes do percurso · spoilers</summary>

A pista Base64 decodifica para o MD5 de `veil`. A rota pública é `/veil`; a pasta interna não é a rota de navegação. Um elemento oculto aponta para `/whispers`, onde estão os candidatos.

Em `/veil?q=<nome>`, tentativas incorretas respondem com HTTP 403; o acerto responde com 200. A interface também envia o Nome a `POST /reward.php`, que emite a flag da conta autenticada.

</details>

---

[← Voltar ao Oráculo](../../README.md)
