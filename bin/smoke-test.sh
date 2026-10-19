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

PAGE=$(curl -s -b "$JAR" "$BASE?r=admin")   # erst vollständig lesen: grep -q in einer Pipe bricht curl sonst zufällig ab
if grep -q "Version $(tr -d '[:space:]' < "$ROOT/VERSION")" <<<"$PAGE"; then
  ok "Version im Dashboard"
else
  fail "Version wird im Dashboard nicht angezeigt"
fi

echo "Plugins"
check "Plugin-Verwaltung" 200 "$BASE?r=admin&a=plugins"
check "Plugin-Asset (Stationen)" 200 "$BASE?r=pluginasset&p=stations&f=css/stations.css"
check "Plugin-Asset: Pfadtrick wird abgelehnt" 404 "$BASE?r=pluginasset&p=stations&f=../plugin.php"
check "Plugin-Asset: PHP-Datei wird abgelehnt" 404 "$BASE?r=pluginasset&p=stations&f=js/../../plugin.php"
check "Plugin-Export" 200 "$BASE?r=admin&a=pluginexport&p=stations"
C=$(csrf "admin&a=plugins")
check "Kern-Plugin löschen wird abgelehnt" 302 -d "_csrf=$C&slug=stations&delete_data=1" "$BASE?r=admin&a=plugindelete"
check "Stationen nach Löschversuch weiter vorhanden" 200 "$BASE?r=stations"
check "Stationen deaktivieren" 302 -d "_csrf=$C&slug=stations&state=off" "$BASE?r=admin&a=pluginset"
check "Deaktiviert: Route nicht mehr da" 404 "$BASE?r=stations"
check "Deaktiviert: Asset nicht mehr da" 404 "$BASE?r=pluginasset&p=stations&f=css/stations.css"
check "Stationen aktivieren" 302 -d "_csrf=$C&slug=stations&state=on" "$BASE?r=admin&a=pluginset"
check "Aktiviert: Route wieder da" 200 "$BASE?r=stations"

mkdir -p "$TMP/zip/ok" "$TMP/zip/bad"
php -r '
  $mk = function (string $f, array $files) { $z = new ZipArchive(); $z->open($f, ZipArchive::CREATE); foreach ($files as $n => $c) { $z->addFromString($n, $c); } $z->close(); };
  $mk($argv[1] . "/ok/smoke.zip", [
    "smoke-plug/plugin.json" => json_encode(["slug" => "smoke-plug", "name" => "Smoke", "version" => "1.0.0", "stores" => ["smoke_data" => []]]),
    "smoke-plug/plugin.php" => "<?php\nreturn function (PluginApi \$p): void { \$p->route(\"smokeplug\", \"c.php\", \"ctrl_smokeplug\"); };\n",
    "smoke-plug/c.php" => "<?php\nfunction ctrl_smokeplug(string \$r, string \$a): void { Store::update(\"smoke_data\", function (array &\$d) { \$d[] = 1; }); echo \"smoke-ok\"; }\n",
  ]);
  $mk($argv[1] . "/bad/traversal.zip", ["plugin.json" => json_encode(["slug" => "evil", "name" => "E", "version" => "1.0.0"]), "plugin.php" => "<?php\n", "../evil.php" => "<?php\n"]);
  $mk($argv[1] . "/bad/type.zip", ["plugin.json" => json_encode(["slug" => "evil2", "name" => "E", "version" => "1.0.0"]), "plugin.php" => "<?php\n", ".htaccess" => "x"]);
  $mk($argv[1] . "/bad/syntax.zip", ["plugin.json" => json_encode(["slug" => "evil3", "name" => "E", "version" => "1.0.0"]), "plugin.php" => "<?php\nreturn function ( {\n"]);
' "$TMP/zip"
C=$(csrf "admin&a=plugins")
for z in traversal type syntax; do
  check "Import abgelehnt: $z" 302 -F "_csrf=$C" -F "plugin_zip=@$TMP/zip/bad/$z.zip" "$BASE?r=admin&a=pluginimport"
