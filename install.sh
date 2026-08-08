#!/usr/bin/env bash
set -euo pipefail

REPO_URL="https://github.com/gdnnepal/bachat/archive/refs/heads/master.zip"
ARCHIVE_NAME="bachat-master.zip"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if [ ! -d "$SCRIPT_DIR/backend" ]; then
  echo "Downloading project files..."
  if command -v curl >/dev/null 2>&1; then
    curl -L "$REPO_URL" -o "$ARCHIVE_NAME"
  elif command -v wget >/dev/null 2>&1; then
    wget -O "$ARCHIVE_NAME" "$REPO_URL"
  else
    echo "curl or wget is required to download the project files." >&2
    exit 1
  fi

  if ! command -v unzip >/dev/null 2>&1; then
    echo "unzip is required to extract the project files." >&2
    exit 1
  fi

  unzip -o "$ARCHIVE_NAME" >/dev/null
  if [ -d "$SCRIPT_DIR/bachat-master" ]; then
    cp -R "$SCRIPT_DIR/bachat-master/." "$SCRIPT_DIR/"
    rm -rf "$SCRIPT_DIR/bachat-master" "$ARCHIVE_NAME"
  fi
fi

php backend/install.php
