#!/bin/bash

VERSION="v1.00.00"

# Record start time for performance tracking
START_TIME=$(date +%s)

# Default values
DO_RESTART=false
DO_OWNER=false
DO_PERMISSION=false
DO_UNUSED=false
DO_FILE=false
DO_CLEANUP=false
DRY_RUN=false
AUTO_CONFIRM=false

# Sub-section toggles
RUN_SYNC=false
RUN_MISSING=false
RUN_UNUSED=false
RUN_DUPLICATES=false

BASE_PATH=$(pwd)
PO_RELATIVE_PATH="locale/{LANG}/LC_MESSAGES/messages.po"
OWNER_CONFIG=""
OUTPUT_FILE="po_intelligence_report_$(date +%Y-%m-%d).txt"

# Filters for analysis
DOC_EXTENSIONS=" md txt log sql bak json local "
EXCLUDE_DIRS=(vendor .claude database locale storage .idea .git)
EXCLUDE_FILES=("composer.*" ".git*")

# Display complete usage instructions
usage() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "General Options:"
    echo "  -d, --dir <path>         Project base path (default: current directory)"
    echo "  -y, --yes                Auto-confirm sensitive operations"
    echo "  --dry-run                Show what would happen without making changes"
    echo ""
    echo "PO Intelligence & Localization:"
    echo "  -r, --restart            Compile PO files and restart PHP-8.4 FPM"
    echo "  -p, --po-path <path>     Relative PO path (default: locale/{LANG}/LC_MESSAGES/messages.po)"
    echo "  -u, --unused [sub...]    Analyze PO: sync, missing, unused, duplicates"
    echo "  -c, --cleanup            Comment out strictly unused keys"
    echo "  -f, --file               Save analysis report to file"
    echo ""
    echo "System Operations:"
    echo "  -o, --owner <u:g>        Set file ownership (user:group)"
    echo "  -m, --permissions        Fix file permissions (664/775)"
    echo "  -h, --help               Display this help message"
    exit 1
}

# Check if no arguments provided
if [ $# -eq 0 ]; then usage; fi

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case "$1" in
        -d|--dir)           BASE_PATH="${2%/}"; shift 2 ;;
        -r|--restart)       DO_RESTART=true; shift ;;
        -p|--po-path)       PO_RELATIVE_PATH="$2"; shift 2 ;;
        -o|--owner)         DO_OWNER=true; OWNER_CONFIG="$2"; shift 2 ;;
        -m|--permissions)   DO_PERMISSION=true; shift ;;
        -y|--yes)           AUTO_CONFIRM=true; shift ;;
        -c|--cleanup)       DO_CLEANUP=true; shift ;;
        -f|--file)          DO_FILE=true; shift ;;
        --dry-run)          DRY_RUN=true; shift ;;
        -u|--unused)        
            DO_UNUSED=true
            shift
            while [[ $# -gt 0 && ! "$1" =~ ^- ]]; do
                case "$1" in
                    sync)    RUN_SYNC=true ;;
                    missing) RUN_MISSING=true ;;
                    unused)  RUN_UNUSED=true ;;
                    duplicates) RUN_DUPLICATES=true ;;
                esac
                shift
            done
            if [[ "$RUN_SYNC" = false && "$RUN_MISSING" = false && "$RUN_UNUSED" = false && "$RUN_DUPLICATES" = false ]]; then
                RUN_SYNC=true; RUN_MISSING=true; RUN_UNUSED=true; RUN_DUPLICATES=true
            fi
            ;;
        -h|--help)          usage ;;
        *) shift ;;
    esac
done

echo "--- SECTION: INITIALIZATION ---"
echo "Base Path: $BASE_PATH"

# 1. PO Compilation & FPM Restart
if [ "$DO_RESTART" = true ]; then
    echo "--- SECTION: PO COMPILATION & FPM RESTART ---"
    LANGS=("en_US" "hu_HU")
    for LANG in "${LANGS[@]}"; do
        FINAL_PO_PATH=$(echo "$PO_RELATIVE_PATH" | sed "s/{LANG}/$LANG/g")
        FULL_PO_PATH="$BASE_PATH/$FINAL_PO_PATH"
        FULL_MO_PATH="${FULL_PO_PATH%.po}.mo"
        if [ -f "$FULL_PO_PATH" ]; then
            echo "Step: Validating and Compiling $LANG"
            if [ "$DRY_RUN" = false ]; then
                if ! msgfmt --check "$FULL_PO_PATH" -o "$FULL_MO_PATH"; then
                    echo "Error: Compilation failed for $LANG. Duplicates found:"
                    grep '^msgid "' "$FULL_PO_PATH" | sort | uniq -d
                fi
            fi
        fi
    done
    
    # Improved FPM Restart Logic with Feedback
    echo "Step: Restarting php8.4-fpm..."
    if [ "$DRY_RUN" = false ]; then
        if sudo systemctl restart php8.4-fpm; then
            echo "Status: [SUCCESS] php8.4-fpm restarted successfully."
        else
            echo "Status: [FAILED] Failed to restart php8.4-fpm."
            echo "Details: Last few lines of the error log:"
            sudo journalctl -u php8.4-fpm -n 5 --no-pager
        fi
    else
        echo "[DRY-RUN] Would restart php8.4-fpm"
    fi
