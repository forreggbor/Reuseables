#!/bin/bash
#
# PatchCreator.sh - Patch Package Builder for PatchModule
#
# Creates .tgz patch packages compatible with PatchModule by detecting
# changed files via git diff, auto-extracting release notes from
# CHANGELOG.md, and generating a verified archive with SHA-256 hash.
#
# Usage: PatchCreator.sh [options]
#
# Author: Gábor
# Version: v1.00.00
# License: Proprietary
#

set -euo pipefail

# ==============================================================================
# Constants
# ==============================================================================

VERSION="v1.00.00"
SCRIPT_NAME="$(basename "$0")"
START_TIME=$(date +%s)

# Default exclude patterns (applied to git diff results)
DEFAULT_EXCLUDES=(
    '.git/'
    '.gitignore'
    '.gitattributes'
    'storage/'
    'vendor/'
    'node_modules/'
    '.env'
    '.env.*'
    '*.log'
    'CLAUDE.md'
    '.claude/'
    'database/schema/'
    'database/migrations/'
    'composer.lock'
    'package-lock.json'
    'README.md'
    'CHANGELOG.md'
    'doc/'
    'tests/'
    'phpunit.xml'
)

# Default version detection pattern (FlowerShop convention)
DEFAULT_VERSION_PATTERN="define\('APP_VERSION',\s*'([^']+)'\)"
DEFAULT_VERSION_FILE="app/helpers/functions.php"

# Exit codes
EXIT_SUCCESS=0
EXIT_ERROR=1
EXIT_NO_FILES=2
EXIT_GIT_ERROR=3
EXIT_CANCELLED=4

# ==============================================================================
# Color Output
# ==============================================================================

if [[ -t 1 ]]; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    BLUE='\033[0;34m'
    CYAN='\033[0;36m'
    BOLD='\033[1m'
    DIM='\033[2m'
    NC='\033[0m'
else
    RED=''
    GREEN=''
    YELLOW=''
    BLUE=''
    CYAN=''
    BOLD=''
    DIM=''
    NC=''
fi

# ==============================================================================
# Helper Functions
# ==============================================================================

##
# Print an error message to stderr and exit.
#
# @param string $1 Error message
# @param int    $2 Exit code (default: EXIT_ERROR)
##
error() {
    echo -e "${RED}[ERROR]${NC} $1" >&2
    exit "${2:-$EXIT_ERROR}"
}

##
# Print a warning message to stderr.
#
# @param string $1 Warning message
##
warn() {
    echo -e "${YELLOW}[WARN]${NC} $1" >&2
}

##
# Print an informational message.
#
# @param string $1 Info message
##
info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

##
# Print a success message.
#
# @param string $1 Success message
##
success() {
    echo -e "${GREEN}[OK]${NC} $1"
}

##
# Print a section header.
#
# @param string $1 Header text
##
header() {
    echo ""
    echo -e "${BOLD}${CYAN}── $1 ──${NC}"
}

