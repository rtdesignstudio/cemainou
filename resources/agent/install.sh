#!/bin/sh
# cemainou - conectează Claude Code într-o singură comandă: serverul MCP, comanda /upd (restul se spune agentului în cuvinte) și hook-urile
# și hook-urile care măsoară timpul (un ping pe minut cât lucrezi, niciodată conținut).
# Folosire, o dată, într-un terminal (comanda din profil: Agenți → Conectează un agent):
#   curl -fsS <url>/agent/install.sh | sh -s -- <url> <cod>
# Codul (wsc_..., valabil 10 minute, o dată) se schimbă pe cheie la server; merge și o cheie dată direct ('3|wsa_...').
# Nimic nu se schimbă până nu merg descărcările și cheia; la orice eșec de după, conectarea MCP abia adăugată se scoate la loc.
# Al treilea argument, „client" (contul unui client): doar serverul MCP și permisiunea uneltelor; fără /upd, fără hook-uri, fără timp măsurat.
set -u
HELP='Nu merge? Problemele știute și soluțiile lor: https://rt.md/cemainou/ro/documentatie/#probleme'
trap 'c=$?; [ $c -ne 0 ] && [ $c -ne 3 ] && echo "$HELP" >&2' EXIT   # la orice eșec, unde se găsește ajutorul
URL=${1:-}
TOKEN=${2:-}
MODE=${3:-}
if [ -z "$URL" ] || [ -z "$TOKEN" ]; then
  echo "folosire: install.sh <url> <cod>" >&2
  exit 2
fi
case "$TOKEN" in
  wsc_*|*\|*) ;;
  *) echo "Codul sau cheia par incomplete - copiază comanda întreagă din profil." >&2; exit 2 ;;
esac
URL=${URL%/}
case "$URL" in
  https://*|http://localhost*|http://127.0.0.1*) ;;
  *) echo "Adresa trebuie să fie https (sau localhost, pentru dezvoltare): $URL" >&2; exit 2 ;;
