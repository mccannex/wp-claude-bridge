#!/bin/sh
# Install git hooks from bin/hooks/ into .git/hooks/.
# Run once after cloning: sh bin/install-hooks.sh

REPO_ROOT=$(git rev-parse --show-toplevel)
HOOKS_SRC="$REPO_ROOT/bin/hooks"
HOOKS_DST="$REPO_ROOT/.git/hooks"

for hook in "$HOOKS_SRC"/*; do
    name=$(basename "$hook")
    cp "$hook" "$HOOKS_DST/$name"
    chmod +x "$HOOKS_DST/$name"
    echo "Installed: .git/hooks/$name"
done

echo "Done."
