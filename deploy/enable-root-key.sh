#!/usr/bin/env bash
# enable-root-key.sh — install an SSH public key for root and turn on key login.
#
# Run this ON THE VPS as root (you can still be on a password session for now).
# It is safe and idempotent; it does NOT disable password auth unless you pass
# --disable-password, so you can't lock yourself out by accident.
#
# USAGE (any one of):
#   sudo bash enable-root-key.sh "ssh-ed25519 AAAA... you@host"   # paste the key
#   sudo bash enable-root-key.sh /root/mykey.pub                   # from a file
#   PUBKEY="ssh-ed25519 AAAA..." sudo -E bash enable-root-key.sh   # from env
#
# Optional, ONLY after you've confirmed key login works in a NEW window:
#   sudo bash enable-root-key.sh --disable-password "ssh-ed25519 AAAA..."
set -euo pipefail

DISABLE_PW=0
KEY_ARG=""
for a in "$@"; do
  case "$a" in
    --disable-password) DISABLE_PW=1 ;;
    *) KEY_ARG="$a" ;;
  esac
done

[[ "$(id -u)" -eq 0 ]] || { echo "ERROR: run as root (use sudo)." >&2; exit 1; }

# --- resolve the public key (arg = file path OR literal key, or $PUBKEY) -----
if [[ -n "$KEY_ARG" && -f "$KEY_ARG" ]]; then
  PUBKEY="$(cat "$KEY_ARG")"
elif [[ -n "$KEY_ARG" ]]; then
  PUBKEY="$KEY_ARG"
elif [[ -n "${PUBKEY:-}" ]]; then
  PUBKEY="$PUBKEY"
else
  echo "Paste your PUBLIC key line (one line, starts with ssh-ed25519 / ssh-rsa), then Enter:"
  read -r PUBKEY
fi

# --- validate it looks like an OpenSSH public key ---------------------------
if ! grep -qE '^(ssh-ed25519|ssh-rsa|ecdsa-sha2-[a-z0-9-]+) [A-Za-z0-9+/=]+' <<<"$PUBKEY"; then
  echo "ERROR: that doesn't look like an OpenSSH public key." >&2
  echo "       It must be ONE line beginning with ssh-ed25519 or ssh-rsa." >&2
  echo "       (If you saved a PuTTY/.ppk private key, export the OpenSSH" >&2
  echo "        public key from MobaKeyGen instead — see the instructions.)" >&2
  exit 1
fi

# --- install into /root/.ssh/authorized_keys -------------------------------
install -d -m 700 /root/.ssh
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
if grep -qxF "$PUBKEY" /root/.ssh/authorized_keys; then
  echo "OK  key already present in /root/.ssh/authorized_keys"
else
  printf '%s\n' "$PUBKEY" >> /root/.ssh/authorized_keys
  echo "OK  key added to /root/.ssh/authorized_keys"
fi

# --- make sure sshd accepts pubkey + key-based root login -------------------
DROPIN=/etc/ssh/sshd_config.d/10-root-key.conf
install -d -m 755 /etc/ssh/sshd_config.d
cat > "$DROPIN" <<'EOF'
# Managed by enable-root-key.sh
PubkeyAuthentication yes
PermitRootLogin prohibit-password
EOF

if [[ "$DISABLE_PW" -eq 1 ]]; then
  echo "PasswordAuthentication no" >> "$DROPIN"
  echo "KbdInteractiveAuthentication no" >> "$DROPIN"
  echo "WARN password authentication will be DISABLED on reload."
fi

if sshd -t 2>/dev/null; then
  systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || service ssh reload
  echo "OK  sshd reloaded (PermitRootLogin prohibit-password, PubkeyAuthentication yes)"
else
  echo "ERROR: sshd config test failed; NOT reloading. Fix $DROPIN and retry." >&2
  exit 1
fi

cat <<EOF

Done. Now TEST in a NEW MobaXterm window before closing this one:
    ssh root@$(hostname -I 2>/dev/null | awk '{print $1}')
It should log in with NO password prompt.

Only after that works should you disable password login:
    sudo bash enable-root-key.sh --disable-password
(Note: the main setup.sh later sets the same prohibit-password policy and
creates a non-root sudo user, so root key login keeps working afterward.)
EOF
