#!/bin/sh
# cemainou - aduce comenzile scurte și scripturile de hook la zi pe un calculator deja conectat (fără cheie; adresa din config).
# Rulat singur, în fundal, de hook-ul de pornire când versiunea de pe server diferă, sau de om, o dată:
#   curl -fsS <url>/agent/update.sh | sh
# Scrie doar fișierele cemainou: ~/.claude/commands/upd.md (și comenzile vechi brief, history, ctx, idea, task, work doar dacă sunt deja acolo),
# aceleași în ~/.codex/prompts dacă există, ~/.config/cemainou/{heartbeat,session-start}.sh și ~/.config/cemainou/version.
# O singură dată, pe un calculator conectat sub numele vechi (whatsupai, până la 0.1.254): mută ~/.config/whatsupai în ~/.config/cemainou,
# hook-urile spre noua cale, iar serverul MCP din Claude Code, Cursor și Codex sub numele cemainou, cu aceeași cheie. Dacă ceva nu merge,
# rămâne cum era: scripturile citesc și configurarea veche.
set -u
NEW="$HOME/.config/cemainou"
OLD="$HOME/.config/whatsupai"
if [ -r "$NEW/config" ]; then CONF="$NEW"; elif [ -r "$OLD/config" ]; then CONF="$OLD"; else
  echo "cemainou nu e conectat pe acest calculator: rulează întâi comanda de conectare din Profil." >&2; exit 1
fi
. "$CONF/config"
CEMAINOU_URL=${CEMAINOU_URL:-${WHATSUPAI_URL:-}}; CEMAINOU_TOKEN=${CEMAINOU_TOKEN:-${WHATSUPAI_TOKEN:-}}
URL="${1:-$CEMAINOU_URL}"
URL="${URL%/}"
[ -n "$URL" ] || exit 1
command -v curl >/dev/null 2>&1 || exit 1

tmp=$(mktemp -d 2>/dev/null) || exit 1
trap 'rm -rf "$tmp"' EXIT
for f in upd.md brief.md history.md ctx.md idea.md task.md work.md heartbeat.sh session-start.sh; do
  curl -fsS --max-time 20 "$URL/agent/$f" -o "$tmp/$f" && [ -s "$tmp/$f" ] || { echo "Nu am putut descărca $URL/agent/$f." >&2; exit 1; }
done
version=$(curl -fsS --max-time 10 "$URL/agent/version" 2>/dev/null | tr -cd '0-9a-f' | cut -c1-12)

# fiecare fișier: copiat lângă și mutat peste (scriptul care rulează acum își păstrează vechea copie până termină)
put() { cp "$1" "$2.new.$$" && chmod "$3" "$2.new.$$" && mv -f "$2.new.$$" "$2"; }

# numele vechi -> cemainou, o singură dată: config-ul, scripturile, hook-urile, serverul MCP
if [ "$CONF" = "$OLD" ]; then
  (umask 077; mkdir -p "$NEW") || exit 1
  sed -e 's/WHATSUPAI_/CEMAINOU_/g' -e 's/ajung la whatsupai/ajung la cemainou/' "$OLD/config" > "$NEW/config.new.$$" && chmod 600 "$NEW/config.new.$$" && mv -f "$NEW/config.new.$$" "$NEW/config" || exit 1
  for f in heartbeat.sh session-start.sh; do put "$tmp/$f" "$NEW/$f" 700 || exit 1; done
  # hook-urile (Claude Code și Cursor): doar calea scripturilor se schimbă, restul fișierelor rămâne neatins
  for s in "$HOME/.claude/settings.json" "$HOME/.cursor/hooks.json"; do
    [ -f "$s" ] && grep -q 'config\\\{0,1\}/whatsupai' "$s" && { sed -e 's#\.config/whatsupai/#.config/cemainou/#g' -e 's#\.config\\/whatsupai\\/#.config\\/cemainou\\/#g' "$s" > "$s.new.$$" && mv -f "$s.new.$$" "$s"; }   # și cu \/ (JSON scris cu barele „scăpate")
  done
  # serverul MCP sub numele nou, cu aceeași cheie: Claude Code (comanda claude), Cursor (mcp.json), Codex (config.toml)
  if command -v claude >/dev/null 2>&1 && claude mcp get whatsupai >/dev/null 2>&1 && [ -n "$CEMAINOU_TOKEN" ]; then
    if claude mcp add --scope user --transport http cemainou "$URL/mcp" --header "Authorization: Bearer $CEMAINOU_TOKEN" >/dev/null 2>&1; then
      claude mcp remove --scope user whatsupai >/dev/null 2>&1
      claude mcp remove --scope local whatsupai >/dev/null 2>&1
    fi
  fi
  m="$HOME/.cursor/mcp.json"
  [ -f "$m" ] && grep -q '"whatsupai"' "$m" && { sed -e 's/"whatsupai"[[:space:]]*:/"cemainou":/' -e 's/X-Whatsupai-Agent/X-Cemainou-Agent/' "$m" > "$m.new.$$" && mv -f "$m.new.$$" "$m"; }
  c="$HOME/.codex/config.toml"
  [ -f "$c" ] && grep -q '^\[mcp_servers\.whatsupai\]' "$c" && { sed 's/^\[mcp_servers\.whatsupai\]/[mcp_servers.cemainou]/' "$c" > "$c.new.$$" && mv -f "$c.new.$$" "$c"; }
  rm -rf "$OLD"                                                   # mutat: de aici încolo doar ~/.config/cemainou
  CONF="$NEW"
fi

mkdir -p "$HOME/.claude/commands"
for c in upd brief history ctx idea task work; do
  [ "$c" = upd ] || [ -f "$HOME/.claude/commands/$c.md" ] || continue    # una singură se pune (/upd); cele vechi doar se țin la zi
  put "$tmp/$c.md" "$HOME/.claude/commands/$c.md" 644 || exit 1
  [ -d "$HOME/.codex/prompts" ] && [ -f "$HOME/.codex/prompts/upd.md" ] && put "$tmp/$c.md" "$HOME/.codex/prompts/$c.md" 644
done
for f in heartbeat.sh session-start.sh; do
  put "$tmp/$f" "$CONF/$f" 700 || exit 1
done
[ -n "$version" ] && printf '%s\n' "$version" > "$CONF/version"
[ "${2:-}" = quiet ] || echo "cemainou: comenzile și hook-urile sunt la zi (versiunea ${version:-necunoscută}). Deschide o sesiune nouă."
exit 0
