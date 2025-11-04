#!/usr/bin/env bash
set -euo pipefail

IMAGE_NAME=webcooking/zpl-to-image:imagick
CONTEXT_DIR=$(pwd)

# Build image
docker build -t ${IMAGE_NAME} .

# Run container mounting current directory so outputs are available on host
docker run --rm -v ${CONTEXT_DIR}:/app -w /app ${IMAGE_NAME}
