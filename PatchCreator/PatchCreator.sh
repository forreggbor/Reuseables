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

VERSION="v1.09.00"
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
    'package-lock.json'
    'tests/'
    'phpunit.xml'
)

# Paths that are re-admitted even when matched by an exclude pattern.
# Allows fine-grained exceptions to broad directory excludes (e.g. vendor/).
DEFAULT_ALLOW_OVERRIDES=(
    'vendor/autoload.php'
    'vendor/composer/'
)

# Default version detection pattern (APP_VERSION PHP constant)
DEFAULT_VERSION_PATTERN="define\('APP_VERSION',\s*'([^']+)'\)"
# Candidate paths searched in order; [Hh]elpers covers both casing conventions
DEFAULT_VERSION_FILES=(
    "app/[Hh]elpers/functions.php"
    "webroot/app/[Hh]elpers/functions.php"
    "public/app/[Hh]elpers/functions.php"
)

# Exit codes
EXIT_SUCCESS=0
EXIT_ERROR=1
EXIT_NO_FILES=2
EXIT_GIT_ERROR=3
EXIT_CANCELLED=4
EXIT_UPLOAD_FAILED=5

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
    echo -e "${BLUE}[INFO]${NC} $1" >&2
}

##
# Print a success message.
#
# @param string $1 Success message
##
success() {
    echo -e "${GREEN}[OK]${NC} $1" >&2
}

