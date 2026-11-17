# LPanel: written by the installer. Exim asks this worker about mail from
# outside (spamd_address in /etc/exim/exim.conf); only this machine reaches it.
bind_socket = "127.0.0.1:11333";
