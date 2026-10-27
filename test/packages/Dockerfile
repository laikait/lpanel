# A supported distro booted with systemd as PID 1, for package tests only.
ARG BASE
FROM ${BASE}
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
 && apt-get install -y --no-install-recommends systemd systemd-sysv dbus ca-certificates procps curl \
 && rm -f /etc/machine-id && (systemd-machine-id-setup >/dev/null 2>&1 || true)
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
