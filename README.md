# Tutor LMS Curriculum Duplicator

เพิ่มปุ่ม **ทำสำเนา (Duplicate)** ให้กับบทเรียน แบบทดสอบ งานที่มอบหมาย และหัวข้อ
ในหน้า Course Builder ของ Tutor LMS รุ่นฟรี โดยไม่แก้ไฟล์ Core และไม่ต้องติดตั้ง Tutor LMS Pro

- เวอร์ชัน: 1.1.2
- ต้องการ: WordPress 6.0+, PHP 7.4+, Tutor LMS 3.0.0+

## 0. เวอร์ชัน Tutor LMS ที่รองรับ

| เวอร์ชัน Tutor LMS | สถานะ | พฤติกรรมของปลั๊กอิน |
| --- | --- | --- |
| **4.0.x** | ✅ ตรวจสอบซอร์สโค้ดแล้ว | ทำงานปกติ |
| 4.1.0 ขึ้นไป | ⚠️ ยังไม่ได้ตรวจสอบ | **ยังทำงานต่อ** แต่ขึ้น admin notice และ `console.warn` |
| 3.0.0 – 3.x | ⚠️ น่าจะใช้ได้ ยังไม่ได้ตรวจสอบ | ทำงานต่อ (ใช้ React Course Builder ตัวเดียวกัน) |
| ต่ำกว่า 3.0.0 | ❌ ไม่รองรับ | ไม่โหลดปุ่มและ REST endpoint พร้อมขึ้น notice |

ค่าที่ pin ไว้อยู่ในไฟล์หลักของปลั๊กอิน

```php
define( 'TLCD_MIN_TUTOR_VERSION',    '3.0.0' ); // ต่ำกว่านี้ปิดการทำงาน
define( 'TLCD_TESTED_TUTOR_VERSION', '4.0.2' ); // เวอร์ชันล่าสุดที่ตรวจซอร์สแล้ว
define( 'TLCD_TESTED_TUTOR_BRANCH',  '4.0'   ); // patch ในสายนี้ไม่ถือว่าใหม่กว่า
```

**ทำไมถึงเลือก "เตือน ไม่บล็อก"** — patch release (4.0.2 → 4.0.9) แทบไม่แตะโครงสร้างข้อมูล
จึงไม่ขึ้นเตือน ส่วน minor/major ใหม่ (4.1, 5.x) จะขึ้นเตือนแต่ยังใช้งานได้
เพราะการบล็อกทั้งหมดจะทำให้ผู้สอนทำงานต่อไม่ได้ทันทีที่อัปเดต

**สิ่งที่ป้องกันข้อมูลเสียหายจริง ๆ ไม่ใช่การเช็กเลขเวอร์ชัน แต่คือ 3 ด่านนี้**

1. **Runtime integrity guard** (`Compatibility::runtime_issues()`) — ก่อนทำสำเนาทุกครั้ง
   ตรวจว่า post type `courses` / `topics` / `lesson` ยังลงทะเบียนอยู่จริง ถ้าหายไปจะตอบ
   HTTP 409 และไม่สร้างอะไรเลย
2. **Relationship validation** — ยึด `post_parent` จริงในฐานข้อมูล ไม่เชื่อ ID จาก JavaScript
3. **DOM/data reconciliation** ฝั่ง JavaScript — ถ้าจำนวนหัวข้อหรือจำนวนเนื้อหาใน DOM
   ไม่ตรงกับข้อมูลจาก REST จะหยุดแทรกปุ่มแทนที่จะเดา (ดูข้อ 8)

หลังอัปเดต Tutor LMS เป็นเวอร์ชันใหม่ ให้รัน checklist ข้อ 7 บน Staging
แล้วค่อยแก้ `TLCD_TESTED_TUTOR_VERSION` / `TLCD_TESTED_TUTOR_BRANCH` เพื่อปิดคำเตือน
(ตั้งใจไม่ทำปุ่มปิดคำเตือนใน UI เพื่อไม่ให้กดข้ามโดยไม่ได้ทดสอบ)

