#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

// Configuration
const MAIN_PLUGIN_FILE = 'the-another-seo.php';
const VERSION_CONSTANT_NAME = 'THE_ANOTHER_SEO_VERSION';

// Get version type argument (patch, minor, major)
const versionType = process.argv[2];
const validTypes = ['patch', 'minor', 'major'];

// Load package.json
const packageJsonPath = path.join(__dirname, '../package.json');
const packageJson = require(packageJsonPath);
const previousVersion = packageJson.version;
let newVersion = packageJson.version;

// If version type is provided, increment the version
if (versionType && validTypes.includes(versionType)) {
  const [major, minor, patch] = newVersion.split('.').map(Number);

  switch (versionType) {
    case 'major':
      newVersion = `${major + 1}.0.0`;
      break;
    case 'minor':
      newVersion = `${major}.${minor + 1}.0`;
      break;
    case 'patch':
      newVersion = `${major}.${minor}.${patch + 1}`;
      break;
  }

  // Update package.json with new version
  packageJson.version = newVersion;
  fs.writeFileSync(packageJsonPath, JSON.stringify(packageJson, null, 2) + '\n', 'utf8');
  console.log(`✓ Bumped version to ${newVersion} (${versionType})`);
} else if (versionType) {
  console.error(`Invalid version type: ${versionType}. Use: patch, minor, or major`);
  process.exit(1);
}

console.log(`Updating version to ${newVersion}...`);

// Update composer.json if it exists
const composerJsonPath = path.join(__dirname, '../composer.json');
if (fs.existsSync(composerJsonPath)) {
  const composerJson = JSON.parse(fs.readFileSync(composerJsonPath, 'utf8'));
  composerJson.version = newVersion;
  fs.writeFileSync(composerJsonPath, JSON.stringify(composerJson, null, '\t') + '\n', 'utf8');
  console.log('✓ Updated composer.json');
}

// Update main plugin file
const pluginFile = path.join(__dirname, '..', MAIN_PLUGIN_FILE);
let pluginContent = fs.readFileSync(pluginFile, 'utf8');

// Update Version in header comment
pluginContent = pluginContent.replace(
  /(\* Version:\s+)[\d.]+/,
  `$1${newVersion}`
);

// Update version constant
pluginContent = pluginContent.replace(
  new RegExp(`(define\\(\\s*'${VERSION_CONSTANT_NAME}',\\s*')[\\d.]+('\\s*\\);)`),
  `$1${newVersion}$2`
);

fs.writeFileSync(pluginFile, pluginContent, 'utf8');
console.log(`✓ Updated ${MAIN_PLUGIN_FILE}`);

// Update readme.txt
const readmeFile = path.join(__dirname, '../readme.txt');
let readmeContent = fs.readFileSync(readmeFile, 'utf8');

// Update Stable tag
readmeContent = readmeContent.replace(
  /(Stable tag:\s+)[\d.]+/,
  `$1${newVersion}`
);

// Add changelog entry
const today = new Date().toISOString().split('T')[0];
const changelogEntry = `= ${newVersion} - ${today} =\n* Version bump\n\n`;

// Find the changelog section and add the new entry
readmeContent = readmeContent.replace(
  /(== Changelog ==\s*\n)/,
  `$1\n${changelogEntry}`
);

fs.writeFileSync(readmeFile, readmeContent, 'utf8');
console.log('✓ Updated readme.txt');

// Update CHANGELOG.md — promote the "[Unreleased]" section into a dated
// release entry and open a fresh, empty "[Unreleased]" above it (Keep a
// Changelog convention), then retarget the comparison links at the bottom.
// Only runs on a real version change, not a same-version re-sync, so
// whatever notes contributors accumulated under [Unreleased] become the
// new release's notes.
const changelogFile = path.join(__dirname, '../CHANGELOG.md');
if (fs.existsSync(changelogFile) && newVersion !== previousVersion) {
  let changelogContent = fs.readFileSync(changelogFile, 'utf8');
  const repo = 'https://github.com/theanother/the-another-seo';

  if (/## \[Unreleased\]/.test(changelogContent)) {
    // Rename the current [Unreleased] heading to a dated release and insert
    // a fresh, empty [Unreleased] above it.
    changelogContent = changelogContent.replace(
      /## \[Unreleased\]/,
      `## [Unreleased]\n\n## [${newVersion}] - ${today}`
    );

    // Retarget the [Unreleased] compare link at the new tag, and add a
    // compare link for the freshly promoted version.
    changelogContent = changelogContent.replace(
      /\[Unreleased\]:\s*\S+/,
      `[Unreleased]: ${repo}/compare/v${newVersion}...HEAD\n` +
        `[${newVersion}]: ${repo}/compare/v${previousVersion}...v${newVersion}`
    );

    fs.writeFileSync(changelogFile, changelogContent, 'utf8');
    console.log('✓ Updated CHANGELOG.md');
  } else {
    console.warn('⚠ CHANGELOG.md has no "## [Unreleased]" section — skipped');
  }
}

// Sync lock files with updated versions
const rootDir = path.join(__dirname, '..');

console.log('\nSyncing lock files...');

try {
  execSync('npm install --package-lock-only', { cwd: rootDir, stdio: 'inherit' });
  console.log('✓ Updated package-lock.json');
} catch {
  console.warn('⚠ Failed to update package-lock.json');
}

try {
  execSync('composer update --lock', { cwd: rootDir, stdio: 'inherit' });
  console.log('✓ Updated composer.lock');
} catch {
  console.warn('⚠ Failed to update composer.lock');
}

console.log(`\nVersion ${newVersion} update complete!`);