done
if ls "$TMP/app/plugins" | grep -q '^evil'; then fail "Unzulässiges Plugin wurde installiert"; else ok "Unzulässige Plugins nicht installiert"; fi
check "Import" 302 -F "_csrf=$C" -F "plugin_zip=@$TMP/zip/ok/smoke.zip" "$BASE?r=admin&a=pluginimport"
check "Importiertes Plugin ist inaktiv (404)" 404 "$BASE?r=smokeplug"
check "Importiertes Plugin aktivieren" 302 -d "_csrf=$C&slug=smoke-plug&state=on" "$BASE?r=admin&a=pluginset"
if [ "$(curl -s -b "$JAR" "$BASE?r=smokeplug")" = "smoke-ok" ]; then ok "Plugin-Route antwortet"; else fail "Plugin-Route antwortet nicht"; fi
check "Aktives Plugin löschen wird abgelehnt" 302 -d "_csrf=$C&slug=smoke-plug" "$BASE?r=admin&a=plugindelete"
[ -d "$TMP/app/plugins/smoke-plug" ] && ok "Aktives Plugin bleibt" || fail "Aktives Plugin wurde gelöscht"
check "Plugin deaktivieren" 302 -d "_csrf=$C&slug=smoke-plug&state=off" "$BASE?r=admin&a=pluginset"
check "Plugin mit Daten löschen" 302 -d "_csrf=$C&slug=smoke-plug&delete_data=1" "$BASE?r=admin&a=plugindelete"
if [ -d "$TMP/app/plugins/smoke-plug" ] || [ -f "$TMP/app/data/smoke_data.json" ]; then fail "Plugin oder Daten sind noch vorhanden"; else ok "Plugin und Daten entfernt"; fi

echo "Checkliste: Verschieben und Sortieren"
CL="$TMP/app/data/checklist.json"
clq() { php -r '$d = json_decode(file_get_contents($argv[1]), true); eval($argv[2]);' "$CL" "$1"; }
C=$(csrf checklist)
CAT1=$(clq 'echo $d["categories"][0]["id"];'); CAT2=$(clq 'echo $d["categories"][1]["id"];')
IDS=$(clq '$n = 0; foreach ($d["items"] as $i) { if ($i["cat"] === $d["categories"][0]["id"] && empty($i["parent"]) && $n++ < 3) echo $i["id"], " "; }')
read -r A B D <<<"$IDS"
check "Reihenfolge ändern" 200 -H 'X-Requested-With: fetch' -d "_csrf=$C&id=$B&cat=$CAT1&parent=&ids[]=$B&ids[]=$A&ids[]=$D" "$BASE?r=checklist&a=move"
[ "$(clq '$o = array_column($d["items"], "sort", "id"); echo $o["'$B'"] < $o["'$A'"] ? "ok" : "no";')" = ok ] && ok "Reihenfolge gespeichert" || fail "Reihenfolge nicht gespeichert"
check "In andere Kategorie verschieben" 200 -H 'X-Requested-With: fetch' -d "_csrf=$C&id=$D&cat=$CAT2&parent=&ids[]=$D" "$BASE?r=checklist&a=move"
[ "$(clq '$i = array_column($d["items"], null, "id")["'$D'"]; echo $i["cat"] === "'$CAT2'" ? "ok" : "no";')" = ok ] && ok "Kategorie gewechselt" || fail "Kategorie nicht gewechselt"
check "Unter Hauptaufgabe ziehen" 200 -H 'X-Requested-With: fetch' -d "_csrf=$C&id=$B&cat=$CAT1&parent=$A&ids[]=$B" "$BASE?r=checklist&a=move"
[ "$(clq '$i = array_column($d["items"], null, "id")["'$B'"]; echo $i["parent"] === "'$A'" ? "ok" : "no";')" = ok ] && ok "Wurde Unteraufgabe" || fail "Unteraufgabe nicht gesetzt"
check "Aufgabe mit Unteraufgaben wird nicht Unteraufgabe" 422 -H 'X-Requested-With: fetch' -d "_csrf=$C&id=$A&cat=$CAT1&parent=$D&ids[]=$A" "$BASE?r=checklist&a=move"
check "Unbekannte Kategorie wird abgelehnt" 422 -H 'X-Requested-With: fetch' -d "_csrf=$C&id=$A&cat=gibtesnicht&parent=&ids[]=$A" "$BASE?r=checklist&a=move"
check "Zurück auf oberste Ebene" 200 -H 'X-Requested-With: fetch' -d "_csrf=$C&id=$B&cat=$CAT2&parent=&ids[]=$B" "$BASE?r=checklist&a=move"

