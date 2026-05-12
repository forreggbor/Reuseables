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

VERSION="v1.03.00"
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
    'composer.lock'
    'package-lock.json'
    'README.md'
    'CHANGELOG.md'
    'doc/'
    'tests/'
    'phpunit.xml'
)

# Default version detection pattern (JupitERP convention)
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
    RED=$'\033[0;31m'
    GREEN=$'\033[0;32m'
    YELLOW=$'\033[1;33m'
    BLUE=$'\033[0;34m'
    CYAN=$'\033[0;36m'
    BOLD=$'\033[1m'
    DIM=$'\033[2m'
    NC=$'\033[0m'
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
    -o <dir>        Output directory (default: <project>/storage/patch)
    -r <file>       Release notes file (overrides CHANGELOG.md extraction)
    -f <file>       File list override (one path per line, relative to project)
    -e <pattern>    Exclude glob pattern (repeatable)
    -p <pattern>    Version detection regex pattern
    --no-changelog  Skip automatic CHANGELOG.md extraction
    --dry-run       Show what would be packaged without creating archive
    --no-validate   Skip PatchModule compatibility validation of the manifest
    -y              Auto-confirm (skip prompts)
    -h              Show this help message
    --version       Show script version

${BOLD}SQL MIGRATIONS:${NC}
    SQL migration files are auto-detected from database/migrations/*.sql in the
    git diff. No flag is needed. Files matching the YYYY_MM_DD_HHMMSS_*.sql
    convention are shipped in a migrations/ directory inside the archive and
    executed by PatchModule v1.8.0+ in chronological (lexicographic) order.
    PHP migrations and files in subdirectories are skipped with a warning.

${BOLD}EXAMPLES:${NC}
    # Create patch from latest tag, auto-detect version
    ${SCRIPT_NAME}

    # Create patch against specific commit
    ${SCRIPT_NAME} -b abc1234

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
      migrations/        SQL migration files (auto-detected; omitted when none)
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
    [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.-]+)?$ ]]
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
# Check that a relative file path is safe to include in the manifest.
#
# Mirrors PatchModule's PatchFileManager::safeJoin() rules so that any path
# that passes here is guaranteed to pass PatchModule's validation.
#
# @param string $1 Path to validate
# @return 0 if safe, non-zero otherwise
##
validate_safe_path() {
    local path="$1"

    [[ -z "$path" ]]         && return 1  # empty
    [[ "$path" == /* ]]      && return 1  # absolute (Unix)
    [[ "$path" =~ ^[A-Za-z]: ]] && return 1  # Windows drive letter
    [[ "$path" == *\\* ]]    && return 1  # backslash

    # Reject any dotdot, lone-dot, or empty segment (from double slashes)
    local IFS='/'
    read -ra segments <<< "$path"
    for seg in "${segments[@]}"; do
        [[ "$seg" == ".." || "$seg" == "." || -z "$seg" ]] && return 1
    done

    return 0
}

##
# Validate manifest.json against PatchModule's acceptance rules.
#
# Checks the same constraints that PatchFileManager::extractPatch() enforces,
# so failures are caught at build time rather than at install time.
#
# @param string $1 Path to manifest.json
# @param string $2 Path to temp build directory (for symlink check)
##
validate_manifest() {
    local manifest_path="$1"
    local temp_dir="$2"

    info "Validating manifest against PatchModule rules..."

    # Must parse as a JSON object
    if ! jq -e 'type == "object"' "$manifest_path" > /dev/null 2>&1; then
        error "Manifest validation failed: manifest.json is not a valid JSON object [invalid_manifest_schema]"
    fi

    # version must be a non-empty string
    local version
    version=$(jq -r '.version // empty' "$manifest_path" 2>/dev/null)
    if [[ -z "$version" ]]; then
        error "Manifest validation failed: missing or empty 'version' field [invalid_manifest_schema]"
    fi

    # version must match PatchModule's semver regex exactly
    if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.-]+)?$ ]]; then
        error "Manifest validation failed: 'version' is not a valid semver string [invalid_manifest_schema]"
    fi

    # migrations must be a required array; each entry must be a safe SQL basename
    local migrations_type
    migrations_type=$(jq -r 'if has("migrations") then (.migrations | type) else "absent" end' "$manifest_path" 2>/dev/null)
    if [[ "$migrations_type" == "absent" ]]; then
        error "Manifest validation failed: required field 'migrations' is missing [invalid_manifest_schema]"
    fi
    if [[ "$migrations_type" != "array" ]]; then
        error "Manifest validation failed: 'migrations' must be an array [invalid_manifest_schema]"
    fi

    local mig_entry
    while IFS= read -r mig_entry; do
        # Each entry must be a safe basename: no leading dot or hyphen, only .sql extension
        if ! [[ "$mig_entry" =~ ^[A-Za-z0-9_][A-Za-z0-9._-]*\.sql$ ]]; then
            error "Manifest validation failed: invalid migration filename '${mig_entry}' (must match ^[A-Za-z0-9_][A-Za-z0-9._-]*.sql$) [invalid_archive]"
        fi
        # Each entry must have a corresponding file on disk
        if [[ ! -f "${temp_dir}/migrations/${mig_entry}" ]]; then
            error "Manifest validation failed: migrations[] entry '${mig_entry}' has no corresponding file in migrations/ [invalid_archive]"
        fi
    done < <(jq -r '.migrations[]' "$manifest_path" 2>/dev/null)

    # Cross-check: every file in migrations/ must be listed in manifest.migrations[]
    if [[ -d "${temp_dir}/migrations" ]]; then
        local disk_file
        while IFS= read -r disk_file; do
            local disk_basename
            disk_basename=$(basename "$disk_file")
            local in_manifest
            in_manifest=$(jq -r --arg f "$disk_basename" '.migrations | map(select(. == $f)) | length' "$manifest_path" 2>/dev/null)
            if [[ "${in_manifest:-0}" -eq 0 ]]; then
                error "Manifest validation failed: migrations/${disk_basename} exists on disk but is not listed in manifest.migrations[] [invalid_archive]"
            fi
        done < <(find "${temp_dir}/migrations" -maxdepth 1 -name '*.sql' -type f)
    fi

    # files and removed_files (when present) must be arrays of strings with safe paths
    for field in files removed_files; do
        local field_type
        field_type=$(jq -r --arg f "$field" 'if has($f) then (.[$f] | type) else "absent" end' "$manifest_path" 2>/dev/null)

        [[ "$field_type" == "absent" ]] && continue

        if [[ "$field_type" != "array" ]]; then
            error "Manifest validation failed: '${field}' must be an array [invalid_manifest_schema]"
        fi

        local non_strings
        non_strings=$(jq -r --arg f "$field" '.[$f] | map(select(type != "string")) | length' "$manifest_path")
        if [[ "${non_strings:-0}" -gt 0 ]]; then
            error "Manifest validation failed: all entries in '${field}' must be strings [invalid_manifest_schema]"
        fi

        local entry
        while IFS= read -r entry; do
            if ! validate_safe_path "$entry"; then
                error "Manifest validation failed: unsafe path in '${field}': '${entry}' [invalid_manifest_path]"
            fi
        done < <(jq -r --arg f "$field" '.[$f][]' "$manifest_path" 2>/dev/null)
    done

    # No symlinks anywhere in the build tree
    local first_symlink
    first_symlink=$(find "$temp_dir" -type l | head -1)
    if [[ -n "$first_symlink" ]]; then
        error "Manifest validation failed: archive would contain a symbolic link: ${first_symlink} [invalid_archive]"
    fi

    success "Manifest validation passed"
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
OUTPUT_DIR=""
RELEASE_NOTES_FILE=""
FILE_LIST=""
USER_EXCLUDES=()
VERSION_PATTERN="$DEFAULT_VERSION_PATTERN"
NO_CHANGELOG=false
DRY_RUN=false
AUTO_CONFIRM=false
VALIDATE=true

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
        --no-validate)
            VALIDATE=false
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

# jq is required for JSON escaping and manifest validation
if ! command -v jq &>/dev/null; then
    error "Required tool not found: jq. Install it with: sudo apt install jq"
fi

# Validate project directory
if [[ ! -d "$PROJECT_DIR" ]]; then
    error "Project directory does not exist: ${PROJECT_DIR}"
fi

if ! is_git_repo "$PROJECT_DIR"; then
    error "Not a git repository: ${PROJECT_DIR}" $EXIT_GIT_ERROR
fi

success "Project directory: ${PROJECT_DIR}"

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
declare -a REMOVED_FILES=()
declare -a MIGRATION_FILES=()
declare -a MIGRATION_BASENAMES=()

##
# Classify a single file path into either MIGRATION_FILES, PATCH_FILES, or skip.
# Also handles D-line (deleted) paths via the optional second argument.
#
# $1 = relative file path
# $2 = "delete" if this is a deleted-file path (D-line from git diff)
##
classify_file() {
    local path="$1"
    local is_delete="${2:-}"

    # Is the path anywhere under database/migrations/?
    if [[ "$path" =~ ^database/migrations/ ]]; then
        # Deleted migration files are silently dropped — not added to removed_files
        [[ "$is_delete" == "delete" ]] && return

        # Is it a direct child (no subdirectory)?
        local basename_only
        basename_only=$(basename "$path")
        local dir_part
        dir_part=$(dirname "$path")
        if [[ "$dir_part" != "database/migrations" ]]; then
            warn "Skipping subdirectory migration: ${path} (only direct children of database/migrations/ are supported)"
            return
        fi

        # PHP migration — skip with WARN
        if [[ "$path" =~ ^database/migrations/[^/]+\.php$ ]]; then
            warn "Skipping PHP migration (not supported in v1.8.0): ${path}"
            return
        fi

        # SQL migration — validate filename and collect
        if [[ "$path" =~ ^database/migrations/[^/]+\.sql$ ]]; then
            # Tightened filename safety regex: must not start with . or -
            if ! [[ "$basename_only" =~ ^[A-Za-z0-9_][A-Za-z0-9._-]*\.sql$ ]]; then
                error "Migration filename is invalid: ${basename_only} (must match ^[A-Za-z0-9_][A-Za-z0-9._-]*.sql$)"
            fi
            # HHMMSS must be 6 digits for correct chronological sort
            if ! [[ "$basename_only" =~ ^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_ ]]; then
                warn "Migration filename does not have a valid YYYY_MM_DD_HHMMSS_ prefix: ${basename_only} (may sort incorrectly)"
            fi
            MIGRATION_FILES+=("$path")
            MIGRATION_BASENAMES+=("$basename_only")
            return
        fi

        # Other extension under database/migrations/ — treat as a regular file
        PATCH_FILES+=("$path")
        return
    fi

    # Regular file — add to PATCH_FILES (deleted files go to REMOVED_FILES handled separately)
    if [[ "$is_delete" != "delete" ]]; then
        PATCH_FILES+=("$path")
    else
        REMOVED_FILES+=("$path")
    fi
}

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

        classify_file "$line"
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

    # Partition files: migrations vs regular patch files
    for file in "${RAW_FILES[@]}"; do
        if matches_exclude "$file" "${ALL_EXCLUDES[@]}"; then
            continue
        fi

        # Verify file still exists (might have been moved/deleted after diff)
        if [[ ! -f "${PROJECT_DIR}/${file}" ]]; then
            warn "File no longer exists, skipping: ${file}"
            continue
        fi

        classify_file "$file"
    done

    # Detect deleted files and collect them for the manifest
    # Deletions under database/migrations/ are silently dropped (see classify_file)
    mapfile -t RAW_DELETED < <(git -C "$PROJECT_DIR" diff --name-only --diff-filter=D "${BASE_REF}..HEAD" 2>/dev/null)

    for df in "${RAW_DELETED[@]}"; do
        if matches_exclude "$df" "${ALL_EXCLUDES[@]}"; then
            continue
        fi
        classify_file "$df" "delete"
    done
fi

# Check if we have any files, migrations, or deletions
if [[ ${#PATCH_FILES[@]} -eq 0 && ${#REMOVED_FILES[@]} -eq 0 && ${#MIGRATION_FILES[@]} -eq 0 ]]; then
    error "No changed or deleted files to package." $EXIT_NO_FILES
fi

# Sort file lists
if [[ ${#PATCH_FILES[@]} -gt 0 ]]; then
    IFS=$'\n' PATCH_FILES=($(sort <<<"${PATCH_FILES[*]}")); unset IFS
fi
if [[ ${#REMOVED_FILES[@]} -gt 0 ]]; then
    IFS=$'\n' REMOVED_FILES=($(sort <<<"${REMOVED_FILES[*]}")); unset IFS
fi
if [[ ${#MIGRATION_BASENAMES[@]} -gt 0 ]]; then
    IFS=$'\n' MIGRATION_BASENAMES=($(sort <<<"${MIGRATION_BASENAMES[*]}")); unset IFS
    IFS=$'\n' MIGRATION_FILES=($(sort <<<"${MIGRATION_FILES[*]}")); unset IFS
fi

if [[ ${#PATCH_FILES[@]} -gt 0 ]]; then
    info "Files to package: ${#PATCH_FILES[@]}"
    for f in "${PATCH_FILES[@]}"; do
        echo -e "  ${GREEN}+${NC} ${f}"
    done
fi

if [[ ${#MIGRATION_FILES[@]} -gt 0 ]]; then
    info "SQL migrations to include: ${#MIGRATION_FILES[@]}"
    for mf in "${MIGRATION_FILES[@]}"; do
        echo -e "  ${GREEN}~${NC} ${mf}"
    done
fi

if [[ ${#REMOVED_FILES[@]} -gt 0 ]]; then
    info "Files to remove: ${#REMOVED_FILES[@]}"
    for df in "${REMOVED_FILES[@]}"; do
        echo -e "  ${RED}-${NC} ${df}"
    done
fi

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
echo -e "  ${BOLD}Files:${NC}          ${#PATCH_FILES[@]} added/modified, ${#REMOVED_FILES[@]} to remove"
echo -e "  ${BOLD}Migrations:${NC}     ${#MIGRATION_FILES[@]} SQL migration(s)"
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

    # -L: always dereference symlinks — PatchModule rejects any archive that contains a symlink
    cp -L "$src" "$dest"
    COPY_COUNT=$((COPY_COUNT + 1))
done

success "Copied ${COPY_COUNT} files"

# Copy SQL migration files into migrations/ directory
if [[ ${#MIGRATION_FILES[@]} -gt 0 ]]; then
    mkdir -p "${TEMP_DIR}/migrations"
    for mf in "${MIGRATION_FILES[@]}"; do
        # -L: always dereference symlinks — PatchModule rejects any archive that contains a symlink
        cp -L "${PROJECT_DIR}/${mf}" "${TEMP_DIR}/migrations/$(basename "$mf")"
    done
    success "Copied ${#MIGRATION_FILES[@]} SQL migration(s) to migrations/"
fi

# Generate manifest.json
info "Generating manifest.json..."

# Build JSON migrations array — always present (empty array when no migrations)
MIGRATIONS_JSON="["
MIG_FIRST=true
for mb in "${MIGRATION_BASENAMES[@]}"; do
    if $MIG_FIRST; then
        MIG_FIRST=false
    else
        MIGRATIONS_JSON+=","
    fi
    JSON_MB=$(jq -Rn --arg s "$mb" '$s')
    MIGRATIONS_JSON+=$'\n'"        ${JSON_MB}"
done
MIGRATIONS_JSON+=$'\n'"    ]"

# Build JSON file list array — jq handles all escaping (control chars, Unicode, etc.)
FILES_JSON="["
FIRST=true
for file in "${PATCH_FILES[@]}"; do
    if $FIRST; then
        FIRST=false
    else
        FILES_JSON+=","
    fi
    JSON_FILE=$(jq -Rn --arg s "$file" '$s')
    FILES_JSON+=$'\n'"        ${JSON_FILE}"
done
FILES_JSON+=$'\n'"    ]"

# Build JSON removed files array (omitted from manifest when empty)
REMOVED_FILES_JSON=""
if [[ ${#REMOVED_FILES[@]} -gt 0 ]]; then
    RF_JSON="["
    RF_FIRST=true
    for rf in "${REMOVED_FILES[@]}"; do
        if $RF_FIRST; then
            RF_FIRST=false
        else
            RF_JSON+=","
        fi
        JSON_RF=$(jq -Rn --arg s "$rf" '$s')
        RF_JSON+=$'\n'"        ${JSON_RF}"
    done
    RF_JSON+=$'\n'"    ]"
    REMOVED_FILES_JSON=",
    \"removed_files\": ${RF_JSON}"
fi

cat > "${TEMP_DIR}/manifest.json" <<EOF
{
    "version": "${TARGET_VERSION}",
    "migrations": ${MIGRATIONS_JSON},
    "files": ${FILES_JSON}${REMOVED_FILES_JSON}
}
EOF

success "Created manifest.json"

# Write release notes
if [[ -n "$RELEASE_NOTES_CONTENT" ]]; then
    echo "$RELEASE_NOTES_CONTENT" > "${TEMP_DIR}/release_notes.md"
    success "Included release_notes.md"
fi

# Validate manifest against PatchModule's acceptance rules (on by default, disable with --no-validate).
# Runs after all files are in TEMP_DIR so the symlink check covers the full archive contents.
if $VALIDATE; then
    validate_manifest "${TEMP_DIR}/manifest.json" "${TEMP_DIR}"
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
echo -e "  ${BOLD}Migrations:${NC} ${#MIGRATION_FILES[@]}"
echo ""

print_elapsed
exit $EXIT_SUCCESS