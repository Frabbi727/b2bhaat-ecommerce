# Used by auto-deploy.sh — sourced, not run.
#
# send_alert "<subject>" "<body>"
#
# With ALERT_EMAIL set (in the cron line: ALERT_EMAIL=you@example.com /bin/bash …)
# the alert is mailed through the server's own sendmail. Without it — or if
# sendmail refuses — the text goes to stdout, which cron mails to the cPanel
# "Cron Email" address when one is set. Either way an alert is never silent.

send_alert() {
    local subject="$1" body="$2"
    local host sendmail="${SENDMAIL_BIN:-/usr/sbin/sendmail}"
    host="$(hostname -f 2>/dev/null || hostname)"

    if [[ -n "${ALERT_EMAIL:-}" && -x "$sendmail" ]]; then
        if printf 'To: %s\nSubject: %s\nContent-Type: text/plain; charset=UTF-8\n\n%s\n\n-- \nSent by %s on %s\n' \
                "$ALERT_EMAIL" "$subject" "$body" "$(basename "$0")" "$host" \
            | "$sendmail" -t 2>/dev/null; then
            return 0
        fi
    fi

    printf '%s\n\n%s\n' "$subject" "$body"
}
