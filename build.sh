#!/usr/bin/env bash
#
# DebugWP Plugin Build Script
#
# Usage:
#   ./build.sh                    — Build plugin and place in ../build/
#   ./build.sh --help             — Show this help message
#
# Output: ../build/debugwp.zip

set -euo pipefail

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$SCRIPT_DIR"
PLUGIN_NAME="debugwp"
BUILD_OUTPUT_DIR="$(cd "${SCRIPT_DIR}/../build" && pwd)"

# Help message
if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    echo "DebugWP Plugin Build Script"
    echo ""
    echo "Usage:"
    echo "  $0                    — Build plugin and place in ../build/"
    echo "  $0 --help             — Show this help message"
    echo ""
    echo "Output: ${BUILD_OUTPUT_DIR}/${PLUGIN_NAME}.zip"
    exit 0
fi

# Verify build output directory exists
if [[ ! -d "$BUILD_OUTPUT_DIR" ]]; then
    echo "ERROR: Build output directory not found: $BUILD_OUTPUT_DIR"
    echo "Make sure build/ directory exists"
    exit 1
fi

echo "========================================="
echo " DebugWP Plugin Build"
echo "========================================="
echo ""
echo "Plugin Directory: $PLUGIN_DIR"
echo "Output Directory: $BUILD_OUTPUT_DIR"
echo ""

# Create zip file
ZIP_FILE="${BUILD_OUTPUT_DIR}/${PLUGIN_NAME}.zip"

# Remove old build if it exists
if [[ -f "$ZIP_FILE" ]]; then
    echo "Removing old build: $ZIP_FILE"
    rm "$ZIP_FILE"
fi

echo "Creating archive: $ZIP_FILE"

# Create the zip excluding common unnecessary files
cd "$(dirname "$PLUGIN_DIR")"
zip -r "$ZIP_FILE" "$PLUGIN_NAME" \
    --exclude \
    "$PLUGIN_NAME/.DS_Store" \
    "$PLUGIN_NAME/.git/*" \
    "$PLUGIN_NAME/.gitignore" \
    "$PLUGIN_NAME/build.sh" \
    "$PLUGIN_NAME/node_modules/*" \
    "$PLUGIN_NAME/.env*" \
    "$PLUGIN_NAME/*.md" \
    > /dev/null

echo ""
echo "✓ Build complete!"
echo "  Archive: $ZIP_FILE"

# Show file size
FILE_SIZE=$(du -h "$ZIP_FILE" | cut -f1)
echo "  Size: $FILE_SIZE"
echo ""