##
# Print usage information and exit.
##
usage() {
    cat <<EOF
${BOLD}PatchCreator.sh${NC} ${DIM}${VERSION}${NC}
Patch Package Builder for PatchModule

${BOLD}USAGE:${NC}
    ${SCRIPT_NAME} [options]

${BOLD}OPTIONS:${NC}
    -d <path>       Project root directory (default: current directory)
    -v <version>    Target patch version (default: auto-detect from project)
    -b <git-ref>    Base git reference to diff against (default: latest tag)
    -m <file>       SQL migration file to include in patch
    -o <dir>        Output directory (default: <project>/storage/patch)
    -r <file>       Release notes file (overrides CHANGELOG.md extraction)
    -f <file>       File list override (one path per line, relative to project)
    -e <pattern>    Exclude glob pattern (repeatable)
    -p <pattern>    Version detection regex pattern
    --no-changelog  Skip automatic CHANGELOG.md extraction
    --dry-run       Show what would be packaged without creating archive
    -y              Auto-confirm (skip prompts)
    -h              Show this help message
    --version       Show script version

${BOLD}EXAMPLES:${NC}
    # Create patch from latest tag, auto-detect version
    ${SCRIPT_NAME}

    # Create patch against specific commit
    ${SCRIPT_NAME} -b abc1234

    # Create patch with migration and explicit version
    ${SCRIPT_NAME} -v 2.33.0 -m database/migrations/2026_02_16_new_feature.sql

    # Dry run to preview what will be packaged
    ${SCRIPT_NAME} --dry-run

    # Use explicit file list instead of git diff
    ${SCRIPT_NAME} -v 2.33.0 -f patch_files.txt

    # Exclude additional patterns
    ${SCRIPT_NAME} -e "public/uploads/*" -e "*.tmp"

${BOLD}EXIT CODES:${NC}
    0   Success
    1   General error (invalid arguments, missing files)
    2   No changed files to package
    3   Git error (not a repository, invalid reference)
    4   User cancelled

${BOLD}OUTPUT:${NC}
    Creates patch-<version>.tgz and patch-<version>.tgz.sha256 in the
    output directory. The archive contains:
      manifest.json      Package metadata and file list
      files/             Changed files preserving directory structure
      migration.sql      SQL migration (if provided via -m)
      release_notes.md   Release notes (auto-extracted or provided via -r)

EOF
    exit $EXIT_SUCCESS
}

##
# Validate that a path is inside a git repository.
#
# @param string $1 Directory path
# @return 0 if git repo, non-zero otherwise
##
is_git_repo() {
    git -C "$1" rev-parse --is-inside-work-tree &>/dev/null
}

##
# Check if a git reference exists in the repository.
#
# @param string $1 Project directory
# @param string $2 Git reference (tag, commit, branch)
# @return 0 if exists, non-zero otherwise
##
git_ref_exists() {
    git -C "$1" rev-parse --verify "$2" &>/dev/null
}

##
# Get the latest git tag in the repository.
#
# @param string $1 Project directory
# @return Latest tag name or empty string
##
get_latest_tag() {
    git -C "$1" describe --tags --abbrev=0 2>/dev/null || echo ""
}

##
# Auto-detect the project version from source files.
#
# @param string $1 Project directory
# @param string $2 Version regex pattern
# @param string $3 Version file path (relative to project)
# @return Version string or empty
##
detect_version() {
    local project_dir="$1"
    local pattern="$2"
    local version_file="$3"
    local full_path="${project_dir}/${version_file}"

    if [[ ! -f "$full_path" ]]; then
        return 0
    fi

    # Use grep -P with \K to extract only the version string after the pattern prefix
    local version
    version=$(grep -oP "define\('APP_VERSION',\s*'\K[^']+" "$full_path" 2>/dev/null | head -1 || echo "")

    # If custom pattern provided (different from default), try it as a fallback
    if [[ -z "$version" && "$pattern" != "$DEFAULT_VERSION_PATTERN" ]]; then
        version=$(grep -oP "$pattern" "$full_path" 2>/dev/null | head -1 | grep -oP '[0-9]+\.[0-9]+\.[0-9]+' 2>/dev/null | head -1 || echo "")
    fi

    echo "$version"
}

##
# Validate that a string matches semantic versioning format.
#
# @param string $1 Version string
# @return 0 if valid semver, non-zero otherwise
##
is_valid_semver() {
    [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9.]+)?$ ]]
}

