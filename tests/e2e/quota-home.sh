# Sourced by the e2e scripts that run inside a container: puts /home on a
# loop-mounted filesystem with user quotas, as ADR-0008 requires of a server.
#
#   HOME_FS=ext4 (default)  ext4 with the quota feature, mounted usrquota
#   HOME_FS=xfs             XFS mounted uquota (EL9's default filesystem)
#   HOME_FS=auto            /home as it is if it already has user quotas (a
#                           VM set up for real), otherwise XFS as above
#
# A container must be --privileged to mount it.
quota_home() { # size
    local size="$1" fs="${HOME_FS:-ext4}"
    if [ "$fs" = auto ]; then
        if [[ ",$(findmnt -no OPTIONS --target /home)," =~ ,(usrquota|uquota), ]]; then
            echo "/home: as it is, $(findmnt -no FSTYPE,OPTIONS --target /home)"
            return 0
        fi
        fs=xfs
    fi
    truncate -s "$size" /homes.img
    case "$fs" in
        ext4)
            dnf -y -q install e2fsprogs >/dev/null 2>&1 || { echo "e2fsprogs install failed"; return 1; }
            mkfs.ext4 -q -O quota -E quotatype=usrquota /homes.img
            mount -o loop,usrquota /homes.img /home || { echo "cannot mount a quota filesystem (run with --privileged)"; return 1; }
            rm -rf /home/lost+found
            ;;
        xfs)
            dnf -y -q install xfsprogs >/dev/null 2>&1 || { echo "xfsprogs install failed"; return 1; }
            mkfs.xfs -q /homes.img
            mount -o loop,uquota /homes.img /home || { echo "cannot mount a quota filesystem (run with --privileged)"; return 1; }
            ;;
        *) echo "HOME_FS must be ext4 or xfs"; return 1 ;;
    esac
    echo "/home: $fs, $(findmnt -no OPTIONS /home)"
}
