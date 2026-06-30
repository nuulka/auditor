#!/usr/bin/env python3
import json, itertools, sys, time
from datetime import datetime, timedelta
from collections import defaultdict

try:
    import mysql.connector as db_driver
except ImportError:
    print(json.dumps({"error": "mysql.connector not installed"}, ensure_ascii=False))
    sys.exit(1)

try:
    sys.stdout.reconfigure(encoding='utf-8')
except AttributeError:
    pass

DB_SRC = dict(host='localhost', user='root', password='', database='revizor_db')
DB_OTS = dict(host='localhost', user='root', password='', database='ots')
DB_TGT = dict(host='localhost', user='root', password='', database='revizor_ai')

KEYWORD_CATS = {
    'rezsi': ['rezsi', 'víz', 'gáz', 'villany', 'áram', 'fűtés', 'közös költség', 'mérő', 'energia', 'távhő', 'üzemeltetés'],
    'adomány': ['adomány', 'felajánlás', 'támogatás', 'persely', 'gyűjtés', 'tized', 'misszió', 'alapítvány'],
    'bankdíj': ['bankdíj', 'számlavezetés', 'kezelési költség', 'jutalék', 'bankköltség', 'tranzakciós díj'],
    'bér': ['bér', 'munkabér', 'fizetés', 'járulék', 'tiszteletdíj', 'megbízási díj', 'személyi juttatás'],
}

EXPENSE_TYPES = [7, 9, 20]
MAX_DAYS = 65
TIME_BUDGET_TP = 180
MAX_CAND = 20
MAX_PER_TGT = 3
TIZED_WORDS = ['tized', 'T', 'adomány', 'adakozás', 'persely']

def log(msg):
    print(f"[{time.strftime('%H:%M:%S')}] {msg}", file=sys.stderr, flush=True)

def get_conn(cfg):
    cfg = cfg.copy()
    cfg['charset'] = 'utf8mb4'
    return db_driver.connect(**cfg)

def categorize(leiras):
    if not leiras:
        return []
    lo = leiras.lower()
    found = []
    for cat, kws in KEYWORD_CATS.items():
        for kw in kws:
            if kw in lo:
                found.append(cat)
                break
    return found

def is_blank(val):
    if val is None:
        return True
    s = str(val).strip()
    return s in ('', '0', '0000', 'r', 'R')

def word_overlap(a, b):
    if not a or not b:
        return 0
    sa = set(w.lower() for w in a.split() if len(w) >= 3)
    sb = set(w.lower() for w in b.split() if len(w) >= 3)
    return len(sa & sb) if sa and sb else 0

def is_person_name(name):
    if not name:
        return False
    parts = name.strip().split()
    if len(parts) < 2:
        return False
    company_indicators = ['kft', 'bt', 'zrt', 'nyrt', 'kht', 'egyház', 'gyülekezet',
                          'alapítvány', 'egyesület', 'szövetkezet', 'nonprofit', 'kkt']
    lo = name.lower()
    for ci in company_indicators:
        if ci in lo:
            return False
    return True

def tithe_score(descriptions, partner_names):
    score = 0
    all_desc = ' '.join(d for d in descriptions if d)
    all_partners = ' '.join(p for p in partner_names if p)
    combined = (all_desc + ' ' + all_partners).lower()
    for tw in TIZED_WORDS:
        if tw.lower() in combined:
            score += 2
    name_person = sum(1 for p in partner_names if p and is_person_name(p))
    if name_person >= 1:
        score += name_person
    tized_count = sum(1 for d in descriptions if d and 'tized' in d.lower())
    if tized_count >= 1 and name_person >= 1:
        score += 3
    if 'adomány' in combined and name_person >= 1:
        score += 2
    return score


