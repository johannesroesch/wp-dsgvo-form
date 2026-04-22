#!/usr/bin/env bash
#
# build-release.sh — Full release build for wp-dsgvo-form.
#
# Usage:
#   bin/build-release.sh --version 1.2.0
#   bin/build-release.sh --version 1.2.0 --output ~/releases/wp-dsgvo-form.zip
#   bin/build-release.sh --version 1.2.0 --dry-run
#   bin/build-release.sh --version 1.2.0 --skip-install
#
# Steps:
#   1. Pre-flight checks (node, npm, composer, openssl)
#   2. Version bump in wp-dsgvo-form.php (header + constant)
#   3. npm install + npm run build (webpack production)
#   4. composer install --no-dev --optimize-autoloader
#   5. SRI hash for CAPTCHA script (public/js/captcha.min.js)
#   6. ZIP archive (production files only)
#   7. Restore dev dependencies (composer install)
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLUGIN_SLUG="wp-dsgvo-form"
MAIN_FILE="$PLUGIN_DIR/wp-dsgvo-form.php"

VERSION=""
OUTPUT=""
DRY_RUN=false
SKIP_INSTALL=false

# --- Colors (disabled if not a terminal) ---
if [[ -t 1 ]]; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[0;33m'
    BLUE='\033[0;34m'
    BOLD='\033[1m'
    RESET='\033[0m'
else
    RED='' GREEN='' YELLOW='' BLUE='' BOLD='' RESET=''
fi

info()  { echo -e "${BLUE}[INFO]${RESET}  $*"; }
ok()    { echo -e "${GREEN}[OK]${RESET}    $*"; }
warn()  { echo -e "${YELLOW}[WARN]${RESET}  $*"; }
error() { echo -e "${RED}[ERROR]${RESET} $*" >&2; }
step()  { echo -e "\n${BOLD}=== $* ===${RESET}"; }

# Portable in-place sed (macOS requires -i '', Linux requires -i without backup arg).
sedi() {
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "$@"
    else
        sed -i "$@"
    fi
}

usage() {
    cat <<EOF
Usage: $(basename "$0") --version <X.Y.Z> [OPTIONS]

Build a production release ZIP for $PLUGIN_SLUG.

Required:
  --version <X.Y.Z>   Semantic version for this release

Options:
  --output <path>      Output ZIP path (default: ../${PLUGIN_SLUG}.zip)
  --skip-install       Skip npm/composer install (use existing node_modules/vendor)
  --dry-run            Run all steps but don't create the ZIP (shows file list)
  -h, --help           Show this help message

Examples:
  $(basename "$0") --version 1.1.0
  $(basename "$0") --version 2.0.0-beta --output ~/Desktop/release.zip
  $(basename "$0") --version 1.1.0 --dry-run
EOF
    exit 0
}

# --- Parse arguments ---
while [[ $# -gt 0 ]]; do
    case "$1" in
        --version)    VERSION="$2"; shift 2 ;;
        --output)     OUTPUT="$2"; shift 2 ;;
        --skip-install) SKIP_INSTALL=true; shift ;;
        --dry-run)    DRY_RUN=true; shift ;;
        -h|--help)    usage ;;
        *) error "Unknown option: $1"; usage ;;
    esac
done

if [[ -z "$VERSION" ]]; then
    error "--version is required"
    echo ""
    usage
fi

# Validate semver (basic: X.Y.Z with optional pre-release suffix).
if ! echo "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?$'; then
    error "Invalid version format: $VERSION (expected X.Y.Z or X.Y.Z-suffix)"
    exit 1
fi

if [[ -z "$OUTPUT" ]]; then
    OUTPUT="$(dirname "$PLUGIN_DIR")/${PLUGIN_SLUG}.zip"
fi

echo -e "${BOLD}wp-dsgvo-form Release Build${RESET}"
echo "  Version: $VERSION"
echo "  Output:  $OUTPUT"
echo "  Dry run: $DRY_RUN"
echo ""

# ============================================================
# Step 1: Pre-flight checks
# ============================================================
step "Pre-flight Checks"

MISSING=()
command -v node    >/dev/null 2>&1 || MISSING+=("node")
command -v npm     >/dev/null 2>&1 || MISSING+=("npm")
command -v composer >/dev/null 2>&1 || MISSING+=("composer")
command -v zip     >/dev/null 2>&1 || MISSING+=("zip")
command -v openssl >/dev/null 2>&1 || MISSING+=("openssl")

