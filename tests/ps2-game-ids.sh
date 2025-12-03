#!/bin/bash

# Script to extract PS2 game ID from ISO files and downloads cover art
# Usage: ./get-game-id.sh <directory>

if [ $# -eq 0 ]; then
    echo "Usage: $0 <directory>"
    echo "Example: $0 /games/ps2"
    exit 1
fi

SCAN_DIR="$1"
ART_DIR="${SCAN_DIR}/ART"

if [ ! -d "$SCAN_DIR" ]; then
    echo "Error: Directory '$SCAN_DIR' not found!"
    exit 1
fi

# Create ART directory if it doesn't exist
mkdir -p "$ART_DIR"

# Function to download cover art for a game ID
download_cover_art() {
    local GAME_ID="$1"
    
    # Convert GAME_ID from SLUS-12345 to SLUS_12345 for filename (OPL format)
    local GAME_ID_UNDERSCORE=$(echo "$GAME_ID" | sed 's/-/_/')
    
    # Skip if cover already exists
    if [ -f "${ART_DIR}/${GAME_ID_UNDERSCORE}_COV.jpg" ]; then
        echo "→ Cover art already exists, skipping download"
        return 0
    fi
    
    echo "→ Downloading cover art..."
    
    # GitHub repository uses dash format (SLUS-12345)
    COVER_URL="https://raw.githubusercontent.com/xlenore/ps2-covers/main/covers/default/${GAME_ID}.jpg"
    
    # Try to download the cover and save with underscore format
    if curl -f -s -L -o "${ART_DIR}/${GAME_ID_UNDERSCORE}_COV.jpg" "$COVER_URL" 2>/dev/null && [ -s "${ART_DIR}/${GAME_ID_UNDERSCORE}_COV.jpg" ]; then
        echo "✓ Cover art downloaded successfully as ${GAME_ID_UNDERSCORE}_COV.jpg"
        return 0
    else
        echo "⚠ Cover art not found in repository"
        rm -f "${ART_DIR}/${GAME_ID_UNDERSCORE}_COV.jpg"
        return 1
    fi
}

# Function to extract game ID from a single ISO
extract_game_id() {
    local ISO_FILE="$1"
    
    echo ""
    echo "=========================================="
    echo "Analyzing: $(basename "$ISO_FILE")"
    echo "=========================================="
    
    # Method 1: Check SYSTEM.CNF for BOOT2 line
    GAME_ID=$(strings "$ISO_FILE" | grep -i "BOOT2" | head -1 | sed 's/.*cdrom0:\\//; s/;.*//' | tr -d '\r\n')
    
    if [ -n "$GAME_ID" ]; then
        # Extract just the ID part (e.g., SLUS_123.45 from SLUS_123.45.ELF)
        CLEAN_ID=$(echo "$GAME_ID" | sed 's/[_;]/-/g; s/\.ELF//i; s/\.//g')
        echo "✓ Game ID found: $CLEAN_ID"
        
        # Get original extension and basename
        CURRENT_NAME=$(basename "$ISO_FILE")
        
        # Check if filename already starts with the game ID
        if [[ "$CURRENT_NAME" == "${CLEAN_ID}."* ]]; then
            echo "✓ File already has game ID in name: $CURRENT_NAME"
            # Try to download cover art
            download_cover_art "$CLEAN_ID"
        else
            EXT="${CURRENT_NAME##*.}"
            BASENAME="${CURRENT_NAME%.*}"
            SUGGESTED_NAME="${CLEAN_ID}.${BASENAME}.${EXT}"
            echo "→ Current: $CURRENT_NAME"
            echo "→ Renaming to: $SUGGESTED_NAME"
            
            DIR_PATH=$(dirname "$ISO_FILE")
            NEW_PATH="${DIR_PATH}/${SUGGESTED_NAME}"
            
            if mv "$ISO_FILE" "$NEW_PATH"; then
                echo "✓ Successfully renamed!"
                # Try to download cover art
                download_cover_art "$CLEAN_ID"
            else
                echo "✗ Failed to rename file"
            fi
        fi
    else
        echo "⚠ Could not find game ID in SYSTEM.CNF"
        echo "→ Trying alternative method..."
        
        # Method 2: Search for common region codes
        REGION_ID=$(strings "$ISO_FILE" | grep -E "^(SLUS|SLES|SLPS|SLPM|SCUS|SCES|SCPS|SCPM)[-_][0-9]{3}\.?[0-9]{2}" | head -1 | tr -d '\r\n')
        
        if [ -n "$REGION_ID" ]; then
            CLEAN_ID=$(echo "$REGION_ID" | sed 's/[_;]/-/g; s/\.//g')
            echo "✓ Game ID found: $CLEAN_ID"
            
            # Get original extension and basename
            CURRENT_NAME=$(basename "$ISO_FILE")
            
            # Check if filename already starts with the game ID
            if [[ "$CURRENT_NAME" == "${CLEAN_ID}."* ]]; then
                echo "✓ File already has game ID in name: $CURRENT_NAME"
                # Try to download cover art
                download_cover_art "$CLEAN_ID"
            else
                EXT="${CURRENT_NAME##*.}"
                BASENAME="${CURRENT_NAME%.*}"
                SUGGESTED_NAME="${CLEAN_ID}.${BASENAME}.${EXT}"
                echo "→ Current: $CURRENT_NAME"
                echo "→ Renaming to: $SUGGESTED_NAME"
                
                DIR_PATH=$(dirname "$ISO_FILE")
                NEW_PATH="${DIR_PATH}/${SUGGESTED_NAME}"
                
                if mv "$ISO_FILE" "$NEW_PATH"; then
                    echo "✓ Successfully renamed!"
                    # Try to download cover art
                    download_cover_art "$CLEAN_ID"
                else
                    echo "✗ Failed to rename file"
                fi
            fi
        else
            echo "✗ Unable to determine game ID"
            echo "→ You may need to look it up manually"
        fi
    fi
}

# Find and process all ISO files
echo "Scanning directory: $SCAN_DIR"
echo "Looking for ISO files (including subdirectories)..."

ISO_COUNT=0
while IFS= read -r -d '' iso_file; do
    extract_game_id "$iso_file"
    ((ISO_COUNT++))
done < <(find "$SCAN_DIR" -type f -iname "*.iso" -print0)

if [ $ISO_COUNT -eq 0 ]; then
    echo ""
    echo "⚠ No ISO files found in $SCAN_DIR"
    echo "→ Make sure you have copied your game files to this directory"
    echo "→ For PS2/OPL, files should be in DVD/ or CD/ subdirectories"
fi

echo ""
echo "=========================================="
echo "Scan complete. Found $ISO_COUNT ISO file(s)"
echo "=========================================="
