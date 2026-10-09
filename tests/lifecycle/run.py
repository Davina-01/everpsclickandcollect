#!/usr/bin/env python3
"""
Lifecycle test runner for everpsclickandcollect.

Runs install / repeated install / disable / uninstall / reinstall / reset / carrier edit /
upgrades from old versions / repeated upgrade / failed upgrade + recovery / failed install +
retry / downgrade / purge against an ISOLATED copy of a PrestaShop shop.

Every check states the SAFE behaviour we expect. A FAIL means the risk is reproduced.

SAFETY
  * The shop copy must use a database whose name ends with "_lc" (LC_DB). The runner and every
    PHP tool refuse anything else. The database is dropped and re-created between scenarios.
  * MySQL privileges of the shop user are changed on LC_DB only (to inject failures) and are
    restored at the end of each scenario.
  * Never point this at a live shop.

Usage
  python3 tests/lifecycle/run.py --shop /path/to/shop_copy --db ps_lc --db-user ps \
      --clean-dump never_installed.sql --out results_dir [--only S01,S04] [--current-ref COMMIT]

  --clean-dump: dump of the shop copy where the module has never been installed (orders exist).
"""
import argparse
import json
import os
import shutil
import subprocess
import sys
import time

MODULE = 'everpsclickandcollect'
HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))

# Historical versions (git commits of this repository)
V311 = '588f834'  # 3.1.1, last Team Ever release
V320 = '671efd2'  # 3.2.0
V330 = 'c68a1cb'  # 3.3.0


