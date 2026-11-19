# Builds public/psgc/ from the PSA Philippine Standard Geographic Code (PSGC) data published at https://psgc.gitlab.io/api/.
# Usage: python tools/build-psgc.py <folder with regions.json, provinces.json, cities-municipalities.json, barangays.json>
# Output: places.json (provinces with their cities/municipalities) and b/<province id>.json (barangays per city).
import json, os, sys, collections

src = sys.argv[1]
root = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'public', 'psgc')
load = lambda f: json.load(open(os.path.join(src, f + '.json'), encoding='utf-8'))

prov = {p['code']: p['name'] for p in load('provinces')}
# Cities outside any province: Metro Manila, plus two independent cities listed under their geographic province.
EXTRA = {'099701000': 'Basilan', '129804000': 'Maguindanao del Norte'}
by_name = {v: k for k, v in prov.items()}
groups = collections.defaultdict(list)
city_prov = {}
for c in load('cities-municipalities'):
    pname = prov.get(c['provinceCode']) if c['provinceCode'] else ('Metro Manila' if c['regionCode'] == '130000000' else EXTRA.get(c['code']))
    if not pname:
        sys.exit(f"No province for {c['name']} {c['code']}")
    groups[pname].append(c)
    city_prov[c['code']] = pname

brgy = collections.defaultdict(lambda: collections.defaultdict(list))
for b in load('barangays'):
    city = b['cityCode'] or b['municipalityCode']
    brgy[city_prov[city]][city].append(b['name'])

os.makedirs(os.path.join(root, 'b'), exist_ok=True)
out = []
for i, pname in enumerate(sorted(groups)):
    pid = f'p{i:02d}'
    cities = sorted(groups[pname], key=lambda c: c['name'])
    out.append({'n': pname, 'id': pid, 'c': [[c['name'], c['code']] for c in cities]})
    data = {code: sorted(set(names)) for code, names in brgy[pname].items()}
    json.dump(data, open(os.path.join(root, 'b', pid + '.json'), 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
json.dump(out, open(os.path.join(root, 'places.json'), 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
print(len(out), 'provinces,', sum(len(p['c']) for p in out), 'cities/municipalities,', sum(len(v) for p in brgy.values() for v in p.values()), 'barangays')