echo "Export und Import"
C=$(csrf admin\&a=backup)
PKG="$TMP/export.zip"
EXP="_csrf=$C&parts[]=settings&parts[]=groups&parts[]=configphp&parts[]=memorial&parts[]=gallery&parts[]=contacts&parts[]=checklist&parts[]=plugindata&parts[]=users&parts[]=logs"
check "Export ohne Auswahl wird abgelehnt (302)" 302 -d "_csrf=$C" "$BASE?r=admin&a=transferexport"
got=$(curl -s -o "$PKG" -w '%{http_code}' -b "$JAR" -c "$JAR" -d "$EXP" "$BASE?r=admin&a=transferexport")
[ "$got" = 200 ] && ok "Export ($got)" || fail "Export: erwartet 200, erhalten $got"
if php -r '$z = new ZipArchive(); exit($z->open($argv[1]) === true && $z->locateName("manifest.json") !== false && $z->locateName("data/checklist.json") !== false && $z->locateName("config/config.json") !== false ? 0 : 1);' "$PKG"; then ok "Paket enthält Manifest, Daten und Konfiguration"; else fail "Paket ist unvollständig"; fi
php -r '$f = $argv[1]; $d = json_decode(file_get_contents($f), true); $d["items"] = []; $d["categories"] = []; file_put_contents($f, json_encode($d));' "$TMP/app/data/checklist.json"
upload() { curl -s -D - -o /dev/null -b "$JAR" -c "$JAR" -F "_csrf=$C" -F "package=@$1" "$BASE?r=admin&a=transferimport"; }
LOC=$(upload "$PKG" | tr -d '\r' | sed -n 's/^[Ll]ocation: //p')
TOKEN=$(sed -n 's/.*token=\([a-f0-9]*\).*/\1/p' <<<"$LOC")
if [ -n "$TOKEN" ]; then ok "Paket wird geprüft (Vorschau)"; else fail "Keine Vorschau: $LOC"; fi
check "Vorschau" 200 "$BASE?r=admin&a=transferpreview&token=$TOKEN"
check "Unbekanntes Paket (Token)" 302 "$BASE?r=admin&a=transferpreview&token=0000"
check "Import ohne Auswahl" 302 -d "_csrf=$C&token=$TOKEN" "$BASE?r=admin&a=transferapply"
check "Import (Checkliste, Gedenkseite)" 302 -d "_csrf=$C&token=$TOKEN&parts[]=checklist&parts[]=memorial&parts[]=configphp" "$BASE?r=admin&a=transferapply"
if [ "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo count($d["categories"]) > 0 ? "ok" : "no";' "$TMP/app/data/checklist.json")" = ok ]; then ok "Checkliste wiederhergestellt"; else fail "Checkliste nicht wiederhergestellt"; fi
ls "$TMP/app/data/backups" | grep -q '^vor-import-' && ok "Sicherung vor dem Import" || fail "Keine Sicherung vor dem Import"
[ ! -d "$TMP/app/data/tmp/import-$TOKEN" ] && ok "Entpacktes Paket entfernt" || fail "Entpacktes Paket bleibt liegen"
check "Abgelaufenes Paket wird abgelehnt" 302 -d "_csrf=$C&token=$TOKEN&parts[]=checklist" "$BASE?r=admin&a=transferapply"