esac
HOME_DIR=${HOME:?}
if ! command -v claude >/dev/null 2>&1; then                            # instalat, dar folderul lui nu e în PATH-ul acestui terminal: îl căutăm
  for c in "$HOME_DIR/.local/bin/claude" "$HOME_DIR/.claude/local/claude" "$HOME_DIR/.npm-global/bin/claude" \
           "$HOME_DIR/.volta/bin/claude" "$HOME_DIR/.bun/bin/claude" "$HOME_DIR"/.nvm/versions/node/*/bin/claude; do
    [ -x "$c" ] && { PATH="$(dirname "$c"):$PATH"; export PATH; break; }
  done
fi
CLI=1
if ! command -v claude >/dev/null 2>&1; then                            # fără comanda claude (Claude Code din aplicația desktop): scriem direct în ~/.claude.json
  CLI=0
  if ! command -v node >/dev/null 2>&1 && ! python3 -c 1 >/dev/null 2>&1; then
    cat >&2 <<'MSG'
Nu găsesc Claude Code (comanda claude) și nici node sau python3, ca să scriu conectarea direct.
- Folosești Claude Code în terminal? Instalează-l (https://docs.claude.com/en/docs/claude-code) sau deschide un terminal nou și rulează din nou comanda.
- Folosești doar aplicația Claude (desktop sau telefon)? În cemainou, Profil → Agenți, conectează aplicația Claude, fără comandă.
MSG
    exit 1
  fi
fi
command -v curl >/dev/null 2>&1 || { echo "Lipsește curl." >&2; exit 1; }

# 1. descărcările, într-un folder temporar: dacă ceva nu merge, nimic nu s-a schimbat încă
tmp=$(mktemp -d 2>/dev/null || mktemp -d -t cemainou) || exit 1
trap 'c=$?; rm -rf "$tmp"; [ $c -ne 0 ] && [ $c -ne 3 ] && echo "$HELP" >&2' EXIT
FILES='upd.md brief.md history.md ctx.md idea.md task.md work.md heartbeat.sh session-start.sh'
[ "$MODE" = client ] && FILES=''
for f in $FILES; do   # comenzile vechi: doar pentru cine le are deja
  curl -fsS "$URL/agent/$f" -o "$tmp/$f" || { echo "Nu am putut descărca $URL/agent/$f (serverul e pornit? adresa e bună?)." >&2; exit 1; }
done

# 2. codul se schimbă pe cheie (o singură dată); apoi cheia, verificată cu o cerere MCP goală (ping)
case "$TOKEN" in
  wsc_*)
    TOKEN=$(curl -fsS --max-time 10 -X POST "$URL/api/connect" -H 'Accept: text/plain' --data-urlencode "code=$TOKEN" 2>/dev/null) || TOKEN=''
    case "$TOKEN" in
      *\|*) ;;
      *) echo "Codul de conectare a expirat sau a fost folosit deja. Creează altul din profil (Agenți → Conectează un agent)." >&2; exit 1 ;;
    esac ;;
esac
code=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 -X POST "$URL/mcp" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -H 'MCP-Protocol-Version: 2026-07-28' \
  --data '{"jsonrpc":"2.0","id":1,"method":"ping"}' 2>/dev/null || echo 000)
case "$code" in
  200|202) ;;
  401|403) echo "Serverul nu acceptă cheia (HTTP $code): e greșită, revocată sau contul e inactiv. Creează alta din profil." >&2; exit 1 ;;
  *) echo "Nu am putut verifica cheia (HTTP $code); continui." >&2 ;;
esac

# 3. serverul MCP în Claude Code (o conectare veche, din orice scope și sub numele vechi, whatsupai, se înlocuiește). Cu comanda claude, prin ea;
#    fără ea (Claude Code din aplicația desktop), direct în ~/.claude.json, același fișier pe care îl citește și aplicația. Cheia trece prin mediu, nu pe linia de comandă.
mcp_json() {                                                       # $1: add | remove
  CEMAINOU_MCP_URL="$URL/mcp" CEMAINOU_MCP_TOKEN="$TOKEN" CEMAINOU_MCP_DO="$1" CEMAINOU_MCP_FILE="$HOME_DIR/.claude.json" sh -c '
    if command -v node >/dev/null 2>&1; then node -e "
      const fs = require(\"fs\"), e = process.env, f = e.CEMAINOU_MCP_FILE; let s = {};
      try { s = JSON.parse(fs.readFileSync(f, \"utf8\")); } catch {}
      const drop = (o) => { if (o && o.mcpServers) { delete o.mcpServers.whatsupai; delete o.mcpServers.cemainou; } };
      drop(s); Object.values(s.projects || {}).forEach(drop);
      if (e.CEMAINOU_MCP_DO === \"add\") { s.mcpServers = s.mcpServers || {}; s.mcpServers.cemainou = { type: \"http\", url: e.CEMAINOU_MCP_URL, headers: { Authorization: \"Bearer \" + e.CEMAINOU_MCP_TOKEN } }; }
      fs.writeFileSync(f + \".cemainou\", JSON.stringify(s, null, 2) + \"\\n\", { mode: 0o600 }); fs.renameSync(f + \".cemainou\", f);"
    else python3 -c "
import json, os
e = os.environ; f = e[\"CEMAINOU_MCP_FILE\"]
try:
    s = json.load(open(f))
except Exception:
    s = {}
def drop(o):
    if isinstance(o, dict) and isinstance(o.get(\"mcpServers\"), dict):
        o[\"mcpServers\"].pop(\"whatsupai\", None); o[\"mcpServers\"].pop(\"cemainou\", None)
drop(s)
for p in (s.get(\"projects\") or {}).values(): drop(p)
if e[\"CEMAINOU_MCP_DO\"] == \"add\":
    s.setdefault(\"mcpServers\", {})[\"cemainou\"] = {\"type\": \"http\", \"url\": e[\"CEMAINOU_MCP_URL\"], \"headers\": {\"Authorization\": \"Bearer \" + e[\"CEMAINOU_MCP_TOKEN\"]}}
fd = os.open(f + \".cemainou\", os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
with os.fdopen(fd, \"w\") as out:
    json.dump(s, out, indent=2); out.write(\"\\n\")
os.replace(f + \".cemainou\", f)"
    fi'
}
if [ "$CLI" = 1 ]; then
  for n in whatsupai cemainou; do
    claude mcp remove --scope user "$n" >/dev/null 2>&1
    claude mcp remove --scope local "$n" >/dev/null 2>&1
  done
  if ! err=$(claude mcp add --scope user --transport http cemainou "$URL/mcp" --header "Authorization: Bearer $TOKEN" 2>&1 >/dev/null); then
    echo "Nu am putut adăuga serverul MCP (claude mcp add): $(printf '%s' "$err" | sed "s/$TOKEN/<cheie>/g")" >&2
    exit 1
  fi
  undo() { claude mcp remove --scope user cemainou >/dev/null 2>&1; echo "$1 Conectarea a fost scoasă la loc; rulează comanda din nou." >&2; exit 1; }
else
  mcp_json add || { echo "Nu am putut scrie conectarea în $HOME_DIR/.claude.json." >&2; exit 1; }
  echo "Comanda claude nu e pe acest calculator: am scris conectarea direct în ~/.claude.json (o citește și aplicația Claude)."
  undo() { mcp_json remove; echo "$1 Conectarea a fost scoasă la loc; rulează comanda din nou." >&2; exit 1; }
fi

# 4. comenzile scurte, scriptul de hook și config-ul cu adresa și cheia (600); un client n-are nimic din ele
if [ "$MODE" != client ]; then
mkdir -p "$HOME_DIR/.claude/commands" "$HOME_DIR/.config/cemainou" || undo "Nu pot scrie în $HOME_DIR."
rm -f "$HOME_DIR/.claude/commands/upd-auto.md"            # comanda veche, renunțată
grep -qs project_context "$HOME_DIR/.claude/commands/context.md" && rm -f "$HOME_DIR/.claude/commands/context.md"   # /context al nostru acoperea comanda Claude Code: acum e /ctx
cp "$tmp/upd.md" "$HOME_DIR/.claude/commands/upd.md" || undo "Nu am putut scrie comanda /upd."
for c in brief history ctx idea task work; do                       # una singură se instalează (/upd); cele vechi, puse cândva, se țin la zi
  [ -f "$HOME_DIR/.claude/commands/$c.md" ] && { cp "$tmp/$c.md" "$HOME_DIR/.claude/commands/$c.md" || undo "Nu am putut scrie comanda /$c."; }
done
for f in heartbeat.sh session-start.sh; do
  cp "$tmp/$f" "$HOME_DIR/.config/cemainou/$f" && chmod 700 "$HOME_DIR/.config/cemainou/$f" || undo "Nu am putut scrie scriptul $f."
done
curl -fsS --max-time 10 "$URL/agent/version" 2>/dev/null | tr -cd '0-9a-f' | cut -c1-12 > "$HOME_DIR/.config/cemainou/version"   # de aici încolo se actualizează singur
umask 077
ONLY_LINE=$(cat "$HOME_DIR/.config/cemainou/config" "$HOME_DIR/.config/whatsupai/config" 2>/dev/null | grep -E '^(CEMAINOU|WHATSUPAI)_ONLY=' | head -1 | sed 's/^WHATSUPAI_/CEMAINOU_/')   # alegerea omului rămâne la reinstalare
{
  printf "CEMAINOU_URL='%s'\nCEMAINOU_TOKEN='%s'\n" "$URL" "$TOKEN"
  printf '# Doar sesiunile din aceste foldere ajung la cemainou (separate cu :). Fără rândul de mai jos, din orice folder.\n'
  if [ -n "$ONLY_LINE" ]; then printf '%s\n' "$ONLY_LINE"; else printf '# CEMAINOU_ONLY="$HOME/Clienti:$HOME/RT"\n'; fi
} > "$HOME_DIR/.config/cemainou/config" || undo "Nu am putut scrie config-ul."
fi

# 5. hook-urile în ~/.claude/settings.json: se păstrează tot ce e acolo, se înlocuiesc doar intrările cemainou.
#    SessionStart: session-start.sh, sincron (rândul lui intră în contextul sesiunii); restul: heartbeat.sh, în fundal.
#    Plus permisiunile, o singură dată, la instalare: modul automat vede cemainou ca serviciu extern și i-ar bloca uneltele
#    („Data Exfiltration"); mcp__cemainou le permite, git remote -v doar citește adresa repo-ului.
SETTINGS="$HOME_DIR/.claude/settings.json"
BEAT='"$HOME/.config/cemainou/heartbeat.sh"'          # cu ghilimele și $HOME neînlocuit: merge și cu spații în cale
START='"$HOME/.config/cemainou/session-start.sh"'
if command -v node >/dev/null 2>&1; then
  node - "$SETTINGS" "$BEAT" "$START" "$MODE" <<'EOF'
const fs = require('fs');
const [file, beat, start, mode] = process.argv.slice(2);
let s = {};
try { s = JSON.parse(fs.readFileSync(file, 'utf8')); } catch {}
s.hooks = s.hooks || {};
for (const ev of ['SessionStart', 'UserPromptSubmit', 'PostToolUse', 'Stop', 'SessionEnd']) {
  s.hooks[ev] = (s.hooks[ev] || []).filter((g) => !(g.hooks || []).some((h) => /(whatsupai|cemainou)\/(heartbeat|session-start)\.sh/.test(String(h.command || ''))));
  if (mode === 'client') { if (!s.hooks[ev].length) delete s.hooks[ev]; continue; }   // clientul: fără hook-uri
  s.hooks[ev].push(ev === 'SessionStart'
    ? { hooks: [{ type: 'command', command: start, timeout: 5 }] }
    : { hooks: [{ type: 'command', command: beat, async: true, timeout: 10 }] });
}
s.permissions = s.permissions || {};
const allow = s.permissions.allow = (s.permissions.allow || []).filter((r) => r !== 'mcp__whatsupai');
for (const r of ['mcp__cemainou', 'Bash(git remote -v)']) if (!allow.includes(r)) allow.push(r);
fs.writeFileSync(file, JSON.stringify(s, null, 2) + '\n');
EOF
elif command -v python3 >/dev/null 2>&1; then
  python3 - "$SETTINGS" "$BEAT" "$START" "$MODE" <<'EOF'
import json, re, sys
file, beat, start, mode = sys.argv[1:5]
try:
    with open(file) as f:
        s = json.load(f)
except Exception:
    s = {}
s.setdefault('hooks', {})
for ev in ['SessionStart', 'UserPromptSubmit', 'PostToolUse', 'Stop', 'SessionEnd']:
    groups = [g for g in s['hooks'].get(ev, []) if not any(re.search(r'(whatsupai|cemainou)/(heartbeat|session-start)\.sh', str(h.get('command', ''))) for h in g.get('hooks', []))]
    if mode == 'client':
        if groups: s['hooks'][ev] = groups
        else: s['hooks'].pop(ev, None)
        continue
    groups.append({'hooks': [{'type': 'command', 'command': start, 'timeout': 5}]} if ev == 'SessionStart'
                  else {'hooks': [{'type': 'command', 'command': beat, 'async': True, 'timeout': 10}]})
    s['hooks'][ev] = groups
p = s.setdefault('permissions', {})
p['allow'] = [r for r in p.get('allow', []) if r != 'mcp__whatsupai']
p['allow'] += [r for r in ['mcp__cemainou', 'Bash(git remote -v)'] if r not in p['allow']]
with open(file, 'w') as f:
    json.dump(s, f, indent=2)
    f.write('\n')
EOF
else
  echo "Nu am găsit node sau python3, deci hook-urile NU sunt instalate. Adaugă manual în $SETTINGS, la hooks: pentru SessionStart {\"hooks\":[{\"type\":\"command\",\"command\":\"\\\"\$HOME/.config/cemainou/session-start.sh\\\"\",\"timeout\":5}]}, iar pentru UserPromptSubmit, PostToolUse, Stop și SessionEnd {\"hooks\":[{\"type\":\"command\",\"command\":\"\\\"\$HOME/.config/cemainou/heartbeat.sh\\\"\",\"async\":true,\"timeout\":10}]}" >&2
  echo "Claude Code e conectat la cemainou ($URL) și comanda /upd e instalată, dar fără hook-uri (vezi mai sus). Deschide o sesiune nouă."
  exit 3
fi

rm -rf "$HOME_DIR/.config/whatsupai"                          # configurarea de sub numele vechi, dacă era
if [ "$MODE" = client ]; then
  echo "Gata: Claude Code e conectat la cemainou ($URL), cu contul tău de client: agentul vede doar proiectele tale, sarcinile, documentele și fișierele arătate ție. Întreabă-l în cuvinte („ce sarcini am pe proiect?”, „răspunde la #12: …”, „adaugă o cerere: …”). Uneltele cemainou sunt permise în Claude Code (regula mcp__cemainou din $SETTINGS). Deschide o sesiune nouă."
  exit 0
fi
echo "Gata: Claude Code e conectat la cemainou ($URL), comanda /upd e instalată, iar hook-urile leagă fiecare sesiune de proiectul ei, îi măsoară timpul și îi aduc ce contează pe proiect. Uneltele cemainou sunt permise în Claude Code (regula mcp__cemainou din $SETTINGS), ca modul automat să nu le blocheze. Restul îl spui agentului în cuvinte („notează o idee: …”, „lucrez la #12”). Deschide o sesiune nouă."
