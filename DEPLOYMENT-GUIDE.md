# Deployment Guide

## Git Setup Complete ✓

The package has been initialized with git and is ready to push.

### Current Status

```
✓ Git repository initialized
✓ Initial commit created
✓ Version v1.0.0 tagged
✓ 39 files tracked
✓ .gitignore configured
✓ .gitattributes configured
```

---

## Push to GitHub

### Option 1: Create New GitHub Repository (Recommended)

#### Step 1: Create Repository on GitHub

1. Go to https://github.com/new
2. Repository name: `laravel-license`
3. Description: `A comprehensive Laravel package for license management and feature gating`
4. Visibility: **Public** (for open source) or **Private** (for internal use)
5. **DO NOT** initialize with README, .gitignore, or license (we already have these)
6. Click "Create repository"

#### Step 2: Add Remote and Push

```bash
cd /home/kwamina/Desktop/westel/packages/westel/laravel-license

# Add remote (replace YOUR_USERNAME with your GitHub username)
git remote add origin https://github.com/YOUR_USERNAME/laravel-license.git

# Or use SSH (if you have SSH keys set up)
# git remote add origin git@github.com:YOUR_USERNAME/laravel-license.git

# Push to GitHub
git push -u origin main

# Push tags
git push --tags
```

#### Step 3: Verify on GitHub

Visit `https://github.com/YOUR_USERNAME/laravel-license` to see your package!

---

### Option 2: Push to Existing Organization

If you have a GitHub organization (e.g., `westel`):

```bash
cd /home/kwamina/Desktop/westel/packages/westel/laravel-license

# Add remote
git remote add origin https://github.com/westel/laravel-license.git

# Or with SSH
# git remote add origin git@github.com:westel/laravel-license.git

# Push
git push -u origin main
git push --tags
```

---

## Publish to Packagist (Optional)

To make your package installable via `composer require westel/laravel-license`:

### Step 1: Create Packagist Account

1. Go to https://packagist.org/
2. Sign up or log in
3. Connect your GitHub account

### Step 2: Submit Package

1. Click "Submit" in Packagist
2. Enter your GitHub repository URL: `https://github.com/YOUR_USERNAME/laravel-license`
3. Click "Check"
4. Click "Submit"

### Step 3: Set Up Auto-Update

1. In Packagist, go to your package settings
2. Copy the webhook URL
3. Go to your GitHub repository settings
4. Add webhook with Packagist URL
5. Now Packagist auto-updates on new releases

### Step 4: Install in Projects

```bash
composer require westel/laravel-license
```

---

## Private Package Distribution

If you want to keep the package private but use it across projects:

### Option 1: GitHub Private Repository

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/YOUR_USERNAME/laravel-license.git"
        }
    ],
    "require": {
        "westel/laravel-license": "^1.0"
    }
}
```

Then authenticate:

```bash
composer config --global github-oauth.github.com YOUR_GITHUB_TOKEN
```

### Option 2: Private Packagist

Use https://packagist.com (paid service) for private packages with better features.

### Option 3: Local Path (Current Setup)

Already configured in your workspace:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../packages/westel/laravel-license"
        }
    ],
    "require": {
        "westel/laravel-license": "*"
    }
}
```

---

## Release Management

### Creating New Releases

When you make updates:

```bash
cd /home/kwamina/Desktop/westel/packages/westel/laravel-license

# Make changes
git add .
git commit -m "Add new feature: X"

# Update CHANGELOG.md with changes

# Create new version tag
git tag -a v1.1.0 -m "Version 1.1.0 - Added feature X"

# Push changes and tags
git push origin main
git push --tags
```

### Semantic Versioning

Follow semver (https://semver.org/):

- **v1.0.0** → **v1.0.1** - Bug fixes (patch)
- **v1.0.0** → **v1.1.0** - New features (minor)
- **v1.0.0** → **v2.0.0** - Breaking changes (major)

---

## Integration into Projects

### westel-admin (Server Mode)

```bash
cd /home/kwamina/Desktop/westel/westel-admin

# Add to composer.json
composer config repositories.laravel-license path ../packages/westel/laravel-license

# Install
composer require westel/laravel-license:*

# Run installation
php artisan license:install
# Choose: Server Mode
```

### westel-pos (Client Mode)

```bash
cd /home/kwamina/Desktop/westel/westel-pos

# Add to composer.json
composer config repositories.laravel-license path ../packages/westel/laravel-license

# Install
composer require westel/laravel-license:*

# Run installation
php artisan license:install
# Choose: Client Mode
```

### exp (Client Mode)

```bash
cd /home/kwamina/Desktop/westel/exp

# Add to composer.json
composer config repositories.laravel-license path ../packages/westel/laravel-license

# Install
composer require westel/laravel-license:*

# Run installation
php artisan license:install
# Choose: Client Mode
```

---

## CI/CD Setup (Optional)

### GitHub Actions for Testing

Create `.github/workflows/tests.yml`:

```yaml
name: Tests

on:
  push:
    branches: [ main ]
  pull_request:
    branches: [ main ]

jobs:
  test:
    runs-on: ubuntu-latest

    strategy:
      matrix:
        php: [8.1, 8.2, 8.3]
        laravel: [10.*, 11.*, 12.*]

    steps:
    - uses: actions/checkout@v3

    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: ${{ matrix.php }}

    - name: Install dependencies
      run: composer install --prefer-dist --no-progress

    - name: Run tests
      run: composer test
```

---

## Documentation Hosting

### Option 1: GitHub Pages

Host your documentation on GitHub Pages:

```bash
# Create docs branch
git checkout -b gh-pages
git push origin gh-pages

# Enable GitHub Pages in repository settings
```

### Option 2: ReadTheDocs

1. Connect your GitHub repository to https://readthedocs.org/
2. Auto-builds documentation on every commit

---

## Quick Commands Reference

```bash
# Check git status
git status

# View commit history
git log --oneline --graph

# View tags
git tag -l

# Create and push new tag
git tag -a v1.0.1 -m "Bug fixes"
git push --tags

# View remote
git remote -v

# Add remote
git remote add origin https://github.com/YOUR_USERNAME/laravel-license.git

# Push everything
git push -u origin main --tags

# Clone package elsewhere
git clone https://github.com/YOUR_USERNAME/laravel-license.git
```

---

## Troubleshooting

### Authentication Issues

If push fails with authentication error:

```bash
# Use GitHub CLI (recommended)
gh auth login

# Or create personal access token
# Go to GitHub → Settings → Developer settings → Personal access tokens
# Create token with 'repo' scope
# Use token as password when pushing
```

### Large Files Warning

If git complains about large files, they're already in .gitignore.

### Branch Name

If GitHub expects `main` but you have `master`:

```bash
git branch -M main
git push -u origin main
```

---

## Next Steps

1. ✅ Git initialized and committed
2. ⏳ Create GitHub repository
3. ⏳ Push to GitHub
4. ⏳ (Optional) Publish to Packagist
5. ⏳ Integrate into westel-admin
6. ⏳ Integrate into westel-pos
7. ⏳ Integrate into exp
8. ⏳ Generate licenses for products
9. ⏳ Deploy and test

Your package is ready to share with the world! 🚀
