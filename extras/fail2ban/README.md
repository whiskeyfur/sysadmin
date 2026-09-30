# fail2ban jails for web abusers

Two jails for servers running Apache, and the jail sys's access log bans go into
(right-click a client address in the Apache or Vhost report).

| Jail | Bans | When |
| --- | --- | --- |
| `web-abusers` | permanently, on ports 80/443 | banned from sys by hand, or 3 exploit probes answered with a 4xx within 10 minutes (`/.env`, `/.git/`, `/.aws/`, `wp-login.php`, phpMyAdmin, `phpunit/eval-stdin.php`, `/cgi-bin/` traversal, `${jndi:`, `../../`, …), or TLS/binary junk sent to plain HTTP, or proxy attempts |
| `web-abusers-4xx` | permanently, on ports 80/443 | 100 client errors (400, 401, 403, 404, 405, 444) within 5 minutes |

Only 4xx answers count, so a site that really serves one of the probed paths isn't affected. The
patterns were checked against real scans on this machine; everything they matched there was a
scanner. They read the `common`, `combined` and `vhost_combined` log formats.

## Install (Debian/Ubuntu)

```sh
sudo cp filter.d/web-abusers.conf filter.d/web-abusers-4xx.conf /etc/fail2ban/filter.d/
sudo cp jail.d/web-abusers.local /etc/fail2ban/jail.d/
sudo cp fail2ban.d/web-abusers.local /etc/fail2ban/fail2ban.d/
sudoedit /etc/fail2ban/jail.d/web-abusers.local   # add your own networks to both ignoreip lines
sudo fail2ban-client -t && sudo systemctl restart fail2ban
sudo fail2ban-client status web-abusers
```

- **ignoreip**: add the addresses you manage from (office, VPN, monitoring) before enabling: bans
  are permanent.
- **fail2ban.d/web-abusers.local** keeps ban records for ten years (Debian purges them after a day),
  so permanent bans come back after fail2ban restarts. It applies to all jails' records.
- Logs elsewhere than `/var/log/apache2/*access.log`: add them to both `logpath` settings, one per
  line.
- Before enabling on a busy server, see what it would have banned:
  `fail2ban-regex /var/log/apache2/access.log /etc/fail2ban/filter.d/web-abusers.conf`

## Letting sys see and change bans

sys reads the jails' banned lists with each Apache check and bans/unbans over SSH with
`sudo -n fail2ban-client`, using the SSH user's own sudo rules. On each server, for the SSH user sys
logs in as (here `ops`):

```sh
echo 'ops ALL=(root) NOPASSWD: /usr/bin/fail2ban-client' | sudo tee /etc/sudoers.d/sys-fail2ban
sudo chmod 440 /etc/sudoers.d/sys-fail2ban && sudo visudo -cf /etc/sudoers.d/sys-fail2ban
```

Unban by hand: `sudo fail2ban-client set web-abusers unbanip ADDRESS`.
