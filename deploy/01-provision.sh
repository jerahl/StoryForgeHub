#!/usr/bin/env bash
# 01-provision.sh — base box hardening (MASTER-PLAN P0 step 1).
#
# SSH key-only auth, non-root sudo user, ufw (22/80/443), fail2ban,
# unattended security upgrades, hostname. Idempotent: safe to re-run.
#
# Run as root on a fresh Debian 12 box:  sudo bash deploy/01-provision.sh
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
need_root

step "Base packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ufw fail2ban unattended-upgrades curl ca-certificates \
  gnupg apt-transport-https sudo rsync >/dev/null
ok "ufw, fail2ban, unattended-upgrades, curl, rsync installed"

step "Non-root sudo user: $CODEX_ADMIN_USER"
if id "$CODEX_ADMIN_USER" >/dev/null 2>&1; then
  ok "user already exists"
else
  adduser --disabled-password --gecos "" "$CODEX_ADMIN_USER"
  ok "created"
fi
usermod -aG sudo "$CODEX_ADMIN_USER"

# Carry over root's authorized_keys so you don't lock yourself out before
# disabling password auth. Add more keys later with ssh-copy-id.
if [[ -f /root/.ssh/authorized_keys ]]; then
  install -d -m 700 -o "$CODEX_ADMIN_USER" -g "$CODEX_ADMIN_USER" \
    "/home/$CODEX_ADMIN_USER/.ssh"
  install -m 600 -o "$CODEX_ADMIN_USER" -g "$CODEX_ADMIN_USER" \
    /root/.ssh/authorized_keys "/home/$CODEX_ADMIN_USER/.ssh/authorized_keys"
  ok "copied root's authorized_keys to $CODEX_ADMIN_USER"
else
  warn "no /root/.ssh/authorized_keys found"
  warn "make sure $CODEX_ADMIN_USER has a key in ~/.ssh/authorized_keys BEFORE you log out,"
  warn "otherwise the SSH hardening below will lock you out."
fi

step "Hostname / DNS"
hostnamectl set-hostname "${CODEX_DOMAIN%%.*}" || warn "could not set hostname"
info "Point a DNS A record for $CODEX_DOMAIN at this box's public IP if not done."

step "SSH hardening (key-only, no root password login)"
SSHD_DROPIN=/etc/ssh/sshd_config.d/10-codex.conf
install -d -m 755 /etc/ssh/sshd_config.d
cat > "$SSHD_DROPIN" <<'EOF'
# Managed by codex 01-provision.sh
PasswordAuthentication no
ChallengeResponseAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
PubkeyAuthentication yes
EOF
if sshd -t 2>/dev/null; then
  systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
  ok "password auth disabled; root login key-only"
else
  warn "sshd config test failed; left running config unchanged"
fi

step "Firewall (ufw): allow 22/80/443"
ufw allow OpenSSH         >/dev/null 2>&1 || ufw allow 22/tcp >/dev/null
ufw allow 80/tcp          >/dev/null
ufw allow 443/tcp         >/dev/null
ufw --force enable        >/dev/null
ok "$(ufw status | head -1)"

step "fail2ban (sshd jail)"
cat > /etc/fail2ban/jail.d/codex-sshd.local <<'EOF'
[sshd]
enabled = true
maxretry = 5
bantime = 1h
EOF
systemctl enable --now fail2ban >/dev/null 2>&1 || true
ok "fail2ban enabled"

step "Unattended security upgrades"
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
systemctl enable --now unattended-upgrades >/dev/null 2>&1 || true
ok "enabled"

step "Done — base box hardened"
info "Verify you can still SSH in as $CODEX_ADMIN_USER (new terminal) before closing this session."
