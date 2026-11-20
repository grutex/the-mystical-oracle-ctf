<p align="center">
  <img src="../../docs/assets/air.svg" alt="AR · Mystical Oracle" width="1040">
</p>

# II · Ar

*Os ventos carregam nomes; os arquivos reconhecem selos.*

**150 XP** · Controle de acesso e manipulação de requisições · Implementado

No Arquivo dos Ventos, documentos levam a outras autoridades e certificados. O desafio explora a diferença entre uma identidade declarada pelo cliente e uma autorização confiável.

## Mecânica

O acesso inicial usa `guest / guest` como mecânica da interface. O fragmento da URL aceita hashes de identidade e certificados conhecidos de 32 caracteres; ambos são resolvidos para o nome associado no card. Em Arnos, o hash de identidade é `363058e8e60aab44cd6ba20359a90c04` e o certificado é `7cdb09abe167a503ae250c96f09cd1b0`. Alterar o hash permite percorrer a cadeia de documentos e descobrir as autoridades certificadas.

O número consultado vai no corpo de uma requisição POST. A interface restringe os números aceitos, mas o servidor permite um documento que exige manipular o pedido para ser consultado.

Ao receber a flag, **Copiar e Submeter Flag ->** copia o valor, mostra **flag copiada! redirecionando...** e abre `/oracle` no portal após um segundo. O jogador cola e envia a flag no formulário para registrar os 150 XP.

## Estrutura

- `frontend/arquivo.html` e `arquivo.js`: consulta, Cartório e pistas de índices.
- `backend/consultar.php`: documentos, verificação da identidade declarada e flag individual.
- `backend/traduzir.php`: tradução de certificados para o certificador.

Host local: `air.localhost:8080`, com a configuração do [README principal](../../README.md). Em domínio: `air.<CHALLENGE_HOST>`. O ingresso do portal é obrigatório, mesmo com o login fictício do Arquivo.

<details>
<summary>Detalhes do percurso · spoilers</summary>

O documento 5 apresenta o certificado de 32 caracteres de `Arnos`: `7cdb09abe167a503ae250c96f09cd1b0`. Ele é diferente do hash de identidade da conta, `363058e8e60aab44cd6ba20359a90c04`; ambos funcionam na URL e resolvem para `Arnos`. O tradutor recebe o certificado e retorna o nome associado. Qualquer identidade conhecida acima de `guest` pode consultar os cinco primeiros documentos; `guest` mantém os acessos originais. Assumir a identidade de `Arnos` abre os documentos restritos seguintes. As pistas do JavaScript incluem os índices 132 e 497, que conduzem ao documento final e à autoridade `Vultum`.

Os selos usam os primeiros 32 caracteres hexadecimais de `sha256("VENTORUM::" + nome.lower() + "::SIGILLUM")`. O documento final exige o selo de Vultum e o número `-1337`.

Intercepte `POST /consultar.php` e ajuste `identity` e `num` no corpo. O fragmento da URL não é transmitido pelo navegador: o JavaScript envia o identificador de 32 caracteres no parâmetro de identidade. O Cartório também emite e traduz selos de 32 caracteres. O backend responde 404 para documento ausente, 403 para identidade incorreta e 200 para acesso permitido.

Os textos protegidos ficam no servidor. O documento final entrega a flag vinculada ao ID da conta.

</details>

---

[← Voltar ao Oráculo](../../README.md)
