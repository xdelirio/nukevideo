# Tailscale on a worker: the private network a worker can reach the fleet's chunk store over when
# the store lives on another provider (see the nodes guide). Installed on every worker deploy;
# joined only when an auth key is stored under Settings.
#
# A node keeps its login in /var/lib/tailscale, across deploys and reboots, and tailscaled starts
# with the system. So the key is spent once, on the first join: a later deploy sees the node is
# already running and leaves it alone, and the key can be rotated or removed without touching it.

echo "=== Tailscale ==="
if ! have_command tailscale; then
    curl -fsSL https://tailscale.com/install.sh | $SUDO sh
    echo "Tailscale installed"
else
    echo "Tailscale already installed: $(tailscale version | head -n 1)"
fi
$SUDO systemctl enable --now tailscaled

if [ -z "${TAILSCALE_AUTH_KEY:-}" ]; then
    echo "No Tailscale auth key stored — not joining the tailnet"
elif $SUDO tailscale status --json 2>/dev/null | grep -q '"BackendState": *"Running"'; then
    echo "Tailscale already joined as $TAILSCALE_HOSTNAME"
else
    # `tailscale up` does not print the key, so it stays out of the deploy log.
    $SUDO tailscale up --authkey="$TAILSCALE_AUTH_KEY" --hostname="$TAILSCALE_HOSTNAME"
    echo "Tailscale joined as $TAILSCALE_HOSTNAME"
fi
