#!/usr/bin/env bash
# Rauchtest: kopiert das Projekt in ein temporäres Verzeichnis, startet den eingebauten PHP-Server,
# richtet die Anwendung ein und prüft die wichtigsten Routen, Rechte und „Als Benutzer anmelden“.
# Aufruf: bin/smoke-test.sh          (Port wählbar mit PORT=18081; Exit-Code 1 bei Fehlern)
# Verändert weder data/ noch configs/config.php des Projekts.
set -uo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
PORT=${PORT:-18080}
BASE="http://127.0.0.1:$PORT/index.php"
TMP=$(mktemp -d)
JAR="$TMP/cookies.txt"
FAILS=0
PID=""

cleanup() {
  [ -n "$PID" ] && kill "$PID" 2>/dev/null
  rm -rf "$TMP"
}
trap cleanup EXIT

ok()   { echo "  ok   $1"; }
fail() { echo "  FAIL $1"; FAILS=$((FAILS + 1)); }

# check <Beschreibung> <erwarteter HTTP-Code> <Methode+Argumente für curl ...>
check() {
  local what=$1 want=$2; shift 2
  local got
  got=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "$@")
  if [ "$got" = "$want" ]; then ok "$what ($got)"; else fail "$what: erwartet $want, erhalten $got"; fi
}

csrf() {
  curl -s -b "$JAR" -c "$JAR" "$BASE?r=$1" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'
}

mkdir -p "$TMP/app" "$TMP/data"
tar -C "$ROOT" --exclude=./data --exclude=./configs/config.php --exclude=./.git --exclude=./.idea -cf - . | tar -C "$TMP/app" -xf -
mkdir -p "$TMP/app/data"

php -S "127.0.0.1:$PORT" -t "$TMP/app" >"$TMP/server.log" 2>&1 &
PID=$!
for _ in $(seq 1 50); do
  curl -s -o /dev/null "$BASE?r=login" && break
  sleep 0.1
done

echo "Einrichtung und Anmeldung"
check "Ohne Benutzer: Weiterleitung zur Einrichtung" 302 "$BASE?r=home"
C=$(csrf setup)
check "Einrichtung" 302 -d "_csrf=$C&username=smoke&name=Smoke&password=smoketest-1234&password2=smoketest-1234&deceased=Test&base_url=http://127.0.0.1:$PORT" "$BASE?r=setup"
check "Einrichtung ist danach gesperrt" 404 "$BASE?r=setup"
check "Ohne Anmeldung: Weiterleitung zum Login" 302 "$BASE?r=home"
C=$(csrf login)
check "Falsches Passwort bleibt auf der Anmeldeseite" 200 -d "_csrf=$C&username=smoke&password=falsch-falsch" "$BASE?r=login"
C=$(csrf login)
check "Anmeldung" 302 -d "_csrf=$C&username=smoke&password=smoketest-1234" "$BASE?r=login"
check "POST ohne CSRF-Token wird abgelehnt" 419 -d "x=1" "$BASE?r=logout"

echo "Seiten als Administrator"
for r in home stations checklist contacts rsvp condolences gallery account admin "admin&a=users" "admin&a=groups" \
         "admin&a=shares" "admin&a=log" "admin&a=backup"; do
  check "$r" 200 "$BASE?r=$r"
done

echo "Benutzer anlegen und als Benutzer anmelden"
GROUP=$(php -r '$g = json_decode(file_get_contents($argv[1]), true); echo $g[0]["id"] ?? "";' "$TMP/app/data/groups.json")
C=$(csrf "admin&a=users")
check "Benutzer anlegen" 302 -d "_csrf=$C&username=anna&name=Anna&password=annaanna-1234&role=user&group_id=$GROUP&active=1" "$BASE?r=admin&a=usersave"
UID_=$(php -r 'foreach (json_decode(file_get_contents($argv[1]), true) as $u) { if ($u["username"] === "anna") echo $u["id"]; }' "$TMP/app/data/users.json")
C=$(csrf "admin&a=users")
check "Als Benutzer anmelden" 302 -d "_csrf=$C&id=$UID_" "$BASE?r=admin&a=impersonate"
check "Verwaltung ist als Benutzer gesperrt" 403 "$BASE?r=admin"
check "Kontoseite ist während der Übernahme gesperrt" 403 "$BASE?r=account"
C=$(csrf home)
check "Logout beendet nur die Übernahme" 302 -d "_csrf=$C" "$BASE?r=logout"
check "Danach wieder Administrator" 200 "$BASE?r=admin&a=users"

echo "Abmelden"
C=$(csrf home)
check "Logout" 302 -d "_csrf=$C" "$BASE?r=logout"
check "Nach dem Logout: Weiterleitung zum Login" 302 "$BASE?r=home"

echo "Server-Log"
if grep -Eq 'PHP (Warning|Notice|Fatal error|Deprecated)' "$TMP/server.log"; then
  fail "PHP-Meldungen im Server-Log:"
  grep -E 'PHP (Warning|Notice|Fatal error|Deprecated)' "$TMP/server.log" | head -10
else
  ok "keine PHP-Warnungen"
fi

echo
if [ "$FAILS" -eq 0 ]; then echo "Alle Prüfungen bestanden."; else echo "$FAILS Prüfung(en) fehlgeschlagen."; exit 1; fi
