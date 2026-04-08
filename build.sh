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
BUILD_OUTPUT_DIR="${SCRIPT_DIR}/../build"

# Ensure output directory exists
mkdir -p "$BUILD_OUTPUT_DIR"
BUILD_OUTPUT_DIR="$(cd "$BUILD_OUTPUT_DIR" && pwd)"

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
FOLDER_DIR="${BUILD_OUTPUT_DIR}/${PLUGIN_NAME}"
if [[ -d "$FOLDER_DIR" ]]; then
    echo "Removing old build folder: $FOLDER_DIR"
    rm -rf "$FOLDER_DIR"
fi
if [[ -f "$ZIP_FILE" ]]; then
    echo "Removing old zip: $ZIP_FILE"
    rm "$ZIP_FILE"
fi

echo "Creating build..."

# Copy plugin files to build folder, excluding dev files
rsync -a --exclude='.DS_Store' --exclude='.git' --exclude='.gitignore' \
    --exclude='build.sh' --exclude='node_modules' --exclude='.env*' \
    --exclude='*.md' \
    "$PLUGIN_DIR/" "$FOLDER_DIR/"

# Create zip from the folder
cd "$BUILD_OUTPUT_DIR"
zip -rq "$ZIP_FILE" "$PLUGIN_NAME" > /dev/null

echo ""
echo "✓ Build complete!"
echo "  Folder:  $FOLDER_DIR"
echo "  Archive: $ZIP_FILE"

# Show file size
FILE_SIZE=$(du -h "$ZIP_FILE" | cut -f1)
echo "  Size: $FILE_SIZE"
echo ""
