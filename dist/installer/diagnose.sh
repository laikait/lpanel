#!/usr/bin/env bash
# Collects what is needed to understand a failed LPanel install or sign-in.
# Run as root: bash diagnose.sh > lpanel-diag.txt 2>&1
# It prints no passwords or keys.
s() { printf '\n===== %s\n' "$*"; }

s "system";        cat /etc/os-release | grep -E '^(PRETTY_NAME|VERSION_ID)='; uname -r
s "selinux";       getenforce; sestatus | grep -E 'policy|mode'; rpm -q selinux-policy-targeted
s "module";        semodule -l | grep -w lpanel
s "processes";     ps -eZ | grep -E 'lpa|lpanel-authd|pure-ftpd' | grep -v grep
s "authd";         systemctl status lpanel-authd.socket lpanel-authd.service --no-pager -l 2>&1 | tail -25
s "authd log";     journalctl -u lpanel-authd -n 40 --no-pager
s "lpa log";       journalctl -u lpa -n 40 --no-pager | grep -iE 'error|denied|refus|fail' | tail -20
s "pam";           authselect current 2>&1; cat /etc/pam.d/lpanel; grep -vE '^#|^$' /etc/pam.d/password-auth
s "chkpwd";        ls -lZ /usr/sbin/unix_chkpwd /etc/shadow
s "files";         ls -lZd /etc/pki /etc/pki/pure-ftpd /etc/pki/pure-ftpd/* /etc/pure-ftpd 2>&1
s "denials";       ausearch -m AVC,USER_AVC,SELINUX_ERR -ts today -i 2>/dev/null | grep -E 'lpanel|lpa|chkpwd|pure' | tail -40
s "auth audit";    ausearch -m USER_AUTH,USER_ACCT -ts today -i 2>/dev/null | tail -10
s "ftp settings";  lpanel ftp:settings --apply 2>&1 | tail -5