def populate(conn_src, conn_ots, conn_tgt):
    ct = conn_tgt.cursor()
    ct.execute("TRUNCATE TABLE bank_tabla")
    ct.execute("TRUNCATE TABLE hazi_penztar")

    cs = conn_src.cursor(dictionary=True)
    cs.execute("""
        SELECT id, church_id, bank_date, bank_amount, bank_desc,
               bank_ext_name, bank_ext_ref, bank_init_name, bank_ben_name, status
        FROM bank_reconciliation
    """)
    bank_rows = cs.fetchall()
    cs.close()
    log(f"Bank sorok: {len(bank_rows)}")

    bank_data = []
    for br in bank_rows:
        partner = (br['bank_ext_name'] or '').strip()
        kozlemeny = (br['bank_desc'] or '').strip()
        leiras = kozlemeny
        if partner and partner not in leiras:
            leiras = (leiras + ' ' + partner).strip()
        if not leiras:
            leiras = (br['bank_ext_ref'] or '').strip()
        bank_data.append((
            br['church_id'], br['bank_date'], br['bank_amount'],
            partner, kozlemeny, leiras,
            br['status'] or 'UNCHECKED', br['id']
        ))

    ct.executemany(
        "INSERT INTO bank_tabla (telephely, datum, osszeg, partner_nev, kozlemeny, leiras, status, forras_id) "
        "VALUES (%s, %s, %s, %s, %s, %s, %s, %s)", bank_data)
    conn_tgt.commit()
    log(f"Bank beszúrva: {len(bank_data)}")

    co = conn_ots.cursor(dictionary=True)
    co.execute("""
        SELECT t.RECORD_ID, t.CHURCH_ID, t.AMOUNT, t.TYPE, t.DATETIME,
               t.CASH_DOCUMENT_NUMBER, t.NAME_ID, t.NAME2_ID,
               TRIM(CONCAT_WS(' ', p.NAME_PREFIX, p.NAME, p.NAME_SUFFIX)) AS person_name,
               nt1.NAME AS nt1_name, nt2.NAME AS nt2_name
        FROM ots.TRANSACTIONS t
        LEFT JOIN ots.PERSONS p ON t.PERSON_ID = p.id
        LEFT JOIN ots.NAMES_OF_TRANSACTION nt1 ON t.NAME_ID = nt1.id
        LEFT JOIN ots.NAMES_OF_TRANSACTION nt2 ON t.NAME2_ID = nt2.id
    """)
    ots_rows = co.fetchall()
    co.close()
    log(f"OTS sorok: {len(ots_rows)}")

    type_names = {}
    ctn = conn_ots.cursor()
    ctn.execute("SELECT id, NAME FROM transaction_type")
    for row in ctn.fetchall():
        type_names[row[0]] = row[1]
    ctn.close()

    seen = {}
    for row in ots_rows:
        rid = row['RECORD_ID']
        if rid not in seen:
            seen[rid] = {
                'rid': rid,
                'church_id': row['CHURCH_ID'],
                'amount': 0.0,
                'earliest': str(row['DATETIME'])[:10] if row['DATETIME'] else None,
                'type_ids': set(),
                'types': [],
                'megnevezes': '',
                'partner': '',
                'descriptions': [],
            }
        rec = seen[rid]
        amt = float(row['AMOUNT'])
        if row['TYPE'] in EXPENSE_TYPES:
            amt = -amt
        rec['amount'] += amt
        rec['type_ids'].add(row['TYPE'])
        if row['DATETIME']:
            d = str(row['DATETIME'])[:10]
            if rec['earliest'] is None or d < rec['earliest']:
                rec['earliest'] = d
        pname = (row['person_name'] or '').strip()
        if pname:
            rec['partner'] = pname
        desc = pname
        nt1 = (row['nt1_name'] or '').strip()
        nt2 = (row['nt2_name'] or '').strip()
        if nt1:
            desc += ' ' + nt1
            rec['megnevezes'] = nt1
        if nt2:
            desc += ' ' + nt2
            if not rec['megnevezes']:
                rec['megnevezes'] = nt2
        if desc.strip():
            rec['descriptions'].append(desc.strip())

    log(f"OTS rekordcsoportok: {len(seen)}")

    cash_data = []
    for rec in seen.values():
        tipus_str = ', '.join(str(t) for t in sorted(rec['type_ids']))
        type_names_list = [type_names.get(t, f'?{t}') for t in sorted(rec['type_ids'])]
        tipus_label = ' / '.join(type_names_list)
        desc = ' | '.join(set(rec['descriptions'])) if rec['descriptions'] else ''
        cash_data.append((
            rec['church_id'], rec['earliest'], round(rec['amount'], 2),
            tipus_label, rec['megnevezes'], rec['partner'],
            desc, 'feltoltve', rec['rid']
        ))

    ct.executemany(
        "INSERT INTO hazi_penztar (telephely, datum, osszeg, tipus, megnevezes, partner_nev, leiras, status, forras_record_id) "
        "VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)", cash_data)
    conn_tgt.commit()
    ct.close()

    return len(bank_data), len(cash_data)


