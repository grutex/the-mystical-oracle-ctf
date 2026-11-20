FROM node:22-bookworm-slim AS scripts
WORKDIR /opt/javascript-tools
COPY tools/javascript/package*.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY tools/javascript/obfuscate.cjs tools/javascript/compact-html.cjs ./
COPY infrastructure/nginx/ /opt/oracle/nginx/
COPY ["app_chall/00. landing/frontend/", "/opt/oracle/landing/"]
RUN node obfuscate.cjs /opt/oracle

FROM nginx:1.27-alpine
COPY --from=scripts /opt/oracle/nginx/ /opt/oracle/nginx/
COPY --from=scripts /opt/oracle/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY --from=scripts /opt/oracle/nginx/oracle-runtime.js.template /etc/nginx/templates/oracle-runtime.js.template
COPY --from=scripts /opt/oracle/landing/ /srv/challenges/landing/
COPY --from=scripts /opt/oracle/nginx/favicon.conf /etc/nginx/oracle-favicon.conf
COPY --from=scripts /opt/oracle/nginx/assets/ /srv/oracle-assets/
