# A supported distro booted with systemd as PID 1, for package tests only.
ARG BASE

# Pebble, Let's Encrypt's test ACME server, issues the websites' certificates
# (ADR-0015): the real authority needs public DNS and port 80.
FROM golang:1.24 AS pebble
RUN GOTOOLCHAIN=local go install github.com/letsencrypt/pebble/v2/cmd/pebble@v2.10.1 \
 && cp -r "$(go env GOMODCACHE)/github.com/letsencrypt/pebble/v2@v2.10.1/test/certs" /pebble-certs

FROM ${BASE}
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
 && apt-get install -y --no-install-recommends systemd systemd-sysv dbus ca-certificates procps curl \
 && rm -f /etc/machine-id && (systemd-machine-id-setup >/dev/null 2>&1 || true)
COPY --from=pebble /go/bin/pebble /usr/local/bin/pebble
COPY --from=pebble /pebble-certs /opt/pebble/certs
# Pebble's own HTTPS is signed by its test root: trusted here only.
RUN cp /opt/pebble/certs/pebble.minica.pem /usr/local/share/ca-certificates/pebble-minica.crt && update-ca-certificates >/dev/null
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
