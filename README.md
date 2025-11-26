# PrestaShop Image Cleanup Tool

A simple, single-file tool to find and remove orphaned images from your PrestaShop store.

Over time, PrestaShop stores accumulate unused images from deleted products, old CMS content, and module changes. This tool helps you identify and safely remove them to reclaim disk space.

## Features

- **Single PHP file** - just upload and run, no installation required
- **Dynamic scanning** - automatically detects your installed modules and database tables
- **Safe by default** - scans thoroughly before suggesting any deletions
- **Product image validation** - checks images against your actual product database
- **Entity image scanning** - validates category, manufacturer, supplier, and store images
- **Temp file cleanup** - easily clear out `/img/tmp`
- **Export reports** - download results as JSON or CSV

## Requirements

- PrestaShop 1.7+ or 8.x
- PHP 7.4+
- Access to upload files to your PrestaShop root directory

## Usage

1. **Download** `ps_image_cleanup.php`
2. **Edit line 59** - change the password from `simontodd` to something secure
3. **Upload** to your PrestaShop root directory (where `index.php` lives)
4. **Access** via browser: `https://yoursite.com/ps_image_cleanup.php`
5. **Login** with your password
6. **Accept** the disclaimer
7. **Run a scan** and review the results
8. **Delete the file** when you're done (important for security!)

## What It Scans

**Database tables** - dynamically discovers all tables with content that might reference images

**Theme files** - scans `.tpl`, `.html`, `.css`, `.js`, and other template files

**Module templates** - checks installed module view files for image references

**Image folders:**
- `img/p` - product images (validated against database)
- `img/c` - category images
- `img/m` - manufacturer images
- `img/su` - supplier images
- `img/st` - store images
- `img/cms` - CMS content images
- `img/tmp` - temporary files
- `upload` - general uploads

## Safety

This tool is deliberately cautious. It cross-references images against:
- All database content fields
- Theme template files
- Module template files
- Product, category, manufacturer, supplier, and store database records

An image is only marked as orphaned if it's not found in any of these locations.

**Always back up your files and database before deleting anything.**

## Screenshots

The tool has a clean, modern interface with:
- Dashboard showing disk usage statistics
- Real-time scan progress with detailed logging
- Organised orphan listings by folder
- One-click temp file cleanup

## Disclaimer

This tool is provided as-is. I've tested it extensively on my own sites, but every PrestaShop installation is different. Always back up before running cleanup operations. I accept no responsibility for any data loss.

## License

MIT License - use it however you like.

## About

Built by [Simon Todd](https://simontodd.dev) for my own PrestaShop projects, shared in case it's useful to others.

Questions or issues? Open an issue here or email hello@simontodd.dev
