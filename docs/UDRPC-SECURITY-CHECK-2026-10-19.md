# Kiểm tra UDRPC — cập nhật bản 1.5.0

Ngày: 2026-10-19.

## Kiểm thử tích hợp đã chạy

Lab riêng: WordPress 7.1.3, SQLite Database Integration, UpdraftPlus Free 1.26.11 và ShieldPress 1.5.0; HTTP chỉ trên localhost. Không dùng site hoặc key thật. Plugin thử nghiệm chỉ ghi slug vào option khi kích hoạt.

- Trước thay đổi: 5 tình huống HTTP xác nhận được cài/kích hoạt qua chữ ký hợp lệ, WAF không chặn, khóa cài đặt chặn, chữ ký sai bị Updraft từ chối, và không key thì bản Free không thực thi lệnh.
- Sau thay đổi: 15 tình huống HTTP đạt kỳ vọng; bật chặn UDRPC trả 403 với key hợp lệ, không key, cookie admin, admin AJAX, giá trị rỗng/mảng và tham số GET. WAF tắt vẫn chặn. Tắt công tắc khôi phục upload hợp lệ. Form thông thường không bị ảnh hưởng.
- 18 kiểm tra trên WordPress multisite thật xác nhận super admin đi qua bộ lọc user_has_cap cũ và bị map_meta_cap mới từ chối. Chưa chạy HTTP RPC trong network.
- 44 kiểm tra ranh giới phiên bản CVE và 31 kiểm tra nâng cấp bỏ Premium/API đều đạt. Xem JSON ở [verification-1.5.0](verification-1.5.0/).

HTTP 200 không được coi là cài thành công: đã giải mã phản hồi RPC khi phù hợp và kiểm tra file, trạng thái kích hoạt và marker. Mỗi lần chạy dùng slug mới.

## Đối chiếu ba báo cáo

