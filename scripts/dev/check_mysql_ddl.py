"""Dev-only static checks on MySQL DDL produced by scripts/dev/mysql-ddl.php (identifier length, FK index prefixes,
SET NULL on composite keys, defaults on TEXT/JSON, index width)."""
import re
import sys

sql = open(sys.argv[1] if len(sys.argv) > 1 else '/tmp/legacy-mysql.sql').read()
stmts = [s for s in sql.split(';\n') if s.strip()]
problems = 0


def report(*a):
    global problems
    problems += 1
    print(*a)


for m in re.finditer(r'(?:constraint|index|unique|key)\s+`([^`]+)`', sql, re.I):
    if len(m.group(1)) > 64:
        report('LONG IDENTIFIER', len(m.group(1)), m.group(1))

tables = {}
for s in stmts:
    m = re.match(r'\s*create table `(\w+)` \((.*)\)', s, re.S | re.I)
    if m:
        tables[m.group(1)] = {'ddl': m.group(2), 'idx': []}
        pk = re.search(r'primary key \(([^)]*)\)', m.group(2), re.I)
        if pk:
            tables[m.group(1)]['idx'].append(('primary', [c.strip(' `') for c in pk.group(1).split(',')]))
for s in stmts:
    m = re.match(r'\s*alter table `(\w+)` add (unique|index|primary key)\s*(?:`[^`]*`)?\s*(?:using \w+)?\s*\(([^)]*)\)', s, re.I)
    if m and m.group(1) in tables:
        tables[m.group(1)]['idx'].append((m.group(2).lower(), [c.strip(' `') for c in m.group(3).split(',')]))

coltypes, nullable = {}, {}
for t, v in tables.items():
    for m in re.finditer(r'`(\w+)` (\w+(?:\(\d+(?:,\s*\d+)?\))?)([^,]*)', v['ddl']):
        coltypes[(t, m.group(1))] = m.group(2).lower()
        nullable[(t, m.group(1))] = ' not null' not in m.group(3).lower()

for s in stmts:
    tm = re.match(r'\s*alter table `(\w+)`', s, re.I)
    for fk in re.finditer(r'foreign key \(([^)]*)\) references `(\w+)` \(([^)]*)\)([^;]*)', s, re.I):
        cols = [c.strip(' `') for c in fk.group(1).split(',')]
        ref, rcols, rest = fk.group(2), [c.strip(' `') for c in fk.group(3).split(',')], fk.group(4).lower()
        if 'on delete set null' in rest and tm:
            for c in cols:
                if not nullable.get((tm.group(1), c), True):
                    report('SET NULL ON NOT NULL COLUMN', tm.group(1), c, '->', ref)
        t = tables.get(ref)
        if not t:
            report('UNKNOWN REFERENCED TABLE', ref)
            continue
        if not any(ix[1][:len(rcols)] == rcols for ix in t['idx']):
            report('NO INDEX ON REFERENCED COLUMNS', ref, rcols)
        if tm:
            for c, rc in zip(cols, rcols):
                a, b = coltypes.get((tm.group(1), c)), coltypes.get((ref, rc))
                if a and b and a != b:
                    report('FK TYPE MISMATCH', tm.group(1), c, a, '->', ref, rc, b)

for s in stmts:
    for m in re.finditer(r'`(\w+)` (json|text|longtext|mediumtext|blob)( (?:not )?null)? default', s, re.I):
        report('DEFAULT ON', m.group(2), m.group(1))


def width(t, c):
    ty = coltypes.get((t, c), '?')
    m = re.match(r'(?:var)?char\((\d+)\)', ty)
    if m:
        return int(m.group(1)) * 4
    if ty in ('text', 'json', 'longtext', 'mediumtext', 'blob'):
        return 10 ** 6
    return 8


for t, v in tables.items():
    for kind, cols in v['idx']:
        if sum(width(t, c) for c in cols) > 3072:
            report('INDEX TOO WIDE/TEXT', t, kind, cols, [coltypes.get((t, c)) for c in cols])

print(f'{len(tables)} tables, {len(stmts)} statements, {problems} problems')
sys.exit(1 if problems else 0)
