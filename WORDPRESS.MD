# CodeWP Shield Monitor - WordPress Plugin Review Checklist

> Review ID: **AUTOPREREVIEW – TRM-OWN**
>
> Ngày nhận review: **24/06/2026**
>
> Trạng thái: **Pending** (Chưa bị từ chối)

---

# Tổng quan

Plugin hiện chưa được chấp thuận do một số vấn đề liên quan đến:

* Thương hiệu (Trademark)
* Quyền sở hữu plugin
* REST API Security
* Source code của file build

Sau khi hoàn thành các yêu cầu dưới đây:

* Upload phiên bản mới
* Reply email review
* Chờ reviewer kiểm tra lại

---

# 1. Đổi tên Plugin (Bắt buộc)

## Vấn đề

Tên hiện tại:

```
CodeWP Shield Monitor
```

Slug:

```
shield_monitor
```

WordPress cho rằng:

* "Press" là biến thể của "WordPress"
* "-Press" không được phép sử dụng trong tên thương hiệu nếu không thuộc WordPress Foundation.
* Có khả năng gây nhầm lẫn với WordPress.

Đây là lỗi quan trọng nhất.

---

## Cần thực hiện

### Đổi Display Name

Trong:

* readme.txt
* File plugin chính

Ví dụ:

```
Plugin Name:
```

---

### Đổi Slug

Ví dụ:

```
shield_monitor
```

↓

```
vshield-security
```

hoặc

```
vithan-security
```

---

### Đổi Text Domain

Ví dụ:

```
shield_monitor
```

↓

```
vshield-security
```

---

### Đổi namespace REST API

Ví dụ:

```
codewp-shield-monitor/v1
```

↓

```
vshield/v1
```

---

### Đổi các chuỗi liên quan

Bao gồm:

* constants
* prefix
* language folder
* load_plugin_textdomain()

---

### Reply yêu cầu đổi slug

Sau khi upload plugin mới cần ghi rõ:

> Please reserve the following slug:
>
> `new-plugin-slug`

---

# 2. Xác minh quyền sở hữu Plugin

## Vấn đề

Plugin:

```
https://shieldpress.net
```

WordPress account:

```
vithanhlam@live.com
```

Không thể chứng minh bạn là chủ website.

---

## Có thể chọn một trong các cách

### Cách 1 (Khuyến nghị)

Đổi email WordPress.org thành email thuộc domain.

Ví dụ:

```
admin@yourdomain.com
```

---

### Cách 2

Thêm DNS TXT Record

```
wordpressorg-vithanhlam-verification
```

---

### Cách 3

Nếu đổi toàn bộ brand plugin thì vấn đề này gần như sẽ được giải quyết.

---

# 3. Public Source Code

## Vấn đề

File

```
assets/js/admin.js
```

đã được build/minify.

Reviewer không tìm thấy source.

---

## Cần thực hiện

Có thể chọn một trong hai cách.

### Cách 1

Đưa source vào plugin.

Ví dụ

```
assets/js/src/admin.js
```

↓

build

```
assets/js/admin.js
```

---

### Cách 2

Public GitHub Repository

Ví dụ

```
https://github.com/username/plugin-name
```

Sau đó ghi trong README.

Ví dụ:

```
Source code:
https://github.com/...
```

---

# 4. REST API Permission

## Endpoint

```
/public-score
```

Đang dùng

```php
permission_callback => '__return_true'
```

Điều này khiến bất kỳ ai cũng có thể xem:

* Security Score
* Security Status
* Incomplete Tasks

Reviewer đánh giá đây là dữ liệu không nên public.

---

## Cần sửa

Nếu endpoint chỉ dành cho admin

```php
permission_callback => function () {
    return current_user_can('manage_options');
}
```

Nếu cần public thì endpoint chỉ nên trả về dữ liệu hoàn toàn công khai.

---

# 5. Kiểm tra lại toàn bộ Trademark

Sau khi đổi tên plugin cần rà soát:

* Plugin Name
* Slug
* Text Domain
* REST Namespace
* Plugin URI
* Assets
* Banner
* Icon
* Readme
* GitHub Repository
* Domain
* Screenshot

Đảm bảo không còn sử dụng tên cũ gây nhầm lẫn.

---

# 6. Upload Plugin

Sau khi hoàn tất:

* Test plugin
* Đảm bảo không lỗi Activate
* Upload phiên bản mới qua:

```
Add Your Plugin
```

---

# 7. Reply Email

Sau khi upload chỉ cần trả lời ngắn gọn.

Ví dụ:

```
Hello,

Thank you for your review.

I have addressed all the reported issues, uploaded a new version, and requested a new plugin slug.

Please review the updated submission.

Thank you.
```

Không cần liệt kê từng thay đổi.

---

# Việc cần làm (Checklist)

## Thương hiệu

* [ ] Đổi Plugin Name
* [ ] Đổi Slug
* [ ] Đổi Text Domain
* [ ] Đổi REST Namespace
* [ ] Đổi prefix nếu cần
* [ ] Reply yêu cầu reserve slug mới

---

## Quyền sở hữu

* [ ] Đổi email WordPress.org sang email domain
  hoặc

* [ ] Thêm DNS TXT Verification

---

## Source Code

* [ ] Thêm source JS

hoặc

* [ ] Public GitHub Repository

* [ ] Cập nhật README

---

## REST API

* [ ] Kiểm tra tất cả register_rest_route()
* [ ] Bổ sung permission_callback phù hợp
* [ ] Không public Security Score

---

## Trước khi Upload

* [ ] Test Activate
* [ ] Test REST API
* [ ] Test Admin
* [ ] Test JavaScript
* [ ] Upload plugin mới
* [ ] Reply email review

---

# Mức độ ưu tiên

| Mức độ        | Công việc                  |
| ------------- | -------------------------- |
| 🔴 Rất cao    | Đổi tên Plugin + Slug      |
| 🔴 Rất cao    | REST API Permission        |
| 🟠 Cao        | Xác minh quyền sở hữu      |
| 🟡 Trung bình | Public Source Code         |
| 🟢 Thấp       | Reply email sau khi upload |