##
# Check if a file path matches any of the given exclude patterns.
#
# Supports:
#   - Directory prefixes ending with / (e.g., "storage/")
#   - Glob patterns with * (e.g., "*.log", ".env.*")
#   - Exact filename matches (e.g., "CLAUDE.md")
#
# @param string $1 File path to check (relative to project root)
# @param array  $@ Patterns to match against (starting from $2)
# @return 0 if matches any pattern, non-zero otherwise
##
matches_exclude() {
    local file="$1"
    shift
    local patterns=("$@")

    # Also extract just the basename for non-path patterns
    local basename="${file##*/}"

    for pattern in "${patterns[@]}"; do
        # Directory prefix patterns (ending with /)
        if [[ "$pattern" == */ ]]; then
            if [[ "$file" == ${pattern}* || "$file" == */${pattern}* ]]; then
                return 0
            fi
        # Patterns containing * — apply as glob against full path AND basename
        elif [[ "$pattern" == *\** ]]; then
            # shellcheck disable=SC2254
            if [[ "$file" == $pattern || "$basename" == $pattern ]]; then
                return 0
            fi
        # Exact match against full path or basename
        else
            if [[ "$file" == "$pattern" || "$basename" == "$pattern" ]]; then
                return 0
            fi
        fi
    done

    return 1
}

##
# Extract release notes for a specific version from CHANGELOG.md.
#
# @param string $1 Path to CHANGELOG.md
# @param string $2 Target version
# @return Extracted release notes text
##
extract_changelog() {
    local changelog_file="$1"
    local target_version="$2"

    if [[ ! -f "$changelog_file" ]]; then
        return 0
    fi

    local in_section=false
    local content=""

    while IFS= read -r line; do
        # Check for target version header: ## [X.Y.Z] or ## [X.Y.Z] - date
        if [[ "$line" =~ ^##[[:space:]]+\[${target_version}\] ]]; then
            in_section=true
            content="${line}"$'\n'
            continue
        fi

        # If we're in the section and hit the next version header, stop
        if $in_section && [[ "$line" =~ ^##[[:space:]]+\[ ]]; then
            break
        fi

        # Accumulate lines while in section
        if $in_section; then
            content+="${line}"$'\n'
        fi
    done < "$changelog_file"

    # Trim trailing whitespace
    echo "$content" | sed -e 's/[[:space:]]*$//'
}

##
# Format file size in human-readable format.
#
# @param int $1 Size in bytes
# @return Formatted size string
##
format_size() {
    local bytes=$1

    if (( bytes >= 1048576 )); then
        echo "$(awk "BEGIN {printf \"%.2f MB\", $bytes/1048576}")"
    elif (( bytes >= 1024 )); then
        echo "$(awk "BEGIN {printf \"%.1f KB\", $bytes/1024}")"
    else
        echo "${bytes} B"
    fi
}

##
# Print elapsed time since script start.
##
print_elapsed() {
    local end_time
    end_time=$(date +%s)
    local elapsed=$(( end_time - START_TIME ))

    if (( elapsed >= 60 )); then
        echo -e "${DIM}Completed in ${elapsed}s ($(( elapsed / 60 ))m $(( elapsed % 60 ))s)${NC}"
    else
        echo -e "${DIM}Completed in ${elapsed}s${NC}"
    fi
}

# ==============================================================================
# Argument Parsing
# ==============================================================================

PROJECT_DIR="$(pwd)"
TARGET_VERSION=""
BASE_REF=""
MIGRATION_FILE=""
OUTPUT_DIR=""
RELEASE_NOTES_FILE=""
FILE_LIST=""
USER_EXCLUDES=()
VERSION_PATTERN="$DEFAULT_VERSION_PATTERN"
NO_CHANGELOG=false
DRY_RUN=false
AUTO_CONFIRM=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        -d)
            [[ -z "${2:-}" ]] && error "Option -d requires a directory path argument."
            PROJECT_DIR="$(realpath "$2")"
            shift 2
            ;;
        -v)
            [[ -z "${2:-}" ]] && error "Option -v requires a version argument."
            TARGET_VERSION="$2"
            shift 2
            ;;
        -b)
            [[ -z "${2:-}" ]] && error "Option -b requires a git reference argument."
            BASE_REF="$2"
            shift 2
            ;;
        -m)
            [[ -z "${2:-}" ]] && error "Option -m requires a migration file path argument."
            MIGRATION_FILE="$2"
            shift 2
            ;;
        -o)
            [[ -z "${2:-}" ]] && error "Option -o requires an output directory argument."
            OUTPUT_DIR="$2"
            shift 2
            ;;
        -r)
            [[ -z "${2:-}" ]] && error "Option -r requires a release notes file path argument."
            RELEASE_NOTES_FILE="$2"
            shift 2
            ;;
        -f)
            [[ -z "${2:-}" ]] && error "Option -f requires a file list path argument."
            FILE_LIST="$2"
            shift 2
            ;;
        -e)
            [[ -z "${2:-}" ]] && error "Option -e requires an exclude pattern argument."
            USER_EXCLUDES+=("$2")
            shift 2
            ;;
        -p)
            [[ -z "${2:-}" ]] && error "Option -p requires a version pattern argument."
            VERSION_PATTERN="$2"
            shift 2
            ;;
        --no-changelog)
            NO_CHANGELOG=true
            shift
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        -y)
            AUTO_CONFIRM=true
            shift
            ;;
        -h|--help)
            usage
            ;;
        --version)
            echo "${SCRIPT_NAME} ${VERSION}"
            exit $EXIT_SUCCESS
            ;;
        *)
            error "Unknown option: $1. Use -h for help."
            ;;
    esac
