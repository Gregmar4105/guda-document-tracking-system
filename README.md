# GUDA Document Tracking System

Automated Document Tracking System with automated deployments via GitHub Actions self-hosted runner.

## Deployment Pipeline
- **Host**: Ubuntu Server (`larable-main-server`)
- **Target Directory**: `/sites/guda-document-tracking-system`
- **Runner Label**: `guda-dts`
- **Migrations**: Automatically executes `php migrate.php` on push to `main`.
