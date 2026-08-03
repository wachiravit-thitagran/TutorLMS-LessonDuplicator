#!/usr/bin/env bash
#
# สร้างไฟล์ ZIP สำหรับติดตั้งใน WordPress
#
# รวมเฉพาะไฟล์ที่ต้องใช้ตอนรันจริง ไม่รวมชุดทดสอบ เครื่องมือพัฒนา และ CI
#
# ZIP มีโฟลเดอร์ระดับบนสุดเพียงโฟลเดอร์เดียว ชื่อ tutor-lms-curriculum-duplicator
# ซึ่งเป็น slug ที่ WordPress ติดตั้งปลั๊กอินนี้ลงไป หลักฐานอยู่ในตัวรีโปเอง ไม่ใช่
# ชื่อรีโป: ไฟล์หลักคือ tutor-lms-curriculum-duplicator.php, text domain คือ
# tutor-lms-curriculum-duplicator และ Plugin::load_textdomain() โหลดคำแปลจาก
# dirname( TLCD_BASENAME ) . '/languages' — ถ้าโฟลเดอร์บนสุดชื่ออื่น ปุ่ม
# "แทนที่ด้วยไฟล์ที่อัปโหลด" จะได้โฟลเดอร์ใหม่วางซ้อนของเดิม และคำแปลจะหาไม่เจอ
#
# vendor/ ไม่เคยถูกแพ็กลงไป: composer.json ต้องการแค่ php >= 7.4 ตอนรันจริง
# แพ็กเกจทุกตัวอยู่ใน require-dev (phpunit, phpcs, wpcs, polyfills) และไฟล์หลัก
# ลงทะเบียน spl_autoload_register() ของตัวเอง ไม่มีที่ไหนเรียก vendor/autoload.php
# เลย — ไม่มี dependency ตอนรันให้แพ็ก และเครื่องมือพัฒนาไม่ควรไปอยู่บนเว็บจริง
#
# languages/*.mo ถูก commit ไว้ในรีโป (CI มี job ตรวจว่า .mo ตรงกับ .po เสมอ)
# สคริปต์นี้จึงคัดลอกไปตรง ๆ แต่จะยืนยันว่ามี .mo ติดไปด้วยจริง ไม่ใช่ปล่อยให้
# ปลั๊กอินไปโผล่บนเว็บจริงแบบไม่มีคำแปล
#
# ใช้: bash bin/build-zip.sh
# ผลลัพธ์: dist/tutor-lms-curriculum-duplicator.zip
#
set -euo pipefail

SLUG="tutor-lms-curriculum-duplicator"
MAIN_FILE="${SLUG}.php"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="${ROOT}/.build"
DIST="${ROOT}/dist"

cd "${ROOT}"

if [ ! -f "${MAIN_FILE}" ]; then
	echo "ไม่พบ ${MAIN_FILE} — นี่ใช่รีโปของปลั๊กอินหรือเปล่า?" >&2
	exit 1
fi

# อ่านค่า Version จาก header ทั้งค่า แล้วตรวจว่าเป็น semver
#
# ถ้าใช้ grep แบบ "ตัวเลขสามชุด" ค่า 1.1.2-dev จะกลายเป็น 1.1.2 เงียบ ๆ แล้ว ZIP
# จะอ้างว่าเป็น release build ทั้งที่ไม่ใช่ — ที่นี่จึงตรวจสตริงทั้งค่าแล้วส่งต่อ
# โดยไม่ตัดอะไรทิ้ง สำเนางานที่ยังไม่ stamp เวอร์ชันจะมองเห็นได้จากผลลัพธ์
VERSION=$(grep -iE '^[[:space:]]*\*[[:space:]]*Version:' "${MAIN_FILE}" | head -1 | sed -E 's/.*[Vv]ersion:[[:space:]]*//' | tr -d '[:space:]')

if ! printf '%s' "${VERSION}" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'; then
	echo "อ่านค่า Version แบบ semver จาก ${MAIN_FILE} ไม่ได้ (ได้ '${VERSION}')" >&2
	exit 1
fi

case "${VERSION}" in
	*-*|*+*)
		echo "หมายเหตุ: header เป็น pre-release — release build จะถูก stamp เวอร์ชันก่อนเสมอ" >&2
		;;
esac

# TLCD_VERSION ใช้ cache-bust ไฟล์ CSS/JS ถ้าไม่ตรงกับ header เว็บจะโหลด asset
# ของเวอร์ชันที่ตัวเองไม่ได้รันอยู่
CONSTANT=$(grep -E "'TLCD_VERSION'" "${MAIN_FILE}" | head -1 | sed -E "s/.*'TLCD_VERSION',[[:space:]]*'([^']*)'.*/\1/")

