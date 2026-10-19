# One-click Oracle deployment

After the one-time setup below, use **Actions → Deploy production → Run workflow → main**.
There is no separate Prepare release step. The workflow runs the complete reusable Checks
suite, rebuilds frontend assets from the same commit and lockfiles, uploads source and assets
over SSH, and activates the release on Oracle. It uses only Paykr's own action and standard
Git, SSH, Composer, npm, PHP and Python tools. Pushes and PRs never deploy automatically.

## One-time setup

This workflow targets the existing Ubuntu installation: `/srv/paykr`, `ubuntu` deployment
user, PHP 8.3 FPM, MySQL database `paykr_production`, Nginx, Redis, and the four enabled
`paykr@` services. The already-enabled WhatsApp sidecar is installed and restarted too.
Google Messages is not supported by this deployment script. Source code stays read-only
to www-data except Laravel's bootstrap cache. Production `.env`, storage, APP_KEY and
WhatsApp credentials remain in `/srv/paykr/shared`. Never put them in GitHub or a bundle.

1. Merge this workflow PR after CI succeeds.
2. On your Mac, create a **dedicated** deployment key (not your personal SSH key):

   ```bash
   ssh-keygen -t ed25519 -f ~/.ssh/paykr-actions -C paykr-github-actions -N ''
   ```

3. Copy only `~/.ssh/paykr-actions.pub` to the server. In your existing Oracle SSH session,
   append its single public-key line to `/home/ubuntu/.ssh/authorized_keys`, prefixed with
   `restrict ` (space after restrict). Keep existing login keys. Set `.ssh` mode 700 and
   `authorized_keys` mode 600, owned by ubuntu. Do not put the private key on the server.
   The restrict option disables forwarding and PTY allocation, but permits the commands
   and file transfer needed for deployment.
4. This automation uses the existing ubuntu account's passwordless sudo. Verify with
   `sudo -n true`. This key can deploy code and administer this server; treat it as a
   production credential and revoke its authorized_keys line if compromised. A restricted
   deploy account would require a separately designed privileged helper; none is implied here.
5. In the server console, run:

   ```bash
   sudo cat /etc/ssh/ssh_host_ed25519_key.pub
   ```

   This is a **public host key**, not a private credential. Construct a known_hosts line
   using the public IP and the first two fields of that output:
   `129.225.112.54 ssh-ed25519 AAAA...`. Do not use unverified ssh-keyscan output or disable
   host checking. Update this entry deliberately if you migrate/rebuild the server.
6. In GitHub **Settings → Environments**, create `production`. Restrict deployment branches
   to `main`. Required reviewers are optional; enabling them adds a review click per deploy.
7. Add these **environment variables**:

   | Name | Value |
   | --- | --- |
   | `DEPLOY_HOST` | `129.225.112.54` |
   | `DEPLOY_USER` | `ubuntu` |

   Add these **environment secrets** through GitHub's secure settings UI:

   | Name | Value |
   | --- | --- |
   | `DEPLOY_SSH_KEY` | Entire contents of your dedicated `~/.ssh/paykr-actions` private key |
   | `DEPLOY_KNOWN_HOSTS` | Verified known_hosts line from step 5 |

   Never paste the private key into chat, an issue, or a workflow file.
8. SSH port 22 must be reachable from the GitHub-hosted runner. A firewall restricted to
   your laptop's address will block it. Review access rules before changing them; keep
   key-only SSH authentication. No self-hosted runner is installed on production.

## What happens on deployment

- All checks must pass on the exact main commit before the production job can run.
- SSH host-key validation is mandatory. A GitHub concurrency group and a server flock prevent
  overlapping deployments. Archives and each public asset are checked before installation.
- A unique release directory is created for every run/attempt. Dependencies are installed
  on the server using its native architecture; node_modules are never copied from CI.
- Existing Laravel configuration is cached; no APP_KEY generation or admin seeding occurs.
- The app enters maintenance mode and FPM/background services stop before backup. There is
  brief downtime, including a possible Nginx 502 while FPM is stopped. This is not zero downtime.
- A MySQL dump and shared-runtime archive are written to a private directory under
  `/srv/paykr/backups/`. These local backups are permission-protected, **not encrypted or
  off-server**. Configure encrypted off-server backups separately; monitor free disk space.
- Pending migrations run with `--force`. Review migrations for production safety before
  merging; the workflow cannot prove schema backwards compatibility.
- `current` switches atomically, caches refresh, services restart, and HTTPS `/health` must
  report UP with the payment clock RUNNING and healthy. TLS verification stays enabled.
- If activation fails without migrations, the previous code release is restored and services
  restart. If migrations have started, the app stays in maintenance mode for manual diagnosis.
  No database is automatically restored, and migrations are never automatically reversed.
- Old releases, uploads and backups are retained. Prune them manually under an explicit
  retention policy after verification; this script never deletes payment data or old backups.

A green workflow confirms deployment and service health, not delivery of a real OTP or
bank payment. Verify WhatsApp shows Connected and run the agreed checkout acceptance check.
If SSH is interrupted or a runner is cancelled, inspect current, maintenance state and services
before retrying. The lock is released when the remote process exits. Never assume cancellation
rolled back a deployment.

## First-run verification

After configuring secrets, dispatch Deploy production from main. Check the log ends with
`DEPLOYED <commit>`. Confirm admin login, invitation links, OTP delivery and QR generation.
The workflow's operational failure tests use mocked PHP/systemd/SSH-adjacent commands and a
throwaway filesystem; they do not establish that your host credentials or sudo setup work.
The first live deployment remains the end-to-end infrastructure check.
