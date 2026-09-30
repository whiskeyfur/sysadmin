# Apache extras

## Request IDs in the logs (`enable-unique-id.sh`)

Adds mod_unique_id's per-request ID to the end of every access log line and of every error log line
that belongs to a request, so an error can be matched to the request that caused it, and sends it back
in every response as an `X-Request-ID` header (redirects and errors too), so a user can quote it and it
shows in the browser's developer tools:

```
127.0.0.1 - - [29/Sep/2026:22:15:16 -0700] "GET /secret HTTP/1.1" 403 360 "http://x/" "curl/8.5.0" arya5LNEQ_6DuP3m_vCtTAAAAAE
[Tue Sep 29 22:15:16.496724 2026] [authz_core:error] [pid 3913282] [client 127.0.0.1:58556] AH01630: client denied by server configuration: /var/www/secret, referer: http://x/ [id arya5LNEQ_6DuP3m_vCtTAAAAAE]
```

```sh
sudo sh extras/apache/enable-unique-id.sh           # turn it on
sudo sh extras/apache/enable-unique-id.sh --undo    # take it out again
```

Debian/Ubuntu layout. It enables `unique_id` (if it isn't already), adds
`conf-available/unique-id-logs.conf` (apache2.conf's own `combined`, `vhost_combined` and `common`
formats plus `%{UNIQUE_ID}e`, and an `ErrorLogFormat` identical to Apache's default, for prefork or
threaded MPMs, plus ` [id %L]`, and `Header always set X-Request-ID "%{UNIQUE_ID}e"`), enables
`headers` if it isn't already, runs `apache2ctl configtest` (undoing everything if it fails) and
reloads Apache. Running it again rewrites the file, so an existing install picks up new parts (the
header). `--undo` disables only the modules it enabled, and keeps `headers` if other configuration has
come to use it.

Checked against this machine's Apache 2.4.58: with the ID removed, error log lines are byte for byte
what the default format writes (prefork and event); both logs and the response header carry the same
ID for a request.

**fail2ban.** The ID goes at the end of the line because that's the only place fail2ban's stock
`apache-auth` and `apache-noscript` filters still match (between `[pid]` and `[client]` they matched
nothing on real logs). `apache-badbots`, `apache-pass`, `apache-botsearch` and `apache-shellshock`
need the line to end as usual, so the script stops when any of those jails is enabled (`--force`
overrides). sys's `web-abusers` jails and sys's own log readers work either way.

**Not covered:** vhosts with their own `LogFormat` nicknames (e.g. Let's Encrypt's
`options-ssl-apache.conf` redefines `vhost_combined`) or their own `ErrorLogFormat` keep theirs; the
script lists the files. It also stops if an `ErrorLogFormat` is set anywhere Apache reads.