def find_combinations(conn_tgt):
    t0 = time.time()
    c = conn_tgt.cursor(dictionary=True)

    c.execute("SELECT id, telephely, datum, osszeg, leiras, partner_nev, kozlemeny, status FROM bank_tabla WHERE status NOT IN ('egyeztetett','elutasitva')")
    bank_rows = c.fetchall()
    c.execute("SELECT id, telephely, datum, osszeg, leiras, tipus, megnevezes, partner_nev, status FROM hazi_penztar WHERE status NOT IN ('egyeztetett','elutasitva')")
    cash_rows = c.fetchall()
    c.close()

    def parse_dt(v):
        try:
            return datetime.strptime(str(v)[:10], '%Y-%m-%d')
        except:
            return None

    bank_by_tp = defaultdict(list)
    for br in bank_rows:
        bank_by_tp[br['telephely']].append({
            'id': br['id'], 'telephely': br['telephely'],
            'datum_obj': parse_dt(br['datum']),
            'datum_str': str(br['datum'])[:10] if br['datum'] else '',
            'osszeg': float(br['osszeg']),
            'leiras': br['leiras'] or '',
            'partner_nev': br['partner_nev'] or '',
            'kozlemeny': br['kozlemeny'] or '',
        })

    cash_by_tp = defaultdict(list)
    for cr in cash_rows:
        cash_by_tp[cr['telephely']].append({
            'id': cr['id'], 'telephely': cr['telephely'],
            'datum_obj': parse_dt(cr['datum']),
            'datum_str': str(cr['datum'])[:10] if cr['datum'] else '',
            'osszeg': float(cr['osszeg']),
            'leiras': cr['leiras'] or '',
            'tipus': cr['tipus'] or '',
            'megnevezes': cr['megnevezes'] or '',
            'partner_nev': cr['partner_nev'] or '',
        })

    tps = sorted(set(bank_by_tp.keys()) & set(cash_by_tp.keys()))
    log(f"Telephelyek: {tps}")

    suggestions = []

    for tp in tps:
        tstart = time.time()
        blist = bank_by_tp[tp]
        clist = cash_by_tp[tp]
        log(f"  TP {tp}: {len(blist)} bank, {len(clist)} cash")

        # MERGE: több bank → egy cash
        mcnt = 0
        for cash_row in clist:
            if time.time() - tstart > TIME_BUDGET_TP:
                log(f"  TP {tp}: merge időkorlát")
                break
            target = cash_row['osszeg']
            if target == 0:
                continue
            target_abs = abs(target)
            tdt = cash_row['datum_obj']
            if tdt is None:
                continue
            eligible = [b for b in blist
                        if b['datum_obj'] is not None
                        and abs((b['datum_obj'] - tdt).days) <= MAX_DAYS
                        and abs(b['osszeg']) <= target_abs
                        and b['osszeg'] != 0]
            if len(eligible) < 2 or len(eligible) > MAX_CAND:
                continue
            best = []
            seen_ids = set()
            for r in range(2, min(len(eligible)+1, 5)):
                for combo in itertools.combinations(eligible, r):
                    s = round(sum(b['osszeg'] for b in combo), 2)
                    ids = tuple(sorted(b['id'] for b in combo))
                    if ids in seen_ids:
                        continue
                    seen_ids.add(ids)
                    if abs(s - target) < 0.01:
                        ws = sum(word_overlap(b['leiras'], cash_row['leiras']) for b in combo)
                        ts = tithe_score(
                            [b['leiras'] for b in combo] + [cash_row['leiras']],
                            [b['partner_nev'] for b in combo] + [cash_row['partner_nev']])
                        best.append({'combo': combo, 'score': ws + ts})
            best.sort(key=lambda x: -x['score'])
            for res in best[:MAX_PER_TGT]:
                combo = res['combo']
                cats = set()
                for b in combo:
                    cats.update(categorize(b['leiras']))
                cats.update(categorize(cash_row['leiras']))
                ts = tithe_score(
                    [b['leiras'] for b in combo] + [cash_row['leiras']],
                    [b['partner_nev'] for b in combo] + [cash_row['partner_nev']])
                if ts >= 3:
                    cats.add('adomány')
                suggestions.append({
                    'tipus': 'SOK_AZ_EGYHEZ',
                    'telephely': tp,
                    'kategoria': sorted(cats),
                    'pontszam': res['score'],
                    'banki_tetelek': [{'id': b['id'], 'datum': b['datum_str'],
                        'osszeg': b['osszeg'], 'leiras': b['leiras'],
                        'partner_nev': b['partner_nev'], 'kozlemeny': b['kozlemeny']} for b in combo],
                    'hazi_penztar_tetel': {'id': cash_row['id'], 'datum': cash_row['datum_str'],
                        'osszeg': cash_row['osszeg'], 'leiras': cash_row['leiras'],
                        'tipus': cash_row['tipus'], 'megnevezes': cash_row['megnevezes'],
                        'partner_nev': cash_row['partner_nev']},
                    'banki_osszeg': round(sum(b['osszeg'] for b in combo), 2),
                    'penztari_osszeg': target,
                })
                mcnt += 1

        # SPLIT: egy bank → több cash
        scant = 0
        for bank_row in blist:
            if time.time() - tstart > TIME_BUDGET_TP:
                log(f"  TP {tp}: split időkorlát")
                break
            source = bank_row['osszeg']
            if source == 0:
                continue
            source_abs = abs(source)
            sdt = bank_row['datum_obj']
            if sdt is None:
                continue
            eligible = [c for c in clist
                        if c['datum_obj'] is not None
                        and abs((c['datum_obj'] - sdt).days) <= MAX_DAYS
                        and abs(c['osszeg']) <= source_abs
                        and c['osszeg'] != 0]
            if len(eligible) < 2 or len(eligible) > MAX_CAND:
                continue
            best = []
            seen_ids = set()
            for r in range(2, min(len(eligible)+1, 5)):
                for combo in itertools.combinations(eligible, r):
                    s = round(sum(c['osszeg'] for c in combo), 2)
                    ids = tuple(sorted(c['id'] for c in combo))
                    if ids in seen_ids:
                        continue
                    seen_ids.add(ids)
                    if abs(s - source) < 0.01:
                        ws = sum(word_overlap(c['leiras'], bank_row['leiras']) for c in combo)
                        ts = tithe_score(
                            [bank_row['leiras']] + [c['leiras'] for c in combo],
                            [bank_row['partner_nev']] + [c['partner_nev'] for c in combo])
                        best.append({'combo': combo, 'score': ws + ts})
            best.sort(key=lambda x: -x['score'])
            for res in best[:MAX_PER_TGT]:
                combo = res['combo']
                cats = set()
                cats.update(categorize(bank_row['leiras']))
                for c in combo:
                    cats.update(categorize(c['leiras']))
                ts = tithe_score(
                    [bank_row['leiras']] + [c['leiras'] for c in combo],
                    [bank_row['partner_nev']] + [c['partner_nev'] for c in combo])
                if ts >= 3:
                    cats.add('adomány')
                suggestions.append({
                    'tipus': 'EGY_A_SOKHOZ',
                    'telephely': tp,
                    'kategoria': sorted(cats),
                    'pontszam': res['score'],
                    'banki_tetel': {'id': bank_row['id'], 'datum': bank_row['datum_str'],
                        'osszeg': bank_row['osszeg'], 'leiras': bank_row['leiras'],
                        'partner_nev': bank_row['partner_nev'], 'kozlemeny': bank_row['kozlemeny']},
                    'hazi_penztar_tetelek': [{'id': c['id'], 'datum': c['datum_str'],
                        'osszeg': c['osszeg'], 'leiras': c['leiras'],
                        'tipus': c['tipus'], 'megnevezes': c['megnevezes'],
                        'partner_nev': c['partner_nev']} for c in combo],
                    'banki_osszeg': source,
                    'penztari_osszeg': round(sum(c['osszeg'] for c in combo), 2),
                })
                scant += 1

        log(f"  TP {tp}: {mcnt}+{scant} javaslat, {time.time()-tstart:.0f}s")

    return suggestions


