const fs = require('fs');
const path = require('path');

const glbPath = '/home/john/proyectos/NEXO/backend/alojamiento/frontend/public/assets/models/nodo.glb';

if (!fs.existsSync(glbPath)) {
  console.log('GLB not found at:', glbPath);
  process.exit(1);
}

const buffer = fs.readFileSync(glbPath);

// Read GLB Header
const magic = buffer.readUInt32LE(0);
const version = buffer.readUInt32LE(4);
const length = buffer.readUInt32LE(8);

if (magic !== 0x46546C67) { // "glTF"
  console.log('Invalid GLB file');
  process.exit(1);
}

// Read First Chunk Header
const chunkLength = buffer.readUInt32LE(12);
const chunkType = buffer.readUInt32LE(16);

if (chunkType !== 0x4E4F534A) { // "JSON"
  console.log('First chunk is not JSON');
  process.exit(1);
}

// Read JSON content
const jsonBuffer = buffer.slice(20, 20 + chunkLength);
const jsonData = JSON.parse(jsonBuffer.toString('utf-8'));

console.log('Nodes in GLB:');
if (jsonData.nodes) {
  jsonData.nodes.forEach((node, i) => {
    if (node.name) {
      console.log(`- Node ${i}: ${node.name}`);
    }
  });
}