# fehlerhafte Pakete
printf 'kein zip' > "$TMP/bad.zip"
LOC=$(upload "$TMP/bad.zip" | tr -d '\r' | sed -n 's/^[Ll]ocation: //p'); grep -q transferpreview <<<"$LOC" && fail "Kein ZIP wurde angenommen" || ok "Kein ZIP wird abgelehnt"
php -r '$z = new ZipArchive(); $z->open($argv[1], ZipArchive::CREATE); $z->addFromString("../evil.json", "{}"); $z->addFromString("data/../../x.json", "{}"); $z->addFromString("data/users.php", "<?php"); $z->addFromString("data/checklist.json", "{\"categories\": 1, \"items\": []}"); $z->close();' "$TMP/evil.zip"
LOC=$(upload "$TMP/evil.zip" | tr -d '\r' | sed -n 's/^[Ll]ocation: //p'); grep -q transferpreview <<<"$LOC" && fail "Ungültige Checkliste wurde angenommen" || ok "Ungültiges Paket wird abgelehnt"
[ ! -e "$TMP/app/data/evil.json" ] && [ ! -e "$TMP/app/evil.json" ] && ok "Pfadtricks im Paket wirkungslos" || fail "Datei außerhalb geschrieben"
php -r '$z = new ZipArchive(); $z->open($argv[1], ZipArchive::CREATE); $z->addFromString("data/users.json", json_encode([["id" => "u1", "username" => "x", "role" => "user", "active" => true]])); $z->close();' "$TMP/noadmin.zip"
LOC=$(upload "$TMP/noadmin.zip" | tr -d '\r' | sed -n 's/^[Ll]ocation: //p'); T2=$(sed -n 's/.*token=\([a-f0-9]*\).*/\1/p' <<<"$LOC")
check "Benutzer ohne Administrator werden nicht importiert" 302 -d "_csrf=$C&token=$T2&parts[]=users" "$BASE?r=admin&a=transferapply"
[ "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo count($d);' "$TMP/app/data/users.json")" = 1 ] && ok "Benutzer unverändert" || fail "Benutzer wurden überschrieben"
check "Paket verwerfen" 302 -d "_csrf=$C&token=$T2" "$BASE?r=admin&a=transferdrop"
LOC=$(upload "$PKG" | tr -d '\r' | sed -n 's/^[Ll]ocation: //p'); T3=$(sed -n 's/.*token=\([a-f0-9]*\).*/\1/p' <<<"$LOC")
check "Import der Benutzer meldet ab" 302 -d "_csrf=$C&token=$T3&parts[]=users" "$BASE?r=admin&a=transferapply"
check "Nach Benutzerimport: Anmeldung nötig" 302 "$BASE?r=admin"
C=$(csrf login)
check "Erneute Anmeldung" 302 -d "_csrf=$C&username=smoke&password=smoketest-1234" "$BASE?r=login"

