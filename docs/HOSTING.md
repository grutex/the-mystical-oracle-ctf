# Publicar na AWS EC2

Este guia registra a implantação usada para o Mystical Oracle. A aplicação foi validada em uma EC2 `m7i.large`; uma instância menor talvez atenda, conforme a concorrência e a carga observada. O Cloudflare foi usado para administrar o DNS do domínio e encaminhar o tráfego ao Elastic IP da EC2.

## 1. Preparar a rede

1. Crie ou selecione uma VPC.
2. Crie um Internet Gateway e associe-o à VPC.
3. Crie uma sub-rede pública.
4. Associe uma tabela de rotas à sub-rede. Mantenha a rota `local` criada pela AWS e adicione `0.0.0.0/0` com destino ao Internet Gateway.
5. Crie um Security Group com SSH (`22`) restrito ao IP de administração e portas HTTP (`80`) e HTTPS (`443`) para os visitantes.

A AWS define a sub-rede como pública quando sua tabela tem uma rota para o Internet Gateway. Instâncias também precisam de IPv4 público ou Elastic IP para tráfego IPv4 externo. Consulte [Internet Gateway e acesso à Internet na VPC](https://docs.aws.amazon.com/vpc/latest/userguide/VPC_Internet_Gateway.html).

## 2. Criar a EC2

Inicie Ubuntu na sub-rede pública e associe um Elastic IP à instância. O endereço pode ser reassociado a uma nova EC2 quando houver troca de servidor. A AWS cobra Elastic IP alocado inclusive quando não está associado; veja a [documentação de Elastic IP](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/working-with-eips.html).

## 3. Instalar Docker e clonar o projeto

Conecte à EC2 por SSH. Instale Git e Docker Engine com o plugin Compose, seguindo a [documentação oficial para Ubuntu](https://docs.docker.com/engine/install/ubuntu/). Então clone a branch do evento:

```bash
git clone --branch CTF --single-branch https://github.com/grutex/mystical-oracle-ctf.git
cd mystical-oracle-ctf
cp .env.example.example .env
nano .env
```

Configure o domínio público e defina `ACCESS_MODE=1`, `ORACLE_BIND=127.0.0.1`, `ORACLE_PORT=8080`, `PUBLIC_SCHEME=https`, `PUBLIC_PORT=443` e `COOKIE_SECURE=1`. Preencha segredos e senhas próprios antes de iniciar:

```bash
docker compose up --build -d
docker compose ps
docker compose logs --tail=100 gateway portal-web water-bot
```

## 4. Apontar DNS e configurar Caddy

No Cloudflare, crie registros `A` para o domínio raiz, o mapa e cada subdomínio dos desafios, todos apontando ao Elastic IP. Nesta implantação, o Cloudflare foi usado para DNS e proxy HTTP/HTTPS. Com acesso direto pela EC2, o túnel `cloudflared` não é necessário.

Instale Caddy pelo [repositório oficial para Debian e Ubuntu](https://caddyserver.com/docs/install) e edite `/etc/caddy/Caddyfile`:

```caddyfile
mysticaloraclectf.com.br, mapa.mysticaloraclectf.com.br, spirit.mysticaloraclectf.com.br, air.mysticaloraclectf.com.br, fire.mysticaloraclectf.com.br, water.mysticaloraclectf.com.br, depth-water.mysticaloraclectf.com.br, earth.mysticaloraclectf.com.br, light-dark.mysticaloraclectf.com.br {
    reverse_proxy 127.0.0.1:8080
}
```

Troque os nomes pelos hosts definidos no `.env`. Caddy encaminha todos eles ao gateway Nginx na porta local `8080` e mantém o cabeçalho `Host`, usado pelo Nginx para escolher cada serviço. Deixe as portas `80` e `443` acessíveis para o Caddy emitir e renovar certificados. Veja [HTTPS automático no Caddy](https://caddyserver.com/docs/automatic-https).

## 5. Verificar

Abra a raiz do domínio, confirme o login, o Oráculo, o mapa e a entrada autenticada nos seis desafios. Confira as filas do bot de Água e o contador do CTF. O bot usa a rede privada do Compose, então suas conexões internas não precisam passar pelo DNS público.

`ORACLE_PORT` é a porta local do gateway Docker. O Caddy recebe conexões externas nas portas `80` e `443` e encaminha internamente para `127.0.0.1:8080`. Não exponha a porta `8080`, os bancos ou os serviços dos desafios diretamente à Internet.
