#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

// Load package.json to get current version and package name
const packageJsonPath = path.join(__dirname, '../package.json');
const packageJson = require(packageJsonPath);
const packageName = packageJson.name;

// --label=<value> overrides the version number in the output filename and
// skips the "latest" alias — used by `plugin-zip:check` to produce a
// throwaway build/{name}-test.zip for the Plugin Check pipeline without
// touching the real version or the release "latest" pointer.
const labelArg = process.argv.find((arg) => arg.startsWith('--label='));
const label = labelArg ? labelArg.slice('--label='.length) : packageJson.version;
const isLabelOverride = Boolean(labelArg);

// Define paths
const rootDir = path.join(__dirname, '/../');
const buildDir = path.join(rootDir, 'build');
const sourceZip = path.join(rootDir, `${packageName}.zip`);
const labeledZip = path.join(buildDir, `${packageName}-${label}.zip`);
const latestZip = path.join(buildDir, `${packageName}.zip`);

// Check if source zip exists
if (!fs.existsSync(sourceZip)) {
  console.error(`Error: ${sourceZip} not found`);
  process.exit(1);
}

// Create build directory if it doesn't exist
if (!fs.existsSync(buildDir)) {
  fs.mkdirSync(buildDir, { recursive: true });
  console.log(`✓ Created build directory`);
}

// Copy to labeled zip in build directory
fs.copyFileSync(sourceZip, labeledZip);
console.log(`✓ Created ${path.basename(labeledZip)} in build directory`);

if (!isLabelOverride) {
  // Copy to latest zip in build directory (overwrite if exists) — only for
  // real release builds; a --label override is a throwaway artifact and
  // must never touch the release "latest" pointer.
  fs.copyFileSync(sourceZip, latestZip);
  console.log(`✓ Updated ${path.basename(latestZip)} in build directory (latest version)`);
}

// Remove source zip from root
fs.unlinkSync(sourceZip);
console.log(`✓ Removed temporary ${path.basename(sourceZip)} from root`);