---

## 1. สิ่งที่ปลั๊กอินนี้ทำ

| ความสามารถ | สถานะใน v1.1.2 |
| --- | --- |
| Duplicate Lesson | ✅ |
| Duplicate Quiz พร้อมคำถามและตัวเลือกคำตอบ | ✅ |
| Duplicate Assignment พร้อมคะแนนและไฟล์ประกอบ | ✅ |
| Duplicate Topic พร้อมเนื้อหาทั้งหมด | ✅ |
| วางสำเนาต่อจากรายการต้นฉบับ | ✅ |
| คัดลอกเนื้อหา / วิดีโอ / ไฟล์แนบ / featured image / preview setting | ✅ |
| Rollback เมื่อ Duplicate Topic ล้มเหลวกลางทาง | ✅ |
| ไม่คัดลอก progress ผลสอบ และกิจกรรมของผู้เรียน | ✅ |
| คำแปลภาษาไทยครบทุกข้อความ | ✅ |
| ชุดทดสอบอัตโนมัติ + CI (PHP, JavaScript และ Playwright E2E scaffold) | ✅ |
| Duplicate Zoom / Google Meet / Interactive Quiz (H5P) | ❌ (ถูกข้ามและรายงานกลับ) |
| Duplicate ข้ามคอร์ส / เลือกปลายทาง / Bulk | ❌ (แผน v1.2–v1.3) |

---

## 2. สิ่งที่พบจากการสำรวจ Tutor LMS 4.x

บันทึกไว้เพื่อให้แก้ไขต่อได้เมื่อ Tutor LMS อัปเดต

**โครงสร้างข้อมูล**

```
courses (post_type: courses)
└── topics (post_type: topics, post_parent = course ID, menu_order = ลำดับ)
    └── lesson / tutor_quiz / tutor_assignments / ...
        (post_parent = topic ID, menu_order = ลำดับ)
```

**Meta key ของ Lesson ที่ต้องคัดลอก**

| Key | ความหมาย |
| --- | --- |
| `_thumbnail_id` | Featured image |
| `_video` | แหล่งวิดีโอ + runtime (array) |
| `_tutor_attachments` | Exercise files (array ของ attachment ID) |
| `_is_preview` | Course Preview addon (`Tutor\Lesson::PREVIEW_META_KEY`) |
| `_tutor_course_id_for_lesson` | ใช้อ้างคอร์สกรณีบทเรียนกำพร้า — ปลั๊กอินเขียนทับด้วยคอร์สปลายทางเสมอ |
| `_content_drip_settings` | Content Drip addon |

**Meta key ของ Quiz และ Assignment**

| Key | ชนิด | ความหมาย |
| --- | --- | --- |
| `tutor_quiz_option` | Quiz | เวลา เกณฑ์ผ่าน จำนวนครั้งที่ทำได้ (`Tutor\Quiz::META_QUIZ_OPTION`) |
| `assignment_option` | Assignment | คะแนนเต็ม เกณฑ์ผ่าน จำนวน/ขนาดไฟล์ |
| `_tutor_assignment_attachments` | Assignment | ไฟล์ประกอบ |
| `_tutor_course_id_for_assignments` | Assignment | course id — เขียนทับด้วยคอร์สปลายทางเสมอ |

**คำถามของ Quiz ไม่ได้อยู่ใน post meta** แต่อยู่ในตารางของ Tutor เอง

```
{prefix}tutor_quiz_questions         question_id (PK), quiz_id, question_order, ...
{prefix}tutor_quiz_question_answers  answer_id (PK), belongs_question_id, answer_order, ...
```

`Quiz_Duplicator` จึงคัดลอกทั้งสองตารางแล้ว map `question_id` เดิม → ใหม่
ส่วน `tutor_quiz_attempts` / `tutor_quiz_attempt_answers` เป็นข้อมูลผู้เรียน — ห้ามแตะ