echo "Web-API und MCP"
C=$(csrf admin\&a=plugins)
check "API-Plugin ist standardmäßig inaktiv (404)" 404 "$BASE?r=api&p=/me"
check "API-Plugin aktivieren" 302 -d "_csrf=$C&slug=api&state=on" "$BASE?r=admin&a=pluginset"
mktoken() { # <Bereich> <Bezeichnung>
  local c; c=$(csrf apitokens)
  curl -s -o /dev/null -b "$JAR" -c "$JAR" -d "_csrf=$c&label=$2&scope=$1&days=30" "$BASE?r=apitokens&a=create"
  curl -s -b "$JAR" -c "$JAR" "$BASE?r=apitokens" | sed -n 's/.*id="newToken"[^>]*value="\(fo_[a-f0-9]*\)".*/\1/p;s/.*value="\(fo_[a-f0-9]*\)" id="newToken".*/\1/p' | head -1
}
check "Token-Verwaltung" 200 "$BASE?r=apitokens"
TR=$(mktoken read smoke-read); TW=$(mktoken write smoke-write)
[ -n "$TR" ] && [ -n "$TW" ] && ok "Token erstellt" || fail "Token wurden nicht angezeigt"
! grep -qF -e "$TR" -e "$TW" "$TMP/app/data/api_tokens.json" && ok "Klartext-Token wird nicht gespeichert" || fail "Klartext-Token in der Datei"
api() { local t=$1; shift; curl -s -o "$TMP/api.out" -w '%{http_code}' -H "Authorization: Bearer $t" "$@"; }
apicheck() { # <Beschreibung> <Status> <Token> <curl-Argumente>
  local what=$1 want=$2 t=$3; shift 3
  local got; got=$(api "$t" "$@")
  if [ "$got" = "$want" ]; then ok "$what ($got)"; else fail "$what: erwartet $want, erhalten $got ($(head -c 200 "$TMP/api.out"))"; fi
}
jq_() { php -r '$d = json_decode(file_get_contents($argv[1]), true); eval("echo " . $argv[2] . ";");' "$TMP/api.out" "$1"; }
check "Ohne Token: 401" 401 -H 'Cookie:' "$BASE?r=api&p=/me"
check "Mit Sitzungscookie aber ohne Token: 401" 401 "$BASE?r=api&p=/me"
apicheck "Falsches Token: 401" 401 fo_0000 "$BASE?r=api&p=/me"
apicheck "/me" 200 "$TR" "$BASE?r=api&p=/me"
[ "$(jq_ '$d["data"]["scope"] . $d["data"]["role"]')" = readadmin ] && ok "/me liefert Bereich und Rolle" || fail "/me: unerwartete Antwort"
apicheck "OpenAPI (ohne Token)" 200 x "$BASE?r=api&p=/openapi.json"
apicheck "Unbekannter Pfad" 404 "$TR" "$BASE?r=api&p=/gibtesnicht"
apicheck "Falsche Methode" 405 "$TR" -X DELETE "$BASE?r=api&p=/me"
apicheck "Lesetoken darf nicht schreiben" 403 "$TR" -H 'Content-Type: application/json' -d '{"first":"Anna","last":"API"}' "$BASE?r=api&p=/contacts"
apicheck "Kontakt anlegen" 200 "$TW" -H 'Content-Type: application/json' -d '{"first":"Anna","last":"API","city":"Detmold","rsvp":"yes","adults":2}' "$BASE?r=api&p=/contacts"
CID=$(jq_ '$d["data"]["id"]')
apicheck "Kontakt ohne Namen: 422" 422 "$TW" -H 'Content-Type: application/json' -d '{"city":"x"}' "$BASE?r=api&p=/contacts"
apicheck "Kontakt ändern" 200 "$TW" -X PATCH -H 'Content-Type: application/json' -d '{"called":true,"rsvp":"no"}' "$BASE?r=api&p=/contacts/$CID"
[ "$(jq_ '($d["data"]["called"] ? "1" : "0") . $d["data"]["rsvp"] . $d["data"]["city"]')" = "1noDetmold" ] && ok "Änderung übernommen, übrige Felder bleiben" || fail "Kontakt nach Änderung unerwartet"
apicheck "Ungültiger RSVP-Wert: 422" 422 "$TW" -X PATCH -H 'Content-Type: application/json' -d '{"rsvp":"vielleicht"}' "$BASE?r=api&p=/contacts/$CID"
apicheck "Kontaktliste mit Filter" 200 "$TR" "$BASE?r=api&p=/contacts&filter=rsvp_no&q=anna"
[ "$(jq_ '$d["data"]["total"]')" = 1 ] && ok "Filter und Suche" || fail "Kontaktliste unerwartet: $(head -c 200 "$TMP/api.out")"
apicheck "Ungültiges JSON: 400" 400 "$TW" -H 'Content-Type: application/json' -d '{kaputt' "$BASE?r=api&p=/contacts"
apicheck "Rückmeldesummen" 200 "$TR" "$BASE?r=api&p=/rsvp/summary"
apicheck "Zusammenfassung" 200 "$TR" "$BASE?r=api&p=/summary"
apicheck "Kategorie anlegen" 200 "$TW" -H 'Content-Type: application/json' -d '{"name":"API-Test"}' "$BASE?r=api&p=/checklist/categories"
KID=$(jq_ '$d["data"]["id"]')
apicheck "Aufgabe anlegen" 200 "$TW" -H 'Content-Type: application/json' -d "{\"title\":\"Blumen bestellen\",\"cat\":\"$KID\",\"due\":\"2026-12-01\"}" "$BASE?r=api&p=/checklist/tasks"
AID=$(jq_ '$d["data"]["id"]')
apicheck "Unteraufgabe anlegen" 200 "$TW" -H 'Content-Type: application/json' -d "{\"title\":\"Angebot einholen\",\"parent\":\"$AID\"}" "$BASE?r=api&p=/checklist/tasks"
SID=$(jq_ '$d["data"]["id"]')
apicheck "Aufgabe abhaken" 200 "$TW" -X PATCH -H 'Content-Type: application/json' -d '{"done":true}' "$BASE?r=api&p=/checklist/tasks/$AID"
[ "$(jq_ '$d["data"]["done"] ? "1" : "0"')" = 1 ] && ok "Aufgabe erledigt" || fail "Aufgabe nicht erledigt"
apicheck "Unteraufgabe nach oben verschieben" 200 "$TW" -H 'Content-Type: application/json' -d "{\"cat\":\"$KID\",\"parent\":\"\",\"position\":0}" "$BASE?r=api&p=/checklist/tasks/$SID/move"
[ "$(jq_ '$d["data"]["parent"] === "" ? "ok" : "no"')" = ok ] && ok "Verschoben auf oberste Ebene" || fail "Verschieben fehlgeschlagen"
apicheck "Zweite Unteraufgabe anlegen" 200 "$TW" -H 'Content-Type: application/json' -d "{\"title\":\"Rechnung prüfen\",\"parent\":\"$AID\"}" "$BASE?r=api&p=/checklist/tasks"
apicheck "Aufgabe mit Unteraufgaben wird nicht Unteraufgabe" 422 "$TW" -H 'Content-Type: application/json' -d "{\"parent\":\"$SID\"}" "$BASE?r=api&p=/checklist/tasks/$AID/move"
apicheck "Checkliste lesen" 200 "$TR" "$BASE?r=api&p=/checklist&show=open"
apicheck "Aufgabe löschen" 200 "$TW" -X DELETE "$BASE?r=api&p=/checklist/tasks/$AID"
apicheck "Kategorie löschen" 200 "$TW" -X DELETE "$BASE?r=api&p=/checklist/categories/$KID"
apicheck "Gedenkseite ändern" 200 "$TW" -X PATCH -H 'Content-Type: application/json' -d '{"name":"Maria Muster","born":"1941"}' "$BASE?r=api&p=/memorial"
apicheck "Gedenkseite lesen" 200 "$TR" "$BASE?r=api&p=/memorial"
[ "$(jq_ '$d["data"]["name"]')" = "Maria Muster" ] && ok "Gedenkseite aktualisiert" || fail "Gedenkseite unerwartet"
apicheck "Album anlegen" 200 "$TW" -H 'Content-Type: application/json' -d '{"name":"Kindheit"}' "$BASE?r=api&p=/gallery/albums"
apicheck "Alben lesen" 200 "$TR" "$BASE?r=api&p=/gallery/albums"
apicheck "Bilder lesen" 200 "$TR" "$BASE?r=api&p=/gallery/images"
apicheck "Stationen lesen" 200 "$TR" "$BASE?r=api&p=/stations"
apicheck "Kontakt löschen" 200 "$TW" -X DELETE "$BASE?r=api&p=/contacts/$CID"
apicheck "Gelöschter Kontakt: 404" 404 "$TR" "$BASE?r=api&p=/contacts/$CID"

