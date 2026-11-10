#####   Version 2.1.1 (2026-11-10)

- Fixed the first image on a page not loading with high priority near a collapsed Details or Accordion block.
- Fixed scheduled optimization stopping on sites with a low PHP memory limit or on images with an invalid file path.
- Fixed backup cleanup deleting files outside the backup folder.
- Fixed several rare crashes with backups and NextGEN Gallery.
- Updated dependencies.

####   Version 2.1.0 (2026-11-02)

- Added new, easier admin pages for Bulk Optimization, Settings, License, and Error Log.
- Added single-request optimization for the image and its WebP and AVIF conversions, for faster results.
- Added a bulk summary before start, pause, and resume, largest-first order, and an upload date filter.
- Added an Optimize with Robin bulk action to the Media Library.
- Added a Failures section that explains why each image failed, with Retry and Ignore buttons.
- Added an automatic pause and retry when the optimization server is busy.
- Added options to exclude images or folders from optimization and to keep an images original dimensions.
- Added a developer filter to skip images from optimization.
- Added a count of optimized images that still need a WebP or AVIF copy.
- Added a Test delivery button that checks that visitors get WebP and AVIF files.
- Added ready-to-copy Nginx rules and a Cloudflare warning for Redirection mode.
- Added the backup count and size to Manage backups.
- Added options to delete WebP and AVIF files on uninstall and to hide the Robin Media Library column.
- Added AVIF delivery in Image URLs mode and high-priority loading for the first image on a page.
- Added AI assistant support for everything the Robin admin pages can do.
- Added support for WordPress 7.1 in-browser image processing.
- Changed how Robin stores optimization data; existing data moves over automatically on update.
- Fixed new uploads waiting behind a running bulk optimization.
- Fixed several bulk optimization issues with large libraries, damaged images, missing files, and interrupted runs.
- Fixed background optimization stalling on sites where scheduled tasks do not run.
- Fixed edited and replaced images not being optimized again.
- Fixed WebP and AVIF files being kept when larger than the original, and GIF images being converted.
- Fixed browsers, caches, and servers getting the wrong image format, including AVIF images labeled as WebP.
- Fixed WebP delivery rules missing after a WP-CLI update or plugin reactivation.
- Fixed page caches keeping old image markup after delivery settings change.
- Fixed page crashes when another plugin loads its own HTML parser.
- Fixed several backup and restore issues that could lose original images.
- Fixed scheduled optimization stopping on hosts with low PHP limits.
- Fixed images being resized while the resize setting was off.
- Fixed reactivation turning backup and EXIF settings back on.
- Fixed leftover files and data after deleting images or uninstalling.
- Fixed several security issues.
- Fixed several multisite issues, including admins changing other sites images.
- Fixed wrong sizes and percentages in the optimization log.
- Fixed the Robin menu on phones and in right-to-left languages.
- Fixed the bulk progress dropping to 0% after WebP or AVIF was turned on.
- Fixed PHP 8.5 deprecation notices.

#####   Version 2.0.9 (2026-09-29)

- Fixed frontend page failures when Picture delivery processes converted images.
- Fixed scheduled WebP conversion crashes for image attachments with missing metadata.
- Fixed Overall Saving values for images and thumbnails.
- Fixed image restoration when saved WebP details are missing.
- Fixed extra Media Library queries for image optimization status.
- Fixed scheduled NextGEN Gallery optimization that did not process gallery images.
- Fixed scheduled license checks crashing on licensing service errors.
- Updated dependencies
- Added AI agent support: let AI assistants read and change your Robin Image Optimizer settings and optimize

#####   Version 2.0.8 (2026-09-21)

- Enhanced Security

#####   Version 2.0.7 (2026-09-07)

- Fixed license updates when WordPress has no secure authentication key.
- Fixed compression mode descriptions to identify displayed file-size reductions as average results.
- Updated dependencies

#####   Version 2.0.6 (2026-08-04)

- Fixed an issue where bulk optimization could keep showing as still running after every image had already finished processing.
- Updated dependencies
- Removed obsolete, obfuscated code that could trigger false-positive malware warnings in security scanners such as Wordfence.
- Failed WebP/AVIF conversions now record the actual error instead of a generic message, and stop repeating the same warning in the frontend error log.
- Fixed WebP/AVIF conversion on sites that use a custom uploads folder defined through the UPLOADS constant.

#####   Version 2.0.5 (2026-05-07)

- Update internal dependencies

#####   Version 2.0.4 (2026-03-12)

### Improvements

- Enhanced logging with more detailed error reporting.

### Bug fixes

- Included error messages in the dashboard logging reports.
- Resolved an issue where error handling could disrupt the processing queue.

#####   Version 2.0.3 (2026-01-23)

### Bug fixes

- Update dependencies.
- Fixed compatibility with other products.
- Fixed display for optimization log metrics.
- Enhanced security.

#####   Version 2.0.2 (2026-01-12)

### Bug fixes

- Fix license encoding for Freemius license keys with special characters.

#####   Version 2.0.1 (2026-01-12)

### Bug Fixes

- License validation for users use Freemius license keys.
- Various PHP warnings.
- Enhanced security.

####   Version 2.0.0 (2026-01-09)

### New Features

- Added AVIF image format conversion capability [PRO]
- Free tier now includes WebP format and unlimited optimizations

### Enhancements

- Updated all dependencies to latest versions
- Enabled auto-optimize by default for new installations
- Improved optimization quality and performance
- Updated optimization libraries
- Refactored admin interface with separate tabs as individual pages in the sidebar

### Bug Fixes

- Fixed critical error with export logs tool permission checking
- Fixed UI bugs including label toggle behavior, text overflow on tabs, text contrast, and padding inconsistencies
- Fixed 429 rate-limiting error
- Fixed GIF optimization failing for large files
- Fixed errors related to WebP conversion
- Fixed EXIF data being stripped during optimization
- Fixed custom folder path checking for non-standard WordPress folder structures

#### Version 1.9.0 (2025-10-20)

Robin Image Optimizer has been acquired by Themeisle 🎉
We’re happy to announce that Themeisle is now the new owner of Robin Image Optimizer. This acquisition will help ensure the plugin’s continued development, better support, and exciting new updates in the future.

Your existing setup and optimizations will continue to work as usual — no action is required on your part.