if [[ ${#MISSING[@]} -gt 0 ]]; then
    error "Missing required tools: ${MISSING[*]}"
    exit 1
fi

ok "node $(node --version), npm $(npm --version)"
ok "composer $(composer --version 2>&1 | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')"
ok "zip available"
ok "openssl available"

# Verify we're in the plugin directory.
if [[ ! -f "$MAIN_FILE" ]]; then
    error "Cannot find $MAIN_FILE — are you in the plugin directory?"
    exit 1
fi

ok "Plugin file found: wp-dsgvo-form.php"

# ============================================================
# Step 2: Version bump
# ============================================================
step "Version Bump → $VERSION"

# 2a: Plugin header — " * Version:           X.Y.Z"
OLD_HEADER_VERSION=$(grep -m1 '^ \* Version:' "$MAIN_FILE" | sed 's/^.*Version:[[:space:]]*//')
if [[ "$OLD_HEADER_VERSION" == "$VERSION" ]]; then
    info "Header version already $VERSION — no change needed"
else
    sedi "s/^ \* Version:.*/ * Version:           $VERSION/" "$MAIN_FILE"
    ok "Header: $OLD_HEADER_VERSION → $VERSION"
fi

# 2b: PHP constant — define( 'WPDSGVO_VERSION', 'X.Y.Z' );
OLD_CONST_VERSION=$(grep -oP "define\(\s*'WPDSGVO_VERSION',\s*'\K[^']+" "$MAIN_FILE" 2>/dev/null || \
                    grep "WPDSGVO_VERSION" "$MAIN_FILE" | grep -oE "'[0-9]+\.[0-9]+\.[0-9]+[^']*'" | tr -d "'")
if [[ "$OLD_CONST_VERSION" == "$VERSION" ]]; then
    info "WPDSGVO_VERSION already $VERSION — no change needed"
else
    sedi "s/define( 'WPDSGVO_VERSION', '.*' )/define( 'WPDSGVO_VERSION', '$VERSION' )/" "$MAIN_FILE"
    ok "WPDSGVO_VERSION: $OLD_CONST_VERSION → $VERSION"
fi

# 2c: readme.txt (if it exists).
if [[ -f "$PLUGIN_DIR/readme.txt" ]]; then
    sedi "s/^Stable tag:.*/Stable tag: $VERSION/" "$PLUGIN_DIR/readme.txt"
    ok "readme.txt: Stable tag → $VERSION"
else
    info "readme.txt not found — skipping (not yet created)"
fi

# ============================================================
# Step 3: npm install + build
# ============================================================
step "Frontend Build (webpack)"

cd "$PLUGIN_DIR"

if $SKIP_INSTALL; then
    info "Skipping npm install (--skip-install)"
else
    info "Running npm install..."
    npm install --loglevel=warn 2>&1 | tail -3
    ok "npm dependencies installed"
fi

info "Running npm run build..."
BUILD_OUTPUT=$(npm run build 2>&1)
# Strip ANSI escape codes for reliable matching (webpack outputs color codes even in non-TTY).
BUILD_CLEAN=$(echo "$BUILD_OUTPUT" | sed 's/\x1b\[[0-9;]*m//g')
if echo "$BUILD_CLEAN" | grep -q "compiled successfully"; then
    COMPILE_TIME=$(echo "$BUILD_CLEAN" | grep -oE 'compiled successfully in [0-9]+ ms' || echo "compiled successfully")
    ok "webpack $COMPILE_TIME"
else
    error "webpack build failed:"
    echo "$BUILD_OUTPUT"
    exit 1
fi

# Verify build output exists.
if [[ ! -d "$PLUGIN_DIR/build" ]]; then
    error "build/ directory not found after webpack build"
    exit 1
fi

BUILD_FILES=$(find "$PLUGIN_DIR/build" -type f | wc -l | tr -d ' ')
ok "build/ contains $BUILD_FILES files"

# ============================================================
# Step 4: Composer (production dependencies only)
# ============================================================
step "Composer (Production)"

if $SKIP_INSTALL; then
    info "Skipping composer install (--skip-install)"
else
    info "Running composer install --no-dev --optimize-autoloader..."
    composer install --no-dev --optimize-autoloader --working-dir="$PLUGIN_DIR" 2>&1 | tail -3
    ok "Production autoloader generated (no dev packages)"
fi

# ============================================================
# Step 5: SRI Hash for CAPTCHA script
# ============================================================
step "SRI Hash (CAPTCHA)"

CAPTCHA_FILE="$PLUGIN_DIR/public/js/captcha.min.js"
if [[ -f "$CAPTCHA_FILE" ]]; then
    SRI_HASH="sha384-$(openssl dgst -sha384 -binary "$CAPTCHA_FILE" | openssl base64 -A)"
    sedi "s|define( 'WPDSGVO_CAPTCHA_SRI', '.*' )|define( 'WPDSGVO_CAPTCHA_SRI', '$SRI_HASH' )|" "$MAIN_FILE"
    ok "WPDSGVO_CAPTCHA_SRI: $SRI_HASH"
else
    warn "public/js/captcha.min.js not found — SRI hash left empty"
fi

# ============================================================
# Step 6: Create ZIP archive
# ============================================================
step "ZIP Archive"

# Files/directories to EXCLUDE from the ZIP.
EXCLUDES=(
    ".github/*" ".github"
    ".git/*" ".git"
    ".DS_Store" "**/.DS_Store"
    "node_modules/*" "node_modules"
    "src/*" "src"
    "tests/*" "tests"
    "docs/*" "docs"
    "bin/*" "bin"
    ".claude/*" ".claude"
    ".playwright-mcp/*" ".playwright-mcp"
    ".env" ".env.*"
    ".browserslistrc"
    ".eslintrc.js"
    ".eslintignore"
    ".gitleaks.toml"
    ".gitignore"
    "phpcs.xml" ".phpcs.xml"
    "phpstan.neon"
    "phpstan-bootstrap.php"
    "jest.config.js"
    "webpack.config.js"
    "composer.json"
    "composer.lock"
    "package.json"
    "package-lock.json"
    "codecov.yml"
    "CLAUDE.md"
    "README.md"
    "CONTRIBUTING.md"
    "ARCHITECTURE.md"
    "SECURITY_REQUIREMENTS.md"
    "QUALITY_STANDARDS.md"
    "PERFORMANCE_REQUIREMENTS.md"
    "UX_CONCEPT.md"
    "LEGAL_REQUIREMENTS.md"
    "DATA_PROTECTION.md"
    "CICD_PIPELINE.md"
    "TEAM.md"
    "*.zip"
)

EXCLUDE_ARGS=()
for pattern in "${EXCLUDES[@]}"; do
    EXCLUDE_ARGS+=(-x "$pattern")
done

if $DRY_RUN; then
    info "Dry run — listing files that would be included:"
    echo ""
    cd "$PLUGIN_DIR"
    find . -type f \
        ! -path './.github/*' \
        ! -path './.git/*' \
        ! -path './node_modules/*' \
        ! -path './src/*' \
        ! -path './tests/*' \
        ! -path './docs/*' \
        ! -path './bin/*' \
        ! -path './.claude/*' \
        ! -path './.playwright-mcp/*' \
        ! -name '.DS_Store' \
        ! -name '.env' \
        ! -name '.env.*' \
        ! -name '.browserslistrc' \
        ! -name '.eslintrc.js' \
        ! -name '.eslintignore' \
        ! -name '.gitleaks.toml' \
        ! -name '.gitignore' \
        ! -name 'phpcs.xml' \
        ! -name 'phpstan.neon' \
        ! -name 'phpstan-bootstrap.php' \
        ! -name 'jest.config.js' \
        ! -name 'webpack.config.js' \
        ! -name 'composer.json' \
        ! -name 'composer.lock' \
        ! -name 'package.json' \
        ! -name 'package-lock.json' \
        ! -name 'codecov.yml' \
        ! -name '*.md' \
        ! -name '*.zip' \
        | sort
    FILE_COUNT=$(find . -type f \
        ! -path './.github/*' \
        ! -path './.git/*' \
        ! -path './node_modules/*' \
        ! -path './src/*' \
        ! -path './tests/*' \
        ! -path './docs/*' \
        ! -path './bin/*' \
        ! -path './.claude/*' \
        ! -path './.playwright-mcp/*' \
        ! -name '.DS_Store' \
        ! -name '.env' \
        ! -name '.env.*' \
        ! -name '.browserslistrc' \
        ! -name '.eslintrc.js' \
        ! -name '.eslintignore' \
        ! -name '.gitleaks.toml' \
        ! -name '.gitignore' \
        ! -name 'phpcs.xml' \
        ! -name 'phpstan.neon' \
        ! -name 'phpstan-bootstrap.php' \
        ! -name 'jest.config.js' \
        ! -name 'webpack.config.js' \
        ! -name 'composer.json' \
        ! -name 'composer.lock' \
        ! -name 'package.json' \
        ! -name 'package-lock.json' \
        ! -name 'codecov.yml' \
        ! -name '*.md' \
        ! -name '*.zip' \
        | wc -l | tr -d ' ')
    echo ""
    info "$FILE_COUNT files would be included"
    info "Dry run complete — no ZIP created"
else
    rm -f "$OUTPUT"

    cd "$PLUGIN_DIR"
    zip -r "$OUTPUT" . "${EXCLUDE_ARGS[@]}" -q

    ZIP_SIZE=$(du -h "$OUTPUT" | cut -f1 | tr -d ' ')
    ZIP_COUNT=$(unzip -l "$OUTPUT" | tail -1 | awk '{print $2}')
    ok "ZIP created: $OUTPUT"
    ok "Size: $ZIP_SIZE, Files: $ZIP_COUNT"
fi

# ============================================================
# Step 7: Restore dev dependencies
# ============================================================
step "Restore Dev Environment"

if $SKIP_INSTALL; then
    info "Skipping restore (--skip-install was used)"
else
    info "Running composer install (with dev dependencies)..."
    composer install --working-dir="$PLUGIN_DIR" 2>&1 | tail -2
    ok "Dev dependencies restored"
fi

# ============================================================
# Summary
# ============================================================
echo ""
echo -e "${BOLD}${GREEN}Build complete!${RESET}"
echo "  Plugin:  $PLUGIN_SLUG"
echo "  Version: $VERSION"
if ! $DRY_RUN; then
    echo "  ZIP:     $OUTPUT"
    echo "  Size:    $ZIP_SIZE"
    echo "  Files:   $ZIP_COUNT"
fi
echo ""
