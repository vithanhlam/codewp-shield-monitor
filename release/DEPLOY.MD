# Deploy CodeWP Shield Monitor to WordPress.org

## Thông tin SVN

- **SVN URL:** `https://plugins.svn.wordpress.org/codewp-shield-monitor`
- **Local working copy:** `release/wordpress-org-svn/`
- **SVN Username:** WordPress.org username (vithanhlam)
- **SVN Password:** dùng SVN-specific password tạo tại https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password

## Cấu trúc thư mục SVN

```
release/wordpress-org-svn/
├── assets/          ← Banner, icon, screenshots (hiện trên wordpress.org)
├── branches/        ← Không dùng
├── tags/            ← Mỗi version release một thư mục (1.3.2, 1.4.2...)
├── trunk/           ← Code mới nhất, được copy từ source
└── .svn/            ← SVN metadata
```

## Quy trình deploy

### Bước 1: Đảm bảo trunk đã cập nhật

Trunk phải chứa code mới nhất với version đã bump. Kiểm tra:

```bash
cd release/wordpress-org-svn
grep "Version:" trunk/shield-monitor.php
grep "Stable tag:" trunk/readme.txt
grep "SHIELD_MONITOR_VERSION" trunk/shield-monitor.php
```

Ba giá trị version phải **khớp nhau**. Nếu chưa, copy lại từ source:

```bash
# Từ thư mục gốc plugin
cp shield-monitor.php release/wordpress-org-svn/trunk/
cp readme.txt release/wordpress-org-svn/trunk/
cp CHANGELOG.MD release/wordpress-org-svn/trunk/
cp uninstall.php release/wordpress-org-svn/trunk/
cp index.php release/wordpress-org-svn/trunk/
cp -r includes/* release/wordpress-org-svn/trunk/includes/
cp -r assets/js/* release/wordpress-org-svn/trunk/assets/js/
cp -r assets/css/* release/wordpress-org-svn/trunk/assets/css/
cp -r addons/* release/wordpress-org-svn/trunk/addons/
```

**KHÔNG copy** vào trunk: `node_modules/`, `assets/src/`, `package.json`, `package-lock.json`, `.git/`, `release/`.

### Bước 2: Kiểm tra thay đổi

```bash
cd release/wordpress-org-svn
svn status
```

- `M` = modified (file đã thay đổi)
- `?` = untracked (file mới chưa add)
- `!` = missing (file đã xóa trên disk nhưng chưa svn delete)

Nếu có file mới (`?`):
```bash
svn add trunk/includes/new-file.php
```

Nếu có file bị xóa (`!`):
```bash
svn delete trunk/includes/old-file.php
```

Hoặc add/delete tất cả cùng lúc:
```bash
# Add tất cả file mới
svn add --force trunk/

# Delete tất cả file missing
svn status | grep "^!" | awk '{print $2}' | xargs svn delete
```

### Bước 3: Tạo tag cho version mới

```bash
svn cp trunk tags/1.4.2
```

### Bước 4: Review trước khi commit

```bash
# Xem tổng hợp thay đổi
svn status

# Xem diff chi tiết (nếu cần)
svn diff trunk/shield-monitor.php
```

### Bước 5: Commit lên WordPress.org

```bash
svn commit -m "Release 1.4.2 — wp2shell virtual patches (CVE-2026-63030, CVE-2026-60137), REST API management panel, core endpoint controls"
```

SVN sẽ hỏi username và password:
- **Username:** `vithanhlam`
- **Password:** SVN-specific password từ WordPress.org (không phải mật khẩu đăng nhập hoặc Application Password)

### Bước 6: Xác nhận

Sau khi commit thành công, kiểm tra:
- https://wordpress.org/plugins/codewp-shield-monitor/ — version mới hiện trong "Changelog"
- https://wordpress.org/plugins/codewp-shield-monitor/advanced/ — "Stable tag" hiện version mới
- Chờ 5-15 phút để WordPress.org build zip mới

## Cập nhật banner/icon/screenshots

Các file trong `assets/` (KHÔNG phải `trunk/assets/`) hiện trên trang WordPress.org:

```
assets/banner-772x250.png     ← Banner trên trang plugin
assets/icon-128x128.png       ← Icon nhỏ
assets/icon-256x256.png       ← Icon lớn
assets/screenshot-1.png       ← Screenshots (match với readme.txt)
```

Khi thay đổi, add và commit:
```bash
svn add assets/banner-772x250.png  # nếu file mới
svn commit -m "Update plugin banner"
```

## Tạo SVN-specific password

1. Đăng nhập https://profiles.wordpress.org/
2. Mở https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password
3. Tạo hoặc đặt SVN-specific password và dùng với username `vithanhlam`.
4. Không ghi mật khẩu vào repository, log hoặc đối số shell. Có thể dùng kho credential của SVN/keyring.

## Rollback (nếu cần)

Nếu phát hiện lỗi sau khi deploy:

```bash
# Sửa code trong trunk
# Bump version lên 1.4.3
# Copy lại vào trunk
svn cp trunk tags/1.4.3
svn commit -m "Hotfix 1.4.3 — fix XYZ"
```

WordPress.org **không hỗ trợ xóa tag** đã release. Luôn fix forward với version mới.

## Checklist trước mỗi release

- [ ] Version khớp: `shield-monitor.php` header, constant, `readme.txt` Stable tag
- [ ] `readme.txt` changelog có entry cho version mới
- [ ] `CHANGELOG.MD` có entry chi tiết
- [ ] `npm run build` đã chạy (admin.js compiled)
- [ ] PHP syntax check pass: `php -l includes/*.php`
- [ ] Không commit file dev: `node_modules/`, `assets/src/`, `package.json`, `.git/`
- [ ] Test plugin hoạt động trên site staging
