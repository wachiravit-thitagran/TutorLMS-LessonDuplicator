#!/usr/bin/env python3
"""สร้างไฟล์ .pot จากซอร์สของปลั๊กอิน

ใช้ Python เพราะเครื่องที่รัน CI ไม่ได้มี WP-CLI หรือ gettext ติดตั้งเสมอไป
และเราต้องการให้ตรวจสอบใน CI ได้ว่า .pot ตรงกับซอร์สจริง

    python3 bin/make-pot.py            เขียนไฟล์ .pot ใหม่
    python3 bin/make-pot.py --check    ตรวจอย่างเดียว (exit 1 ถ้าไม่ตรง)
"""

import collections
import io
import os
import re
import sys

TEXT_DOMAIN = 'tutor-lms-curriculum-duplicator'
POT_PATH = os.path.join('languages', TEXT_DOMAIN + '.pot')
SKIP_DIRS = {'.git', 'node_modules', 'vendor', 'dist', '.build', 'tests', 'bin', '.github', 'languages'}

# จับทั้ง msgid ปกติและ context ของ _x()
CALL = re.compile(
    r"\b(esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e|_x|_ex|_n|_nx)\(\s*"
    r"'((?:[^'\\]|\\.)*)'"
    r"(?:\s*,\s*'((?:[^'\\]|\\.)*)')?"
)

# ดึงคอมเมนต์สำหรับนักแปลที่อยู่บรรทัดก่อนหน้า
TRANSLATOR_COMMENT = re.compile(r'/\*\s*(translators:.*?)\s*\*/', re.IGNORECASE | re.DOTALL)


def source_files(root='.'):
    found = []
    for base, dirs, files in os.walk(root):
        dirs[:] = [d for d in dirs if d not in SKIP_DIRS]
        for name in files:
            if name.endswith('.php'):
                found.append(os.path.join(base, name).replace('./', '').replace(os.sep, '/'))
    return sorted(found)


def escape(value):
    return value.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


def collect(root='.'):
    """คืน OrderedDict: msgid -> {'refs': [...], 'comments': [...], 'plural': str|None}"""
    entries = collections.OrderedDict()

    for path in source_files(root):
        text = io.open(path, encoding='utf-8').read()
        lines = text.split('\n')

        for index, line in enumerate(lines, 1):
            for match in CALL.finditer(line):
                func, first, second = match.group(1), match.group(2), match.group(3)

                if func in ('_x', '_ex'):
                    # _x( 'text', 'context', 'domain' ) — msgid คือพารามิเตอร์แรก
                    msgid = first
                elif func in ('_n', '_nx'):
                    msgid = first
                else:
                    msgid = first

                if not msgid.strip():
                    continue

                entry = entries.setdefault(
                    msgid,
                    {'refs': [], 'comments': [], 'plural': None},
                )
                entry['refs'].append('%s:%d' % (path, index))

                if func in ('_n', '_nx') and second:
                    entry['plural'] = second

                # มองย้อนขึ้นไปไม่เกิน 3 บรรทัดเพื่อหา translators comment
                window = '\n'.join(lines[max(0, index - 4):index])
                for comment in TRANSLATOR_COMMENT.findall(window):
                    cleaned = ' '.join(comment.split())
                    if cleaned not in entry['comments']:
                        entry['comments'].append(cleaned)

    return entries


def render(entries, version='1.1.2'):
    out = [
        '# Copyright (C) 2026 Wachiravit',
        '# This file is distributed under the GPL-2.0-or-later license.',
        '#',
        '# ข้อความต้นทางเป็นภาษาอังกฤษตามมาตรฐานของ WordPress',
        '# คำแปลภาษาไทยอยู่ที่ languages/%s-th.po' % TEXT_DOMAIN,
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: Tutor LMS Curriculum Duplicator %s\\n"' % version,
        '"Report-Msgid-Bugs-To: https://github.com/wachiravit/TutorLMS-LessonDuplicator/issues\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"X-Domain: %s\\n"' % TEXT_DOMAIN,
        '',
    ]

    for msgid, data in entries.items():
        out.append('')
        for comment in data['comments']:
            out.append('#. %s' % comment)
        for ref in data['refs']:
            out.append('#: %s' % ref)
        out.append('msgid "%s"' % escape(msgid))
        if data['plural']:
            out.append('msgid_plural "%s"' % escape(data['plural']))
            out.append('msgstr[0] ""')
            out.append('msgstr[1] ""')
        else:
            out.append('msgstr ""')

    return '\n'.join(out) + '\n'


def main():
    check_only = '--check' in sys.argv

    entries = collect()
    rendered = render(entries)

    existing = io.open(POT_PATH, encoding='utf-8').read() if os.path.exists(POT_PATH) else ''

    def strip_dates(text):
        return '\n'.join(
            line for line in text.split('\n') if not line.startswith('"POT-Creation-Date')
        )

    if check_only:
        if strip_dates(existing) != strip_dates(rendered):
            print('.pot ไม่ตรงกับซอร์ส — รัน `python3 bin/make-pot.py` แล้ว commit ใหม่')
            return 1
        print('.pot ตรงกับซอร์ส (%d ข้อความ)' % len(entries))
        return 0

    io.open(POT_PATH, 'w', encoding='utf-8').write(rendered)
    print('เขียน %s แล้ว (%d ข้อความ)' % (POT_PATH, len(entries)))
    return 0


if __name__ == '__main__':
    sys.exit(main())