**สิ่งที่ห้ามคัดลอก** — `_edit_lock`, `_edit_last`, transient, `_tutor_completed_*`,
`_lesson_reading_info`, `_tutor_attempt*`, `_tutor_assignment_submission*`,
cache ของ Elementor/Divi (ดู `Post_Meta_Copier::blocked_patterns()`)

**สิทธิ์ของผู้สอน** มาจากสองทาง ต้องตรวจทั้งคู่ (`Utils::get_instructors_by_course()`)

1. `post_author` ของคอร์ส = main instructor
2. usermeta `_tutor_instructor_course_id` = co-instructor

และ `can_user_manage()` รับ context เป็น `lesson` / `quiz` / `assignment` / `topic` / `course`
ไม่ใช่ชื่อ post type — `Permission::content_context()` ทำหน้าที่แปลง

**Course Builder**

- เป็น React app mount ที่ `<div id="tutor-course-builder">`
- ไม่มี PHP hook สำหรับแทรกปุ่มในแถว Curriculum
- **Tutor LMS ฟรีมีปุ่ม Duplicate อยู่แล้ว แต่ถูก disable และห่อด้วย `<ProBadge>`**
  เงื่อนไขคือ `isTutorPro = !!tutorConfig.tutor_pro_url` และปุ่มที่ใช้งานได้จริงจะยิง
  AJAX action `tutor_duplicate_content` ซึ่งมีเฉพาะใน Pro
- ปลั๊กอินนี้จึง **ไม่แตะปุ่มเดิม** แต่แทรกปุ่มของตัวเองเข้าไปแทน

**จุดยึดใน DOM ที่ใช้** (มาจากชุดทดสอบ Cypress ของ Tutor LMS จึงเสถียรกว่า class name ที่ Emotion generate)

| Selector | ใช้ทำอะไร |
| --- | --- |
| `button[data-cy="delete-topic"]` | ระบุแถวหัวข้อ |
| `button[data-cy="edit-topic"]` | หา container ของกลุ่มปุ่มหัวข้อ |
| `[aria-roledescription="sortable"]` | ขอบเขตของหัวข้อ (มาจาก dnd-kit) |
| `[data-actions] button[data-cy^="delete-"]` | ระบุแถวเนื้อหาในหัวข้อ |
| `data-cy="delete-{post_type}"` | ยืนยันว่าชนิดของแถวตรงกับข้อมูลจาก REST |
| `p` ในแถวเนื้อหา | ยืนยันชื่อรายการก่อนแทรกปุ่ม |

ปรับ selector ได้ผ่าน filter `tlcd_builder_config` โดยไม่ต้องแก้ JavaScript

---

## 3. สถาปัตยกรรม

```
UI (JavaScript)                  ← เปลี่ยนบ่อยตาม Tutor LMS
   ↓ REST
Permission + Relationship check  ← ตรวจสิทธิ์และความสัมพันธ์ทุกครั้ง
   ↓
Duplicate Services               ← ไม่ผูกกับ UI, ใช้ซ้ำได้จาก PHP/WP-CLI
   ↓
Curriculum Order (menu_order)
```

```
includes/
├── class-plugin.php              bootstrap
├── class-compatibility.php       รวมทุกจุดที่ต้องรู้จัก Tutor LMS
├── class-permission.php          can_user_manage() + fallback
├── class-curriculum-order.php    จัดลำดับ menu_order
├── api/
│   ├── class-rest-controller.php    base + lock กันกดซ้ำ (ปลดใน finally)
│   ├── class-content-controller.php duplicate lesson/quiz/assignment + curriculum snapshot
│   └── class-topic-controller.php   duplicate topic
├── services/
│   ├── class-content-duplicator.php    base — ขั้นตอนที่ทุกชนิดใช้ร่วมกัน
│   ├── class-lesson-duplicator.php     บทเรียน
│   ├── class-quiz-duplicator.php       แบบทดสอบ + คำถามในตารางของ Tutor
│   ├── class-assignment-duplicator.php งานที่มอบหมาย
│   ├── class-duplicator-factory.php    post type → คลาสไหน (จุดเดียวที่รู้)
│   ├── class-topic-duplicator.php      เรียก factory + rollback ทั้ง operation
│   ├── class-post-meta-copier.php      allowlist ก่อน blocklist
│   └── class-title-generator.php       "ชื่อเดิม – สำเนา", "– สำเนา 2", ...
└── integrations/
    ├── interface-adapter.php
    ├── class-course-builder.php        เลือก adapter + โหลด asset
    └── class-react-builder-adapter.php Tutor LMS 3.x/4.x

tests/                              ชุดทดสอบ PHP และ JavaScript (ไม่รวมใน ZIP)
languages/                          .pot, .po, .mo
bin/                                install-wp-tests.sh, build-zip.sh, make-pot.py, compile-mo.py
.github/workflows/ci.yml            lint → unit → integration → build
```

