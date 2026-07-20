#!/bin/bash
# NEXO Database Deployment Script
# Ejecuta el schema final y el seed data contra Supabase

set -e

DB_URL="postgresql://postgres:nexus@db.owlzoztdcqwyxkdmqovj.supabase.co:5432/postgres"

echo "=========================================="
echo "NEXO Database Deployment"
echo "=========================================="
echo "Target: Supabase"
echo "=========================================="

echo ""
echo "Deploying all SQL files in order..."
for file in backend/api/sql/*.sql; do
    echo "Executing: $file"
    psql "$DB_URL" -f "$file"
done

echo ""
echo "=========================================="
echo "Deployment completed successfully!"
echo "=========================================="
