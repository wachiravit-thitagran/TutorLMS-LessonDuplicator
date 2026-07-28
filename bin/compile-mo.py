#!/usr/bin/env python3
"""คอมไพล์ไฟล์ .po เป็น .mo โดยไม่ต้องพึ่ง gettext

รูปแบบไฟล์ MO เป็น binary ที่มีสเปกชัดเจน (GNU gettext manual, 9.3 Format of MO Files)
เขียนเองได้ตรง ๆ จึงไม่ต้องบังคับให้เครื่องพัฒนาหรือ CI ติดตั้ง msgfmt

    python3 bin/compile-mo.py languages/*.po
    python3 bin/compile-mo.py --check languages/*.po
"""

import io
import os
import re
import struct
import sys

MAGIC = 0x950412DE


def parse_po(path):
    """อ่านไฟล์ .po → dict ของ msgid -> msgstr (ข้ามรายการที่ยังไม่ได้แปลและ fuzzy)"""
    entries = {}

    msgid = None
    msgstr = None
    ctxt = None
    target = None
    fuzzy = False
    pending_fuzzy = False

    def flush():
        if msgid is None or msgstr is None:
            return
        if fuzzy:
            return
        # msgid ว่างคือ header — เก็บไว้ด้วยเพราะ gettext ต้องใช้
        if msgid == '' or msgstr != '':
            key = (ctxt + '\x04' + msgid) if ctxt else msgid
            entries[key] = msgstr

    for raw in io.open(path, encoding='utf-8'):
        line = raw.rstrip('\n')

        if line.startswith('#,'):
            if 'fuzzy' in line:
                pending_fuzzy = True
            continue

        if line.startswith('#') or not line.strip():
            if not line.strip():
                flush()
                msgid = msgstr = ctxt = target = None
                fuzzy = pending_fuzzy = False
            continue

        match = re.match(r'^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?)\s+"(.*)"$', line)
        if match:
            keyword, value = match.group(1), unescape(match.group(2))

            if keyword == 'msgctxt':
                flush()
                msgid = msgstr = None
                ctxt = value
                fuzzy = pending_fuzzy
                pending_fuzzy = False
                target = 'ctxt'
            elif keyword == 'msgid':
                flush()
                msgid = value
                msgstr = None
                if target != 'ctxt':
                    ctxt = None
                    fuzzy = pending_fuzzy
                    pending_fuzzy = False
                target = 'msgid'
            elif keyword == 'msgid_plural':
                target = 'plural'
            elif keyword.startswith('msgstr'):
                if keyword == 'msgstr' or keyword == 'msgstr[0]':
                    msgstr = value
                    target = 'msgstr'
                else:
                    target = 'ignore'
            continue

        continuation = re.match(r'^"(.*)"$', line.strip())
        if continuation:
            value = unescape(continuation.group(1))
            if target == 'msgid':
                msgid = (msgid or '') + value
            elif target == 'msgstr':
                msgstr = (msgstr or '') + value
            elif target == 'ctxt':
                ctxt = (ctxt or '') + value

    flush()

    return entries


def unescape(value):
    return (
        value.replace('\\n', '\n')
        .replace('\\t', '\t')
        .replace('\\"', '"')
        .replace('\\\\', '\\')
    )


def build_mo(entries):
    """สร้าง binary ของไฟล์ .mo"""
    keys = sorted(entries.keys())

    ids = b''
    strs = b''
    offsets = []

    for key in keys:
        value = entries[key].encode('utf-8')
        key_bytes = key.encode('utf-8')
        offsets.append((len(ids), len(key_bytes), len(strs), len(value)))
        ids += key_bytes + b'\x00'
        strs += value + b'\x00'

    count = len(keys)
    key_table_offset = 7 * 4
    value_table_offset = key_table_offset + count * 8
    ids_offset = value_table_offset + count * 8
    strs_offset = ids_offset + len(ids)

    key_table = b''
    value_table = b''
    for id_off, id_len, str_off, str_len in offsets:
        key_table += struct.pack('<II', id_len, ids_offset + id_off)
        value_table += struct.pack('<II', str_len, strs_offset + str_off)

    header = struct.pack(
        '<IIIIIII',
        MAGIC,
        0,                    # revision
        count,
        key_table_offset,
        value_table_offset,
        0,                    # hash table size
        ids_offset,           # hash table offset (end of index tables when size is 0)
    )

    return header + key_table + value_table + ids + strs


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    check_only = '--check' in sys.argv

    if not args:
        print('ใช้: python3 bin/compile-mo.py [--check] <ไฟล์.po> ...')
        return 1

    failed = 0

    for po_path in args:
        if not os.path.exists(po_path):
            print('ไม่พบไฟล์: %s' % po_path)
            failed += 1
            continue

        entries = parse_po(po_path)
        data = build_mo(entries)
        mo_path = po_path[:-3] + '.mo'

        translated = len([k for k in entries if k])

        if check_only:
            current = open(mo_path, 'rb').read() if os.path.exists(mo_path) else b''
            if current != data:
                print('%s ไม่ตรงกับ %s — รัน `python3 bin/compile-mo.py %s`' % (mo_path, po_path, po_path))
                failed += 1
            else:
                print('%s ตรงกับไฟล์ .po (%d ข้อความ)' % (mo_path, translated))
            continue

        open(mo_path, 'wb').write(data)
        print('เขียน %s แล้ว (%d ข้อความ)' % (mo_path, translated))

    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