การเพิ่มเนื้อหาชนิดใหม่ทำได้โดยเขียนคลาสที่สืบทอด `Content_Duplicator` แล้วลงทะเบียน
ผ่าน filter `tlcd_duplicator_map` — ไม่ต้องแก้ REST controller หรือ Topic_Duplicator

เมื่อ Tutor LMS เปลี่ยน UI ให้เขียน adapter ตัวใหม่แล้วลงทะเบียนผ่าน
filter `tlcd_course_builder_adapters` โดยไม่ต้องแตะ Duplicate Service

---

## 4. REST API

ต้องส่ง `X-WP-Nonce` (nonce ของ `wp_rest`) และเป็นผู้ใช้ที่มีสิทธิ์แก้ไขคอร์สนั้น

```
POST /wp-json/tlcd/v1/contents/{id}/duplicate   ← บทเรียน / แบบทดสอบ / งานที่มอบหมาย
POST /wp-json/tlcd/v1/topics/{id}/duplicate
GET  /wp-json/tlcd/v1/courses/{id}/curriculum

POST /wp-json/tlcd/v1/lessons/{id}/duplicate    ← alias เดิมของ v1.0 (ยังใช้ได้ รับเฉพาะบทเรียน)
```

ตัวอย่างผลลัพธ์

```json
{
  "success": true,
  "message": "ทำสำเนาบทเรียนเรียบร้อย",
  "data": {
    "source_id": 123,
    "duplicate_id": 456,
    "post_type": "lesson",
    "topic_id": 45,
    "course_id": 10,
    "title": "บทเรียนที่ 2 – สำเนา",
    "menu_order": 3
  }
}
```

**การตรวจสอบทุกคำขอ**

1. ผู้ใช้ล็อกอินแล้ว
2. REST nonce ถูกต้อง
3. `tutor_utils()->can_user_manage()` ผ่านทั้งตัวเนื้อหา, topic และ course
   (post type ถูกแปลงเป็น context ที่ Tutor เข้าใจ: `lesson` / `quiz` / `assignment`)
4. ไม่เชื่อ `topic_id` / `course_id` จาก JavaScript — ยึด `post_parent` จริงในฐานข้อมูล
   ถ้าค่าที่ส่งมาไม่ตรงจะตอบ 400
5. Atomic option lock ระดับเว็บไซต์ อายุสูงสุด 300 วินาที กันคำขอซ้ำข้ามผู้ใช้ (ตอบ 409)
   แต่ละคำขอถือ owner token ของตัวเอง จึงปลด lock ของคำขออื่นไม่ได้ และปลดใน `finally` เสมอ
6. หัวข้อที่มีเนื้อหาเกิน 200 รายการถูกปฏิเสธด้วย 413 ก่อนเริ่มสร้างอะไร
   เพื่อไม่ให้คำขอตายกลางทางจนเหลือข้อมูลครึ่ง ๆ กลาง ๆ

---

## 5. Hooks สำหรับปรับแต่ง