mcp() { api "$1" -H 'Content-Type: application/json' -d "$2" "$BASE?r=mcp"; }
[ "$(mcp "$TR" '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"1"}}}')" = 200 ] && ok "MCP initialize (200)" || fail "MCP initialize"
[ "$(jq_ '$d["result"]["protocolVersion"] . $d["result"]["serverInfo"]["name"]')" = "2025-06-18funeralorg" ] && ok "MCP Protokollversion und Servername" || fail "MCP initialize unerwartet"
[ "$(mcp "$TR" '{"jsonrpc":"2.0","method":"notifications/initialized"}')" = 202 ] && ok "MCP Notification (202)" || fail "MCP Notification"
[ "$(mcp "$TR" '{"jsonrpc":"2.0","id":2,"method":"tools/list"}')" = 200 ] && ok "MCP tools/list (200)" || fail "MCP tools/list"
READ_TOOLS=$(jq_ 'count($d["result"]["tools"])'); [ "$READ_TOOLS" -gt 10 ] && ok "MCP Lesetoken sieht $READ_TOOLS Tools" || fail "MCP Tools: $READ_TOOLS"
grep -q 'contacts_create' "$TMP/api.out" && fail "Lesetoken sieht Schreib-Tools" || ok "Lesetoken sieht keine Schreib-Tools"
mcp "$TW" '{"jsonrpc":"2.0","id":3,"method":"tools/list"}' >/dev/null; grep -q 'contacts_create' "$TMP/api.out" && ok "Schreibtoken sieht Schreib-Tools" || fail "Schreibtoken sieht keine Schreib-Tools"
mcp "$TR" '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"contacts_create","arguments":{"first":"X"}}}' >/dev/null
[ "$(jq_ '$d["result"]["isError"] ? "err" : "ok"')" = err ] && ok "MCP: Schreib-Tool mit Lesetoken wird abgelehnt" || fail "MCP: Schreib-Tool lief mit Lesetoken"
mcp "$TW" '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"contacts_create","arguments":{"first":"Mcp","last":"Test"}}}' >/dev/null
[ "$(jq_ '$d["result"]["isError"] ? "err" : "ok"')" = ok ] && ok "MCP: Kontakt angelegt" || fail "MCP: Kontakt nicht angelegt ($(head -c 200 "$TMP/api.out"))"
mcp "$TR" '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"contacts_list","arguments":{"q":"mcp"}}}' >/dev/null
grep -q 'Mcp' "$TMP/api.out" && ok "MCP: Kontakt gefunden" || fail "MCP: Kontakt nicht gefunden"
mcp "$TR" '{"jsonrpc":"2.0","id":7,"method":"gibtes/nicht"}' >/dev/null
[ "$(jq_ '$d["error"]["code"]')" = -32601 ] && ok "MCP: unbekannte Methode (-32601)" || fail "MCP: Fehlercode"
[ "$(api "$TR" "$BASE?r=mcp")" = 405 ] && ok "MCP: GET wird abgelehnt (405)" || fail "MCP: GET"
[ "$(api x -H 'Content-Type: application/json' -d '{}' "$BASE?r=mcp")" = 401 ] && ok "MCP ohne gültiges Token (401)" || fail "MCP ohne Token"
[ "$(api "$TR" -H 'Origin: https://boese.example' -H 'Content-Type: application/json' -d '{}' "$BASE?r=mcp")" = 403 ] && ok "MCP: fremder Origin (403)" || fail "MCP: Origin-Prüfung"

