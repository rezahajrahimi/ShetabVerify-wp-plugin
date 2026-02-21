# PLUGIN-TODO.md — لیست کامل مراحل ساخت پلاگین درگاه پرداخت (ShetabVerify)

خلاصه: پلاگین باید با آخرین WordPress و WooCommerce و ساختار بلوکی (WooCommerce Blocks) سازگار باشد، چندین شماره کارت مدیریتی بپذیرد (هر کارت با سقف تعداد و سقف مجموع واریز)، یک REST API تایید پرداخت با `secret` داشته باشد، مبلغ پرداخت را با سه رقم آخر یکتا کند، تأیید پرداخت تا 10 دقیقه قابل قبول باشد و در صورت تأیید سفارش به حالت پرداخت‌شده برود.

---

## تصمیمات کلیدی (باید نهایی شوند) ⚠️
- بازه زمانی برای "سقف تعداد واریز" و "سقف مجموع واریز" برای هر کارت: (مثلاً daily / monthly / lifetime) — نیاز به تعیین.
- واحد پول: تومان (هماهنگ با تنظیمات WooCommerce).
- نمایش و ذخیره شماره کارت: پیشنهاد — `encrypted storage + masked display`.
- مسیر REST API: `shetab-verify/v1`.

---

## نقشه راه سطح بالا
1. طراحی محصول و تصمیم‌گیری‌های کلیدی
2. اسکلت پلاگین و نصب/فعال‌سازی
3. دیتابیس و migration
4. پنل مدیریت کارت‌ها (CRUD)
5. درگاه WooCommerce (پرداخت، redirect)
6. REST API تأیید پرداخت و امنیت
7. صفحه پرداخت سازگار با WooCommerce Blocks
8. انقضا 10 دقیقه و cleanup
9. لاگینگ و مدیریت دستی
10. تست‌ها (unit, integration, e2e)
11. مستندسازی و انتشار در WordPress.org
12. CI/CD و نگهداری

---

## ساختار پیشنهادی پوشه‌ها
```
ShetabVerify/
├─ shetab-verify.php
├─ includes/
│  ├─ class-activator.php
│  ├─ class-deactivator.php
│  ├─ class-db.php
│  ├─ admin/
│  │  ├─ class-admin-pages.php
│  │  └─ class-cards-controller.php
│  ├─ api/
│  │  └─ class-rest-controller.php
│  └─ gateway/
│     └─ class-wc-gateway-shetab.php
├─ public/
│  └─ js/
├─ templates/
├─ languages/
├─ uninstall.php
└─ tests/
```

---

## دیتابیس — جداول پیشنهادی 🗄️
- جدول کارت‌ها: `wp_shetab_cards`
  - id, label, encrypted_number, masked_number, max_deposits_count, max_total_amount, reset_period, active, created_at, updated_at
- جدول تراکنش‌ها: `wp_shetab_transactions`
  - id, order_id, card_id, original_amount, unique_amount, status, expires_at, remote_ref, raw_payload, created_at, confirmed_at
- options: `shetab_api_secret_hash`, تنظیمات پلاگین در `wp_options`.

---

## الگوریتم تولید مقدار یکتا (سه رقم آخر) 🔢
1. base = floor(original_amount / 1000) * 1000
2. انتخاب `suffix` سه‌رقمی (000–999) به‌صورت رندوم یا متوالی
3. final = base + suffix
4. اطمینان: final >= original_amount و final در تراکنش‌های `pending` رزرو نشده باشد
5. تلاش تا N بار (مثلاً 10)؛ در صورت شکست، خطای کاربر یا fallback

ذخیره `unique_amount` در جدول تراکنش‌ها و اندیس‌گذاری برای جستجوی سریع.

---

## جریان پرداخت (کاربر) — گام‌به‌گام 🧾
1. کاربر در checkout ووکامرس `ShetabVerify` را انتخاب می‌کند.
2. در `process_payment()`:
   - تولید `unique_amount`
   - ایجاد رکورد در `wp_shetab_transactions` با `expires_at = now + 10min`
   - redirect به صفحه پرداخت پلاگین که `unique_amount` و یک کارت نمایش داده می‌شود