fi

# 2. PO Intelligence
if [ "$DO_UNUSED" = true ]; then
    echo "--- SECTION: PO INTELLIGENCE ANALYSIS ---"
    
    FULL_HU="$BASE_PATH/$(echo "$PO_RELATIVE_PATH" | sed "s/{LANG}/hu_HU/g")"
    FULL_EN="$BASE_PATH/$(echo "$PO_RELATIVE_PATH" | sed "s/{LANG}/en_US/g")"

    declare -A PO_HU; declare -A PO_EN; declare -A PO_ALL
    while read -r k; do [ -n "$k" ] && PO_HU["$k"]=1 && PO_ALL["$k"]=1; done <<< "$(grep '^msgid "' "$FULL_HU" 2>/dev/null | cut -d'"' -f2 | grep -v '^$')"
    while read -r k; do [ -n "$k" ] && PO_EN["$k"]=1 && PO_ALL["$k"]=1; done <<< "$(grep '^msgid "' "$FULL_EN" 2>/dev/null | cut -d'"' -f2 | grep -v '^$')"

    PREFIXES=$(for k in "${!PO_ALL[@]}"; do echo "$k"; done | grep -o '^[^_]\+_' | sort -u)
    JOINED_PREFIXES=$(echo "$PREFIXES" | tr '\n' '|' | sed 's/|$//')
    REGEX="(?<![A-Z0-9_])($JOINED_PREFIXES)[A-Z0-9_]*(?![A-Z0-9_])"

    declare -A KEY_IN_CORE; declare -A DYNAMIC_PREFIXES; declare -A KEY_IN_OTHER; declare -A MISSING_KEY_EXT
    GREP_EXCLUDES=(); for d in "${EXCLUDE_DIRS[@]}"; do GREP_EXCLUDES+=(--exclude-dir="$d"); done; for f in "${EXCLUDE_FILES[@]}"; do GREP_EXCLUDES+=(--exclude="$f"); done

    while IFS=: read -r file match; do
        [ -z "$match" ] && continue
        ext="${file##*.}"
        if [[ "$ext" == "php" || "$ext" == "js" ]]; then
            if [[ "$match" =~ _$ ]]; then DYNAMIC_PREFIXES["$match"]=1; else KEY_IN_CORE["$match"]=1; fi
        else
            [[ -z "${KEY_IN_OTHER["$match"]}" ]] && KEY_IN_OTHER["$match"]="$ext"
        fi
        if [[ -z "${PO_ALL["$match"]}" ]] && [[ ! "$DOC_EXTENSIONS" =~ " $ext " ]]; then
            [[ -z "${MISSING_KEY_EXT["$match"]}" ]] && MISSING_KEY_EXT["$match"]="$ext"
        fi
    done <<< "$(grep -rPo "${GREP_EXCLUDES[@]}" "$REGEX" "$BASE_PATH" 2>/dev/null)"

    MAX_LEN=40
    for k in "${!PO_ALL[@]}"; do (( ${#k} > MAX_LEN )) && MAX_LEN=${#k}; done
    for k in "${!MISSING_KEY_EXT[@]}"; do (( ${#k} > MAX_LEN )) && MAX_LEN=${#k}; done

    REPORT_CONTENT=""
    D_SRC_COUNT=0; D_MATCH_COUNT=0; U_COUNT=0; DUP_COUNT=0

    if [ "$RUN_DUPLICATES" = true ]; then
        REPORT_CONTENT+="Sub-Section: Duplicate Definitions\n"
        for f in "$FULL_HU" "$FULL_EN"; do
            [ ! -f "$f" ] && continue
            fname=$(basename "$f")
            DUPS=$(awk '/^msgid "/ { count[$0]++; lines[$0]=lines[$0] (lines[$0]?" , ": "") NR } END { for (m in count) if (count[m]>1) print "  Duplicate | " m " | Lines: " lines[m] }' "$f")
            if [ -n "$DUPS" ]; then REPORT_CONTENT+="  File: $fname\n$DUPS\n"; ((DUP_COUNT += $(echo "$DUPS" | wc -l))); fi
        done
        REPORT_CONTENT+="\n"
    fi

    if [ "$RUN_SYNC" = true ]; then
        REPORT_CONTENT+="Sub-Section: Sync Check (HU vs EN)\n"
        for k in "${!PO_HU[@]}"; do [[ -z "${PO_EN[$k]}" ]] && REPORT_CONTENT+="  Sync    | Missing  | EN | $k\n"; done
        for k in "${!PO_EN[@]}"; do [[ -z "${PO_HU[$k]}" ]] && REPORT_CONTENT+="  Sync    | Missing  | HU | $k\n"; done
        REPORT_CONTENT+="\n"
    fi

    if [ "$RUN_MISSING" = true ]; then
        REPORT_CONTENT+="Sub-Section: Missing from PO (Check for dynamic prefixes vs actual missing keys)\n"
        IFS=$'\n' sorted_keys=($(sort <<<"$(printf "%s\n" "${!MISSING_KEY_EXT[@]}")"))
        unset IFS
        for k in "${sorted_keys[@]}"; do
            [ -z "$k" ] && continue
            note=""
            if [[ "$k" =~ _$ ]]; then note="[Dynamic Source Prefix - Used for concatenation]"; ((D_SRC_COUNT++)); fi
            REPORT_CONTENT+=$(printf "  Missing | %-12s | %-${MAX_LEN}s | %s\n" "(${MISSING_KEY_EXT[$k]})" "$k" "$note")
            REPORT_CONTENT+="\n"
        done
        REPORT_CONTENT+="\n"
    fi

    if [ "$RUN_UNUSED" = true ]; then
        UNUSED_LINES=""; DYNAMIC_LINES=""; UNUSED_LIST=()
        for k in "${!PO_ALL[@]}"; do
            IS_USED=false; IS_DYNAMIC=false
            [[ -n "${KEY_IN_CORE[$k]}" ]] && IS_USED=true
            if [ "$IS_USED" = false ]; then
                for p in "${!DYNAMIC_PREFIXES[@]}"; do if [[ "$k" == "$p"* ]]; then IS_USED=true; IS_DYNAMIC=true; break; fi; done
            fi
            if [ "$IS_USED" = false ]; then
                langs=""; line_num="N/A"
                [[ -n "${PO_HU[$k]}" ]] && langs="HU" && line_num=$(grep -n "^msgid \"$k\"" "$FULL_HU" | cut -d: -f1)
                [[ -n "${PO_EN[$k]}" ]] && langs=$( [[ -z "$langs" ]] && echo "EN" || echo "HU,EN" ) && [[ "$line_num" == "N/A" ]] && line_num=$(grep -n "^msgid \"$k\"" "$FULL_EN" | cut -d: -f1)
                other_info=$( [[ -n "${KEY_IN_OTHER["$k"]}" ]] && echo "[Used in: ${KEY_IN_OTHER["$k"]}]" || echo "" )
                UNUSED_LINES+="$(printf "%-8s | %-8s | %-${MAX_LEN}s | %s\n" "$line_num" "$langs" "$k" "$other_info")\n"
                UNUSED_LIST+=("$k"); ((U_COUNT++))
            elif [ "$IS_DYNAMIC" = true ]; then
                langs=""; [[ -n "${PO_HU[$k]}" ]] && langs="HU"; [[ -n "${PO_EN[$k]}" ]] && langs=$( [[ -z "$langs" ]] && echo "EN" || echo "HU,EN" )
                DYNAMIC_LINES+="$(printf "  Dynamic | %-8s | %-${MAX_LEN}s | Prefix match found in code\n" "$langs" "$k")\n"
                ((D_MATCH_COUNT++))
            fi
        done
        if [[ -n "$DYNAMIC_LINES" ]]; then
            REPORT_CONTENT+="Sub-Section: Dynamic Matches (Safe - These PO keys are matched by code prefixes)\n"
            REPORT_CONTENT+="$(echo -e "$DYNAMIC_LINES" | sort)\n"
        fi
        REPORT_CONTENT+="Sub-Section: Unused in Core (Strictly unused in PHP/JS files)\n"
        REPORT_CONTENT+="$(echo -e "$UNUSED_LINES" | sort -n)\n"
    fi

    SUMMARY_LINE="Summary: Found $U_COUNT unused, $D_MATCH_COUNT saved by dynamic matches, $D_SRC_COUNT dynamic prefixes, and $DUP_COUNT duplicates."
    REPORT_CONTENT+="$SUMMARY_LINE\n"
    if [ "$DO_FILE" = true ]; then echo -e "$REPORT_CONTENT" > "$OUTPUT_FILE"; echo "Result saved to $OUTPUT_FILE"; fi
    echo -e "$REPORT_CONTENT"

    if [ "$DO_CLEANUP" = true ] && [ "$DRY_RUN" = false ]; then
        for k in "${UNUSED_LIST[@]}"; do
            sed -i "s/^msgid \"$k\"/#~ msgid \"$k\"/" "$FULL_HU" "$FULL_EN"
            sed -i "/#~ msgid \"$k\"/,/msgstr/ s/^msgstr/#~ msgstr/" "$FULL_HU" "$FULL_EN"
        done
    fi
fi

# 3. Ownership & 4. Permissions
if [ "$DO_OWNER" = true ]; then
    echo "--- SECTION: OWNERSHIP ---"
    [ "$DRY_RUN" = false ] && sudo chown -R "$OWNER_CONFIG" "$BASE_PATH"
fi
if [ "$DO_PERMISSION" = true ]; then
    echo "--- SECTION: PERMISSIONS ---"
    [ "$DRY_RUN" = false ] && sudo find "$BASE_PATH" -type f ! -name "*.sh" -exec chmod 664 {} \; && sudo find "$BASE_PATH" -type f -name "*.sh" -exec chmod 775 {} \;
fi

echo -e "\n--- SECTION: STATUS ---"
echo "Execution time: $(( $(date +%s) - START_TIME )) seconds. finished."
