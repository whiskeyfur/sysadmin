#!/bin/sh
# Add mod_unique_id's request ID to Apache's access and error logs (Debian/Ubuntu layout), so an
# error log line can be matched to the request that caused it. Part of sys (extras/apache).
#
#   sudo sh enable-unique-id.sh           turn it on
#   sudo sh enable-unique-id.sh --force   turn it on even though some fail2ban jails would stop matching
#   sudo sh enable-unique-id.sh --undo    take it out again
#
# What it does:
#   - enables mod_unique_id (it gives every request an ID; on its own it changes no log line);
#   - adds conf-available/unique-id-logs.conf, which redefines the combined, vhost_combined and common
#     LogFormats as apache2.conf has them, plus the ID at the end (%{UNIQUE_ID}e), and sets an
#     ErrorLogFormat identical to Apache's default plus " [id ...]" at the end for lines that belong to
#     a request (%L, which mod_unique_id sets to the same ID);
#   - sends the same ID back in every response as an X-Request-ID header (mod_headers, enabled if it
#     isn't), so a user can quote it and it shows in the browser's developer tools;
#   - checks the configuration, and undoes everything if Apache refuses it; then reloads Apache.
#
# Why at the end of the line: fail2ban's stock Apache filters read these lines. apache-auth and
# apache-noscript expect [pid] then [client] with nothing between them, so the end is the only place
# they still match (checked against real logs). But apache-badbots and apache-pass (access log) and
# apache-botsearch and apache-shellshock (error log) require the line to end where it normally does:
# with any of those jails enabled this script stops, unless --force.
#
# Vhosts that define their own LogFormat nicknames (e.g. Let's Encrypt's options-ssl-apache.conf
# redefines vhost_combined) or their own ErrorLogFormat keep theirs: the script lists them.
#
# Testing without root: APACHE_ETC points it at a copy of /etc/apache2, and the tools it runs
# (apache2ctl, a2enmod, a2enconf, a2dismod, a2disconf, systemctl, fail2ban-client) come from PATH.
set -eu

ETC=${APACHE_ETC:-/etc/apache2}
NAME=unique-id-logs
CONF="$ETC/conf-available/$NAME.conf"
# Present when this script enabled mod_unique_id / mod_headers (so --undo only disables what it enabled).
MARK="$ETC/conf-available/.$NAME.module"
HEADERS_MARK="$ETC/conf-available/.$NAME.headers"
FORCE=no
UNDO=no

for arg in "$@"; do
    case $arg in
        --force) FORCE=yes ;;
        --undo) UNDO=yes ;;
        *) echo "Unknown option: $arg (use --force or --undo)" >&2; exit 2 ;;
    esac
done

if [ "$(id -u)" -ne 0 ] && [ -z "${APACHE_ETC:-}" ]; then
    echo "Run it with sudo: sudo sh $0 $*" >&2
    exit 1
fi

undo() {
    a2disconf -q "$NAME" >/dev/null 2>&1 || true
    rm -f "$CONF"
    if [ -e "$MARK" ]; then
        a2dismod -q unique_id >/dev/null 2>&1 || true
        rm -f "$MARK"
    fi
    if [ -e "$HEADERS_MARK" ]; then
        # Other configuration may have come to use mod_headers since: keep it then.
        a2dismod -q headers >/dev/null 2>&1 || true
        if ! apache2ctl configtest >/dev/null 2>&1; then
            a2enmod -q headers >/dev/null 2>&1 || true
            echo "mod_headers stays enabled: other configuration uses it now."
        fi
        rm -f "$HEADERS_MARK"
    fi
}

if [ "$UNDO" = yes ]; then
    undo
    if apache2ctl configtest; then
        systemctl reload apache2
        echo "Request IDs are out of Apache's logs and responses again."
    else
        echo "Apache refuses its configuration now; see above." >&2
        exit 1
    fi
    exit 0
fi