if [ "${CONSTANT}" != "${VERSION}" ]; then
	echo "TLCD_VERSION เป็น '${CONSTANT}' แต่ header เป็น '${VERSION}'" >&2
	exit 1
fi

# ค่าที่ไม่เกี่ยวกับเวอร์ชันปลั๊กอิน — อ่านไว้เทียบกับใน ZIP ทีหลัง เพื่อพิสูจน์ว่า
# sed ที่ stamp เวอร์ชันไม่ได้ไปแตะค่าเหล่านี้
MIN_TUTOR=$(grep -E "'TLCD_MIN_TUTOR_VERSION'" "${MAIN_FILE}" | head -1 | sed -E "s/.*'TLCD_MIN_TUTOR_VERSION',[[:space:]]*'([^']*)'.*/\1/")
TESTED_TUTOR=$(grep -E "'TLCD_TESTED_TUTOR_VERSION'" "${MAIN_FILE}" | head -1 | sed -E "s/.*'TLCD_TESTED_TUTOR_VERSION',[[:space:]]*'([^']*)'.*/\1/")
TESTED_BRANCH=$(grep -E "'TLCD_TESTED_TUTOR_BRANCH'" "${MAIN_FILE}" | head -1 | sed -E "s/.*'TLCD_TESTED_TUTOR_BRANCH',[[:space:]]*'([^']*)'.*/\1/")

for pair in "TLCD_MIN_TUTOR_VERSION=${MIN_TUTOR}" "TLCD_TESTED_TUTOR_VERSION=${TESTED_TUTOR}" "TLCD_TESTED_TUTOR_BRANCH=${TESTED_BRANCH}"; do
	if [ -z "${pair#*=}" ]; then
		echo "อ่านค่า ${pair%%=*} จาก ${MAIN_FILE} ไม่ได้" >&2
		exit 1
	fi
done

echo "สร้าง ${SLUG} ${VERSION}"

rm -rf "${BUILD}"
mkdir -p "${BUILD}/${SLUG}" "${DIST}"

# ไฟล์และโฟลเดอร์ที่ต้องอยู่ในแพ็กเกจ — ทุกรายการเป็นของจำเป็น ไม่ใช่ทางเลือก
#
# เดิมสคริปต์แค่พิมพ์ "ข้าม (ไม่พบ)" แล้วไปต่อ ซึ่งทำให้ ZIP ที่ขาด readme.txt หรือ
# ขาด languages/ ทั้งโฟลเดอร์ ยัง exit 0 ได้ — CI จะเห็นว่าผ่านและปล่อยของเสียออกไป
INCLUDE=(
	"${MAIN_FILE}"
	"uninstall.php"
	"readme.txt"
	"includes"
	"assets"
	"languages"
)

for item in "${INCLUDE[@]}"; do
	if [ ! -e "${ROOT}/${item}" ]; then
		echo "ไม่พบสิ่งที่แพ็กเกจต้องมี: ${item}" >&2
		exit 1
	fi

	cp -R "${ROOT}/${item}" "${BUILD}/${SLUG}/"
done

# กันไฟล์ที่ไม่ควรหลุดเข้าไป
find "${BUILD}" -depth \( -name '.DS_Store' -o -name '*.map' -o -name 'Thumbs.db' -o -name '__pycache__' -o -name '*.pyc' \) -exec rm -rf {} +

rm -f "${DIST}/${SLUG}.zip"

cd "${BUILD}"
zip -rq "${DIST}/${SLUG}.zip" "${SLUG}" -x '*.DS_Store'
cd "${ROOT}"

ZIP="${DIST}/${SLUG}.zip"

echo "ตรวจไฟล์ ZIP..."

# แจงรายการครั้งเดียว: ถ้า pipe เข้า `grep -q` จะยิง SIGPIPE ใส่ unzip แล้วชน pipefail
entries=$(unzip -Z1 "${ZIP}")

# โฟลเดอร์บนสุดต้องมีอันเดียว และต้องเป็น slug ที่ติดตั้ง
top_level=$(awk -F/ 'NF > 0 { print $1 }' <<<"${entries}" | sort -u)

if [ "${top_level}" != "${SLUG}" ]; then
	echo "ZIP ต้องมีโฟลเดอร์บนสุดอันเดียวชื่อ ${SLUG} แต่พบ:" >&2
	printf '%s\n' "${top_level}" >&2
	exit 1
fi

