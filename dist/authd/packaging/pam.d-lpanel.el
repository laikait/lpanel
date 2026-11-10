#%PAM-1.0
# /etc/pam.d/lpanel: how lpanel-authd checks a system password on EL
# (AlmaLinux, Rocky, CloudLinux). The distribution's own stacks, so password
# policy, faillock and expiry apply as they do at an SSH login.
auth       include      password-auth
account    include      password-auth