# Bilder: Upload, Metadaten, Datei, Reihenfolge, MCP-Bildansicht
php -r '$im = imagecreatetruecolor(40, 30); imagejpeg($im, $argv[1]);' "$TMP/pic.jpg"
apicheck "Lesetoken darf nicht hochladen" 403 "$TR" -F "files[]=@$TMP/pic.jpg" "$BASE?r=api&p=/gallery/images"
apicheck "Bild hochladen" 200 "$TW" -F "files[]=@$TMP/pic.jpg" "$BASE?r=api&p=/gallery/images"
[ "$(jq_ '$d["uploaded"]')" = 1 ] && ok "Ein Bild hochgeladen" || fail "Upload: $(head -c 200 "$TMP/api.out")"
apicheck "Bilderliste" 200 "$TW" "$BASE?r=api&p=/gallery/images"
IID=$(jq_ '$d["data"]["items"][0]["id"]')
apicheck "Bildbeschreibung ändern" 200 "$TW" -X PATCH -H 'Content-Type: application/json' -d '{"caption":"Am See"}' "$BASE?r=api&p=/gallery/images/$IID"
apicheck "Bilddatei (Vorschau)" 200 "$TW" "$BASE?r=api&p=/gallery/images/$IID/file&size=thumb"
head -c 3 "$TMP/api.out" | grep -q $'\xff\xd8\xff' && ok "Bilddatei ist ein JPEG" || fail "Bilddatei ist kein JPEG"
apicheck "Reihenfolge setzen" 200 "$TW" -X PUT -H 'Content-Type: application/json' -d "{\"ids\":[\"$IID\"]}" "$BASE?r=api&p=/gallery/albums/_none/order"
mcp "$TW" "{\"jsonrpc\":\"2.0\",\"id\":8,\"method\":\"tools/call\",\"params\":{\"name\":\"gallery_image_view\",\"arguments\":{\"id\":\"$IID\",\"size\":\"thumb\"}}}" >/dev/null
[ "$(jq_ '$d["result"]["content"][0]["type"] . $d["result"]["content"][0]["mimeType"]')" = "imageimage/jpeg" ] && ok "MCP: Bild als Bildinhalt" || fail "MCP: Bildansicht: $(head -c 200 "$TMP/api.out")"
apicheck "Bild löschen" 200 "$TW" -X DELETE "$BASE?r=api&p=/gallery/images/$IID"

