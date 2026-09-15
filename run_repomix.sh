#!/bin/bash

# Configuration file check
if [ ! -f repomix.config.json ]; then
  echo "Error: repomix.config.json not found."
  exit 1
fi

echo "=========================================================="
echo "📦 Running Repomix to pack your codebase into nexo_repomix.xml..."
echo "=========================================================="
npx repomix

echo ""
echo "📊 Counting total lines of code and documentation (excluding libraries & builds)..."
TOTAL_LINES=$(find . -type f \( \
  -name "*.js" -o -name "*.jsx" -o -name "*.ts" -o -name "*.tsx" -o \
  -name "*.cjs" -o -name "*.mjs" -o -name "*.php" -o -name "*.sql" -o \
  -name "*.css" -o -name "*.html" -o -name "*.sh" -o -name "*.cpp" -o \
  -name "*.h" -o -name "*.hpp" -o -name "*.cc" -o -name "*.c" -o \
  -name "*.md" -o -name "*.txt" \
\) \
  -not -path "*/node_modules/*" \
  -not -path "*/vendor/*" \
  -not -path "*/dist/*" \
  -not -path "*/build/*" \
  -not -path "*/sensorvendor/*" \
  -not -path "*/.git/*" \
  -not -path "*/.github/*" \
  -print0 | xargs -0 wc -l | tail -n 1 | awk '{print $1}')

echo "--------------------------------------------------------"
echo "✅ Total lines of user-owned code + documentation: $TOTAL_LINES"
echo "💾 Packed output saved as: nexo_repomix.xml"
echo "--------------------------------------------------------"