| Báo cáo | Điều kiểm chứng được | Giới hạn |
| --- | --- | --- |
| [Sự cố 1.26.11](https://wordpress.org/support/topic/security-vulnerability-affecting-updraftplus-1-26-11/) | Tái hiện đường RPC upload và kích hoạt bằng key lab hợp lệ; công tắc mới chặn trước listener. | Không có request/key gốc; chưa xác định hay tái hiện nguyên nhân ban đầu. |
| [Không key / Premium](https://wordpress.org/support/topic/auth-bypass-in-udrpc-listener-active-exploitation/) | Bản Free không thực thi khi không có key; công tắc mới vẫn trả 403 với marker UDRPC dù không key. | Không có Premium 2.26.8.26 hoặc payload gốc; chưa tái hiện bypass được báo cáo. |
| [Năm site / kết nối có sẵn](https://wordpress.org/support/topic/unauthorised-updraftcentral-plugin-uploads-on-five-sites/) | Tái hiện lệnh qua kết nối lab có sẵn và xác nhận khóa cài đặt/công tắc UDRPC chặn. | Không chứng minh key thật đã bị lấy bằng cách nào hoặc hạ tầng bên nào bị xâm nhập. |

Không tuyên bố tái hiện trọn vẹn cả ba vụ. Không tái hiện khai thác CVE-2026-10795; mã nguồn 1.26.4 chỉ được tải để đối chiếu, không được dùng để thực thi khai thác. Raw JSON không được công tắc này đọc; listener được thử nghiệm dùng PHP POST.

## Phần nâng cấp

block_udrpc mặc định tắt để giữ tương thích. Khi bật sẽ ngắt cả RPC hợp lệ, có thể ảnh hưởng UpdraftCentral, migration và plugin khác dùng UDRPC. Không có miễn trừ admin và không cần bật WAF. Audit log chỉ ghi mã rule, không ghi key/chữ ký/payload.

[Danh sách CVE và ưu tiên nâng cấp](SECURITY-REVIEW-2026-10-19.md). Mã kiểm thử ở [tests/security](../tests/security/). Không phát hành lab hay key thử nghiệm trong ZIP.

---

## Ghi nhận ban đầu trước kiểm thử tích hợp


Ngày kiểm tra: 2026-10-19. ShieldPress: mã nguồn workspace 1.4.4.

## Kế hoạch và phạm vi đã thực hiện

1. Đọc ba báo cáo sự cố trên WordPress.org; phân biệt cáo buộc vượt xác thực với việc lạm dụng kết nối đã cấp quyền.
2. Tải gói UpdraftPlus Free 1.26.11 chính thức, kiểm tra listener và đường đi của `plugin.upload_plugin`.
3. Chạy PHP CLI cô lập với WAF và bộ lọc quyền thật của ShieldPress, cùng lớp lệnh upload thật của UpdraftPlus. Các API WordPress được giả lập; không nạp site, database hoặc tài khoản thật.
4. So sánh khóa cài phần mềm bật/tắt và dùng SQLi làm đối chứng để xác nhận bộ kiểm tra WAF có hoạt động.

Gói nguồn: https://downloads.wordpress.org/plugin/updraftplus.1.26.11.zip

SHA-256: `f8f7a816b546635ad97a830a58f7652a83f35c1eb761ab5a609f5139d9183d45`.

## Kết quả

13 kiểm tra khớp kỳ vọng, không có lỗi chạy. “Khớp kỳ vọng” bao gồm xác nhận khoảng trống bảo vệ; không có nghĩa 13 tình huống đều được chặn.

| Tình huống | Quan sát |
| --- | --- |
| WAF bật | Đăng ký tại `init`, priority 1 |
| Khách gửi POST `/` có `udrpc_message`, `format`, `key_name`, `signature` giả | WAF cho qua |
| Giá trị POST chứa riêng `plugin.upload_plugin` | WAF cho qua |
| Khách gửi `UNION SELECT` ở frontend | WAF chặn 403 |
| Cùng POST SQLi ở admin endpoint | WAF bỏ qua POST này |
| Admin gửi POST có cấu trúc UDRPC | WAF cho qua |
| Admin gửi SQLi | WAF bỏ qua theo thiết kế |
| Khóa cài phần mềm tắt | `install_plugins` vẫn true; lệnh upload đến bước lấy thư mục tạm, rồi dừng vì dữ liệu rỗng |
| Khóa cài phần mềm bật | `install_plugins` false; lệnh upload trả `plugin_insufficient_permission`, chưa gọi bước lấy thư mục tạm |

Mã UpdraftPlus được thực thi: `central/modules/plugin.php::upload_plugin()` gọi `central/commands.php::process_chunk_upload()`. Hàm này kiểm tra `install_plugins` và `activate_plugins` trước thao tác ghi file. Constructor của lớp lệnh được bỏ qua vì chỉ nạp các file UI/admin WordPress; thân lệnh upload không bị thay thế.

Đối chiếu listener: `class-udrpc2.php` và `class-udrpc3.php` đăng ký xử lý tại `wp_loaded`; `central/listener.php` có bước đổi current user theo thông tin kết nối trước khi gọi lệnh. Thời điểm đổi user này diễn ra sau `init`; việc WAF bỏ qua admin không phải điều kiện cần để request khách UDRPC vượt qua WAF trong thử nghiệm.

## Kết luận có giới hạn

- Không thể coi WAF hiện tại là bản vá ảo cho vụ UDRPC: không có kiểm tra chuyên biệt và request mô phỏng đi qua.
- Khóa cài phần mềm chặn được bước kiểm tra quyền của đường `plugin.upload_plugin` trong UpdraftPlus Free 1.26.11 ở kiểm thử thành phần này.
- Cấu hình mặc định của ShieldPress đặt cả `waf_enabled` và `disable_software_installation` bằng 0. Không đọc hoặc thay đổi cấu hình site đang chạy.
- Chưa chứng minh có lỗ hổng mới, chưa tái hiện vượt chữ ký/xác thực, chưa kiểm thử Premium 2.x hoặc multisite/super admin. Chữ ký giả trong mẫu chỉ dùng kiểm tra WAF, không được xem là request RPC hợp lệ.
- Không ghi plugin lên site, không kích hoạt backdoor, không thay đổi cấu hình bảo vệ.

## Kế hoạch kiểm thử tích hợp tiếp theo

Trước khi công bố khả năng chặn hoặc phát hành thay đổi bảo vệ:

1. Dựng WordPress dùng database riêng trong lab; cài ShieldPress và gói UpdraftPlus cần hỗ trợ, tạo admin và kết nối RPC chỉ dùng trong lab.
2. Gửi lệnh có chữ ký hợp lệ để upload plugin đánh dấu vô hại; so sánh WAF bật/tắt và khóa cài bật/tắt. Kiểm tra HTTP response, kết quả RPC, file và trạng thái kích hoạt.
3. Lặp lại với request không key/sai chữ ký để tách việc Updraft từ chối xác thực khỏi việc ShieldPress chặn. Không suy diễn từ response HTTP 200 đơn lẻ.
4. Kiểm tra multisite/super admin riêng: bộ lọc `user_has_cap` có giới hạn trên super admin, nên không mặc định áp dụng kết quả single-site cho multisite.
5. Nếu bổ sung chặn UDRPC: chạy trước listener, không miễn kiểm tra vì admin; kiểm tra cả dạng mảng của tham số và request frontend/admin. Xác nhận tác động đến UpdraftCentral và các tính năng dùng chung UDRPC; có tùy chọn bật/tắt rõ ràng.

## Tái chạy kiểm tra thành phần trong phiên này

- Script: `/tmp/shieldpress-udrpc-audit/check.php`
- Kết quả JSON: `/tmp/shieldpress-udrpc-audit/results.json`
- Lệnh: `php /tmp/shieldpress-udrpc-audit/check.php`

Các file `/tmp` là artifact tạm của phiên, không được bảo đảm tồn tại lâu dài.

## Báo cáo tham chiếu

- https://wordpress.org/support/topic/security-vulnerability-affecting-updraftplus-1-26-11/
- https://wordpress.org/support/topic/auth-bypass-in-udrpc-listener-active-exploitation/
- https://wordpress.org/support/topic/unauthorised-updraftcentral-plugin-uploads-on-five-sites/
