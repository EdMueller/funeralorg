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