# fail2ban jails whose stock filters need the line to end as usual.
if command -v fail2ban-client >/dev/null 2>&1 && fail2ban-client ping >/dev/null 2>&1; then
    affected=""
    for jail in $(fail2ban-client status | sed -n 's/.*Jail list:[[:space:]]*//p' | tr ',' ' '); do
        case $jail in
            apache-badbots|apache-pass|apache-botsearch|apache-shellshock) affected="$affected $jail" ;;
        esac
    done
    if [ -n "$affected" ] && [ "$FORCE" = no ]; then
        echo "These fail2ban jails would stop matching with an ID at the end of the lines:$affected" >&2
        echo "Nothing was changed. Run again with --force to do it anyway." >&2
        exit 1
    fi
fi

# An ErrorLogFormat set elsewhere (in what Apache reads) wins for its vhost, or clashes in the main server.
others=$(grep -RilE '^[[:space:]]*ErrorLogFormat' "$ETC/apache2.conf" "$ETC/conf-enabled" "$ETC/sites-enabled" "$ETC/mods-enabled" 2>/dev/null | grep -v "/$NAME.conf$" || true)
if [ -n "$others" ] && [ "$FORCE" = no ]; then
    echo "ErrorLogFormat is already set in:" >&2
    echo "$others" | sed 's/^/  /' >&2
    echo "Nothing was changed. Run again with --force to add this one anyway." >&2
    exit 1
fi

# The error log's pid field as Apache's default writes it: with the thread on threaded MPMs.
if apache2ctl -V 2>/dev/null | grep -Eq 'threaded:[[:space:]]+yes'; then
    PID='[pid %P:tid %T]'
else
    PID='[pid %P]'
fi

# apache2.conf's own definitions of the three nicknames, with the ID added before the closing quote.
formats=$(grep -E '^[[:space:]]*LogFormat[[:space:]]+".*"[[:space:]]+(vhost_combined|combined|common)[[:space:]]*$' "$ETC/apache2.conf" \
    | sed -E 's/^[[:space:]]*//; s/"[[:space:]]+(vhost_combined|combined|common)[[:space:]]*$/ %{UNIQUE_ID}e" \1/; s/^/    /')

if [ -z "$formats" ]; then
    echo "apache2.conf defines none of the combined, vhost_combined and common LogFormats; nothing to add the ID to." >&2
    exit 1
fi

mkdir -p "$ETC/conf-available"
cat > "$CONF" <<EOF
# Written by sys's extras/apache/enable-unique-id.sh: mod_unique_id's request ID at the end of each log
# line. Take it out with: sudo sh enable-unique-id.sh --undo
<IfModule unique_id_module>
$formats
    ErrorLogFormat "[%{u}t] [%-m:%l] $PID %7F: %E: [client\ %a] %M% ,\ referer:\ %{Referer}i% \ [id\ %L]"
    # The same ID in every response (errors and redirects too), to quote or find in the logs.
    <IfModule headers_module>
        Header always set X-Request-ID "%{UNIQUE_ID}e"
    </IfModule>
</IfModule>
EOF
chmod 644 "$CONF"

if [ ! -e "$ETC/mods-enabled/unique_id.load" ]; then
    a2enmod -q unique_id
    touch "$MARK"
fi

if [ ! -e "$ETC/mods-enabled/headers.load" ]; then
    a2enmod -q headers
    touch "$HEADERS_MARK"
fi

a2enconf -q "$NAME"

if ! apache2ctl configtest; then
    undo
    echo "Apache refused the change; it has been undone." >&2
    exit 1
fi

systemctl reload apache2
echo "Done: new requests get an ID at the end of their access log line, and error log lines that belong"
echo "to a request end with [id ...], the same ID; responses carry it as an X-Request-ID header."

# Vhosts that keep their own formats.
own=$(grep -rlE '^[[:space:]]*LogFormat[[:space:]]+.*[[:space:]](vhost_combined|combined|common)[[:space:]]*$' \
    "$ETC/sites-enabled/" /etc/letsencrypt/options-ssl-apache.conf 2>/dev/null || true)
if [ -n "$own" ]; then
    echo "These define their own LogFormat nicknames, so the vhosts using them log without the ID:"
    echo "$own" | sed 's/^/  /'
fi