##
# Print a section header.
#
# @param string $1 Header text
##
header() {
    echo "" >&2
    echo -e "${BOLD}${CYAN}── $1 ──${NC}" >&2
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
    -b <git-ref>    Base git reference to diff against (default: last patch's build commit, then latest tag)
    -o <dir>        Output directory (default: <project>/storage/patch)
    -r <file>       Release notes file (overrides CHANGELOG.md extraction)
    -f <file>       File list override (one path per line, relative to project)
    -e <pattern>    Exclude glob pattern (repeatable)
    -i <pattern>    Allow-override pattern — re-admits files that match an exclude (repeatable)
    -p <pattern>    Version detection regex pattern
    --no-changelog  Skip automatic CHANGELOG.md extraction
    --dry-run       Show what would be packaged without creating archive
    --no-upload     Skip automatic upload even if a target is configured
    --no-validate   Skip PatchModule compatibility validation of the manifest
    --upload        Force upload even if auto-upload would be skipped
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

    # Re-admit a specific vendor package that ships its own migration runner
    ${SCRIPT_NAME} -i "vendor/acme/"

${BOLD}EXIT CODES:${NC}
    0   Success
    1   General error (invalid arguments, missing files)
    2   No changed files to package
    3   Git error (not a repository, invalid reference)
    4   User cancelled
    5   Upload failed (patch was created but could not be delivered)

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
# Iterates over candidate file-glob patterns (relative to project dir) and
# returns the first non-empty version string found.
#
# @param string $1   Project directory
# @param string $2   Version regex pattern (PCRE, must use \K lookbehind to
#                    emit only the version string)
# @param string $3…  Candidate file globs relative to project dir
# @return Version string or empty
##
detect_version() {
    local project_dir="$1"
    local pattern="$2"
    shift 2

    local prev_nullglob
    prev_nullglob=$(shopt -p nullglob)
    shopt -s nullglob

    local candidate full_path version
    for candidate in "$@"; do
        for full_path in "$project_dir"/$candidate; do
            [[ -f "$full_path" ]] || continue
            version=$(grep -oP "define\('APP_VERSION',\s*'\K[^']+" "$full_path" 2>/dev/null | head -1 || true)
            if [[ -n "$version" ]]; then
                eval "$prev_nullglob"
                echo "$version"
                return 0
            fi
        done
    done

    eval "$prev_nullglob"
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
# Find the highest-version patch-*.tgz archive in the given output directory.
#
# @param string $1 Output directory path
# @param string $2 Optional upper bound (exclusive): archives whose version is >= this value are skipped
# @return Absolute path to the highest-version archive, or empty string if none found
##
find_last_patch_archive() {
    local output_dir="$1"
    local max_excluded="${2:-}"
    local best_path=""
    local best_ver=""

    [[ -d "$output_dir" ]] || return 0

    local prev_nullglob
    prev_nullglob=$(shopt -p nullglob)
    shopt -s nullglob

    local f bn ver hi
    for f in "$output_dir"/patch-*.tgz; do
        [[ -f "$f" ]] || continue
        bn=$(basename "$f" .tgz)
        ver="${bn#patch-}"
        is_valid_semver "$ver" || continue

        if [[ -n "$max_excluded" ]]; then
            hi=$(printf '%s\n%s' "$ver" "$max_excluded" | sort -V | tail -1)
            [[ "$hi" == "$ver" ]] && continue
        fi

        if [[ -z "$best_ver" ]] || [[ "$(printf '%s\n%s' "$best_ver" "$ver" | sort -V | tail -1)" == "$ver" && "$ver" != "$best_ver" ]]; then
            best_ver="$ver"
            best_path="$f"
        fi
    done

    eval "$prev_nullglob"
    echo "$best_path"
}

##
# Extract the built_from_commit SHA from a patch archive's manifest.json.
#
# @param string $1 Path to .tgz archive
# @return 40-character hex SHA, or empty string if the field is absent or unreadable
##
extract_manifest_sha() {
    local tgz_path="$1"
    tar -xzOf "$tgz_path" manifest.json 2>/dev/null | jq -r '.built_from_commit // empty' 2>/dev/null || true
}

##
# Resolve the diff base reference from the last patch archive in OUTPUT_DIR.
#
# Resolution order:
#  1. Read built_from_commit SHA from the last archive's manifest.json.
#     Use it if it exists in the repo and is an ancestor of HEAD.
#  2. Fall back to a git tag matching the archive's version (tries vX.Y.Z, then X.Y.Z).
#     If no matching tag is reachable from HEAD, error out — the caller must pass -b explicitly.
#  3. If OUTPUT_DIR has no patch archives, return 1 (caller falls back to latest tag).
#
# Archives whose version is >= target_version are excluded so that a prior failed/partial
# build of the same version does not cause the resolver to diff against HEAD itself.
#
# On success (return 0) sets globals:
#   RESOLVED_BASE_REF     — the base git reference (SHA or tag name)
#   RESOLVED_BASE_VERSION — the version string of the last patch archive (e.g. "1.07.01")
#
# @param string $1 Project directory
# @param string $2 Output directory to scan for patch archives
# @param string $3 Target version being built (archives >= this version are skipped)
# @return 0 if a previous patch archive was found and resolved; 1 if no archive exists
##
resolve_cumulative_base() {
    local project_dir="$1"
    local output_dir="$2"
    local target_version="$3"

    local last_tgz
    last_tgz=$(find_last_patch_archive "$output_dir" "$target_version")

    if [[ -z "$last_tgz" ]]; then
        return 1
    fi

    local last_basename
    last_basename=$(basename "$last_tgz")
    local last_ver="${last_basename%.tgz}"
    last_ver="${last_ver#patch-}"

    # Prefer the commit SHA embedded in the archive's manifest
    local sha
    sha=$(extract_manifest_sha "$last_tgz")

    if [[ -n "$sha" ]]; then
        if [[ "$sha" =~ ^[0-9a-f]{40}$ ]] &&
           git -C "$project_dir" rev-parse "$sha^{commit}" &>/dev/null &&
           git -C "$project_dir" merge-base --is-ancestor "$sha" HEAD 2>/dev/null; then
            info "Cumulative patch since last package (${last_basename}, commit ${sha:0:7})"
            RESOLVED_BASE_REF="$sha"
            RESOLVED_BASE_VERSION="$last_ver"
            return 0
        else
            warn "Last patch's commit SHA is not an ancestor of HEAD (rebased or wrong branch); trying tag lookup."
        fi
    fi

    # Fallback: resolve via git tag matching the archive version
    local candidate
    for candidate in "v${last_ver}" "${last_ver}"; do
        if git_ref_exists "$project_dir" "$candidate" &&
           git -C "$project_dir" merge-base --is-ancestor "$candidate" HEAD 2>/dev/null; then
            info "Cumulative patch since last package (${candidate})"
            RESOLVED_BASE_REF="$candidate"
            RESOLVED_BASE_VERSION="$last_ver"
            return 0
        fi
    done

    error "Found last patch '${last_basename}' but no build SHA in its manifest and no matching git tag (tried v${last_ver}, ${last_ver}) is reachable from HEAD. Pass -b <ref> to specify the base explicitly, or move the orphan archive out of ${output_dir}." $EXIT_GIT_ERROR
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

    # built_from_commit, when present, must be a 40-character lowercase hex SHA
    local bfc
    bfc=$(jq -r '.built_from_commit // empty' "$manifest_path" 2>/dev/null)
    if [[ -n "$bfc" && ! "$bfc" =~ ^[0-9a-f]{40}$ ]]; then
        error "Manifest validation failed: 'built_from_commit' is not a valid 40-character hex SHA [invalid_manifest_schema]"
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
# Extract release notes covering all versions between base and target from CHANGELOG.md.
#
# Collects every version block from ## [target_version] down to (but not including)
# ## [base_version]. CHANGELOG is assumed newest-first (Keep a Changelog). When
# base_version is empty or not found, collects through EOF.
#
# @param string $1 Path to CHANGELOG.md
# @param string $2 Target version (newest version to include)
# @param string $3 Base version (exclusive lower bound; empty = no lower bound)
# @return Collected release notes body (may span multiple version blocks)
##
extract_changelog() {
    local changelog_file="$1"
    local target_version="$2"
    local base_version="${3:-}"

    if [[ ! -f "$changelog_file" ]]; then
        return 0
    fi

    local in_section=false
    local content=""

    while IFS= read -r line; do
        # Start collecting at the target version header
        if ! $in_section && [[ "$line" =~ ^##[[:space:]]+\[${target_version}\] ]]; then
            in_section=true
            content="${line}"$'\n'
            continue
        fi

        # Stop (exclusive) when reaching the base version header
        if $in_section && [[ -n "$base_version" ]] && [[ "$line" =~ ^##[[:space:]]+\[${base_version}\] ]]; then
            break
        fi

        # Accumulate lines while in section (including intermediate version headers)
        if $in_section; then
            content+="${line}"$'\n'
        fi
    done < "$changelog_file"

    echo "$content" | sed -e 's/[[:space:]]*$//'
}

##
# Build a consolidated | Version | Category | Description | summary table from a
# multi-version release notes body produced by extract_changelog.
#
# Reads the per-version summary tables (pipe-rows appearing before the first ###
# section in each version block) and emits a single table with a Version column.
# Header and separator rows are excluded; pipe-rows after a ### heading are ignored.
#
# @param string $1 Multi-version release notes body
# @return Markdown table string, or empty if no data rows were found
##
build_consolidated_table() {
    local body="$1"
    local current_ver=""
    local in_summary=false
    local seen_separator=false
    local rows=""
    local line stripped col1 col2
    local -a _cells

    while IFS= read -r line; do
        if [[ "$line" =~ ^##[[:space:]]+\[([^]]+)\] ]]; then
            current_ver="${BASH_REMATCH[1]}"
            in_summary=true
            seen_separator=false
            continue
        fi
        if [[ "$line" =~ ^###[[:space:]] ]]; then
            in_summary=false
            continue
        fi
        if $in_summary && [[ "$line" =~ ^\| ]]; then
            if [[ "$line" =~ ^\|[[:space:]]*[-:] ]]; then
                seen_separator=true
                continue
            fi
            $seen_separator || continue
            stripped="${line#|}"
            stripped="${stripped%|}"
            IFS='|' read -ra _cells <<< "$stripped"
            col1="$(echo "${_cells[0]:-}" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
            col2="$(echo "${_cells[1]:-}" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
            rows+="| ${current_ver} | ${col1} | ${col2} |"$'\n'
        fi
    done <<< "$body"

    [[ -z "$rows" ]] && return 0

    printf '| Version | Category | Description |\n'
    printf '|---------|----------|-------------|\n'
    printf '%s' "$rows"
}

##
# Strip per-version summary tables from a release notes body.
#
# Removes pipe-rows that appear before the first ### section in each version block
# (the per-version summary tables), since they are merged into the consolidated table
# by build_consolidated_table. Version headers, ### sections, bullet lists, and ---
# separators are preserved intact.
#
# @param string $1 Multi-version release notes body
# @return Body with per-version summary tables removed
##
strip_version_tables() {
    local body="$1"
    local in_summary=false
    local result=""
    local line

    while IFS= read -r line; do
        if [[ "$line" =~ ^##[[:space:]]+\[ ]]; then
            in_summary=true
            result+="${line}"$'\n'
            continue
        fi
        if [[ "$line" =~ ^###[[:space:]] ]]; then
            in_summary=false
            result+="${line}"$'\n'
            continue
        fi
        if $in_summary && [[ "$line" =~ ^\| ]]; then
            continue
        fi
        result+="${line}"$'\n'
    done <<< "$body"

    echo "$result" | sed -e 's/[[:space:]]*$//'
}

##
# Assemble consolidated release notes for a single changelog file.
#
# Extracts the version range, builds the consolidated summary table, and strips
# per-version summary tables from the detail sections. Returns nothing if the target
# version has no entry in the changelog.
#
# @param string $1 Path to changelog file
# @param string $2 Target version
# @param string $3 Base version (exclusive lower bound)
# @return Consolidated notes: summary table followed by per-version detail sections
##
assemble_consolidated_notes() {
    local changelog_path="$1"
    local target="$2"
    local base="$3"
    local body table details

    body=$(extract_changelog "$changelog_path" "$target" "$base")
    [[ -z "$body" ]] && return 0

    table=$(build_consolidated_table "$body")
    details=$(strip_version_tables "$body")

    if [[ -n "$table" ]]; then
        printf '%s\n\n%s' "$table" "$details"
    else
        printf '%s' "$details"
    fi
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

##
# Upload a patch archive to LicenseManager via the /api/v1/patches/upload endpoint.
#
# Uses file-based curl I/O (body + headers to temp files) so Retry-After header
# is readable and response body is available regardless of HTTP status.
# --fail-with-body is mandatory: --fail discards the body on 4xx/5xx, losing
# error.error_code on 422 and data.sha256 for 409 idempotent-retry detection.
# Requires curl >= 7.76 (Ubuntu 24.04 ships 8.x).
#
# @param string $1 Path to the .tgz archive
# @param string $2 SHA-256 hex digest of the archive
# @param string $3 Version string (e.g. "1.07.00")
# @param string $4 Upload URL (must be https://)
# @param string $5 Bearer token
# @return 0 on success (201 or 409-idempotent), non-zero on any failure
##
upload_patch_to_licensemanager() {
    local archive_path="$1"
    local sha256="$2"
    local version="$3"
    local upload_url="$4"
    local token="$5"

    local body_file headers_file
    body_file=$(mktemp) || error "mktemp failed"
    headers_file=$(mktemp) || error "mktemp failed"
    # shellcheck disable=SC2064
    trap "rm -f '$body_file' '$headers_file'" RETURN

    local -a backoff_delays=(5 30 120)
    local attempt http_code curl_rc

    for attempt in 1 2 3 4; do
        if [[ $attempt -gt 1 ]]; then
            local delay="${backoff_delays[$((attempt - 2))]}"
            info "Upload attempt ${attempt}/4 (waiting ${delay}s after failure)..."
            sleep "$delay"
            : > "$body_file"
            : > "$headers_file"
        fi

        curl_rc=0
        http_code=$(curl --fail-with-body --show-error --silent \
            --max-time 600 \
            -H "Authorization: Bearer $token" \
            -H "Expect:" \
            -F "patch_file=@$archive_path" \
            -F "sha256=$sha256" \
            -o "$body_file" -D "$headers_file" \
            -w '%{http_code}' \
            "$upload_url") || curl_rc=$?

        # --- Transient network errors → retry ---
        if [[ $curl_rc -eq 28 || $curl_rc -eq 35 || $curl_rc -eq 56 ]]; then
            warn "Upload attempt ${attempt}/4 failed (curl exit ${curl_rc})"
            [[ $attempt -lt 4 ]] && continue
            warn "Upload failed after 4 attempts (network/TLS error)"
            return 1
        fi

        # --- Partial transfer → no retry ---
        if [[ $curl_rc -eq 18 ]]; then
            warn "Upload failed: partial transfer — patch may exceed server upload size limit"
            return 1
        fi

        # --- Other non-HTTP curl error ---
        if [[ $curl_rc -ne 0 && $curl_rc -ne 22 ]]; then
            warn "Upload failed: curl exit ${curl_rc}"
            return 1
        fi

        # --- We have an HTTP response (curl_rc=0 success or =22 HTTP error status) ---
        # Check Content-Type before JSON parsing
        local content_type
        content_type=$(grep -i '^content-type:' "$headers_file" | tail -n1 | awk '{print $2}' | tr -d '\r')
        if [[ "$content_type" != application/json* ]]; then
            warn "Server returned non-JSON response (HTTP ${http_code}): $(head -c 200 "$body_file")"
            return 1
        fi

        # --- HTTP status dispatch ---
        case "$http_code" in
            201)
                local uploaded_version uploaded_patch_id
                uploaded_version=$(jq -r '.data.version // empty' "$body_file")
                uploaded_patch_id=$(jq -r '.data.patch_id // empty' "$body_file")
                success "Uploaded ${uploaded_version} (patch_id=${uploaded_patch_id})"
                return 0
                ;;
            409)
                local server_sha local_sha
                server_sha=$(jq -r '.data.sha256 // empty' "$body_file" | tr '[:upper:]' '[:lower:]')
                local_sha=$(echo "$sha256" | tr '[:upper:]' '[:lower:]')
                if [[ -n "$server_sha" && "$server_sha" == "$local_sha" ]]; then
                    info "Patch ${version} already uploaded (idempotent retry)"
                    return 0
                fi
                warn "Version ${version} exists on server with different content (server SHA: ${server_sha:-unknown}, local SHA: ${local_sha}). Manual intervention required."
                return 1
                ;;
            422)
                local error_code
                error_code=$(jq -r '.error.error_code // empty' "$body_file")
                warn "Upload rejected (422 ${error_code:-unknown})"
                return 1
                ;;
            401|403)
                local msg detail
                msg=$(jq -r '.error.message // empty' "$body_file")
                detail=$(jq -r '.error.detail // empty' "$body_file")
                warn "Upload not authorised (${http_code}): ${msg}${detail:+ — ${detail}}"
                return 1
                ;;
            404)
                warn "Upload endpoint not found (404) — is LicenseManager >= v2.16.1 at ${upload_url}?"
                return 1
                ;;
            429)
                local retry_after
                retry_after=$(grep -i '^retry-after:' "$headers_file" | tail -n1 | awk '{print $2}' | tr -d '\r')
                retry_after="${retry_after:-60}"
                info "Rate limited (429) — sleeping ${retry_after}s then retrying once..."
                sleep "$retry_after"
                : > "$body_file"
                : > "$headers_file"
                curl_rc=0
                http_code=$(curl --fail-with-body --show-error --silent \
                    --max-time 600 \
                    -H "Authorization: Bearer $token" \
                    -H "Expect:" \
                    -F "patch_file=@$archive_path" \
                    -F "sha256=$sha256" \
                    -o "$body_file" -D "$headers_file" \
                    -w '%{http_code}' \
                    "$upload_url") || curl_rc=$?
                if [[ ($curl_rc -eq 0 || $curl_rc -eq 22) ]]; then
                    local ct2
                    ct2=$(grep -i '^content-type:' "$headers_file" | tail -n1 | awk '{print $2}' | tr -d '\r')
                    if [[ "$ct2" != application/json* ]]; then
                        warn "Server returned non-JSON on rate-limit retry (HTTP ${http_code}): $(head -c 200 "$body_file")"
                        return 1
                    fi
                    if [[ "$http_code" == "201" ]]; then
                        local v2 id2
                        v2=$(jq -r '.data.version // empty' "$body_file")
                        id2=$(jq -r '.data.patch_id // empty' "$body_file")
                        success "Uploaded ${v2} (patch_id=${id2})"
                        return 0
                    fi
                fi
                warn "Upload failed after rate-limit retry (HTTP ${http_code:-?}, curl ${curl_rc})"
                return 1
                ;;
            5*)
                # Server error → retry via the outer loop
                warn "Upload attempt ${attempt}/4 failed (HTTP ${http_code} — server error)"
                [[ $attempt -lt 4 ]] && continue
                warn "Upload failed after 4 attempts (server error)"
                return 1
                ;;
            *)
                warn "Unexpected HTTP response: ${http_code}"
                return 1
                ;;
        esac
    done
}

##
# Resolve upload configuration from environment variables or .patchcreator.local.
#
# Precedence: env vars (if BOTH are set) → .patchcreator.local → neither.
# Sets globals UPLOAD_URL and TOKEN.
# Exits loudly on configuration errors (invalid JSON, missing keys, non-HTTPS URL).
# Returns silently with exit 1 if no config source is found.
#
# @return 0 if UPLOAD_URL and TOKEN are resolved, 1 if no config source present
##
resolve_upload_config() {
    UPLOAD_URL=""
    TOKEN=""

    # --- Env source (BOTH must be non-empty; partial falls through) ---
    if [[ -n "${PATCHCREATOR_UPLOAD_URL:-}" && -n "${PATCHCREATOR_TOKEN:-}" ]]; then
        UPLOAD_URL="$PATCHCREATOR_UPLOAD_URL"
        TOKEN="$PATCHCREATOR_TOKEN"
    else
        # --- File source ---
        local config_file="${PROJECT_DIR}/.patchcreator.local"
        if [[ -f "$config_file" ]]; then
            if [[ ! -s "$config_file" ]]; then
                error ".patchcreator.local is empty — expected JSON with upload_url and token"
            fi
            local config
            if ! config=$(jq '.' "$config_file" 2>/dev/null); then
                error ".patchcreator.local contains invalid JSON — aborting"
            fi
            UPLOAD_URL=$(echo "$config" | jq -r '.upload_url // empty')
            TOKEN=$(echo "$config" | jq -r '.token // empty')
            if [[ -z "$UPLOAD_URL" || -z "$TOKEN" ]]; then
                error ".patchcreator.local is missing required keys: upload_url and/or token"
            fi
        fi
    fi

    # --- HTTPS guard ---
    if [[ -n "$UPLOAD_URL" && "$UPLOAD_URL" != https://* ]]; then
        error "UPLOAD_URL must use https:// — got: $UPLOAD_URL"
    fi

    # --- Return ---
    if [[ -z "$UPLOAD_URL" || -z "$TOKEN" ]]; then
        return 1
    fi
    return 0
}

# ==============================================================================
# Argument Parsing
# ==============================================================================

PROJECT_DIR="$(pwd)"
TARGET_VERSION=""
BASE_REF=""
RESOLVED_BASE_REF=""
RESOLVED_BASE_VERSION=""
BASE_VERSION=""
OUTPUT_DIR=""
RELEASE_NOTES_FILE=""
FILE_LIST=""
USER_EXCLUDES=()
USER_ALLOW_OVERRIDES=()
VERSION_PATTERN="$DEFAULT_VERSION_PATTERN"
NO_CHANGELOG=false
DRY_RUN=false
AUTO_CONFIRM=false
VALIDATE=true
UPLOAD_MODE="auto"          # auto | force | off

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
        -i)
            [[ -z "${2:-}" ]] && error "Option -i requires an allow-override pattern argument."
            USER_ALLOW_OVERRIDES+=("$2")
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
        --upload)
            UPLOAD_MODE="force"
            shift
            ;;
        --no-upload)
            UPLOAD_MODE="off"
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

# Optional: GNU tar required for reproducible archives (idempotent re-upload)
if ! tar --version 2>/dev/null | grep -q 'GNU tar'; then
    warn "Non-GNU tar detected — archive may not be byte-reproducible (idempotent re-upload may fail)"
fi

# Upload configuration — resolved early to fail before the build on bad config
if [[ "$UPLOAD_MODE" != "off" ]]; then
    if ! command -v curl &>/dev/null; then
        if [[ "$UPLOAD_MODE" == "force" ]]; then
            error "Required tool not found: curl (needed for --upload). Install with: sudo apt install curl"
        fi
        UPLOAD_MODE="off"
        warn "curl not installed — upload step will be skipped"
    fi
fi

if [[ "$UPLOAD_MODE" != "off" ]]; then
    if ! resolve_upload_config; then
        if [[ "$UPLOAD_MODE" == "force" ]]; then
            error "--upload set but no config source (env vars or .patchcreator.local) provides upload_url and token"
        fi
        UPLOAD_MODE="off"
    fi
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
    TARGET_VERSION=$(detect_version "$PROJECT_DIR" "$VERSION_PATTERN" "${DEFAULT_VERSION_FILES[@]}")

    if [[ -z "$TARGET_VERSION" ]]; then
        error "Could not auto-detect version (looked in: ${DEFAULT_VERSION_FILES[*]}). Use -v to specify it explicitly."
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
# Resolve Base Reference
# ==============================================================================

header "Resolving base reference"

if [[ -z "$BASE_REF" ]]; then
    RESOLVED_BASE_REF=""
    RESOLVED_BASE_VERSION=""

    if resolve_cumulative_base "$PROJECT_DIR" "$OUTPUT_DIR" "$TARGET_VERSION"; then
        BASE_REF="$RESOLVED_BASE_REF"
        BASE_VERSION="$RESOLVED_BASE_VERSION"
    else
        BASE_REF=$(get_latest_tag "$PROJECT_DIR")

        if [[ -z "$BASE_REF" ]]; then
            error "No git tags found and no previous patch archive in ${OUTPUT_DIR}. Use -b to specify a base reference explicitly." $EXIT_GIT_ERROR
        fi

        BASE_VERSION="${BASE_REF#v}"
        info "Using latest tag: ${BOLD}${BASE_REF}${NC} (no previous patch package found)"
    fi
else
    if [[ "$BASE_REF" =~ ^v?([0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)*)$ ]]; then
        BASE_VERSION="${BASH_REMATCH[1]}"
    fi
    info "Using specified reference: ${BOLD}${BASE_REF}${NC}"
fi

if ! git_ref_exists "$PROJECT_DIR" "$BASE_REF"; then
    error "Git reference does not exist: ${BASE_REF}" $EXIT_GIT_ERROR
fi

# Show commit range info
COMMIT_COUNT=$(git -C "$PROJECT_DIR" rev-list --count "${BASE_REF}..HEAD" 2>/dev/null || echo "0")
HEAD_COMMIT=$(git -C "$PROJECT_DIR" rev-parse HEAD)
BASE_COMMIT=$(git -C "$PROJECT_DIR" rev-parse "${BASE_REF}^{commit}" 2>/dev/null || echo "")
if [[ -n "$BASE_COMMIT" && "$BASE_COMMIT" == "$HEAD_COMMIT" ]]; then
    error "Base reference '${BASE_REF}' points to the current HEAD (${HEAD_COMMIT:0:7}); the diff would be empty. This usually means the target version (${TARGET_VERSION}) is already tagged at HEAD and no prior-version patch archive was found in ${OUTPUT_DIR}. Pass -b <prior-ref> (e.g. the previous release tag) to specify a real base." $EXIT_GIT_ERROR
fi
success "Base: ${BASE_REF} (${COMMIT_COUNT} commits since)"

# ==============================================================================
# Collect Changed Files
# ==============================================================================

header "Collecting changed files"

# Combine default and user exclude/allow-override patterns
ALL_EXCLUDES=("${DEFAULT_EXCLUDES[@]}" "${USER_EXCLUDES[@]}")
ALL_ALLOW_OVERRIDES=("${DEFAULT_ALLOW_OVERRIDES[@]}" "${USER_ALLOW_OVERRIDES[@]}")

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
            matches_exclude "$file" "${ALL_ALLOW_OVERRIDES[@]}" || continue
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
            matches_exclude "$df" "${ALL_ALLOW_OVERRIDES[@]}" || continue
        fi
        classify_file "$df" "delete"
    done

    # Warn when tracked autoload files have uncommitted working-tree changes.
    # The patch ships the committed state of these files; a regenerated but
    # uncommitted autoload map would be absent from the package.
    mapfile -t AUTOLOAD_DIRTY < <(git -C "$PROJECT_DIR" diff --name-only HEAD -- vendor/autoload.php vendor/composer/ 2>/dev/null)
    if [[ ${#AUTOLOAD_DIRTY[@]} -gt 0 ]]; then
        warn "Uncommitted autoload changes detected — patch will ship the committed versions:"
        for al_file in "${AUTOLOAD_DIRTY[@]}"; do
            warn "  ${al_file}"
        done
        warn "Run 'composer dump-autoload' and commit the result to include fresh maps."
    fi
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
    CHANGELOG_EN_PATH="${PROJECT_DIR}/CHANGELOG.md"
    CHANGELOG_HU_PATH="${PROJECT_DIR}/CHANGELOG.hu.md"

    if [[ -f "$CHANGELOG_EN_PATH" ]]; then
        CHANGELOG_EN=$(assemble_consolidated_notes "$CHANGELOG_EN_PATH" "$TARGET_VERSION" "$BASE_VERSION")

        if [[ -n "$CHANGELOG_EN" ]]; then
            if [[ -f "$CHANGELOG_HU_PATH" ]]; then
                CHANGELOG_HU=$(assemble_consolidated_notes "$CHANGELOG_HU_PATH" "$TARGET_VERSION" "$BASE_VERSION")
                if [[ -n "$CHANGELOG_HU" ]]; then
                    RELEASE_NOTES_CONTENT="$(printf '# English\n\n%s\n\n# Magyar\n\n%s' "$CHANGELOG_EN" "$CHANGELOG_HU")"
                    RELEASE_NOTES_SOURCE="CHANGELOG.md + CHANGELOG.hu.md"
                    success "Extracted dual-language release notes (EN + HU) for v${TARGET_VERSION}"
                else
                    warn "CHANGELOG.hu.md found but has no entry for v${TARGET_VERSION} — using English only."
                    RELEASE_NOTES_CONTENT="$CHANGELOG_EN"
                    RELEASE_NOTES_SOURCE="CHANGELOG.md"
                    success "Extracted release notes from CHANGELOG.md for v${TARGET_VERSION}"
                fi
            else
                RELEASE_NOTES_CONTENT="$CHANGELOG_EN"
                RELEASE_NOTES_SOURCE="CHANGELOG.md"
                success "Extracted release notes from CHANGELOG.md for v${TARGET_VERSION}"
            fi
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
    "built_from_commit": "${HEAD_COMMIT}",
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
HEAD_COMMIT_TS=$(git -C "$PROJECT_DIR" show -s --format=%ct "$HEAD_COMMIT")
tar --sort=name \
    --mtime="@${HEAD_COMMIT_TS}" \
    --owner=0 --group=0 --numeric-owner \
    --use-compress-program='gzip -n' \
    -cf "${ARCHIVE_PATH}" -C "${TEMP_DIR}" .

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

# Auto-upload to LicenseManager (opt-in)
# UPLOAD_MODE="off": no config, --no-upload, curl missing, or auto+no-config
# UPLOAD_MODE="auto"/"force": UPLOAD_URL + TOKEN guaranteed set by early validation
if [[ "$UPLOAD_MODE" != "off" ]]; then
    header "Uploading patch to LicenseManager"
    if ! upload_patch_to_licensemanager \
            "$ARCHIVE_PATH" "$SHA256" "$TARGET_VERSION" \
            "$UPLOAD_URL" "$TOKEN"; then
        print_elapsed
        exit $EXIT_UPLOAD_FAILED
    fi
fi

print_elapsed
exit $EXIT_SUCCESS