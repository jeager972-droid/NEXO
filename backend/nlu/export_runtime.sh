#!/bin/bash
# Copia los archivos de RUNTIME del NLU (no el corpus de entrenamiento)
# hacia backend/api/nlu_runtime/ para que la imagen de la API lo embeba.
set -e
DST="$(dirname "$0")/../api/nlu_runtime"
mkdir -p "$DST/model"
cp service.py preprocess.py domains.py math_ner.py requirements.txt "$DST/"
cp model/model.joblib "$DST/model/"
echo "Runtime exportado → $DST ($(du -sh $DST | cut -f1))"
