# Publish CodeWP Shield Monitor to WordPress.org

Plugin hosting request approved for:

- Plugin slug: `codewp-shield-monitor`
- WordPress.org user: `vithanhlam`
- SVN URL: `https://plugins.svn.wordpress.org/codewp-shield-monitor`
- Public URL: `https://wordpress.org/plugins/codewp-shield-monitor`
- Current release: `1.3.2`

## 1. Wait for SVN access

WordPress.org says SVN access can take up to 1 hour after approval.

Before publishing, set or confirm the SVN password here:

https://profiles.wordpress.org/vithanhlam/profile/edit/group/3/?screen=svn-password

Important:

- SVN username is `vithanhlam`.
- Username is case sensitive.
- Do not use the email address as the SVN username.

## 2. Prepare local SVN checkout

Create a clean SVN working copy outside the plugin development folder.

```powershell
cd F:\CodeWP_Projects\proj_1776555528073\www\wp-content\plugins
svn checkout https://plugins.svn.wordpress.org/codewp-shield-monitor codewp-shield-monitor-svn --username vithanhlam
cd codewp-shield-monitor-svn
```

Expected SVN folders:

```text
assets/
branches/
tags/
trunk/
```

## 3. Copy plugin code into trunk

Use the prepared release folder as the source:

```text
F:\CodeWP_Projects\proj_1776555528073\www\wp-content\plugins\shieldpress\release\codewp-shield-monitor
```

Copy the contents of that folder into:

```text
F:\CodeWP_Projects\proj_1776555528073\www\wp-content\plugins\codewp-shield-monitor-svn\trunk
```

Do not copy the parent folder itself. `trunk` should contain files such as:

```text
trunk/readme.txt
trunk/shield-monitor.php
trunk/includes/
trunk/assets/
trunk/languages/
```

Recommended PowerShell copy:

```powershell
$source = "F:\CodeWP_Projects\proj_1776555528073\www\wp-content\plugins\shieldpress\release\codewp-shield-monitor"
$trunk = "F:\CodeWP_Projects\proj_1776555528073\www\wp-content\plugins\codewp-shield-monitor-svn\trunk"
Copy-Item -Path "$source\*" -Destination $trunk -Recurse -Force
```

## 4. Keep these files out of SVN

Do not commit development-only folders or files:

- `.git`
- `.github`
- `.agents`
- `.codex`
- `node_modules`
- `docs`
- `release`
- `wordpress-org-assets`
- local zip files
- temporary test files

The current release folder is already prepared without these internal files.

## 5. Add WordPress.org plugin assets

Plugin directory assets go in SVN `assets/`, not in `trunk/assets/`.

Optional asset filenames:

```text
assets/banner-772x250.png
assets/banner-1544x500.png
assets/icon-128x128.png
assets/icon-256x256.png
assets/screenshot-1.png
```

The plugin's runtime CSS/JS files stay in `trunk/assets/`.

## 6. Check version consistency

Before commit, confirm these match:

- `trunk/shield-monitor.php` header `Version: 1.3.2`
- `trunk/readme.txt` `Stable tag: 1.3.2`
- `trunk/readme.txt` changelog has `= 1.3.2 =`

Also confirm:

- Text domain is `codewp-shield-monitor`.
- Main plugin folder slug is `codewp-shield-monitor`.
- External services are documented in `readme.txt`.
- No development-only files are staged.

## 7. Validate readme

Use the official validator:

https://wordpress.org/plugins/developers/readme-validator/

Paste `trunk/readme.txt` and fix any warnings before commit.

## 8. Stage SVN changes

From the SVN checkout root:

```powershell
cd F:\CodeWP_Projects\proj_1776555528073\www\wp-content\plugins\codewp-shield-monitor-svn
svn status
svn add --force trunk
svn add --force assets
svn status
```

If `svn status` shows unwanted files, remove them before commit:

```powershell
svn revert path\to\unwanted-file
```

## 9. Commit trunk

```powershell
svn commit -m "Initial import of CodeWP Shield Monitor 1.3.2" --username vithanhlam
```

SVN will ask for the SVN password from WordPress.org.

## 10. Create version tag

After trunk commit succeeds, copy trunk to the release tag:

```powershell
svn copy trunk tags/1.3.2
svn commit -m "Tag CodeWP Shield Monitor 1.3.2" --username vithanhlam
```

Alternative one-step remote copy:

```powershell
svn copy https://plugins.svn.wordpress.org/codewp-shield-monitor/trunk https://plugins.svn.wordpress.org/codewp-shield-monitor/tags/1.3.2 -m "Tag CodeWP Shield Monitor 1.3.2" --username vithanhlam
```

## 11. Verify public page

Public page:

https://wordpress.org/plugins/codewp-shield-monitor

Notes:

- The plugin page may take time to update.
- Search results can take up to 72 hours to fully update.
- If the page shows the wrong version, check `Stable tag`, `tags/1.3.2`, and SVN commit status.

## 12. Useful commands

Check status:

```powershell
svn status
```

Update local SVN copy:

```powershell
svn update
```

See recent commits:

```powershell
svn log -l 5
```

Show changed files:

```powershell
svn diff
```

## 13. Reply email template

After publishing, reply to the approval email:

```text
Hi WordPress Plugins Team,

Thank you. I have committed CodeWP Shield Monitor 1.3.2 to SVN and tagged the release as 1.3.2.

SVN URL:
https://plugins.svn.wordpress.org/codewp-shield-monitor

Public URL:
https://wordpress.org/plugins/codewp-shield-monitor

Best regards,
vithanhlam
```

## References

- Using Subversion with the WordPress Plugin Directory: https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/
- Generating your SVN password: https://make.wordpress.org/meta/handbook/tutorials-guides/svn-access/
- Plugin Developer FAQ: https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
- readme.txt standard: https://wordpress.org/plugins/developers/#readme
- Readme validator: https://wordpress.org/plugins/developers/readme-validator/
- Plugin assets: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
- Plugin guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