| Hook | ประเภท | ใช้ทำอะไร |
| --- | --- | --- |
| `tlcd_copy_suffix` | filter | เปลี่ยนข้อความต่อท้าย (ค่าเริ่มต้น `– สำเนา`) |
| `tlcd_generated_title` | filter | เปลี่ยนชื่อสำเนาทั้งหมด |
| `tlcd_duplicate_author` | filter | ค่าเริ่มต้นคือผู้ที่กด Duplicate — คืน `$source->post_author` เพื่อคงผู้เขียนเดิม |
| `tlcd_duplicate_post_status` | filter | สถานะโพสต์ของสำเนา |
| `tlcd_lesson_meta_allowlist` | filter | เพิ่ม meta key ที่ addon ของคุณใช้ |
| `tlcd_quiz_meta_allowlist` | filter | เช่นเดียวกันสำหรับแบบทดสอบ |
| `tlcd_assignment_meta_allowlist` | filter | เช่นเดียวกันสำหรับงานที่มอบหมาย |
| `tlcd_topic_meta_allowlist` | filter | เช่นเดียวกันสำหรับหัวข้อ |
| `tlcd_meta_blocklist` / `tlcd_meta_blocked_patterns` | filter | เพิ่มคีย์ที่ห้ามคัดลอก |
| `tlcd_meta_copy_mode` | filter | คืน `'blocklist'` เพื่อคัดลอกทุกคีย์ยกเว้นที่บล็อก |
| `tlcd_topic_child_post_types` | filter | post type ที่ถือว่าเป็นเนื้อหาในหัวข้อ (ใช้นับแถวใน DOM ด้วย) |
| `tlcd_duplicator_map` | filter | ลงทะเบียนคลาส Duplicator ของ post type ใหม่ |
| `tlcd_max_topic_children` | filter | จำนวนเนื้อหาสูงสุดต่อหัวข้อในหนึ่งคำขอ (ค่าเริ่มต้น 200) |
| `tlcd_countable_post_statuses` | filter | สถานะโพสต์ที่นับเป็นรายการใน Curriculum |
| `tlcd_builder_config` | filter | แก้ selector / ข้อความฝั่ง JavaScript |
| `tlcd_course_builder_adapters` | filter | ลงทะเบียน adapter สำหรับ Course Builder รุ่นอื่น |
| `tlcd_skip_when_pro_active` | filter | คืน `false` เพื่อให้แสดงปุ่มแม้มี Tutor LMS Pro |
| `tlcd_user_can_manage` | filter | เขียนกฎสิทธิ์เอง |
| `tlcd_runtime_issues` | filter | เพิ่ม/ลดเงื่อนไขที่ถือว่าโครงสร้าง Tutor LMS ไม่เข้ากัน |
| `tlcd_fire_tutor_created_hooks` | filter | คืน `true` เพื่อยิง `tutor/lesson/created` หลังสร้างสำเนา |
| `tlcd_content_postarr` | filter | แก้ข้อมูลโพสต์ก่อนสร้างสำเนาเนื้อหาทุกชนิด |
| `tlcd/content/duplicated` | action | `( $new_id, $source_id, $topic_id, $type )` — ทุกชนิด |
| `tlcd/lesson/duplicated` | action | `( $new_id, $source_id, $topic_id )` |
| `tlcd/quiz/duplicated` | action | `( $new_id, $source_id, $topic_id )` |
| `tlcd/quiz/questions_copied` | action | `( $new_id, $source_id, $question_id_map )` |
| `tlcd/assignment/duplicated` | action | `( $new_id, $source_id, $topic_id )` |
| `tlcd/topic/duplicated` | action | `( $new_topic_id, $source_id, $course_id )` |
| `tlcd/topic/rollback` | action | `( $created_ids )` |
| `tlcd/log/error` | action | `( $context, $detail, $data )` — ส่งรายละเอียดภายในไปยังระบบ logging โดย REST response ไม่เปิดเผย exception |

ตัวอย่าง — คงผู้เขียนเดิมไว้แทนการเปลี่ยนเป็นผู้ที่กด Duplicate:

```php
add_filter( 'tlcd_duplicate_author', function ( $author, $source ) {
	return (int) $source->post_author;
}, 10, 2 );
```

---

## 6. ติดตั้ง