# Widerruf
TID=$(php -r '$d = json_decode(file_get_contents($argv[1]), true); foreach ($d["tokens"] as $t) { if ($t["label"] === "smoke-read") echo $t["id"]; }' "$TMP/app/data/api_tokens.json")
C=$(csrf apitokens)
check "Token widerrufen" 302 -d "_csrf=$C&id=$TID" "$BASE?r=apitokens&a=revoke"
apicheck "Widerrufenes Token: 401" 401 "$TR" "$BASE?r=api&p=/me"
# deaktivierter Benutzer / Passwortwechsel machen Token ungültig
php -r '$f = $argv[1]; $d = json_decode(file_get_contents($f), true); $d[0]["pw_changed"] = time() + 5; file_put_contents($f, json_encode($d));' "$TMP/app/data/users.json"
apicheck "Nach Passwortwechsel: 401" 401 "$TW" "$BASE?r=api&p=/me"
php -r '$f = $argv[1]; $d = json_decode(file_get_contents($f), true); unset($d[0]["pw_changed"]); file_put_contents($f, json_encode($d));' "$TMP/app/data/users.json"
check "Plugin deaktivieren" 302 -d "_csrf=$C&slug=api&state=off" "$BASE?r=admin&a=pluginset"
apicheck "Deaktiviertes Plugin: 404" 404 "$TW" "$BASE?r=api&p=/me"

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
