# SitesSaver v1.1.5 Release Notes

## What's New

### Export Destination Selector
- Choose where to save your backup: **Local Only**, **Google Drive**, or **Local + Google Drive**
- Google Drive options are automatically disabled (with a Settings link) if Drive is not connected
- GDrive-only mode removes the local copy after a successful upload

### Progress Modal with Cancel Support
- Export and import operations now show a dedicated progress modal
- **Cancel export**: Stops the process via AJAX at any step
- **Cancel import upload**: Aborts the XHR and cleans up uploaded chunks
- Restore phase clearly disables cancel with an inline explanation (DB restore cannot be safely interrupted)
- Inline confirmation panel — no browser `confirm()` dialogs

### UI Fix
- Fixed result card layout: icon is now top-aligned against multi-line text
