# LPanel: written by the installer. The web interface and its API answer only
# on this socket, which only Rspamd's own user and root may open; nothing
# listens on the network.
bind_socket = "/var/lib/rspamd/controller.sock mode=0600";
