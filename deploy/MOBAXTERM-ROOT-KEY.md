# Root key login to the VPS with MobaXterm

Set up passwordless SSH key login for `root` on your Debian VPS using MobaXterm
on Windows. Do this **before** running `setup.sh` — that script disables
password auth, so you need working key access first.

There are two halves: **(A)** make a key in MobaXterm, **(B)** install the
public half on the VPS with `enable-root-key.sh`.

---

## A. Generate a key pair in MobaXterm

1. MobaXterm menu: **Tools → MobaKeyGen (SSH key generator)**.
2. Key type: **Ed25519** (or RSA 4096 if you prefer). Click **Generate** and
   wiggle the mouse over the blank area until it finishes.
3. **Copy the public key.** At the top is a box labeled *"Public key for pasting
   into OpenSSH authorized_keys file"*. Select **all** of it and copy — it's one
   line starting with `ssh-ed25519 AAAA...`. This is what the VPS needs.
4. **Save the private key.** Click **Save private key** (a `.ppk`, e.g.
   `C:\Users\steph\codex-vps.ppk`). Keep it private; never share or upload it.
   Optionally add a passphrase when prompted.

> Tip: MobaXterm sessions use the `.ppk` directly. If any other tool needs an
> OpenSSH-format private key, use MobaKeyGen's **Conversions → Export OpenSSH
> key**.

---

## B. Install the public key on the VPS

You still have password access right now, so use it once to push the key.

1. In MobaXterm, start an **SSH session** to the VPS as `root` (Session → SSH,
   host = your VPS IP, username = `root`) and log in with the **password** your
   provider gave you.
2. Get `enable-root-key.sh` onto the box — easiest way: the file panel on the
   **left** of the MobaXterm terminal is live SFTP; drag `enable-root-key.sh`
   from Windows into it. (Or just paste the command in step 3 — no file needed.)
3. Run it, pasting the public key you copied in A‑3 (keep the quotes):

   ```bash
   bash enable-root-key.sh "ssh-ed25519 AAAA...the whole line... you@host"
   ```

   No file transfer at all? This three-liner does the same minimal job:

   ```bash
   mkdir -p /root/.ssh && chmod 700 /root/.ssh
   echo 'ssh-ed25519 AAAA...the whole line... you@host' >> /root/.ssh/authorized_keys
   chmod 600 /root/.ssh/authorized_keys
   ```

---

## C. Point the MobaXterm session at your private key

1. Right‑click your VPS session → **Edit session → Advanced SSH settings**.
2. Tick **Use private key** and select your `.ppk` from A‑4.
3. Save.

---

## D. Test, then lock it down

1. Open a **new** MobaXterm tab/session and connect as `root`. It should log in
   with **no password prompt** (you'll be asked for the key passphrase only if
   you set one). **Keep your old password session open** until this works.
2. Once key login is confirmed, disable password auth:

   ```bash
   bash enable-root-key.sh --disable-password
   ```

That's it. When you later run `deploy/setup.sh`, it keeps `root` key login
working (`PermitRootLogin prohibit-password`) and adds a non-root sudo user.

---

## Troubleshooting

- **Still asks for a password / "Permission denied (publickey)".** The session
  isn't using your private key (redo C), or the public key on the server is
  wrong/garbled. Re-run `enable-root-key.sh` with the exact one-line public key.
- **"doesn't look like an OpenSSH public key".** You pasted a private key or a
  `.ppk`. Go back to MobaKeyGen and copy from the *"Public key for pasting into
  OpenSSH authorized_keys file"* box (one line, starts with `ssh-ed25519`).
- **Locked out after `--disable-password`.** Use your provider's web/VNC console
  to log in and remove the last line of `/etc/ssh/sshd_config.d/10-root-key.conf`,
  then `systemctl reload ssh`.