# INCLUDE เป็น allowlist อยู่แล้ว แต่ของพัฒนาที่เผลอไปวางไว้ใน includes/ assets/
# หรือ languages/ ก็ยังตามมาได้ ตรวจซ้ำอีกชั้นก่อนขึ้นเว็บจริง
#
# จับได้ทุกความลึก ไม่ใช่แค่ระดับบนสุด: includes/node_modules/ ก็คือ node_modules
# ที่หลุดขึ้นเว็บจริงเหมือนกัน
if grep -E "(^|/)(tests|bin|docs|build|\.build|dist|vendor|node_modules|\.git|\.github)/|(^|/)(composer\.(json|lock)|package\.json|package-lock\.json|phpunit[^/]*\.xml[^/]*|phpcs[^/]*\.xml[^/]*|eslint\.config\.mjs|playwright\.config\.mjs|[^/]*\.md|[^/]*\.map|\.git[^/]*|\.DS_Store)$" <<<"${entries}"; then
	echo "รายการข้างบนไม่ควรอยู่ใน build สำหรับใช้งานจริง" >&2
	exit 1
fi

# สิ่งที่เว็บจริงต้องได้รับ
for required in \
	"${SLUG}/${MAIN_FILE}" \
	"${SLUG}/uninstall.php" \
	"${SLUG}/readme.txt" \
	"${SLUG}/includes/class-plugin.php" \
	"${SLUG}/includes/class-compatibility.php" \
	"${SLUG}/includes/api/class-content-controller.php" \
	"${SLUG}/includes/integrations/class-react-builder-adapter.php" \
	"${SLUG}/includes/services/class-lesson-duplicator.php" \
	"${SLUG}/assets/css/curriculum-duplicator.css" \
	"${SLUG}/assets/js/curriculum-duplicator.js" \
	"${SLUG}/languages/${SLUG}-th.mo"; do
	if ! grep -qxF "${required}" <<<"${entries}"; then
		echo "ZIP ขาด: ${required}" >&2
		exit 1
	fi
done

# WordPress อ่านเฉพาะ .mo — ไฟล์เปล่าเท่ากับไม่มีคำแปล
mo_size=$(unzip -p "${ZIP}" "${SLUG}/languages/${SLUG}-th.mo" | wc -c | tr -d ' ')

if [ "${mo_size}" -lt 1024 ]; then
	echo "ไฟล์คำแปล ${SLUG}-th.mo ใน ZIP มีขนาด ${mo_size} ไบต์ ซึ่งเล็กผิดปกติ" >&2
	exit 1
fi

# ZIP ต้องมีเวอร์ชันเดียวกับที่ header อ้าง
zipped_header=$(unzip -p "${ZIP}" "${SLUG}/${MAIN_FILE}")

assert_zipped() {
	local label="$1" expected="$2" found="$3"

	if [ "${found}" != "${expected}" ]; then
		echo "ใน ZIP ${label} เป็น '${found}' แต่ต้องเป็น '${expected}'" >&2
		exit 1
	fi

	echo "  ${label}: ${found}"
}

read_constant() {
	grep -E "'$1'" <<<"${zipped_header}" | head -1 | sed -E "s/.*'$1',[[:space:]]*'([^']*)'.*/\1/"
}

assert_zipped 'Version header' "${VERSION}" \
	"$(grep -iE '^[[:space:]]*\*[[:space:]]*Version:' <<<"${zipped_header}" | head -1 | sed -E 's/.*[Vv]ersion:[[:space:]]*//' | tr -d '[:space:]')"
assert_zipped 'TLCD_VERSION' "${VERSION}" "$(read_constant TLCD_VERSION)"

# ค่าความเข้ากันได้กับ Tutor LMS ไม่ใช่เวอร์ชันของปลั๊กอิน ห้ามถูก stamp ทับ
assert_zipped 'TLCD_MIN_TUTOR_VERSION' "${MIN_TUTOR}" "$(read_constant TLCD_MIN_TUTOR_VERSION)"
assert_zipped 'TLCD_TESTED_TUTOR_VERSION' "${TESTED_TUTOR}" "$(read_constant TLCD_TESTED_TUTOR_VERSION)"
assert_zipped 'TLCD_TESTED_TUTOR_BRANCH' "${TESTED_BRANCH}" "$(read_constant TLCD_TESTED_TUTOR_BRANCH)"

rm -rf "${BUILD}"

echo
echo "สร้างแล้ว: dist/${SLUG}.zip ($(du -h "${ZIP}" | cut -f1), $(wc -l <<<"${entries}" | tr -d ' ') รายการ, เวอร์ชัน ${VERSION})"