def main():
    t_start = time.time()
    try:
        conn_src = get_conn(DB_SRC)
        conn_ots = get_conn(DB_OTS)
        conn_tgt = get_conn(DB_TGT)
    except Exception as e:
        print(json.dumps({"error": f"Kapcsolódási hiba: {e}"}, ensure_ascii=False))
        sys.exit(1)

    log("Táblák feltöltése...")
    try:
        bc, cc = populate(conn_src, conn_ots, conn_tgt)
    except Exception as e:
        print(json.dumps({"error": f"Adatbetöltési hiba: {e}"}, ensure_ascii=False))
        for conn in (conn_src, conn_ots, conn_tgt):
            conn.close()
        sys.exit(1)

    log("Kombinációk keresése...")
    suggestions = find_combinations(conn_tgt)
    elapsed = time.time() - t_start

    out = {
        'stat': {
            'banki_rekordok': bc,
            'penztari_rekordok': cc,
            'talalt_javaslat': len(suggestions),
            'futasi_ido': round(elapsed, 1),
        },
        'javaslatok': suggestions,
    }
    result_path = __file__ + '.result.json'  # mellé a .py mellé
    if __name__ == '__main__':
        result_path = __file__.replace('.py', '.result.json') if __file__.endswith('.py') else (__file__ + '.result.json')
    import os
    result_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'result.json')
    with open(result_path, 'w', encoding='utf-8') as f:
        json.dump(out, f, ensure_ascii=False, indent=2, default=str)
    print(f"OK:{len(suggestions)}", flush=True)
    log(f"Kész {elapsed:.1f}s, {len(suggestions)} javaslat -> {result_path}")

    for conn in (conn_src, conn_ots, conn_tgt):
        conn.close()


if __name__ == '__main__':
    main()