3. صفحه پرداخت نمایش `masked_number` و countdown (10:00)، polling AJAX برای وضعیت
4. سامانه بیرونی POST به REST API پلاگین برای تأیید مقدار واریزی
5. اگر تأیید شد: transaction -> confirmed، call `WC_Order->payment_complete()` و هدایت کاربر به صفحه تایید
6. اگر پس از 10 دقیقه تأییدی نیاید: سفارش لغو و transaction -> expired

---

## REST API — مشخصات فنی 🔐
- namespace: `wp-json/shetab-verify/v1`

### POST /confirm
- Header: `Authorization: Bearer <SECRET>` یا `X-Shetab-Secret`
- Body JSON: { "order_id": 123, "amount": 12120, "remote_ref": "BANK123", "card_number": "..." }
- رفتار: بررسی secret، یافتن تراکنش `pending` با همان `order_id` و `unique_amount` و `expires_at >= now` → set confirmed و فراخوانی `payment_complete()`
- پاسخ: { "success": true, "message": "confirmed" }

### GET /status
- پارامتر: `order_id`
- پاسخ: وضعیت تراکنش (pending/confirmed/expired)

سایر موارد: لاگ، rate-limit، بررسی IP/SSL

---

## سازگاری با WooCommerce Blocks 🧩
- ثبت `WC_Payment_Gateway` برای هر دو محیط classic و blocks
- client-side adapter JS برای Checkout Blocks (public/js/blocks.js)
- export متد `processPayment` مناسب برای block editor

---

## انقضا 10 دقیقه — پیاده‌سازی ⏱️
- گزینه‌ها:
  - استفاده از WP-Cron با چک دوره‌ای (هر 1–5 دقیقه)
  - یا schedule event برای هر تراکنش (دقت بیشتر)
- هنگام انقضا: set transaction -> expired و cancel سفارش ووکامرس

---

## امنیت و حریم خصوصی 🔒
- ذخیره secret به‌صورت هش (hash_hmac یا wp_hash_password)
- شماره کارت‌ها رمزنگاری شده هنگام ذخیره (`openssl_encrypt`) و تنها masked نمایش داده شود
- capability checks برای UI ادمین (`manage_woocommerce` / `manage_options`)
- sanitization و prepared statements
- گزینه حذف داده‌ها در `uninstall.php`

---

## تست‌‌ها (واحدها و سناریوها) ✅
- Unit: الگوریتم unique_amount، encryption، secret validation
- Integration: full checkout + API confirm + expiration
- E2E: flow کاربر در checkout block و classic
- Security: auth failure, rate-limit tests

---

## UI ادمین و گزارش‌ها 📊
- صفحه مدیریت کارت‌ها (CRUD)
- لیست تراکنش‌ها با فیلتر status/date/order_id
- export CSV
- بازتولید `secret` و audit log

---

## آماده‌سازی برای WordPress.org — چک‌لیست ✅
- `readme.txt` استاندارد، assets، license GPL-2.0-or-later
- i18n و load_textdomain
- عدم فعالیت‌های مخفیانه یا tracking پنهان
- تست روی نسخه‌های مورد پشتیبانی (PHP & WP & WC)

---

## CI / انتشار
- GitHub Actions: lint, phpcs (WordPress), phpunit, e2e
- release tagging (semver)

---

## موارد اختیاری آینده‌نگر
- webhook از بانک
- داشبورد آنالیتیکس
- rotating API secrets
- load-balancing بین کارت‌ها

---

## معیارهای پذیرش (نمونه)
1. پرداخت موفق → سفارش `paid` شود.
2. عدم تایید تا 10 دقیقه → سفارش `cancelled` شود.
3. API با secret اشتباه → 401.
4. سازگاری با WooCommerce Blocks.

---

## تحویلی‌ها
- `ShetabVerify/` کد کامل پلاگین
- migration scripts
- مستندات API
- تست‌ها (unit/integration/e2e)
- `readme.txt` و assets برای WordPress.org

---

## Next steps / پیشنهاد من
1. تأیید تصمیمات کلیدی (reset period و ذخیره کارت)
2. پس از تأیید: اسکلت پروژه و فایل اصلی پلاگین ساخته شود (shetab-verify.php)

---

*در صورت نیاز می‌توانم بلافاصله اسکلت پلاگین را بسازم و فایل‌های اولیه را اضافه کنم.*
