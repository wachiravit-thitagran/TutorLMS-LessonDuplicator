#!/usr/bin/env bash
#
# สร้างไฟล์ ZIP สำหรับติดตั้งใน WordPress
#
# รวมเฉพาะไฟล์ที่ต้องใช้ตอนรันจริง ไม่รวมชุดทดสอบ เครื่องมือพัฒนา และ CI
#
set -euo pipefail

SLUG="tutor-lms-curriculum-duplicator"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="${ROOT}/.build"
DIST="${ROOT}/dist"

rm -rf "${BUILD}"
mkdir -p "${BUILD}/${SLUG}" "${DIST}"

# ไฟล์และโฟลเดอร์ที่ต้องอยู่ในแพ็กเกจ
INCLUDE=(
	"${SLUG}.php"
	"uninstall.php"
	"readme.txt"
	"includes"
	"assets"
	"languages"
)

for item in "${INCLUDE[@]}"; do
	if [ -e "${ROOT}/${item}" ]; then
		cp -R "${ROOT}/${item}" "${BUILD}/${SLUG}/"
	else
		echo "ข้าม (ไม่พบ): ${item}"
	fi
done

# กันไฟล์ที่ไม่ควรหลุดเข้าไป
find "${BUILD}" \( -name '.DS_Store' -o -name '*.map' -o -name 'Thumbs.db' \) -delete

rm -f "${DIST}/${SLUG}.zip"

cd "${BUILD}"
zip -rq "${DIST}/${SLUG}.zip" "${SLUG}"
cd "${ROOT}"

rm -rf "${BUILD}"

echo "สร้างแล้ว: dist/${SLUG}.zip"
unzip -l "${DIST}/${SLUG}.zip" | tail -n 3