1. คัดลอกโฟลเดอร์นี้ไปที่ `wp-content/plugins/tutor-lms-curriculum-duplicator/`
   หรือบีบอัดเป็น ZIP แล้วอัปโหลดผ่าน Plugins → Add New → Upload
2. เปิดใช้งานปลั๊กอิน (Tutor LMS ต้องเปิดใช้งานอยู่ก่อน)
3. เข้า Course Builder → แท็บ Curriculum → ปุ่มทำสำเนาจะอยู่ระหว่างปุ่มแก้ไขและปุ่มลบ

หากเวอร์ชัน Tutor LMS ต่ำกว่า 3.0.0 ปลั๊กอินจะไม่โหลดส่วน UI และจะขึ้น admin notice แจ้งเตือน

---

## 7. ภาษาและการแปล

ข้อความต้นทางในโค้ดเป็น **ภาษาอังกฤษ** ตามมาตรฐานของ WordPress ส่วนภาษาไทยมาจากไฟล์แปล
เว็บที่ตั้งค่าภาษาไทยจะเห็นข้อความไทยอัตโนมัติ เว็บภาษาอื่นเห็นภาษาอังกฤษ

```
languages/
├── tutor-lms-curriculum-duplicator.pot      แม่แบบสำหรับแปลภาษาอื่น (68 ข้อความ)
├── tutor-lms-curriculum-duplicator-th.po    คำแปลภาษาไทย (แก้ไขที่นี่)
└── tutor-lms-curriculum-duplicator-th.mo    ไฟล์ที่ WordPress อ่านจริง (คอมไพล์จาก .po)
```

ข้อความต่อท้ายชื่อสำเนาก็แปลตามภาษาด้วย — เว็บอังกฤษได้ `บทเรียน – Copy`
เว็บไทยได้ `บทเรียน – สำเนา` และ `Title_Generator` ตัด suffix ของภาษาที่ใช้อยู่ออกได้ถูกต้อง
จึงไม่เกิด `– สำเนา – สำเนา`

**แก้คำแปล**

```bash
# 1. แก้ languages/tutor-lms-curriculum-duplicator-th.po
# 2. คอมไพล์ใหม่ (ไม่ต้องมี gettext ติดตั้ง)
python3 bin/compile-mo.py languages/tutor-lms-curriculum-duplicator-th.po
```

**เพิ่ม/แก้ข้อความในโค้ด**

```bash
python3 bin/make-pot.py     # อัปเดต .pot จากซอร์ส
# แล้วเติมคำแปลใน .po และคอมไพล์ .mo ใหม่
```

CI มี job `i18n` ที่ตรวจว่า `.pot` ตรงกับซอร์สและ `.mo` ตรงกับ `.po` เสมอ
ถ้าเพิ่มข้อความใหม่แล้วลืมแปล ชุดทดสอบ `test-i18n.php` จะฟ้อง

