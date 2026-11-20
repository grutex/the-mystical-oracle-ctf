<p align="center">
  <img src="../../docs/assets/earth.svg" alt="TERRA · Mystical Oracle" width="1040">
</p>

# V · Terra

*Uma câmara guarda a cifra. Outra guarda a chave.*

**250 XP** · SQL injection e decifração · Implementado

O Relicário parece um catálogo de peças antigas. Sua busca permite investigar outras tabelas e reunir os elementos necessários para decifrar o tesouro.

## Mecânica

O endpoint concatena a entrada em uma consulta SQL real no MariaDB. As respostas incluem a consulta, os resultados ou o erro do banco, permitindo descobrir a estrutura antes da extração.

O objetivo é recuperar cifra e chave de câmaras distintas. A interface oferece uma Câmara de Decifração; a prova obtida é validada no servidor e trocada por uma flag individual.

Ao receber a flag, **Copiar e Submeter Flag ->** copia o valor, mostra **flag copiada! redirecionando...** e abre `/oracle` no portal após um segundo. O jogador cola e envia a flag no formulário para registrar os **250 XP**, valor atual após o rebalanceamento.

## Estrutura

- `frontend/catalogo.*`: busca, resultados e decifração.
- `backend/catalogo.php`: ponto de SQL injection.
- `db/init.sql`: relíquias, cofre, chaveiro e usuário de leitura.
- `infrastructure/challenge/reward.php`, na raiz: validação da prova e recompensa.

Host local: `earth.localhost:8080`, com a configuração do [README principal](../../README.md). Em domínio: `earth.<CHALLENGE_HOST>`. O banco `terra_relicario` é separado do portal e persiste em `earth-data`. O usuário `relic_ro` tem acesso de leitura.

<details>
<summary>Detalhes do percurso · spoilers</summary>

A consulta tem duas colunas:

```sql
SELECT nome, descricao FROM relicarios WHERE nome LIKE '%<entrada>%'
```

A cadeia pretendida usa `UNION SELECT` para explorar `information_schema` e descobrir `cofre` e `chaveiro`. A primeira tabela fornece algoritmo e cifra; a segunda fornece a chave.

A cifra usa Base64 sobre bytes transformados por XOR com chave repetida. A decifração local é uma conveniência; `POST /reward.php` valida a prova antes de entregar a flag da conta.

No MariaDB, `--` precisa ser seguido de espaço ou caractere de controle para iniciar um comentário. `-- -` mantém esse espaço visível e evita perdê-lo ao copiar.

</details>

---

[← Voltar ao Oráculo](../../README.md)
