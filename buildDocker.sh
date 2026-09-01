#!/bin/bash
#
# Builds the application image locally. The tag is deliberately local-only:
# nothing publishes an image for this repository, and devaslanphp/helper on
# Docker Hub belongs to the upstream project. See docs/docker.md.
#
# The image needs an APP_KEY at run time; it does not contain one:
#
#     docker run --rm helper:local php artisan key:generate --show

set -euo pipefail

docker build -t helper:local "$(dirname "$0")"