class Runner:
    def __init__(self, a):
        self.shop = a.shop
        self.db = a.db
        self.user = a.db_user
        self.clean = os.path.abspath(a.clean_dump)
        self.out = os.path.abspath(a.out)
        os.makedirs(self.out, exist_ok=True)
        if not self.db.endswith('_lc'):
            sys.exit('refusing: --db must end with _lc')
        params = open(os.path.join(self.shop, 'app/config/parameters.php')).read()
        if "'database_name' => '%s'" % self.db not in params:
            sys.exit('refusing: the shop at --shop does not use database %s' % self.db)
        self.env = dict(os.environ, LC_DB=self.db)
        # --current-ref: test a git commit as "current" instead of the working tree (e.g. the code before fixes)
        self.current_ref = a.current_ref
        self.results = []
        self.scenario = None

    # ---------- shell helpers ----------
    def sh(self, cmd, cwd=None, check=False):
        p = subprocess.run(cmd, shell=True, cwd=cwd, env=self.env, capture_output=True, text=True)
        if check and p.returncode:
            raise RuntimeError('%s\n%s%s' % (cmd, p.stdout, p.stderr))
        return p

    def sql(self, q):
        return self.sh('mysql -N %s -e %s' % (self.db, json.dumps(q)), check=True).stdout.strip()

    def clear_cache(self):
        shutil.rmtree(os.path.join(self.shop, 'var/cache'), ignore_errors=True)

    def restore(self, dump):
        self.sh('mysql -e "DROP DATABASE IF EXISTS %s; CREATE DATABASE %s"' % (self.db, self.db), check=True)
        self.sh('mysql %s < %s' % (self.db, dump), check=True)
        self.clear_cache()

    def dump(self, name):
        path = os.path.join(self.out, name + '.sql')
        self.sh('mysqldump %s > %s' % (self.db, path), check=True)
        return path

    def deploy(self, ref):
        """ref = 'current' (working tree) or a git commit"""
        dst = os.path.join(self.shop, 'modules', MODULE)
        shutil.rmtree(dst, ignore_errors=True)
        os.makedirs(dst)
        if ref == 'current' and self.current_ref:
            ref = self.current_ref
        if ref == 'current':
            self.sh('rsync -a --exclude .git --exclude tests %s/ %s/' % (REPO, dst), check=True)
        else:
            self.sh('git -C %s archive %s | tar -x -C %s' % (REPO, ref, dst), check=True)
            shutil.rmtree(os.path.join(dst, 'tests'), ignore_errors=True)
        tools = os.path.join(self.shop, 'lc_tools')
        shutil.rmtree(tools, ignore_errors=True)
        shutil.copytree(os.path.join(HERE, 'php'), tools)
        self.clear_cache()

    def console(self, action):
        p = self.sh('php bin/console prestashop:module %s %s --env=prod --no-debug' % (action, MODULE), cwd=self.shop)
        self.clear_cache()
        text = (p.stdout + p.stderr)
        lines = [l.strip() for l in text.splitlines() if l.strip() and 'Deprecated' not in l and not l.strip().startswith('prestashop:module [')]
        ok = p.returncode == 0 and 'succeeded' in text
        return ok, ' '.join(lines)[:300]

    def tool(self, name, *args):
        p = self.sh('php lc_tools/%s.php %s' % (name, ' '.join(str(x) for x in args)), cwd=self.shop)
        lines = [l for l in p.stdout.splitlines() if l.startswith('{') or l.startswith('[')]
        if not lines:
            return {'_error': (p.stdout + p.stderr)[-300:]}
        return json.loads(lines[-1])

    def snap(self, label):
        s = self.tool('snap')
        with open(os.path.join(self.out, '%s_%s.json' % (self.scenario, label)), 'w') as f:
            json.dump(s, f, indent=1, ensure_ascii=False)
        return s

    def grants(self, deny_table=None, priv=None):
        """Full privileges on LC_DB, or everything except `priv` on `deny_table`."""
        hosts = ('127.0.0.1', 'localhost')
        for h in hosts:
            self.sh("mysql -e \"REVOKE ALL PRIVILEGES ON %s.* FROM '%s'@'%s'\"" % (self.db, self.user, h))
            for t in self.sql("SELECT DISTINCT table_name FROM information_schema.table_privileges WHERE table_schema='%s' AND grantee LIKE \"'%s'@%%\"" % (self.db, self.user)).split():
                self.sh("mysql -e \"REVOKE ALL PRIVILEGES ON %s.%s FROM '%s'@'%s'\"" % (self.db, t, self.user, h))
        if not deny_table:
            for h in hosts:
                self.sh("mysql -e \"GRANT ALL ON %s.* TO '%s'@'%s'\"" % (self.db, self.user, h), check=True)
        else:
            all_privs = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX']
            tables = self.sql('SHOW TABLES').split() + ['ps_everpsclickandcollect', 'ps_everpsclickandcollect_store', 'ps_everpsclickandcollect_store_stock']
            stmts = []
            for h in hosts:
                stmts.append("GRANT CREATE, LOCK TABLES, CREATE TEMPORARY TABLES ON %s.* TO '%s'@'%s';" % (self.db, self.user, h))
                for t in sorted(set(tables)):
                    privs = [x for x in all_privs if not (t == deny_table and x == priv)]
                    stmts.append("GRANT %s ON %s.`%s` TO '%s'@'%s';" % (', '.join(privs), self.db, t, self.user, h))
            path = os.path.join(self.out, 'grants.sql')
            open(path, 'w').write('\n'.join(stmts) + '\nFLUSH PRIVILEGES;\n')
            self.sh('mysql < %s' % path, check=True)
        self.sh('mysql -e "FLUSH PRIVILEGES"')

    # ---------- results ----------
    def check(self, name, ok, detail):
        self.results.append({'scenario': self.scenario, 'check': name, 'pass': bool(ok), 'detail': detail})
        print('  [%s] %s -- %s' % ('PASS' if ok else 'FAIL', name, detail))

    def note(self, text):
        self.results.append({'scenario': self.scenario, 'check': 'note', 'pass': None, 'detail': text})
        print('  note: %s' % text)

    # ---------- fixtures ----------
    def active_module_carriers(self, s):
        return [c for c in s['carriers'] if c['deleted'] == '0' and c['active'] == '1']

    def seed_history(self, version):
        """Orders of the clean dump become click & collect orders with pickup data of `version`."""
        idc = self.sql("SELECT value FROM ps_configuration WHERE name='EVERPSCLICKANDCOLLECT_CARRIER_ID'")
        self.sql('UPDATE ps_orders o LEFT JOIN ps_carrier c ON c.id_carrier = o.id_carrier SET o.id_carrier = {IDC} WHERE c.id_carrier IS NULL'.replace('{IDC}', idc))
        self.sql('UPDATE ps_order_carrier oc LEFT JOIN ps_carrier c ON c.id_carrier = oc.id_carrier SET oc.id_carrier = {IDC} WHERE c.id_carrier IS NULL'.replace('{IDC}', idc))
        ids = self.sql('SELECT id_order FROM ps_orders WHERE id_carrier = {IDC} ORDER BY id_order'.replace('{IDC}', idc)).split()
        if version == '3.1.1':
            self.sql("INSERT INTO ps_everpsclickandcollect (id_cart, id_store, delivery_date) SELECT id_cart, 1, ELT(1 + (id_order % 3), 'Monday', 'Friday', 'Saturday') FROM ps_orders WHERE id_carrier = {IDC}".replace('{IDC}', idc))
        elif version == '3.2.0':
            self.sql("INSERT INTO ps_everpsclickandcollect (id_cart, id_store, delivery_date, delivery_hour) SELECT id_cart, 1, '2026-10-09', '10:00-10:30,10:30-11:00' FROM ps_orders WHERE id_carrier = {IDC}".replace('{IDC}', idc))
        else:  # current: half 3.3.0 slots, half 3.4.0 choices
            self.sql("INSERT INTO ps_everpsclickandcollect (id_cart, id_store, delivery_date, delivery_hour) SELECT id_cart, 1, '2026-10-09,2026-10-12', '2026-10-09 15:00-15:30,2026-10-12 09:00-09:30' FROM ps_orders WHERE id_carrier = {IDC} AND id_order % 2 = 0".replace('{IDC}', idc))
            self.sql("INSERT INTO ps_everpsclickandcollect (id_cart, id_store, delivery_date, pickup_mode, pickup_periods, pickup_prepare, pickup_summary) SELECT id_cart, 1, '2026-10-09', 'later', '[{\"date\":\"2026-10-09\",\"start\":\"14:00\",\"end\":\"17:00\"}]', 1, '2026-10-09 14:00-17:00' FROM ps_orders WHERE id_carrier = {IDC} AND id_order % 2 = 1".replace('{IDC}', idc))
            self.sql("UPDATE ps_configuration_lang cl JOIN ps_configuration c ON c.id_configuration = cl.id_configuration SET cl.value = 'CUSTOM T2' WHERE c.name = 'EVERPSCLICKANDCOLLECT_TEXT_T2' AND cl.id_lang = 1")
            self.sql("UPDATE ps_configuration SET value = '{\"1\":\"09:00-12:00\",\"2\":\"09:00-12:00\",\"3\":\"\",\"4\":\"09:00-12:00\",\"5\":\"09:00-12:00\",\"6\":\"09:00-12:00\",\"7\":\"\"}' WHERE name = 'EVERPSCLICKANDCOLLECT_SCHEDULE'")
        self.sql("INSERT INTO ps_everpsclickandcollect_store_stock (id_store, id_product, id_product_attribute, id_shop, qty) VALUES (1, 1, 1, 1, '5'), (1, 1, 2, 1, '3'), (2, 2, 0, 1, '7')")
        return [int(x) for x in ids]

    def install_current_with_history(self):
        self.restore(self.clean)
        self.deploy('current')
        ok, msg = self.console('install')
        if not ok:
            raise RuntimeError('install failed: ' + msg)
        orders = self.seed_history('current')
        self.clear_cache()
        return orders

    def shown(self, rendered):
        """Orders whose BO block shows a pickup time (every seeded row has a time)"""
        return [k for k, v in rendered.items() if v and 'Pickup' in v and not v.startswith('EXCEPTION')
                and (':' in v.split('Change pickup time')[0] or 'Monday' in v or 'Friday' in v or 'Saturday' in v)
                and 'No time given' not in v]

    # ---------- scenarios ----------
    def S01_fresh_install(self):
        """Fresh install on a shop that never had the module"""
        self.restore(self.clean)
        self.deploy('current')
        ok, msg = self.console('install')
        s = self.snap('after')
        self.check('install succeeds', ok, msg[:120])
        self.check('module active', s['module'] and s['module']['active'] == '1', str(s['module']))
        self.check('3 tables exist', all(v != 'MISSING' for v in s['tables'].values()), str(s['tables']))
        self.check('exactly 1 active module carrier', len(self.active_module_carriers(s)) == 1, str(s['carriers']))
        self.check('CARRIER_ID points to it', [c['id_carrier'] for c in self.active_module_carriers(s)] == [s['carrier_id_cfg']], str(s['carrier_id_cfg']))
        self.check('pickup edit tab installed', 'AdminEverPsClickAndCollectPickup' in s['tabs'], str(s['tabs']))
        off = self.tool('offered')
        self.check('carrier offered at checkout', int(s['carrier_id_cfg'] or 0) in off.get('offered', []), str(off))
        self.dump('S01_fresh')
        json.dump(s['hooks'], open(os.path.join(self.out, 'fresh_hooks.json'), 'w'))

    def S02_repeated_install(self):
        """install() called again on an installed module (legacy controller, script, double click)"""
        self.restore(os.path.join(self.out, 'S01_fresh.sql'))
        self.deploy('current')
        before = self.snap('before')
        r = self.tool('call', 'install')
        after = self.snap('after')
        self.check('second install() refused', r.get('returned') is False, str(r)[:150])
        self.check('no extra carrier created', len(after['carriers']) == len(before['carriers']), '%s -> %s' % (len(before['carriers']), len(after['carriers'])))
        self.check('CARRIER_ID unchanged', after['carrier_id_cfg'] == before['carrier_id_cfg'], '%s -> %s' % (before['carrier_id_cfg'], after['carrier_id_cfg']))
        self.check('store rows not duplicated', after['tables']['everpsclickandcollect_store'] == before['tables']['everpsclickandcollect_store'], '%s -> %s' % (before['tables']['everpsclickandcollect_store'], after['tables']['everpsclickandcollect_store']))
        ok, msg = self.console('install')
        after2 = self.snap('after_console')
        self.check('console install on installed module = no-op', len(after2['carriers']) == len(after['carriers']), msg[:100])

    def S03_disable_enable(self):
        """Disable then enable"""
        self.restore(os.path.join(self.out, 'S01_fresh.sql'))
        self.deploy('current')
        idc = int(self.snap('before')['carrier_id_cfg'])
        ok, msg = self.console('disable')
        s = self.snap('disabled')
        off = self.tool('offered')
        self.check('disable succeeds', ok, msg[:80])
        self.check('carrier NOT offered while module disabled', idc not in off.get('offered', []), str(off))
        self.check('data kept while disabled', s['tables']['everpsclickandcollect'] != 'MISSING', str(s['tables']))
        self.sql('UPDATE ps_carrier SET active = 1 WHERE id_carrier = %d' % idc)
        self.clear_cache()
        off = self.tool('offered')
        self.check('not offered while disabled even if the carrier is switched on by hand', idc not in off.get('offered', []), str(off))
        self.sql('UPDATE ps_carrier SET active = 0 WHERE id_carrier = %d' % idc)
        ok, msg = self.console('enable')
        off = self.tool('offered')
        self.check('enable succeeds', ok, msg[:80])
        self.check('carrier offered again after enable', idc in off.get('offered', []), str(off))

    def S04_uninstall_keeps_history(self):
        """Uninstall a shop with history, then reinstall"""
        orders = self.install_current_with_history()
        before = self.snap('before')
        r0 = self.tool('render', *orders[:4])
        ok, msg = self.console('uninstall')
        s = self.snap('uninstalled')
        self.check('uninstall succeeds', ok, msg[:80])
        self.check('pickup rows kept', s['tables']['everpsclickandcollect'] == before['tables']['everpsclickandcollect'], '%s -> %s' % (before['tables']['everpsclickandcollect'], s['tables']['everpsclickandcollect']))
        self.check('store stock kept', s['tables']['everpsclickandcollect_store_stock'] == before['tables']['everpsclickandcollect_store_stock'], '%s -> %s' % (before['tables']['everpsclickandcollect_store_stock'], s['tables']['everpsclickandcollect_store_stock']))
        self.check('no order loses its carrier', s['orders_with_missing_carrier'] == before['orders_with_missing_carrier'], '%s -> %s orders without carrier row' % (before['orders_with_missing_carrier'], s['orders_with_missing_carrier']))
        self.check('module carrier no longer active', not self.active_module_carriers(s), str(s['carriers']))
        self.check('settings kept', s['text_t2_lang1'] == 'CUSTOM T2' and 'EVERPSCLICKANDCOLLECT_SCHEDULE' in s['config'], 'T2=%r, %d keys' % (s['text_t2_lang1'], len(s['config'])))
        ok, msg = self.console('install')
        s2 = self.snap('reinstalled')
        r2 = self.tool('render', *orders[:4])
        self.check('reinstall succeeds', ok, msg[:80])
        self.check('old pickup rows still there', s2['tables']['everpsclickandcollect'] == before['tables']['everpsclickandcollect'], str(s2['tables']))
        self.check('old orders show pickup again in BO', len(self.shown(r2)) == len(self.shown(r0)) > 0, 'before %s / after %s' % (self.shown(r0), self.shown(r2)))
        self.check('exactly 1 active module carrier', len(self.active_module_carriers(s2)) == 1, str(s2['carriers']))
        self.check('carrier reused (no new carrier row)', len(s2['carriers']) == len(before['carriers']), '%s -> %s rows' % (len(before['carriers']), len(s2['carriers'])))
        self.check('store rows not duplicated', s2['tables']['everpsclickandcollect_store'] == before['tables']['everpsclickandcollect_store'], '%s -> %s' % (before['tables']['everpsclickandcollect_store'], s2['tables']['everpsclickandcollect_store']))
        self.check('settings survive reinstall', s2['text_t2_lang1'] == 'CUSTOM T2', 'T2=%r' % s2['text_t2_lang1'])

    def S05_reset(self):
        """Back office "Reset" (PrestaShop runs uninstall + install)"""
        orders = self.install_current_with_history()
        before = self.snap('before')
        ok, msg = self.console('reset')
        s = self.snap('after')
        r = self.tool('render', *orders[:2])
        self.check('reset succeeds', ok, msg[:80])
        self.check('pickup rows kept', s['tables']['everpsclickandcollect'] == before['tables']['everpsclickandcollect'], '%s -> %s' % (before['tables']['everpsclickandcollect'], s['tables']['everpsclickandcollect']))
        self.check('old orders still show pickup', len(self.shown(r)) == 2, str(r)[:160])
        self.check('no order loses its carrier', s['orders_with_missing_carrier'] == before['orders_with_missing_carrier'], '%s -> %s' % (before['orders_with_missing_carrier'], s['orders_with_missing_carrier']))

    def S06_carrier_edit(self):
        """Staff edits the carrier in Shipping > Carriers (PrestaShop duplicates it)"""
        orders = self.install_current_with_history()
        before = self.snap('before')
        r0 = self.tool('render', *orders[:4])
        w = self.tool('wizard_edit', before['carrier_id_cfg'])
        s = self.snap('after')
        r1 = self.tool('render', *orders[:4])
        self.check('CARRIER_ID follows the new carrier', s['carrier_id_cfg'] == str(w.get('new')), '%s -> %s' % (before['carrier_id_cfg'], s['carrier_id_cfg']))
        self.check('old orders still show pickup', len(self.shown(r1)) == len(self.shown(r0)) > 0, 'before %s / after %s' % (self.shown(r0), self.shown(r1)))
        ok, msg = self.console('uninstall')
        s2 = self.snap('uninstalled')
        self.check('uninstall after edit keeps every carrier row', s2['orders_with_missing_carrier'] == before['orders_with_missing_carrier'], '%s orders without carrier' % s2['orders_with_missing_carrier'])

    def _upgrade_from(self, ref, version):
        self.restore(self.clean)
        self.deploy(ref)
        ok, msg = self.console('install')
        if not ok:
            self.note('install of %s failed: %s' % (version, msg))
            return None
        orders = self.seed_history(version)
        before = self.snap('before')
        r0 = self.tool('render', *orders[:3])
        self.deploy('current')
        ok, msg = self.console('upgrade')
        s = self.snap('after')
        r1 = self.tool('render', *orders[:3])
        fresh = json.load(open(os.path.join(self.out, 'fresh_hooks.json')))
        self.check('upgrade succeeds', ok, msg[:100])
        self.check('module active after upgrade', s['module']['active'] == '1', str(s['module']))
        self.check('all pickup rows kept', s['tables']['everpsclickandcollect'] == before['tables']['everpsclickandcollect'], '%s -> %s' % (before['tables']['everpsclickandcollect'], s['tables']['everpsclickandcollect']))
        self.check('stock rows kept', s['tables']['everpsclickandcollect_store_stock'] == before['tables']['everpsclickandcollect_store_stock'], str(s['tables']))
        self.check('pickup_* columns added', all(c in s['columns'] for c in ('pickup_mode', 'pickup_periods', 'pickup_prepare', 'pickup_summary')), str(s['columns']))
        self.check('same hooks as a fresh install', sorted(s['hooks']) == sorted(fresh), 'missing %s / extra %s' % (sorted(set(fresh) - set(s['hooks'])), sorted(set(s['hooks']) - set(fresh))))
        self.check('same carrier, still active', [c['id_carrier'] for c in self.active_module_carriers(s)] == [c['id_carrier'] for c in self.active_module_carriers(before)], str(s['carriers']))
        self.check('old orders still show pickup', len(self.shown(r1)) == len(orders[:3]), str(r1)[:200])
        sv = self.tool('save_choice', 900001)
        self.check('checkout can save a new choice', sv.get('returned') is True, str(sv))
        self.sql('DELETE FROM ps_everpsclickandcollect WHERE id_cart = 900001')
        return orders

    def S07_upgrade_from_311(self):
        """Upgrade 3.1.1 (Team Ever) -> current, with history"""
        if self._upgrade_from(V311, '3.1.1') is not None:
            self.dump('S07_upgraded')

    def S08_upgrade_from_320(self):
        """Upgrade 3.2.0 -> current, with history"""
        self._upgrade_from(V320, '3.2.0')

    def S09_repeated_upgrade(self):
        """Every upgrade script run a second time on an up-to-date shop"""
        path = os.path.join(self.out, 'S07_upgraded.sql')
        if not os.path.exists(path):
            self.note('needs S07')
            return
        self.restore(path)
        self.deploy('current')
        tables = 'ps_configuration ps_configuration_lang ps_everpsclickandcollect ps_everpsclickandcollect_store ps_everpsclickandcollect_store_stock ps_hook_module ps_tab ps_carrier'
        b = self.sh('mysqldump --skip-dump-date --skip-extended-insert %s %s' % (self.db, tables)).stdout
        versions = sorted(f[8:-4] for f in os.listdir(os.path.join(self.shop, 'modules', MODULE, 'upgrade')) if f.startswith('upgrade-') and f.endswith('.php'))
        versions = [v for v in versions if tuple(map(int, v.split('.'))) > (3, 1, 1)]
        r = self.tool('rerun_upgrades', *versions)
        r2 = self.tool('rerun_upgrades', *versions)
        a = self.sh('mysqldump --skip-dump-date --skip-extended-insert %s %s' % (self.db, tables)).stdout
        diff = [l for l in set(a.splitlines()) ^ set(b.splitlines()) if l.startswith('INSERT') and 'date_upd' not in l]
        diff = [l for l in diff if not ("'PS_CCC" in l)]
        self.check('every upgrade function returns true twice', all(v is True for v in list(r.values()) + list(r2.values())), '%s %s' % (r, r2))
        self.check('second run changes nothing', not diff, '%d changed rows %s' % (len(diff), [l[:90] for l in diff[:3]]))

    def S10_upgrade_from_old_2x(self):
        """Upgrade from a 2.x install (simulated: 3.1.1 schema registered as 2.2.0)"""
        self.restore(self.clean)
        self.deploy(V311)
        self.console('install')
        self.seed_history('3.1.1')
        self.sql("UPDATE ps_module SET version = '2.2.0' WHERE name = '%s'" % MODULE)
        self.deploy('current')
        ok, msg = self.console('upgrade')
        s = self.snap('after')
        self.check('upgrade succeeds', ok, msg[:140])
        self.check('module active', s['module']['active'] == '1', str(s['module']))
        self.check('schema migrated', 'pickup_mode' in s['columns'], str(s['columns']))
        if s['module']['active'] != '1' or 'pickup_mode' not in s['columns']:
            ok2, msg2 = self.console('upgrade')
            s2 = self.snap('retry')
            self.check('retry of the upgrade migrates the schema', 'pickup_mode' in s2['columns'], msg2[:100])
            ok3, msg3 = self.console('enable')
            sv = self.tool('save_choice', 900001)
            self.check('after enable, checkout can save', sv.get('returned') is True, str(sv)[:150])

    def S11_failed_upgrade_then_retry(self):
        """ALTER denied during upgrade 3.1.1 -> current, then privileges fixed and upgrade retried"""
        self.restore(self.clean)
        self.deploy(V311)
        self.console('install')
        self.seed_history('3.1.1')
        before = self.snap('before')
        self.deploy('current')
        self.grants('ps_everpsclickandcollect', 'ALTER')
        try:
            ok, msg = self.console('upgrade')
            s = self.snap('failed')
            self.check('upgrade reports failure', not ok, msg[:160])
            self.check('no pickup row lost', s['tables']['everpsclickandcollect'] == before['tables']['everpsclickandcollect'], str(s['tables']))
            self.check('version NOT bumped (upgrade can be retried)', s['module']['version'] == '3.1.1', str(s['module']))
            sv = self.tool('save_choice', 900001)
            self.check('checkout does not crash while schema is old', 'exception' not in sv, str(sv)[:150])
        finally:
            self.grants()
        ok, msg = self.console('upgrade')
        s = self.snap('retried')
        self.check('retry succeeds', ok and 'pickup_mode' in s['columns'], msg[:100])
        if s['module']['active'] != '1':
            self.console('enable')
        sv = self.tool('save_choice', 900001)
        self.check('checkout saves after retry', sv.get('returned') is True, str(sv))

    def S12_stuck_upgrade_recovery(self):
        """PrestaShop recorded the new version although a migration did not run (module disabled):
        recovery without data loss"""
        self.restore(self.clean)
        self.deploy(V311)
        self.console('install')
        self.seed_history('3.1.1')
        self.deploy('current')
        cur = open(os.path.join(self.shop, 'modules', MODULE, MODULE + '.php')).read().split("$this->version = '")[1].split("'")[0]
        self.sql("UPDATE ps_module SET version = '%s', active = 0 WHERE name = '%s'" % (cur, MODULE))
        self.sql("DELETE FROM ps_module_shop WHERE id_module = (SELECT id_module FROM ps_module WHERE name = '%s')" % MODULE)
        before = self.snap('before')
        ok, msg = self.console('enable')
        s = self.snap('after')
        sv = self.tool('save_choice', 900001)
        self.check('enable repairs the schema', 'pickup_mode' in s['columns'], str(s['columns']))
        self.check('checkout saves after enable', sv.get('returned') is True, str(sv)[:150])
        self.check('no pickup row lost', s['tables']['everpsclickandcollect'] == before['tables']['everpsclickandcollect'], str(s['tables']))

    def S13_failed_install_then_retry(self):
        """INSERT denied on ps_tab during install (late failure), then privileges fixed and install retried"""
        self.restore(self.clean)
        self.deploy('current')
        self.grants('ps_tab', 'INSERT')
        try:
            ok, msg = self.console('install')
            s = self.snap('failed')
            self.check('install reports failure', not ok, msg[:140])
            self.check('module not left half-installed', s['module'] is None or s['module']['active'] == '0', str(s['module']))
            self.check('no active module carrier left behind', not self.active_module_carriers(s), str(s['carriers']))
        finally:
            self.grants()
        ok, msg = self.console('install')
        s = self.snap('retried')
        self.check('retry install succeeds', ok, msg[:100])
        self.check('tab present after retry', 'AdminEverPsClickAndCollectPickup' in s['tabs'], str(s['tabs']))
        self.check('exactly 1 active module carrier', len(self.active_module_carriers(s)) == 1, str(s['carriers']))
        self.check('at most 1 module carrier row', len(s['carriers']) <= 1, str(s['carriers']))

    def S14_downgrade_to_330(self):
        """A choice saved by the current version, then files replaced by 3.3.0 (downgrade)"""
        orders = self.install_current_with_history()
        cart = self.sql('SELECT id_cart FROM ps_orders WHERE id_order = %d' % orders[0])
        self.tool('save_choice', cart, '2026-10-20')
        self.deploy(V330)
        r = self.tool('render', orders[0])
        txt = list(r.values())[0] if r else ''
        self.check('3.3.0 still shows the time chosen with the new version', '10:00' in txt and '12:00' in txt, txt[:140])
        s = self.snap('after')
        self.check('no data lost', s['tables']['everpsclickandcollect'] != 'MISSING', str(s['tables']))

    def S15_status_changes(self):
        """Order status changed 3 times with the store e-mail option on"""
        orders = self.install_current_with_history()
        r = self.tool('status_changes', orders[0])
        self.check('at most one store address per order', r.get('after', 999) - r.get('before', 0) <= 1, str(r))

    def S16_purge(self):
        """Explicit "delete all data" (the only destructive path)"""
        orders = self.install_current_with_history()
        before = self.snap('before')
        r = self.tool('call', 'purgeData')
        if 'exception' in r and 'undefined method' in r['exception'].lower():
            self.note('no purge path in this version')
            return
        s = self.snap('after')
        self.check('purge returns true', r.get('returned') is True, str(r)[:150])
        self.check('module uninstalled', s['module'] is None, str(s['module']))
        self.check('module tables dropped', all(v == 'MISSING' for v in s['tables'].values()), str(s['tables']))
        self.check('module settings deleted', not s['config'], str(list(s['config'])[:5]))
        self.check('carrier rows kept, flagged deleted', s['carriers'] and all(c['deleted'] == '1' for c in s['carriers']), str(s['carriers']))
        self.check('no order loses its carrier', s['orders_with_missing_carrier'] == before['orders_with_missing_carrier'], '%s -> %s' % (before['orders_with_missing_carrier'], s['orders_with_missing_carrier']))
        self.check('orders untouched', s['orders_total'] == before['orders_total'], '%s -> %s' % (before['orders_total'], s['orders_total']))
        ok, msg = self.console('install')
        s2 = self.snap('reinstalled')
        self.check('install after purge starts clean', ok and s2['tables']['everpsclickandcollect'] == 0 and len(self.active_module_carriers(s2)) == 1, '%s %s' % (s2['tables'], s2['carriers']))

    def S17_merchant_carrier_choices(self):
        """Carrier switched off or deleted by the merchant, duplicate carriers of older versions"""
        self.restore(os.path.join(self.out, 'S01_fresh.sql'))
        self.deploy('current')
        idc = int(self.snap('before')['carrier_id_cfg'])
        cur = open(os.path.join(self.shop, 'modules', MODULE, MODULE + '.php')).read().split("$this->version = '")[1].split("'")[0]
        # 1. switched off by the merchant: an upgrade (repair) and a disable / enable keep it off
        self.sql('UPDATE ps_carrier SET active = 0 WHERE id_carrier = %d' % idc)
        r = self.tool('rerun_upgrades', cur)
        self.console('disable')
        self.console('enable')
        s = self.snap('off_by_merchant')
        self.check('carrier switched off by the merchant stays off', not self.active_module_carriers(s), '%s %s' % (r, s['carriers']))
        # 2. deleted by the merchant: repair does not create a new carrier
        self.sql('UPDATE ps_carrier SET deleted = 1 WHERE id_carrier = %d' % idc)
        self.tool('rerun_upgrades', cur)
        self.console('disable')
        self.console('enable')
        s = self.snap('deleted_by_merchant')
        self.check('carrier deleted by the merchant is not re-created', len(s['carriers']) == 1, str(s['carriers']))
        # 3. duplicate active carriers (older versions): disabling the module hides all of them
        self.restore(os.path.join(self.out, 'S01_fresh.sql'))
        self.deploy('current')
        self.tool('wizard_edit', idc)  # new copy, old one deleted
        self.sql("INSERT INTO ps_carrier (id_reference, name, url, active, deleted, shipping_handling, range_behavior, is_module, is_free, shipping_external, need_range, external_module_name, shipping_method, position, max_width, max_height, max_depth, max_weight, grade) SELECT id_reference + 100, name, url, 1, 0, shipping_handling, range_behavior, is_module, is_free, shipping_external, need_range, external_module_name, shipping_method, position, max_width, max_height, max_depth, max_weight, grade FROM ps_carrier WHERE id_carrier = %d" % idc)
        self.console('disable')
        s = self.snap('duplicates_disabled')
        self.check('disable deactivates every carrier of the module', not self.active_module_carriers(s), str(s['carriers']))

    def run(self, only):
        names = sorted(n for n in dir(self) if n[:1] == 'S' and n[1:3].isdigit())
        for n in names:
            if only and n.split('_')[0] not in only:
                continue
            self.scenario = n.split('_')[0]
            print('\n== %s: %s' % (n, getattr(self, n).__doc__.strip().splitlines()[0]))
            t = time.time()
            try:
                getattr(self, n)()
            except Exception as e:
                self.check('scenario ran to the end', False, str(e)[:300])
            finally:
                self.grants()
            print('   (%.0fs)' % (time.time() - t))
        json.dump(self.results, open(os.path.join(self.out, 'results.json'), 'w'), indent=1, ensure_ascii=False)
        checks = [r for r in self.results if r['pass'] is not None]
        print('\n%d checks: %d PASS, %d FAIL' % (len(checks), sum(r['pass'] for r in checks), sum(not r['pass'] for r in checks)))


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('--shop', required=True)
    ap.add_argument('--db', required=True)
    ap.add_argument('--db-user', default='ps')
    ap.add_argument('--clean-dump', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--only', default='')
    ap.add_argument('--current-ref', default='')
    a = ap.parse_args()
    Runner(a).run([x for x in a.only.split(',') if x])