done

# ==============================================================================
# Validation
# ==============================================================================

header "Validating environment"

# Validate project directory
if [[ ! -d "$PROJECT_DIR" ]]; then
    error "Project directory does not exist: ${PROJECT_DIR}"
fi

if ! is_git_repo "$PROJECT_DIR"; then
    error "Not a git repository: ${PROJECT_DIR}" $EXIT_GIT_ERROR
fi

success "Project directory: ${PROJECT_DIR}"

# Validate migration file (resolve relative to project dir)
if [[ -n "$MIGRATION_FILE" ]]; then
    if [[ "$MIGRATION_FILE" != /* ]]; then
        MIGRATION_FILE="${PROJECT_DIR}/${MIGRATION_FILE}"
    fi
    if [[ ! -f "$MIGRATION_FILE" ]]; then
        error "Migration file not found: ${MIGRATION_FILE}"
    fi
    success "Migration file: ${MIGRATION_FILE}"
fi

# Validate file list
if [[ -n "$FILE_LIST" ]]; then
    if [[ "$FILE_LIST" != /* ]]; then
        FILE_LIST="${PROJECT_DIR}/${FILE_LIST}"
    fi
    if [[ ! -f "$FILE_LIST" ]]; then
        error "File list not found: ${FILE_LIST}"
    fi
    success "File list: ${FILE_LIST}"
fi

# Validate release notes file
if [[ -n "$RELEASE_NOTES_FILE" ]]; then
    if [[ "$RELEASE_NOTES_FILE" != /* ]]; then
        RELEASE_NOTES_FILE="${PROJECT_DIR}/${RELEASE_NOTES_FILE}"
    fi
    if [[ ! -f "$RELEASE_NOTES_FILE" ]]; then
        error "Release notes file not found: ${RELEASE_NOTES_FILE}"
    fi
    success "Release notes: ${RELEASE_NOTES_FILE}"
fi

# ==============================================================================
# Resolve Version
# ==============================================================================

header "Resolving version"

if [[ -z "$TARGET_VERSION" ]]; then
    TARGET_VERSION=$(detect_version "$PROJECT_DIR" "$VERSION_PATTERN" "$DEFAULT_VERSION_FILE")

    if [[ -z "$TARGET_VERSION" ]]; then
        error "Could not auto-detect version. Use -v to specify it explicitly."
    fi

    info "Auto-detected version: ${BOLD}${TARGET_VERSION}${NC}"
else
    info "Using specified version: ${BOLD}${TARGET_VERSION}${NC}"
fi

if ! is_valid_semver "$TARGET_VERSION"; then
    error "Invalid version format '${TARGET_VERSION}'. Expected semantic versioning (X.Y.Z)."
fi

success "Version: ${TARGET_VERSION}"

# ==============================================================================
# Resolve Base Reference
# ==============================================================================

header "Resolving base reference"

if [[ -z "$BASE_REF" ]]; then
    BASE_REF=$(get_latest_tag "$PROJECT_DIR")

    if [[ -z "$BASE_REF" ]]; then
        error "No git tags found. Use -b to specify a base reference explicitly." $EXIT_GIT_ERROR
    fi

    info "Using latest tag: ${BOLD}${BASE_REF}${NC}"
else
    info "Using specified reference: ${BOLD}${BASE_REF}${NC}"
fi

if ! git_ref_exists "$PROJECT_DIR" "$BASE_REF"; then
    error "Git reference does not exist: ${BASE_REF}" $EXIT_GIT_ERROR
fi

# Show commit range info
COMMIT_COUNT=$(git -C "$PROJECT_DIR" rev-list --count "${BASE_REF}..HEAD" 2>/dev/null || echo "0")
success "Base: ${BASE_REF} (${COMMIT_COUNT} commits since)"

# ==============================================================================
# Collect Changed Files
# ==============================================================================

header "Collecting changed files"

# Combine default and user exclude patterns
ALL_EXCLUDES=("${DEFAULT_EXCLUDES[@]}" "${USER_EXCLUDES[@]}")

declare -a PATCH_FILES=()

if [[ -n "$FILE_LIST" ]]; then
    # Read from file list (skip empty lines and comments)
    info "Reading file list from: ${FILE_LIST}"

    while IFS= read -r line; do
        # Skip empty lines and comments
        line=$(echo "$line" | sed 's/#.*//' | xargs)
        [[ -z "$line" ]] && continue

        # Validate file exists
        if [[ ! -f "${PROJECT_DIR}/${line}" ]]; then
            warn "File not found, skipping: ${line}"
            continue
        fi

        PATCH_FILES+=("$line")
    done < "$FILE_LIST"
else
    # Use git diff to detect changes
    info "Detecting changes: ${BASE_REF}..HEAD"

    # Get changed files (Added, Copied, Modified, Renamed)
    mapfile -t RAW_FILES < <(git -C "$PROJECT_DIR" diff --name-only --diff-filter=ACMR "${BASE_REF}..HEAD" 2>/dev/null)

    if [[ ${#RAW_FILES[@]} -eq 0 ]]; then
        # Also check uncommitted changes
        mapfile -t RAW_FILES < <(git -C "$PROJECT_DIR" diff --name-only --diff-filter=ACMR HEAD 2>/dev/null)

        if [[ ${#RAW_FILES[@]} -gt 0 ]]; then
            warn "No committed changes since ${BASE_REF}, but found uncommitted changes."
            warn "Uncommitted changes are not included. Commit first or use -f for a file list."
        fi
    fi

    # Filter excludes
    for file in "${RAW_FILES[@]}"; do
        if matches_exclude "$file" "${ALL_EXCLUDES[@]}"; then
            continue
        fi

        # Verify file still exists (might have been moved/deleted after diff)
        if [[ ! -f "${PROJECT_DIR}/${file}" ]]; then
            warn "File no longer exists, skipping: ${file}"
            continue
        fi

        PATCH_FILES+=("$file")
    done

    # Detect deleted files (warning only)
    mapfile -t DELETED_FILES < <(git -C "$PROJECT_DIR" diff --name-only --diff-filter=D "${BASE_REF}..HEAD" 2>/dev/null)

    if [[ ${#DELETED_FILES[@]} -gt 0 ]]; then
        echo ""
        warn "Deleted files detected (${#DELETED_FILES[@]}). These cannot be included in a patch:"
        for df in "${DELETED_FILES[@]}"; do
            echo -e "  ${DIM}${RED}✗${NC} ${DIM}${df}${NC}"
        done
        echo -e "  ${DIM}Handle deletions via migration.sql or manual intervention.${NC}"
    fi
fi

# Check if we have any files
if [[ ${#PATCH_FILES[@]} -eq 0 ]]; then
    error "No changed files to package." $EXIT_NO_FILES
fi

# Sort file list
IFS=$'\n' PATCH_FILES=($(sort <<<"${PATCH_FILES[*]}")); unset IFS

info "Files to package: ${#PATCH_FILES[@]}"
for f in "${PATCH_FILES[@]}"; do
    echo -e "  ${GREEN}+${NC} ${f}"
done

# ==============================================================================
# Auto-Extract Release Notes
# ==============================================================================

header "Release notes"

RELEASE_NOTES_CONTENT=""
RELEASE_NOTES_SOURCE=""

if [[ -n "$RELEASE_NOTES_FILE" ]]; then
    RELEASE_NOTES_CONTENT=$(cat "$RELEASE_NOTES_FILE")
    RELEASE_NOTES_SOURCE="provided file"
    success "Using release notes from: ${RELEASE_NOTES_FILE}"
elif ! $NO_CHANGELOG; then
    CHANGELOG_PATH="${PROJECT_DIR}/CHANGELOG.md"

    if [[ -f "$CHANGELOG_PATH" ]]; then
        RELEASE_NOTES_CONTENT=$(extract_changelog "$CHANGELOG_PATH" "$TARGET_VERSION")

        if [[ -n "$RELEASE_NOTES_CONTENT" ]]; then
            RELEASE_NOTES_SOURCE="CHANGELOG.md"
            success "Extracted release notes from CHANGELOG.md for v${TARGET_VERSION}"
        else
            warn "No entry found in CHANGELOG.md for version ${TARGET_VERSION}."
        fi
    else
        warn "No CHANGELOG.md found in project root."
    fi
else
    info "CHANGELOG extraction skipped (--no-changelog)."
fi

# ==============================================================================
# Resolve Output Directory
# ==============================================================================

if [[ -z "$OUTPUT_DIR" ]]; then
    OUTPUT_DIR="${PROJECT_DIR}/storage/patch"
fi

if [[ "$OUTPUT_DIR" != /* ]]; then
    OUTPUT_DIR="${PROJECT_DIR}/${OUTPUT_DIR}"
fi

ARCHIVE_NAME="patch-${TARGET_VERSION}.tgz"
ARCHIVE_PATH="${OUTPUT_DIR}/${ARCHIVE_NAME}"
HASH_PATH="${ARCHIVE_PATH}.sha256"

# ==============================================================================
# Summary & Confirmation
# ==============================================================================

header "Package Summary"

echo ""
echo -e "  ${BOLD}Version:${NC}        ${TARGET_VERSION}"
echo -e "  ${BOLD}Base ref:${NC}       ${BASE_REF} (${COMMIT_COUNT} commits)"
echo -e "  ${BOLD}Files:${NC}          ${#PATCH_FILES[@]}"
echo -e "  ${BOLD}Migration:${NC}      $([ -n "$MIGRATION_FILE" ] && echo "Yes ($(basename "$MIGRATION_FILE"))" || echo "No")"
echo -e "  ${BOLD}Release notes:${NC}  $([ -n "$RELEASE_NOTES_CONTENT" ] && echo "Yes (${RELEASE_NOTES_SOURCE})" || echo "No")"
echo -e "  ${BOLD}Output:${NC}         ${ARCHIVE_PATH}"

if $DRY_RUN; then
    echo ""
    info "Dry run mode — no archive created."
    print_elapsed
    exit $EXIT_SUCCESS
fi

# Check if archive already exists
OVERWRITE=false
if [[ -f "$ARCHIVE_PATH" ]]; then
    warn "Archive already exists: ${ARCHIVE_PATH}"
    OVERWRITE=true
fi

if ! $AUTO_CONFIRM; then
    echo ""
    if $OVERWRITE; then
        read -rp "$(echo -e "${YELLOW}Overwrite existing archive and create package? [y/N]:${NC} ")" confirm
    else
        read -rp "$(echo -e "${BOLD}Create patch package? [y/N]:${NC} ")" confirm
    fi

    if [[ "$confirm" != "y" && "$confirm" != "Y" ]]; then
        info "Cancelled by user."
        exit $EXIT_CANCELLED
    fi
fi

# ==============================================================================
# Build Package
# ==============================================================================

header "Building patch package"

# Create temp working directory
TEMP_DIR=$(mktemp -d "/tmp/patchcreator_XXXXXX")
trap 'rm -rf "$TEMP_DIR"' EXIT

info "Working directory: ${TEMP_DIR}"

# Copy files preserving directory structure
info "Copying ${#PATCH_FILES[@]} files..."
COPY_COUNT=0

for file in "${PATCH_FILES[@]}"; do
    src="${PROJECT_DIR}/${file}"
    dest="${TEMP_DIR}/files/${file}"

    # Create parent directory
    mkdir -p "$(dirname "$dest")"

    # Copy file
    cp "$src" "$dest"
    COPY_COUNT=$((COPY_COUNT + 1))
done

success "Copied ${COPY_COUNT} files"

# Generate manifest.json
info "Generating manifest.json..."

HAS_MIGRATION=false
if [[ -n "$MIGRATION_FILE" ]]; then
    HAS_MIGRATION=true
fi

# Build JSON file list array
FILES_JSON="["
FIRST=true
for file in "${PATCH_FILES[@]}"; do
    if $FIRST; then
        FIRST=false
    else
        FILES_JSON+=","
    fi
    # Escape any special JSON characters in filename
    ESCAPED_FILE=$(echo "$file" | sed 's/\\/\\\\/g; s/"/\\"/g')
    FILES_JSON+=$'\n'"        \"${ESCAPED_FILE}\""
done
FILES_JSON+=$'\n'"    ]"

cat > "${TEMP_DIR}/manifest.json" <<EOF
{
    "version": "${TARGET_VERSION}",
    "has_migration": ${HAS_MIGRATION},
    "files": ${FILES_JSON}
}
EOF

success "Created manifest.json"

# Copy migration file
if [[ -n "$MIGRATION_FILE" ]]; then
    cp "$MIGRATION_FILE" "${TEMP_DIR}/migration.sql"
    success "Included migration.sql"
fi

# Write release notes
if [[ -n "$RELEASE_NOTES_CONTENT" ]]; then
    echo "$RELEASE_NOTES_CONTENT" > "${TEMP_DIR}/release_notes.md"
    success "Included release_notes.md"
fi

# Create output directory
mkdir -p "$OUTPUT_DIR"

# Create .tgz archive
info "Creating archive: ${ARCHIVE_NAME}"
tar -czf "${ARCHIVE_PATH}" -C "${TEMP_DIR}" .

success "Archive created: ${ARCHIVE_PATH}"

# Calculate SHA-256 hash
info "Calculating SHA-256 hash..."
SHA256=$(sha256sum "${ARCHIVE_PATH}" | awk '{print $1}')
echo "${SHA256}  ${ARCHIVE_NAME}" > "${HASH_PATH}"

success "SHA-256: ${SHA256}"

# ==============================================================================
# Final Summary
# ==============================================================================

ARCHIVE_SIZE=$(stat -c%s "${ARCHIVE_PATH}" 2>/dev/null || stat -f%z "${ARCHIVE_PATH}" 2>/dev/null)

header "Patch package created"
echo ""
echo -e "  ${BOLD}Archive:${NC}   ${ARCHIVE_PATH}"
echo -e "  ${BOLD}Hash:${NC}      ${HASH_PATH}"
echo -e "  ${BOLD}Size:${NC}      $(format_size "$ARCHIVE_SIZE")"
echo -e "  ${BOLD}SHA-256:${NC}   ${SHA256}"
echo -e "  ${BOLD}Files:${NC}     ${#PATCH_FILES[@]}"
echo ""

print_elapsed
exit $EXIT_SUCCESS