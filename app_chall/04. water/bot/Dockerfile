FROM node:22-bookworm-slim AS scripts
WORKDIR /opt/javascript-tools
COPY tools/javascript/package*.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY tools/javascript/obfuscate.cjs tools/javascript/compact-html.cjs ./
COPY ["app_chall/04. water/bot/", "/opt/oracle/bot/"]
RUN node obfuscate.cjs /opt/oracle/bot

# A Guardiã das Marés (bot do desafio Água). Imagem oficial do Puppeteer:
# traz Node + Chromium prontos, evitando instalar navegador à mão.
FROM ghcr.io/puppeteer/puppeteer:23.6.1

WORKDIR /home/pptruser

# O contexto de build é a raiz do repositório (ver docker-compose.yml).
COPY --from=scripts /opt/oracle/bot/guardia.js ./guardia.js
COPY --from=scripts /opt/oracle/bot/scheduler.js ./scheduler.js

CMD ["node", "guardia.js"]