**เพิ่มภาษาอื่น** — คัดลอก `.pot` เป็น `{textdomain}-{locale}.po` เช่น `-ja.po`
แล้วรัน `bin/compile-mo.py` (รหัสภาษาดูจาก [WordPress locale list](https://translate.wordpress.org/))

---

## 8. ชุดทดสอบและ CI

```bash
composer install
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest

composer run test:unit          # ไม่ต้องมี Tutor LMS (ใช้ post type จำลอง)
composer run test:integration   # ต้องตั้ง TUTOR_PLUGIN_FILE ชี้ไปที่ tutor.php
composer run lint               # WordPress Coding Standards
```

```bash
npm install
npm test                        # ชุดทดสอบ JavaScript บน DOM จำลองของ Course Builder
npm run lint                    # ESLint
npm run test:e2e:list           # ตรวจว่า Playwright suite โหลดได้
```

Playwright E2E ใช้กับเว็บไซต์ staging ที่ติดตั้ง Tutor LMS จริง:

```bash
export TLCD_E2E_BASE_URL='https://staging.example.com'
export TLCD_E2E_USERNAME='admin'
export TLCD_E2E_PASSWORD='...'
export TLCD_E2E_COURSE_ID='123'
npx playwright install chromium
npm run test:e2e
```

ชุดแรกตรวจการแทรกปุ่มโดยไม่แก้ข้อมูล ส่วนกรณีทำสำเนาจริงจะถูก skip จนกว่าจะตั้ง
`TLCD_E2E_ALLOW_MUTATION=1` และควรใช้เฉพาะคอร์ส staging ที่ลบทิ้งได้

| ไฟล์ | ครอบคลุม |
| --- | --- |
| `tests/test-lesson-duplicator.php` | TC-01, TC-02, TC-08 + meta allowlist/blocklist |
| `tests/test-quiz-duplicator.php` | คำถาม/ตัวเลือกของแบบทดสอบ, งานที่มอบหมาย, factory |
| `tests/test-topic-duplicator.php` | TC-03, TC-04, TC-07 (rollback) |
| `tests/test-permissions.php` | TC-05, TC-06 + REST endpoint ทุกเส้น |
| `tests/test-curriculum-order.php` | `menu_order`, การแทรกตำแหน่ง, การตั้งชื่อสำเนา |
| `tests/test-meta-copier.php` | allowlist/blocklist ทั้งสองโหมด, อักขระพิเศษ, meta หลายค่า |
| `tests/test-compatibility.php` | การตรวจเวอร์ชัน, runtime guard, ความสอดคล้องของเลขเวอร์ชัน |
| `tests/test-i18n.php` | text domain, ไฟล์ .mo โหลดได้จริง, คำแปลครบ, placeholder ตรงกัน |
| `tests/js/*.test.mjs` | การจับคู่แถวใน DOM, การกดปุ่ม, การกันกดซ้ำ, XSS |
| `tests/e2e/course-builder.spec.mjs` | Course Builder จริง: ปุ่ม Duplicate และการทำสำเนาแบบ opt-in |
| `tests/class-tlcd-seeder.php` | ชุดข้อมูลจำลอง — คอร์ส, หัวข้อว่าง, เนื้อหาผสม, ผู้สอน, ความก้าวหน้าผู้เรียน |

ชุดทดสอบ JavaScript ใช้ jsdom สร้าง DOM ที่ลอกโครงสร้างจาก `TopicHeader.tsx` และ
`TopicContent.tsx` ของ Tutor LMS 4.x จึงจับกรณีที่อันตรายที่สุดได้ —
แถวใน DOM ไม่ตรงกับข้อมูลแล้วปุ่มไปผูกกับ ID ของรายการอื่น

GitHub Actions (`.github/workflows/ci.yml`) รัน 6 งาน

1. **lint** — `php -l` ทุกไฟล์ + PHPCS บน PHP 7.4 และ 8.3
2. **javascript** — `node --check` + ESLint + ชุดทดสอบ jsdom + ตรวจการโหลด Playwright suite
3. **i18n** — ตรวจว่า `.pot` ตรงกับซอร์ส และ `.mo` ตรงกับ `.po`
4. **unit** — matrix PHP 7.4/8.1/8.3 × WP 6.4/latest/trunk
5. **integration** — ติดตั้ง Tutor LMS จริงจาก wordpress.org (3.7.0, 4.0.2 และ trunk)
   เพื่อจับกรณีที่ Tutor เปลี่ยน post type, meta key หรือ `can_user_manage()`
6. **build** — สร้าง ZIP เป็น artifact เมื่อทุกอย่างผ่าน

งาน integration คือด่านที่จะเตือนล่วงหน้าเมื่อ Tutor LMS ออกรุ่นใหม่ที่ทำให้สมมติฐานของ
ปลั๊กอินไม่จริงอีกต่อไป — สำคัญกว่าการเช็กเลขเวอร์ชันในโค้ด

---

## 9. Checklist ก่อนขึ้น Production

ชุดทดสอบอัตโนมัติครอบคลุมตรรกะฝั่งเซิร์ฟเวอร์แล้ว แต่ส่วน UI ยังต้องตรวจด้วยมือ
กรุณาทดสอบบน Staging และสำรองฐานข้อมูลก่อน

**สิ่งที่ต้องตรวจด้วยมือ (ชุดทดสอบครอบคลุมไม่ได้)**

- [ ] ปุ่มปรากฏในแถวหัวข้อและแถวเนื้อหาทุกชนิดที่รองรับ
- [ ] Backend Course Builder และ Frontend Course Builder
- [ ] กดปุ่มรัว ๆ → ได้สำเนาเดียว ปุ่มถูก disable ระหว่างประมวลผล
- [ ] หลังทำสำเนา รายการใหม่ปรากฏโดยไม่ต้องโหลดหน้าใหม่
- [ ] เปิดหลายแท็บพร้อมกันแล้วทำสำเนาสลับกัน
- [ ] วิดีโอในบทเรียนสำเนาเล่นได้ ไฟล์แนบเปิดได้
- [ ] บทเรียนที่สร้างด้วย Block Editor / Classic / Elementor / Divi
- [ ] ปุ่มไม่แสดงกับ Student
- [ ] ไม่มี PHP warning ใน `debug.log` (เปิด `WP_DEBUG`) และไม่มี error ใน console

**การ pin เวอร์ชัน**

- [ ] ติดตั้ง Tutor LMS เวอร์ชันที่ pin ไว้ (4.0.x) → ไม่มี admin notice เรื่องเวอร์ชัน
- [ ] อัปเดตเป็น 4.1.x หรือสูงกว่า → ขึ้น notice สีเหลือง และปุ่มยังใช้งานได้
- [ ] ลองปิด Tutor LMS → ขึ้น notice และไม่เกิด fatal error
- [ ] จำลอง post type หาย (`add_filter( 'tlcd_runtime_issues', ... )`) → REST ตอบ 409

---

## 10. ข้อจำกัดที่รู้อยู่แล้ว

1. **การจับคู่แถวใน DOM ใช้ลำดับ** เพราะ Course Builder ไม่ได้ใส่ post ID ลงใน DOM
   ปลั๊กอินตรวจทาน 3 ชั้น: จำนวนหัวข้อ, จำนวนเนื้อหาในแต่ละหัวข้อ และ**ชื่อของแต่ละแถว**
   ถ้าชั้นใดไม่ตรงจะดึงข้อมูลใหม่ และหยุดแทรกปุ่มพร้อมเสนอให้โหลดหน้าใหม่
   (ยอมไม่มีปุ่ม ดีกว่าทำสำเนาผิดรายการ)
2. **การรีเฟรชหน้าจอ** ใช้วิธียิง event `visibilitychange` เพื่อให้ React Query ดึงข้อมูลใหม่
   ถ้าไม่ได้ผลภายใน 6 วินาที จะแสดงปุ่ม "โหลดหน้าใหม่" แทนการรีโหลดเอง
3. **Zoom / Google Meet / Interactive Quiz (H5P) ยังไม่รองรับ** จะถูกข้ามพร้อมรายงานกลับ
4. **หัวข้อขนาดใหญ่เกิน 200 รายการ** ถูกปฏิเสธแทนการเสี่ยงให้คำขอตายกลางทาง
   ปรับได้ผ่าน filter `tlcd_max_topic_children`
5. **E2E ที่ทำสำเนาจริงต้องใช้ staging** — CI ตรวจว่า suite โหลดได้ แต่ไม่รับ credential
   หรือแก้ข้อมูลเว็บไซต์โดยอัตโนมัติ การทดสอบ mutation จึงเป็น opt-in

---

## 11. Roadmap

**v1.1** ✅ — Duplicate Quiz + Assignment, ชุดทดสอบอัตโนมัติ, CI
**v1.2** — Duplicate ข้าม Topic / ข้าม Course, popup เลือกปลายทาง, เลือกหลายรายการ,
เลือกประเภทเนื้อหาที่จะคัดลอก, รองรับ Zoom / Google Meet / H5P
**v1.3** — Bulk duplicate, Template Topic, ตั้งรูปแบบชื่อเอง, audit log, WP-CLI

---

## License

GPL-2.0-or-later
